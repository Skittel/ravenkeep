<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sender addresses carry tags too (decision E-020):
 * - customer_domains: sender domains of a customer; the domain stands for the customer code, so
 *   server77_plesk@customer-domain is read like server77_plesk_<customer>. subdomains = 1: also
 *   all subdomains of the domain.
 * - messages.sender_unknown: JSON list of the unknown parts of the sender address (as address_unknown).
 * - messages.sender_unverified: why a sender of a customer domain was not trusted (SPF and DKIM must
 *   both pass for a domain of that customer), e.g. "spf:softfail dkim:none".
 */
class CustomerDomains extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'customer_id' => ['type' => 'INT', 'unsigned' => true],
            'domain'      => ['type' => 'VARCHAR', 'constraint' => 253],
            'subdomains'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('domain');
        $this->forge->addForeignKey('customer_id', 'customers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('customer_domains');

        $this->forge->addColumn('messages', [
            'sender_unknown'    => ['type' => 'TEXT', 'null' => true, 'after' => 'address_unknown'],
            'sender_unverified' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true, 'after' => 'sender_unknown'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('messages', ['sender_unknown', 'sender_unverified']);
        $this->forge->dropTable('customer_domains');
    }
}
