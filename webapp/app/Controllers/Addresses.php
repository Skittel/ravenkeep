<?php

namespace App\Controllers;

use App\Libraries\Inventory\AddressService;
use App\Libraries\Rules\RuleRunner;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Unresolved recipient addresses and the address map (decision E-019).
 */
class Addresses extends BaseController
{
    protected $helpers = ['ravenkeep', 'url', 'form'];

    private function service(): AddressService
    {
        $db = db_connect();

        return new AddressService($db, $this->runner());
    }

    private function runner(): RuleRunner
    {
        return new RuleRunner(db_connect(), config('Ravenkeep')->archivePath);
    }

    public function index(): string
    {
        $db      = db_connect();
        $devices = $db->table('devices d')->select('d.id, d.path, d.name, c.name AS customer_name')
            ->join('customers c', 'c.id = d.customer_id')->orderBy('c.name')->orderBy('d.name')->get()->getResultArray();
        $service = $this->service();

        return view('addresses/index', [
            'unknown'  => $service->unknown(),
            'mappings' => $service->mappings(),
            'devices'  => $devices,
            'backlog'  => (new RuleRunner($db))->backlog(),
        ]);
    }

    public function create(): RedirectResponse
    {
        return $this->change(fn (AddressService $s) => $s->create((string) $this->request->getPost('address'),
            (string) $this->request->getPost('kind'), (string) $this->request->getPost('value')), 'created');
    }

    public function map(): RedirectResponse
    {
        return $this->change(fn (AddressService $s) => $s->map((string) $this->request->getPost('address'),
            (int) $this->request->getPost('device_id')), 'mapped');
    }

    public function unmap(int $id): RedirectResponse
    {
        return $this->change(static fn (AddressService $s) => $s->unmap($id), 'unmapped');
    }

    private function change(callable $change, string $success): RedirectResponse
    {
        try {
            $change($this->service());
            $this->runner()->processBatch(500); // show the result right away; the collector does the rest
        } catch (InvalidArgumentException $e) {
            $key = 'Customers.invalid_' . $e->getMessage();
            $msg = lang($key) !== $key ? lang($key) : lang('Addresses.invalid', [$e->getMessage()]);

            return redirect()->to(site_url('addresses'))->with('error', $msg);
        }

        return redirect()->to(site_url('addresses'))->with('success', lang('Addresses.' . $success));
    }
}
