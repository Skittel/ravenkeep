<?php

use App\Libraries\Nodes\NodeConfigSync;
use App\Libraries\Nodes\NodeSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class NodeSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private NodeSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new NodeSettings($this->db);
        $this->settings->addDomain('customer.example.org', null);
    }

    public function testIdentityPasswordIsShownOnceAndStoredAsBcrypt(): void
    {
        [$id, $password] = $this->settings->createIdentity('Acme.Site.NAS01', 'customer.example.org', 'test');

        $this->assertMatchesRegularExpression('/^[A-Za-z2-9]{24}$/', $password);
        $row = $this->db->table('smtp_identities')->where('id', $id)->get()->getRowArray();
        $this->assertSame('acme.site.nas01@customer.example.org', $row['username']);
        $this->assertStringStartsWith('$2y$10$', $row['password_hash']);
        $this->assertTrue(password_verify($password, $row['password_hash']));
        $this->assertStringNotContainsString($password, json_encode($row));

        $new = $this->settings->resetPassword($id);
        $row = $this->db->table('smtp_identities')->where('id', $id)->get()->getRowArray();
        $this->assertFalse(password_verify($password, $row['password_hash']));
        $this->assertTrue(password_verify($new, $row['password_hash']));
    }

    public function testRenderedSmtpUsersContainOnlyEnabledIdentities(): void
    {
        [$a] = $this->settings->createIdentity('a', 'customer.example.org', null);
        $this->settings->createIdentity('b', 'customer.example.org', null);
        $this->settings->toggleIdentity($a);

        $users = $this->settings->renderFiles()['smtp-users'];
        $this->assertStringNotContainsString('a@customer.example.org', $users);
        $this->assertMatchesRegularExpression('/^b@customer\.example\.org:\{BLF-CRYPT\}\$2y\$10\$[.\/A-Za-z0-9]{53}$/m', $users);
    }

    public function testInvalidIdentities(): void
    {
        foreach ([['bad name', 'customer.example.org'], ['.x', 'customer.example.org'], ['a..b', 'customer.example.org'], ['ok', 'unknown.example']] as [$local, $domain]) {
            try {
                $this->settings->createIdentity($local, $domain, null);
                $this->fail("accepted {$local}@{$domain}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->settings->createIdentity('dup', 'customer.example.org', null);
        $this->expectExceptionMessage('exists');
        $this->settings->createIdentity('DUP', 'customer.example.org', null);
    }

    public function testDomainsCannotBeEmptiedOrRemovedWhileInUse(): void
    {
        $only = (int) $this->db->table('local_domains')->get()->getRow()->id;
        try {
            $this->settings->deleteDomain($only);
            $this->fail('deleted last domain');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('last_domain', $e->getMessage());
        }
        $this->settings->addDomain('Other.Example.ORG', null);
        $this->settings->createIdentity('dev', 'customer.example.org', null);
        try {
            $this->settings->deleteDomain($only);
            $this->fail('deleted domain in use');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('domain_in_use', $e->getMessage());
        }
        $this->assertSame("customer.example.org\nother.example.org\n", $this->settings->renderFiles()['local-domains']);
    }

    public function testNetworks(): void
    {
        $this->assertSame('192.0.2.10', NodeSettings::normalizeNetwork(' 192.0.2.10 '));
        $this->assertSame('2001:db8::/64', NodeSettings::normalizeNetwork('2001:DB8:0:0::/64'));
        foreach (['10.0.0.0/4', '300.1.1.1', '192.0.2.1/33', 'example.org', '::/0'] as $bad) {
            try {
                NodeSettings::normalizeNetwork($bad);
                $this->fail("accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEmptyTablesAreNotInTheBundle(): void
    {
        $this->db->table('local_domains')->truncate();
        $files = (new NodeConfigSync($this->db))->bundle()['files'];
        $this->assertArrayHasKey('smtp-users', $files);
        $this->assertArrayNotHasKey('local-domains', $files);
        $this->assertArrayNotHasKey('admin-networks', $files);
    }
}
