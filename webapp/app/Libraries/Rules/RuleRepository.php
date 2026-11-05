<?php

namespace App\Libraries\Rules;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Create, update, reorder and delete rules. Every change bumps the rules version so the
 * collector re-evaluates all messages in the background.
 */
class RuleRepository
{
    public function __construct(private readonly BaseConnection $db, private readonly RuleRunner $runner)
    {
    }

    /** @return list<array> all rules (enabled or not) with conditions and actions, ordered by position */
    public function all(): array
    {
        $rules      = $this->db->table('rules')->orderBy('position')->orderBy('id')->get()->getResultArray();
        $conditions = $this->db->table('rule_conditions')->orderBy('id')->get()->getResultArray();
        $actions    = $this->db->table('rule_actions')->orderBy('id')->get()->getResultArray();
        foreach ($rules as &$rule) {
            $rule['conditions'] = array_values(array_filter($conditions, static fn ($c) => $c['rule_id'] === $rule['id']));
            $rule['actions']    = array_values(array_filter($actions, static fn ($a) => $a['rule_id'] === $rule['id']));
        }

        return $rules;
    }

    public function find(int $id): ?array
    {
        foreach ($this->all() as $rule) {
            if ((int) $rule['id'] === $id) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Validate and save a rule (insert if $id is null). Returns the rule id.
     *
     * @param list<array{field: string, operator: string, value: string}> $conditions
     * @param list<string>                                                 $tags       tags to add
     */
    public function save(?int $id, string $name, string $matchMode, bool $stop, bool $enabled, array $conditions, array $tags): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 128) {
            throw new InvalidArgumentException('name');
        }
        $matchMode  = $matchMode === 'any' ? 'any' : 'all';
        $conditions = $this->validConditions($conditions);
        if ($conditions === []) {
            throw new InvalidArgumentException('conditions');
        }
        $tags = array_values(array_unique(array_map([TagName::class, 'normalize'], array_filter(array_map('trim', $tags), 'strlen'))));
        if ($tags === []) {
            throw new InvalidArgumentException('tags');
        }
        (new TagTypes($this->db))->assertKnown($tags);

        $now  = gmdate('Y-m-d H:i:s');
        $data = ['name' => $name, 'match_mode' => $matchMode, 'stop' => (int) $stop, 'enabled' => (int) $enabled, 'updated_at' => $now];

        $this->db->transStart();
        if ($id === null) {
            $max = $this->db->table('rules')->selectMax('position')->get()->getRowArray();
            $this->db->table('rules')->insert($data + ['position' => ((int) ($max['position'] ?? 0)) + 10, 'created_at' => $now]);
            $id = (int) $this->db->insertID();
        } else {
            $this->db->table('rules')->where('id', $id)->update($data);
            $this->db->table('rule_conditions')->where('rule_id', $id)->delete();
            $this->db->table('rule_actions')->where('rule_id', $id)->delete();
        }
        foreach ($conditions as $condition) {
            $this->db->table('rule_conditions')->insert($condition + ['rule_id' => $id]);
        }
        foreach ($tags as $tag) {
            $this->db->table('rule_actions')->insert(['rule_id' => $id, 'type' => 'add_tag', 'value' => $tag]);
        }
        $this->db->transComplete();
        $this->runner->bumpVersion();

        return $id;
    }

    public function delete(int $id): void
    {
        $this->db->table('rules')->where('id', $id)->delete();
        $this->runner->bumpVersion();
    }

    public function toggle(int $id): void
    {
        $this->db->query('UPDATE rules SET enabled = 1 - enabled WHERE id = ?', [$id]);
        $this->runner->bumpVersion();
    }

    /** Move a rule one place up (-1) or down (+1) and renumber all positions. */
    public function move(int $id, int $direction): void
    {
        $ids = array_map('intval', array_column($this->db->table('rules')->select('id')->orderBy('position')->orderBy('id')->get()->getResultArray(), 'id'));
        $pos = array_search($id, $ids, true);
        $new = $pos === false ? false : $pos + ($direction < 0 ? -1 : 1);
        if ($new === false || $new < 0 || $new >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$new]] = [$ids[$new], $ids[$pos]];
        foreach ($ids as $i => $ruleId) {
            $this->db->table('rules')->where('id', $ruleId)->update(['position' => ($i + 1) * 10]);
        }
        $this->runner->bumpVersion();
    }

    /**
     * How many of the latest accepted messages a rule would match (using their current tags).
     */
    public function preview(array $rule, int $sample = 1000): array
    {
        $messages = $this->db->table('messages')->where('status', 'accepted')->orderBy('id', 'DESC')->limit($sample)->get()->getResultArray();
        if ($messages === []) {
            return ['matched' => 0, 'sample' => 0];
        }
        $tagRows = $this->db->table('message_tags mt')->select('mt.message_id, t.name')->join('tags t', 't.id = mt.tag_id')
            ->whereIn('mt.message_id', array_column($messages, 'id'))->get()->getResultArray();
        $tags = [];
        foreach ($tagRows as $row) {
            $tags[$row['message_id']][] = $row['name'];
        }
        $engine  = new RuleEngine([]);
        $matched = 0;
        foreach ($messages as $message) {
            $matched += $engine->matches($rule, $message, $tags[$message['id']] ?? []) ? 1 : 0;
        }

        return ['matched' => $matched, 'sample' => count($messages)];
    }

    /** @return list<array{field: string, operator: string, value: string}> */
    private function validConditions(array $conditions): array
    {
        $valid = [];
        foreach ($conditions as $condition) {
            $field    = (string) ($condition['field'] ?? '');
            $operator = (string) ($condition['operator'] ?? '');
            $value    = trim((string) ($condition['value'] ?? ''));
            if (! array_key_exists($field, RuleEngine::FIELDS) || mb_strlen($value) > 255) {
                continue;
            }
            $allowed = $field === 'tag' ? RuleEngine::TAG_OPERATORS : RuleEngine::TEXT_OPERATORS;
            if (! in_array($operator, $allowed, true) || ($value === '' && ! in_array($operator, ['equals', 'not_equals'], true))) {
                continue;
            }
            $valid[] = ['field' => $field, 'operator' => $operator, 'value' => $field === 'tag' ? mb_strtolower($value) : $value];
        }

        return $valid;
    }
}
