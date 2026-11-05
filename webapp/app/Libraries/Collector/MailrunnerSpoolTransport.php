<?php

namespace App\Libraries\Collector;

use App\Libraries\Mailrunner\MailrunnerClient;
use App\Libraries\Senders\SenderRules;
use RuntimeException;

/**
 * Spool transport for a MailRunner receive node (decision E-014).
 *
 * The node accepts and analyses the mail (SPF, DKIM, DMARC, ...); Ravenkeep collects it through the
 * customer API and classifies it with its own sender rules, using the node's analysis:
 *   listEntries  -> GET /v1/messages?acked=0&include=content
 *   readMetadata -> MailRunner message mapped to the metadata format of the former local MX
 *   readMessage  -> GET /v1/messages/{id}/raw
 *   deleteEntry  -> POST /v1/messages/ack
 *
 * A message that is not acknowledged is offered again on the next run, so the import order of the
 * Collector (archive, database row, then ack) stays safe to interrupt.
 */
class MailrunnerSpoolTransport implements SpoolTransport
{
    /** @var array<string, array<string, mixed>> MailRunner messages of the last listEntries() call */
    private array $messages = [];

    public function __construct(
        private readonly MailrunnerClient $client,
        private readonly SenderRules $rules,
    ) {
    }

    public function listEntries(int $limit): array
    {
        $this->messages = [];
        $afterId        = 0;

        do {
            $page = $this->client->unackedMessages($afterId, $limit - count($this->messages));
            foreach ($page['data'] ?? [] as $message) {
                $this->messages[(string) $message['message_id']] = $message;
            }
            $afterId = (int) ($page['next_after_id'] ?? 0);
        } while (! empty($page['has_more']) && $afterId > 0 && count($this->messages) < $limit);

        return array_keys($this->messages);
    }

