<?php

use App\Libraries\Auth\EntraClient;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class AuthTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        $config               = config('Entra');
        $config->tenantId     = 'tenant';
        $config->clientId     = 'client';
        $config->clientSecret = 'secret';
        $config->groupRw      = 'rw';
        $config->groupRo      = 'ro';
    }

    private static function user(string $role, int $age = 0): array
    {
        return ['user' => ['oid' => 'o', 'name' => 'Test', 'username' => 't@example.org', 'role' => $role, 'login_at' => time() - $age]];
    }

    public function testPagesRequireSignIn(): void
    {
        $result = $this->get('rules');
        $result->assertRedirect();
        $this->assertStringContainsString('auth/login?return=%2Frules', $result->getRedirectUrl());

        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get('messages/1')->assertStatus(401);
    }

    public function testExpiredSessionRequiresNewSignIn(): void
    {
        $this->withSession(self::user('rw', 50000))->get('rules')->assertRedirect();
    }

    public function testReadOnlyUsersCanReadButNotChange(): void
    {
        $this->withSession(self::user('ro'))->get('rules')->assertOK();
        $result = $this->withSession(self::user('ro'))->post('rules/1/delete');
        $result->assertStatus(403);
    }

    public function testReadWriteUsersCanChange(): void
    {
        $this->db->table('rules')->insert(['name' => 'r', 'position' => 10]);
        $id = $this->db->insertID();
        // This test covers the role check; CSRF is tested end-to-end against the running app
        config('Filters')->globals['before'] = ['rklogin' => ['except' => ['auth/*']]];
        $this->withSession(self::user('rw'))->post("rules/{$id}/delete")->assertRedirect();
        $this->dontSeeInDatabase('rules', ['id' => $id]);
    }

    public function testCallbackRejectsWrongState(): void
    {
        $result = $this->withSession(['oidc' => ['state' => 's1', 'nonce' => 'n', 'verifier' => 'v', 'return' => '/', 'created' => time()]])
            ->get('auth/callback?state=other&code=c');
        $result->assertStatus(401);
        $this->assertNull(session('user'));
    }

    public function testCallbackSignsInUserWithRoleAndRejectsUsersWithoutGroup(): void
    {
        $mock = $this->createMock(EntraClient::class);
        $mock->method('redeemCode')->willReturn(['claims' => (object) ['oid' => 'abc', 'name' => 'Anna', 'preferred_username' => 'anna@example.org'], 'access_token' => 't']);
        $mock->method('roleFor')->willReturnOnConsecutiveCalls('rw', null);
        $mock->method('groupDiagnosis')->willReturn('test');
        Services::injectMock('entraClient', $mock);
        $oidc = ['oidc' => ['state' => 's1', 'nonce' => 'n', 'verifier' => 'v', 'return' => '/rules', 'created' => time()]];

        $result = $this->withSession($oidc)->get('auth/callback?state=s1&code=c');
        $result->assertRedirectTo(site_url('rules'));
        $this->assertSame(['abc', 'rw'], [session('user')['oid'], session('user')['role']]);

        session()->remove('user');
        $result = $this->withSession($oidc)->get('auth/callback?state=s1&code=c');
        $result->assertStatus(403);
        $this->assertNull(session('user'));
    }
}
