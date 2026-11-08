<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Subject patterns can be switched off without deleting them (E-029).
 */
class SubjectPatternEnabled extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('subject_patterns', [
            'enabled'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1, 'after' => 'pattern'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'created_at'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('subject_patterns', ['enabled', 'updated_at']);
    }
}
