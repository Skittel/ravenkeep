<?php

namespace App\Libraries\Rules;

use App\Libraries\Senders\SenderRules;

/**
 * Tags from the sender address (decisions E-020, E-021).
 *
 * The meaning of a sender domain is set in its allow rule (page Senders):
 *   customer: the domain stands for the customer code
 *             server77_plesk@globex.example  is read like  server77_plesk_globex
 *   source:   the domain stands for a source tag, the local part names the device, which is looked
 *             up across all customers (device codes like server71 are expected to be unique)
 *             server71@plesk.example  ->  source:plesk + device server71 of its customer
 * The header From address is used, and only if it passes the check of that allow rule (SPF and/or
 * DKIM aligned with the From domain, or none if set explicitly). Recipient addresses are never checked.
 */
final class SenderTags
{
    /**
     * Most specific allow rule with a tag meaning that matches the sender (full address before domain,
     * longer domain before shorter).
     *
     * @param list<array{pattern: string, auth_check: string, customer: ?string, source: ?string}> $senders
     */
    public static function rule(string $from, array $senders): ?array
    {
        $best = null;
        foreach ($senders as $rule) {
            if (($rule['customer'] ?? null) === null && ($rule['source'] ?? null) === null) {
                continue;
            }
            if (SenderRules::addressMatches($from, $rule['pattern'])) {
                $rank = (str_contains($rule['pattern'], '@') ? 1000 : 0) + strlen($rule['pattern']);
                if ($best === null || $rank > $best[0]) {
                    $best = [$rank, $rule];
                }
            }
        }

        return $best[1] ?? null;
    }

    /**
     * Read a sender address with the meaning of its rule.
     *
     * @param array<string, mixed> $known        Inventory::addressLookup()
     * @param string|null          $mappedDevice device path the address is mapped to
     *
     * @return array{tags: list<string>, device: ?string, unknown: list<array{part: string, value: string}>}
     */
    public static function resolve(string $from, array $rule, array $known, ?string $mappedDevice = null): array
    {
        $local = substr($from, 0, (int) strrpos($from, '@'));
        if (($rule['customer'] ?? null) !== null) {
            return AddressTags::resolve($local . '_' . $rule['customer'], $known, $mappedDevice);
        }

        // source domain: find the customer by the device
        if ($mappedDevice !== null) {
            $customer = explode('.', $mappedDevice)[0];
        } else {
            $split = AddressTags::split($local . '_x');
            if ($split === null) {
                return ['tags' => [], 'device' => null, 'unknown' => [['part' => AddressTags::SCHEME, 'value' => '']]];
            }
            $code  = $split['groups'][0][0];
            $paths = array_values(array_filter($known['devices'] ?? [], static fn (string $p): bool => str_ends_with($p, '.' . $code)
                && substr_count($p, '.') === 1));
            if (count($paths) !== 1) {
                return ['tags' => [], 'device' => null, 'unknown' => [['part' => $paths === [] ? AddressTags::DEVICE : AddressTags::AMBIGUOUS, 'value' => $code]]];
            }
            $customer = explode('.', $paths[0])[0];
        }
        $result = AddressTags::resolve($local . '_' . $customer, $known, $mappedDevice);
        if ($result['unknown'] === []) {
            try {
                $result['tags'][] = TagName::normalize('source:' . $rule['source']);
            } catch (\InvalidArgumentException) {
                // invalid source value: leave out
            }
            $result['tags'] = array_values(array_unique($result['tags']));
        }

        return $result;
    }

    /** Is the sender trusted: does the message pass the check of the rule? */
    public static function verified(array $rule, array $message): bool
    {
        $auth = json_decode((string) ($message['auth_details'] ?? ''), true);

        return SenderRules::passes($rule['auth_check'] ?? SenderRules::DEFAULT_CHECK, is_array($auth) ? $auth : null, $message['auth_verdict'] ?? null);
    }
}
