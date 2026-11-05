<?php

use App\Libraries\Mailrunner\MailrunnerClient;
use App\Libraries\Mailrunner\SmtpCredentials;
use App\Libraries\Nodes\NodeSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Fake node for /v1/smtp-credentials.
 */
final class FakeCredentialNode extends MailrunnerClient
{
    /** @var array<string, array> credential_id => credential */
    public array $credentials = [];

    /** @var list<string> */
    public array $calls = [];

    private int $next = 1;

    public function __construct()
    {
        parent::__construct('https://node.example', 'mrt_test.secret');
    }

    protected function request(string $method, string $path, ?array $body = null): array
    {
        $this->calls[] = $method . ' ' . $path;
        if ($method === 'POST' && $path === '/v1/smtp-credentials') {
            foreach ($this->credentials as $c) {
                if ($c['username'] === $body['username']) {
                    return [409, '{"error":{"code":"USERNAME_EXISTS","message":"exists"}}'];
                }
            }
            $id                     = 'mrc_' . $this->next++;
            $this->credentials[$id] = ['credential_id' => $id, 'enabled' => true] + $body;

            return [201, json_encode(['data' => $this->credentials[$id], 'password' => 'pw-' . $id])];
        }
        if (preg_match('#^/v1/smtp-credentials/(mrc_\d+)(/rotate)?$#', $path, $m)) {
            if (! isset($this->credentials[$m[1]])) {
                return [404, '{"error":{"code":"NOT_FOUND","message":"x"}}'];
            }
            if ($method === 'PATCH') {
                $this->credentials[$m[1]] = $body + $this->credentials[$m[1]];

                return [200, json_encode(['data' => $this->credentials[$m[1]]])];
            }
            if ($method === 'POST' && ($m[2] ?? '') === '/rotate') {
                return [200, json_encode(['data' => $this->credentials[$m[1]], 'password' => 'new-' . $body['grace_seconds']])];
            }
            if ($method === 'DELETE') {
                unset($this->credentials[$m[1]]);

                return [200, '{"data":null}'];
            }
        }

        return [404, '{"error":{"code":"NOT_FOUND","message":"x"}}'];
    }
}

/**
 * SMTP logins on the MailRunner node (T-21, E-024).
 *
 * @internal
 */
final class SmtpCredentialsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private FakeCredentialNode $node;
    private SmtpCredentials $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        (new NodeSettings($this->db))->addDomain('customer.example.org', null);
        $this->node        = new FakeCredentialNode();
        $this->credentials = new SmtpCredentials($this->db, $this->node);
    }

    public function testCreateRotateToggleDelete(): void
    {
        [$id, $password, $username] = $this->credentials->create('pm10_ipmi_hq1_acme', 'customer.example.org', 'IPMI', null, false, ['198.51.100.0/24']);
        $this->assertSame('pw-mrc_1', $password);
        $this->assertSame('pm10_ipmi_hq1_acme@customer.example.org', $username);
        $this->seeInDatabase('smtp_identities', ['id' => $id, 'credential_id' => 'mrc_1', 'password_hash' => null, 'allowed_networks' => '198.51.100.0/24']);
        $this->assertSame(['198.51.100.0/24'], $this->node->credentials['mrc_1']['allowed_networks']);
        $this->assertSame(['mrc_1' => $username], SmtpCredentials::map($this->db));

        $this->assertSame('new-86400', $this->credentials->rotate($id, 86400));

        $this->credentials->toggle($id);
        $this->assertFalse($this->node->credentials['mrc_1']['enabled']);
        $this->seeInDatabase('smtp_identities', ['id' => $id, 'enabled' => 0]);

        $this->credentials->updateOptions($id, true, []);
        $this->assertTrue($this->node->credentials['mrc_1']['plaintext_port25']);

        $this->credentials->delete($id);
        $this->assertSame([], $this->node->credentials);
        $this->dontSeeInDatabase('smtp_identities', ['id' => $id]);
    }

    public function testErrorsOfTheNodeAndLegacyLogins(): void
    {
        $this->node->credentials['mrc_99'] = ['credential_id' => 'mrc_99', 'username' => 'taken@customer.example.org'];
        try {
            $this->credentials->create('taken', 'customer.example.org', null, null);
            $this->fail('created twice');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('mr_username_exists', $e->getMessage());
        }
        $this->dontSeeInDatabase('smtp_identities', ['username' => 'taken@customer.example.org']);

        // a login from before MailRunner is created on the node with the same user name
        [$legacy] = (new NodeSettings($this->db))->createIdentity('old.device', 'customer.example.org', null);
        $this->assertSame('pw-mrc_1', $this->credentials->register($legacy));
        $this->seeInDatabase('smtp_identities', ['id' => $legacy, 'credential_id' => 'mrc_1', 'password_hash' => null]);
    }
}
