<?php

namespace App\Libraries\Senders;

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
        return new SenderRules($this->db->table('sender_rules')->select('pattern, target, action')->orderBy('id')->get()->getResultArray());
    }

    /**
     * Add or replace the rule for (pattern, target). Returns the number of reclassified messages.
     */
    public function save(string $pattern, string $target, string $action, ?string $note = null): int
    {
        $pattern = SenderRules::normalizePattern($pattern);
        if (! in_array($target, SenderRules::TARGETS, true) || ! in_array($action, SenderRules::ACTIONS, true)) {
            throw new InvalidArgumentException('Invalid target or action');
        }
        $now      = gmdate('Y-m-d H:i:s');
        $existing = $this->db->table('sender_rules')->where(['pattern' => $pattern, 'target' => $target])->get()->getRowArray();
        if ($existing) {
            $this->db->table('sender_rules')->where('id', $existing['id'])
                ->update(['action' => $action, 'note' => $note, 'updated_at' => $now]);
        } else {
            $this->db->table('sender_rules')->insert(['pattern' => $pattern, 'target' => $target, 'action' => $action,
                'note' => $note, 'created_at' => $now, 'updated_at' => $now]);
        }

        return $this->reclassify($pattern, $target);
    }

    /** Delete a rule. Returns the number of reclassified messages. */
    public function delete(int $id): int
    {
        $rule = $this->db->table('sender_rules')->where('id', $id)->get()->getRowArray();
        if (! $rule) {
            return 0;
        }
        $this->db->table('sender_rules')->where('id', $id)->delete();

        return $this->reclassify($rule['pattern'], $rule['target']);
    }

    /**
     * Recompute status for all MX messages whose sender (selected by target) could match the pattern.
     */
    public function reclassify(string $pattern, string $target): int
    {
        $column  = $target === 'header' ? 'header_from_address' : 'envelope_sender';
        $builder = $this->db->table('messages')
            ->select('id, status, envelope_sender, header_from_address, auth_verdict, list_match_pattern, list_match_target')
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
