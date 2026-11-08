<?php

use App\Libraries\Inventory\Inventory;
use App\Libraries\Inventory\PatternService;
use App\Libraries\Rules\RuleRunner;
use App\Libraries\Rules\TagService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Subject patterns, aliases, unknown values and proposals (E-029).
 *
 * The reporting tool sends to n-sight_acme@report.example.org (source n-sight, customer acme = the
 * service provider); the subject names the actual customer, location and device.
 *
 * @internal
 */
final class PatternsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private RuleRunner $runner;
    private Inventory $inventory;
    private PatternService $patterns;
    private int $initech;
    private int $cloud;
    private int $srv;

    protected function setUp(): void
    {
        parent::setUp();
        config('Filters')->globals['before'] = ['rklogin' => ['except' => ['auth/*']]]; // no CSRF in tests
        $this->runner    = new RuleRunner($this->db);
        $this->inventory = new Inventory($this->db, $this->runner);
        $this->patterns  = new PatternService($this->db, $this->runner);
        $this->inventory->createCustomer('acme', 'ACME IT', null);
        $this->initech = $this->inventory->createCustomer('initech', 'Initech HH', null);
        $this->cloud   = $this->inventory->createLocation($this->initech, 'cloud', 'Cloud', null);
        $this->srv     = $this->inventory->createDevice($this->initech, $this->cloud, 'srv01', 'Srv01', null, []);
        (new TagService($this->db, $this->runner))->create('source:n-sight');
        $this->db->table('local_domains')->insert(['domain' => 'report.example.org', 'parse_address' => 1]);
    }

    private function addMessage(string $subject): int
    {
        $this->db->table('nodes')->ignore(true)->insert(['id' => 1, 'name' => 'n1', 'hostname' => 'n1.example']);
        $this->db->table('messages')->insert(['node_id' => 1, 'spool_id' => uniqid('s', true), 'received_at' => gmdate('Y-m-d H:i:s'),
            'mode' => 'mx', 'status' => 'accepted', 'envelope_recipient' => 'n-sight_acme@report.example.org', 'envelope_sender' => 'no-reply@rmm.example',
            'header_from_address' => 'no-reply@rmm.example', 'subject' => $subject, 'eml_path' => 'x.eml', 'eml_sha256' => str_repeat('0', 64), 'body_text' => '']);
        $id = (int) $this->db->insertID();
        $this->runner->processMessage($id);

        return $id;
    }

    private function tagsOf(int $id): array
    {
        return array_column($this->db->query('SELECT t.name, mt.source FROM message_tags mt JOIN tags t ON t.id = mt.tag_id
            WHERE mt.message_id = ? ORDER BY t.name', [$id])->getResultArray(), 'source', 'name');
    }

    private function as(string $role): static
    {
        return $this->withSession(['user' => ['oid' => 'o', 'name' => 'Test', 'username' => 't@example.org', 'role' => $role, 'login_at' => time()]]);
    }

    public function testPatternTagsReplaceTheCustomerOfTheAddress(): void
    {
        $before = $this->addMessage('Fehlerbericht - Initech HH, Cloud, srv01');
        $this->assertSame(['kunde:acme' => 'address', 'source:n-sight' => 'address'], $this->tagsOf($before));

        $pid = $this->patterns->addPattern('n-sight', 'Fehlerbericht - {kunde}, {standort}, {geraet}');
        $this->runner->processBatch();
        $this->assertSame([
            'geraet:initech.srv01'  => 'device',
            'kunde:initech'              => 'device',
            'source:n-sight'             => 'address',
            'standort:initech.cloud'     => 'device',
        ], $this->tagsOf($before));
        $row = $this->db->table('messages')->where('id', $before)->get()->getRowArray();
        $this->assertSame($pid, (int) $row['subject_pattern_id']);
        $this->assertNull($row['subject_unknown']);
        // another source: the pattern does not apply
        $this->db->table('messages')->where('id', $before)->update(['envelope_recipient' => 'other@elsewhere.example']);
        $this->runner->processMessage($before);
        $this->assertSame([], $this->tagsOf($before));
    }

    public function testAliasResolvesAnotherNameAndUnknownValuesAreListed(): void
    {
        $this->patterns->addPattern('n-sight', 'Fehlerbericht - {kunde}, {standort}, {geraet}');
        $id = $this->addMessage('Fehlerbericht - InitechDE, Cloud, srv01');
        $this->addMessage('Problem bei InitechDE, Tests auf vm-1'); // no pattern: proposal
        $this->addMessage('Problem bei Globex, Tests auf vm-2');

        $row = $this->db->table('messages')->where('id', $id)->get()->getRowArray();
        $this->assertSame([['part' => 'customer', 'value' => 'InitechDE', 'customer' => null]], json_decode($row['subject_unknown'], true));
        $this->assertSame(['kunde:acme' => 'address', 'source:n-sight' => 'address'], $this->tagsOf($id)); // nothing from the subject
        $unknown = $this->patterns->unknown();
        $this->assertCount(1, $unknown);
        $this->assertSame(['customer', 'InitechDE', 1], [$unknown[0]['part'], $unknown[0]['value'], $unknown[0]['messages']]);
        $this->assertSame([['source' => 'n-sight', 'pattern' => 'Problem bei {kunde}, Tests auf {geraet}']],
            array_map(static fn ($s) => ['source' => $s['source'], 'pattern' => $s['pattern']], $this->patterns->suggestions()));

        $this->patterns->addAlias('customer', $this->initech, 'InitechDE');
        $this->runner->processBatch();
        $this->assertArrayHasKey('geraet:initech.srv01', $this->tagsOf($id));
        $this->assertSame([], $this->patterns->unknown());
        // the alias now marks the customer word in proposals as well
        $this->assertSame('Problem bei {kunde}, Tests auf {geraet}', $this->patterns->suggestions()[0]['pattern']);

        $this->expectException(InvalidArgumentException::class);
        $this->patterns->addAlias('customer', $this->initech, 'initech-de'); // same key
    }

    public function testOptionalDeviceIsIgnoredWhenUnknown(): void
    {
        $this->patterns->addPattern('n-sight', 'Fehlerbericht - {kunde}, {standort}, {geraet?}');
        $id = $this->addMessage('Fehlerbericht - Initech HH, Cloud, banking01');
        $this->assertSame(['kunde:initech' => 'pattern', 'source:n-sight' => 'address', 'standort:initech.cloud' => 'pattern'], $this->tagsOf($id));
        $this->assertNull($this->db->table('messages')->where('id', $id)->get()->getRowArray()['subject_unknown']);
        $this->assertSame([], $this->patterns->unknown());
        // a known device is still used
        $known = $this->addMessage('Fehlerbericht - Initech HH, Cloud, srv01');
        $this->assertArrayHasKey('geraet:initech.srv01', $this->tagsOf($known));
    }

    public function testUnknownDeviceCanBeCreatedFromTheList(): void
    {
        $this->patterns->addPattern('n-sight', 'Fehlerbericht - {kunde}, {standort}, {geraet}');
        $id = $this->addMessage('Fehlerbericht - Initech HH, Cloud, FW-EDGE-2016R2');
        $this->assertSame('device', $this->patterns->unknown()[0]['part']);
        $this->patterns->create('device', $this->initech, 'fw-edge-2016r2', 'FW-EDGE-2016R2');
        $this->runner->processBatch();
        $this->assertArrayHasKey('geraet:initech.fw-edge-2016r2', $this->tagsOf($id));
    }

    public function testPageListsPatternsUnknownValuesAndProposals(): void
    {
        $this->addMessage('Fehlerbericht - Initech HH, Cloud, srv01');
        $this->addMessage('Fehlerbericht - Globex, Office, nb-1');
        $res = $this->as('rw')->get('patterns');
        $res->assertOK();
        $res->assertSee('Fehlerbericht - {kunde}, {standort}, {geraet}'); // proposal
        $res->assertSee('Fehlerbericht - Globex, Office, nb-1');

        $this->as('rw')->post('patterns', ['source' => 'n-sight', 'pattern' => 'Fehlerbericht - {kunde}, {standort}, {geraet}'])->assertRedirect();
        $this->assertSame(1, $this->db->table('subject_patterns')->countAllResults());
        $res = $this->as('rw')->get('patterns');
        $res->assertSee('Kunde Globex unbekannt');
        $res->assertSee('name="code"');

        $res = $this->as('rw')->get('patterns?test_source=n-sight&test_subject=' . urlencode('Fehlerbericht - initech hh, CLOUD, Srv01'));
        $res->assertSee('initech.srv01');
        $this->assertStringContainsString('{geraet}</code> = „Srv01“', $res->getBody());

        $this->as('rw')->post('patterns/aliases', ['target' => 'location:' . $this->cloud, 'alias' => 'Rechenzentrum'])->assertRedirect();
        $this->assertSame(1, $this->db->table('aliases')->where(['kind' => 'location', 'location_id' => $this->cloud])->countAllResults());
        $this->as('rw')->get('patterns')->assertSee('Rechenzentrum');

        $this->as('ro')->post('patterns', ['source' => 'n-sight', 'pattern' => '{kunde} x'])->assertStatus(403);
        $this->as('rw')->post('patterns', ['source' => 'n-sight', 'pattern' => 'no placeholder'])->assertSessionHas('error');
    }
}
