<?php

namespace App\Libraries\Inventory;

use App\Libraries\Rules\PatternSuggester;
use App\Libraries\Rules\RuleRunner;
use App\Libraries\Rules\SubjectPattern;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Subject patterns, aliases, unknown values named by subjects and pattern proposals (decision E-029).
 */
class PatternService
{
    public const ALIAS_KINDS = ['customer', 'location', 'device'];

    private const ALIAS_MAX = 128;

    /** messages read for proposals (newest first) */
    private const SUGGEST_ROWS = 5000;

    public function __construct(private readonly BaseConnection $db, private readonly RuleRunner $runner)
    {
    }

    // ---- patterns ----

    /** @return array<string, list<array{id: int, pattern: string}>> source => patterns (oldest first) */
    public function patternsBySource(): array
    {
        $out = [];
        foreach ($this->db->table('subject_patterns')->select('id, source, pattern')->orderBy('id')->get()->getResultArray() as $p) {
            $out[$p['source']][] = ['id' => (int) $p['id'], 'pattern' => $p['pattern']];
        }

        return $out;
    }

    /** @return list<array{id: int, source: string, pattern: string, messages: int, unknown: int}> */
    public function patterns(): array
    {
        $counts = [];
        foreach ($this->db->query('SELECT subject_pattern_id AS id, COUNT(*) AS n, SUM(subject_unknown IS NOT NULL) AS u
                FROM messages WHERE subject_pattern_id IS NOT NULL GROUP BY subject_pattern_id')->getResultArray() as $r) {
            $counts[(int) $r['id']] = $r;
        }

        return array_map(static fn (array $p): array => [
            'id'       => (int) $p['id'],
            'source'   => $p['source'],
            'pattern'  => $p['pattern'],
            'messages' => (int) ($counts[(int) $p['id']]['n'] ?? 0),
            'unknown'  => (int) ($counts[(int) $p['id']]['u'] ?? 0),
        ], $this->db->table('subject_patterns')->orderBy('source')->orderBy('id')->get()->getResultArray());
    }

    /** Known source values (first level), for the pattern form. @return list<string> */
    public function sources(): array
    {
        $sources = array_map(static fn (string $s): string => explode('.', $s)[0], (new Inventory($this->db, $this->runner))->addressLookup()['sources']);
        $sources = array_values(array_unique($sources));
        sort($sources);

        return $sources;
    }

    public function addPattern(string $source, string $pattern): int
    {
        $source = strtolower(trim($source));
        if (! in_array($source, $this->sources(), true)) {
            throw new InvalidArgumentException('source');
        }
        $pattern = SubjectPattern::normalize($pattern);
        if ($this->db->table('subject_patterns')->where(['source' => $source, 'pattern' => $pattern])->countAllResults() > 0) {
            throw new InvalidArgumentException('pattern_exists');
        }
        $this->db->table('subject_patterns')->insert(['source' => $source, 'pattern' => $pattern, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $id = (int) $this->db->insertID();
        $this->runner->bumpVersion();

        return $id;
    }

    public function deletePattern(int $id): void
    {
        $this->db->table('subject_patterns')->where('id', $id)->delete();
        $this->db->table('messages')->where('subject_pattern_id', $id)->update(['subject_pattern_id' => null, 'subject_unknown' => null]);
        $this->runner->bumpVersion();
    }

    // ---- aliases ----

    /** @return list<array{id: int, kind: string, alias: string, customer_id: int, customer: string, target: string}> */
    public function aliases(): array
    {
        return array_map(static fn (array $a): array => [
            'id'          => (int) $a['id'],
            'kind'        => $a['kind'],
            'alias'       => $a['alias'],
            'customer_id' => (int) $a['customer_id'],
            'customer'    => $a['customer_name'],
            'target'      => match ($a['kind']) {
                'location' => $a['location_name'] . ' (' . $a['customer_code'] . '.' . $a['location_code'] . ')',
                'device'   => $a['device_name'] . ' (' . $a['device_path'] . ')',
                default    => $a['customer_name'] . ' (' . $a['customer_code'] . ')',
            },
        ], $this->db->table('aliases a')
            ->select('a.id, a.kind, a.alias, a.customer_id, c.name AS customer_name, c.code AS customer_code, l.name AS location_name, l.code AS location_code, d.name AS device_name, d.path AS device_path')
            ->join('customers c', 'c.id = a.customer_id')->join('locations l', 'l.id = a.location_id', 'left')->join('devices d', 'd.id = a.device_id', 'left')
            ->orderBy('c.name')->orderBy('a.kind')->orderBy('a.alias')->get()->getResultArray());
    }

    /** Add an alias for a customer, location or device ($targetId = id of that customer / location / device). */
    public function addAlias(string $kind, int $targetId, string $alias): int
    {
        $alias = trim((string) preg_replace('/\s+/u', ' ', $alias));
        $key   = SubjectPattern::key($alias);
        if ($key === '' || mb_strlen($alias) > self::ALIAS_MAX) {
            throw new InvalidArgumentException('alias');
        }
        $row = match ($kind) {
            'customer' => $this->db->table('customers')->select('id AS customer_id, NULL AS location_id, NULL AS device_id', false)->where('id', $targetId)->get()->getRowArray(),
            'location' => $this->db->table('locations')->select('customer_id, id AS location_id, NULL AS device_id', false)->where('id', $targetId)->get()->getRowArray(),
            'device'   => $this->db->table('devices')->select('customer_id, NULL AS location_id, id AS device_id', false)->where('id', $targetId)->get()->getRowArray(),
            default    => throw new InvalidArgumentException('kind'),
        };
        if ($row === null) {
            throw new InvalidArgumentException($kind);
        }
        if ($this->db->table('aliases')->where(['kind' => $kind, 'customer_id' => $row['customer_id'], 'alias_key' => $key])->countAllResults() > 0) {
            throw new InvalidArgumentException('alias_exists');
        }
        $this->db->table('aliases')->insert(['kind' => $kind, 'alias' => $alias, 'alias_key' => $key, 'created_at' => gmdate('Y-m-d H:i:s')] + $row);
        $id = (int) $this->db->insertID();
        $this->runner->bumpVersion();

        return $id;
    }

    public function deleteAlias(int $id): void
    {
        $this->db->table('aliases')->where('id', $id)->delete();
        $this->runner->bumpVersion();
    }

    // ---- lookup ----

    /**
     * Known names for SubjectPattern::resolve: code, name and aliases per customer, location and device.
     *
     * @return array{customers: array<string, list<string>>, locations: array<string, array<string, list<string>>>, devices: array<string, array<string, list<string>>>}
     */
    public function lookup(): array
    {
        $known   = ['customers' => [], 'locations' => [], 'devices' => []];
        $add     = static function (array &$map, array $values, string $target): void {
            foreach ($values as $v) {
                if (($k = SubjectPattern::key((string) $v)) !== '' && ! in_array($target, $map[$k] ?? [], true)) {
                    $map[$k][] = $target;
                }
            }
        };
        $aliases = [];
        foreach ($this->db->table('aliases')->select('kind, customer_id, location_id, device_id, alias')->get()->getResultArray() as $a) {
            $aliases[$a['kind']][(int) ($a['device_id'] ?? $a['location_id'] ?? $a['customer_id'])][] = $a['alias'];
        }
        $codes = [];
        foreach ($this->db->table('customers')->select('id, code, name')->get()->getResultArray() as $c) {
            $codes[(int) $c['id']] = $c['code'];
            $add($known['customers'], [$c['code'], $c['name'], ...($aliases['customer'][(int) $c['id']] ?? [])], $c['code']);
        }
        foreach ($this->db->table('locations')->select('id, customer_id, code, name')->get()->getResultArray() as $l) {
            $c = $codes[(int) $l['customer_id']];
            $known['locations'][$c] ??= [];
            $add($known['locations'][$c], [$l['code'], $l['name'], ...($aliases['location'][(int) $l['id']] ?? [])], $l['code']);
        }
        $users = [];
        foreach ($this->db->table('device_customers')->select('device_id, customer_id')->get()->getResultArray() as $u) {
            $users[(int) $u['device_id']][] = $codes[(int) $u['customer_id']];
        }
        foreach ($this->db->table('devices')->select('id, customer_id, code, name, path')->get()->getResultArray() as $d) {
            foreach ([$codes[(int) $d['customer_id']], ...($users[(int) $d['id']] ?? [])] as $c) { // owner and users (E-027)
                $known['devices'][$c] ??= [];
                $add($known['devices'][$c], [$d['code'], $d['name'], ...($aliases['device'][(int) $d['id']] ?? [])], $d['path']);
            }
        }

        return $known;
    }

    /** Comparison key => placeholder of all known names (for proposals). @return array<string, string> */
    public static function roles(array $known): array
    {
        $roles = [];
        foreach ($known['devices'] as $map) {
            $roles += array_fill_keys(array_keys($map), SubjectPattern::DEVICE);
        }
        foreach ($known['locations'] as $map) {
            $roles += array_fill_keys(array_keys($map), SubjectPattern::LOCATION);
        }

        return array_fill_keys(array_keys($known['customers']), SubjectPattern::CUSTOMER) + $roles;
    }

    /**
     * Try the patterns of a source on a subject (or only $pattern, if given).
     *
     * @return array{pattern: string, pattern_id: ?int, values: array<string, string>, result: array}|null
     */
    public function test(string $source, string $subject, ?string $pattern = null): ?array
    {
        $patterns = $pattern !== null ? [['id' => null, 'pattern' => SubjectPattern::normalize($pattern)]]
            : ($this->patternsBySource()[strtolower(trim($source))] ?? []);
        foreach ($patterns as $p) {
            $values = SubjectPattern::match($p['pattern'], $subject);
            if ($values !== null) {
                return ['pattern' => $p['pattern'], 'pattern_id' => $p['id'], 'values' => $values, 'result' => SubjectPattern::resolve($values, $this->lookup(), SubjectPattern::optional($p['pattern']))];
            }
        }

        return null;
    }

    // ---- unknown values ----

    /**
     * Values named by subjects that are not known, per part, customer and value.
     *
     * @return list<array{part: string, value: string, customer: ?string, customer_id: ?int, messages: int, last: string, last_id: int, sources: list<string>}>
     */
    public function unknown(): array
    {
        $rows = $this->db->query('SELECT m.subject_unknown AS u, p.source, COUNT(*) AS n, MAX(m.received_at) AS last, MAX(m.id) AS last_id
            FROM messages m JOIN subject_patterns p ON p.id = m.subject_pattern_id
            WHERE m.subject_unknown IS NOT NULL GROUP BY m.subject_unknown, p.source')->getResultArray();
        $ids = array_column($this->db->table('customers')->select('id, code')->get()->getResultArray(), 'id', 'code');
        $out = [];
        foreach ($rows as $r) {
            foreach (json_decode((string) $r['u'], true) ?: [] as $p) {
                $k = $p['part'] . "\n" . ($p['customer'] ?? '') . "\n" . SubjectPattern::key((string) $p['value']);
                $out[$k] ??= ['part' => $p['part'], 'value' => (string) $p['value'], 'customer' => $p['customer'] ?? null,
                    'customer_id' => isset($p['customer'], $ids[$p['customer']]) ? (int) $ids[$p['customer']] : null,
                    'messages' => 0, 'last' => $r['last'], 'last_id' => (int) $r['last_id'], 'sources' => []];
                $out[$k]['messages'] += (int) $r['n'];
                if ($r['last_id'] > $out[$k]['last_id']) {
                    [$out[$k]['last'], $out[$k]['last_id']] = [$r['last'], (int) $r['last_id']];
                }
                $out[$k]['sources'] = array_values(array_unique([...$out[$k]['sources'], $r['source']]));
            }
        }
        usort($out, static fn (array $a, array $b): int => [$b['messages'], $a['value']] <=> [$a['messages'], $b['value']]);

        return $out;
    }

    public function countUnknown(): int
    {
        return count($this->unknown());
    }

    /**
     * Create a customer, location or device named by a subject. Location and device belong to the
     * customer $customerId; the name is the value as written in the subject.
     */
    public function create(string $part, ?int $customerId, string $code, string $name): void
    {
        $inventory = new Inventory($this->db, $this->runner);
        match ($part) {
            'customer' => $inventory->createCustomer($code, $name, null),
            'location' => $inventory->createLocation($customerId ?? throw new InvalidArgumentException('customer'), $code, $name, null),
            'device'   => $inventory->createDevice($customerId ?? throw new InvalidArgumentException('customer'), null, $code, $name, null, []),
            default    => throw new InvalidArgumentException('kind'),
        };
    }

    // ---- proposals ----

    /**
     * Pattern proposals from accepted messages with a source and without device that no pattern matched.
     *
     * @return list<array{source: string, pattern: string, messages: int, examples: list<string>}>
     */
    public function suggestions(int $limit = 20): array
    {
        $rows = $this->db->query("SELECT DISTINCT m.id, m.subject, SUBSTRING_INDEX(SUBSTRING(t.name, 8), '.', 1) AS source
            FROM messages m JOIN message_tags mt ON mt.message_id = m.id JOIN tags t ON t.id = mt.tag_id AND t.name LIKE 'source:%'
            WHERE m.status = 'accepted' AND m.subject_pattern_id IS NULL AND m.subject IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM message_tags g JOIN tags gt ON gt.id = g.tag_id AND gt.name LIKE 'geraet:%' WHERE g.message_id = m.id)
            ORDER BY m.id DESC LIMIT " . self::SUGGEST_ROWS)->getResultArray();
        $existing = array_map(static fn (array $p): string => $p['source'] . "\n" . $p['pattern'],
            $this->db->table('subject_patterns')->select('source, pattern')->get()->getResultArray());

        return PatternSuggester::suggest($rows, self::roles($this->lookup()), $existing, $limit);
    }
}
