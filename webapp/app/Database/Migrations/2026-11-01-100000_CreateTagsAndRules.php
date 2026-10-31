<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tags and rules (concept sections 7 and 8).
 *
 * - tags: free names, optionally typed by a prefix ("kunde:acme" -> type "kunde")
 * - message_tags: tags of a message; source "rule" (with rule_id) or "manual"
 * - rules: evaluated in ascending position; each rule sees the tags set by earlier rules
 * - rule_conditions / rule_actions: what a rule checks and what it does
 * - settings: key/value store (rules_version for background re-evaluation)
 * - messages.body_text: plain text for rule conditions and search
 * - messages.rules_version: rules_version the message was evaluated with (0 = not yet)
 */
class CreateTagsAndRules extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 64],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('tags');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 128],
            'position'   => ['type' => 'INT', 'default' => 0],
            'enabled'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'match_mode' => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'all', 'comment' => 'all | any'],
            'stop'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'comment' => 'stop evaluating further rules when matched'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('position');
        $this->forge->createTable('rules');

        $this->forge->addField([
            'id'       => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'rule_id'  => ['type' => 'INT', 'unsigned' => true],
            'field'    => ['type' => 'VARCHAR', 'constraint' => 32],
            'operator' => ['type' => 'VARCHAR', 'constraint' => 16],
            'value'    => ['type' => 'VARCHAR', 'constraint' => 255],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('rule_id', 'rules', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('rule_conditions');

        $this->forge->addField([
            'id'      => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'rule_id' => ['type' => 'INT', 'unsigned' => true],
            'type'    => ['type' => 'VARCHAR', 'constraint' => 32, 'comment' => 'add_tag (more types later, e.g. zammad_ticket)'],
            'value'   => ['type' => 'VARCHAR', 'constraint' => 255],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('rule_id', 'rules', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('rule_actions');

        $this->forge->addField([
            'message_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'tag_id'     => ['type' => 'INT', 'unsigned' => true],
            'source'     => ['type' => 'VARCHAR', 'constraint' => 8, 'comment' => 'rule | manual'],
            'rule_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addPrimaryKey(['message_id', 'tag_id']);
        $this->forge->addKey('tag_id');
        $this->forge->addForeignKey('message_id', 'messages', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('tag_id', 'tags', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('rule_id', 'rules', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('message_tags');

        $this->forge->addField([
            'name'  => ['type' => 'VARCHAR', 'constraint' => 64],
            'value' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('name');
        $this->forge->createTable('settings');
        $this->db->table('settings')->insert(['name' => 'rules_version', 'value' => '1']);

        $this->forge->addColumn('messages', [
            'body_text'     => ['type' => 'MEDIUMTEXT', 'null' => true, 'after' => 'auth_details'],
            'rules_version' => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'after' => 'body_text'],
        ]);
        $this->db->query('CREATE INDEX messages_rules_version ON messages (rules_version)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX messages_rules_version ON messages');
        $this->forge->dropColumn('messages', ['body_text', 'rules_version']);
        $this->forge->dropTable('settings');
        $this->forge->dropTable('message_tags');
        $this->forge->dropTable('rule_actions');
        $this->forge->dropTable('rule_conditions');
        $this->forge->dropTable('rules');
        $this->forge->dropTable('tags');
    }
}
