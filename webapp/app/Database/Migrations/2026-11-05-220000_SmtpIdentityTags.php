<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SMTP login per component (decision E-024): several logins per device (e.g. IPMI, Proxmox and PBS
 * of one server), user name freely chosen, fixed tags per login. Messages of a login get the tags
 * of its device plus its own tags; nothing is read from addresses.
 */
class SmtpIdentityTags extends Migration
{
    public function up(): void
    {
        // the foreign key needs an index: add the plain one before dropping the unique one
        $indexes = array_column($this->db->query('SHOW INDEX FROM smtp_identities')->getResultArray(), 'Key_name');
        if (! in_array('smtp_identities_device_idx', $indexes, true)) {
            $this->db->query('ALTER TABLE smtp_identities ADD INDEX smtp_identities_device_idx (device_id)');
        }
        if (in_array('smtp_identities_device', $indexes, true)) {
            $this->db->query('ALTER TABLE smtp_identities DROP INDEX smtp_identities_device');
        }

        $this->forge->addField([
            'identity_id' => ['type' => 'INT', 'unsigned' => true],
            'tag_id'      => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey(['identity_id', 'tag_id']);
        $this->forge->addForeignKey('identity_id', 'smtp_identities', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('tag_id', 'tags', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('smtp_identity_tags');
    }

    public function down(): void
    {
        $this->forge->dropTable('smtp_identity_tags');
        // back to the old index name (as a plain index: a device may have several logins by now)
        $this->db->query('ALTER TABLE smtp_identities ADD INDEX smtp_identities_device (device_id)');
        $this->db->query('ALTER TABLE smtp_identities DROP INDEX smtp_identities_device_idx');
    }
}
