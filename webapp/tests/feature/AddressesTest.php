<?php

use App\Libraries\Inventory\AddressService;
use App\Libraries\Inventory\Inventory;
use App\Libraries\Rules\RuleRunner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Unresolved addresses: list, create the missing value, map to a device (E-019).
 *
 * @internal
 */
final class AddressesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private RuleRunner $runner;
    private AddressService $service;
    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner   = new RuleRunner($this->db);
        $this->service  = new AddressService($this->db, $this->runner);
        $this->customer = (new Inventory($this->db, $this->runner))->createCustomer('globex', 'Globex', null);
        $this->db->table('local_domains')->insert(['domain' => 'report.example.org', 'parse_address' => 1]);
        $this->db->table('nodes')->insert(['id' => 1, 'name' => 'n1', 'hostname' => 'n1.example']);
    }

    private function as(string $role): static
    {
        return $this->withSession(['user' => ['oid' => 'o', 'name' => 'Test', 'username' => 't@example.org', 'role' => $role, 'login_at' => time()]]);
    }

    private function addMessage(string $spoolId, string $recipient): int
    {
        $this->db->table('messages')->insert(['node_id' => 1, 'spool_id' => $spoolId, 'received_at' => gmdate('Y-m-d H:i:s'),
            'mode' => 'mx', 'status' => 'accepted', 'envelope_recipient' => $recipient, 'eml_path' => 'x.eml',
            'eml_sha256' => str_repeat('0', 64), 'body_text' => '']);

        return (int) $this->db->insertID();
    }

    private function tags(int $id): array
    {
        return array_column($this->db->query('SELECT t.name FROM message_tags mt JOIN tags t ON t.id = mt.tag_id WHERE mt.message_id = ? ORDER BY t.name', [$id])->getResultArray(), 'name');
    }

    public function testListCreateAndMap(): void
    {
        $a = $this->addMessage('a', 'srv01_plesk_globex@report.example.org');
        $b = $this->addMessage('b', 'srv01_plesk_globex@report.example.org');
        $c = $this->addMessage('c', 'srv71_plesk_globex@report.example.org');
        $this->runner->processBatch();

        $list = array_column($this->service->unknown(), null, 'address');
        $this->assertSame(2, $list['srv01_plesk_globex@report.example.org']['messages']);
        $this->assertSame('globex', $list['srv01_plesk_globex@report.example.org']['customer']);
        $this->assertSame(2, $this->service->countUnknown());

        // create device and source from the list
        $this->service->create('srv01_plesk_globex@report.example.org', 'device', 'srv01');
        $this->service->create('srv01_plesk_globex@report.example.org', 'source', 'plesk');
        $this->runner->processBatch();
        $this->assertSame(['geraet:globex.srv01', 'kunde:globex', 'source:plesk'], $this->tags($a));
        $this->assertSame(['geraet:globex.srv01', 'kunde:globex', 'source:plesk'], $this->tags($b));

        // srv71 is a typo: map it to srv01
        $device = (int) $this->db->table('devices')->where('path', 'globex.srv01')->get()->getRow()->id;
        $this->service->map('<SRV71_plesk_globex@report.example.org>', $device);
        $this->runner->processBatch();
        $this->assertSame(['geraet:globex.srv01', 'kunde:globex', 'source:plesk'], $this->tags($c));
        $this->assertSame([], $this->service->unknown());
        $this->assertSame('srv71_plesk_globex@report.example.org', $this->service->mappings()[0]['address']);

        // removing the mapping brings it back
        $this->service->unmap($this->service->mappings()[0]['id']);
        $this->runner->processBatch();
        $this->assertSame([], $this->tags($c));
        $this->assertSame(1, $this->service->countUnknown());
    }

    public function testMappingResolvesAddressOutsideTheScheme(): void
    {
        $id     = $this->addMessage('a', 'statuscake.globex@report.example.org');
        $device = (new Inventory($this->db, $this->runner))->createDevice($this->customer, null, 'web01', 'Web 01', null, []);
        $this->runner->processBatch();
        $this->assertSame([['part' => 'scheme', 'value' => '']], $this->service->unknown()[0]['unknown']);

        $this->service->map('statuscake.globex@report.example.org', $device);
        $this->runner->processBatch();
        $this->assertSame(['geraet:globex.web01', 'kunde:globex'], $this->tags($id));
    }

    public function testCreateCustomerAndRejectsInvalidValues(): void
    {
        $this->service->create('nas01_newco@report.example.org', 'customer', 'newco');
        $this->seeInDatabase('customers', ['code' => 'newco']);

        $this->expectException(InvalidArgumentException::class);
        $this->service->create('nas01_unknown@report.example.org', 'device', 'nas01'); // customer does not exist
    }

    public function testPageForRwAndRo(): void
    {
        $this->addMessage('a', 'srv01_plesk_globex@report.example.org');
        $this->runner->processBatch();

        $result = $this->as('rw')->get('addresses');
        $result->assertOK();
        $result->assertSee('srv01_plesk_globex@report.example.org');
        $result->assertSee('Gerät srv01 anlegen');
        $result->assertSee('srv01 unbekannt');
        $this->assertStringContainsString('class="nav-count">1<', $result->getBody());

        if (getenv('RK_DUMP_PAGES')) {
            file_put_contents(getenv('RK_DUMP_PAGES') . '/addresses.html', $result->getBody());
        }
        $this->as('ro')->get('addresses')->assertOK();
        $this->as('ro')->post('addresses/map', ['address' => 'x@y', 'device_id' => 1])->assertStatus(403);
        $this->assertSame([], $this->service->mappings());
    }
}
