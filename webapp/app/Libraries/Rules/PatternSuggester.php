<?php

namespace App\Libraries\Rules;

use InvalidArgumentException;

/**
 * Pattern proposals from subjects no pattern matched (decision E-029). Neutral, no knowledge of a
 * particular sender:
 *
 * 1. Every subject is split into segments at separators (", ", " - ", ": ", ";" ...) and the
 *    segments into words. Known names of several words ("Acme GmbH") count as one word.
 * 2. Subjects of one source with the same separators and the same number of words per segment form a
 *    group (the same template).
 * 3. Per word position: a value known from the inventory (code, name or alias of a customer, location
 *    or device) gives that placeholder; the same word in every subject of the group stays literal
 *    text; varying words are placeholders (neighbouring ones in a segment merged into one).
 *    Unassigned placeholders get customer, location, device in the order of the words
 *    (general -> specific, as monitoring tools write them); extra ones {*}.
 * 4. Values found at a placeholder of a large group are learned for that placeholder, and all subjects
 *    are grouped once more with them, so a template seen with only one customer still gets {kunde}.
 * 5. Groups giving the same pattern are merged; the largest come first.
 *
 * Proposals are never applied automatically: a person checks, edits and saves them.
 */
final class PatternSuggester
{
    private const SEPARATOR = '/(\s*[,;|]\s*|\s+[-–—:]\s+|:\s+)/u';

    /** a group needs this many subjects before its placeholder values are learned */
    private const LEARN_MIN = 3;

    private const FREE = '?';

    /**
     * @param list<array{source: string, subject: string}> $rows
     * @param array<string, string> $roles comparison key (SubjectPattern::key) => placeholder of a known value
     * @param list<string> $existing "source\npattern" of saved patterns (not proposed again)
     *
     * @return list<array{source: string, pattern: string, messages: int, examples: list<string>}>
     */
    public static function suggest(array $rows, array $roles, array $existing = [], int $limit = 20): array
    {
        $learned = [];
        foreach (self::analyse($rows, $roles) as $g) {
            if ($g['count'] < self::LEARN_MIN) {
                continue;
            }
            foreach ($g['slotValues'] as $role => $values) {
                foreach ($values as $v) {
                    $k = SubjectPattern::key($v);
                    if ($k !== '' && ! isset($roles[$k])) {
                        $learned[$k][$role] = true;
                    }
                }
            }
        }
        $learned = array_map(static fn (array $r): string => (string) key($r), array_filter($learned, static fn (array $r): bool => count($r) === 1));

        $out = [];
        foreach (self::analyse($rows, $roles + $learned) as $g) {
            if ($g['pattern'] === null || in_array($g['source'] . "\n" . $g['pattern'], $existing, true)) {
                continue;
            }
            $k = $g['source'] . "\n" . $g['pattern'];
            $out[$k] ??= ['source' => $g['source'], 'pattern' => $g['pattern'], 'messages' => 0, 'examples' => []];
            $out[$k]['messages'] += $g['count'];
            $out[$k]['examples'] = array_slice(array_values(array_unique([...$out[$k]['examples'], ...$g['subjects']])), 0, 3);
        }
        usort($out, static fn (array $a, array $b): int => [$b['messages'], $a['pattern']] <=> [$a['messages'], $b['pattern']]);

        return array_slice(array_values($out), 0, $limit);
    }

    /**
     * Group the subjects and derive a pattern per group.
     *
     * @return list<array{source: string, pattern: ?string, count: int, subjects: list<string>, slotValues: array<string, list<string>>}>
     */
    private static function analyse(array $rows, array $roles): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $subject = trim((string) $row['subject']);
            if ($subject === '') {
                continue;
            }
            $parts    = preg_split(self::SEPARATOR, $subject, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $segments = [];
            $seps     = [];
            foreach ($parts as $i => $p) {
                if ($i % 2 === 0) {
                    $words      = preg_split('/\s+/u', trim($p), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $segments[] = self::mergeKnown($words, $roles);
                } else {
                    $seps[] = $p;
                }
            }
            $key = $row['source'] . "\n" . implode('|', array_map(static fn (string $s): string => trim($s) === '' ? ' ' : trim($s), $seps))
                . "\n" . implode(',', array_map('count', $segments));
            $groups[$key] ??= ['source' => $row['source'], 'seps' => $seps, 'subjects' => [], 'segments' => []];
            $groups[$key]['segments'][] = $segments;
            $groups[$key]['subjects'][] = $subject;
        }

        $out = [];
        foreach ($groups as $g) {
            [$pattern, $slotValues] = self::patternOf($g['segments'], $g['seps'], $roles);
            $out[] = ['source' => $g['source'], 'pattern' => $pattern, 'count' => count($g['subjects']), 'subjects' => $g['subjects'], 'slotValues' => $slotValues];
        }

        return $out;
    }

