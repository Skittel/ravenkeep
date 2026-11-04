<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Address scheme and automatic tags (decision E-016):
 * - local_domains.parse_address: recipient addresses of this domain carry customer, location,
 *   type and device (device_type_location_customer, see AddressTags).
 * - A device is identified by customer + device code only (path "customer.device"), so moving
 *   it to another location keeps its identity, monitoring and history.
 */
class AddressTags extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('local_domains', [
            'parse_address' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'note'],
        ]);
        $prefix = $this->db->DBPrefix;
        $this->db->query("UPDATE {$prefix}devices d JOIN {$prefix}customers c ON c.id = d.customer_id SET d.path = CONCAT(c.code, '.', d.code)");
    }

    public function down(): void
    {
        $this->forge->dropColumn('local_domains', 'parse_address');
        $prefix = $this->db->DBPrefix;
        $this->db->query("UPDATE {$prefix}devices d JOIN {$prefix}customers c ON c.id = d.customer_id
            LEFT JOIN {$prefix}locations l ON l.id = d.location_id SET d.path = CONCAT_WS('.', c.code, l.code, d.code)");
    }
}
