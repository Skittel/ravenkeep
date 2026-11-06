<?php

namespace App\Libraries\Rules;

use App\Libraries\Inventory\Inventory;
use App\Libraries\MessageReader;
use App\Libraries\Senders\SenderRules;
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
 *   address – tags from the recipient address of a domain with parse_address (E-016) and from the
 *             verified sender address of a domain with a tag meaning (E-020, E-021)
 *   sender  – fixed tags of the matching sender allow rules (E-022)
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

    /** @var array<string, mixed>|null known values for reading addresses (Inventory::addressLookup) */
    private ?array $addressKnown = null;

    /** @var list<array{pattern: string, target: string, auth_check: string, tags: list<string>}>|null allow rules with fixed tags (E-022) */
    private ?array $senderRuleTags = null;

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
        $this->senderRuleTags = null;
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

        $auto = $message['status'] === 'accepted' ? $this->automaticTags($message, $manualNames)
            : ['device' => [], 'address' => [], 'sender' => [], 'unknown' => null, 'sender_unknown' => null, 'sender_unverified' => null];
        [$deviceTags, $addressTags, $senderTags] = [$auto['device'], $auto['address'], $auto['sender']];
        $update['address_unknown']   = $auto['unknown'] === null ? null : json_encode($auto['unknown'], JSON_THROW_ON_ERROR);
        $update['sender_unknown']    = $auto['sender_unknown'] === null ? null : json_encode($auto['sender_unknown'], JSON_THROW_ON_ERROR);
        $update['sender_unverified'] = $auto['sender_unverified'];

        $tags = $message['status'] === 'accepted'
            ? $this->engine()->evaluate($message, $manualNames, [...$deviceTags, ...$addressTags, ...$senderTags])['tags'] : [];

        // Fixed rules win over automatic tags of the same type
        $ruleTypes = array_filter(array_map([TagName::class, 'type'], array_keys($tags)));
        if ($ruleTypes !== []) {
            $keep        = static fn (string $t): bool => ! in_array(TagName::type($t), $ruleTypes, true);
            $deviceTags  = array_values(array_filter($deviceTags, $keep));
            $addressTags = array_values(array_filter($addressTags, $keep));
            $senderTags  = array_values(array_filter($senderTags, $keep));
        }

        $this->db->transStart();
        $this->db->table('message_tags')->where('message_id', $message['id'])->whereIn('source', ['rule', 'device', 'address', 'sender'])->delete();
        foreach ($deviceTags as $name) {
            $this->db->table('message_tags')->insert(['message_id' => $message['id'], 'tag_id' => $this->tagId($name), 'source' => 'device']);
        }
        foreach ($addressTags as $name) {
            $this->db->table('message_tags')->insert(['message_id' => $message['id'], 'tag_id' => $this->tagId($name), 'source' => 'address']);
        }
        foreach ($senderTags as $name) {
            $this->db->table('message_tags')->insert(['message_id' => $message['id'], 'tag_id' => $this->tagId($name), 'source' => 'sender']);
        }
        foreach ($tags as $name => $ruleId) {
            if (in_array($name, $deviceTags, true) || in_array($name, $addressTags, true) || in_array($name, $senderTags, true)) {
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
     * Device tags (SMTP login, or the device named or mapped by the recipient or sender address) and
     * address tags. Address tags only use known values (E-019); unknown parts are returned for the
     * clearing list. Recipient (E-016) and verified sender (E-020) complement each other; if both are
     * resolved but name a different customer or device, neither is used.
     *
     * @param list<string> $manualNames
     *
     * Plus the fixed tags of the allow rules the sender matches and passes (E-022).
     *
     * @return array{device: list<string>, address: list<string>, sender: list<string>, unknown: ?list<array>, sender_unknown: ?list<array>, sender_unverified: ?string}
     */
    private function automaticTags(array $message, array $manualNames): array
    {
        $out    = ['device' => [], 'address' => [], 'sender' => [], 'unknown' => null, 'sender_unknown' => null, 'sender_unverified' => null];
        $device = [];
        $login  = false;
        if ($message['mode'] === 'auth' && ! empty($message['sasl_username'])) {
            $this->loginTags ??= (new Inventory($this->db, $this))->tagsBySmtpLogin();
            $login  = array_key_exists(strtolower($message['sasl_username']), $this->loginTags);
            $device = $this->loginTags[strtolower($message['sasl_username'])] ?? [];
        }

        if (! $login && $device === []) { // a known SMTP login always wins: its fixed tags, no addresses read (E-024)
            $this->addressKnown ??= (new Inventory($this->db, $this))->addressLookup();
            $recipient = $this->recipientResult($message);
            $sender    = $this->senderResult($message, $out);
            if ($recipient !== null && $recipient['unknown'] !== []) {
                $out['unknown'] = $recipient['unknown'];
            }
            if ($sender !== null && $sender['unknown'] !== []) {
                $out['sender_unknown'] = $sender['unknown'];
            }
            $resolved = array_values(array_filter([$recipient, $sender], static fn ($r) => $r !== null && $r['unknown'] === []));
            if (count($resolved) === 2 && ($conflict = self::conflict($resolved[0], $resolved[1])) !== null) {
                $out['sender_unknown'] = [['part' => AddressTags::CONFLICT, 'value' => $conflict]];
                $resolved              = [];
            }
            $path = null;
            foreach ($resolved as $r) {
                $out['address'] = [...$out['address'], ...$r['tags']];
                $path ??= $r['device'];
            }
            if ($path !== null) {
                $this->pathTags ??= (new Inventory($this->db, $this))->tagsByPath();
                $device = $this->pathTags[$path] ?? [];
            }
        }

        $out['device']  = array_values(array_diff(array_unique($device), $manualNames));
        $out['address'] = array_values(array_diff(array_unique($out['address']), $manualNames, $out['device']));
        $out['sender']  = array_values(array_diff(array_unique($this->senderRuleTagsFor($message)), $manualNames, $out['device'], $out['address']));

        return $out;
    }

    /**
     * Fixed tags of the allow rules whose sender (envelope or From, as set in the rule) matches and
     * whose check the message passes (E-022). Only for checked (MX) messages.
     *
     * @return list<string>
     */
    private function senderRuleTagsFor(array $message): array
    {
        if ($message['mode'] !== 'mx') {
            return [];
        }
        if ($this->senderRuleTags === null) {
            $rows = $this->db->table('sender_rules r')->select('r.id, r.pattern, r.target, r.auth_check, t.name')
                ->join('sender_rule_tags st', 'st.sender_rule_id = r.id')->join('tags t', 't.id = st.tag_id')
                ->where('r.action', 'allow')->get()->getResultArray();
            $rules = [];
            foreach ($rows as $r) {
                $rules[$r['id']] ??= ['pattern' => $r['pattern'], 'target' => $r['target'], 'auth_check' => $r['auth_check'], 'tags' => []];
                $rules[$r['id']]['tags'][] = $r['name'];
            }
            $this->senderRuleTags = array_values($rules);
        }
        $tags = [];
        foreach ($this->senderRuleTags as $rule) {
            $sender = $rule['target'] === 'header' ? $message['header_from_address'] : $message['envelope_sender'];
            if (SenderRules::addressMatches($sender, $rule['pattern']) && SenderTags::verified($rule, $message)) {
                $tags = [...$tags, ...$rule['tags']];
            }
        }

        return $tags;
    }

    /** Recipient address of a domain with parse_address, or a mapped one (E-016, E-019). */
    private function recipientResult(array $message): ?array
    {
        $recipient = strtolower(trim((string) $message['envelope_recipient'], '<> '));
        $domain    = substr((string) strrchr($recipient, '@'), 1);
        $this->addressDomains ??= array_column($this->db->table('local_domains')->select('domain')->where('parse_address', 1)->get()->getResultArray(), 'domain');
        $mapped = $this->addressKnown['map'][$recipient] ?? null;
        if ($mapped === null && ($domain === '' || ! in_array($domain, $this->addressDomains, true))) {
            return null;
        }

        return AddressTags::resolve($recipient, $this->addressKnown, $mapped);
    }

    /**
     * Header From address covered by an allow rule with a tag meaning (E-020, E-021), only for checked
     * (MX) messages and only if the message passes the check of that rule; otherwise noted in $out.
     * On a customer domain a single word without mapping (info@, office@) is a mailbox, not a device:
     * ignored unless resolved.
     */
    private function senderResult(array $message, array &$out): ?array
    {
        $from = strtolower(trim((string) ($message['header_from_address'] ?? ''), '<> '));
        if ($message['mode'] !== 'mx' || ! str_contains($from, '@')) {
            return null;
        }
        $rule = SenderTags::rule($from, $this->addressKnown['senders']);
        if ($rule === null) {
            return null;
        }
        if (! SenderTags::verified($rule, $message)) {
            $out['sender_unverified'] = $rule['auth_check'];

            return null;
        }
        $mapped = $this->addressKnown['map'][$from] ?? null;
        $result = SenderTags::resolve($from, $rule, $this->addressKnown, $mapped);
        if ($result['unknown'] !== [] && $mapped === null && $rule['customer'] !== null
            && ! str_contains(substr($from, 0, (int) strrpos($from, '@')), '_')) {
            return null;
        }

        return $result;
    }

    /** Description of the recipient side if recipient and sender name a different customer or device. */
    private static function conflict(array $recipient, array $sender): ?string
    {
        // an address outside the scheme resolved by the map only has the device: customer = path prefix
        $customer = static fn (array $r): ?string => ($k = preg_grep('/^kunde:/', $r['tags']))
            ? substr((string) reset($k), 6) : ($r['device'] !== null ? explode('.', $r['device'])[0] : null);
        if ($customer($recipient) !== $customer($sender)) {
            return (string) $customer($recipient);
        }
        if ($recipient['device'] !== null && $sender['device'] !== null && $recipient['device'] !== $sender['device']) {
            return $recipient['device'];
        }

        return null;
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
