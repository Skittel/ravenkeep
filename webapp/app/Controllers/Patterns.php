<?php

namespace App\Controllers;

use App\Libraries\Inventory\PatternService;
use App\Libraries\Rules\RuleRunner;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Subject patterns, aliases, unknown values named by subjects and pattern proposals (decision E-029).
 */
class Patterns extends BaseController
{
    protected $helpers = ['ravenkeep', 'url', 'form'];

    private function runner(): RuleRunner
    {
        return new RuleRunner(db_connect(), config('Ravenkeep')->archivePath);
    }

    private function service(): PatternService
    {
        return new PatternService(db_connect(), $this->runner());
    }

    public function index(): string
    {
        $db      = db_connect();
        $service = $this->service();
        $test    = null;
        $subject = trim((string) $this->request->getGet('test_subject'));
        $tested  = trim((string) $this->request->getGet('test_pattern'));
        $error   = null;
        if ($subject !== '') {
            try {
                $test = $service->test((string) $this->request->getGet('test_source'), $subject, $tested === '' ? null : $tested);
            } catch (InvalidArgumentException) {
                $error = lang('Patterns.invalid_pattern');
            }
        }

        return view('patterns/index', [
            'patterns'    => $service->patterns(),
            'sources'     => $service->sources(),
            'aliases'     => $service->aliases(),
            'unknown'     => $service->unknown(),
            'suggestions' => $service->suggestions(),
            'customers'   => $db->table('customers')->select('id, code, name')->orderBy('name')->get()->getResultArray(),
            'locations'   => $db->table('locations l')->select('l.id, l.customer_id, l.code, l.name, c.code AS customer_code, c.name AS customer_name')
                ->join('customers c', 'c.id = l.customer_id')->orderBy('c.name')->orderBy('l.name')->get()->getResultArray(),
            'devices'     => $db->table('devices d')->select('d.id, d.customer_id, d.path, d.name, c.name AS customer_name')
                ->join('customers c', 'c.id = d.customer_id')->orderBy('c.name')->orderBy('d.name')->get()->getResultArray(),
            'test'        => $test,
            'testInput'   => ['source' => (string) $this->request->getGet('test_source'), 'subject' => $subject, 'pattern' => $tested],
            'testError'   => $error,
            'backlog'     => $this->runner()->backlog(),
        ]);
    }

    public function add(): RedirectResponse
    {
        return $this->change(fn (PatternService $s) => $s->addPattern((string) $this->request->getPost('source'),
            (string) $this->request->getPost('pattern')), 'added');
    }

    public function delete(int $id): RedirectResponse
    {
        return $this->change(static fn (PatternService $s) => $s->deletePattern($id), 'deleted');
    }

    public function addAlias(): RedirectResponse
    {
        // target = "customer:ID", "location:ID" or "device:ID"
        [$kind, $id] = array_pad(explode(':', (string) $this->request->getPost('target'), 2), 2, '0');

        return $this->change(fn (PatternService $s) => $s->addAlias($kind, (int) $id, (string) $this->request->getPost('alias')), 'aliasAdded');
    }

    public function deleteAlias(int $id): RedirectResponse
    {
        return $this->change(static fn (PatternService $s) => $s->deleteAlias($id), 'aliasDeleted');
    }

    public function create(): RedirectResponse
    {
        $customer = (int) $this->request->getPost('customer_id');

        return $this->change(fn (PatternService $s) => $s->create((string) $this->request->getPost('part'), $customer > 0 ? $customer : null,
            (string) $this->request->getPost('code'), (string) $this->request->getPost('name')), 'created');
    }

    private function change(callable $change, string $success): RedirectResponse
    {
        try {
            $change($this->service());
            $this->runner()->processBatch(500); // show the result right away; the collector does the rest
        } catch (InvalidArgumentException $e) {
            $msg = null;
            foreach (['Patterns.invalid_', 'Customers.invalid_'] as $prefix) {
                if (lang($prefix . $e->getMessage()) !== $prefix . $e->getMessage()) {
                    $msg = lang($prefix . $e->getMessage());
                    break;
                }
            }

            return redirect()->to(site_url('patterns'))->with('error', $msg ?? lang('Addresses.invalid', [$e->getMessage()]))->withInput();
        }

        return redirect()->to(site_url('patterns'))->with('success', lang('Patterns.' . $success));
    }
}
