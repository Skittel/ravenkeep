<?php

namespace App\Libraries\Mailrunner;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Ping from the MailRunner receive node (T-22): the node calls
 * POST <baseURL>hooks/mailrunner/<value> after new mail; Ravenkeep then starts a normal collect run.
 *
 * The ping carries no data, so a random value in the URL is enough (no signature). The value is
 * replaced every hour (rotate()); the new value is only used after the node confirmed it, and the
 * previous one stays valid for a grace period so that a ping on its way is not lost.
 * State is kept in the settings table (never in the repository).
 */
class Webhook
{
    public const GRACE_SECONDS = 300;

    private const KEYS = ['webhook_token', 'webhook_token_prev', 'webhook_token_prev_until', 'webhook_rotated_at',
        'webhook_rotation_error', 'webhook_last_ping_at', 'webhook_late_at'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly CollectSignal $signal,
        private readonly string $baseUrl,
    ) {
    }

    /** Ping with this value: accepted (and a collect run requested) or not. */
    public function ping(string $value, ?int $now = null): bool
    {
        $now ??= time();
        $state = $this->state();
        $valid = $value !== '' && (
            ($state['webhook_token'] !== null && hash_equals($state['webhook_token'], $value))
            || ($state['webhook_token_prev'] !== null && (int) $state['webhook_token_prev_until'] >= $now
                && hash_equals($state['webhook_token_prev'], $value))
        );
        if (! $valid) {
            return false;
        }
        $this->set('webhook_last_ping_at', gmdate('Y-m-d H:i:s', $now));
        $this->signal->request();

        return true;
    }

    /** New random value, registered at the node; only on success it becomes the current value. */
    public function rotate(MailrunnerClient $client, ?int $now = null): void
    {
        $now ??= time();
        $state = $this->state();
        $token = bin2hex(random_bytes(32));
        try {
            $client->setWebhook($this->url($token));
        } catch (RuntimeException $e) {
            $this->set('webhook_rotation_error', gmdate('Y-m-d H:i:s', $now) . ' ' . $e->getMessage());

            throw $e;
        }
        $this->set('webhook_token_prev', $state['webhook_token']);
        $this->set('webhook_token_prev_until', $state['webhook_token'] === null ? null : (string) ($now + self::GRACE_SECONDS));
        $this->set('webhook_token', $token);
        $this->set('webhook_rotated_at', gmdate('Y-m-d H:i:s', $now));
        $this->set('webhook_rotation_error', null);
    }

    /** Remove the ping at the node and forget the values. */
    public function disable(MailrunnerClient $client): void
    {
        $client->deleteWebhook();
        foreach (['webhook_token', 'webhook_token_prev', 'webhook_token_prev_until'] as $key) {
            $this->set($key, null);
        }
    }

    public function isEnabled(): bool
    {
        return $this->state()['webhook_token'] !== null;
    }

    /** Remember that a message was collected late (no ping, picked up by the timer). */
    public function markLate(?int $now = null): void
    {
        $this->set('webhook_late_at', gmdate('Y-m-d H:i:s', $now ?? time()));
    }

    /** @return array<string, ?string> */
    public function state(): array
    {
        $state = array_fill_keys(self::KEYS, null);
        foreach ($this->db->table('settings')->whereIn('name', self::KEYS)->get()->getResultArray() as $row) {
            $state[$row['name']] = $row['value'];
        }

        return $state;
    }

    private function url(string $token): string
    {
        return rtrim($this->baseUrl, '/') . '/hooks/mailrunner/' . $token;
    }

    private function set(string $key, ?string $value): void
    {
        $this->db->query('INSERT INTO ' . $this->db->prefixTable('settings') . ' (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, $value]);
    }
}
