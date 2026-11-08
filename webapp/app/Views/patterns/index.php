<?php
/**
 * Subject patterns, test, unknown values from subjects, proposals and aliases (E-029).
 *
 * @var list<array> $patterns
 * @var list<string> $sources
 * @var list<array> $aliases
 * @var list<array> $unknown
 * @var list<array> $suggestions
 * @var list<array> $customers
 * @var list<array> $locations
 * @var list<array> $devices
 * @var array|null $test
 * @var array{source: string, subject: string, pattern: string} $testInput
 * @var string|null $testError
 * @var int $backlog
 * @var int $editPattern id of the pattern being edited (0 = none)
 */
use App\Libraries\Rules\SubjectPattern;

$this->setData(['active' => 'patterns', 'title' => lang('Patterns.title')]);
$sourceSelect = static function (string $selected = '', string $name = 'source') use ($sources): string {
    $html = '<select name="' . $name . '" required>';
    foreach ($sources as $s) {
        $html .= '<option value="' . esc($s, 'attr') . '"' . ($s === $selected ? ' selected' : '') . '>' . esc($s) . '</option>';
    }

    return $html . '</select>';
};
$locationsOf = static fn (int $customerId): array => array_values(array_filter($locations, static fn ($l) => (int) $l['customer_id'] === $customerId));
$devicesOf   = static fn (int $customerId): array => array_values(array_filter($devices, static fn ($d) => (int) $d['customer_id'] === $customerId));
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page">
    <section class="card">
        <h1><?= lang('Patterns.title') ?></h1>
        <p class="hint"><?= esc(lang('Patterns.hint')) ?></p>
        <?php if ($backlog > 0): ?><p class="hint"><?= lang('Rules.backlog', [$backlog]) ?></p><?php endif ?>
        <?php if ($patterns === []): ?>
            <p class="muted"><?= lang('Patterns.none') ?></p>
        <?php else: ?>
            <table class="grid patterns">
                <thead><tr><th><?= lang('Patterns.source') ?></th><th><?= lang('Patterns.pattern') ?></th><th><?= lang('Patterns.status') ?></th><th class="num"><?= lang('Patterns.messages') ?></th><th class="num"><?= lang('Patterns.unknownCount') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($patterns as $p): ?>
                    <?php if ($editPattern === $p['id']): ?>
                    <tr class="editing"><td colspan="6">
                        <?= form_open('patterns/' . $p['id'], ['class' => 'rule-form pattern-form']) ?>
                            <?= $sourceSelect($p['source']) ?>
                            <input type="text" name="pattern" maxlength="255" required value="<?= esc($p['pattern'], 'attr') ?>">
                            <button type="submit" class="button button-primary"><?= lang('Patterns.save') ?></button>
                            <a class="button" href="<?= site_url('patterns') ?>"><?= lang('Rules.cancel') ?></a>
                        <?= form_close() ?>
                    </td></tr>
                    <?php else: ?>
                    <tr class="<?= $p['enabled'] ? '' : 'pattern-off' ?>">
                        <td><span class="<?= rk_tag_class('source:' . $p['source']) ?>"><?= esc($p['source']) ?></span></td>
                        <td><code><?= esc($p['pattern']) ?></code></td>
                        <td><?= $p['enabled'] ? lang('Patterns.active') : '<span class="muted">' . lang('Patterns.inactive') . '</span>' ?></td>
                        <td class="num"><?= $p['messages'] ?></td>
                        <td class="num"><?= $p['unknown'] ?></td>
                        <td class="write-only pattern-actions">
                            <a class="button" href="<?= site_url('patterns') ?>?edit=<?= $p['id'] ?>"><?= lang('Patterns.edit') ?></a>
                            <?= form_open('patterns/' . $p['id'] . '/toggle', ['class' => 'inline']) ?><?= form_hidden('enabled', $p['enabled'] ? '0' : '1') ?><button type="submit" class="button"><?= lang($p['enabled'] ? 'Patterns.disable' : 'Patterns.enable') ?></button><?= form_close() ?>
                            <?= form_open('patterns/' . $p['id'] . '/delete', ['class' => 'inline', 'data-confirm' => lang('Patterns.confirmDelete')]) ?><button type="submit" class="button"><?= lang('Patterns.delete') ?></button><?= form_close() ?>
                        </td>
                    </tr>
                    <?php endif ?>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
        <?php if ($sources !== []): ?>
            <?= form_open('patterns', ['class' => 'rule-form write-only pattern-form']) ?>
                <?= $sourceSelect((string) old('source')) ?>
                <input type="text" name="pattern" maxlength="255" required placeholder="Fehlerbericht - {kunde}, {standort}, {geraet}" value="<?= esc((string) old('pattern'), 'attr') ?>">
                <button type="submit" class="button button-primary"><?= lang('Patterns.add') ?></button>
            <?= form_close() ?>
        <?php endif ?>
    </section>

    <section class="card">
        <h1><?= lang('Patterns.testTitle') ?></h1>
        <p class="hint"><?= lang('Patterns.testHint') ?></p>
        <form method="get" action="<?= site_url('patterns') ?>" class="rule-form pattern-form">
            <?= $sourceSelect($testInput['source'], 'test_source') ?>
            <input type="text" name="test_subject" required placeholder="<?= esc(lang('Patterns.testSubject'), 'attr') ?>" value="<?= esc($testInput['subject'], 'attr') ?>">
            <input type="text" name="test_pattern" placeholder="<?= esc(lang('Patterns.testPatternOpt'), 'attr') ?>" value="<?= esc($testInput['pattern'], 'attr') ?>">
            <button type="submit" class="button"><?= lang('Patterns.test') ?></button>
        </form>
        <?php if ($testError !== null): ?>
            <p class="error"><?= esc($testError) ?></p>
        <?php elseif ($testInput['subject'] !== ''): ?>
            <div class="pattern-test">
            <?php if ($test === null): ?>
                <p class="muted"><?= lang('Patterns.testNoMatch') ?></p>
            <?php else: ?>
                <p><?= lang('Patterns.testMatched', [esc($test['pattern'])]) ?></p>
                <p><?= lang('Patterns.testValues') ?>: <?php foreach ($test['values'] as $k => $v): ?><code>{<?= esc($k) ?>}</code> = „<?= esc($v) ?>“ <?php endforeach ?></p>
                <?php if ($test['result']['unknown'] !== []): ?>
                    <p class="error"><?= esc(implode(', ', array_map(static fn ($p) => lang('Patterns.part_' . $p['part'], [$p['value']]), $test['result']['unknown']))) ?></p>
                <?php else: ?>
                    <p><?= lang('Patterns.testTags') ?>: <?php foreach ($test['result']['tags'] as $t): ?><span class="<?= rk_tag_class($t) ?>"><?= esc(rk_tag_label($t)) ?></span> <?php endforeach ?></p>
                <?php endif ?>
            <?php endif ?>
            </div>
        <?php endif ?>
    </section>

    <section class="card">
        <h1><?= lang('Patterns.unknownTitle') ?></h1>
        <p class="hint"><?= lang('Patterns.unknownHint') ?></p>
        <?php if ($unknown === []): ?>
            <p class="ok-text"><?= lang('Patterns.noUnknown') ?></p>
        <?php else: ?>
            <table class="grid pattern-unknown">
                <thead><tr><th><?= lang('Patterns.value') ?></th><th><?= lang('Patterns.customerCol') ?></th><th class="num"><?= lang('Patterns.messages') ?></th><th><?= lang('Addresses.actions') ?></th></tr></thead>
                <tbody>
                <?php foreach ($unknown as $u): ?>
                    <tr>
                        <td><?= esc(lang('Patterns.part_' . $u['part'], [$u['value']])) ?><br><a class="muted" href="<?= site_url('/') ?>?range=all&amp;id=<?= $u['last_id'] ?>"><?= lang('Addresses.showMessages') ?></a></td>
                        <td><?= esc($u['customer'] ?? '') ?></td>
                        <td class="num"><?= $u['messages'] ?></td>
                        <td class="address-actions">
                            <div class="write-only">
                            <?php if ($u['part'] === 'customer' || ($u['customer_id'] !== null && in_array($u['part'], ['location', 'device'], true))): ?>
                                <?= form_open('patterns/create', ['class' => 'inline']) ?>
                                    <?= form_hidden(['part' => $u['part'], 'customer_id' => (string) ($u['customer_id'] ?? ''), 'name' => $u['value']]) ?>
                                    <label><?= lang('Patterns.create') ?> <?= lang('Patterns.kind_' . $u['part']) ?>
                                        <input type="text" name="code" size="14" maxlength="32" required value="<?= esc(SubjectPattern::code($u['value']), 'attr') ?>" title="<?= esc(lang('Customers.code'), 'attr') ?>"></label>
                                    <button type="submit" class="button"><?= lang('Patterns.createButton') ?></button>
                                <?= form_close() ?>
                                <?php $targets = match ($u['part']) {
                                    'customer' => array_map(static fn ($c) => ['customer:' . $c['id'], $c['name'] . ' (' . $c['code'] . ')'], $customers),
                                    'location' => array_map(static fn ($l) => ['location:' . $l['id'], $l['name'] . ' (' . $l['code'] . ')'], $locationsOf($u['customer_id'])),
                                    default    => array_map(static fn ($d) => ['device:' . $d['id'], $d['name'] . ' (' . $d['path'] . ')'], $devicesOf($u['customer_id'])),
                                }; ?>
                                <?php if ($targets !== []): ?>
                                    <?= form_open('patterns/aliases', ['class' => 'inline']) ?>
                                        <?= form_hidden('alias', $u['value']) ?>
                                        <select name="target" required>
                                            <option value=""><?= lang('Patterns.aliasFor') ?></option>
                                            <?php foreach ($targets as [$v, $label]): ?><option value="<?= esc($v, 'attr') ?>"><?= esc($label) ?></option><?php endforeach ?>
                                        </select>
                                        <button type="submit" class="button button-primary"><?= lang('Patterns.aliasButton') ?></button>
                                    <?= form_close() ?>
                                <?php endif ?>
                            <?php endif ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>

    <section class="card">
        <h1><?= lang('Patterns.suggestTitle') ?></h1>
        <p class="hint"><?= esc(lang('Patterns.suggestHint')) ?></p>
        <?php if ($suggestions === []): ?>
            <p class="muted"><?= lang('Patterns.noSuggestions') ?></p>
        <?php else: ?>
            <table class="grid pattern-suggestions">
                <thead><tr><th><?= lang('Patterns.source') ?></th><th class="num"><?= lang('Patterns.messages') ?></th><th><?= lang('Patterns.pattern') ?> / <?= lang('Patterns.examples') ?></th></tr></thead>
                <tbody>
                <?php foreach ($suggestions as $s): ?>
                    <tr>
                        <td><span class="<?= rk_tag_class('source:' . $s['source']) ?>"><?= esc($s['source']) ?></span></td>
                        <td class="num"><?= $s['messages'] ?></td>
                        <td>
                            <?= form_open('patterns', ['class' => 'rule-form pattern-form']) ?>
                                <?= form_hidden('source', $s['source']) ?>
                                <input type="text" name="pattern" maxlength="255" required value="<?= esc($s['pattern'], 'attr') ?>">
                                <button type="submit" class="button button-primary write-only"><?= lang('Patterns.add') ?></button>
                            <?= form_close() ?>
                            <ul class="examples"><?php foreach ($s['examples'] as $e): ?><li><?= esc($e) ?></li><?php endforeach ?></ul>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>

    <section class="card">
        <h1><?= lang('Patterns.aliasesTitle') ?></h1>
        <p class="hint"><?= lang('Patterns.aliasesHint') ?></p>
        <?php if ($aliases === []): ?>
            <p class="muted"><?= lang('Patterns.noAliases') ?></p>
        <?php else: ?>
            <table class="grid">
                <thead><tr><th><?= lang('Patterns.alias') ?></th><th><?= lang('Patterns.aliasKind') ?></th><th><?= lang('Patterns.aliasTarget') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($aliases as $a): ?>
                    <tr>
                        <td><?= esc($a['alias']) ?></td>
                        <td><?= lang('Patterns.kind_' . $a['kind']) ?></td>
                        <td><a href="<?= site_url('customers/' . $a['customer_id']) ?>"><?= esc($a['target']) ?></a></td>
                        <td class="write-only"><?= form_open('patterns/aliases/' . $a['id'] . '/delete', ['class' => 'inline', 'data-confirm' => lang('Patterns.confirmAliasDelete')]) ?><button type="submit" class="button"><?= lang('Patterns.delete') ?></button><?= form_close() ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
        <?= form_open('patterns/aliases', ['class' => 'rule-form write-only pattern-form']) ?>
            <select name="target" required>
                <option value=""><?= lang('Patterns.chooseTarget') ?></option>
                <optgroup label="<?= esc(lang('Patterns.kind_customer'), 'attr') ?>">
                    <?php foreach ($customers as $c): ?><option value="customer:<?= $c['id'] ?>"><?= esc($c['name']) ?> (<?= esc($c['code']) ?>)</option><?php endforeach ?>
                </optgroup>
                <optgroup label="<?= esc(lang('Patterns.kind_location'), 'attr') ?>">
                    <?php foreach ($locations as $l): ?><option value="location:<?= $l['id'] ?>"><?= esc($l['customer_name']) ?> / <?= esc($l['name']) ?> (<?= esc($l['customer_code'] . '.' . $l['code']) ?>)</option><?php endforeach ?>
                </optgroup>
                <optgroup label="<?= esc(lang('Patterns.kind_device'), 'attr') ?>">
                    <?php foreach ($devices as $d): ?><option value="device:<?= $d['id'] ?>"><?= esc($d['customer_name']) ?> / <?= esc($d['name']) ?> (<?= esc($d['path']) ?>)</option><?php endforeach ?>
                </optgroup>
            </select>
            <input type="text" name="alias" maxlength="128" required placeholder="<?= esc(lang('Patterns.alias'), 'attr') ?>">
            <button type="submit" class="button button-primary"><?= lang('Patterns.aliasAdd') ?></button>
        <?= form_close() ?>
    </section>
</div>
<?= $this->endSection() ?>
