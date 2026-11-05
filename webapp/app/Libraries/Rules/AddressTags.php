<?php

namespace App\Libraries\Rules;

use InvalidArgumentException;

/**
 * Automatic tags from the recipient address (decisions E-016, E-018).
 *
 * Local part = groups separated by "_", read from the right (general -> specific). The last group is
 * always the customer. Which meaning the other groups have is decided with the inventory:
 *   2 groups: X_customer          X is a device if the customer has a device with that code, else a source
 *                                 n-sight_initech -> source n-sight; nas05_initech -> device nas05 (if in inventory)
 *   3 groups: device_Y_customer   Y is a location if the customer has a location with that code, else a source
 *                                 nas05_hq1_acme -> location hq1; srv01_plesk_globex -> source plesk
 *   4 groups: device_source_location_customer (positions are unambiguous, unknown locations stay locations)
 *                                 gc.pm10_proxmox_2og.hq1_acme
 * Inside a group "." separates levels, again from the right: "2og.hq1" = location hq1, level 2og
 * (only hq1 is looked up); "ve.proxmox" = source proxmox, subtype ve; "gc.pm10" = device pm10,
 * sub-address gc (also "gc.n-sight" = source n-sight, sub-address gc).
 *
 * Tags of gc.pm10_proxmox_2og.hq1_acme:
 *   kunde:acme, standort:acme.hq1, standort:acme.hq1.2og, source:proxmox, geraet:acme.pm10, meldung:gc
 * The device is identified by customer + device code only (not by location or source).
 */
final class AddressTags
{
    private const LABEL = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    /**
     * @param array{locations?: list<string>, devices?: list<string>} $known inventory: "customer.location"
     *        codes and device paths "customer.device"
     *
     * @return array{customer: string, location: list<string>, source: list<string>, device: ?string, sub: list<string>}|null
     *         levels general -> specific; null if the address does not follow the scheme
     */
    public static function parse(string $address, array $known = []): ?array
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
        $c         = $customer[0];
        $locations = array_flip($known['locations'] ?? []);
        $devices   = array_flip($known['devices'] ?? []);

        $first  = array_shift($levels); // [device or source, sub...]
        $result = ['customer' => $c, 'location' => [], 'source' => [], 'device' => $first[0], 'sub' => array_slice($first, 1)];

        if ($levels === [] && ! isset($devices[$c . '.' . $first[0]])) {
            $result['device'] = null;
            $result['source'] = [$first[0]];
        } elseif (count($levels) === 1) {
            $middle = $levels[0];
            if (isset($locations[$c . '.' . $middle[0]])) {
                $result['location'] = $middle;
            } else {
                $result['source'] = $middle;
            }
        } elseif (count($levels) === 2) {
            $result['source']   = $levels[0];
            $result['location'] = $levels[1];
        }

        return $result;
    }

    /**
     * @param array{locations?: list<string>, devices?: list<string>} $known see parse()
     *
     * @return list<string> tags of an address (empty if it does not follow the scheme)
     */
    public static function tags(string $address, array $known = []): array
    {
        $p = self::parse($address, $known);
        if ($p === null) {
            return [];
        }
        $c    = $p['customer'];
        $tags = ['kunde:' . $c];
        foreach (self::cumulative($p['location']) as $level) {
            $tags[] = 'standort:' . $c . '.' . $level;
        }
        foreach (self::cumulative($p['source']) as $level) {
            $tags[] = 'source:' . $level;
        }
        if ($p['device'] !== null) {
            $tags[] = 'geraet:' . $c . '.' . $p['device'];
        }
        foreach (self::cumulative($p['sub']) as $level) {
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

        return $valid;
    }

    /** ["hq", "2og"] -> ["hq", "hq.2og"] */
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
