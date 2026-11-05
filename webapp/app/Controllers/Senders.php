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
                   SUM(JSON_VALUE(auth_details, '$.alignment.spf_aligned') = 1) AS spf_passed,
                   SUM(JSON_VALUE(auth_details, '$.alignment.dkim_aligned') = 1) AS dkim_passed,
                   MAX(received_at) AS last_seen,
                   MAX(subject) AS sample_subject,
                   MAX(id) AS sample_id
            FROM messages
            WHERE status = 'pending' AND mode = 'mx'
            GROUP BY envelope_domain, header_domain
            ORDER BY last_seen DESC
            LIMIT 200")->getResultArray();

        // What the senders of each rule actually pass (last 30 days), to choose the check (E-021)
        $observed = [];
        foreach ($db->query("
            SELECT list_match_pattern AS pattern, list_match_target AS target, COUNT(*) AS messages,
                   SUM(JSON_VALUE(auth_details, '$.alignment.spf_aligned') = 1) AS spf,
                   SUM(JSON_VALUE(auth_details, '$.alignment.dkim_aligned') = 1) AS dkim
            FROM messages WHERE list_match_pattern IS NOT NULL AND mode = 'mx' AND received_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
            GROUP BY list_match_pattern, list_match_target")->getResultArray() as $o) {
            $observed[$o['pattern'] . '|' . $o['target']] = $o;
        }

        return view('senders/index', [
            'pending'   => $pending,
            'rules'     => $db->table('sender_rules r')->select('r.*, c.name AS customer_name, c.code AS customer_code')
                ->join('customers c', 'c.id = r.tag_customer_id', 'left')->orderBy('r.action')->orderBy('r.pattern')->get()->getResultArray(),
            'observed'  => $observed,
            'customers' => $db->table('customers')->select('id, code, name')->orderBy('name')->get()->getResultArray(),
            'edit'      => (int) $this->request->getGet('edit'),
        ]);
    }

    public function save(): RedirectResponse
    {
        $post = $this->request->getPost(['pattern', 'target', 'action', 'note', 'return_to', 'auth_check', 'tag_kind', 'tag_customer_id', 'tag_source']);
        // full form: check and tag meaning (E-021); quick buttons send neither and keep them
        $settings = $post['auth_check'] === null ? null : [
            'auth_check'      => (string) $post['auth_check'],
            'tag_customer_id' => $post['tag_kind'] === 'customer' ? ((int) $post['tag_customer_id'] ?: null) : null,
            'tag_source'      => $post['tag_kind'] === 'source' ? (string) $post['tag_source'] : null,
        ];

        try {
            $changed = (new SenderRuleService(db_connect()))->save(
                (string) $post['pattern'], (string) $post['target'], (string) $post['action'], trim((string) $post['note']) ?: null, $settings);
            $message = lang('Senders.saved', [$changed]) . ' ' . $this->syncNodes();
            session()->setFlashdata('success', $message);
        } catch (InvalidArgumentException $e) {
            $key = 'Senders.invalid_' . $e->getMessage();
            session()->setFlashdata('error', lang($key) !== $key ? lang($key) : lang('Senders.invalid', [(string) $post['pattern']]));
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
