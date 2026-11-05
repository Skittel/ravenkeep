<?php
/**
 * @var list<array> $pending
 * @var list<array> $rules
 * @var array<string, array> $observed what the senders of a rule passed (key pattern|target)
 * @var list<array> $customers
 * @var int $edit rule id in edit mode
 * @var array<int, list<string>> $ruleTags fixed tags per rule (E-022)
 * @var list<string> $tagNames
 */
use App\Libraries\Senders\SenderRules;

$this->setData(['active' => 'senders', 'title' => lang('Senders.title')]);
$ruleForm = static function (string $pattern, string $target, string $action, string $label, string $class = ''): string {
    return form_open('senders', ['class' => 'inline'])
        . form_hidden(['pattern' => $pattern, 'target' => $target, 'action' => $action, 'return_to' => '/senders'])
        . '<button type="submit" class="button ' . $class . '">' . esc($label) . '</button>' . form_close();
};
// check and tag meaning of an allow rule (E-021)
$settingsFields = static function (array $r, array $tags = []) use ($customers): string {
    $check = $r['auth_check'] ?? SenderRules::DEFAULT_CHECK;
    $kind  = ! empty($r['tag_customer_id']) ? 'customer' : (! empty($r['tag_source']) ? 'source' : '');
    $html  = '<label>' . lang('Senders.check') . '<select name="auth_check">';
    foreach (SenderRules::CHECKS as $c) {
        $html .= '<option value="' . $c . '"' . ($c === $check ? ' selected' : '') . '>' . esc(lang('Senders.checkOption_' . $c)) . '</option>';
    }
    $html .= '</select></label><label>' . lang('Senders.tagMeaning') . '<select name="tag_kind">'
        . '<option value="">' . lang('Senders.tagNone') . '</option>'
        . '<option value="customer"' . ($kind === 'customer' ? ' selected' : '') . '>' . lang('Senders.tagCustomer') . '</option>'
        . '<option value="source"' . ($kind === 'source' ? ' selected' : '') . '>' . lang('Senders.tagSource') . '</option></select></label>'
        . '<label>' . lang('Customers.customer') . '<select name="tag_customer_id"><option value="">–</option>';
    foreach ($customers as $c) {
        $html .= '<option value="' . (int) $c['id'] . '"' . ((int) ($r['tag_customer_id'] ?? 0) === (int) $c['id'] ? ' selected' : '') . '>' . esc($c['name']) . ' (' . esc($c['code']) . ')</option>';
    }

    return $html . '</select></label><label>' . lang('Senders.tagSourceValue') . '<input type="text" name="tag_source" maxlength="64" class="code-input" placeholder="plesk" value="'
        . esc($r['tag_source'] ?? '', 'attr') . '"></label>'
        . '<label>' . lang('Senders.fixedTags') . '<input type="text" name="tags" autocomplete="off" placeholder="source:site24x7" value="'
        . esc(implode(', ', $tags), 'attr') . '"' . rk_tag_picker_attrs('multi', ['kunde', 'standort', 'geraet']) . '></label>';
};
$observedText = static function (array $r) use ($observed): string {
    $o = $observed[$r['pattern'] . '|' . $r['target']] ?? null;

    return $o === null ? lang('Senders.observedNone') : lang('Senders.observed', [(int) $o['spf'], (int) $o['dkim'], (int) $o['messages']]);
};
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?= rk_tag_data($tagNames) ?>
<div class="page">
    <section class="card">
        <h1><?= lang('Senders.pendingTitle') ?></h1>
        <p class="hint"><?= lang('Senders.pendingHint') ?></p>
        <?php if ($pending === []): ?>
            <p class="muted"><?= lang('Senders.noPending') ?></p>
        <?php else: ?>
            <table class="grid">
                <thead><tr>
                    <th><?= lang('Senders.envelopeDomain') ?></th><th><?= lang('Senders.headerDomain') ?></th>
                    <th class="num"><?= lang('Senders.messages') ?></th><th class="num"><?= lang('Senders.spfPassed') ?></th><th class="num"><?= lang('Senders.dkimPassed') ?></th>
                    <th><?= lang('Senders.lastSeen') ?></th><th><?= lang('Senders.example') ?></th><th><?= lang('Senders.actions') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($pending as $p): ?>
                    <tr>
                        <td><?= esc($p['envelope_domain'] ?: '<>') ?></td>
                        <td><?= esc($p['header_domain'] ?: '–') ?></td>
                        <td class="num"><?= (int) $p['messages'] ?></td>
                        <td class="num"><?= (int) $p['spf_passed'] ?> / <?= (int) $p['messages'] ?></td>
                        <td class="num"><?= (int) $p['dkim_passed'] ?> / <?= (int) $p['messages'] ?></td>
                        <td><?= esc(rk_time($p['last_seen'], 'd.m. H:i')) ?></td>
                        <td class="ellipsis"><a href="<?= site_url('/') ?>?id=<?= (int) $p['sample_id'] ?>"><?= esc($p['sample_subject'] ?? '') ?: '<em>' . lang('Ui.noSubject') . '</em>' ?></a></td>
                        <td class="actions">
                            <?php if ($p['envelope_domain'] !== ''): ?>
                                <?= $ruleForm($p['envelope_domain'], 'envelope', 'allow', lang('Senders.allowEnvelope'), 'button-allow') ?>
                            <?php endif ?>
                            <?php if ($p['header_domain'] !== '' && $p['header_domain'] !== $p['envelope_domain']): ?>
                                <?= $ruleForm($p['header_domain'], 'header', 'allow', lang('Senders.allowHeader'), 'button-allow') ?>
                            <?php endif ?>
                            <?php if ($p['envelope_domain'] !== ''): ?>
                                <?= $ruleForm($p['envelope_domain'], 'envelope', 'block', lang('Senders.block'), 'button-block') ?>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>

    <section class="card">
        <h1><?= lang('Senders.rulesTitle') ?></h1>
        <p class="hint"><?= lang('Senders.checkHint') ?></p>
        <?= form_open('senders', ['class' => 'rule-form']) ?>
            <label><?= lang('Senders.pattern') ?>
                <input type="text" name="pattern" required maxlength="255" placeholder="<?= esc(lang('Senders.patternHint'), 'attr') ?>"></label>
            <label><?= lang('Senders.target') ?>
                <select name="target">
                    <option value="envelope"><?= lang('Senders.targetEnvelope') ?></option>
                    <option value="header"><?= lang('Senders.targetHeader') ?></option>
                </select></label>
            <label><?= lang('Senders.action') ?>
                <select name="action">
                    <option value="allow"><?= lang('Senders.actionAllow') ?></option>
                    <option value="block"><?= lang('Senders.actionBlock') ?></option>
                </select></label>
            <?= $settingsFields([]) ?>
            <label><?= lang('Senders.note') ?>
                <input type="text" name="note" maxlength="255"></label>
            <input type="hidden" name="return_to" value="/senders">
            <button type="submit" class="button button-primary"><?= lang('Senders.add') ?></button>
        <?= form_close() ?>

        <?php if ($rules === []): ?>
            <p class="muted"><?= lang('Senders.noRules') ?></p>
        <?php else: ?>
            <table class="grid">
                <thead><tr>
                    <th><?= lang('Senders.action') ?></th><th><?= lang('Senders.pattern') ?></th><th><?= lang('Senders.target') ?></th>
                    <th><?= lang('Senders.check') ?></th><th><?= lang('Customers.tags') ?></th>
                    <th><?= lang('Senders.note') ?></th><th><?= lang('Senders.created') ?></th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rules as $r): ?>
                    <?php if ($edit === (int) $r['id']): ?>
                        <tr class="editing"><td colspan="8">
                            <?= form_open('senders', ['class' => 'rule-form']) ?>
                                <?= form_hidden(['pattern' => $r['pattern'], 'target' => $r['target'], 'return_to' => '/senders']) ?>
                                <label><?= lang('Senders.pattern') ?><input type="text" value="<?= esc($r['pattern'], 'attr') ?>" disabled></label>
                                <label><?= lang('Senders.action') ?><select name="action">
                                    <option value="allow"<?= $r['action'] === 'allow' ? ' selected' : '' ?>><?= lang('Senders.actionAllow') ?></option>
                                    <option value="block"<?= $r['action'] === 'block' ? ' selected' : '' ?>><?= lang('Senders.actionBlock') ?></option></select></label>
                                <?= $settingsFields($r, $ruleTags[(int) $r['id']] ?? []) ?>
                                <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255" value="<?= esc($r['note'] ?? '', 'attr') ?>"></label>
                                <button type="submit" class="button button-primary"><?= lang('Rules.save') ?></button>
                                <a class="button" href="<?= site_url('senders') ?>"><?= lang('Rules.cancel') ?></a>
                            <?= form_close() ?>
                            <p class="hint"><?= esc($observedText($r)) ?></p>
                        </td></tr>
                        <?php continue ?>
                    <?php endif ?>
                    <tr>
                        <td><span class="badge badge-<?= $r['action'] === 'allow' ? 'accepted' : 'blocked' ?>"><?= $r['action'] === 'allow' ? lang('Senders.actionAllow') : lang('Senders.actionBlock') ?></span></td>
                        <td><code><?= esc($r['pattern']) ?></code></td>
                        <td><?= $r['target'] === 'header' ? lang('Senders.targetHeader') : lang('Senders.targetEnvelope') ?></td>
                        <td><?php if ($r['action'] === 'allow'): ?>
                            <span class="<?= $r['auth_check'] === 'none' ? 'check-none' : '' ?>"><?= lang('Senders.check_' . $r['auth_check']) ?></span>
                            <br><span class="muted"><?= esc($observedText($r)) ?></span>
                        <?php endif ?></td>
                        <td><?php if ($r['customer_code'] !== null): ?><span class="<?= rk_tag_class('kunde:x') ?>"><?= esc(rk_tag_label('kunde:' . $r['customer_code'])) ?></span>
                            <?php elseif ($r['tag_source'] !== null): ?><span class="<?= rk_tag_class('source:x') ?>"><?= esc(rk_tag_label('source:' . $r['tag_source'])) ?></span> <span class="muted">+ <?= lang('Senders.tagDevice') ?></span><?php endif ?>
                            <?php foreach ($ruleTags[(int) $r['id']] ?? [] as $t): ?><a class="<?= rk_tag_class($t) ?>" href="<?= site_url('/') ?>?tag%5B0%5D=<?= urlencode($t) ?>"><?= esc(rk_tag_label($t)) ?></a><?php endforeach ?></td>
                        <td><?= esc($r['note'] ?? '') ?></td>
                        <td><?= esc(rk_time($r['created_at'], 'd.m.Y H:i')) ?></td>
                        <td class="actions">
                            <a class="button write-only" href="<?= site_url('senders') ?>?edit=<?= (int) $r['id'] ?>"><?= lang('Customers.edit') ?></a>
                            <?= form_open('senders/' . (int) $r['id'] . '/delete', ['class' => 'inline', 'data-confirm' => lang('Senders.confirmDelete')]) ?>
                            <button type="submit" class="button"><?= lang('Senders.delete') ?></button>
                            <?= form_close() ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>
</div>
<?= $this->endSection() ?>
