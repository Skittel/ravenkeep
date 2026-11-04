<?php

namespace App\Libraries\Rules;

use App\Libraries\Inventory\Inventory;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Tag management: list with usage, create, rename (rules follow), delete.
 * Tags of the reserved types kunde/standort/geraet are managed through customers, locations and
 * devices and cannot be created, renamed or deleted here.
 */
class TagService
{
    public function __construct(private readonly BaseConnection $db, private readonly RuleRunner $runner)
    {
    }

    /**
     * All tags with usage counts.
     *
     * @return list<array{id: int, name: string, type: string, reserved: bool, messages: int, devices: int, rules: int}>
     */
    public function all(): array
    {
        $tags = $this->db->query('
            SELECT t.id, t.name,
                   (SELECT COUNT(*) FROM message_tags mt WHERE mt.tag_id = t.id) AS messages,
                   (SELECT COUNT(*) FROM device_tags dt WHERE dt.tag_id = t.id) AS devices
            FROM tags t ORDER BY t.name')->getResultArray();
        $ruleUse = $this->ruleUsage();
        foreach ($tags as &$tag) {
            $tag['id']       = (int) $tag['id']; // MySQLi returns strings; the view compares strictly
            $tag['type']     = TagName::type($tag['name']);
            $tag['reserved'] = in_array($tag['type'], Inventory::RESERVED_TAG_TYPES, true);
            $tag['messages'] = (int) $tag['messages'];
            $tag['devices']  = (int) $tag['devices'];
            $tag['rules']    = (int) ($ruleUse[$tag['name']] ?? 0);
        }
        unset($tag);

        // automatic tags of customers, locations and devices, also while nothing uses them yet
        $known = array_column($tags, 'name');
        foreach ((new Inventory($this->db, $this->runner))->automaticTagNames() as $name) {
            if (! in_array($name, $known, true)) {
                $tags[] = ['id' => 0, 'name' => $name, 'type' => TagName::type($name), 'reserved' => true, 'messages' => 0, 'devices' => 0, 'rules' => 0];
            }
        }
        usort($tags, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $tags;
    }

    public function create(string $name): int
    {
        $name = $this->assertEditable(TagName::normalize($name));

        return $this->runner->tagId($name);
    }

    /** Rename a tag; rule actions and tag conditions using the old name are updated. */
    public function rename(int $id, string $newName): void
    {
        $tag     = $this->find($id);
        $this->assertEditable($tag['name']);
        $newName = $this->assertEditable(TagName::normalize($newName));
        if ($newName === $tag['name']) {
            return;
        }
        if ($this->db->table('tags')->where('name', $newName)->countAllResults() > 0) {
            throw new InvalidArgumentException('tag_exists');
        }
        $this->db->transStart();
        $this->db->table('tags')->where('id', $id)->update(['name' => $newName]);
        $this->db->table('rule_actions')->where(['type' => 'add_tag', 'value' => $tag['name']])->update(['value' => $newName]);
        $this->db->table('rule_conditions')->where(['field' => 'tag', 'value' => $tag['name']])->update(['value' => $newName]);
        $this->db->transComplete();
        $this->runner->bumpVersion();
    }

    /** Delete a tag (from messages and devices). Refused while rules still use it. */
    public function delete(int $id): void
    {
        $tag = $this->find($id);
        $this->assertEditable($tag['name']);
        if (($this->ruleUsage()[$tag['name']] ?? 0) > 0) {
            throw new InvalidArgumentException('tag_in_rules');
        }
        $this->db->table('tags')->where('id', $id)->delete(); // message_tags/device_tags cascade
        $this->runner->bumpVersion();
    }

    /** @return array<string, int> tag name => number of rules using it (action or exact condition) */
    private function ruleUsage(): array
    {
        $rows = $this->db->query("
            SELECT value AS name, COUNT(DISTINCT rule_id) AS n FROM (
                SELECT rule_id, value FROM rule_actions WHERE type = 'add_tag'
                UNION ALL SELECT rule_id, value FROM rule_conditions WHERE field = 'tag'
            ) u GROUP BY value")->getResultArray();

        return array_map('intval', array_column($rows, 'n', 'name'));
    }

    private function find(int $id): array
    {
        $tag = $this->db->table('tags')->where('id', $id)->get()->getRowArray();
        if ($tag === null) {
            throw new InvalidArgumentException('tag');
        }

        return $tag;
    }

    private function assertEditable(string $name): string
    {
        if (in_array(TagName::type($name), Inventory::RESERVED_TAG_TYPES, true)) {
            throw new InvalidArgumentException('reserved_tag');
        }

        return $name;
    }
}
