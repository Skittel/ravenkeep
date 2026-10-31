<?php

namespace App\Libraries\Nodes;

use App\Libraries\Senders\SenderRuleService;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/**
 * Builds the config bundle for the MX nodes from the database and delivers it.
 *
 * Bundle = files managed by the web app + manifest.json {hash, generated_at, files: {name: sha256}}.
 * Only files listed in the manifest are touched on the node; others (e.g. smtp-users, as long as
 * the web app does not manage it yet) stay as they are.
 */
class NodeConfigSync
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Current bundle: files and their combined hash.
     *
     * @return array{files: array<string, string>, hash: string}
     */
    public function bundle(): array
    {
        $rules = (new SenderRuleService($this->db))->rules();
        $files = [
            'allowed-senders' => $rules->render('allow'),
            'blocked-senders' => $rules->render('block'),
        ];
        ksort($files);
        $hash = hash('sha256', implode("\0", array_map(static fn ($n, $c) => $n . "\0" . $c, array_keys($files), $files)));

        return ['files' => $files, 'hash' => $hash];
    }

    public function transportFor(array $node): ConfigTransport
    {
        return match ($node['transport']) {
            'local' => new LocalConfigTransport($node['config_path']),
            default => throw new RuntimeException("Config transport '{$node['transport']}' not implemented yet"),
        };
    }

    /**
     * Push the bundle to every enabled node whose last pushed hash differs (or to all with $force),
     * and read back each node's applied status. Returns a message per node.
     *
     * @return array<string, string>
     */
    public function syncAll(bool $force = false): array
    {
        $bundle  = $this->bundle();
        $results = [];

        foreach ($this->db->table('nodes')->where('enabled', 1)->get()->getResultArray() as $node) {
            $update = [];

            try {
                $transport = $this->transportFor($node);
                if ($force || $node['config_hash'] !== $bundle['hash']) {
                    $transport->push($bundle['files'], $this->manifest($bundle));
                    $update += ['config_hash' => $bundle['hash'], 'config_pushed_at' => gmdate('Y-m-d H:i:s')];
                    $results[$node['name']] = 'pushed ' . substr($bundle['hash'], 0, 12);
                } else {
                    $results[$node['name']] = 'up to date';
                }
                $update += $this->statusColumns($transport);
            } catch (Throwable $e) {
                $update['config_error']  = 'web app: ' . $e->getMessage();
                $results[$node['name']] = 'error: ' . $e->getMessage();
            }

            if ($update !== []) {
                $this->db->table('nodes')->where('id', $node['id'])->update($update);
            }
        }

        return $results;
    }

    /** Read back only the applied status of every enabled node (no push). */
    public function refreshStatus(): void
    {
        foreach ($this->db->table('nodes')->where('enabled', 1)->get()->getResultArray() as $node) {
            try {
                $update = $this->statusColumns($this->transportFor($node));
            } catch (Throwable $e) {
                $update = ['config_error' => 'web app: ' . $e->getMessage()];
            }
            if ($update !== []) {
                $this->db->table('nodes')->where('id', $node['id'])->update($update);
            }
        }
    }

    /** Columns to update from the status file written by the node. */
    private function statusColumns(ConfigTransport $transport): array
    {
        $status = $transport->readStatus();
        if ($status === null) {
            return [];
        }

        return [
            'config_applied_hash' => $status['applied_hash'] ?? null,
            'config_applied_at'   => isset($status['applied_at']) ? gmdate('Y-m-d H:i:s', strtotime($status['applied_at'])) : null,
            'config_error'        => $status['error'] ?? null,
        ];
    }

    private function manifest(array $bundle): string
    {
        return json_encode([
            'hash'         => $bundle['hash'],
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'files'        => array_map(static fn ($c) => hash('sha256', $c), $bundle['files']),
        ], JSON_PRETTY_PRINT);
    }
}
