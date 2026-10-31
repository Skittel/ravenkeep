<?php
/**
 * @var array|null $rule
 * @var array{matched: int, sample: int}|null $preview
 */
use App\Libraries\Rules\RuleEngine;

$this->setData(['active' => 'rules', 'title' => $rule ? $rule['name'] : lang('Rules.new')]);
$conditions = $rule['conditions'] ?? [['field' => 'envelope_sender', 'operator' => 'ends_with', 'value' => '']];
$tags       = implode(', ', array_column($rule['actions'] ?? [], 'value'));
$fields     = array_keys(RuleEngine::FIELDS);
$operators  = ['text' => RuleEngine::TEXT_OPERATORS, 'tag' => RuleEngine::TAG_OPERATORS];
$row = static function (array $c) use ($fields, $operators): string {
    $html = '<div class="cond-row"><select name="conditions[field][]" class="cond-field">';
    foreach ($fields as $f) {
        $html .= '<option value="' . $f . '"' . ($c['field'] === $f ? ' selected' : '') . '>' . esc(lang('Rules.field_' . $f)) . '</option>';
    }
    $html .= '</select><select name="conditions[operator][]" class="cond-op">';
    foreach ($operators as $group => $ops) {
        foreach ($ops as $o) {
            $html .= '<option value="' . $o . '" data-group="' . $group . '"' . ($c['operator'] === $o ? ' selected' : '') . '>' . esc(lang('Rules.op_' . $o)) . '</option>';
        }
    }

    return $html . '</select><input type="text" name="conditions[value][]" maxlength="255" value="' . esc($c['value'], 'attr') . '">'
        . '<button type="button" class="button cond-remove" title="' . esc(lang('Rules.removeCondition'), 'attr') . '">×</button></div>';
};
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page">
    <section class="card rule-edit">
        <h1><?= $rule ? esc($rule['name']) : lang('Rules.new') ?></h1>
        <?php if ($preview !== null): ?>
            <p class="notice"><?= lang('Rules.preview', [$preview['matched'], $preview['sample']]) ?></p>
        <?php endif ?>
        <?= form_open($rule ? 'rules/' . $rule['id'] : 'rules') ?>
            <label class="field"><?= lang('Rules.name') ?>
                <input type="text" name="name" required maxlength="128" value="<?= esc(old('name', $rule['name'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang('Rules.namePlaceholder'), 'attr') ?>"></label>

            <div class="field">
                <span><?= lang('Rules.when') ?>
                    <select name="match_mode">
                        <option value="all" <?= ($rule['match_mode'] ?? 'all') === 'all' ? 'selected' : '' ?>><?= lang('Rules.matchAll') ?></option>
                        <option value="any" <?= ($rule['match_mode'] ?? '') === 'any' ? 'selected' : '' ?>><?= lang('Rules.matchAny') ?></option>
                    </select></span>
                <div id="conditions">
                    <?php foreach ($conditions as $c): ?><?= $row($c) ?><?php endforeach ?>
                </div>
                <template id="condition-template"><?= $row(['field' => 'subject', 'operator' => 'contains', 'value' => '']) ?></template>
                <button type="button" class="button" id="add-condition"><?= lang('Rules.addCondition') ?></button>
                <p class="hint"><?= lang('Rules.conditionHint') ?></p>
            </div>

            <label class="field"><?= lang('Rules.thenTags') ?>
                <input type="text" name="tags" required value="<?= esc(old('tags', $tags), 'attr') ?>" placeholder="kunde:acme, typ:nas"></label>
            <p class="hint"><?= lang('Rules.tagHint') ?></p>

            <label class="check"><input type="checkbox" name="enabled" value="1" <?= ($rule['enabled'] ?? 1) ? 'checked' : '' ?>> <?= lang('Rules.enabled') ?></label>
            <label class="check"><input type="checkbox" name="stop" value="1" <?= ! empty($rule['stop']) ? 'checked' : '' ?>> <?= lang('Rules.stop') ?></label>

            <div class="form-actions">
                <button type="submit" class="button button-primary"><?= lang('Rules.save') ?></button>
                <a class="button" href="<?= site_url('rules') ?>"><?= lang('Rules.cancel') ?></a>
            </div>
        <?= form_close() ?>
    </section>
</div>
<?= $this->endSection() ?>
