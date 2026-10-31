<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Registers the MX node running on this server (transport "local") if it does not exist yet.
 * php spark db:seed LocalNodeSeeder
 */
class LocalNodeSeeder extends Seeder
{
    public function run(): void
    {
        $hostname = gethostname() ?: 'localhost';
        $fqdn     = gethostbyaddr(gethostbyname($hostname)) ?: $hostname;
        $name     = explode('.', $fqdn)[0];

        if ($this->db->table('nodes')->where('name', $name)->countAllResults() > 0) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('nodes')->insert([
            'name'       => $name,
            'hostname'   => $fqdn,
            'transport'  => 'local',
            'spool_path' => '/var/lib/ravenkeep/spool',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
