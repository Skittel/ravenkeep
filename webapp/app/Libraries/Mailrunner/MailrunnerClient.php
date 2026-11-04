<?php

namespace App\Libraries\Mailrunner;

use RuntimeException;

/**
 * Minimal client for the MailRunner customer API (receive): https://wiki.skit.center/books/mailrunnerpub
 *
 * Only the calls Ravenkeep needs: list unacknowledged messages, fetch the original, acknowledge.
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

    /** @return array<string, mixed> */
    private function json(string $method, string $path, ?array $body = null): array
    {
        [$code, $response] = $this->request($method, $path, $body);
        if ($code !== 200) {
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
