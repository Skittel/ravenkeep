<?php

namespace App\Controllers;

use App\Libraries\Rules\RuleRepository;
use App\Libraries\Rules\RuleRunner;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Rules that assign tags: list, edit, reorder, enable/disable, delete.
 */
class Rules extends BaseController
{
    protected $helpers = ['ravenkeep', 'url', 'form'];

    private function repository(): RuleRepository
    {
        $db = db_connect();

        return new RuleRepository($db, new RuleRunner($db, config('Ravenkeep')->archivePath));
    }

    public function index(): string
    {
        return view('rules/index', [
            'rules'   => $this->repository()->all(),
            'backlog' => (new RuleRunner(db_connect()))->backlog(),
        ]);
    }

    public function edit(?int $id = null): string
    {
        $rule = $id === null ? null : $this->repository()->find($id);
        if ($id !== null && $rule === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('rules/edit', [
            'rule'    => $rule,
            'preview' => $rule !== null ? $this->repository()->preview($rule) : null,
        ]);
    }

    public function save(?int $id = null): RedirectResponse
    {
        $post       = $this->request->getPost();
        $conditions = [];
        foreach ((array) ($post['conditions']['field'] ?? []) as $i => $field) {
            $conditions[] = [
                'field'    => $field,
                'operator' => $post['conditions']['operator'][$i] ?? '',
                'value'    => $post['conditions']['value'][$i] ?? '',
            ];
        }
        $tags = preg_split('/[\s,]+/', (string) ($post['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        try {
            $id = $this->repository()->save($id, (string) ($post['name'] ?? ''), (string) ($post['match_mode'] ?? 'all'),
                ! empty($post['stop']), ! empty($post['enabled']), $conditions, $tags);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', lang('Rules.invalid_' . (in_array($e->getMessage(), ['name', 'conditions', 'tags'], true) ? $e->getMessage() : 'tags')));
        }
        $preview = $this->repository()->preview($this->repository()->find($id));
        session()->setFlashdata('success', lang('Rules.saved', [$preview['matched'], $preview['sample']]));

        return redirect()->to(site_url('rules'));
    }

    public function delete(int $id): RedirectResponse
    {
        $this->repository()->delete($id);

        return redirect()->to(site_url('rules'))->with('success', lang('Rules.deleted'));
    }

    public function toggle(int $id): RedirectResponse
    {
        $this->repository()->toggle($id);

        return redirect()->to(site_url('rules'));
    }

    public function move(int $id, string $direction): RedirectResponse
    {
        $this->repository()->move($id, $direction === 'up' ? -1 : 1);

        return redirect()->to(site_url('rules'));
    }
}
