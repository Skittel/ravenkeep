<?php
/**
 * Standalone page for sign-in messages (no navigation, user is not signed in).
 *
 * @var string $title
 * @var string $message
 * @var bool $login
 */
?>
<!DOCTYPE html>
<html lang="<?= esc(service('request')->getLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title) ?> – <?= lang('Ui.appName') ?></title>
    <link rel="icon" href="<?= base_url('favicon.ico') ?>" sizes="any">
    <link rel="apple-touch-icon" href="<?= base_url('assets/icon.png') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/app.css') ?>">
</head>
<body class="auth-page">
<main class="auth-box">
    <img src="<?= base_url('assets/logo.svg') ?>" alt="<?= esc(lang('Ui.appName'), 'attr') ?>" class="auth-logo">
    <h1><?= esc($title) ?></h1>
    <p><?= esc($message) ?></p>
    <?php if ($login): ?>
        <a class="button button-primary" href="<?= site_url('auth/login') ?>"><?= lang('Auth.signIn') ?></a>
    <?php endif ?>
</main>
</body>
</html>
