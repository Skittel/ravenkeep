<?php

namespace App\Controllers;

use App\Libraries\MessageReader;
use App\Libraries\Rules\RuleRunner;
use App\Libraries\Rules\TagName;
use App\Libraries\Rules\TagTypes;
use App\Models\MessageModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Mail-client style view: message list with status filter and search, reading pane.
 */
class Messages extends BaseController
{
    protected $helpers = ['ravenkeep', 'url', 'form'];

    public function index(): string
    {
        $config = config('Ravenkeep');
        $filter = [
            'range'  => MessageModel::range($this->request->getGet('range')),
            'status' => $this->request->getGet('status'),
            'q'      => $this->request->getGet('q'),
        ];
        $filter['tag'] = MessageModel::tagFilter(['tag' => $this->request->getGet('tag')]) ?: null;
        $page   = max(1, (int) $this->request->getGet('page'));
        $model  = new MessageModel();

        $total    = (clone $model)->filtered($filter)->countAllResults();
        $pages    = max(1, (int) ceil($total / $config->pageSize));
        $page     = min($page, $pages);
        $messages = $model->filtered($filter)
            ->select('id, received_at, status, mode, envelope_sender, envelope_recipient, header_from, sasl_username, subject')
            ->orderBy('received_at', 'DESC')->orderBy('id', 'DESC')
            ->findAll($config->pageSize, ($page - 1) * $config->pageSize);

        $selectedId = (int) $this->request->getGet('id');

        return view('messages/index', [
            'filter'   => $filter + ['page' => $page > 1 ? $page : null],
            'messages' => $messages,
            'counts'   => (new MessageModel())->countsByStatus($filter['range']),
            'tags'     => (new MessageModel())->tagsFor(array_column($messages, 'id')),
            'tagTree'  => (new MessageModel())->tagTree($filter['range']),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'pane'     => $selectedId > 0 ? $this->paneOrNull($selectedId) : null,
            'selected' => $selectedId,
        ]);
    }

    /** Reading pane (HTML fragment for the list view; full page without JavaScript). */
    public function show(int $id): ResponseInterface|string
    {
        if ($this->request->isAJAX()) {
            return $this->pane($id);
        }

        return redirect()->to(site_url('/') . '?id=' . $id);
    }

    /**
     * HTML part of a message for the sandboxed iframe. The Content-Security-Policy sandbox
     * blocks scripts, forms and every external resource (no tracking pixels, no remote images).
     */
    public function html(int $id): ResponseInterface
    {
        $reader = $this->reader($this->find($id));

        return $this->response
            ->setHeader('Content-Security-Policy', "sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:")
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Referrer-Policy', 'no-referrer')
            ->setContentType('text/html', 'UTF-8')
            ->setBody($reader->htmlBody() ?? '');
    }

    /** Download the original message. */
    public function eml(int $id): ResponseInterface
    {
        $message = $this->find($id);

        return $this->response
            ->setHeader('Content-Disposition', 'attachment; filename="' . $message['spool_id'] . '.eml"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setContentType('message/rfc822')
            ->setBody($this->reader($message)->raw());
    }

    /** Reading pane for a preselected message; an unknown id just shows the list. */
    private function paneOrNull(int $id): ?string
    {
        try {
            return $this->pane($id);
        } catch (PageNotFoundException) {
            return null;
        }
    }

    private function pane(int $id): string
    {
        $message = $this->find($id);
        $reader  = null;
        $error   = null;

        try {
            $reader = $this->reader($message);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        $node = db_connect()->table('nodes')->select('name')->where('id', $message['node_id'])->get()->getRowArray();
        // check of the matching allow rule (E-021)
        $listCheck = $message['list_match_pattern'] === null ? null : (db_connect()->table('sender_rules')->select('auth_check')
            ->where(['pattern' => $message['list_match_pattern'], 'target' => $message['list_match_target'], 'action' => 'allow'])->get()->getRow()->auth_check ?? null);
        $reasons = json_decode($message['reasons'] ?? '[]', true) ?: [];
        if ($message['status'] === 'rejected' && $listCheck !== null && $listCheck !== 'spf_or_dkim') {
            array_unshift($reasons, rk_sender_reason($listCheck));
        }

        return view('messages/_pane', [
            'm'       => $message,
            'node'    => $node['name'] ?? '?',
            'reasons' => $reasons,
            'listCheck' => $listCheck,
            'tagTypes'  => array_values(array_diff((new TagTypes(db_connect()))->names(), ['kunde', 'standort', 'geraet'])),
            'auth'    => json_decode($message['auth_details'] ?? 'null', true),
            'reader'  => $reader,
            'error'   => $error,
            'tags'    => (new MessageModel())->tagsFor([$id])[$id] ?? [],
        ]);
    }

    /** Add a manual tag; the rules are re-applied right away (rules may depend on it). */
    public function addTag(int $id): RedirectResponse
    {
        $this->find($id);

        try {
            // type from the list of tag types (E-023) + value
            $name   = TagName::normalize((string) $this->request->getPost('type') . ':' . (string) $this->request->getPost('value'));
            (new TagTypes(db_connect()))->assertKnown([$name]);
            $runner = new RuleRunner(db_connect(), config('Ravenkeep')->archivePath);
            $tagId  = $runner->tagId($name);
            db_connect()->query('INSERT INTO message_tags (message_id, tag_id, source) VALUES (?, ?, ?) '
                . "ON DUPLICATE KEY UPDATE source = 'manual', rule_id = NULL", [$id, $tagId, 'manual']);
            $runner->processMessage($id);
        } catch (InvalidArgumentException $e) {
            session()->setFlashdata('error', lang($e->getMessage() === 'tag_type' ? 'Customers.invalid_tag_type' : 'Rules.invalid_tag'));
        }

        return redirect()->to(site_url('/') . '?id=' . $id);
    }

    /** Remove a manual tag (rule tags disappear only by changing the rule). */
    public function removeTag(int $id, int $tagId): RedirectResponse
    {
        $this->find($id);
        db_connect()->table('message_tags')->where(['message_id' => $id, 'tag_id' => $tagId, 'source' => 'manual'])->delete();
        (new RuleRunner(db_connect(), config('Ravenkeep')->archivePath))->processMessage($id);

        return redirect()->to(site_url('/') . '?id=' . $id);
    }

    private function find(int $id): array
    {
        $message = (new MessageModel())->find($id);
        if ($message === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $message;
    }

    private function reader(array $message): MessageReader
    {
        return new MessageReader(config('Ravenkeep')->archivePath, $message['eml_path']);
    }
}
