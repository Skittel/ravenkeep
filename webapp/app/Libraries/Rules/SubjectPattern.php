<?php

namespace App\Libraries\Rules;

use InvalidArgumentException;
use Normalizer;

/**
 * Subject patterns (decision E-029): customer, location and device named in the subject of a report.
 *
 * A pattern is plain text with placeholders, no regular expression:
 *   Fehlerbericht - {kunde}, {standort}, {geraet}
 *   {kunde}  customer (required)        {standort}  location of that customer
 *   {geraet} device of that customer    {*}         anything, ignored
 *   {standort?} {geraet?}  optional: used if known, otherwise ignored (not reported), e.g. for a
 *                          customer whose many devices are deliberately not in the inventory
 * Text outside the placeholders must match literally (case-insensitive, any run of white space
 * matches any white space). A pattern applies to the messages of one source (source tag).
 *
 * The captured values are compared with code, name and aliases of the known customers, locations and
 * devices after normalizing (lower case, umlauts transliterated, only letters and digits), so
 * "Kopf & Steine", "kopf-steine" and "KopfSteine" are the same. As with addresses (E-019) only known
 * values yield tags; if any part is unknown the message gets none and the parts are reported.
 */
final class SubjectPattern
{
    public const CUSTOMER = 'kunde';
    public const LOCATION = 'standort';
    public const DEVICE   = 'geraet';
    public const ANY      = '*';

    public const PLACEHOLDERS = [self::CUSTOMER, self::LOCATION, self::DEVICE, self::ANY];

    /** suffix of an optional placeholder: {geraet?} */
    public const OPTIONAL = '?';

    /** part names of unknown values (as in AddressTags) */
    public const PART = [self::CUSTOMER => 'customer', self::LOCATION => 'location', self::DEVICE => 'device'];

    /** value that names several customers / locations / devices */
    public const AMBIGUOUS = 'ambiguous';

    private const MAX_LENGTH = 255;

    /** Validate and normalize a pattern; throws InvalidArgumentException('pattern'). */
    public static function normalize(string $pattern): string
    {
        $pattern = trim((string) preg_replace('/\s+/u', ' ', $pattern));
        if ($pattern === '' || mb_strlen($pattern) > self::MAX_LENGTH || ! mb_check_encoding($pattern, 'UTF-8')) {
            throw new InvalidArgumentException('pattern');
        }
        preg_match_all('/\{([^{}]*)\}/', $pattern, $m);
        $base  = array_map(static fn (string $p): string => in_array($p, [self::LOCATION . self::OPTIONAL, self::DEVICE . self::OPTIONAL], true) ? rtrim($p, self::OPTIONAL) : $p, $m[1]);
        $named = array_filter($base, static fn (string $p): bool => $p !== self::ANY);
        if (array_diff($base, self::PLACEHOLDERS) !== [] || count($named) !== count(array_unique($named))
            || ! in_array(self::CUSTOMER, $named, true) || preg_match('/[{}]/', (string) preg_replace('/\{[^{}]*\}/', '', $pattern))
            || preg_match('/\}\{/', $pattern)) { // two placeholders side by side cannot be told apart
            throw new InvalidArgumentException('pattern');
        }

        return $pattern;
    }

    /** Regular expression for a (normalized) pattern; every placeholder is a lazy named group. */
    public static function regex(string $pattern): string
    {
        $out = '';
        foreach (preg_split('/(\{[^{}]*\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (preg_match('/^\{(.*)\}$/', $part, $p)) {
                $out .= $p[1] === self::ANY ? '.*?' : '(?<' . rtrim($p[1], self::OPTIONAL) . '>.+?)';
            } else {
                $out .= implode('\s+', array_map(static fn (string $w): string => preg_quote($w, '/'), preg_split('/\s+/u', $part) ?: []));
            }
        }

        return '/^\s*' . $out . '\s*$/iu';
    }

