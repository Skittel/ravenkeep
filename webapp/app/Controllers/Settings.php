<?php

namespace App\Controllers;

use App\Libraries\Nodes\NodeConfigSync;
use App\Libraries\Nodes\NodeSettings;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Settings delivered to the MX nodes: local domains and admin networks (fail2ban ignore list).
 */
class Settings extends BaseController
{
    protected $helpers = ['ravenkeep', 'url', 'form'];

    public function index(): string
    {
        $db = db_connect();

        return view('settings/index', [
            'domains'  => $db->table('local_domains')->orderBy('domain')->get()->getResultArray(),
            'networks' => $db->table('admin_networks')->orderBy('network')->get()->getResultArray(),
        ]);
    }

    public function addDomain(): RedirectResponse
    {
        return $this->change(fn (NodeSettings $s) => $s->addDomain((string) $this->request->getPost('domain'), trim((string) $this->request->getPost('note')) ?: null));
    }

    public function deleteDomain(int $id): RedirectResponse
    {
        return $this->change(static fn (NodeSettings $s) => $s->deleteDomain($id));
    }

    public function addNetwork(): RedirectResponse
    {
        return $this->change(fn (NodeSettings $s) => $s->addNetwork((string) $this->request->getPost('network'), trim((string) $this->request->getPost('note')) ?: null));
    }

    public function deleteNetwork(int $id): RedirectResponse
    {
        return $this->change(static fn (NodeSettings $s) => $s->deleteNetwork($id));
    }

    private function change(callable $change): RedirectResponse
    {
        try {
            $change(new NodeSettings(db_connect()));
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('settings'))->with('error', lang('Devices.invalid_' . $e->getMessage()));
        }
        $errors = array_filter((new NodeConfigSync(db_connect()))->syncAll(), static fn ($r) => str_starts_with($r, 'error'));

        return redirect()->to(site_url('settings'))->with('success', $errors === [] ? lang('Senders.nodesUpdated')
            : lang('Senders.nodesError', [implode(', ', array_keys($errors))]));
    }
}
