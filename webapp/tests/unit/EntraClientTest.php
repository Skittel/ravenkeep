<?php

use App\Libraries\Auth\EntraClient;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Entra;
use Firebase\JWT\JWT;

/**
 * ID token verification with a locally generated key (no network).
 *
 * @internal
 */
final class EntraClientTest extends CIUnitTestCase
{
    private static $key;
    private static array $jwks;
    private Entra $config;
    private EntraClient $client;

    public static function setUpBeforeClass(): void
    {
        self::$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details   = openssl_pkey_get_details(self::$key)['rsa'];
        self::$jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256',
            'n' => EntraClient::base64url($details['n']), 'e' => EntraClient::base64url($details['e'])]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->config               = new Entra();
        $this->config->tenantId     = 'tenant-' . bin2hex(random_bytes(4)); // unique: JWKS cache per tenant
        $this->config->clientId     = 'client-123';
        $this->config->clientSecret = 'secret';
        $this->config->groupRw      = 'group-rw';
        $this->config->groupRo      = 'group-ro';
        $this->client               = new EntraClient($this->config, null, fn () => self::$jwks);
    }

    private function token(array $override = [], $key = null): string
    {
        openssl_pkey_export($key ?? self::$key, $pem);
        $claims = array_merge([
            'iss' => "https://login.microsoftonline.com/{$this->config->tenantId}/v2.0", 'aud' => 'client-123',
            'tid' => $this->config->tenantId, 'oid' => 'user-oid', 'nonce' => 'n1', 'name' => 'Test User',
            'preferred_username' => 'test@example.org', 'groups' => ['group-ro'], 'iat' => time(), 'nbf' => time(), 'exp' => time() + 3600,
        ], $override);

        return JWT::encode(array_filter($claims, static fn ($v) => $v !== null), $pem, 'RS256', 'test-key');
    }

    public function testValidTokenAndRoles(): void
    {
        $claims = $this->client->verifyIdToken($this->token(), 'n1');
        $this->assertSame('user-oid', $claims->oid);
        $this->assertSame('ro', $this->client->role($claims));
        $this->assertSame('rw', $this->client->role($this->client->verifyIdToken($this->token(['groups' => ['x', 'group-rw', 'group-ro']]), 'n1')));
        $this->assertNull($this->client->role($this->client->verifyIdToken($this->token(['groups' => ['other']]), 'n1')));
        $this->assertNull($this->client->role($this->client->verifyIdToken($this->token(['groups' => null]), 'n1')));
    }

    public function testInvalidTokensAreRejected(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $cases = [
            'wrong nonce'    => [$this->token(), 'n2'],
            'wrong audience' => [$this->token(['aud' => 'other-app']), 'n1'],
            'wrong issuer'   => [$this->token(['iss' => 'https://login.microsoftonline.com/other/v2.0']), 'n1'],
            'wrong tenant'   => [$this->token(['tid' => 'other']), 'n1'],
            'expired'        => [$this->token(['exp' => time() - 600, 'iat' => time() - 4000, 'nbf' => time() - 4000]), 'n1'],
            'no object id'   => [$this->token(['oid' => null]), 'n1'],
            'foreign key'    => [$this->token([], $other), 'n1'],
            'tampered'       => [preg_replace('/\.([^.]+)\./', '.' . EntraClient::base64url(json_encode(['oid' => 'evil'])) . '.', $this->token()), 'n1'],
        ];
        foreach ($cases as $name => [$token, $nonce]) {
            try {
                $this->client->verifyIdToken($token, $nonce);
                $this->fail("accepted token: {$name}");
            } catch (Throwable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAuthorizationRequestUsesPkceStateAndNonce(): void
    {
        $request = $this->client->authorizationRequest('https://app.example/auth/callback');
        parse_str(parse_url($request['url'], PHP_URL_QUERY), $query);

        $this->assertStringStartsWith("https://login.microsoftonline.com/{$this->config->tenantId}/oauth2/v2.0/authorize?", $request['url']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertSame(EntraClient::base64url(hash('sha256', $request['verifier'], true)), $query['code_challenge']);
        $this->assertSame($request['state'], $query['state']);
        $this->assertSame($request['nonce'], $query['nonce']);
        $this->assertArrayNotHasKey('client_secret', $query);
    }

    public function testRedeemCodeSendsVerifierAndVerifiesToken(): void
    {
        $sent   = [];
        $client = new EntraClient($this->config, function (string $url, array $form) use (&$sent) {
            $sent = $form;

            return ['id_token' => $this->token()];
        }, fn () => self::$jwks);

        $claims = $client->redeemCode('the-code', 'https://app.example/auth/callback', 'the-verifier', 'n1');
        $this->assertSame('user-oid', $claims->oid);
        $this->assertSame(['the-code', 'the-verifier', 'authorization_code'], [$sent['code'], $sent['code_verifier'], $sent['grant_type']]);
    }
}
