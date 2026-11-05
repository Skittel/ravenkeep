<?php

use App\Libraries\Inventory\Inventory;
use App\Libraries\Inventory\LoginTagSuggestion;
use App\Libraries\Nodes\NodeSettings;
use App\Libraries\Rules\RuleRunner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * SMTP login per component with fixed tags (E-024).
 *
 * @internal
 */
final class SmtpLoginTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private RuleRunner $runner;
    private Inventory $inventory;
    private NodeSettings $settings;
    private int $customer;
    private int $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner    = new RuleRunner($this->db);
        $this->inventory = new Inventory($this->db, $this->runner);
        $this->settings  = new NodeSettings($this->db);
        $this->settings->addDomain('customer.example.org', null);
        $this->db->table('local_domains')->where('domain', 'customer.example.org')->update(['parse_address' => 1]);
        $this->customer = $this->inventory->createCustomer('acme', 'ACME', null);
        $hq             = $this->inventory->createLocation($this->customer, 'hq1', 'HQ', null);
        $this->device   = $this->inventory->createDevice($this->customer, $hq, 'pm10', 'PM10', null, []);
        $this->db->table('nodes')->insert(['id' => 1, 'name' => 'n1', 'hostname' => 'n1.example']);
    }

    private function message(string $login, string $recipient = 'status@customer.example.org'): int
    {
        static $n = 0;
        $this->db->table('messages')->insert(['node_id' => 1, 'spool_id' => 's' . ++$n, 'received_at' => gmdate('Y-m-d H:i:s'), 'mode' => 'auth',
            'status' => 'accepted', 'sasl_username' => $login, 'envelope_recipient' => $recipient, 'eml_path' => 'x.eml',
            'eml_sha256' => str_repeat('0', 64), 'body_text' => '']);

        return (int) $this->db->insertID();
    }

    private function tags(int $id): array
    {
        return array_column($this->db->query('SELECT t.name FROM message_tags mt JOIN tags t ON t.id = mt.tag_id WHERE mt.message_id = ? ORDER BY t.name', [$id])->getResultArray(), 'name');
    }

    public function testSeveralLoginsPerDeviceWithOwnTags(): void
    {
        [$ipmi] = $this->settings->createIdentity('pm10_ipmi_hq1_acme', 'customer.example.org', null, $this->device);
        [$pbs]  = $this->settings->createIdentity('pm10_pbs_hq1_acme', 'customer.example.org', null, $this->device);
        $this->settings->setIdentity($ipmi, $this->device, ['source:ipmi'], null);
        $this->settings->setIdentity($pbs, $this->device, ['source:pbs'], 'Backup');

        $a = $this->message('PM10_ipmi_hq1_acme@customer.example.org');
        $b = $this->message('pm10_pbs_hq1_acme@customer.example.org');
        $this->runner->processBatch();

        $this->assertSame(['geraet:acme.pm10', 'kunde:acme', 'source:ipmi', 'standort:acme.hq1'], $this->tags($a));
        $this->assertSame(['geraet:acme.pm10', 'kunde:acme', 'source:pbs', 'standort:acme.hq1'], $this->tags($b));
        // the recipient status@ is not read, so nothing lands in the clearing list
        $this->assertNull($this->db->table('messages')->where('id', $a)->get()->getRow()->address_unknown);
    }

    public function testLoginWithoutDeviceOrTagsStillSkipsTheRecipient(): void
    {
        $this->settings->createIdentity('test-login', 'customer.example.org', null);
        $id = $this->message('test-login@customer.example.org');
        $this->runner->processBatch();
        $this->assertSame([], $this->tags($id));
        $this->assertNull($this->db->table('messages')->where('id', $id)->get()->getRow()->address_unknown);
    }

    public function testSuggestionFromTheUserName(): void
    {
        $known = $this->inventory->addressLookup();
        $s     = LoginTagSuggestion::suggest('pm10_ipmi_hq1_acme@customer.example.org', $known);
        $this->assertSame([['part' => 'source', 'value' => 'ipmi']], $s['unknown']); // 4 groups: source must be known
        $this->db->table('tags')->insert(['name' => 'source:ipmi', 'created_at' => gmdate('Y-m-d H:i:s')]);

        $s = LoginTagSuggestion::suggest('gc.pm10_ipmi_hq1_acme@customer.example.org', $this->inventory->addressLookup());
        $this->assertSame('acme.pm10', $s['device']);
        $this->assertSame(['source:ipmi', 'meldung:gc'], $s['tags']); // kunde/standort/geraet come from the device
        $this->assertFalse(LoginTagSuggestion::suggest('initech@customer.example.org', $known)['scheme']);
    }

    public function testValidationAndPages(): void
    {
        [$id] = $this->settings->createIdentity('pm10_ipmi_hq1_acme', 'customer.example.org', null);
        // all types allowed, also kunde/standort/geraet: a login without a device
        $this->settings->setIdentity($id, null, ['kunde:acme', 'standort:acme.hq1', 'source:ipmi'], null);
        $msg = $this->message('pm10_ipmi_hq1_acme@customer.example.org');
        $this->runner->processBatch();
        $this->assertSame(['kunde:acme', 'source:ipmi', 'standort:acme.hq1'], $this->tags($msg));
        foreach ([['tag_type', ['typo:x']]] as [$error, $tags]) {
            try {
                $this->settings->setIdentity($id, null, $tags, null);
                $this->fail($error);
            } catch (InvalidArgumentException $e) {
                $this->assertSame($error, $e->getMessage());
            }
        }
        $user = ['user' => ['oid' => 'o', 'name' => 'Test', 'username' => 't@example.org', 'role' => 'ro', 'login_at' => time()]];
        $page = $this->withSession($user)->get('devices/' . $id);
        $page->assertOK();
        $page->assertSee('acme.pm10'); // source:ipmi exists now: the suggestion names the device
        $this->withSession($user)->get('devices')->assertSee('pm10_ipmi_hq1_acme@customer.example.org');
        $this->withSession($user)->post('devices/' . $id, ['tags' => 'source:x'])->assertStatus(403);
        $this->withSession($user)->post('devices/' . $id . '/show')->assertStatus(403); // read-only users never see a password

        // rw: access data with the stored password, masked, with the eye button
        $keyFile = tempnam(sys_get_temp_dir(), 'rk');
        $key     = random_bytes(32);
        file_put_contents($keyFile, base64_encode($key));
        putenv('RAVENKEEP_SECRET_KEY_FILE=' . $keyFile);
        try {
            $this->db->table('smtp_identities')->where('id', $id)->update(['password_enc' => (new App\Libraries\Secrets($key))->encrypt('Sup3r-Geheim-Kennwort')]);
            $rw       = ['user' => ['role' => 'rw'] + $user['user']];
            $form     = $this->withSession($rw)->get('devices/' . $id);
            $security = config('Security');
            preg_match('/name="' . $security->tokenName . '" value="([^"]+)"/', $form->getBody(), $m);
            $page = $this->withSession($rw)->post('devices/' . $id . '/show', [$security->tokenName => $m[1]]);
            $page->assertOK();
            $this->assertStringContainsString('type="password" id="access-password" value="Sup3r-Geheim-Kennwort"', $page->getBody());
            $this->assertStringContainsString('data-reveal="access-password"', $page->getBody());
            $this->assertStringContainsString('no-store', $page->response()->getHeaderLine('Cache-Control'));
        } finally {
            putenv('RAVENKEEP_SECRET_KEY_FILE');
            unlink($keyFile);
        }
    }
}
