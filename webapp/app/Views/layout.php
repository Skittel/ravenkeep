<!DOCTYPE html>
<html lang="<?= esc(service('request')->getLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? lang('Ui.appName')) ?></title>
    <link rel="stylesheet" href="<?= base_url('assets/app.css') ?>">
    <script src="<?= base_url('assets/app.js') ?>" defer></script>
</head>
<body>
<?= $this->renderSection('content') ?>
</body>
</html>
