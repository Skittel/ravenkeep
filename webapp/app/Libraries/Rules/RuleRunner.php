<?php

namespace App\Libraries\Rules;

use App\Libraries\MessageReader;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Applies the rules to stored messages and keeps message_tags up to date.
 *
 * Every rule change increases settings.rules_version. Messages with an older rules_version
 * (and new messages, which start with 0) are (re)evaluated in batches by the collector timer,
 * so a rule change never blocks the UI. Only accepted messages get rule tags (decision E-002);
 * for all others the rule tags are removed. Manual tags are never touched.
 */
class RuleRunner
{
    private const BODY_LIMIT = 262144; // 256 KB of text per message for conditions and search

    /** @var array<string, int> tag name => id */
    private array $tagIds = [];

    private ?RuleEngine $engine = null;

    public function __construct(private readonly BaseConnection $db, private readonly ?string $archivePath = null)
    {
    }

    public function currentVersion(): int
    {
        $row = $this->db->table('settings')->where('name', 'rules_version')->get()->getRowArray();

        return (int) ($row['value'] ?? 1);
    }

    /** Mark all messages for re-evaluation (call after every rule change). */
    public function bumpVersion(): void
    {
        $this->db->query("UPDATE settings SET value = CAST(value AS UNSIGNED) + 1 WHERE name = 'rules_version'");
        $this->engine = null;
    }

    /** Number of messages still waiting for (re-)evaluation. */
    public function backlog(): int
    {
        return $this->db->table('messages')->where('rules_version <', $this->currentVersion())->countAllResults();
    }

    /** Evaluate up to $limit outdated messages. Returns the number processed. */
    public function processBatch(int $limit = 2000): int
    {
        $version = $this->currentVersion();
        $rows    = $this->db->table('messages')->where('rules_version <', $version)->orderBy('id')->limit($limit)->get()->getResultArray();
        foreach ($rows as $message) {
            $this->apply($message, $version);
        }

        return count($rows);
    }

    /** Evaluate one message right away (e.g. after a manual tag change). */
    public function processMessage(int $id): void
    {
        $message = $this->db->table('messages')->where('id', $id)->get()->getRowArray();
        if ($message !== null) {
            $this->apply($message, $this->currentVersion());
        }
    }

    public function engine(): RuleEngine
    {
        return $this->engine ??= new RuleEngine($this->loadRules());
    }

    private function apply(array $message, int $version): void
    {
        $update = ['rules_version' => $version];
        if ($message['body_text'] === null && $this->archivePath !== null) {
            $message['body_text'] = $update['body_text'] = $this->extractBody($message['eml_path']);
        }

        $manual = $this->db->table('message_tags mt')->select('t.name')->join('tags t', 't.id = mt.tag_id')
            ->where(['mt.message_id' => $message['id'], 'mt.source' => 'manual'])->get()->getResultArray();
        $manualNames = array_column($manual, 'name');

        $tags = $message['status'] === 'accepted' ? $this->engine()->evaluate($message, $manualNames)['tags'] : [];

        $this->db->transStart();
        $this->db->table('message_tags')->where(['message_id' => $message['id'], 'source' => 'rule'])->delete();
        foreach ($tags as $name => $ruleId) {
            $this->db->table('message_tags')->insert([
                'message_id' => $message['id'], 'tag_id' => $this->tagId($name), 'source' => 'rule', 'rule_id' => $ruleId,
            ]);
        }
        $this->db->table('messages')->where('id', $message['id'])->update($update);
        $this->db->transComplete();
    }

    /** Id of a tag, created if necessary. */
    public function tagId(string $name): int
    {
        if (! isset($this->tagIds[$name])) {
            $row = $this->db->table('tags')->select('id')->where('name', $name)->get()->getRowArray();
            if ($row === null) {
                $this->db->table('tags')->insert(['name' => $name, 'created_at' => gmdate('Y-m-d H:i:s')]);
                $row = ['id' => $this->db->insertID()];
            }
            $this->tagIds[$name] = (int) $row['id'];
        }

        return $this->tagIds[$name];
    }

    /** @return list<array> enabled rules with conditions and actions, ordered by position */
    private function loadRules(): array
    {
        $rules = $this->db->table('rules')->where('enabled', 1)->orderBy('position')->orderBy('id')->get()->getResultArray();
        if ($rules === []) {
            return [];
        }
        $ids        = array_column($rules, 'id');
        $conditions = $this->db->table('rule_conditions')->whereIn('rule_id', $ids)->orderBy('id')->get()->getResultArray();
        $actions    = $this->db->table('rule_actions')->whereIn('rule_id', $ids)->orderBy('id')->get()->getResultArray();
        foreach ($rules as &$rule) {
            $rule['conditions'] = array_values(array_filter($conditions, static fn ($c) => $c['rule_id'] === $rule['id']));
            $rule['actions']    = array_values(array_filter($actions, static fn ($a) => $a['rule_id'] === $rule['id']));
        }

        return $rules;
    }

    private function extractBody(string $emlPath): string
    {
        try {
            $reader = new MessageReader($this->archivePath, $emlPath);
            $text   = $reader->textBody();
            if ($text === null && ($html = $reader->htmlBody()) !== null) {
                $text = html_entity_decode(strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/is', '', $html) ?? ''), ENT_QUOTES | ENT_HTML5);
            }

            return mb_substr($text ?? '', 0, self::BODY_LIMIT);
        } catch (Throwable) {
            return '';
        }
    }
}
