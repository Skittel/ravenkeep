<?php

namespace App\Libraries\Rules;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Managed tag types – the part before ":" (decision E-023).
 *
 * New tags entered by hand (tags page, reading pane, devices, sender rules, rule actions) must have
 * a type from this list. Renaming a type renames all its tags and the rules using them; renaming onto
 * an existing type merges both (fixes a typo). A type can only be deleted while nothing uses it.
 * System types are set by Ravenkeep itself and cannot be changed.
 */
final class TagTypes
{
    public const SYSTEM = ['kunde', 'standort', 'geraet', 'source', 'meldung'];

    private ?array $names = null;

    public function __construct(private readonly BaseConnection $db, private readonly ?RuleRunner $runner = null)
    {
    }

    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));
        if ($name === '' || mb_strlen($name) > 32 || ! preg_match('/^[\p{Ll}\p{Lo}0-9][\p{Ll}\p{Lo}0-9_.\/-]*$/u', $name)) {
            throw new InvalidArgumentException('type_name');
        }

        return $name;
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->names ??= array_column($this->db->table('tag_types')->select('name')->orderBy('name')->get()->getResultArray(), 'name');
    }

    /**
     * Types with usage: number of tags and rules.
     *
     * @return list<array{id: int, name: string, note: ?string, tags: int, rules: int, system: bool}>
     */
    public function all(): array
    {
        $types = $this->db->query("
            SELECT tt.id, tt.name, tt.note,
                   (SELECT COUNT(*) FROM tags t WHERE t.name LIKE CONCAT(REPLACE(REPLACE(tt.name, '_', '\\\\_'), '%', '\\\\%'), ':%')) AS tags,
                   (SELECT COUNT(DISTINCT u.rule_id) FROM (
                       SELECT rule_id, value FROM rule_actions WHERE type = 'add_tag'
                       UNION ALL SELECT rule_id, value FROM rule_conditions WHERE field = 'tag') u
                    WHERE u.value LIKE CONCAT(REPLACE(REPLACE(tt.name, '_', '\\\\_'), '%', '\\\\%'), ':%')) AS rules
            FROM tag_types tt ORDER BY tt.name")->getResultArray();

        return array_map(static fn (array $t): array => ['id' => (int) $t['id'], 'tags' => (int) $t['tags'], 'rules' => (int) $t['rules'],
            'system' => in_array($t['name'], self::SYSTEM, true)] + $t, $types);
    }

    /** Throw unless every tag has a known type. @param list<string> $tagNames normalized */
    public function assertKnown(array $tagNames): void
    {
        foreach ($tagNames as $name) {
            if (! in_array(TagName::type($name), $this->names(), true)) {
                throw new InvalidArgumentException('tag_type');
            }
        }
    }

    public function create(string $name, ?string $note): int
    {
        $name = self::normalize($name);
        if (in_array($name, $this->names(), true)) {
            throw new InvalidArgumentException('type_exists');
        }
        $this->db->table('tag_types')->insert(['name' => $name, 'note' => $note, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->names = null;

        return (int) $this->db->insertID();
    }

    public function updateNote(int $id, ?string $note): void
    {
        $this->find($id);
        $this->db->table('tag_types')->where('id', $id)->update(['note' => $note]);
    }

    /** Rename a type with all its tags and rule references; onto an existing type: merge. */
    public function rename(int $id, string $newName): void
    {
        $type    = $this->find($id);
        $newName = self::normalize($newName);
        if ($newName === $type['name']) {
            return;
        }
        if (in_array($type['name'], self::SYSTEM, true) || in_array($newName, ['kunde', 'standort', 'geraet'], true)) {
            throw new InvalidArgumentException('type_system');
        }
        $runner = $this->runner ?? new RuleRunner($this->db);
        $merge  = in_array($newName, $this->names(), true);
        if (! $merge) {
            $this->db->table('tag_types')->where('id', $id)->update(['name' => $newName]);
            $this->names = null;
        }
        $old    = $type['name'] . ':';
        $tags   = new TagService($this->db, $runner);
        foreach ($this->db->table('tags')->select('id, name')->like('name', $old, 'after')->get()->getResultArray() as $tag) {
            $tags->rename((int) $tag['id'], $newName . ':' . substr($tag['name'], strlen($old)));
        }
        // remaining references, e.g. "type:*" conditions or tags not created yet
        foreach ([['rule_actions', 'type', 'add_tag'], ['rule_conditions', 'field', 'tag']] as [$table, $col, $val]) {
            $this->db->query("UPDATE {$table} SET value = CONCAT(?, SUBSTRING(value, ?)) WHERE {$col} = ? AND value LIKE ?",
                [$newName . ':', strlen($old) + 1, $val, str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $old) . '%']);
        }
        if ($merge) {
            $this->db->table('tag_types')->where('id', $id)->delete();
            $this->names = null;
        }
        $runner->bumpVersion();
    }

    public function delete(int $id): void
    {
        $type = $this->find($id);
        if (in_array($type['name'], self::SYSTEM, true)) {
            throw new InvalidArgumentException('type_system');
        }
        $usage = array_column($this->all(), null, 'id')[$id];
        if ($usage['tags'] > 0 || $usage['rules'] > 0) {
            throw new InvalidArgumentException('type_in_use');
        }
        $this->db->table('tag_types')->where('id', $id)->delete();
        $this->names = null;
    }

    private function find(int $id): array
    {
        return $this->db->table('tag_types')->where('id', $id)->get()->getRowArray() ?? throw new InvalidArgumentException('type');
    }
}
