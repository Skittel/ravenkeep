<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Managed tag types (decision E-023): the part before ":" comes from this list; new tags can only be
 * created with a known type (typos like "sources:" no longer create a new type). Seeded with the
 * types set by Ravenkeep itself and all types in use.
 */
class TagTypes extends Migration
{
    private const SYSTEM = ['kunde', 'standort', 'geraet', 'source', 'meldung'];

    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 32],
            'note'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('tag_types');

        $used = $this->db->query("
            SELECT name FROM tags
            UNION SELECT value FROM rule_actions WHERE type = 'add_tag'
            UNION SELECT value FROM rule_conditions WHERE field = 'tag'")->getResultArray();
        $types = self::SYSTEM;
        foreach ($used as $row) {
            if (str_contains($row['name'], ':')) {
                $types[] = explode(':', $row['name'], 2)[0];
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        foreach (array_unique($types) as $type) {
            if ($type !== '' && mb_strlen($type) <= 32) {
                $this->db->table('tag_types')->insert(['name' => $type, 'created_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('tag_types');
    }
}
