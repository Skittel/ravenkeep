<?php

namespace App\Commands;

use App\Libraries\Nodes\NodeSettings;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use InvalidArgumentException;

/**
 * php spark ravenkeep:import-node-settings
 *
 * One-time takeover of the local node's current settings into the database (local domains from
 * Postfix, admin networks from /etc/ravenkeep/admin-networks), so the first config bundle that
 * includes these files does not change the running configuration. Existing entries are kept.
 */
class ImportNodeSettings extends BaseCommand
{
    protected $group       = 'Ravenkeep';
    protected $name        = 'ravenkeep:import-node-settings';
    protected $description = 'Imports local domains and admin networks from the local MX node into the database.';

    public function run(array $params)
    {
        $settings = new NodeSettings(Database::connect());

        $domainsFile = '/etc/ravenkeep/local-domains';
        $domains     = is_readable($domainsFile) ? (string) file_get_contents($domainsFile) : (string) shell_exec('/usr/sbin/postconf -h virtual_mailbox_domains');
        foreach (preg_split('/[\s,]+/', $domains, -1, PREG_SPLIT_NO_EMPTY) as $domain) {
            if ($domain[0] === '/' || str_contains($domain, ':')) {
                continue; // file name or lookup table, not a domain
            }
            $this->import(static fn () => $settings->addDomain($domain, 'imported'), "domain {$domain}");
        }

        $networksFile = '/etc/ravenkeep/admin-networks';
        foreach (is_readable($networksFile) ? file($networksFile, FILE_IGNORE_NEW_LINES) : [] as $line) {
            $network = trim(explode('#', $line, 2)[0]);
            if ($network !== '') {
                $this->import(static fn () => $settings->addNetwork($network, 'imported'), "network {$network}");
            }
        }

        return EXIT_SUCCESS;
    }

    private function import(callable $add, string $label): void
    {
        try {
            $add();
            CLI::write("imported {$label}");
        } catch (InvalidArgumentException $e) {
            CLI::error("skipped {$label}: invalid");
        }
    }
}
