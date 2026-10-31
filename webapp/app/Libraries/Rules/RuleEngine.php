<?php

namespace App\Libraries\Rules;

/**
 * Evaluates rules for one message (no database access – see RuleRunner).
 *
 * Rules are evaluated once, in ascending position. Each rule sees the tags set by earlier rules
 * and the manual tags of the message, so rules can build on each other:
 *   1. sender ends with @acme.example              -> kunde:acme
 *   2. sender is nas-01@acme.example        -> typ:nas
 *   3. tag kunde:acme AND tag typ:nas AND subject contains [Error] -> status:error
 * A rule with "stop" ends the evaluation when it matches.
 */
final class RuleEngine
{
    /** Message fields available in conditions => database column (null = special). */
    public const FIELDS = [
        'envelope_sender'    => 'envelope_sender',
        'header_from'        => 'header_from_address',
        'envelope_recipient' => 'envelope_recipient',
        'header_to'          => 'header_to',
        'subject'            => 'subject',
        'body'               => 'body_text',
        'sasl_username'      => 'sasl_username',
        'client_ip'          => 'client_ip',
        'client_hostname'    => 'client_hostname',
        'tag'                => null,
    ];

    public const TEXT_OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'wildcard'];
    public const TAG_OPERATORS  = ['has', 'has_not'];

    /**
     * @param list<array{id: int|string, name: string, match_mode: string, stop: int|string|bool,
     *     conditions: list<array{field: string, operator: string, value: string}>,
     *     actions: list<array{type: string, value: string}>}> $rules enabled rules, ordered by position
     */
    public function __construct(private readonly array $rules)
    {
    }

    /**
     * @param array<string, mixed> $message    message row (columns of the messages table)
     * @param list<string>         $manualTags tags set manually on the message
     *
     * @return array{tags: array<string, int>, matched: list<int>} tags set by rules (name => rule id), matched rule ids
     */
    public function evaluate(array $message, array $manualTags = []): array
    {
        $current = array_fill_keys($manualTags, true);
        $added   = [];
        $matched = [];

        foreach ($this->rules as $rule) {
            if (! $this->matches($rule, $message, array_keys($current))) {
                continue;
            }
            $matched[] = (int) $rule['id'];
            foreach ($rule['actions'] as $action) {
                if ($action['type'] === 'add_tag' && ! isset($current[$action['value']])) {
                    $current[$action['value']] = true;
                    $added[$action['value']]   = (int) $rule['id'];
                }
            }
            if (! empty($rule['stop'])) {
                break;
            }
        }

        return ['tags' => $added, 'matched' => $matched];
    }

    /** @param list<string> $tags */
    public function matches(array $rule, array $message, array $tags): bool
    {
        if ($rule['conditions'] === []) {
            return false; // a rule without conditions never matches (protects against accidental "tag everything")
        }
        $any = $rule['match_mode'] === 'any';
        foreach ($rule['conditions'] as $condition) {
            $result = self::condition($condition, $message, $tags);
            if ($any && $result) {
                return true;
            }
            if (! $any && ! $result) {
                return false;
            }
        }

        return ! $any;
    }

    /** @param list<string> $tags */
    public static function condition(array $condition, array $message, array $tags): bool
    {
        $operator = $condition['operator'];
        $expected = mb_strtolower(trim((string) $condition['value']));

        if ($condition['field'] === 'tag') {
            $has = false;
            foreach ($tags as $tag) {
                if (self::wildcard($tag, $expected)) {
                    $has = true;
                    break;
                }
            }

            return $operator === 'has_not' ? ! $has : $has;
        }

        $column = self::FIELDS[$condition['field']] ?? null;
        $actual = $column === null ? '' : mb_strtolower((string) ($message[$column] ?? ''));

        return match ($operator) {
            'equals'       => $actual === $expected,
            'not_equals'   => $actual !== $expected,
            'contains'     => $expected !== '' && str_contains($actual, $expected),
            'not_contains' => $expected === '' || ! str_contains($actual, $expected),
            'starts_with'  => $expected !== '' && str_starts_with($actual, $expected),
            'ends_with'    => $expected !== '' && str_ends_with($actual, $expected),
            'wildcard'     => self::wildcard($actual, $expected),
            default        => false,
        };
    }

    /** Whole-value match with * (any characters) and ? (one character), case-insensitive. */
    public static function wildcard(string $value, string $pattern): bool
    {
        $regex = '/^' . strtr(preg_quote($pattern, '/'), ['\*' => '.*', '\?' => '.']) . '$/su';

        return preg_match($regex, mb_strtolower($value)) === 1;
    }
}
