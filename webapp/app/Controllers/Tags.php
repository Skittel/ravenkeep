<?php

namespace App\Controllers;

use App\Libraries\Rules\RuleRunner;
use App\Libraries\Rules\TagService;
use App\Libraries\Rules\TagTypes;
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
        $all   = $this->service()->all();
        $type  = (string) $this->request->getGet('type');
        $query = mb_strtolower(trim((string) $this->request->getGet('q')));
        $types = array_values(array_unique(array_column($all, 'type')));
        sort($types, SORT_STRING);
        $tags = array_values(array_filter($all, static fn (array $t): bool => ($type === '' || ($type === '-' ? $t['type'] === '' : $t['type'] === $type))
            && ($query === '' || str_contains($t['name'], $query) || str_contains(mb_strtolower(rk_tag_label($t['name'])), $query))));

        return view('tags/index', ['tags' => $tags, 'total' => count($all), 'types' => $types, 'type' => $type, 'q' => $query,
            'edit' => (int) $this->request->getGet('edit'), 'tagTypes' => (new TagTypes(db_connect()))->all(),
            'editType' => (int) $this->request->getGet('edit_type')]);
    }

    public function create(): RedirectResponse
    {
        return $this->change(fn (TagService $s) => $s->create($this->typedName()));
    }

    public function rename(int $id): RedirectResponse
    {
        return $this->change(fn (TagService $s) => $s->rename($id, $this->typedName()));
    }

    public function delete(int $id): RedirectResponse
    {
        return $this->change(static fn (TagService $s) => $s->delete($id));
    }

    // ------------------------------------------------------------------ Tag types (E-023)

    public function createType(): RedirectResponse
    {
        return $this->change(fn () => $this->types()->create((string) $this->request->getPost('name'), $this->note()));
    }

    public function updateType(int $id): RedirectResponse
    {
        return $this->change(function () use ($id) {
            $this->types()->updateNote($id, $this->note());
            $this->types()->rename($id, (string) $this->request->getPost('name'));
        });
    }

    public function deleteType(int $id): RedirectResponse
    {
        return $this->change(fn () => $this->types()->delete($id));
    }

    private function types(): TagTypes
    {
        $db = db_connect();

        return new TagTypes($db, new RuleRunner($db));
    }

    private function note(): ?string
    {
        return trim((string) $this->request->getPost('note')) ?: null;
    }

    /** Tag name from the type dropdown and the value field (E-023). */
    private function typedName(): string
    {
        return trim((string) $this->request->getPost('type')) . ':' . trim((string) $this->request->getPost('value'));
    }

    private function change(callable $change): RedirectResponse
    {
        try {
            $change($this->service());
        } catch (InvalidArgumentException $e) {
            $key = in_array($e->getMessage(), ['reserved_tag', 'tag_exists', 'tag_in_rules', 'tag', 'tag_type', 'type_name', 'type_exists',
                'type_system', 'type_in_use', 'type'], true) ? $e->getMessage() : 'tag_name';

            return redirect()->to(site_url('tags'))->with('error', lang('Customers.invalid_' . $key));
        }

        return redirect()->to(site_url('tags'))->with('success', lang('Customers.saved'));
    }
}
