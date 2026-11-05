<?php

namespace App\Controllers;

use App\Libraries\Inventory\Inventory;
use App\Libraries\Nodes\NodeConfigSync;
use App\Libraries\Rules\RuleRunner;

/**
 * Overview: overdue devices, today's messages, waiting senders, error tags, node problems.
 */
class Dashboard extends BaseController
{
    protected $helpers = ['ravenkeep', 'url'];

    public function index(): string
    {
        $db         = db_connect();
        $monitoring = (new Inventory($db, new RuleRunner($db)))->monitoring();
        $today      = (new \DateTimeImmutable('today', new \DateTimeZone(config('Ravenkeep')->displayTimezone)))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $counts = array_fill_keys(['accepted', 'rejected', 'pending', 'blocked'], 0);
        foreach ($db->table('messages')->select('status, COUNT(*) AS n')->where('received_at >=', $today)->groupBy('status')->get()->getResultArray() as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }

        $errors = $db->query("
            SELECT m.id, m.received_at, m.subject, t.name AS tag
            FROM message_tags mt JOIN tags t ON t.id = mt.tag_id JOIN messages m ON m.id = mt.message_id
            WHERE t.name LIKE 'status:error%' AND m.received_at >= ?
            ORDER BY m.received_at DESC LIMIT 20", [gmdate('Y-m-d H:i:s', time() - 86400)])->getResultArray();

        (new NodeConfigSync($db))->refreshStatus();
        $currentHash = (new NodeConfigSync($db))->bundle()['hash'];
        $nodes       = array_filter($db->table('nodes')->where('enabled', 1)->get()->getResultArray(), static fn ($n) => ! empty($n['last_error'])
            || ! empty($n['config_error']) || $n['config_applied_hash'] !== $currentHash
            || $n['last_collect_at'] === null || strtotime($n['last_collect_at'] . ' UTC') < time() - 600);

        return view('dashboard/index', [
            'overdue'    => array_values(array_filter($monitoring, static fn ($d) => $d['overdue'])),
            'monitored'  => count($monitoring),
            'upcoming'   => array_slice(array_values(array_filter($monitoring, static fn ($d) => ! $d['overdue'])), 0, 5),
            'counts'     => $counts,
            'pending'    => (int) $db->query("SELECT COUNT(DISTINCT SUBSTRING_INDEX(envelope_sender, '@', -1)) AS n FROM messages WHERE status = 'pending'")->getRow()->n,
            'errors'     => $errors,
            'nodeIssues' => array_values($nodes),
            'addresses'  => (new \App\Libraries\Inventory\AddressService($db, new RuleRunner($db)))->countUnknown(),
        ]);
    }
}
