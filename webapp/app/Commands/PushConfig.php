<?php

namespace App\Commands;

use App\Libraries\Nodes\NodeConfigSync;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * php spark ravenkeep:push-config [--force]
 *
 * Delivers the config bundle (sender lists, …) to all enabled nodes and reads back their status.
 * Also run by ravenkeep:collect, so a node that was unreachable catches up automatically.
 */
class PushConfig extends BaseCommand
{
    protected $group       = 'Ravenkeep';
    protected $name        = 'ravenkeep:push-config';
    protected $description = 'Delivers the config bundle to all enabled MX nodes.';
    protected $usage       = 'ravenkeep:push-config [--force]';
    protected $options     = ['--force' => 'Push even if the node already has the current bundle.'];

    public function run(array $params)
    {
        $errors = 0;
        foreach ((new NodeConfigSync(Database::connect()))->syncAll(CLI::getOption('force') !== null) as $node => $result) {
            CLI::write("{$node}: {$result}");
            $errors += str_starts_with($result, 'error') ? 1 : 0;
        }

        return $errors === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
