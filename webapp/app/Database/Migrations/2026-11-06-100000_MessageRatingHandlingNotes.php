<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per message (product owner, 06.11.2026):
 *  - rating ("Status 1"): info, ok, warning, error; NULL = not rated. Only the field for now, the
 *    evaluation of the content comes later (T-17).
 *  - handling ("Status 2"): new, open, done, waiting (until handling_until), with who/when.
 *  - notes: written once, never changed or deleted; author from the sign-in.
 */
class MessageRatingHandlingNotes extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('messages', [
            'rating'         => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true, 'comment' => 'info|ok|warning|error, NULL = not rated'],
            'handling'       => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'new', 'comment' => 'new|open|done|waiting'],
            'handling_until' => ['type' => 'DATE', 'null' => true, 'comment' => 'handling = waiting: until this day'],
            'handling_by'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'handling_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->db->query('ALTER TABLE messages ADD INDEX messages_rating (rating), ADD INDEX messages_handling (handling)');

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'message_id'      => ['type' => 'BIGINT', 'unsigned' => true],
            'author_name'     => ['type' => 'VARCHAR', 'constraint' => 255],
            'author_username' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'author_oid'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'body'            => ['type' => 'TEXT'],
            'created_at'      => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['message_id', 'id']);
        $this->forge->addForeignKey('message_id', 'messages', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('message_notes', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('message_notes', true);
        $this->db->query('ALTER TABLE messages DROP INDEX messages_rating, DROP INDEX messages_handling');
        $this->forge->dropColumn('messages', ['rating', 'handling', 'handling_until', 'handling_by', 'handling_at']);
    }
}
