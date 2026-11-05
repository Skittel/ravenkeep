<?php

namespace App\Libraries\Rules;

/**
 * Tags from the sender address (decision E-020).
 *
 * A customer can have sender domains (customer_domains). The domain stands for the customer code:
 *   server77_plesk@globex.example  is read like  server77_plesk_globex  (AddressTags::resolve)
 * The header From address is used, but only if the sender is verified: SPF and DKIM must both pass,
 * each for a domain of the same customer (exact domain, or a subdomain if the domain allows them).
 * Anyone can write any From address; this check makes sure it belongs to the checked domains.
 * The check only applies to sender addresses, never to recipient addresses.
 */
final class SenderTags
{
    /**
     * Customer code a domain belongs to.
     *
     * @param array<string, array{customer: string, subdomains: bool}> $domains customer domains
     */
    public static function customerOf(string $domain, array $domains): ?string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === '') {
            return null;
        }
        if (isset($domains[$domain])) {
            return $domains[$domain]['customer'];
        }
        $labels = explode('.', $domain);
        for ($i = 1, $n = count($labels); $i < $n - 1; $i++) {
            $parent = implode('.', array_slice($labels, $i));
            if (isset($domains[$parent]) && $domains[$parent]['subdomains']) {
                return $domains[$parent]['customer'];
            }
        }

        return null;
    }

    /**
     * Check SPF and DKIM of a message for the customer of its sender.
     *
     * @param array<string, mixed>|null                                $auth    auth_details of the message
     * @param array<string, array{customer: string, subdomains: bool}> $domains
     *
     * @return list<string> problems, e.g. ["spf:softfail", "dkim:none"]; empty = verified
     *         spf:<result> | spf:foreign (pass for a domain of someone else)
     *         dkim:none (no signature) | dkim:fail | dkim:foreign (valid signature of someone else)
     */
    public static function verify(?array $auth, string $customer, array $domains): array
    {
        $problems = [];
        $spf      = is_array($auth['spf'] ?? null) ? $auth['spf'] : [];
        $result   = strtolower((string) ($spf['result'] ?? 'none')) ?: 'none';
        if ($result !== 'pass') {
            $problems[] = 'spf:' . preg_replace('/[^a-z]/', '', $result);
        } elseif (self::customerOf((string) ($spf['domain'] ?? ''), $domains) !== $customer) {
            $problems[] = 'spf:foreign';
        }

        $signatures = is_array($auth['dkim'] ?? null) ? $auth['dkim'] : [];
        $passed     = array_filter($signatures, static fn ($s): bool => is_array($s) && strtolower((string) ($s['result'] ?? '')) === 'pass');
        $own        = array_filter($passed, static fn (array $s): bool => self::customerOf((string) ($s['domain'] ?? ''), $domains) === $customer);
        if ($own === []) {
            $problems[] = 'dkim:' . ($signatures === [] ? 'none' : ($passed === [] ? 'fail' : 'foreign'));
        }

        return $problems;
    }

    /** "server77_plesk@globex.example" + "globex" -> "server77_plesk_globex" */
    public static function schemeAddress(string $address, string $customer): string
    {
        return substr($address, 0, (int) strrpos($address, '@')) . '_' . $customer;
    }
}
