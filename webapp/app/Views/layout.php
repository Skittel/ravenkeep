<?php
/**
 * Page shell: top bar with navigation and search, flash messages, page content.
 *
 * @var string|null $title
 */
$active = $active ?? 'messages';
$search = $search ?? [];
$user   = session('user');
?>
<!DOCTYPE html>
<html lang="<?= esc(service('request')->getLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc(($title ?? null) ? $title . ' – ' . lang('Ui.appName') : lang('Ui.appName')) ?></title>
    <link rel="icon" href="<?= base_url('favicon.ico') ?>" sizes="any">
    <link rel="apple-touch-icon" href="<?= base_url('assets/icon.png') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/app.css') ?>">
    <script src="<?= base_url('assets/app.js') ?>" defer></script>
</head>
<body class="<?= ($user['role'] ?? '') === 'rw' ? 'role-rw' : 'role-ro' ?>">
<div class="app <?= $active === 'messages' ? 'app-mail' : 'app-page' ?>">
    <header class="topbar">
        <a class="brand" href="<?= site_url('/') ?>"><img src="<?= base_url('assets/icon.png') ?>" alt="" class="brand-icon"><?= lang('Ui.appName') ?></a>
        <nav class="mainnav">
            <?php foreach ([
                'dashboard' => ['dashboard', lang('Customers.navDashboard')],
                'messages'  => ['/', lang('Senders.navMessages')],
                'customers' => ['customers', lang('Customers.nav')],
                'rules'     => ['rules', lang('Rules.nav')],
                'tags'      => ['tags', lang('Customers.navTags')],
                'senders'   => ['senders', lang('Senders.nav')],
                'devices'   => ['devices', lang('Devices.nav')],
                'nodes'     => ['nodes', lang('Senders.navNodes')],
                'settings'  => ['settings', lang('Devices.navSettings')],
            ] as $key => [$url, $label]): ?>
                <a href="<?= site_url($url) ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach ?>
        </nav>
        <form class="search" method="get" action="<?= site_url('/') ?>">
            <?php if (! empty($search['status'])): ?>
                <input type="hidden" name="status" value="<?= esc($search['status'], 'attr') ?>">
            <?php endif ?>
            <input type="search" name="q" value="<?= esc($search['q'] ?? '', 'attr') ?>" placeholder="<?= esc(lang('Ui.search'), 'attr') ?>" autocomplete="off">
        </form>
        <?php if ($user): ?>
            <div class="userbox" title="<?= esc($user['username'], 'attr') ?>">
                <span><?= esc($user['name']) ?></span>
                <span class="role"><?= $user['role'] === 'rw' ? lang('Auth.roleRw') : lang('Auth.roleRo') ?></span>
                <a href="<?= site_url('auth/logout') ?>"><?= lang('Auth.signOut') ?></a>
            </div>
        <?php endif ?>
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
