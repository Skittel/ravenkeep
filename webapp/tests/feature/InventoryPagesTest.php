<?php

use App\Libraries\Inventory\Inventory;
use App\Libraries\Rules\RuleRunner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Pages render for signed-in users; read-only users see no write forms.
 *
 * @internal
 */
final class InventoryPagesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $inventory      = new Inventory($this->db, new RuleRunner($this->db));
        $this->customer = $inventory->createCustomer('acme', 'ACME GmbH', null);
        $location       = $inventory->createLocation($this->customer, 'branch2', 'Branch 2', null);
        $profile        = $inventory->createProfile('Daily', 24, null);
        $inventory->createDevice($this->customer, $location, 'nas05', 'NAS05', 'Rack 1', ['typ:nas', 'qnap'], $profile);
        $this->db->table('devices')->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
    }

    private function as(string $role): static
    {
        return $this->withSession(['user' => ['oid' => 'o', 'name' => 'Test', 'username' => 't@example.org', 'role' => $role, 'login_at' => time()]]);
    }

    private function dump(string $name, string $html): void
    {
        // rendered pages for visual checks (screenshots); not part of the assertions
        if (getenv('RK_DUMP_PAGES')) {
            file_put_contents(getenv('RK_DUMP_PAGES') . "/{$name}.html", $html);
        }
    }

    public function testDashboardShowsOverdueDevice(): void
    {
        $result = $this->as('ro')->get('dashboard');
        $result->assertOK();
        $result->assertSee('NAS05');
        $result->assertSee('acme.branch2.nas05');
        $this->dump('dashboard', $result->getBody());
    }

    public function testCustomerPageShowsDevicesTagsAndMonitoring(): void
    {
        $result = $this->as('rw')->get('customers/' . $this->customer);
        $result->assertOK();
        foreach (['kunde:acme', 'standort:acme.branch2', 'geraet:acme.branch2.nas05', 'typ:nas', 'qnap', 'Daily'] as $text) {
            $result->assertSee($text);
        }
        $this->dump('customer', $result->getBody());
        $this->dump('customers', $this->as('rw')->get('customers')->getBody());
        $this->dump('tags', $this->as('rw')->get('tags')->getBody());
        $this->dump('settings', $this->as('rw')->get('settings')->getBody());
        $this->dump('devices', $this->as('rw')->get('devices')->getBody());
    }

    public function testReadOnlyCannotChangeInventory(): void
    {
        $this->as('ro')->post('customers', ['code' => 'x', 'name' => 'X'])->assertStatus(403);
        $this->dontSeeInDatabase('customers', ['code' => 'x']);
    }
}
