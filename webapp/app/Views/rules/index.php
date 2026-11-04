<?php
/**
 * @var list<array> $rules
 * @var int $backlog
 */
$this->setData(['active' => 'rules', 'title' => lang('Rules.title')]);
$post = static fn (string $url, string $label, string $class = '', ?string $confirm = null): string => form_open($url, array_filter(['class' => 'inline', 'data-confirm' => $confirm]))
    . '<button type="submit" class="button ' . $class . '">' . $label . '</button>' . form_close();
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page">
    <section class="card">
        <div class="card-head">
            <h1><?= lang('Rules.title') ?></h1>
            <a class="button button-primary write-only" href="<?= site_url('rules/new') ?>"><?= lang('Rules.new') ?></a>
        </div>
        <p class="hint"><?= lang('Rules.hint') ?></p>
        <?php if ($backlog > 0): ?>
            <p class="notice"><?= lang('Rules.backlog', [$backlog]) ?></p>
        <?php endif ?>
        <?php if ($rules === []): ?>
            <p class="muted"><?= lang('Rules.none') ?></p>
        <?php else: ?>
            <table class="grid rules">
                <thead><tr><th>#</th><th><?= lang('Rules.name') ?></th><th><?= lang('Rules.conditions') ?></th><th><?= lang('Rules.then') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rules as $i => $r): ?>
                    <tr class="<?= $r['enabled'] ? '' : 'disabled' ?>">
                        <td class="num"><?= $i + 1 ?></td>
                        <td><a href="<?= site_url('rules/' . $r['id']) ?>"><strong><?= esc($r['name']) ?></strong></a>
                            <?php if (! $r['enabled']): ?><br><span class="muted"><?= lang('Rules.disabled') ?></span><?php endif ?>
                            <?php if ($r['stop']): ?><br><span class="muted"><?= lang('Rules.stopShort') ?></span><?php endif ?></td>
                        <td><?php foreach ($r['conditions'] as $j => $c): ?>
                                <?= $j > 0 ? '<span class="joiner">' . ($r['match_mode'] === 'any' ? lang('Rules.or') : lang('Rules.and')) . '</span> ' : '' ?>
                                <span class="cond"><?= lang('Rules.field_' . $c['field']) ?> <em><?= lang('Rules.op_' . $c['operator']) ?></em> <code><?= esc($c['value']) ?></code></span>
                            <?php endforeach ?></td>
                        <td><?php foreach ($r['actions'] as $a): ?><span class="<?= rk_tag_class($a['value']) ?>"><?= esc(rk_tag_label($a['value'])) ?></span><?php endforeach ?></td>
                        <td class="actions">
                            <?= $post('rules/' . $r['id'] . '/move/up', '↑', '', null) ?><?= $post('rules/' . $r['id'] . '/move/down', '↓', '', null) ?>
                            <?= $post('rules/' . $r['id'] . '/toggle', $r['enabled'] ? lang('Rules.disable') : lang('Rules.enable')) ?>
                            <?= $post('rules/' . $r['id'] . '/delete', lang('Rules.delete'), '', lang('Rules.confirmDelete')) ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>
</div>
<?= $this->endSection() ?>
