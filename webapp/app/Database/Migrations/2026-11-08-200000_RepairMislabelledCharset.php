<?php

namespace App\Database\Migrations;

use App\Libraries\CharsetRepair;
use CodeIgniter\Database\Migration;

/**
 * T-29: repair messages labelled as a Latin character set but sent as UTF-8 (see CharsetRepair).
 * Data only, nothing to undo.
 */
class RepairMislabelledCharset extends Migration
{
    public function up(): void
    {
        CharsetRepair::run($this->db, config('Ravenkeep')->archivePath);
    }

    public function down(): void
    {
    }
}
