<?php

namespace App\Controllers;

use App\Libraries\Nodes\NodeConfigSync;
use App\Libraries\Senders\SenderRuleService;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * Sender allow/block rules: waiting (unknown) senders, rule list, add/delete.
 * Every change reclassifies stored messages and delivers the new config to the MX nodes.
 */
class Senders extends BaseController
{
    protected $helpers = ['ravenkeep', 'url', 'form'];

    public function index(): string
    {
        $db = db_connect();

        // Unknown senders, grouped by envelope domain and From domain
        $pending = $db->query("
            SELECT SUBSTRING_INDEX(envelope_sender, '@', -1) AS envelope_domain,
                   SUBSTRING_INDEX(COALESCE(header_from_address, ''), '@', -1) AS header_domain,
                   COUNT(*) AS messages,
                   SUM(auth_verdict = 'accepted') AS auth_passed,
                   MAX(received_at) AS last_seen,
                   MAX(subject) AS sample_subject,
                   MAX(id) AS sample_id
            FROM messages
            WHERE status = 'pending' AND mode = 'mx'
            GROUP BY envelope_domain, header_domain
            ORDER BY last_seen DESC
            LIMIT 200")->getResultArray();

        return view('senders/index', [
            'pending' => $pending,
            'rules'   => $db->table('sender_rules')->orderBy('action')->orderBy('pattern')->get()->getResultArray(),
        ]);
    }

    public function save(): RedirectResponse
    {
        $post = $this->request->getPost(['pattern', 'target', 'action', 'note', 'return_to']);

        try {
            $changed = (new SenderRuleService(db_connect()))->save(
                (string) $post['pattern'], (string) $post['target'], (string) $post['action'], trim((string) $post['note']) ?: null);
            $message = lang('Senders.saved', [$changed]) . ' ' . $this->syncNodes();
            session()->setFlashdata('success', $message);
        } catch (InvalidArgumentException $e) {
            session()->setFlashdata('error', lang('Senders.invalid', [(string) $post['pattern']]));
        }

        return redirect()->to($this->safeReturn($post['return_to'] ?? null));
    }

    public function delete(int $id): RedirectResponse
    {
        $changed = (new SenderRuleService(db_connect()))->delete($id);
        session()->setFlashdata('success', lang('Senders.deleted', [$changed]) . ' ' . $this->syncNodes());

        return redirect()->to(site_url('senders'));
    }

    /** Deliver the new config right away; the collector timer retries if a node is unreachable. */
    private function syncNodes(): string
    {
        $results = (new NodeConfigSync(db_connect()))->syncAll();
        $errors  = array_filter($results, static fn ($r) => str_starts_with($r, 'error'));

        return $errors === [] ? lang('Senders.nodesUpdated') : lang('Senders.nodesError', [implode(', ', array_keys($errors))]);
    }

    /** Only allow redirects within this application. */
    private function safeReturn(?string $target): string
    {
        return ($target !== null && preg_match('#^/(\?[^\s]*)?$|^/senders$#', $target)) ? site_url(ltrim($target, '/')) : site_url('senders');
    }
}
