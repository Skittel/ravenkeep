<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The password of a MailRunner SMTP login is kept encrypted (libsodium secretbox, key in
 * /etc/ravenkeep/secret.key outside the repository and database), so the same login can be set up
 * on a further node later (product owner, 05.11.2026).
 */
class SmtpPasswordEncrypted extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('smtp_identities', [
            'password_enc' => ['type' => 'TEXT', 'null' => true, 'after' => 'password_hash', 'comment' => 'encrypted with secret.key (Secrets)'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('smtp_identities', 'password_enc');
    }
}
