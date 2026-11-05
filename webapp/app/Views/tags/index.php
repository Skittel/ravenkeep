<?php
/**
 * @var list<array> $tags
 * @var int $edit
 * @var int $total
 * @var list<string> $types
 * @var string $type
 * @var string $q
 */
$this->setData(['active' => 'tags', 'title' => lang('Customers.navTags')]);
$post = static fn (string $url, string $label, ?string $confirm = null): string => form_open($url, array_filter(['class' => 'inline', 'data-confirm' => $confirm]))
    . '<button type="submit" class="button">' . $label . '</button>' . form_close();
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page">
    <section class="card">
        <h1><?= lang('Customers.navTags') ?></h1>
        <p class="hint"><?= lang('Customers.tagsHint') ?></p>
        <?= form_open('tags', ['class' => 'rule-form']) ?>
            <label><?= lang('Customers.tag') ?><input type="text" name="name" required maxlength="64" placeholder="typ:nas, hersteller:qnap, wichtig"></label>
            <button type="submit" class="button button-primary"><?= lang('Customers.createTag') ?></button>
        <?= form_close() ?>
        <form class="tag-filter" method="get" action="<?= site_url('tags') ?>">
            <input type="search" name="q" value="<?= esc($q, 'attr') ?>" placeholder="<?= esc(lang('Customers.tagSearch'), 'attr') ?>" autocomplete="off">
            <select name="type" data-autosubmit>
                <option value=""><?= lang('Customers.allTypes') ?></option>
                <?php foreach ($types as $tp): ?>
                    <option value="<?= esc($tp === '' ? '-' : $tp, 'attr') ?>" <?= $type === ($tp === '' ? '-' : $tp) ? 'selected' : '' ?>><?= esc($tp === '' ? lang('Customers.noType') : $tp) ?></option>
                <?php endforeach ?>
            </select>
            <button type="submit" class="button"><?= lang('Customers.filter') ?></button>
            <?php if ($q !== '' || $type !== ''): ?><a class="button" href="<?= site_url('tags') ?>"><?= lang('Customers.resetFilter') ?></a><?php endif ?>
            <span class="muted"><?= lang('Customers.tagCount', [count($tags), $total]) ?></span>
        </form>
        <table class="grid">
            <thead><tr><th><?= lang('Customers.tag') ?></th><th class="num"><?= lang('Senders.messages') ?></th><th class="num"><?= lang('Customers.devicesAndSenders') ?></th>
                <th class="num"><?= lang('Rules.title') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tags as $t): ?>
                <tr>
                    <td><?php if ($edit === $t['id'] && ! $t['reserved']): ?>
                            <?= form_open('tags/' . $t['id'], ['class' => 'inline']) ?><input type="text" name="name" value="<?= esc($t['name'], 'attr') ?>" maxlength="64" required>
                            <button type="submit" class="button button-primary"><?= lang('Rules.save') ?></button> <a class="button" href="<?= site_url('tags') ?>"><?= lang('Rules.cancel') ?></a><?= form_close() ?>
                        <?php else: ?>
                            <a class="<?= rk_tag_class($t['name']) ?>" href="<?= site_url('/') ?>?tag%5B0%5D=<?= urlencode($t['name']) ?>"><?= esc(rk_tag_label($t['name'])) ?></a>
                            <?php if ($t['reserved']): ?><span class="muted"><?= lang('Customers.automatic') ?></span><?php endif ?>
                        <?php endif ?></td>
                    <td class="num"><?= $t['messages'] ?></td>
                    <td class="num"><?= $t['devices'] ?: '' ?></td>
                    <td class="num"><?= $t['rules'] ?: '' ?></td>
                    <td class="actions"><?php if (! $t['reserved'] && $edit !== $t['id']): ?>
                        <a class="button write-only" href="<?= site_url('tags') ?>?edit=<?= $t['id'] ?>"><?= lang('Customers.rename') ?></a>
                        <?= $t['rules'] === 0 ? $post('tags/' . $t['id'] . '/delete', lang('Senders.delete'), lang('Customers.confirmDeleteTag')) : '' ?>
                    <?php endif ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </section>
</div>
<?= $this->endSection() ?>
