<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Customers, locations and devices (inventory).
 *
 * - Codes (customer, location, device) are fixed after creation: they are part of the automatic
 *   tags (kunde:<c>, standort:<c>.<l>, geraet:<c>[.<l>].<d>) and of the SMTP user name.
 * - device_tags: additional tags assigned to a device (e.g. nas, qnap).
 * - smtp_identities.device_id: an SMTP login belongs to exactly one device.
 * - message_tags.source can now also be "device" (tags of the sending device).
 * - timeout_profiles: how often a device is expected to send (hours); a device without an
 *   accepted message within this interval is overdue.
 */
class CreateInventory extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'code'       => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 128],
            'note'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('customers');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'customer_id' => ['type' => 'INT', 'unsigned' => true],
            'code'        => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 128],
            'note'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['customer_id', 'code']);
        $this->forge->addForeignKey('customer_id', 'customers', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('locations');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 64],
            'hours'      => ['type' => 'INT', 'unsigned' => true, 'comment' => 'expected interval in hours'],
            'note'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('timeout_profiles');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'customer_id' => ['type' => 'INT', 'unsigned' => true],
            'location_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'timeout_profile_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'code'        => ['type' => 'VARCHAR', 'constraint' => 32],
            'path'        => ['type' => 'VARCHAR', 'constraint' => 98, 'comment' => 'customer[.location].device – unique, used in tags and SMTP user name'],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 128],
            'note'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('path');
        $this->forge->addForeignKey('customer_id', 'customers', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('location_id', 'locations', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('timeout_profile_id', 'timeout_profiles', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('devices');

        $this->forge->addField([
            'device_id' => ['type' => 'INT', 'unsigned' => true],
            'tag_id'    => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey(['device_id', 'tag_id']);
        $this->forge->addForeignKey('device_id', 'devices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('tag_id', 'tags', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('device_tags');

        $this->forge->addColumn('smtp_identities', [
            'device_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'id'],
        ]);
        $this->db->query('ALTER TABLE smtp_identities ADD UNIQUE KEY smtp_identities_device (device_id), '
            . 'ADD CONSTRAINT smtp_identities_device_fk FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE RESTRICT ON UPDATE CASCADE');

        $this->db->query("ALTER TABLE message_tags MODIFY source VARCHAR(8) NOT NULL COMMENT 'rule | manual | device'");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE smtp_identities DROP FOREIGN KEY smtp_identities_device_fk, DROP KEY smtp_identities_device');
        $this->forge->dropColumn('smtp_identities', 'device_id');
        $this->forge->dropTable('device_tags');
        $this->forge->dropTable('devices');
        $this->forge->dropTable('timeout_profiles');
        $this->forge->dropTable('locations');
        $this->forge->dropTable('customers');
    }
}
