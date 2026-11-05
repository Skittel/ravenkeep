<?php

use App\Libraries\Inventory\AddressService;
use App\Libraries\Inventory\Inventory;
use App\Libraries\Rules\RuleRunner;
use App\Libraries\Senders\SenderRuleService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Checks per allow rule and tags from sender addresses (E-020, E-021).
 *
 * @internal
 */
final class SenderAddressTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private RuleRunner $runner;
    private Inventory $inventory;
    private AddressService $service;
    private SenderRuleService $senders;
    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner    = new RuleRunner($this->db);
        $this->inventory = new Inventory($this->db, $this->runner);
        $this->service   = new AddressService($this->db, $this->runner);
        $this->senders   = new SenderRuleService($this->db);
        $this->customer  = $this->inventory->createCustomer('globex', 'Globex', null);
        $hq              = $this->inventory->createLocation($this->customer, 'hq1', 'HQ', null);
        $this->inventory->createDevice($this->customer, $hq, 'srv77', 'Server 77', null, []);
        $this->db->table('tags')->insert(['name' => 'source:plesk', 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->db->table('local_domains')->insert(['domain' => 'report.example.org', 'parse_address' => 1]);
        $this->db->table('nodes')->insert(['id' => 1, 'name' => 'n1', 'hostname' => 'n1.example']);
    }

    private static function auth(bool $spf, bool $dkim): string
    {
        return json_encode(['spf' => ['result' => $spf ? 'pass' : 'softfail'], 'dkim' => [],
            'alignment' => ['spf_aligned' => $spf, 'dkim_aligned' => $dkim]]);
    }

    /** A message as collected: status from the current sender rules. */
    private function addMessage(string $from, bool $spf, bool $dkim, string $recipient = 'inbox@other.example', string $envelope = ''): int
    {
        static $n = 0;
        $row = ['envelope_sender' => $envelope ?: $from, 'header_from_address' => $from, 'auth_details' => self::auth($spf, $dkim),
            'auth_verdict' => $spf || $dkim ? 'accepted' : 'rejected'];
        $this->db->table('messages')->insert($row + $this->senders->rules()->classify($row) + ['node_id' => 1, 'spool_id' => 's' . ++$n,
            'received_at' => gmdate('Y-m-d H:i:s'), 'mode' => 'mx', 'envelope_recipient' => $recipient,
            'eml_path' => 'x.eml', 'eml_sha256' => str_repeat('0', 64), 'body_text' => '']);

        return (int) $this->db->insertID();
    }

    private function tags(int $id): array
    {
        return array_column($this->db->query('SELECT t.name FROM message_tags mt JOIN tags t ON t.id = mt.tag_id WHERE mt.message_id = ? ORDER BY t.name', [$id])->getResultArray(), 'name');
    }

    private function row(int $id): array
    {
        return $this->db->table('messages')->where('id', $id)->get()->getRowArray();
    }

    public function testCheckPerRuleDecidesAcceptanceAndIsReclassified(): void
    {
        $this->senders->save('plesk.example', 'header', 'allow', null, ['auth_check' => 'spf_and_dkim']);
        $id = $this->addMessage('srv77@plesk.example', true, false);
        $this->assertSame('rejected', $this->row($id)['status']);

        $this->senders->save('plesk.example', 'header', 'allow', null, ['auth_check' => 'spf']);
        $this->assertSame('accepted', $this->row($id)['status']);

        // nothing passed: only accepted with "no check", which must be set explicitly
        $none = $this->addMessage('alerts@legacy.example', false, false);
        $this->senders->save('legacy.example', 'header', 'allow');
        $this->assertSame('rejected', $this->row($none)['status']);
        $this->senders->save('legacy.example', 'header', 'allow', null, ['auth_check' => 'none']);
        $this->assertSame('accepted', $this->row($none)['status']);

        // quick buttons (no settings) keep the check of an existing rule
        $this->senders->save('legacy.example', 'header', 'allow');
        $this->assertSame('none', $this->db->table('sender_rules')->where('pattern', 'legacy.example')->get()->getRow()->auth_check);
    }

    public function testSourceDomainSenderNamesTheDevice(): void
    {
        $this->senders->save('plesk.example', 'header', 'allow', null, ['auth_check' => 'spf', 'tag_source' => 'plesk']);
        $id = $this->addMessage('Srv77@plesk.example', true, false);
        $this->runner->processBatch();
        $this->assertSame(['geraet:globex.srv77', 'kunde:globex', 'source:plesk', 'standort:globex.hq1'], $this->tags($id));

        // unknown device: listed, only mapping possible (no customer)
        $unknown = $this->addMessage('srv99@plesk.example', true, false);
        $this->runner->processBatch();
        $list = $this->service->unknown();
        $this->assertSame([['from', 'srv99@plesk.example', null]], array_map(static fn ($u) => [$u['direction'], $u['address'], $u['customer']], $list));
        $device = (int) $this->db->table('devices')->where('path', 'globex.srv77')->get()->getRow()->id;
        $this->service->map('srv99@plesk.example', $device);
        $this->runner->processBatch();
        $this->assertContains('geraet:globex.srv77', $this->tags($unknown));
        $this->assertContains('source:plesk', $this->tags($unknown));
    }

    public function testCustomerDomainAndMailboxes(): void
    {
        $this->senders->save('globex.example', 'header', 'allow', null, ['tag_customer_id' => $this->customer]);
        $id      = $this->addMessage('srv77_plesk@globex.example', false, true);
        $unknown = $this->addMessage('srv78_plesk@globex.example', true, false);
        $mailbox = $this->addMessage('info@globex.example', true, false);
        $this->runner->processBatch();

        $this->assertSame(['geraet:globex.srv77', 'kunde:globex', 'source:plesk', 'standort:globex.hq1'], $this->tags($id));
        $this->assertSame([], $this->tags($mailbox));
        $this->assertSame(['srv78_plesk@globex.example'], array_column($this->service->unknown(), 'address'));
        $this->service->create('srv78_plesk@globex.example', 'device', 'srv78', 'from');
        $this->runner->processBatch();
        $this->assertContains('geraet:globex.srv78', $this->tags($unknown));
    }

    public function testSenderOfAnotherRuleIsNotTrusted(): void
    {
        // accepted through the envelope rule, but the From rule with a meaning requires DKIM
        $this->senders->save('bounce.example', 'envelope', 'allow');
        $this->senders->save('plesk.example', 'header', 'allow', null, ['auth_check' => 'dkim', 'tag_source' => 'plesk']);
        $id = $this->addMessage('srv77@plesk.example', true, false, 'inbox@other.example', 'x@bounce.example');
        $this->runner->processBatch();

        $this->assertSame('accepted', $this->row($id)['status']);
        $this->assertSame([], $this->tags($id));
        $this->assertSame('dkim', $this->row($id)['sender_unverified']);
        $this->assertSame(['srv77@plesk.example'], array_column($this->service->unverified(), 'address'));
    }

    public function testRecipientIsNeverChecked(): void
    {
        $this->senders->save('unrelated.example', 'header', 'allow', null, ['auth_check' => 'none']);
        $id = $this->addMessage('alerts@unrelated.example', false, false, 'srv77_plesk_globex@report.example.org');
        $this->runner->processBatch();
        $this->assertContains('geraet:globex.srv77', $this->tags($id));
    }

    public function testRecipientAndSenderComplementOrConflict(): void
    {
        $this->senders->save('plesk.example', 'header', 'allow', null, ['auth_check' => 'spf', 'tag_source' => 'plesk']);
        $initech = $this->inventory->createCustomer('initech', 'Initech', null);
        $this->inventory->createDevice($initech, null, 'nas1', 'NAS', null, []);
        $this->db->table('tags')->insert(['name' => 'source:backup', 'created_at' => gmdate('Y-m-d H:i:s')]);

        $both     = $this->addMessage('srv77@plesk.example', true, false, 'backup_globex@report.example.org');
        $conflict = $this->addMessage('srv77@plesk.example', true, false, 'nas1_initech@report.example.org');
        $this->runner->processBatch();

        $this->assertSame(['geraet:globex.srv77', 'kunde:globex', 'source:backup', 'source:plesk', 'standort:globex.hq1'], $this->tags($both));
        $this->assertSame([], $this->tags($conflict));
        $this->assertSame([['part' => 'conflict', 'value' => 'initech']], json_decode($this->row($conflict)['sender_unknown'], true));
    }

    public function testValidationAndPages(): void
    {
        foreach ([[['auth_check' => 'maybe'], 'check'], [['tag_customer_id' => $this->customer, 'tag_source' => 'plesk'], 'tag_meaning'],
            [['tag_customer_id' => 999], 'customer'], [['tag_source' => 'Not valid!'], 'source']] as [$settings, $error]) {
            try {
                $this->senders->save('x.example', 'header', 'allow', null, $settings);
                $this->fail($error);
            } catch (InvalidArgumentException $e) {
                $this->assertSame($error, $e->getMessage());
            }
        }
        $this->senders->save('globex.example', 'header', 'allow', null, ['auth_check' => 'spf', 'tag_customer_id' => $this->customer]);
        $this->senders->save('plesk.example', 'header', 'allow', null, ['auth_check' => 'none', 'tag_source' => 'source:plesk']);
        $this->assertSame('plesk', $this->db->table('sender_rules')->where('pattern', 'plesk.example')->get()->getRow()->tag_source);

        $user = ['user' => ['oid' => 'o', 'name' => 'Test', 'username' => 't@example.org', 'role' => 'ro', 'login_at' => time()]];
        $page = $this->withSession($user)->get('senders');
        $page->assertOK();
        $this->assertStringContainsString('nur SPF', $page->getBody());
        $this->assertStringContainsString('keine Prüfung', $page->getBody());
        $this->withSession($user)->get("customers/{$this->customer}")->assertSee('globex.example');
        $this->withSession($user)->get('addresses')->assertOK();
    }
}
