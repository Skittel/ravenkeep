<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Only known values from recipient addresses (decision E-019):
 * - address_map: a recipient address assigned to an existing device (e.g. a typo in the device
 *   configuration, or an address outside the scheme).
 * - messages.address_unknown: JSON list of the unknown parts of the recipient address, set by the
 *   RuleRunner for accepted messages of domains with parse_address; NULL if resolved or not applicable.
 *   The page "Unknown addresses" groups these messages by recipient.
 */
class AddressMap extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'address'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'device_id'  => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('address');
        $this->forge->addForeignKey('device_id', 'devices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('address_map');

        $this->forge->addColumn('messages', [
            'address_unknown' => ['type' => 'TEXT', 'null' => true, 'after' => 'rules_version'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('messages', 'address_unknown');
        $this->forge->dropTable('address_map');
    }
}
