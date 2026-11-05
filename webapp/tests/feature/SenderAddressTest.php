<?php

use App\Libraries\Inventory\AddressService;
use App\Libraries\Inventory\Inventory;
use App\Libraries\Rules\RuleRunner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Tags from verified sender addresses of customer domains (E-020).
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
    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner    = new RuleRunner($this->db);
        $this->inventory = new Inventory($this->db, $this->runner);
        $this->service   = new AddressService($this->db, $this->runner);
        $this->customer  = $this->inventory->createCustomer('globex', 'Globex', null);
        $hq              = $this->inventory->createLocation($this->customer, 'hq1', 'HQ', null);
        $this->inventory->createDevice($this->customer, $hq, 'srv77', 'Server 77', null, []);
        $this->db->table('tags')->insert(['name' => 'source:plesk', 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->inventory->addDomain($this->customer, 'globex.example', true);
        $this->db->table('local_domains')->insert(['domain' => 'report.example.org', 'parse_address' => 1]);
        $this->db->table('nodes')->insert(['id' => 1, 'name' => 'n1', 'hostname' => 'n1.example']);
    }

    private static function auth(string $spfDomain = 'globex.example', ?string $dkimDomain = 'globex.example', string $spf = 'pass'): string
    {
        return json_encode(['spf' => ['result' => $spf, 'identity' => 'mailfrom', 'domain' => $spfDomain],
            'dkim' => $dkimDomain === null ? [] : [['result' => 'pass', 'domain' => $dkimDomain, 'selector' => 's1', 'reason' => '']]]);
    }

    private function addMessage(string $from, string $auth, string $recipient = 'inbox@other.example', string $mode = 'mx'): int
    {
        static $n = 0;
        $this->db->table('messages')->insert(['node_id' => 1, 'spool_id' => 's' . ++$n, 'received_at' => gmdate('Y-m-d H:i:s'),
            'mode' => $mode, 'status' => 'accepted', 'envelope_recipient' => $recipient, 'header_from_address' => $from,
            'auth_details' => $auth, 'eml_path' => 'x.eml', 'eml_sha256' => str_repeat('0', 64), 'body_text' => '']);

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

    public function testVerifiedSenderIsReadLikeAnAddress(): void
    {
        $id = $this->addMessage('Srv77_plesk@globex.example', self::auth('bounce.globex.example'));
        $this->runner->processBatch();
        $this->assertSame(['geraet:globex.srv77', 'kunde:globex', 'source:plesk', 'standort:globex.hq1'], $this->tags($id));
        $this->assertNull($this->row($id)['sender_unverified']);
    }

    public function testUnverifiedSendersYieldNoTags(): void
    {
        $unaligned = $this->addMessage('srv77_plesk@globex.example', self::auth('evil.example', 'evil.example'));
        $noDkim    = $this->addMessage('srv77_plesk@globex.example', self::auth('globex.example', null));
        $softfail  = $this->addMessage('srv77_plesk@globex.example', self::auth('globex.example', 'globex.example', 'softfail'));
        $auth      = $this->addMessage('srv77_plesk@globex.example', self::auth(), 'inbox@other.example', 'auth');
        $this->runner->processBatch();

        foreach ([$unaligned, $noDkim, $softfail, $auth] as $id) {
            $this->assertSame([], $this->tags($id));
        }
        $this->assertSame('spf:foreign dkim:foreign', $this->row($unaligned)['sender_unverified']);
        $this->assertSame('dkim:none', $this->row($noDkim)['sender_unverified']);
        $this->assertSame('spf:softfail', $this->row($softfail)['sender_unverified']);
        $this->assertNull($this->row($auth)['sender_unverified']); // SMTP AUTH: the login is the identity
        $this->assertSame(['srv77_plesk@globex.example'], array_column($this->service->unverified(), 'address'));
        $this->assertSame(0, $this->service->countUnknown());
    }

    public function testRecipientIsNotCheckedForSpfOrDkim(): void
    {
        $id = $this->addMessage('alerts@unrelated.example', json_encode(['spf' => ['result' => 'fail'], 'dkim' => []]), 'srv77_plesk_globex@report.example.org');
        $this->runner->processBatch();
        $this->assertContains('geraet:globex.srv77', $this->tags($id));
        $this->assertNull($this->row($id)['sender_unverified']);
    }

    public function testUnknownSenderPartsAreListedButMailboxesAreIgnored(): void
    {
        $unknown = $this->addMessage('srv78_plesk@globex.example', self::auth());
        $mailbox = $this->addMessage('info@globex.example', self::auth());
        $this->runner->processBatch();

        $this->assertSame([], $this->tags($mailbox));
        $this->assertNull($this->row($mailbox)['sender_unknown']);
        $list = $this->service->unknown();
        $this->assertCount(1, $list);
        $this->assertSame(['from', 'srv78_plesk@globex.example', 'globex'], [$list[0]['direction'], $list[0]['address'], $list[0]['customer']]);
        $this->assertSame([['part' => 'device', 'value' => 'srv78']], $list[0]['unknown']);

        // create the device from the list: belongs to the customer of the sender domain
        $this->service->create('srv78_plesk@globex.example', 'device', 'srv78', 'from');
        $this->runner->processBatch();
        $this->assertContains('geraet:globex.srv78', $this->tags($unknown));

        // a mailbox can still be mapped to a device
        $device = (int) $this->db->table('devices')->where('path', 'globex.srv77')->get()->getRow()->id;
        $this->service->map('info@globex.example', $device);
        $this->runner->processBatch();
        $this->assertContains('geraet:globex.srv77', $this->tags($mailbox));
        $this->assertSame(0, $this->service->countUnknown());
    }

    public function testRecipientAndSenderComplementOrConflict(): void
    {
        $initech = $this->inventory->createCustomer('initech', 'Initech', null);
        $this->inventory->createDevice($initech, null, 'nas1', 'NAS', null, []);
        $this->db->table('tags')->insert(['name' => 'source:backup', 'created_at' => gmdate('Y-m-d H:i:s')]);

        // recipient names only the source, the sender names the device
        $both = $this->addMessage('srv77@globex.example', self::auth(), 'backup_globex@report.example.org');
        // customers differ
        $conflict = $this->addMessage('srv77_plesk@globex.example', self::auth(), 'nas1_initech@report.example.org');
        $this->runner->processBatch();

        $this->assertSame(['geraet:globex.srv77', 'kunde:globex', 'source:backup', 'standort:globex.hq1'], $this->tags($both));
        $this->assertSame([], $this->tags($conflict));
        $this->assertSame([['part' => 'conflict', 'value' => 'initech']], json_decode($this->row($conflict)['sender_unknown'], true));
    }

    public function testDomainManagementAndPages(): void
    {
        $this->inventory->addDomain($this->customer, ' Mail.Globex.Example. ', false);
        $this->assertSame(1, $this->db->table('customer_domains')->where(['domain' => 'mail.globex.example', 'subdomains' => 0])->countAllResults());
        foreach ([['globex.example', 'domain_exists'], ['no_domain', 'domain'], ['localhost', 'domain']] as [$domain, $error]) {
            try {
                $this->inventory->addDomain($this->customer, $domain, false);
                $this->fail($domain);
            } catch (InvalidArgumentException $e) {
                $this->assertSame($error, $e->getMessage());
            }
        }

        $this->addMessage('srv77_plesk@globex.example', self::auth('globex.example', null));
        $this->addMessage('srv79_plesk@globex.example', self::auth());
        $this->runner->processBatch();
        $user = ['user' => ['oid' => 'o', 'name' => 'Test', 'username' => 't@example.org', 'role' => 'ro', 'login_at' => time()]];
        $page = $this->withSession($user)->get('addresses');
        $page->assertOK();
        $this->assertStringContainsString('srv79_plesk@globex.example', $page->getBody());
        $this->assertStringContainsString('keine DKIM-Signatur', $page->getBody());
        $this->withSession($user)->get("customers/{$this->customer}")->assertSee('mail.globex.example');
        $this->withSession($user)->post("customers/{$this->customer}/domains", ['domain' => 'x.example'])->assertStatus(403);
    }
}
