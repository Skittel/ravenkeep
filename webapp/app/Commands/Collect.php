<?php

namespace App\Commands;

use App\Libraries\Collector\Collector;
use App\Libraries\Collector\LocalSpoolTransport;
use App\Libraries\Nodes\NodeConfigSync;
use App\Libraries\Rules\RuleRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * php spark ravenkeep:collect
 *
 * Imports the spool of every enabled node. Run regularly (systemd timer) as the web server user.
 */
class Collect extends BaseCommand
{
    protected $group       = 'Ravenkeep';
    protected $name        = 'ravenkeep:collect';
    protected $description = 'Imports received messages from all enabled MX nodes and delivers pending config changes.';
    protected $usage       = 'ravenkeep:collect';

    public function run(array $params)
    {
        $db        = Database::connect();
        $config    = config('Ravenkeep');
        $collector = new Collector($db, $config->archivePath, $config->collectBatchSize);
        $exitCode  = EXIT_SUCCESS;

        foreach ($db->table('nodes')->where('enabled', 1)->get()->getResultArray() as $node) {
            $update = ['last_collect_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null];

            try {
                $transport = match ($node['transport']) {
                    'local' => new LocalSpoolTransport($node['spool_path']),
                    default => throw new \RuntimeException("Transport '{$node['transport']}' not implemented yet"),
                };
                $result = $collector->collectNode($node, $transport);
                $update['last_collect_count'] = $result['imported'];
                CLI::write(sprintf('%s: %d imported, %d duplicates, %d errors',
                    $node['name'], $result['imported'], $result['duplicates'], $result['errors']));
            } catch (Throwable $e) {
                $update['last_error'] = $e->getMessage();
                CLI::error("{$node['name']}: {$e->getMessage()}");
                $exitCode = EXIT_ERROR;
            }

            $errors = $collector->errors();
            if ($errors !== []) {
                $update['last_error'] = implode("\n", array_slice($errors, -20));
                foreach ($errors as $error) {
                    CLI::error($error);
                    log_message('error', 'Collector: ' . $error);
                }
                $exitCode = EXIT_ERROR;
            }

            $db->table('nodes')->where('id', $node['id'])->update($update);
        }

        // Apply the rules to new messages and, after rule changes, re-evaluate older ones in batches
        $processed = (new RuleRunner($db, $config->archivePath))->processBatch();
        if ($processed > 0) {
            CLI::write("rules: {$processed} messages evaluated");
        }

        // Deliver pending config changes and read back what the nodes applied
        foreach ((new NodeConfigSync($db))->syncAll() as $name => $result) {
            if ($result !== 'up to date') {
                CLI::write("{$name}: config {$result}");
            }
            if (str_starts_with($result, 'error')) {
                $exitCode = EXIT_ERROR;
            }
        }

        return $exitCode;
    }
}
