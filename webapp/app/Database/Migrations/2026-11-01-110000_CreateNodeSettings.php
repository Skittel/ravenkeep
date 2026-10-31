<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Data the web app delivers to the MX nodes as config files (decision E-009, T-13):
 * - smtp_identities -> smtp-users (Dovecot passwd-file, bcrypt)
 * - local_domains   -> local-domains (domains the nodes accept mail for)
 * - admin_networks  -> admin-networks (never banned by fail2ban)
 */
class CreateNodeSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'username'        => ['type' => 'VARCHAR', 'constraint' => 255, 'comment' => 'local-part@local-domain'],
            'password_hash'   => ['type' => 'VARCHAR', 'constraint' => 255, 'comment' => 'bcrypt ($2y$)'],
            'enabled'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'note'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'password_set_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('username');
        $this->forge->createTable('smtp_identities');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'domain'     => ['type' => 'VARCHAR', 'constraint' => 253],
            'note'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('domain');
        $this->forge->createTable('local_domains');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'network'    => ['type' => 'VARCHAR', 'constraint' => 64, 'comment' => 'IPv4/IPv6 address or CIDR'],
            'note'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('network');
        $this->forge->createTable('admin_networks');
    }

    public function down(): void
    {
        $this->forge->dropTable('admin_networks');
        $this->forge->dropTable('local_domains');
        $this->forge->dropTable('smtp_identities');
    }
}
