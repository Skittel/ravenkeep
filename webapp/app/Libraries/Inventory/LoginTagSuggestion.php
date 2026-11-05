<?php

namespace App\Libraries\Inventory;

use App\Libraries\Rules\AddressTags;

/**
 * "Capture tags" for an SMTP login (decision E-024): the user name is read once with the address
 * scheme (device_source_location_customer, E-016/E-018) and the inventory. The result is only a
 * suggestion – what the user confirms is stored as device and fixed tags of the login; nothing is
 * read from the name when messages arrive.
 */
final class LoginTagSuggestion
{
    /**
     * @param array<string, mixed> $known Inventory::addressLookup()
     *
     * @return array{device: ?string, tags: list<string>, unknown: list<array{part: string, value: string}>, customer: ?string, scheme: bool}
     *         device = path of the named device, tags = own tags of the login (without kunde/standort/geraet,
     *         which come from the device), unknown = parts missing in the inventory
     */
    public static function suggest(string $username, array $known): array
    {
        // path form of E-025: customer.device.component
        $local = strtolower(substr($username, 0, (int) strrpos($username, '@')) ?: $username);
        if (preg_match('/^([a-z0-9-]+)\.([a-z0-9-]+)\.([a-z0-9-]+)$/', $local, $m)) {
            $unknown = [];
            if (! in_array($m[1], $known['customers'] ?? [], true)) {
                $unknown[] = ['part' => AddressTags::CUSTOMER, 'value' => $m[1]];
            } elseif (! in_array($m[1] . '.' . $m[2], $known['devices'] ?? [], true)) {
                $unknown[] = ['part' => AddressTags::DEVICE, 'value' => $m[2]];
            }
            if (! in_array($m[3], $known['sources'] ?? [], true)) {
                $unknown[] = ['part' => AddressTags::SOURCE, 'value' => $m[3]];
            }

            return ['device' => $unknown === [] ? $m[1] . '.' . $m[2] : null, 'tags' => $unknown === [] ? ['source:' . $m[3]] : [],
                'unknown' => $unknown, 'customer' => $m[1], 'scheme' => true];
        }

        $split  = AddressTags::split($username);
        $result = AddressTags::resolve($username, $known);
        $tags   = array_values(array_filter($result['tags'], static fn (string $t): bool
            => ! in_array(explode(':', $t, 2)[0], Inventory::RESERVED_TAG_TYPES, true)));

        return [
            'device'   => $result['unknown'] === [] ? $result['device'] : null,
            'tags'     => $tags,
            'unknown'  => $result['unknown'],
            'customer' => $split['customer'] ?? null,
            'scheme'   => $split !== null,
        ];
    }
}
