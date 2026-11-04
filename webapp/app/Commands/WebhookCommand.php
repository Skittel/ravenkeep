<?php

namespace App\Commands;

use App\Libraries\Mailrunner\CollectSignal;
use App\Libraries\Mailrunner\MailrunnerClient;
use App\Libraries\Mailrunner\Webhook;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * php spark ravenkeep:webhook rotate|status|disable
 *
 * rotate  – new random ping URL, registered at the MailRunner node (hourly by systemd timer)
 * status  – current state (no secret values)
 * disable – remove the ping at the node; collecting falls back to the timer
 */
class WebhookCommand extends BaseCommand
{
    protected $group       = 'Ravenkeep';
    protected $name        = 'ravenkeep:webhook';
    protected $description = 'Manages the MailRunner ping (webhook): rotate, status, disable.';
    protected $usage       = 'ravenkeep:webhook rotate|status|disable';

    public function run(array $params)
    {
        $webhook = new Webhook(Database::connect(), CollectSignal::fromConfig(), config('App')->baseURL);
        $action  = $params[0] ?? 'status';

        try {
            switch ($action) {
                case 'rotate':
                    // own lock: two rotations at the same time would register two values (T-22)
                    $lock = fopen(rtrim(config('Mailrunner')->runDir, '/') . '/webhook.lock', 'c');
                    if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                        CLI::write('rotation already running');
                        break;
                    }
                    $webhook->rotate(MailrunnerClient::fromConfig());
                    CLI::write('ping URL rotated');
                    break;

                case 'disable':
                    $webhook->disable(MailrunnerClient::fromConfig());
                    CLI::write('ping disabled');
                    break;

                case 'status':
                    $state = $webhook->state();
                    CLI::write('enabled:        ' . ($state['webhook_token'] !== null ? 'yes' : 'no'));
                    foreach (['webhook_rotated_at' => 'rotated at', 'webhook_last_ping_at' => 'last ping', 'webhook_late_at' => 'last late',
                        'webhook_rotation_error' => 'rotation error'] as $key => $label) {
                        CLI::write(str_pad($label . ':', 16) . ($state[$key] ?? '-'));
                    }
                    break;

                default:
                    CLI::error("Unknown action '{$action}'");

                    return EXIT_USER_INPUT;
            }
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
