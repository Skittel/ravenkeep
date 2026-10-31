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
 */
$statusLabels = [
    'accepted' => lang('Ui.statusAccepted'),
    'rejected' => lang('Ui.statusRejected'),
    'pending'  => lang('Ui.statusPending'),
    'blocked'  => lang('Ui.statusBlocked'),
];
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="app">
    <header class="topbar">
        <a class="brand" href="<?= site_url('/') ?>"><?= lang('Ui.appName') ?></a>
        <form class="search" method="get" action="<?= site_url('/') ?>">
            <?php if (! empty($filter['status'])): ?>
                <input type="hidden" name="status" value="<?= esc($filter['status'], 'attr') ?>">
            <?php endif ?>
            <input type="search" name="q" value="<?= esc($filter['q'] ?? '', 'attr') ?>" placeholder="<?= esc(lang('Ui.search'), 'attr') ?>" autocomplete="off">
        </form>
    </header>

    <nav class="sidebar">
        <h2><?= lang('Ui.status') ?></h2>
        <ul class="filters">
            <li><a href="<?= rk_query($filter, ['status' => null, 'page' => null]) ?>" class="<?= empty($filter['status']) ? 'active' : '' ?>">
                <?= lang('Ui.all') ?> <span class="count"><?= array_sum($counts) ?></span></a></li>
            <?php foreach ($statusLabels as $status => $label): ?>
                <li><a href="<?= rk_query($filter, ['status' => $status, 'page' => null]) ?>" class="<?= ($filter['status'] ?? '') === $status ? 'active' : '' ?>">
                    <span class="dot dot-<?= $status ?>"></span><?= esc($label) ?> <span class="count"><?= $counts[$status] ?></span></a></li>
            <?php endforeach ?>
        </ul>
        <h2><?= lang('Ui.filters') ?></h2>
        <p class="hint"><?= lang('Ui.filterTreeHint') ?></p>
    </nav>

    <main class="main">
        <section class="list" id="message-list">
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
                        <td class="col-subject"><a href="<?= rk_query($filter, ['id' => $m['id']]) ?>"><?= esc($m['subject'] ?? '') ?: '<em>' . lang('Ui.noSubject') . '</em>' ?></a></td>
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
            <?= $pane ?? '<p class="placeholder">' . lang('Ui.selectMessage') . '</p>' ?>
        </section>
    </main>
</div>
<?= $this->endSection() ?>
