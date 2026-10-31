<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Allow/block rules for senders on the public MX (decisions E-007, E-008).
 * pattern: domain (also matches subdomains) or full address; target: envelope sender or From header.
 * The rules are rendered into plain config files for the MX nodes (E-009).
 */
class CreateSenderRules extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'pattern'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'target'     => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'envelope', 'comment' => 'envelope | header'],
            'action'     => ['type' => 'VARCHAR', 'constraint' => 8, 'comment' => 'allow | block'],
            'note'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['pattern', 'target']);
        $this->forge->createTable('sender_rules');

        $this->forge->addColumn('nodes', [
            'config_path'         => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => '/var/lib/ravenkeep', 'after' => 'spool_path',
                'comment' => 'node data dir: config-incoming/ and config-status.json'],
            'config_hash'         => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'comment' => 'hash of the last pushed bundle'],
            'config_pushed_at'    => ['type' => 'DATETIME', 'null' => true],
            'config_applied_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'comment' => 'reported by the node'],
            'config_applied_at'   => ['type' => 'DATETIME', 'null' => true],
            'config_error'        => ['type' => 'TEXT', 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('nodes', ['config_path', 'config_hash', 'config_pushed_at', 'config_applied_hash', 'config_applied_at', 'config_error']);
        $this->forge->dropTable('sender_rules');
    }
}
