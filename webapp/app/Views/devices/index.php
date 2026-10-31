<?php
/**
 * @var list<array> $identities
 * @var list<string> $domains
 * @var array{username: string, password: string, sync: string, reset?: bool}|null $created
 * @var array<string, string> $lastSeen
 */
$this->setData(['active' => 'devices', 'title' => lang('Devices.title')]);
$post = static fn (string $url, string $label, ?string $confirm = null): string => form_open($url, array_filter(['class' => 'inline', 'data-confirm' => $confirm]))
    . '<button type="submit" class="button">' . $label . '</button>' . form_close();
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page">
    <?php if ($created !== null): ?>
        <section class="card credentials">
            <h1><?= empty($created['reset']) ? lang('Devices.createdTitle') : lang('Devices.resetTitle') ?></h1>
            <p class="notice"><?= lang('Devices.showOnce') ?></p>
            <table class="kv">
                <tr><th><?= lang('Devices.server') ?></th><td><code><?= esc(config('Ravenkeep')->smtpHost) ?></code></td></tr>
                <tr><th><?= lang('Devices.port') ?></th><td><code>587</code> (STARTTLS) <?= lang('Devices.or') ?> <code>465</code> (SSL/TLS)</td></tr>
                <tr><th><?= lang('Devices.username') ?></th><td><code class="select"><?= esc($created['username']) ?></code></td></tr>
                <tr><th><?= lang('Devices.password') ?></th><td><code class="select secret"><?= esc($created['password']) ?></code></td></tr>
                <tr><th><?= lang('Devices.sender') ?></th><td><code><?= esc($created['username']) ?></code> <span class="muted"><?= lang('Devices.senderHint') ?></span></td></tr>
                <tr><th><?= lang('Devices.recipient') ?></th><td><code>status@<?= esc(explode('@', $created['username'])[1]) ?></code> <span class="muted"><?= lang('Devices.recipientHint') ?></span></td></tr>
            </table>
            <p class="muted"><?= esc($created['sync']) ?></p>
        </section>
    <?php endif ?>

    <section class="card">
        <h1><?= lang('Devices.title') ?></h1>
        <p class="hint"><?= lang('Devices.hint') ?></p>
        <?= form_open('devices', ['class' => 'rule-form']) ?>
            <label><?= lang('Devices.username') ?>
                <span class="address-input"><input type="text" name="local_part" required maxlength="64" pattern="[A-Za-z0-9._+\-]+" placeholder="<?= esc(lang('Devices.localPartHint'), 'attr') ?>">@<select name="domain">
                    <?php foreach ($domains as $d): ?><option value="<?= esc($d, 'attr') ?>" <?= str_starts_with($d, 'customer.') ? 'selected' : '' ?>><?= esc($d) ?></option><?php endforeach ?>
                </select></span></label>
            <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255" placeholder="<?= esc(lang('Devices.notePlaceholder'), 'attr') ?>"></label>
            <button type="submit" class="button button-primary"><?= lang('Devices.create') ?></button>
        <?= form_close() ?>

        <?php if ($identities === []): ?>
            <p class="muted"><?= lang('Devices.none') ?></p>
        <?php else: ?>
            <table class="grid">
                <thead><tr><th><?= lang('Devices.username') ?></th><th><?= lang('Senders.note') ?></th><th><?= lang('Devices.lastMail') ?></th>
                    <th><?= lang('Devices.passwordSet') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($identities as $i): ?>
                    <tr class="<?= $i['enabled'] ? '' : 'disabled' ?>">
                        <td><code><?= esc($i['username']) ?></code><?php if (! $i['enabled']): ?> <span class="badge badge-blocked"><?= lang('Devices.disabled') ?></span><?php endif ?></td>
                        <td><?= esc($i['note'] ?? '') ?></td>
                        <td><?php if (isset($lastSeen[$i['username']])): ?><a href="<?= site_url('/') ?>?q=<?= urlencode($i['username']) ?>"><?= esc(rk_time($lastSeen[$i['username']], 'd.m.Y H:i')) ?></a><?php else: ?><span class="muted"><?= lang('Devices.never') ?></span><?php endif ?></td>
                        <td><?= esc(rk_time($i['password_set_at'], 'd.m.Y H:i')) ?></td>
                        <td class="actions">
                            <?= $post('devices/' . $i['id'] . '/password', lang('Devices.newPassword'), lang('Devices.confirmPassword')) ?>
                            <?= $post('devices/' . $i['id'] . '/toggle', $i['enabled'] ? lang('Rules.disable') : lang('Rules.enable')) ?>
                            <?= $post('devices/' . $i['id'] . '/delete', lang('Senders.delete'), lang('Devices.confirmDelete')) ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>
</div>
<?= $this->endSection() ?>
