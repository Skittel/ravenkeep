<?php

namespace App\Controllers;

use App\Libraries\Inventory\Inventory;
use App\Libraries\Rules\RuleRunner;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Customers with their locations and devices (inventory). Codes become automatic tags.
 */
class Customers extends BaseController
{
    protected $helpers = ['ravenkeep', 'url', 'form'];

    private function inventory(): Inventory
    {
        $db = db_connect();

        return new Inventory($db, new RuleRunner($db));
    }

    public function index(): string
    {
        $db = db_connect();

        return view('customers/index', [
            'customers' => $db->query('
                SELECT c.*, (SELECT COUNT(*) FROM locations l WHERE l.customer_id = c.id) AS locations,
                       (SELECT COUNT(*) FROM devices d WHERE d.customer_id = c.id) AS devices
                FROM customers c ORDER BY c.name')->getResultArray(),
            'overdue' => array_count_values(array_column(array_filter($this->inventory()->monitoring(), static fn ($d) => $d['overdue']), 'customer_name')),
        ]);
    }

    public function show(int $id): string
    {
        $db       = db_connect();
        $customer = $db->table('customers')->where('id', $id)->get()->getRowArray();
        if ($customer === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        // several SMTP logins per device (E-024): listed together
        $devices = $db->table('devices d')->select("d.*, l.name AS location_name, p.name AS profile_name,
                (SELECT GROUP_CONCAT(s.username ORDER BY s.username SEPARATOR ', ') FROM smtp_identities s WHERE s.device_id = d.id) AS login, 1 AS login_enabled", false)
            ->join('locations l', 'l.id = d.location_id', 'left')->join('timeout_profiles p', 'p.id = d.timeout_profile_id', 'left')
            ->where('d.customer_id', $id)->orderBy('l.name')->orderBy('d.name')->get()->getResultArray();
        $inventory = $this->inventory();

        return view('customers/show', [
            'customer'   => $customer,
            'domains'    => $db->table('sender_rules')->where(['tag_customer_id' => $id, 'action' => 'allow'])->orderBy('pattern')->get()->getResultArray(),
            'locations'  => $db->table('locations')->where('customer_id', $id)->orderBy('name')->get()->getResultArray(),
            'devices'    => $devices,
            'tags'       => $inventory->deviceTagMap(array_map('intval', array_column($devices, 'id'))),
            'monitoring' => array_column($inventory->monitoring($id), null, 'id'),
            'profiles'   => $db->table('timeout_profiles')->orderBy('hours')->get()->getResultArray(),
            'tagNames'   => array_column($db->table('tags')->select('name')->orderBy('name')->get()->getResultArray(), 'name'),
            'edit'       => (int) $this->request->getGet('edit'),
            'editLocation' => (int) $this->request->getGet('edit_location'),
        ]);
    }

    public function create(): RedirectResponse
    {
        return $this->change(fn (Inventory $i) => $i->createCustomer((string) $this->post('code'), (string) $this->post('name'), $this->note()),
            static fn ($id) => site_url('customers/' . $id));
    }

    public function update(int $id): RedirectResponse
    {
        return $this->change(fn (Inventory $i) => $i->updateCustomer($id, (string) $this->post('name'), $this->note()), fn () => site_url("customers/{$id}"));
    }

    public function delete(int $id): RedirectResponse
    {
        return $this->change(static fn (Inventory $i) => $i->deleteCustomer($id), static fn () => site_url('customers'), site_url("customers/{$id}"));
    }

    public function createLocation(int $customerId): RedirectResponse
    {
        return $this->change(fn (Inventory $i) => $i->createLocation($customerId, (string) $this->post('code'), (string) $this->post('name'), $this->note()),
            static fn () => site_url("customers/{$customerId}"));
    }

    public function updateLocation(int $customerId, int $id): RedirectResponse
    {
        return $this->change(fn (Inventory $i) => $i->updateLocation($id, (string) $this->post('name'), $this->note()), static fn () => site_url("customers/{$customerId}"));
    }

    public function deleteLocation(int $customerId, int $id): RedirectResponse
    {
        return $this->change(static fn (Inventory $i) => $i->deleteLocation($id), static fn () => site_url("customers/{$customerId}"));
    }

    public function createDevice(int $customerId): RedirectResponse
    {
        return $this->change(fn (Inventory $i) => $i->createDevice($customerId, ((int) $this->post('location_id')) ?: null,
            (string) $this->post('code'), (string) $this->post('name'), $this->note(), $this->tags(), ((int) $this->post('timeout_profile_id')) ?: null),
            static fn () => site_url("customers/{$customerId}"));
    }

    public function updateDevice(int $customerId, int $id): RedirectResponse
    {
        return $this->change(fn (Inventory $i) => $i->updateDevice($id, (string) $this->post('name'), $this->note(), $this->tags(),
            ((int) $this->post('timeout_profile_id')) ?: null, ((int) $this->post('location_id')) ?: null), static fn () => site_url("customers/{$customerId}"));
    }

    public function deleteDevice(int $customerId, int $id): RedirectResponse
    {
        return $this->change(static fn (Inventory $i) => $i->deleteDevice($id), static fn () => site_url("customers/{$customerId}"));
    }

    private function post(string $key): mixed
    {
        return $this->request->getPost($key);
    }

    private function note(): ?string
    {
        return trim((string) $this->post('note')) ?: null;
    }

    /** @return list<string> */
    private function tags(): array
    {
        return preg_split('/[\s,]+/', (string) $this->post('tags'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Run a change; on success redirect to $target($result), on invalid input back with an error.
     */
    private function change(callable $change, callable $target, ?string $errorTarget = null): RedirectResponse
    {
        try {
            $result = $change($this->inventory());
        } catch (InvalidArgumentException $e) {
            return redirect()->to($errorTarget ?? previous_url())->with('error', lang('Customers.invalid_' . $e->getMessage()));
        }

        return redirect()->to($target($result))->with('success', lang('Customers.saved'));
    }
}
