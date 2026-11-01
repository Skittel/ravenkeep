<?php

namespace App\Controllers;

use App\Libraries\Rules\RuleRunner;
use App\Libraries\Rules\TagService;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Tag management. Customer, location and device tags are maintained via the inventory.
 */
class Tags extends BaseController
{
    protected $helpers = ['ravenkeep', 'url', 'form'];

    private function service(): TagService
    {
        $db = db_connect();

        return new TagService($db, new RuleRunner($db));
    }

    public function index(): string
    {
        return view('tags/index', ['tags' => $this->service()->all(), 'edit' => (int) $this->request->getGet('edit')]);
    }

    public function create(): RedirectResponse
    {
        return $this->change(fn (TagService $s) => $s->create((string) $this->request->getPost('name')));
    }

    public function rename(int $id): RedirectResponse
    {
        return $this->change(fn (TagService $s) => $s->rename($id, (string) $this->request->getPost('name')));
    }

    public function delete(int $id): RedirectResponse
    {
        return $this->change(static fn (TagService $s) => $s->delete($id));
    }

    private function change(callable $change): RedirectResponse
    {
        try {
            $change($this->service());
        } catch (InvalidArgumentException $e) {
            $key = in_array($e->getMessage(), ['reserved_tag', 'tag_exists', 'tag_in_rules', 'tag'], true) ? $e->getMessage() : 'tag_name';

            return redirect()->to(site_url('tags'))->with('error', lang('Customers.invalid_' . $key));
        }

        return redirect()->to(site_url('tags'))->with('success', lang('Customers.saved'));
    }
}
