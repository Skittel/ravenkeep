<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One row per received message and recipient (as stored by the MX node).
 * The original message is kept as an EML file in the archive; this table holds the metadata.
 * (node_id, spool_id) is the durable import key – a re-run never imports an entry twice,
 * but two identical messages sent separately remain two rows.
 */
class CreateMessages extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'node_id'             => ['type' => 'INT', 'unsigned' => true],
            'spool_id'            => ['type' => 'VARCHAR', 'constraint' => 64],
            'received_at'         => ['type' => 'DATETIME'],
            'mode'                => ['type' => 'VARCHAR', 'constraint' => 8, 'comment' => 'mx | auth'],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 16, 'comment' => 'accepted | rejected | pending | blocked'],
            'auth_verdict'        => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'sasl_username'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'envelope_sender'     => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'envelope_recipient'  => ['type' => 'VARCHAR', 'constraint' => 255],
            'client_ip'           => ['type' => 'VARCHAR', 'constraint' => 45, 'default' => ''],
            'client_hostname'     => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'client_ptr'          => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'client_helo'         => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'header_from'         => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'header_from_address' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'header_to'           => ['type' => 'TEXT', 'null' => true],
            'subject'             => ['type' => 'VARCHAR', 'constraint' => 998, 'null' => true],
            'header_date'         => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'message_id'          => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'size'                => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'queue_id'            => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => ''],
            'list_match_pattern'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'list_match_target'   => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'reasons'             => ['type' => 'TEXT', 'null' => true, 'comment' => 'JSON array'],
            'auth_details'        => ['type' => 'MEDIUMTEXT', 'null' => true, 'comment' => 'JSON: spf, dkim, from_domain, alignment'],
            'eml_path'            => ['type' => 'VARCHAR', 'constraint' => 255, 'comment' => 'relative to the archive directory'],
            'eml_sha256'          => ['type' => 'CHAR', 'constraint' => 64],
            'created_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['node_id', 'spool_id']);
        $this->forge->addKey(['status', 'received_at']);
        $this->forge->addKey('received_at');
        $this->forge->addKey('envelope_sender');
        $this->forge->addKey('header_from_address');
        $this->forge->addKey('sasl_username');
        $this->forge->addForeignKey('node_id', 'nodes', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('messages');
    }

    public function down(): void
    {
        $this->forge->dropTable('messages');
    }
}
