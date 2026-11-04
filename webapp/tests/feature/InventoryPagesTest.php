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
        foreach (['kunde - acme', 'standort - acme.branch2', 'geraet - acme.branch2.nas05', 'typ - nas', 'qnap', 'Daily'] as $text) {
            $result->assertSee($text);
        }
        $result->assertDontSee('>typ:nas<'); // tags are displayed with " - " instead of ":"
        $this->dump('customer', $result->getBody());
        $this->dump('customers', $this->as('rw')->get('customers')->getBody());
        $this->dump('tags', $this->as('rw')->get('tags')->getBody());
        $this->dump('settings', $this->as('rw')->get('settings')->getBody());
        $this->dump('devices', $this->as('rw')->get('devices')->getBody());
    }

    public function testLocationCanBeEditedButKeepsItsCode(): void
    {
        $location = (int) $this->db->table('locations')->get()->getRow()->id;
        $form     = $this->as('rw')->get('customers/' . $this->customer . '?edit_location=' . $location);
        $form->assertOK();
        $form->assertSee('value="Branch 2"');
        $this->dump('customer-edit-location', $form->getBody());

        // CSRF: use the token of the rendered form (the security service is shared within the test)
        $security = config('Security');
        preg_match('/name="' . $security->tokenName . '" value="([^"]+)"/', $form->getBody(), $m);
        $hash = $m[1];
        $this->as('rw')->post('customers/' . $this->customer . '/locations/' . $location,
            [$security->tokenName => $hash, 'name' => 'Branch Two', 'note' => 'Hall B', 'code' => 'changed'])
            ->assertRedirectTo(site_url('customers/' . $this->customer));
        $this->seeInDatabase('locations', ['id' => $location, 'name' => 'Branch Two', 'note' => 'Hall B', 'code' => 'branch2']);
    }

    public function testReadOnlyCannotChangeInventory(): void
    {
        $this->as('ro')->post('customers', ['code' => 'x', 'name' => 'X'])->assertStatus(403);
        $this->dontSeeInDatabase('customers', ['code' => 'x']);
    }
}
