<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Received messages (one row per message and recipient), imported by the collector.
 */
class MessageModel extends Model
{
    public const STATUSES = ['accepted', 'rejected', 'pending', 'blocked'];

    protected $table         = 'messages';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [];

    /** Columns searched by the free-text search. */
    /** Time ranges of the message list (seconds; null = all), default 48 h */
    public const RANGES = ['1h' => 3600, '4h' => 14400, '8h' => 28800, '24h' => 86400, '48h' => 172800,
        '1w' => 604800, '2w' => 1209600, '1m' => 2592000, 'all' => null];
    public const DEFAULT_RANGE = '48h';

    /** Validated range from a request parameter; null = default (kept out of URLs). */
    public static function range(mixed $value): ?string
    {
        return is_string($value) && array_key_exists($value, self::RANGES) && $value !== self::DEFAULT_RANGE ? $value : null;
    }

    /** Start of a range (UTC), null for "all". */
    public static function since(?string $range, ?int $now = null): ?string
    {
        $seconds = self::RANGES[$range ?? self::DEFAULT_RANGE] ?? null;

        return $seconds === null ? null : gmdate('Y-m-d H:i:s', ($now ?? time()) - $seconds);
    }

    private const SEARCH_COLUMNS = [
        'subject', 'envelope_sender', 'envelope_recipient', 'header_from', 'header_to',
        'client_ip', 'client_hostname', 'client_helo', 'sasl_username',
    ];

    /**
     * Apply time range, status and search filters.
     *
     * @param array{range?: string|null, status?: string|null, q?: string|null, tag?: list<string>|string|null} $filter
     */
    public function filtered(array $filter): static
    {
        if (($since = self::since($filter['range'] ?? null)) !== null) {
            $this->where('received_at >=', $since);
        }
        foreach (self::tagFilter($filter) as $tag) {
            // all selected tags must be present; "kunde:*" selects any tag of that type
            $op = str_ends_with($tag, ':*') ? 'LIKE' : '=';
            $value = $op === 'LIKE' ? str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], substr($tag, 0, -1)) . '%' : $tag;
            $this->where('EXISTS (SELECT 1 FROM message_tags mt JOIN tags t ON t.id = mt.tag_id WHERE mt.message_id = messages.id AND t.name '
                . $op . ' ' . $this->db->escape($value) . ')', null, false);
        }
        if (! empty($filter['status']) && in_array($filter['status'], self::STATUSES, true)) {
            $this->where('status', $filter['status']);
        }
        $query = trim((string) ($filter['q'] ?? ''));
        if ($query !== '') {
            $this->groupStart();
            foreach (self::SEARCH_COLUMNS as $i => $column) {
                $i === 0 ? $this->like($column, $query) : $this->orLike($column, $query);
            }
            $this->groupEnd();
        }

        return $this;
    }

    /** @return list<string> selected tag filters (validated) */
    public static function tagFilter(array $filter): array
    {
        $tags = array_filter((array) ($filter['tag'] ?? []), static fn ($t) => is_string($t) && preg_match('/^[\p{Ll}\p{Lo}0-9:_.\/*-]{1,64}$/u', $t));

        return array_values(array_unique($tags));
    }

    /**
     * Tags of the given messages.
     *
     * @param list<int|string> $ids
     *
     * @return array<int, list<array{id: int, name: string, source: string, rule_id: ?int, rule_name: ?string}>>
     */
    public function tagsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->table('message_tags mt')
            ->select('mt.message_id, t.id, t.name, mt.source, mt.rule_id, r.name AS rule_name')
            ->join('tags t', 't.id = mt.tag_id')->join('rules r', 'r.id = mt.rule_id', 'left')
            ->whereIn('mt.message_id', $ids)->orderBy('t.name')->get()->getResultArray();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['message_id']][] = $row;
        }

        return $result;
    }

    /**
     * Tag tree for the sidebar: type => [tag name => message count] (untyped tags under "").
     *
     * @return array<string, array<string, int>>
     */
    public function tagTree(?string $range = null): array
    {
        $builder = $this->db->table('message_tags mt')->select('t.name, COUNT(*) AS n')->join('tags t', 't.id = mt.tag_id');
        if (($since = self::since($range)) !== null) {
            $builder->join('messages m', 'm.id = mt.message_id')->where('m.received_at >=', $since);
        }
        $rows = $builder->groupBy('t.name')->orderBy('t.name')->get()->getResultArray();
        $tree = [];
        foreach ($rows as $row) {
            $type = str_contains($row['name'], ':') ? explode(':', $row['name'], 2)[0] : '';
            $tree[$type][$row['name']] = (int) $row['n'];
        }
        ksort($tree);

        return $tree;
    }

    /**
     * Number of messages per status in the time range, for the sidebar.
     *
     * @return array<string, int>
     */
    public function countsByStatus(?string $range = null): array
    {
        $counts  = array_fill_keys(self::STATUSES, 0);
        $builder = $this->builder()->select('status, COUNT(*) AS n');
        if (($since = self::since($range)) !== null) {
            $builder->where('received_at >=', $since);
        }
        foreach ($builder->groupBy('status')->get()->getResultArray() as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }

        return $counts;
    }
}
