<?php

namespace App\Libraries\Rules;

use InvalidArgumentException;

/**
 * Automatic tags from the recipient address (decision E-016).
 *
 * Local part = groups separated by "_", read from the right (general -> specific):
 *   2 groups: device_customer                      n-sight_skit
 *   3 groups: device_location_customer             nas05_hb_atc
 *   4 groups: device_type_location_customer        gc.pm2610_proxmox_2og.hb_atc
 * Inside a group "." separates levels, again from the right: "2og.hb" = location hb, level 2og;
 * "ve.proxmox" = type proxmox, subtype ve; "gc.pm2610" = device pm2610, sub-address gc.
 *
 * Tags of gc.pm2610_proxmox_2og.hb_atc:
 *   kunde:atc, standort:atc.hb, standort:atc.hb.2og, typ:proxmox, geraet:atc.pm2610, meldung:gc
 * The device is identified by customer + device code only (not by location or type).
 */
final class AddressTags
{
    private const LABEL = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    /**
     * @return array{customer: string, location: list<string>, type: list<string>, device: string, sub: list<string>}|null
     *         levels general -> specific; null if the address does not follow the scheme
     */
    public static function parse(string $address): ?array
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
        $deviceGroup = array_shift($levels); // [device, sub..., ] general -> specific
        $location    = count($levels) >= 1 ? array_pop($levels) : [];
        $type        = count($levels) === 1 ? array_pop($levels) : [];

        return [
            'customer' => $customer[0],
            'location' => $location,
            'type'     => $type,
            'device'   => $deviceGroup[0],
            'sub'      => array_slice($deviceGroup, 1),
        ];
    }

    /** @return list<string> tags of an address (empty if it does not follow the scheme) */
    public static function tags(string $address): array
    {
        $p = self::parse($address);
        if ($p === null) {
            return [];
        }
        $c    = $p['customer'];
        $tags = ['kunde:' . $c];
        foreach (self::cumulative($p['location']) as $level) {
            $tags[] = 'standort:' . $c . '.' . $level;
        }
        foreach (self::cumulative($p['type']) as $level) {
            $tags[] = 'typ:' . $level;
        }
        $tags[] = 'geraet:' . $c . '.' . $p['device'];
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
