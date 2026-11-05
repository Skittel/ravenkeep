<?php

namespace App\Libraries\Mailrunner;

use RuntimeException;

/**
 * Minimal client for the MailRunner customer API (receive): https://wiki.skit.center/books/mailrunnerpub
 *
 * Only the calls Ravenkeep needs: list unacknowledged messages, fetch the original, acknowledge,
 * webhook, and the SMTP logins of the project (/v1/smtp-credentials, T-21).
 */
class MailrunnerClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 30,
    ) {
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('MailRunner is not configured (MAILRUNNER_URL / MAILRUNNER_TOKEN)');
        }
    }

    public static function fromConfig(?\Config\Mailrunner $config = null): self
    {
        $config ??= config('Mailrunner');

        return new self($config->url, $config->token, $config->timeout);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return $this->json('GET', '/v1/node/status');
    }

    /**
     * One page of unacknowledged messages with content, oldest first.
     *
     * @return array{data: list<array<string, mixed>>, next_after_id: int, has_more: bool}
     */
    public function unackedMessages(int $afterId, int $limit): array
    {
        $query = http_build_query(['acked' => '0', 'include' => 'content', 'after_id' => $afterId, 'limit' => max(1, min(100, $limit))]);

        return $this->json('GET', '/v1/messages?' . $query);
    }

    /** Original message (RFC 5322), or null if the node does not keep it (404). */
    public function raw(string $messageId): ?string
    {
        [$code, $body] = $this->request('GET', '/v1/messages/' . rawurlencode($messageId) . '/raw');
        if ($code === 404) {
            return null;
        }
        if ($code !== 200) {
            throw new RuntimeException("MailRunner raw {$messageId}: HTTP {$code} " . $this->errorText($body));
        }

        return $body;
    }

    /**
     * Acknowledge messages; returns the ids the node did not know.
     *
     * @param list<string> $messageIds
     *
     * @return list<string>
     */
    public function ack(array $messageIds): array
    {
        $result = $this->json('POST', '/v1/messages/ack', ['message_ids' => array_values($messageIds)]);

        return $result['unknown'] ?? [];
    }

    /**
     * Register the ping URL of the project (PUT /v1/webhook). The node sends an empty POST with
     * "X-MailRunner-Event: messages" after new mail, at most once per cooldown.
     *
     * @return array<string, mixed>
     */
    public function setWebhook(string $url, int $cooldownSeconds = 2): array
    {
        return $this->json('PUT', '/v1/webhook', ['url' => $url, 'cooldown_seconds' => $cooldownSeconds]);
    }

    /** @return array<string, mixed> */
    public function getWebhook(): array
    {
        return $this->json('GET', '/v1/webhook');
    }

    public function deleteWebhook(): void
    {
        $this->json('DELETE', '/v1/webhook');
    }

    /** Host name of the node (devices use it as SMTP server). */
    public function host(): string
    {
        return (string) parse_url($this->baseUrl, PHP_URL_HOST);
    }

    /**
     * Create an SMTP login with the password given by Ravenkeep (node 0.1.35+: never returned, so the
     * same login can be set up on several nodes).
     *
     * @param list<string> $allowedNetworks
     *
     * @return array<string, mixed> credential
     */
    public function createCredential(string $username, string $password, string $label, bool $plaintextPort25 = false, array $allowedNetworks = []): array
    {
        $body = ['username' => $username, 'password' => $password, 'label' => $label, 'plaintext_port25' => $plaintextPort25];
        if ($allowedNetworks !== []) {
            $body['allowed_networks'] = $allowedNetworks;
        }
        return $this->json('POST', '/v1/smtp-credentials', $body)['data'] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function credentials(): array
    {
        return $this->json('GET', '/v1/smtp-credentials')['data'] ?? [];
    }

    /** Change label, enabled, plaintext_port25 or allowed_networks. */
    public function updateCredential(string $credentialId, array $fields): void
    {
        $this->json('PATCH', '/v1/smtp-credentials/' . rawurlencode($credentialId), $fields);
    }

    /** Set a new password (given by Ravenkeep); the old one stays valid for $graceSeconds. */
    public function rotateCredential(string $credentialId, string $password, int $graceSeconds = 0): void
    {
        $this->json('POST', '/v1/smtp-credentials/' . rawurlencode($credentialId) . '/rotate',
            ['password' => $password, 'grace_seconds' => max(0, min(604800, $graceSeconds))]);
    }

    /** Delete (block) a login; an unknown one counts as deleted. */
    public function deleteCredential(string $credentialId): void
    {
        [$code, $response] = $this->request('DELETE', '/v1/smtp-credentials/' . rawurlencode($credentialId));
        if (($code < 200 || $code > 299) && $code !== 404) {
            throw new RuntimeException("MailRunner DELETE credential: HTTP {$code} " . $this->errorText($response));
        }
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, ?array $body = null): array
    {
        [$code, $response] = $this->request($method, $path, $body);
        if ($code === 204) {
            return [];
        }
        if ($code < 200 || $code > 299) {
            $error = json_decode($response, true)['error']['code'] ?? null;
            if (is_string($error) && $error !== '') {
                throw new MailrunnerApiError($error, "MailRunner {$method} {$path}: HTTP {$code} " . $this->errorText($response));
            }
            throw new RuntimeException("MailRunner {$method} {$path}: HTTP {$code} " . $this->errorText($response));
        }
        $data = json_decode($response, true);
        if (! is_array($data)) {
            throw new RuntimeException("MailRunner {$method} {$path}: answer is not JSON");
        }

        return $data;
    }

    /** @return array{0: int, 1: string} */
    protected function request(string $method, string $path, ?array $body = null): array
    {
        $headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];
        $ch      = curl_init($this->baseUrl . $path);
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
            $headers[]                   = 'Content-Type: application/json';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new RuntimeException("MailRunner {$method} {$path}: {$error}");
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$code, (string) $response];
    }

    private function errorText(string $body): string
    {
        $data = json_decode($body, true);

        return is_array($data['error'] ?? null)
            ? (($data['error']['code'] ?? '') . ' ' . ($data['error']['message'] ?? ''))
            : mb_substr($body, 0, 200);
    }
}
