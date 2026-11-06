<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Customers that use a device (1:n), next to the one owning customer (devices.customer_id, 1:1).
 * Replaces "kunde:" device tags (0.3.31); existing ones are moved here.
 */
class DeviceUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'device_id'   => ['type' => 'INT', 'unsigned' => true],
            'customer_id' => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey(['device_id', 'customer_id']);
        $this->forge->addKey('customer_id');
        $this->forge->addForeignKey('device_id', 'devices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('customer_id', 'customers', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('device_customers', true, ['ENGINE' => 'InnoDB']);

        $this->db->query("INSERT IGNORE INTO device_customers (device_id, customer_id)
            SELECT dt.device_id, c.id FROM device_tags dt JOIN tags t ON t.id = dt.tag_id
            JOIN customers c ON t.name = CONCAT('kunde:', c.code) JOIN devices d ON d.id = dt.device_id
            WHERE d.customer_id <> c.id");
        $this->db->query("DELETE dt FROM device_tags dt JOIN tags t ON t.id = dt.tag_id WHERE t.name LIKE 'kunde:%'");
    }

    public function down(): void
    {
        $this->forge->dropTable('device_customers', true);
    }
}
