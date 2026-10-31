<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * php spark ravenkeep:purge [--dry-run]
 *
 * Deletes messages (database rows and archived EML files) older than the retention period of
 * their status (Config\Ravenkeep::$retentionDays). Run daily by a systemd timer.
 */
class Purge extends BaseCommand
{
    protected $group       = 'Ravenkeep';
    protected $name        = 'ravenkeep:purge';
    protected $description = 'Deletes messages older than the retention period of their status.';
    protected $usage       = 'ravenkeep:purge [--dry-run]';
    protected $options     = ['--dry-run' => 'Only count, do not delete.'];

    public function run(array $params)
    {
        $db      = Database::connect();
        $config  = config('Ravenkeep');
        $dryRun  = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $archive = rtrim($config->archivePath, '/');

        foreach ($config->retentionDays as $status => $days) {
            $cutoff  = gmdate('Y-m-d H:i:s', time() - $days * 86400);
            $deleted = 0;
            do {
                $rows = $db->table('messages')->select('id, eml_path')
                    ->where('status', $status)->where('received_at <', $cutoff)->limit(500)->get()->getResultArray();
                if ($rows === [] || $dryRun) {
                    $deleted += $dryRun ? $db->table('messages')->where('status', $status)->where('received_at <', $cutoff)->countAllResults() : 0;
                    break;
                }
                $ids = array_column($rows, 'id');
                $db->transStart();
                if ($db->tableExists('message_tags')) {
                    $db->table('message_tags')->whereIn('message_id', $ids)->delete();
                }
                $db->table('messages')->whereIn('id', $ids)->delete();
                $db->transComplete();
                foreach ($rows as $row) {
                    $file = $archive . '/' . $row['eml_path'];
                    if (! str_contains($row['eml_path'], '..') && is_file($file)) {
                        unlink($file);
                    }
                }
                $deleted += count($rows);
            } while (count($rows) === 500);

            CLI::write(sprintf('%-9s older than %3d days: %d %s', $status, $days, $deleted, $dryRun ? 'would be deleted' : 'deleted'));
        }

        return EXIT_SUCCESS;
    }
}
