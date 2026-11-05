<?php

namespace App\Libraries\Rules;

use InvalidArgumentException;

/**
 * Automatic tags from the recipient address (decisions E-016, E-018, E-019).
 *
 * Local part = groups separated by "_", read from the right (general -> specific). The last group is
 * always the customer. Only known values are used (E-019): customers, locations and devices from the
 * inventory, sources from the existing "source:" tags. Nothing is created from a mail.
 *   2 groups: X_customer                     X = known device, else known source
 *                                            n-sight_initech -> source n-sight; nas05_initech -> device nas05
 *   3 groups: device_Y_customer              Y = known location of the customer, else known source
 *                                            nas05_hq1_acme -> location hq1; srv01_plesk_globex -> source plesk
 *   4 groups: device_source_location_customer
 *                                            gc.pm10_proxmox_2og.hq1_acme
 * Inside a group "." separates levels, again from the right. Only the first level is checked; levels
 * below a known value are free: "2og.hq1" = location hq1, level 2og; "ve.proxmox" = source proxmox,
 * subtype ve; "gc.pm10" = device pm10, sub-address gc.
 *
 * If any part is unknown the address yields no tags at all; the unknown parts are reported so the
 * address can be cleared up (create the value or map the address to a device).
 *
 * Tags of gc.pm10_proxmox_2og.hq1_acme:
 *   kunde:acme, standort:acme.hq1, standort:acme.hq1.2og, source:proxmox, geraet:acme.pm10, meldung:gc
 * The device is identified by customer + device code only (not by location or source).
 */
final class AddressTags
{
    private const LABEL = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    /** Kinds of unknown parts */
    public const CUSTOMER           = 'customer';
    public const DEVICE             = 'device';
    public const LOCATION           = 'location';
    public const SOURCE             = 'source';
    public const DEVICE_OR_SOURCE   = 'device_or_source';
    public const LOCATION_OR_SOURCE = 'location_or_source';
    public const SCHEME             = 'scheme';

    /**
     * Split an address into customer and groups (levels general -> specific), without any lookup.
     *
     * @return array{customer: string, groups: list<list<string>>}|null null if it does not follow the scheme
     */
    public static function split(string $address): ?array
    {
        $local  = strtolower(trim(str_contains($address, '@') ? substr($address, 0, (int) strrpos($address, '@')) : $address, '<> '));
        $groups = explode('_', $local);
        if (count($groups) < 2 || count($groups) > 4) {
            return null;
        }
        $levels = [];
        foreach ($groups as $group) {
            $labels = explode('.', $group);
            foreach ($labels as $label) {
                if (! preg_match(self::LABEL, $label)) {
                    return null;
                }
            }
            $levels[] = array_reverse($labels); // general -> specific
        }
        $customer = array_pop($levels);
        if (count($customer) !== 1) {
            return null;
        }

        return ['customer' => $customer[0], 'groups' => $levels];
    }

    /**
     * Read an address with the known values.
     *
     * @param array{customers?: list<string>, locations?: list<string>, devices?: list<string>, sources?: list<string>} $known
     *        customer codes, "customer.location" codes, device paths "customer.device", source values
     * @param string|null $mappedDevice device path the address is mapped to (address map): the device
     *        part counts as this device, and an address outside the scheme is resolved by the mapping alone
     *
     * @return array{tags: list<string>, device: ?string, unknown: list<array{part: string, value: string}>}
     *         device = path of the device named by the address; tags are empty if anything is unknown
     */
    public static function resolve(string $address, array $known, ?string $mappedDevice = null): array
    {
        $split = self::split($address);
        if ($split === null) {
            return ['tags' => [], 'device' => $mappedDevice, 'unknown' => $mappedDevice === null ? [['part' => self::SCHEME, 'value' => '']] : []];
        }
        $c         = $split['customer'];
        $groups    = $split['groups'];
        $customers = array_flip($known['customers'] ?? []);
        $locations = array_flip($known['locations'] ?? []);
        $devices   = array_flip($known['devices'] ?? []);
        $sources   = array_flip($known['sources'] ?? []);

        if (! isset($customers[$c])) {
            return ['tags' => [], 'device' => $mappedDevice, 'unknown' => [['part' => self::CUSTOMER, 'value' => $c]]];
        }

        $first    = array_shift($groups); // [device or source, sub...]
        $isDevice = static fn (string $code): bool => isset($devices[$c . '.' . $code]);
        $unknown  = [];
        $device   = null;
        $sub      = [];
        $source   = [];
        $location = [];

        if ($groups === []) { // X_customer
            if ($mappedDevice !== null || $isDevice($first[0])) {
                [$device, $sub] = [$first[0], array_slice($first, 1)];
            } elseif (isset($sources[$first[0]])) {
                [$source, $sub] = [[$first[0]], array_slice($first, 1)];
            } else {
                $unknown[] = ['part' => self::DEVICE_OR_SOURCE, 'value' => $first[0]];
            }
        } else {
            [$device, $sub] = [$first[0], array_slice($first, 1)];
            if ($mappedDevice === null && ! $isDevice($device)) {
                $unknown[] = ['part' => self::DEVICE, 'value' => $device];
            }
            if (count($groups) === 1) { // device_Y_customer
                $middle = $groups[0];
                if (isset($locations[$c . '.' . $middle[0]])) {
                    $location = $middle;
                } elseif (isset($sources[$middle[0]])) {
                    $source = $middle;
                } else {
                    $unknown[] = ['part' => self::LOCATION_OR_SOURCE, 'value' => $middle[0]];
                }
            } else { // device_source_location_customer
                [$source, $location] = $groups;
                if (! isset($sources[$source[0]])) {
                    $unknown[] = ['part' => self::SOURCE, 'value' => $source[0]];
                }
                if (! isset($locations[$c . '.' . $location[0]])) {
                    $unknown[] = ['part' => self::LOCATION, 'value' => $location[0]];
                }
            }
        }

        if ($unknown !== []) {
            return ['tags' => [], 'device' => $mappedDevice, 'unknown' => $unknown];
        }
        $devicePath = $mappedDevice ?? ($device !== null ? $c . '.' . $device : null);

        $tags = ['kunde:' . $c];
        foreach (self::cumulative($location) as $level) {
            $tags[] = 'standort:' . $c . '.' . $level;
        }
        foreach (self::cumulative($source) as $level) {
            $tags[] = 'source:' . $level;
        }
        if ($devicePath !== null) {
            $tags[] = 'geraet:' . $devicePath;
        }
        foreach (self::cumulative($sub) as $level) {
            $tags[] = 'meldung:' . $level;
        }

        $valid = [];
        foreach ($tags as $tag) {
            try {
                $valid[] = TagName::normalize($tag);
            } catch (InvalidArgumentException) {
                // too long (> 64 characters): leave out
            }
        }

        return ['tags' => $valid, 'device' => $devicePath, 'unknown' => []];
    }

    /** ["hb", "2og"] -> ["hb", "hb.2og"] */
    private static function cumulative(array $levels): array
    {
        $out = [];
        $acc = '';
        foreach ($levels as $level) {
            $acc   = $acc === '' ? $level : $acc . '.' . $level;
            $out[] = $acc;
        }

        return $out;
    }
}
