<?php
/**
 * @var list<array> $identities
 * @var list<string> $domains
 * @var array{username: string, password: string, sync: string, reset?: bool}|null $created
 * @var array<string, string> $lastSeen
 * @var list<array> $devices all devices
 * @var array<int, list<string>> $identityTags fixed tags per login (E-024)
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
            <?php if (! empty($created['id'])): ?><p><a class="button button-primary" href="<?= site_url('devices/' . (int) $created['id']) ?>"><?= lang('Devices.editTags') ?></a></p><?php endif ?>
        </section>
    <?php endif ?>

    <section class="card">
        <h1><?= lang('Devices.title') ?></h1>
        <p class="hint"><?= lang('Devices.hint') ?></p>
        <p class="notice"><?= lang('Devices.mailrunnerPending') ?></p>
        <?= form_open('devices', ['class' => 'rule-form']) ?>
            <label><?= lang('Devices.username') ?><input type="text" name="local_part" required maxlength="64" placeholder="pm10_ipmi_hq1_acme" class="code-input"></label>
            <label><?= lang('Senders.domain') ?><select name="domain">
                <?php foreach ($domains as $d): ?><option value="<?= esc($d, 'attr') ?>" <?= str_starts_with($d, 'customer.') ? 'selected' : '' ?>><?= esc($d) ?></option><?php endforeach ?>
            </select></label>
            <label><?= lang('Customers.device') ?>
                <select name="device_id">
                    <option value=""><?= lang('Devices.deviceLater') ?></option>
                    <?php $pre = (int) service('request')->getGet('device'); ?>
                    <?php foreach ($devices as $fd): ?><option value="<?= (int) $fd['id'] ?>" <?= (int) $fd['id'] === $pre ? 'selected' : '' ?>><?= esc($fd['customer_name'] . ' – ' . $fd['name'] . ' (' . $fd['path'] . ')') ?></option><?php endforeach ?>
                </select></label>
            <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255"></label>
            <button type="submit" class="button button-primary"><?= lang('Devices.create') ?></button>
        <?= form_close() ?>
        <p class="hint"><?= lang('Devices.usernameHint') ?></p>

        <?php if ($identities === []): ?>
            <p class="muted"><?= lang('Devices.none') ?></p>
        <?php else: ?>
            <table class="grid">
                <thead><tr><th><?= lang('Devices.username') ?></th><th><?= lang('Customers.device') ?></th><th><?= lang('Customers.tags') ?></th><th><?= lang('Senders.note') ?></th><th><?= lang('Devices.lastMail') ?></th>
                    <th><?= lang('Devices.passwordSet') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($identities as $i): ?>
                    <tr class="<?= $i['enabled'] ? '' : 'disabled' ?>">
                        <td><code><?= esc($i['username']) ?></code><?php if (! $i['enabled']): ?> <span class="badge badge-blocked"><?= lang('Devices.disabled') ?></span><?php endif ?></td>
                        <td><?= $i['device_name'] ? esc($i['customer_name'] . ' – ' . $i['device_name']) : '<span class="muted">–</span>' ?></td>
                        <td><?php foreach ($identityTags[(int) $i['id']] ?? [] as $t): ?><span class="<?= rk_tag_class($t) ?>"><?= esc(rk_tag_label($t)) ?></span><?php endforeach ?></td>
                        <td><?= esc($i['note'] ?? '') ?></td>
                        <td><?php if (isset($lastSeen[$i['username']])): ?><a href="<?= site_url('/') ?>?range=all&amp;q=<?= urlencode($i['username']) ?>"><?= esc(rk_time($lastSeen[$i['username']], 'd.m.Y H:i')) ?></a><?php else: ?><span class="muted"><?= lang('Devices.never') ?></span><?php endif ?></td>
                        <td><?= esc(rk_time($i['password_set_at'], 'd.m.Y H:i')) ?></td>
                        <td class="actions">
                            <a class="button write-only" href="<?= site_url('devices/' . $i['id']) ?>"><?= lang('Devices.editTags') ?></a>
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
