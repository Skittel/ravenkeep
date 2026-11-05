<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Fixed tags per sender allow rule (decision E-022): accepted messages whose sender matches the
 * rule and passes its check get these tags (message_tags.source = 'sender').
 */
class SenderRuleTags extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'sender_rule_id' => ['type' => 'INT', 'unsigned' => true],
            'tag_id'         => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey(['sender_rule_id', 'tag_id']);
        $this->forge->addForeignKey('sender_rule_id', 'sender_rules', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('tag_id', 'tags', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('sender_rule_tags');
    }

    public function down(): void
    {
        $this->forge->dropTable('sender_rule_tags');
    }
}
