<?php
/**
 * @var list<array> $pending
 * @var list<array> $rules
 */
$this->setData(['active' => 'senders', 'title' => lang('Senders.title')]);
$ruleForm = static function (string $pattern, string $target, string $action, string $label, string $class = ''): string {
    return form_open('senders', ['class' => 'inline'])
        . form_hidden(['pattern' => $pattern, 'target' => $target, 'action' => $action, 'return_to' => '/senders'])
        . '<button type="submit" class="button ' . $class . '">' . esc($label) . '</button>' . form_close();
};
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
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
                    <th class="num"><?= lang('Senders.messages') ?></th><th class="num"><?= lang('Senders.authPassed') ?></th>
                    <th><?= lang('Senders.lastSeen') ?></th><th><?= lang('Senders.example') ?></th><th><?= lang('Senders.actions') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($pending as $p): ?>
                    <tr>
                        <td><?= esc($p['envelope_domain'] ?: '<>') ?></td>
                        <td><?= esc($p['header_domain'] ?: '–') ?></td>
                        <td class="num"><?= (int) $p['messages'] ?></td>
                        <td class="num"><?= (int) $p['auth_passed'] ?> / <?= (int) $p['messages'] ?></td>
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
                    <th><?= lang('Senders.note') ?></th><th><?= lang('Senders.created') ?></th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rules as $r): ?>
                    <tr>
                        <td><span class="badge badge-<?= $r['action'] === 'allow' ? 'accepted' : 'blocked' ?>"><?= $r['action'] === 'allow' ? lang('Senders.actionAllow') : lang('Senders.actionBlock') ?></span></td>
                        <td><code><?= esc($r['pattern']) ?></code></td>
                        <td><?= $r['target'] === 'header' ? lang('Senders.targetHeader') : lang('Senders.targetEnvelope') ?></td>
                        <td><?= esc($r['note'] ?? '') ?></td>
                        <td><?= esc(rk_time($r['created_at'], 'd.m.Y H:i')) ?></td>
                        <td>
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
