<?php
/**
 * @var list<array> $domains
 * @var list<array> $networks
 * @var list<array> $profiles
 */
$this->setData(['active' => 'settings', 'title' => lang('Devices.navSettings')]);
$del = static fn (string $url, string $confirm): string => form_open($url, ['class' => 'inline', 'data-confirm' => $confirm])
    . '<button type="submit" class="button">' . lang('Senders.delete') . '</button>' . form_close();
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page">
    <section class="card">
        <h1><?= lang('Customers.profilesTitle') ?></h1>
        <p class="hint"><?= lang('Customers.profilesHint') ?></p>
        <?= form_open('settings/profiles', ['class' => 'rule-form']) ?>
            <label><?= lang('Customers.name') ?><input type="text" name="name" required maxlength="64" placeholder="<?= esc(lang('Customers.profilePlaceholder'), 'attr') ?>"></label>
            <label><?= lang('Customers.hours') ?><input type="number" name="hours" required min="1" max="8760" value="24"></label>
            <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255"></label>
            <button type="submit" class="button button-primary"><?= lang('Devices.add') ?></button>
        <?= form_close() ?>
        <?php if ($profiles !== []): ?>
        <table class="grid">
            <thead><tr><th><?= lang('Customers.name') ?></th><th><?= lang('Customers.hours') ?></th><th class="num"><?= lang('Customers.devices') ?></th><th><?= lang('Senders.note') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($profiles as $p): ?>
                <tr><td><?= esc($p['name']) ?></td>
                    <td><?= form_open('settings/profiles/' . $p['id'], ['class' => 'inline']) ?><input type="number" name="hours" min="1" max="8760" value="<?= (int) $p['hours'] ?>" class="hours-input"> h <button type="submit" class="button"><?= lang('Rules.save') ?></button><?= form_close() ?>
                        <span class="role-ro-only"><?= (int) $p['hours'] ?> h</span></td>
                    <td class="num"><?= (int) $p['devices'] ?></td><td><?= esc($p['note'] ?? '') ?></td>
                    <td><?= $del('settings/profiles/' . $p['id'] . '/delete', lang('Customers.confirmDeleteProfile')) ?></td></tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?php endif ?>
    </section>

    <section class="card">
        <h1><?= lang('Devices.domainsTitle') ?></h1>
        <p class="hint"><?= lang('Devices.domainsHint') ?></p>
        <?= form_open('settings/domains', ['class' => 'rule-form']) ?>
            <label><?= lang('Senders.domain') ?><input type="text" name="domain" required maxlength="253" placeholder="customer.example.org"></label>
            <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255"></label>
            <button type="submit" class="button button-primary"><?= lang('Devices.add') ?></button>
        <?= form_close() ?>
        <table class="grid">
            <thead><tr><th><?= lang('Senders.domain') ?></th><th><?= lang('Senders.note') ?></th><th><?= lang('Senders.created') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($domains as $d): ?>
                <tr><td><code><?= esc($d['domain']) ?></code></td><td><?= esc($d['note'] ?? '') ?></td><td><?= esc(rk_time($d['created_at'], 'd.m.Y H:i')) ?></td>
                    <td><?= $del('settings/domains/' . $d['id'] . '/delete', lang('Devices.confirmDeleteDomain')) ?></td></tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h1><?= lang('Devices.networksTitle') ?></h1>
        <p class="hint"><?= lang('Devices.networksHint') ?></p>
        <?= form_open('settings/networks', ['class' => 'rule-form']) ?>
            <label><?= lang('Devices.network') ?><input type="text" name="network" required maxlength="64" placeholder="192.0.2.10 / 2001:db8::/64"></label>
            <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255"></label>
            <button type="submit" class="button button-primary"><?= lang('Devices.add') ?></button>
        <?= form_close() ?>
        <table class="grid">
            <thead><tr><th><?= lang('Devices.network') ?></th><th><?= lang('Senders.note') ?></th><th><?= lang('Senders.created') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($networks as $n): ?>
                <tr><td><code><?= esc($n['network']) ?></code></td><td><?= esc($n['note'] ?? '') ?></td><td><?= esc(rk_time($n['created_at'], 'd.m.Y H:i')) ?></td>
                    <td><?= $del('settings/networks/' . $n['id'] . '/delete', lang('Devices.confirmDeleteNetwork')) ?></td></tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </section>
</div>
<?= $this->endSection() ?>
