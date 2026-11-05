<?php
/**
 * @var list<array> $identities
 * @var list<string> $domains
 * @var array{id: int, username: string, password: string, reset?: bool}|null $created
 * @var string $smtpHost SMTP server of the devices (MailRunner node)
 * @var array<string, string> $lastSeen
 * @var list<array> $devices all devices
 * @var list<string> $components known sources (source:<x>) as components of a login (E-025)
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
                <tr><th><?= lang('Devices.server') ?></th><td><code class="select"><?= esc($smtpHost) ?></code></td></tr>
                <tr><th><?= lang('Devices.port') ?></th><td><code>587</code> (STARTTLS) <?= lang('Devices.or') ?> <code>465</code> (SSL/TLS)<?= lang('Devices.port25') ?></td></tr>
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
        <p class="hint"><?= lang('Devices.serverHint', [esc($smtpHost)]) ?></p>
        <?php
        $domainSelect = static function () use ($domains): string {
            $html = '<select name="domain">';
            foreach ($domains as $d) {
                $html .= '<option value="' . esc($d, 'attr') . '"' . (str_starts_with($d, 'customer.') ? ' selected' : '') . '>' . esc($d) . '</option>';
            }

            return $html . '</select>';
        };
        $deviceOptions = static function (bool $optional) use ($devices): string {
            $pre  = (int) service('request')->getGet('device');
            $html = $optional ? '<option value="">' . esc(lang('Devices.deviceLater')) . '</option>' : '<option value="">' . esc(lang('Devices.chooseDevice')) . '</option>';
            foreach ($devices as $fd) {
                $html .= '<option value="' . (int) $fd['id'] . '"' . ((int) $fd['id'] === $pre ? ' selected' : '') . '>' . esc($fd['customer_name'] . ' – ' . $fd['name'] . ' (' . $fd['path'] . ')') . '</option>';
            }

            return $html;
        };
        $options = '<label>' . lang('Senders.note') . '<input type="text" name="note" maxlength="255"></label>'
            . '<label>' . lang('Devices.allowedNetworks') . '<input type="text" name="allowed_networks" maxlength="255" placeholder="' . esc(lang('Devices.allowedNetworksHint'), 'attr') . '"></label>'
            . '<label class="check"><input type="checkbox" name="plaintext_port25" value="1"> ' . lang('Devices.plaintext25') . '</label>';
        ?>
        <?= form_open('devices', ['class' => 'rule-form write-only']) ?>
            <input type="hidden" name="mode" value="device">
            <label><?= lang('Customers.device') ?><select name="device_id" required><?= $deviceOptions(false) ?></select></label>
            <label><?= lang('Devices.component') ?><select name="component" required>
                <option value=""><?= lang('Devices.chooseComponent') ?></option>
                <?php foreach ($components as $c): ?><option value="<?= esc($c, 'attr') ?>"><?= esc($c) ?></option><?php endforeach ?>
            </select></label>
            <label><?= lang('Senders.domain') ?><?= $domainSelect() ?></label>
            <?= $options ?>
            <button type="submit" class="button button-primary"><?= lang('Devices.create') ?></button>
        <?= form_close() ?>
        <p class="hint"><?= lang('Devices.usernameHint') ?></p>
        <details class="custom-login write-only" open>
            <summary><?= lang('Devices.customTitle') ?></summary>
            <p class="hint"><?= lang('Devices.customHint') ?></p>
            <?= form_open('devices', ['class' => 'rule-form']) ?>
                <input type="hidden" name="mode" value="custom">
                <label><?= lang('Devices.username') ?><input type="text" name="local_part" required maxlength="64" placeholder="backup-script" class="code-input"></label>
                <label><?= lang('Senders.domain') ?><?= $domainSelect() ?></label>
                <label><?= lang('Customers.device') ?><select name="device_id"><?= $deviceOptions(true) ?></select></label>
                <?= $options ?>
                <button type="submit" class="button"><?= lang('Devices.create') ?></button>
            <?= form_close() ?>
        </details>


        <?php if ($identities === []): ?>
            <p class="muted"><?= lang('Devices.none') ?></p>
        <?php else: ?>
            <table class="grid">
                <thead><tr><th><?= lang('Devices.username') ?></th><th><?= lang('Customers.device') ?></th><th><?= lang('Customers.tags') ?></th><th><?= lang('Senders.note') ?></th><th><?= lang('Devices.lastMail') ?></th>
                    <th><?= lang('Devices.passwordSet') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($identities as $i): ?>
                    <tr class="<?= $i['enabled'] ? '' : 'disabled' ?>">
                        <td><code><?= esc($i['username']) ?></code><?php if (! $i['enabled']): ?> <span class="badge badge-blocked"><?= lang('Devices.disabled') ?></span><?php endif ?>
                            <?php if ($i['credential_id'] === null): ?><br><span class="check-none"><?= lang('Devices.notOnMailrunner') ?></span><?php endif ?>
                            <?php if ($i['plaintext_port25']): ?><br><span class="muted"><?= lang('Devices.plaintext25Short') ?></span><?php endif ?></td>
                        <td><?= $i['device_name'] ? esc($i['customer_name'] . ' – ' . $i['device_name']) : '<span class="muted">–</span>' ?></td>
                        <td><?php foreach ($identityTags[(int) $i['id']] ?? [] as $t): ?><span class="<?= rk_tag_class($t) ?>"><?= esc(rk_tag_label($t)) ?></span><?php endforeach ?></td>
                        <td><?= esc($i['note'] ?? '') ?></td>
                        <td><?php if (isset($lastSeen[$i['username']])): ?><a href="<?= site_url('/') ?>?range=all&amp;q=<?= urlencode($i['username']) ?>"><?= esc(rk_time($lastSeen[$i['username']], 'd.m.Y H:i')) ?></a><?php else: ?><span class="muted"><?= lang('Devices.never') ?></span><?php endif ?></td>
                        <td><?= esc(rk_time($i['password_set_at'], 'd.m.Y H:i')) ?></td>
                        <td class="actions">
                            <a class="button write-only" href="<?= site_url('devices/' . $i['id']) ?>"><?= lang('Devices.editTags') ?></a>
                            <?php if ($i['credential_id'] === null): ?>
                                <?= $post('devices/' . $i['id'] . '/register', lang('Devices.register'), lang('Devices.confirmRegister')) ?>
                            <?php else: ?>
                                <?= form_open('devices/' . $i['id'] . '/password', ['class' => 'inline', 'data-confirm' => lang('Devices.confirmPassword')]) ?>
                                    <select name="grace" title="<?= esc(lang('Devices.graceHint'), 'attr') ?>"><option value="0"><?= lang('Devices.graceNone') ?></option><option value="86400"><?= lang('Devices.grace1d') ?></option><option value="604800"><?= lang('Devices.grace7d') ?></option></select>
                                    <button type="submit" class="button"><?= lang('Devices.newPassword') ?></button>
                                <?= form_close() ?>
                            <?php endif ?>
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