    public function readMetadata(string $id): string
    {
        return json_encode($this->metadata($this->message($id)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function readMessage(string $id): ?string
    {
        return $this->client->raw($id);
    }

    public function deleteEntry(string $id): void
    {
        if ($this->client->ack([$id]) !== []) {
            throw new RuntimeException("MailRunner does not know message {$id}");
        }
    }

    /** @return array<string, mixed> */
    private function message(string $id): array
    {
        return $this->messages[$id] ?? throw new RuntimeException("Message {$id} was not listed");
    }

    /**
     * Map a MailRunner message (with content) to the Ravenkeep metadata format.
     *
     * @return array<string, mixed>
     */
    public function metadata(array $m): array
    {
        $envelope = $m['envelope'] ?? [];
        $debug    = $m['debug'] ?? [];
        $header   = $m['header'] ?? [];
        $from     = $debug['header']['from'] ?? [];
        $check    = $this->senderCheck($debug);

        $meta = [
            'id'          => (string) $m['message_id'],
            'received_at' => $m['received_at'] ?? 'now',
            'mode'        => 'mx',
            'envelope'    => ['sender' => (string) ($envelope['mail_from'] ?? ''), 'recipient' => (string) ($envelope['rcpt'] ?? '')],
            'client'      => [
                'address'  => (string) ($envelope['client_ip'] ?? ''),
                'hostname' => (string) (($debug['fcrdns']['ptr'] ?? [])[0] ?? ''),
                'ptr'      => array_values($debug['fcrdns']['ptr'] ?? []),
                'helo'     => (string) ($envelope['helo'] ?? ''),
            ],
            'headers' => [
                'from'         => $header['from'] ?? null,
                'from_address' => count($from) === 1 ? strtolower((string) $from[0]['address']) : $this->addressOf($header['from'] ?? null),
                'to'           => $header['to'] ?? null,
                'subject'      => $header['subject'] ?? null,
                'date'         => $header['date'] ?? null,
                'message_id'   => $debug['header']['message_id'] ?? null,
            ],
            'queue_id'     => (string) ($envelope['queue_id'] ?? ''),
            'size'         => (int) ($m['size'] ?? 0),
            'auth_verdict' => $check['verdict'],
            'reasons'      => $check['reasons'],
            'auth'         => $check['auth'],
            'source'       => ['mailrunner_role' => $m['role'] ?? null, 'sha256' => $m['sha256'] ?? null],
        ];

        $class = $this->rules->classify([
            'envelope_sender'     => $meta['envelope']['sender'],
            'header_from_address' => $meta['headers']['from_address'],
            'auth_verdict'        => $meta['auth_verdict'],
            'auth'                => $meta['auth'],
        ]);
        $meta['status']     = $class['status'];
        $meta['list_match'] = $class['list_match_pattern'] === null ? null
            : ['pattern' => $class['list_match_pattern'], 'target' => $class['list_match_target']];

        return $meta;
    }

    /**
     * Ravenkeep's sender check (decision E-002) from the node's analysis: accepted if SPF or DKIM
     * passes and is aligned with the visible From domain.
     *
     * @return array{verdict: string, reasons: list<string>, auth: ?array}
     */
    private function senderCheck(array $debug): array
    {
        if (! isset($debug['spf'], $debug['dkim'])) {
            return ['verdict' => 'rejected', 'reasons' => ['no sender analysis from the MailRunner node'], 'auth' => null];
        }
        $spf   = $debug['spf'];
        $sigs  = $debug['dkim']['signatures'] ?? [];
        $dmarc = $debug['dmarc'] ?? [];
        $fromDomain = $dmarc['from_domain'] ?? null;

        $spfOk  = ($spf['result'] ?? '') === 'pass' && ($spf['aligned'] ?? null) === true;
        $dkimOk = false;
        foreach ($sigs as $sig) {
            $dkimOk = $dkimOk || (($sig['result'] ?? '') === 'pass' && ! empty($sig['aligned_with_from']));
        }

        $auth = [
            'spf'         => ['result' => $spf['result'] ?? null, 'identity' => $spf['identity'] ?? null, 'domain' => $spf['domain'] ?? null],
            'dkim'        => array_map(static fn (array $s): array => [
                'result' => $s['result'] ?? null, 'domain' => $s['domain'] ?? null,
                'selector' => $s['selector'] ?? null, 'reason' => $s['reason'] ?? '',
            ], $sigs),
            'from_domain' => $fromDomain,
            'alignment'   => [
                'spf_aligned' => $spfOk, 'dkim_aligned' => $dkimOk,
                'aspf' => $dmarc['aspf'] ?? 'r', 'adkim' => $dmarc['adkim'] ?? 'r',
                'dmarc_policy' => $dmarc['published_policy'] ?? null,
            ],
        ];

        $reasons = [];
        if ($spfOk || $dkimOk) {
            return ['verdict' => 'accepted', 'reasons' => $reasons, 'auth' => $auth];
        }
        if (! $fromDomain) {
            $reasons[] = 'From header missing or ambiguous';
        }
        $reasons[] = ($spf['result'] ?? '') === 'pass'
            ? "SPF pass for {$spf['domain']}, but not aligned with From domain {$fromDomain}"
            : 'SPF ' . ($spf['result'] ?? '?') . ' for ' . (($spf['domain'] ?? '') ?: 'empty sender');
        if ($sigs === []) {
            $reasons[] = 'no DKIM signature';
        }
        foreach ($sigs as $sig) {
            $reasons[] = ($sig['result'] ?? '') === 'pass'
                ? "DKIM pass for {$sig['domain']}, but not aligned with From domain {$fromDomain}"
                : 'DKIM ' . ($sig['result'] ?? '?') . ' (' . (($sig['domain'] ?? '') ?: '?') . '): ' . ($sig['reason'] ?? '');
        }

        return ['verdict' => 'rejected', 'reasons' => $reasons, 'auth' => $auth];
    }

    private function addressOf(?string $header): ?string
    {
        if ($header !== null && preg_match('/<([^<>@\s]+@[^<>\s]+)>|([^\s<>"]+@[^\s<>"]+)/', $header, $m)) {
            return strtolower($m[1] !== '' ? $m[1] : $m[2]);
        }

        return null;
    }
}
