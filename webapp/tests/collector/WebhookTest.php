<?php

use App\Libraries\Mailrunner\CollectSignal;
use App\Libraries\Mailrunner\MailrunnerClient;
use App\Libraries\Mailrunner\Webhook;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/** Fake node for PUT /v1/webhook. */
final class FakeWebhookClient extends MailrunnerClient
{
    /** @var list<string> */
    public array $urls = [];
    public bool $fail  = false;

    public function __construct()
    {
        parent::__construct('https://node.example', 'mrt_test.secret');
    }

    protected function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->fail) {
            return [503, '{"error":{"code":"UNAVAILABLE","message":"down"}}'];
        }
        if ($method === 'PUT' && $path === '/v1/webhook') {
            $this->urls[] = $body['url'];

            return [200, json_encode(['url' => $body['url'], 'cooldown_seconds' => $body['cooldown_seconds']])];
        }

        return [200, '{}'];
    }
}

/**
 * MailRunner ping (T-22): rotating URL value, grace period, route without sign-in, run lock.
 *
 * @internal
 */
final class WebhookTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $runDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runDir = sys_get_temp_dir() . '/rk-run-' . bin2hex(random_bytes(4));
        config('Mailrunner')->runDir = $this->runDir;
    }

    private function webhook(): Webhook
    {
        return new Webhook($this->db, new CollectSignal($this->runDir), 'https://app.example/');
    }

    private function valueOf(string $url): string
    {
        return substr($url, strlen('https://app.example/hooks/mailrunner/'));
    }

    public function testRotationRegistersUrlAndAcceptsOnlyCurrentAndRecentValues(): void
    {
        $client = new FakeWebhookClient();
        $hook   = $this->webhook();
        $now    = 1_800_000_000;

        $hook->rotate($client, $now);
        $first = $this->valueOf($client->urls[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        $this->assertTrue($hook->ping($first, $now + 10));
        $this->assertFileExists($this->runDir . '/collect.pending');

        $hook->rotate($client, $now + 3600);
        $second = $this->valueOf($client->urls[1]);
        $this->assertNotSame($first, $second);
        $this->assertTrue($hook->ping($second, $now + 3601));
        $this->assertTrue($hook->ping($first, $now + 3600 + Webhook::GRACE_SECONDS), 'old value valid during the grace period');
        $this->assertFalse($hook->ping($first, $now + 3601 + Webhook::GRACE_SECONDS), 'old value expired');
        $this->assertFalse($hook->ping('wrong', $now + 3601));
        $this->assertFalse($hook->ping('', $now + 3601));
    }

    public function testFailedRotationKeepsTheCurrentValue(): void
    {
        $client = new FakeWebhookClient();
        $hook   = $this->webhook();
        $hook->rotate($client);
        $value = $this->valueOf($client->urls[0]);

        $client->fail = true;
        try {
            $hook->rotate($client);
            $this->fail('rotation should fail');
        } catch (RuntimeException) {
        }

        $this->assertTrue($hook->ping($value));
        $this->assertStringContainsString('HTTP 503', (string) $hook->state()['webhook_rotation_error']);
    }

    public function testRouteWorksWithoutSignInAndHidesUnknownValues(): void
    {
        $client = new FakeWebhookClient();
        $this->webhook()->rotate($client);
        $value = $this->valueOf($client->urls[0]);

        $this->post('hooks/mailrunner/' . $value)->assertStatus(204);
        $this->assertFileExists($this->runDir . '/collect.pending');
        $this->assertNotNull($this->webhook()->state()['webhook_last_ping_at']);

        $this->post('hooks/mailrunner/' . str_repeat('0', 64))->assertStatus(404);

        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class); // only POST is routed (404 in production)
        $this->get('hooks/mailrunner/' . $value);
    }

    public function testSecondRunEndsAtOnceAndRequestsAFollowUpRound(): void
    {
        $running = new CollectSignal($this->runDir);
        $this->assertTrue($running->acquire());
        $running->startRound();
        $this->assertFalse($running->hasPending());

        // a ping or the timer starts another run while the first one is active
        $second = new CollectSignal($this->runDir);
        $this->assertFalse($second->acquire());
        $second->request();

        $this->assertTrue($running->hasPending(), 'the running collect does one more round');
        $running->startRound();
        $this->assertFalse($running->hasPending());
        $running->release();

        $this->assertTrue($second->acquire(), 'lock is free after release');
        $second->release();
    }
}
