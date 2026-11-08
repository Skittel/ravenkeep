<?php

namespace App\Database\Migrations;

use App\Libraries\CharsetRepair;
use CodeIgniter\Database\Migration;

/**
 * T-29, second pass: also mixed text (UTF-8 template with Latin-1 names), see CharsetRepair.
 * Data only, nothing to undo.
 */
class RepairMixedCharset extends Migration
{
    public function up(): void
    {
        CharsetRepair::run($this->db, config('Ravenkeep')->archivePath);
    }

    public function down(): void
    {
    }
}
