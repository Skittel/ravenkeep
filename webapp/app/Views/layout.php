<?php
/**
 * Page shell: top bar with navigation and search, flash messages, page content.
 *
 * @var string|null $title
 */
$active = $active ?? 'messages';
$search = $search ?? [];
?>
<!DOCTYPE html>
<html lang="<?= esc(service('request')->getLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc(($title ?? null) ? $title . ' – ' . lang('Ui.appName') : lang('Ui.appName')) ?></title>
    <link rel="stylesheet" href="<?= base_url('assets/app.css') ?>">
    <script src="<?= base_url('assets/app.js') ?>" defer></script>
</head>
<body>
<div class="app <?= $active === 'messages' ? 'app-mail' : 'app-page' ?>">
    <header class="topbar">
        <a class="brand" href="<?= site_url('/') ?>"><?= lang('Ui.appName') ?></a>
        <nav class="mainnav">
            <a href="<?= site_url('/') ?>" class="<?= $active === 'messages' ? 'active' : '' ?>"><?= lang('Senders.navMessages') ?></a>
            <a href="<?= site_url('senders') ?>" class="<?= $active === 'senders' ? 'active' : '' ?>"><?= lang('Senders.nav') ?></a>
            <a href="<?= site_url('nodes') ?>" class="<?= $active === 'nodes' ? 'active' : '' ?>"><?= lang('Senders.navNodes') ?></a>
        </nav>
        <form class="search" method="get" action="<?= site_url('/') ?>">
            <?php if (! empty($search['status'])): ?>
                <input type="hidden" name="status" value="<?= esc($search['status'], 'attr') ?>">
            <?php endif ?>
            <input type="search" name="q" value="<?= esc($search['q'] ?? '', 'attr') ?>" placeholder="<?= esc(lang('Ui.search'), 'attr') ?>" autocomplete="off">
        </form>
    </header>
    <?php foreach (['success', 'error'] as $type): ?>
        <?php if ($msg = session()->getFlashdata($type)): ?>
            <div class="flash flash-<?= $type ?>" role="status"><?= esc($msg) ?></div>
        <?php endif ?>
    <?php endforeach ?>
    <?= $this->renderSection('content') ?>
</div>
</body>
</html>