    /**
     * Values captured by a pattern, or null if the subject does not match.
     *
     * @return array<string, string>|null placeholder => trimmed value
     */
    public static function match(string $pattern, string $subject): ?array
    {
        if (@preg_match(self::regex($pattern), $subject, $m) !== 1) {
            return null;
        }
        $values = [];
        foreach ([self::CUSTOMER, self::LOCATION, self::DEVICE] as $name) {
            if (isset($m[$name])) {
                $values[$name] = trim($m[$name]);
            }
        }

        return $values;
    }

    /** Comparison key: lower case, umlauts and accents transliterated, only a-z and 0-9. */
    public static function key(string $value): string
    {
        $value = strtr(mb_strtolower($value), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        if (class_exists(Normalizer::class)) {
            $value = (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($value, Normalizer::FORM_D));
        }

        return (string) preg_replace('/[^a-z0-9]+/', '', $value);
    }

    /** Code proposal for creating a value: lower case, transliterated, other characters become "-". */
    public static function code(string $value): string
    {
        $value = strtr(mb_strtolower($value), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        if (class_exists(Normalizer::class)) {
            $value = (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($value, Normalizer::FORM_D));
        }
        $value = trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');

        return rtrim(substr($value, 0, 32), '-');
    }

    /** Optional placeholders of a pattern ({standort?}, {geraet?}). @return list<string> */
    public static function optional(string $pattern): array
    {
        preg_match_all('/\{(' . self::LOCATION . '|' . self::DEVICE . ')\?\}/', $pattern, $m);

        return $m[1];
    }

    /**
     * Resolve captured values with the known names.
     *
     * @param array<string, string> $values placeholder => value (from match())
     * @param array{customers: array<string, list<string>>, locations: array<string, array<string, list<string>>>, devices: array<string, array<string, list<string>>>} $known
     *        customers: key => customer codes; locations: customer code => key => location codes;
     *        devices: customer code => key => device paths (owned and used devices)
     *
     * @param list<string> $optional placeholders whose value is ignored if unknown or ambiguous
     *
     * @return array{tags: list<string>, device: ?string, customer: ?string, unknown: list<array{part: string, value: string, customer: ?string}>}
     */
    public static function resolve(array $values, array $known, array $optional = []): array
    {
        $none    = ['tags' => [], 'device' => null, 'customer' => null, 'unknown' => []];
        $find    = static fn (array $map, string $value): array => array_values(array_unique($map[self::key($value)] ?? []));
        $unknown = static fn (string $placeholder, string $value, ?string $customer, bool $ambiguous): array => [
            'part' => $ambiguous ? self::AMBIGUOUS : self::PART[$placeholder], 'value' => $value, 'customer' => $customer,
        ];

        $customers = $find($known['customers'], $values[self::CUSTOMER] ?? '');
        if (count($customers) !== 1) {
            return ['unknown' => [$unknown(self::CUSTOMER, $values[self::CUSTOMER] ?? '', null, $customers !== [])]] + $none;
        }
        $c       = $customers[0];
        $tags    = ['kunde:' . $c];
        $device  = null;
        $missing = [];
        if (isset($values[self::LOCATION])) {
            $found = $find($known['locations'][$c] ?? [], $values[self::LOCATION]);
            if (count($found) === 1) {
                $tags[] = 'standort:' . $c . '.' . $found[0];
            } elseif (! in_array(self::LOCATION, $optional, true)) {
                $missing[] = $unknown(self::LOCATION, $values[self::LOCATION], $c, $found !== []);
            }
        }
        if (isset($values[self::DEVICE])) {
            $found = $find($known['devices'][$c] ?? [], $values[self::DEVICE]);
            if (count($found) === 1) {
                $tags[] = 'geraet:' . ($device = $found[0]);
            } elseif (! in_array(self::DEVICE, $optional, true)) {
                $missing[] = $unknown(self::DEVICE, $values[self::DEVICE], $c, $found !== []);
            }
        }
        if ($missing !== []) {
            return ['customer' => $c, 'unknown' => $missing] + $none;
        }

        return ['tags' => array_map([TagName::class, 'normalize'], $tags), 'device' => $device, 'customer' => $c, 'unknown' => []];
    }
}
