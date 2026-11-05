<?php

use App\Libraries\Rules\RuleRepository;
use App\Libraries\Rules\RuleRunner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class RuleRunnerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private RuleRunner $runner;
    private RuleRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['typ', 'hersteller', 'status', 'aufgabe', 'sources'] as $type) { // managed tag types (E-023)
            $this->db->table('tag_types')->insert(['name' => $type]);
        }
        $this->db->table('nodes')->insert(['name' => 'test01', 'hostname' => 'test01.example']);
        $nodeId = $this->db->insertID();
        $base   = ['node_id' => $nodeId, 'received_at' => '2026-10-31 12:00:00', 'mode' => 'mx', 'envelope_recipient' => 'x@customer.ravenkeep.cloud',
            'eml_path' => 'x.eml', 'eml_sha256' => str_repeat('0', 64), 'body_text' => '', 'envelope_sender' => 'alert@monitor.example'];
        $this->db->table('messages')->insertBatch([
            $base + ['spool_id' => 'acc', 'status' => 'accepted', 'subject' => '[Error] disk'],
            $base + ['spool_id' => 'rej', 'status' => 'rejected', 'subject' => '[Error] disk'],
        ]);
        $this->runner = new RuleRunner($this->db);
        $this->repo   = new RuleRepository($this->db, $this->runner);
    }

    private function tagsOf(string $spoolId): array
    {
        return array_column($this->db->query('SELECT t.name FROM message_tags mt JOIN tags t ON t.id = mt.tag_id JOIN messages m ON m.id = mt.message_id
            WHERE m.spool_id = ? ORDER BY t.name', [$spoolId])->getResultArray(), 'name');
    }

    public function testOnlyAcceptedMessagesGetRuleTagsAndChangesAreReapplied(): void
    {
        $id = $this->repo->save(null, 'Errors', 'all', false, true, [['field' => 'subject', 'operator' => 'contains', 'value' => '[error]']], ['status:error']);
        $this->assertSame(2, $this->runner->processBatch());
        $this->assertSame(['status:error'], $this->tagsOf('acc'));
        $this->assertSame([], $this->tagsOf('rej'));
        $this->assertSame(0, $this->runner->backlog());

        // Manual tag survives re-evaluation; rule change re-tags in the background
        $msgId = (int) $this->db->table('messages')->where('spool_id', 'acc')->get()->getRow()->id;
        $this->db->table('message_tags')->insert(['message_id' => $msgId, 'tag_id' => $this->runner->tagId('wichtig'), 'source' => 'manual']);
        $this->repo->save($id, 'Errors', 'all', false, true, [['field' => 'subject', 'operator' => 'contains', 'value' => '[error]']], ['status:fehler']);
        $this->assertSame(2, $this->runner->backlog());
        $this->runner->processBatch();
        $this->assertSame(['status:fehler', 'wichtig'], $this->tagsOf('acc'));

        $this->repo->toggle($id);
        $this->runner->processBatch();
        $this->assertSame(['wichtig'], $this->tagsOf('acc'));
    }

    public function testInvalidRulesAreRejected(): void
    {
        foreach ([
            ['', [['field' => 'subject', 'operator' => 'contains', 'value' => 'x']], ['a']],
            ['n', [['field' => 'subject', 'operator' => 'has', 'value' => 'x']], ['a']],      // tag operator on text field
            ['n', [['field' => 'nope', 'operator' => 'contains', 'value' => 'x']], ['a']],
            ['n', [['field' => 'subject', 'operator' => 'contains', 'value' => '']], ['a']],
            ['n', [['field' => 'subject', 'operator' => 'contains', 'value' => 'x']], []],
        ] as [$name, $conditions, $tags]) {
            try {
                $this->repo->save(null, $name, 'all', false, true, $conditions, $tags);
                $this->fail('accepted invalid rule ' . json_encode([$name, $conditions, $tags]));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->seeNumRecords(0, 'rules', []);
    }

    public function testMoveChangesEvaluationOrder(): void
    {
        $a = $this->repo->save(null, 'A', 'all', false, true, [['field' => 'subject', 'operator' => 'contains', 'value' => 'x']], ['aufgabe:a']);
        $b = $this->repo->save(null, 'B', 'all', false, true, [['field' => 'subject', 'operator' => 'contains', 'value' => 'x']], ['aufgabe:b']);
        $this->repo->move($b, -1);
        $this->assertSame([$b, $a], array_map(static fn ($r) => (int) $r['id'], $this->repo->all()));
    }
}
