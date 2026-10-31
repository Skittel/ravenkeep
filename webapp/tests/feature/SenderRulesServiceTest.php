<?php

use App\Libraries\Nodes\NodeConfigSync;
use App\Libraries\Senders\SenderRuleService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class SenderRulesServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $dataDir;
    private int $nodeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = sys_get_temp_dir() . '/rk-cfg-' . bin2hex(random_bytes(4));
        mkdir("{$this->dataDir}/config-incoming", 0700, true);
        $this->db->table('nodes')->insert(['name' => 'test01', 'hostname' => 'test01.example', 'config_path' => $this->dataDir]);
        $this->nodeId = $this->db->insertID();

        $base = ['node_id' => $this->nodeId, 'received_at' => '2026-10-31 12:00:00', 'mode' => 'mx', 'status' => 'pending',
            'envelope_recipient' => 'x@customer.ravenkeep.cloud', 'eml_path' => 'x.eml', 'eml_sha256' => str_repeat('0', 64)];
        $this->db->table('messages')->insertBatch([
            $base + ['spool_id' => 'a', 'envelope_sender' => 'alert@mail.monitor.example', 'auth_verdict' => 'accepted'],
            $base + ['spool_id' => 'b', 'envelope_sender' => 'bounce@monitor.example', 'auth_verdict' => 'rejected'],
            $base + ['spool_id' => 'c', 'envelope_sender' => 'x@notmonitor.example', 'auth_verdict' => 'accepted'],
            array_merge($base, ['spool_id' => 'd', 'envelope_sender' => 'device@customer.ravenkeep.cloud', 'auth_verdict' => null, 'mode' => 'auth', 'status' => 'accepted']),
        ]);
    }

    public function testAllowReclassifiesPendingMessagesAndDeleteRevertsThem(): void
    {
        $service = new SenderRuleService($this->db);

        $this->assertSame(2, $service->save('Monitor.Example', 'envelope', 'allow'));
        $this->seeInDatabase('messages', ['spool_id' => 'a', 'status' => 'accepted', 'list_match_pattern' => 'monitor.example']);
        $this->seeInDatabase('messages', ['spool_id' => 'b', 'status' => 'rejected']);
        $this->seeInDatabase('messages', ['spool_id' => 'c', 'status' => 'pending']);   // other domain
        $this->seeInDatabase('messages', ['spool_id' => 'd', 'status' => 'accepted']);  // auth mode untouched

        $this->assertSame(2, $service->save('monitor.example', 'envelope', 'block'));   // same rule switched to block
        $this->seeInDatabase('messages', ['spool_id' => 'a', 'status' => 'blocked']);
        $this->seeNumRecords(1, 'sender_rules', []);

        $rule = $this->db->table('sender_rules')->get()->getRowArray();
        $this->assertSame(2, $service->delete((int) $rule['id']));
        $this->seeInDatabase('messages', ['spool_id' => 'a', 'status' => 'pending', 'list_match_pattern' => null]);
    }

    public function testInvalidRuleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SenderRuleService($this->db))->save('com', 'envelope', 'allow');
    }

    public function testConfigBundleIsPushedOnceAndStatusIsReadBack(): void
    {
        (new SenderRuleService($this->db))->save('monitor.example', 'envelope', 'allow');
        $sync = new NodeConfigSync($this->db);

        $this->assertStringStartsWith('pushed', $sync->syncAll()['test01']);
        $manifest = json_decode((string) file_get_contents("{$this->dataDir}/config-incoming/manifest.json"), true);
        $content  = (string) file_get_contents("{$this->dataDir}/config-incoming/allowed-senders");
        $this->assertSame(hash('sha256', $content), $manifest['files']['allowed-senders']);
        $this->assertStringContainsString('monitor.example', $content);
        $this->assertSame('up to date', $sync->syncAll()['test01']);

        // The node reports the applied bundle
        file_put_contents("{$this->dataDir}/config-status.json", json_encode(['applied_hash' => $manifest['hash'], 'applied_at' => '2026-10-31T12:00:00Z', 'error' => null]));
        $sync->refreshStatus();
        $this->seeInDatabase('nodes', ['id' => $this->nodeId, 'config_applied_hash' => $manifest['hash'], 'config_hash' => $manifest['hash']]);
    }
}
