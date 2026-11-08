<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Subject patterns per source and aliases for customers, locations and devices (decision E-029).
 */
class SubjectPatterns extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'source'     => ['type' => 'VARCHAR', 'constraint' => 64, 'comment' => 'source tag value the pattern applies to'],
            'pattern'    => ['type' => 'VARCHAR', 'constraint' => 255, 'comment' => 'text with placeholders {kunde} {standort} {geraet} {*}'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['source', 'pattern']);
        $this->forge->createTable('subject_patterns', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'kind'        => ['type' => 'VARCHAR', 'constraint' => 16, 'comment' => 'customer | location | device'],
            'customer_id' => ['type' => 'INT', 'unsigned' => true],
            'location_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'device_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'alias'       => ['type' => 'VARCHAR', 'constraint' => 128],
            'alias_key'   => ['type' => 'VARCHAR', 'constraint' => 128, 'comment' => 'normalized alias used for matching'],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['kind', 'customer_id', 'alias_key']);
        $this->forge->addForeignKey('customer_id', 'customers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('location_id', 'locations', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('device_id', 'devices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('aliases', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addColumn('messages', [
            'subject_pattern_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'sender_unverified'],
            'subject_unknown'    => ['type' => 'TEXT', 'null' => true, 'after' => 'subject_pattern_id'],
        ]);
        $this->db->query('ALTER TABLE messages ADD KEY messages_subject_pattern (subject_pattern_id)');
        $this->db->query("ALTER TABLE message_tags MODIFY source VARCHAR(8) NOT NULL COMMENT 'rule | manual | device | address | sender | pattern'");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE messages DROP KEY messages_subject_pattern');
        $this->forge->dropColumn('messages', ['subject_pattern_id', 'subject_unknown']);
        $this->forge->dropTable('aliases', true);
        $this->forge->dropTable('subject_patterns', true);
    }
}
