<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Checks and tag meaning per sender allow rule (decision E-021, replaces the customer domains of E-020):
 * - auth_check: what an allowed sender must pass (aligned with the From domain):
 *   spf_or_dkim (default, as E-002), spf_and_dkim, spf, dkim, none (only if set explicitly).
 * - tag_customer_id / tag_source: meaning of the From domain for tags. Customer: the domain stands
 *   for the customer code (server77_plesk@domain = server77_plesk_<customer>). Source: the domain
 *   stands for a source tag and the local part names the device (server71@plesk.example).
 * The customer domains of 0.3.14 are moved to the matching allow rule.
 */
class SenderRuleChecks extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('sender_rules', [
            'auth_check'      => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'spf_or_dkim', 'after' => 'action'],
            'tag_customer_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'auth_check'],
            'tag_source'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'tag_customer_id'],
        ]);
        $this->db->query('ALTER TABLE sender_rules ADD CONSTRAINT sender_rules_tag_customer_fk FOREIGN KEY (tag_customer_id) REFERENCES customers (id) ON DELETE SET NULL ON UPDATE CASCADE');

        if ($this->db->tableExists('customer_domains')) {
            $this->db->query("UPDATE sender_rules sr JOIN customer_domains cd ON cd.domain = sr.pattern SET sr.tag_customer_id = cd.customer_id WHERE sr.action = 'allow'");
            $this->forge->dropTable('customer_domains');
        }
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE sender_rules DROP FOREIGN KEY sender_rules_tag_customer_fk');
        $this->forge->dropColumn('sender_rules', ['auth_check', 'tag_customer_id', 'tag_source']);
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
    }
}
