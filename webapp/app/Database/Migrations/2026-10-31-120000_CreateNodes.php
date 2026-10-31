<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * MX/SMTP nodes. The web app is the single source of truth: it collects the spool from
 * each node and provides each node with plain config files, so nodes work without the database.
 */
class CreateNodes extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'              => ['type' => 'VARCHAR', 'constraint' => 64],
            'hostname'          => ['type' => 'VARCHAR', 'constraint' => 255],
            'transport'         => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'local', 'comment' => 'local | ssh'],
            'spool_path'        => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => '/var/lib/ravenkeep/spool'],
            'enabled'           => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'last_collect_at'   => ['type' => 'DATETIME', 'null' => true],
            'last_collect_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'last_error'        => ['type' => 'TEXT', 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('nodes');
    }

    public function down(): void
    {
        $this->forge->dropTable('nodes');
    }
}
