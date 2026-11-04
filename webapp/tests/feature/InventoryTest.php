<?php

use App\Libraries\Inventory\Inventory;
use App\Libraries\Nodes\NodeSettings;
use App\Libraries\Rules\RuleRepository;
use App\Libraries\Rules\RuleRunner;
use App\Libraries\Rules\TagService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Customers, locations, devices, device tags in messages, timeout monitoring, tag management.
 *
 * @internal
 */
final class InventoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private RuleRunner $runner;
    private Inventory $inventory;
    private int $customer;
    private int $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner    = new RuleRunner($this->db);
        $this->inventory = new Inventory($this->db, $this->runner);
        $this->customer  = $this->inventory->createCustomer('Acme', 'ACME GmbH', null);
        $this->location  = $this->inventory->createLocation($this->customer, 'branch2', 'Branch 2', null);
        (new NodeSettings($this->db))->addDomain('customer.example.org', null);
    }

    private function addMessage(string $spoolId, array $data): int
    {
        $this->db->table('nodes')->ignore(true)->insert(['id' => 1, 'name' => 'n1', 'hostname' => 'n1.example']);
        $this->db->table('messages')->insert($data + ['node_id' => 1, 'spool_id' => $spoolId, 'received_at' => gmdate('Y-m-d H:i:s'),
            'mode' => 'auth', 'status' => 'accepted', 'envelope_recipient' => 'status@customer.example.org', 'eml_path' => 'x.eml',
            'eml_sha256' => str_repeat('0', 64), 'body_text' => '']);

        return (int) $this->db->insertID();
    }

    private function tagsOf(int $messageId): array
    {
        return array_column($this->db->query('SELECT t.name, mt.source FROM message_tags mt JOIN tags t ON t.id = mt.tag_id
            WHERE mt.message_id = ? ORDER BY t.name', [$messageId])->getResultArray(), 'source', 'name');
    }

    public function testDeviceGetsAutomaticAndAssignedTags(): void
    {
        $id = $this->inventory->createDevice($this->customer, $this->location, 'NAS05', 'NAS05', null, ['typ:nas', 'QNAP', 'qnap']);

        $this->seeInDatabase('devices', ['id' => $id, 'path' => 'acme.nas05']);
        $this->assertSame(['kunde:acme', 'standort:acme.branch2', 'geraet:acme.nas05', 'qnap', 'typ:nas'], $this->inventory->deviceTags($id));

        $noLocation = $this->inventory->createDevice($this->customer, null, 'pbs', 'PBS', null, []);
        $this->assertSame(['kunde:acme', 'geraet:acme.pbs'], $this->inventory->deviceTags($noLocation));
    }

    public function testValidation(): void
    {
        $cases = [
            'code'         => fn () => $this->inventory->createCustomer('Bad Code', 'x', null),
            'code_exists'  => fn () => $this->inventory->createCustomer('acme', 'x', null),
            'reserved_tag' => fn () => $this->inventory->createDevice($this->customer, null, 'd1', 'D1', null, ['kunde:other']),
            'location'     => fn () => $this->inventory->createDevice($this->customer, 999, 'd2', 'D2', null, []),
            'not_empty'    => fn () => $this->inventory->deleteCustomer($this->customer),
        ];
        foreach ($cases as $expected => $call) {
            try {
                $call();
                $this->fail("no exception for {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertSame($expected, $e->getMessage());
            }
        }
    }

    public function testSmtpLoginIsNamedAfterDeviceAndItsMessagesGetDeviceTags(): void
    {
        $device = $this->inventory->createDevice($this->customer, $this->location, 'nas05', 'NAS05', null, ['typ:nas']);
        [, , $username] = (new NodeSettings($this->db))->createIdentityForDevice($device, 'customer.example.org', null);
        $this->assertSame('acme.nas05@customer.example.org', $username);

        $this->expectExceptionMessage('device_has_login');
        (new NodeSettings($this->db))->createIdentityForDevice($device, 'customer.example.org', null);
    }

    public function testRulesCanUseDeviceTags(): void
    {
        $device = $this->inventory->createDevice($this->customer, $this->location, 'nas05', 'NAS05', null, ['typ:nas']);
        (new NodeSettings($this->db))->createIdentityForDevice($device, 'customer.example.org', null);
        (new RuleRepository($this->db, $this->runner))->save(null, 'NAS errors', 'all', false, true, [
            ['field' => 'tag', 'operator' => 'has', 'value' => 'typ:nas'],
            ['field' => 'subject', 'operator' => 'contains', 'value' => '[error]'],
        ], ['status:error']);

        $error = $this->addMessage('a', ['sasl_username' => 'ACME.nas05@customer.example.org', 'subject' => '[Error] RAID degraded']);
        $info  = $this->addMessage('b', ['sasl_username' => 'acme.nas05@customer.example.org', 'subject' => '[Info] ok']);
        $other = $this->addMessage('c', ['sasl_username' => 'unknown@customer.example.org', 'subject' => '[Error] x']);
        $this->runner->processBatch();

        $this->assertSame(['geraet:acme.nas05' => 'device', 'kunde:acme' => 'device', 'standort:acme.branch2' => 'device',
            'status:error' => 'rule', 'typ:nas' => 'device'], $this->tagsOf($error));
        $this->assertArrayNotHasKey('status:error', $this->tagsOf($info));
        $this->assertSame([], $this->tagsOf($other));

        // Changing the device tags re-evaluates its messages
        $this->inventory->updateDevice($device, 'NAS05', null, ['typ:storage']);
        $this->runner->processBatch();
        $this->assertArrayNotHasKey('status:error', $this->tagsOf($error));
        $this->assertArrayHasKey('typ:storage', $this->tagsOf($error));
    }

    public function testAddressTagsForAcceptedMessagesOfMarkedDomains(): void
    {
        $this->db->table('local_domains')->insert(['domain' => 'report.example.org', 'parse_address' => 1]);
        $this->inventory->createDevice($this->customer, $this->location, 'pm2610', 'PM2610', null, ['hersteller:dell']);

        $known   = $this->addMessage('a', ['mode' => 'mx', 'envelope_recipient' => 'gc.pm2610_proxmox_2og.branch2_acme@report.example.org']);
        $unknown = $this->addMessage('b', ['mode' => 'mx', 'envelope_recipient' => 'nas09_other@report.example.org']);
        $pending = $this->addMessage('c', ['mode' => 'mx', 'status' => 'pending', 'envelope_recipient' => 'nas09_other@report.example.org']);
        $plain   = $this->addMessage('d', ['mode' => 'mx', 'envelope_recipient' => 'nas09_other@customer.example.org']);
        $this->runner->processBatch();

        // known device: its own tags (source device) plus the remaining address tags
        $this->assertSame([
            'geraet:acme.pm2610' => 'device', 'hersteller:dell' => 'device', 'kunde:acme' => 'device', 'meldung:gc' => 'address',
            'standort:acme.branch2' => 'device', 'standort:acme.branch2.2og' => 'address', 'typ:proxmox' => 'address',
        ], $this->tagsOf($known));
        // unknown device: new tags are created from the address
        $this->assertSame(['geraet:other.nas09' => 'address', 'kunde:other' => 'address'], $this->tagsOf($unknown));
        $this->seeInDatabase('tags', ['name' => 'kunde:other']);
        // only accepted messages (E-002), only marked domains
        $this->assertSame([], $this->tagsOf($pending));
        $this->assertSame([], $this->tagsOf($plain));
    }

    public function testFixedRulesWinOverAutomaticTagsOfTheSameType(): void
    {
        $this->db->table('local_domains')->insert(['domain' => 'report.example.org', 'parse_address' => 1]);
        (new RuleRepository($this->db, $this->runner))->save(null, 'Mailhardener belongs to skit', 'all', false, true, [
            ['field' => 'header_from', 'operator' => 'ends_with', 'value' => '@mailhardener.example'],
        ], ['kunde:skit']);

        $id = $this->addMessage('a', ['mode' => 'mx', 'header_from_address' => 'alerts@mailhardener.example',
            'envelope_recipient' => 'mh_hb_acme@report.example.org']);
        $this->runner->processBatch();

        $this->assertSame(['geraet:acme.mh' => 'address', 'kunde:skit' => 'rule', 'standort:acme.hb' => 'address'], $this->tagsOf($id));
    }

    public function testMonitoringMarksOverdueDevices(): void
    {
        $daily  = $this->inventory->createProfile('Daily', 24, null);
        $onTime = $this->inventory->createDevice($this->customer, null, 'ontime', 'On time', null, [], $daily);
        $late   = $this->inventory->createDevice($this->customer, null, 'late', 'Late', null, [], $daily);
        $never  = $this->inventory->createDevice($this->customer, null, 'never', 'Never', null, [], $daily);
        $this->inventory->createDevice($this->customer, null, 'free', 'Unmonitored', null, []);
        $this->db->table('devices')->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);

        $tag = fn (string $path, int $age, string $status = 'accepted') => $this->db->table('message_tags')->insert([
            'message_id' => $this->addMessage(uniqid(), ['received_at' => gmdate('Y-m-d H:i:s', time() - $age), 'status' => $status]),
            'tag_id' => $this->runner->tagId('geraet:' . $path), 'source' => 'rule']);
        $tag('acme.ontime', 3600);
        $tag('acme.late', 30 * 3600);
        $tag('acme.late', 3600, 'rejected');   // rejected messages do not count

        $state = array_column($this->inventory->monitoring(), null, 'id');
        $this->assertCount(3, $state);
        $this->assertFalse($state[$onTime]['overdue']);
        $this->assertTrue($state[$late]['overdue']);
        $this->assertSame(6, $state[$late]['overdue_hours']);
        $this->assertTrue($state[$never]['overdue']);
        $this->assertNull($state[$never]['last_seen']);
        // overdue first, longest overdue first: "never" was due 2 days ago, "late" 6 hours ago
        $this->assertSame([$never, $late, $onTime], array_column($this->inventory->monitoring(), 'id'));
    }

    public function testTagRenameFollowsRulesAndDeleteIsBlockedWhileUsed(): void
    {
        $tags = new TagService($this->db, $this->runner);
        $id   = $tags->create('Typ:NAS');
        (new RuleRepository($this->db, $this->runner))->save(null, 'r', 'all', false, true,
            [['field' => 'tag', 'operator' => 'has', 'value' => 'typ:nas']], ['typ:nas-error']);

        $tags->rename($id, 'typ:storage');
        $this->seeInDatabase('rule_conditions', ['field' => 'tag', 'value' => 'typ:storage']);

        try {
            $tags->delete($id);
            $this->fail('deleted tag used in rules');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('tag_in_rules', $e->getMessage());
        }
        $reserved = $this->runner->tagId('kunde:acme');
        $this->expectExceptionMessage('reserved_tag');
        $tags->rename($reserved, 'kunde:other');
    }
}