    /** Known names of several words become one word. @return list<string> */
    private static function mergeKnown(array $words, array $roles): array
    {
        for ($i = 0; $i < count($words); $i++) {
            for ($n = min(4, count($words) - $i); $n >= 2; $n--) {
                if (isset($roles[SubjectPattern::key(implode(' ', array_slice($words, $i, $n)))])) {
                    array_splice($words, $i, $n, [implode(' ', array_slice($words, $i, $n))]);
                    break;
                }
            }
        }

        return $words;
    }

    /**
     * @param list<list<list<string>>> $subjects segments of words per subject (same shape)
     * @param list<string> $seps separators of the first subject
     *
     * @return array{0: ?string, 1: array<string, list<string>>} pattern (null if none) and the values per placeholder
     */
    private static function patternOf(array $subjects, array $seps, array $roles): array
    {
        // per segment a list of [kind, word positions]; kind = null (literal), a placeholder or FREE
        $slots = [];
        foreach ($subjects[0] as $s => $words) {
            $segment = [];
            foreach (array_keys($words) as $w) {
                $values = array_map(static fn (array $subj): string => $subj[$s][$w], $subjects);
                $known  = array_count_values(array_filter(array_map(static fn (string $v): ?string => $roles[SubjectPattern::key($v)] ?? null, $values)));
                arsort($known);
                if ($known !== [] && reset($known) * 2 >= count($values)) {
                    $kind = (string) key($known);
                } elseif (count(array_unique(array_map('mb_strtolower', $values))) === 1) {
                    $kind = null;
                } else {
                    $kind = self::FREE;
                }
                $last = array_key_last($segment);
                if ($kind === self::FREE && $last !== null && $segment[$last][0] === self::FREE) {
                    $segment[$last][1][] = $w; // neighbouring varying words: one placeholder
                } else {
                    $segment[] = [$kind, [$w]];
                }
            }
            $slots[] = $segment;
        }

        // a known role at several positions: keep the first, later ones are free placeholders
        $seen = [];
        $free = [];
        foreach ($slots as $s => $segment) {
            foreach ($segment as $n => [$kind]) {
                if ($kind !== null && $kind !== self::FREE && isset($seen[$kind])) {
                    $slots[$s][$n][0] = $kind = self::FREE;
                }
                if ($kind === self::FREE) {
                    $free[] = [$s, $n];
                } elseif ($kind !== null) {
                    $seen[$kind] = true;
                }
            }
        }
        $avail = array_values(array_diff([SubjectPattern::CUSTOMER, SubjectPattern::LOCATION, SubjectPattern::DEVICE], array_keys($seen)));
        while (count($avail) > count($free)) { // fewer free positions: location is the least important
            $drop  = in_array(SubjectPattern::LOCATION, $avail, true) && count($avail) > 1 ? SubjectPattern::LOCATION : end($avail);
            $avail = array_values(array_diff($avail, [$drop]));
        }
        foreach ($free as $i => [$s, $n]) {
            $slots[$s][$n][0] = $avail[$i] ?? SubjectPattern::ANY;
        }

        $pattern    = '';
        $slotValues = [];
        foreach ($slots as $s => $segment) {
            $texts = [];
            foreach ($segment as [$kind, $ws]) {
                if ($kind === null) {
                    $texts[] = str_replace(['{', '}'], '', implode(' ', array_map(static fn (int $w): string => $subjects[0][$s][$w], $ws)));
                } else {
                    $texts[] = '{' . $kind . '}';
                    if ($kind !== SubjectPattern::ANY) {
                        foreach ($subjects as $subj) {
                            $slotValues[$kind][] = implode(' ', array_map(static fn (int $w): string => $subj[$s][$w], $ws));
                        }
                    }
                }
            }
            $pattern .= implode(' ', $texts) . ($seps[$s] ?? '');
        }
        $slotValues = array_map(static fn (array $v): array => array_values(array_unique($v)), $slotValues);
        if (! isset($slotValues[SubjectPattern::CUSTOMER])) {
            return [null, $slotValues];
        }
        try {
            return [SubjectPattern::normalize($pattern), $slotValues];
        } catch (InvalidArgumentException) {
            return [null, $slotValues];
        }
    }
}
