<?php
/**
 * @var array $filter
 * @var list<array> $messages
 * @var array<string,int> $counts
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var string|null $pane
 * @var int $selected
 * @var array<int, list<array>> $tags
 * @var array<string, array<string, int>> $tagTree
 */
$statusLabels = [
    'accepted' => lang('Ui.statusAccepted'),
    'rejected' => lang('Ui.statusRejected'),
    'pending'  => lang('Ui.statusPending'),
    'blocked'  => lang('Ui.statusBlocked'),
];
?>
<?php $this->setData(['active' => 'messages', 'search' => $filter, 'hasSelection' => $pane !== null]); ?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
    <nav class="sidebar">
        <form method="get" action="<?= site_url('/') ?>" class="range-form">
            <?php foreach (['status', 'q'] as $key): ?><?php if (! empty($filter[$key])): ?><input type="hidden" name="<?= $key ?>" value="<?= esc($filter[$key], 'attr') ?>"><?php endif ?><?php endforeach ?>
            <?php foreach ($filter['tag'] ?? [] as $t): ?><input type="hidden" name="tag[]" value="<?= esc($t, 'attr') ?>"><?php endforeach ?>
            <label><?= lang('Ui.range') ?>
                <select name="range" data-autosubmit>
                    <?php foreach (array_keys(App\Models\MessageModel::RANGES) as $r): ?>
                        <option value="<?= $r ?>"<?= ($filter['range'] ?? App\Models\MessageModel::DEFAULT_RANGE) === $r ? ' selected' : '' ?>><?= lang('Ui.range_' . $r) ?></option>
                    <?php endforeach ?>
                </select></label>
            <noscript><button type="submit" class="button"><?= lang('Ui.apply') ?></button></noscript>
        </form>
        <h2><?= lang('Ui.status') ?></h2>
        <ul class="filters">
            <li><a href="<?= rk_query($filter, ['status' => null, 'page' => null]) ?>" class="<?= empty($filter['status']) ? 'active' : '' ?>">
                <?= lang('Ui.all') ?> <span class="count"><?= array_sum($counts) ?></span></a></li>
            <?php foreach ($statusLabels as $status => $label): ?>
                <li><a href="<?= rk_query($filter, ['status' => $status, 'page' => null]) ?>" class="<?= ($filter['status'] ?? '') === $status ? 'active' : '' ?>">
                    <span class="dot dot-<?= $status ?>"></span><?= esc($label) ?> <span class="count"><?= $counts[$status] ?></span></a></li>
            <?php endforeach ?>
        </ul>
        <h2><?= lang('Rules.tagsTitle') ?></h2>
        <?php if ($tagTree === []): ?>
            <p class="hint"><?= lang('Rules.noTagsYet') ?></p>
        <?php endif ?>
        <ul class="tree">
            <?php foreach ($tagTree as $type => $names): ?>
                <li>
                    <?php if ($type !== ''): ?>
                        <?php $typeTag = $type . ':*'; ?>
                        <a href="<?= rk_query($filter, rk_tag_toggle($filter, $typeTag)) ?>" class="tree-type <?= in_array($typeTag, $filter['tag'] ?? [], true) ? 'active' : '' ?>"><?= esc($type) ?> <span class="count"><?= array_sum($names) ?></span></a>
                    <?php endif ?>
                    <ul>
                        <?php foreach ($names as $name => $n): ?>
                            <li><a href="<?= rk_query($filter, rk_tag_toggle($filter, $name)) ?>" class="<?= in_array($name, $filter['tag'] ?? [], true) ? 'active' : '' ?>">
                                <span class="<?= rk_tag_class($name) ?> tag-dot"></span><?= esc(str_contains($name, ':') ? explode(':', $name, 2)[1] : $name) ?> <span class="count"><?= $n ?></span></a></li>
                        <?php endforeach ?>
                    </ul>
                </li>
            <?php endforeach ?>
        </ul>
    </nav>

    <main class="main">
        <section class="list" id="message-list">
            <?php if (! empty($filter['tag'])): ?>
                <div class="active-filters"><?= lang('Rules.filteredBy') ?>
                    <?php foreach ($filter['tag'] as $tag): ?>
                        <a class="<?= rk_tag_class($tag) ?>" href="<?= rk_query($filter, rk_tag_toggle($filter, $tag)) ?>" title="<?= esc(lang('Rules.removeFilter'), 'attr') ?>"><?= esc(rk_tag_label($tag)) ?> ×</a>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
            <table>
                <thead>
                <tr>
                    <th class="col-time"><?= lang('Ui.received') ?></th>
                    <th class="col-status"></th>
                    <th class="col-from"><?= lang('Ui.from') ?></th>
                    <th class="col-to"><?= lang('Ui.to') ?></th>
                    <th><?= lang('Ui.subject') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($messages as $m): ?>
                    <tr data-id="<?= $m['id'] ?>" class="<?= (int) $m['id'] === $selected ? 'selected' : '' ?> status-<?= esc($m['status'], 'attr') ?>" tabindex="0">
                        <td class="col-time" title="<?= esc(rk_time($m['received_at']), 'attr') ?>"><?= esc(rk_list_time($m['received_at'])) ?></td>
                        <td class="col-status"><span class="dot dot-<?= esc($m['status'], 'attr') ?>" title="<?= esc($statusLabels[$m['status']] ?? $m['status'], 'attr') ?>"></span></td>
                        <td class="col-from"><?= esc($m['mode'] === 'auth' ? $m['sasl_username'] : ($m['header_from'] ?: $m['envelope_sender'])) ?></td>
                        <td class="col-to"><?= esc($m['envelope_recipient']) ?></td>
                        <td class="col-subject"><?php foreach ($tags[(int) $m['id']] ?? [] as $tg): ?><span class="<?= rk_tag_class($tg['name']) ?>"><?= esc(rk_tag_label($tg['name'])) ?></span><?php endforeach ?><a href="<?= rk_query($filter, ['id' => $m['id']]) ?>"><?= esc($m['subject'] ?? '') ?: '<em>' . lang('Ui.noSubject') . '</em>' ?></a></td>
                    </tr>
                <?php endforeach ?>
                <?php if ($messages === []): ?>
                    <tr><td colspan="5" class="empty"><?= lang('Ui.noMessages') ?></td></tr>
                <?php endif ?>
                </tbody>
            </table>
            <div class="pager">
                <span><?= lang('Ui.messages', [$total]) ?> · <?= lang('Ui.page', [$page, $pages]) ?></span>
                <?php if ($page > 1): ?><a href="<?= rk_query($filter, ['page' => $page - 1]) ?>"><?= lang('Ui.newer') ?></a><?php endif ?>
                <?php if ($page < $pages): ?><a href="<?= rk_query($filter, ['page' => $page + 1]) ?>"><?= lang('Ui.older') ?></a><?php endif ?>
            </div>
        </section>

        <section class="pane" id="reading-pane">
            <a class="back-link" href="<?= rk_query($filter, ['id' => null]) ?>">‹ <?= lang('Ui.backToList') ?></a>
            <?= $pane ?? '<p class="placeholder">' . lang('Ui.selectMessage') . '</p>' ?>
        </section>
    </main>
<?= $this->endSection() ?>
