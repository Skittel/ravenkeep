<?php

use App\Libraries\Inventory\Inventory;
use App\Libraries\Rules\RuleRepository;
use App\Libraries\Rules\RuleRunner;
use App\Libraries\Rules\TagService;
use App\Libraries\Rules\TagTypes;
use App\Libraries\Senders\SenderRuleService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Managed tag types (E-023).
 *
 * @internal
 */
final class TagTypesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private RuleRunner $runner;
    private TagTypes $types;
    private TagService $tags;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new RuleRunner($this->db);
        $this->types  = new TagTypes($this->db, $this->runner);
        $this->tags   = new TagService($this->db, $this->runner);
    }

    private function error(callable $fn): string
    {
        try {
            $fn();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return 'no error';
    }

    public function testSystemTypesExistAndNewTagsNeedAKnownType(): void
    {
        foreach (TagTypes::SYSTEM as $type) {
            $this->assertContains($type, $this->types->names());
        }
        $this->assertSame('tag_type', $this->error(fn () => $this->tags->create('hersteller:qnap')));
        $this->assertSame('tag_type', $this->error(fn () => $this->tags->create('wichtig'))); // untyped
        $this->types->create('Hersteller', 'Gerätehersteller');
        $this->tags->create('hersteller:qnap');
        $this->seeInDatabase('tags', ['name' => 'hersteller:qnap']);

        // all manual entry points check the type
        $customer = (new Inventory($this->db, $this->runner))->createCustomer('acme', 'ACME', null);
        $this->assertSame('tag_type', $this->error(fn () => (new Inventory($this->db, $this->runner))->createDevice($customer, null, 'd1', 'D1', null, ['typo:x'])));
        $this->assertSame('tag_type', $this->error(fn () => (new SenderRuleService($this->db))->save('x.example', 'header', 'allow', null, ['tags' => ['typo:x']])));
        $this->assertSame('tag_type', $this->error(fn () => (new RuleRepository($this->db, $this->runner))->save(null, 'r', 'all', false, true,
            [['field' => 'subject', 'operator' => 'contains', 'value' => 'x']], ['typo:x'])));

        // automatic tags are not affected
        $this->runner->tagId('meldung:gc');
        $this->seeInDatabase('tags', ['name' => 'meldung:gc']);
    }

    public function testRenameMergesATypo(): void
    {
        $this->types->create('sources', null);
        $typo = $this->tags->create('sources:statuscake');
        $this->tags->create('source:statuscake');
        (new RuleRepository($this->db, $this->runner))->save(null, 'Statuscake', 'all', false, true,
            [['field' => 'subject', 'operator' => 'contains', 'value' => 'x']], ['sources:statuscake', 'sources:other']);
        $id = (int) $this->db->table('tag_types')->where('name', 'sources')->get()->getRow()->id;

        $this->types->rename($id, 'source');

        $this->dontSeeInDatabase('tag_types', ['name' => 'sources']);
        $this->dontSeeInDatabase('tags', ['id' => $typo]);
        $this->seeNumRecords(1, 'tags', ['name' => 'source:statuscake']);
        $this->assertSame(['source:other', 'source:statuscake'], array_column($this->db->table('rule_actions')->orderBy('value')->get()->getResultArray(), 'value'));
    }

    public function testRenameAndDeleteRules(): void
    {
        $this->types->create('typ', null);
        $this->tags->create('typ:nas');
        $id = (int) $this->db->table('tag_types')->where('name', 'typ')->get()->getRow()->id;
        $this->types->rename($id, 'geraetetyp');
        $this->seeInDatabase('tags', ['name' => 'geraetetyp:nas']);
        $this->seeInDatabase('tag_types', ['id' => $id, 'name' => 'geraetetyp']);

        $this->assertSame('type_in_use', $this->error(fn () => $this->types->delete($id)));
        $this->tags->delete((int) $this->db->table('tags')->where('name', 'geraetetyp:nas')->get()->getRow()->id);
        $this->types->delete($id);
        $this->dontSeeInDatabase('tag_types', ['id' => $id]);

        $system = (int) $this->db->table('tag_types')->where('name', 'source')->get()->getRow()->id;
        $this->assertSame('type_system', $this->error(fn () => $this->types->rename($system, 'quelle')));
        $this->assertSame('type_system', $this->error(fn () => $this->types->delete($system)));
        $this->assertSame('type_exists', $this->error(fn () => $this->types->create('source', null)));
        $this->assertSame('type_name', $this->error(fn () => $this->types->create('a:b', null)));
    }

    public function testTagsPageShowsTypes(): void
    {
        $this->types->create('hersteller', 'Gerätehersteller');
        $user = ['user' => ['oid' => 'o', 'name' => 'Test', 'username' => 't@example.org', 'role' => 'ro', 'login_at' => time()]];
        $page = $this->withSession($user)->get('tags');
        $page->assertOK();
        $page->assertSee('Gerätehersteller');
        $this->assertStringContainsString('<option value="hersteller">', $page->getBody());
    }
}
