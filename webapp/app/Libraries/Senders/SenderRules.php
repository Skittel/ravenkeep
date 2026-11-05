<?php

namespace App\Libraries\Senders;

use InvalidArgumentException;

/**
 * Sender allow/block rules. Applied when a message is collected from the MailRunner receive node
 * and again when the rules change (reclassification of stored messages).
 *
 * Classification of a message received without SMTP login:
 *   block rule matches  -> blocked
 *   allow rule matches  -> accepted if the check of the rule passed (E-021), otherwise rejected
 *   no rule             -> pending
 *
 * Checks (auth_check, always aligned with the visible From domain):
 *   spf_or_dkim (default, E-002) | spf_and_dkim | spf | dkim | none (only if set explicitly)
 */
class SenderRules
{
    public const TARGETS = ['envelope', 'header'];
    public const ACTIONS = ['allow', 'block'];
    public const CHECKS  = ['spf_or_dkim', 'spf_and_dkim', 'spf', 'dkim', 'none'];
    public const DEFAULT_CHECK = 'spf_or_dkim';

    private const DOMAIN_RE = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';

    /** @param list<array{pattern: string, target: string, action: string, auth_check?: string}> $rules */
    public function __construct(private readonly array $rules)
    {
    }

    /** Normalize and validate a pattern (domain or full address). Throws on invalid input. */
    public static function normalizePattern(string $pattern): string
    {
        $pattern = strtolower(trim($pattern, " \t\n\r\0\x0B<>."));
        $domain  = $pattern;
        if (str_contains($pattern, '@')) {
            [$local, $domain] = explode('@', $pattern, 2);
            if ($local === '' || preg_match('/[\s@"]/', $local) || strlen($local) > 64) {
                throw new InvalidArgumentException('Invalid address');
            }
        }
        if (! preg_match(self::DOMAIN_RE, $domain)) {
            throw new InvalidArgumentException('Invalid domain');
        }

        return $pattern;
    }

    /** Full address: exact match. Domain: the address domain or one of its parent domains equals the pattern. */
    public static function addressMatches(?string $address, string $pattern): bool
    {
        $address = strtolower(trim((string) $address, '<> '));
        if ($address === '') {
            return false;
        }
        if (str_contains($pattern, '@')) {
            return $address === $pattern;
        }
        $domain = rtrim(str_contains($address, '@') ? substr((string) strrchr($address, '@'), 1) : $address, '.');
        $labels = explode('.', $domain);
        for ($i = 0, $n = count($labels) - 1; $i < $n; $i++) {
            if (implode('.', array_slice($labels, $i)) === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a message pass a check? $auth = auth_details (alignment flags from the receive node);
     * without them only the overall verdict of the node is known (= spf_or_dkim).
     */
    public static function passes(string $check, ?array $auth, ?string $verdict = null): bool
    {
        if ($check === 'none') {
            return true;
        }
        if (! isset($auth['alignment'])) {
            return $check === 'spf_or_dkim' && $verdict === 'accepted';
        }
        $spf  = ! empty($auth['alignment']['spf_aligned']);
        $dkim = ! empty($auth['alignment']['dkim_aligned']);

        return match ($check) {
            'spf_and_dkim' => $spf && $dkim,
            'spf'          => $spf,
            'dkim'         => $dkim,
            default        => $spf || $dkim,
        };
    }

    /** First rule of the given action that matches the selected sender, or null. */
    public function findMatch(string $action, ?string $envelopeSender, ?string $headerFrom): ?array
    {
        foreach ($this->rules as $rule) {
            if ($rule['action'] !== $action) {
                continue;
            }
            $sender = $rule['target'] === 'header' ? $headerFrom : $envelopeSender;
            if (self::addressMatches($sender, $rule['pattern'])) {
                return ['pattern' => $rule['pattern'], 'target' => $rule['target']];
            }
        }

        return null;
    }

    /**
     * Status and list match for a stored message (only messages received on the public MX).
     *
     * @return array{status: string, list_match_pattern: ?string, list_match_target: ?string}
     */
    public function classify(array $message): array
    {
        $envelope = $message['envelope_sender'] ?? '';
        $header   = $message['header_from_address'] ?? '';

        if ($match = $this->findMatch('block', $envelope, $header)) {
            $status = 'blocked';
        } elseif ($match = $this->findMatch('allow', $envelope, $header)) {
            // several allow rules may match (envelope and From): accepted if one of them passes
            $auth   = $message['auth'] ?? (isset($message['auth_details']) ? json_decode((string) $message['auth_details'], true) : null);
            $status = 'rejected';
            foreach ($this->rules as $rule) {
                $sender = $rule['target'] === 'header' ? $header : $envelope;
                if ($rule['action'] === 'allow' && self::addressMatches($sender, $rule['pattern'])
                    && self::passes($rule['auth_check'] ?? self::DEFAULT_CHECK, is_array($auth) ? $auth : null, $message['auth_verdict'] ?? null)) {
                    [$status, $match] = ['accepted', ['pattern' => $rule['pattern'], 'target' => $rule['target']]];
                    break;
                }
            }
        } else {
            $status = 'pending';
        }

        return ['status' => $status, 'list_match_pattern' => $match['pattern'] ?? null, 'list_match_target' => $match['target'] ?? null];
    }

    /**
     * Render the node config file for one action (format of /etc/ravenkeep/allowed-senders).
     */
    public function render(string $action): string
    {
        $lines = [];
        foreach ($this->rules as $rule) {
            if ($rule['action'] === $action) {
                $lines[] = sprintf('%-60s %s', $rule['pattern'], $rule['target']);
            }
        }
        sort($lines, SORT_STRING);

        return "# <domain or address> [envelope|header] – generated by the Ravenkeep web app, do not edit\n"
            . implode("\n", $lines) . ($lines === [] ? '' : "\n");
    }
}
