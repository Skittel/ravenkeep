<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SMTP logins live on the MailRunner node (T-21, E-024): the node generates the password and
 * reports the login of a message only as credential_id ("mrc_…"). Ravenkeep keeps the mapping
 * credential_id -> login with device and tags; no password hash is stored any more.
 */
class MailrunnerCredentials extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('smtp_identities', [
            'credential_id'    => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'id'],
            'plaintext_port25' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'enabled'],
            'allowed_networks' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'plaintext_port25'],
        ]);
        $this->db->query('ALTER TABLE smtp_identities ADD UNIQUE INDEX smtp_identities_credential (credential_id)');
        $this->db->query("ALTER TABLE smtp_identities MODIFY password_hash VARCHAR(255) NULL COMMENT 'only logins from before MailRunner'");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE smtp_identities DROP INDEX smtp_identities_credential');
        $this->forge->dropColumn('smtp_identities', ['credential_id', 'plaintext_port25', 'allowed_networks']);
    }
}
