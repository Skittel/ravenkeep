<?php
/**
 * @var list<array> $tags
 * @var int $edit
 * @var int $total
 * @var list<string> $types
 * @var string $type
 * @var string $q
 * @var list<array> $tagTypes managed tag types (E-023)
 * @var int $editType
 */
$this->setData(['active' => 'tags', 'title' => lang('Customers.navTags')]);
$post = static fn (string $url, string $label, ?string $confirm = null): string => form_open($url, array_filter(['class' => 'inline', 'data-confirm' => $confirm]))
    . '<button type="submit" class="button">' . $label . '</button>' . form_close();
$typeNames = array_column($tagTypes, 'name');
$typeSelect = static function (string $selected = '') use ($typeNames): string {
    $html = '<select name="type" required><option value="">' . esc(lang('Customers.chooseType')) . '</option>';
    foreach ($typeNames as $t) {
        $html .= '<option value="' . esc($t, 'attr') . '"' . ($t === $selected ? ' selected' : '') . '>' . esc($t) . '</option>';
    }

    return $html . '</select>';
};
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page">
    <section class="card">
        <h1><?= lang('Customers.tagTypes') ?></h1>
        <p class="hint"><?= lang('Customers.tagTypesHint') ?></p>
        <?= form_open('tags/types', ['class' => 'rule-form']) ?>
            <label><?= lang('Customers.tagType') ?><input type="text" name="name" required maxlength="32" placeholder="hersteller" class="code-input"></label>
            <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255"></label>
            <button type="submit" class="button button-primary"><?= lang('Customers.createType') ?></button>
        <?= form_close() ?>
        <table class="grid">
            <thead><tr><th><?= lang('Customers.tagType') ?></th><th><?= lang('Senders.note') ?></th><th class="num"><?= lang('Customers.navTags') ?></th><th class="num"><?= lang('Rules.title') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tagTypes as $tt): ?>
                <?php if ($editType === $tt['id'] && ! $tt['system']): ?>
                    <tr class="editing"><td colspan="5">
                        <?= form_open('tags/types/' . $tt['id'], ['class' => 'rule-form']) ?>
                            <label><?= lang('Customers.tagType') ?><input type="text" name="name" required maxlength="32" value="<?= esc($tt['name'], 'attr') ?>" class="code-input"></label>
                            <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255" value="<?= esc($tt['note'] ?? '', 'attr') ?>"></label>
                            <button type="submit" class="button button-primary"><?= lang('Rules.save') ?></button>
                            <a class="button" href="<?= site_url('tags') ?>"><?= lang('Rules.cancel') ?></a>
                        <?= form_close() ?>
                        <p class="hint"><?= lang('Customers.renameTypeHint') ?></p>
                    </td></tr>
                    <?php continue ?>
                <?php endif ?>
                <tr>
                    <td><code><?= esc($tt['name']) ?></code><?php if ($tt['system']): ?> <span class="muted"><?= lang('Customers.automatic') ?></span><?php endif ?></td>
                    <td><?= esc($tt['note'] ?? '') ?></td>
                    <td class="num"><a href="<?= site_url('tags') ?>?type=<?= urlencode($tt['name']) ?>"><?= $tt['tags'] ?></a></td>
                    <td class="num"><?= $tt['rules'] ?: '' ?></td>
                    <td class="actions"><?php if (! $tt['system']): ?>
                        <a class="button write-only" href="<?= site_url('tags') ?>?edit_type=<?= $tt['id'] ?>"><?= lang('Customers.edit') ?></a>
                        <?= $tt['tags'] === 0 && $tt['rules'] === 0 ? $post('tags/types/' . $tt['id'] . '/delete', lang('Senders.delete'), lang('Customers.confirmDeleteType')) : '' ?>
                    <?php endif ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h1><?= lang('Customers.navTags') ?></h1>
        <p class="hint"><?= lang('Customers.tagsHint') ?></p>
        <?= form_open('tags', ['class' => 'rule-form']) ?>
            <label><?= lang('Customers.tagType') ?><?= $typeSelect() ?></label>
            <label><?= lang('Customers.tagValue') ?><input type="text" name="value" required maxlength="63" placeholder="nas, qnap"></label>
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
                            <?= form_open('tags/' . $t['id'], ['class' => 'inline']) ?><?= $typeSelect($t['type']) ?> <input type="text" name="value" value="<?= esc(App\Libraries\Rules\TagName::value($t['name']), 'attr') ?>" maxlength="63" required>
                            <button type="submit" class="button button-primary"><?= lang('Rules.save') ?></button> <a class="button" href="<?= site_url('tags') ?>"><?= lang('Rules.cancel') ?></a><?= form_close() ?>
                        <?php else: ?>
                            <a class="<?= rk_tag_class($t['name']) ?>" href="<?= site_url('/') ?>?range=all&amp;tag%5B0%5D=<?= urlencode($t['name']) ?>"><?= esc(rk_tag_label($t['name'])) ?></a>
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
