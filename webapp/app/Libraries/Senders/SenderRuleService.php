<?php

namespace App\Libraries\Senders;

use App\Libraries\Inventory\Inventory;
use App\Libraries\Rules\RuleRunner;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Create/delete sender rules and reclassify the affected stored messages.
 */
class SenderRuleService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function rules(): SenderRules
    {
        return new SenderRules($this->db->table('sender_rules')->select('pattern, target, action, auth_check')->orderBy('id')->get()->getResultArray());
    }

    /**
     * Add or replace the rule for (pattern, target). Returns the number of reclassified messages.
     *
     * @param array{auth_check?: string, tag_customer_id?: ?int, tag_source?: ?string, tags?: list<string>}|null $settings
     *        check, tag meaning (E-021) and fixed tags (E-022); null keeps those of an existing rule
     *        (quick buttons), a new rule gets the default check, no tag meaning and no tags
     */
    public function save(string $pattern, string $target, string $action, ?string $note = null, ?array $settings = null): int
    {
        $pattern = SenderRules::normalizePattern($pattern);
        if (! in_array($target, SenderRules::TARGETS, true) || ! in_array($action, SenderRules::ACTIONS, true)) {
            throw new InvalidArgumentException('Invalid target or action');
        }
        $data = ['action' => $action, 'note' => $note];
        $tags = null;
        if ($settings !== null) {
            $data += $this->validSettings($action, $settings);
            $tags = $action === 'allow' ? Inventory::validTags($settings['tags'] ?? []) : [];
        }
        $now      = gmdate('Y-m-d H:i:s');
        $existing = $this->db->table('sender_rules')->where(['pattern' => $pattern, 'target' => $target])->get()->getRowArray();
        $this->db->transStart();
        if ($existing) {
            $id = (int) $existing['id'];
            $this->db->table('sender_rules')->where('id', $id)->update($data + ['updated_at' => $now]);
        } else {
            $this->db->table('sender_rules')->insert($data + ['pattern' => $pattern, 'target' => $target, 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $this->db->insertID();
        }
        if ($tags !== null) {
            $runner = new RuleRunner($this->db);
            $this->db->table('sender_rule_tags')->where('sender_rule_id', $id)->delete();
            foreach ($tags as $name) {
                $this->db->table('sender_rule_tags')->insert(['sender_rule_id' => $id, 'tag_id' => $runner->tagId($name)]);
            }
        }
        $this->db->transComplete();
        $this->bumpRules();

        return $this->reclassify($pattern, $target);
    }

    /** @return array{auth_check: string, tag_customer_id: ?int, tag_source: ?string} */
    private function validSettings(string $action, array $settings): array
    {
        $check = $settings['auth_check'] ?? SenderRules::DEFAULT_CHECK;
        if (! in_array($check, SenderRules::CHECKS, true)) {
            throw new InvalidArgumentException('check');
        }
        $customer = $settings['tag_customer_id'] ?? null;
        $source   = trim((string) ($settings['tag_source'] ?? '')) ?: null;
        if ($action !== 'allow') {
            [$customer, $source] = [null, null]; // block rules carry no tag meaning
        } elseif ($customer !== null && $source !== null) {
            throw new InvalidArgumentException('tag_meaning');
        }
        if ($customer !== null && $this->db->table('customers')->where('id', $customer)->countAllResults() === 0) {
            throw new InvalidArgumentException('customer');
        }
        if ($source !== null) {
            $source = strtolower($source);
            if (str_starts_with($source, 'source:')) {
                $source = substr($source, 7);
            }
            if (! preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $source)) {
                throw new InvalidArgumentException('source');
            }
        }

        return ['auth_check' => $action === 'allow' ? $check : SenderRules::DEFAULT_CHECK, 'tag_customer_id' => $customer, 'tag_source' => $source];
    }

    /** Tag meaning of sender domains changed: re-evaluate the tags of all messages. */
    private function bumpRules(): void
    {
        (new RuleRunner($this->db))->bumpVersion();
    }

    /** Delete a rule. Returns the number of reclassified messages. */
    public function delete(int $id): int
    {
        $rule = $this->db->table('sender_rules')->where('id', $id)->get()->getRowArray();
        if (! $rule) {
            return 0;
        }
        $this->db->table('sender_rules')->where('id', $id)->delete();
        $this->bumpRules();

        return $this->reclassify($rule['pattern'], $rule['target']);
    }

    /**
     * Recompute status for all MX messages whose sender (selected by target) could match the pattern.
     */
    public function reclassify(string $pattern, string $target): int
    {
        $column  = $target === 'header' ? 'header_from_address' : 'envelope_sender';
        $builder = $this->db->table('messages')
            ->select('id, status, envelope_sender, header_from_address, auth_verdict, auth_details, list_match_pattern, list_match_target')
            ->where('mode', 'mx');
        if (str_contains($pattern, '@')) {
            $builder->where($column, $pattern);
        } else {
            $builder->groupStart()->like($column, '@' . $pattern, 'before')->orLike($column, '.' . $pattern, 'before')->groupEnd();
        }

        $rules   = $this->rules();
        $changed = 0;
        foreach ($builder->get()->getResultArray() as $message) {
            $new = $rules->classify($message);
            if ($new['status'] !== $message['status'] || $new['list_match_pattern'] !== $message['list_match_pattern']
                || $new['list_match_target'] !== $message['list_match_target']) {
                // rules_version 0: the collector re-applies the rules (rule tags only for accepted messages)
                $this->db->table('messages')->where('id', $message['id'])->update($new + ['rules_version' => 0]);
                $changed++;
            }
        }

        return $changed;
    }
}
