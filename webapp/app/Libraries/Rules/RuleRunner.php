<?php

namespace App\Libraries\Rules;

use App\Libraries\Inventory\Inventory;
use App\Libraries\MessageReader;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Applies the rules to stored messages and keeps message_tags up to date.
 *
 * Every rule change increases settings.rules_version. Messages with an older rules_version
 * (and new messages, which start with 0) are (re)evaluated in batches by the collector timer,
 * so a rule change never blocks the UI. Only accepted messages get rule, device and address tags
 * (E-002); for all others they are removed. Manual tags are never touched.
 *
 * Automatic tags are set before the rules run, so rules can use them as conditions:
 *   device  – tags of the sending device (SMTP login, or the device named by the address)
 *   address – tags from the recipient address of a domain with parse_address (E-016)
 * Fixed rules always win: if a rule sets a tag of a type (e.g. kunde:), automatic tags of that
 * type are dropped.
 */
class RuleRunner
{
    private const BODY_LIMIT = 262144; // 256 KB of text per message for conditions and search

    /** @var array<string, int> tag name => id */
    private array $tagIds = [];

    private ?RuleEngine $engine = null;

    /** @var array<string, list<string>>|null tags per SMTP login (device tags) */
    private ?array $loginTags = null;

    /** @var array<string, list<string>>|null tags per device path */
    private ?array $pathTags = null;

    /** @var array{locations: list<string>, devices: list<string>}|null inventory codes for reading addresses */
    private ?array $addressKnown = null;

    /** @var list<string>|null domains whose recipient addresses carry tags */
    private ?array $addressDomains = null;

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
        $this->engine         = null;
        $this->loginTags      = null;
        $this->pathTags       = null;
        $this->addressDomains = null;
        $this->addressKnown   = null;
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

        [$deviceTags, $addressTags] = $message['status'] === 'accepted' ? $this->automaticTags($message, $manualNames) : [[], []];

        $tags = $message['status'] === 'accepted'
            ? $this->engine()->evaluate($message, [...$manualNames, ...$deviceTags, ...$addressTags])['tags'] : [];

        // Fixed rules win over automatic tags of the same type
        $ruleTypes = array_filter(array_map([TagName::class, 'type'], array_keys($tags)));
        if ($ruleTypes !== []) {
            $keep        = static fn (string $t): bool => ! in_array(TagName::type($t), $ruleTypes, true);
            $deviceTags  = array_values(array_filter($deviceTags, $keep));
            $addressTags = array_values(array_filter($addressTags, $keep));
        }

        $this->db->transStart();
        $this->db->table('message_tags')->where('message_id', $message['id'])->whereIn('source', ['rule', 'device', 'address'])->delete();
        foreach ($deviceTags as $name) {
            $this->db->table('message_tags')->insert(['message_id' => $message['id'], 'tag_id' => $this->tagId($name), 'source' => 'device']);
        }
        foreach ($addressTags as $name) {
            $this->db->table('message_tags')->insert(['message_id' => $message['id'], 'tag_id' => $this->tagId($name), 'source' => 'address']);
        }
        foreach ($tags as $name => $ruleId) {
            if (in_array($name, $deviceTags, true) || in_array($name, $addressTags, true)) {
                continue; // already set automatically
            }
            $this->db->table('message_tags')->insert([
                'message_id' => $message['id'], 'tag_id' => $this->tagId($name), 'source' => 'rule', 'rule_id' => $ruleId,
            ]);
        }
        $this->db->table('messages')->where('id', $message['id'])->update($update);
        $this->db->transComplete();
    }

    /**
     * Device tags (SMTP login, or the device named by the recipient address) and address tags.
     *
     * @param list<string> $manualNames
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function automaticTags(array $message, array $manualNames): array
    {
        $device = [];
        if ($message['mode'] === 'auth' && ! empty($message['sasl_username'])) {
            $this->loginTags ??= (new Inventory($this->db, $this))->tagsBySmtpLogin();
            $device = $this->loginTags[strtolower($message['sasl_username'])] ?? [];
        }

        $address   = [];
        $recipient = strtolower((string) $message['envelope_recipient']);
        $domain    = substr((string) strrchr($recipient, '@'), 1);
        $this->addressDomains ??= array_column($this->db->table('local_domains')->select('domain')->where('parse_address', 1)->get()->getResultArray(), 'domain');
        if ($domain !== '' && in_array($domain, $this->addressDomains, true)) {
            $this->addressKnown ??= (new Inventory($this->db, $this))->addressLookup();
            $address = AddressTags::tags($recipient, $this->addressKnown);
            $parsed  = AddressTags::parse($recipient, $this->addressKnown);
            if ($device === [] && $parsed !== null && $parsed['device'] !== null) {
                $this->pathTags ??= (new Inventory($this->db, $this))->tagsByPath();
                $device = $this->pathTags[$parsed['customer'] . '.' . $parsed['device']] ?? [];
            }
        }

        $device  = array_values(array_diff(array_unique($device), $manualNames));
        $address = array_values(array_diff(array_unique($address), $manualNames, $device));

        return [$device, $address];
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
