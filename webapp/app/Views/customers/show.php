<?php
/**
 * @var array $customer
 * @var list<array> $locations
 * @var list<array> $devices
 * @var array<int, list<string>> $tags
 * @var array<int, array> $monitoring
 * @var list<array> $profiles
 * @var list<string> $tagNames
 * @var int $edit device id in edit mode
 * @var int $editLocation location id in edit mode
 */
$this->setData(['active' => 'customers', 'title' => $customer['name']]);
$cid  = (int) $customer['id'];
$post = static fn (string $url, string $label, ?string $confirm = null): string => form_open($url, array_filter(['class' => 'inline', 'data-confirm' => $confirm]))
    . '<button type="submit" class="button">' . $label . '</button>' . form_close();
$profileSelect = static function (?int $selected) use ($profiles): string {
    $html = '<select name="timeout_profile_id"><option value="">' . esc(lang('Customers.noProfile')) . '</option>';
    foreach ($profiles as $p) {
        $html .= '<option value="' . (int) $p['id'] . '"' . ((int) $p['id'] === $selected ? ' selected' : '') . '>' . esc($p['name']) . ' (' . (int) $p['hours'] . ' h)</option>';
    }

    return $html . '</select>';
};
$autoTag = static fn (string $t) => in_array(explode(':', $t, 2)[0], ['kunde', 'standort', 'geraet'], true);
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<datalist id="tag-names"><?php foreach ($tagNames as $n): ?><option value="<?= esc($n, 'attr') ?>"><?php endforeach ?></datalist>
<div class="page">
    <section class="card">
        <div class="card-head">
            <h1><a href="<?= site_url('customers') ?>"><?= lang('Customers.title') ?></a> › <?= esc($customer['name']) ?></h1>
            <span class="<?= rk_tag_class('kunde:x') ?>"><?= esc(rk_tag_label('kunde:' . $customer['code'])) ?></span>
        </div>
        <?= form_open('customers/' . $cid, ['class' => 'rule-form']) ?>
            <label><?= lang('Customers.name') ?><input type="text" name="name" required maxlength="128" value="<?= esc($customer['name'], 'attr') ?>"></label>
            <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255" value="<?= esc($customer['note'] ?? '', 'attr') ?>"></label>
            <button type="submit" class="button"><?= lang('Rules.save') ?></button>
        <?= form_close() ?>
        <?php if ($locations === [] && $devices === []): ?>
            <?= $post('customers/' . $cid . '/delete', lang('Customers.deleteCustomer'), lang('Customers.confirmDeleteCustomer')) ?>
        <?php endif ?>
    </section>

    <section class="card">
        <h1><?= lang('Customers.locations') ?></h1>
        <?= form_open('customers/' . $cid . '/locations', ['class' => 'rule-form']) ?>
            <label><?= lang('Customers.name') ?><input type="text" name="name" required maxlength="128" placeholder="<?= esc(lang('Customers.locationPlaceholder'), 'attr') ?>"></label>
            <label><?= lang('Customers.code') ?><input type="text" name="code" required maxlength="32" pattern="[a-z0-9][a-z0-9\-]*" placeholder="filiale2" class="code-input"></label>
            <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255"></label>
            <button type="submit" class="button button-primary"><?= lang('Customers.createLocation') ?></button>
        <?= form_close() ?>
        <?php if ($locations !== []): ?>
            <table class="grid">
                <thead><tr><th><?= lang('Customers.name') ?></th><th><?= lang('Customers.tag') ?></th><th class="num"><?= lang('Customers.devices') ?></th><th><?= lang('Senders.note') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($locations as $l): ?>
                    <?php $count = count(array_filter($devices, static fn ($d) => (int) $d['location_id'] === (int) $l['id'])); ?>
                    <?php if ($editLocation === (int) $l['id']): ?>
                        <tr class="editing"><td colspan="5">
                            <?= form_open('customers/' . $cid . '/locations/' . $l['id'], ['class' => 'rule-form']) ?>
                                <label><?= lang('Customers.name') ?><input type="text" name="name" required maxlength="128" value="<?= esc($l['name'], 'attr') ?>"></label>
                                <label><?= lang('Customers.code') ?><input type="text" value="<?= esc($l['code'], 'attr') ?>" disabled class="code-input" title="<?= esc(lang('Customers.codeFixed'), 'attr') ?>"></label>
                                <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255" value="<?= esc($l['note'] ?? '', 'attr') ?>"></label>
                                <button type="submit" class="button button-primary"><?= lang('Rules.save') ?></button>
                                <a class="button" href="<?= site_url('customers/' . $cid) ?>"><?= lang('Rules.cancel') ?></a>
                            <?= form_close() ?>
                        </td></tr>
                        <?php continue ?>
                    <?php endif ?>
                    <tr>
                        <td><?= esc($l['name']) ?></td>
                        <td><span class="<?= rk_tag_class('standort:x') ?>"><?= esc(rk_tag_label('standort:' . $customer['code'] . '.' . $l['code'])) ?></span></td>
                        <td class="num"><?= $count ?></td>
                        <td><?= esc($l['note'] ?? '') ?></td>
                        <td class="actions">
                            <a class="button write-only" href="<?= site_url('customers/' . $cid) ?>?edit_location=<?= (int) $l['id'] ?>"><?= lang('Customers.edit') ?></a>
                            <?= $count === 0 ? $post('customers/' . $cid . '/locations/' . $l['id'] . '/delete', lang('Senders.delete'), lang('Customers.confirmDeleteLocation')) : '' ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>

    <section class="card">
        <h1><?= lang('Customers.devices') ?></h1>
        <?= form_open('customers/' . $cid . '/devices', ['class' => 'rule-form']) ?>
            <label><?= lang('Customers.name') ?><input type="text" name="name" required maxlength="128" placeholder="<?= esc(lang('Customers.devicePlaceholder'), 'attr') ?>"></label>
            <label><?= lang('Customers.code') ?><input type="text" name="code" required maxlength="32" pattern="[a-z0-9][a-z0-9\-]*" placeholder="nas05" class="code-input"></label>
            <label><?= lang('Customers.location') ?><select name="location_id"><option value="">–</option>
                <?php foreach ($locations as $l): ?><option value="<?= (int) $l['id'] ?>"><?= esc($l['name']) ?></option><?php endforeach ?></select></label>
            <label><?= lang('Customers.extraTags') ?><input type="text" name="tags" list="tag-names" placeholder="nas, qnap"></label>
            <label><?= lang('Customers.profile') ?><?= $profileSelect(null) ?></label>
            <button type="submit" class="button button-primary"><?= lang('Customers.createDevice') ?></button>
        <?= form_close() ?>
        <?php if ($devices !== []): ?>
            <table class="grid devices">
                <thead><tr><th><?= lang('Customers.device') ?></th><th><?= lang('Customers.location') ?></th><th><?= lang('Customers.tags') ?></th>
                    <th><?= lang('Customers.monitoring') ?></th><th><?= lang('Customers.login') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($devices as $d): ?>
                    <?php $id = (int) $d['id']; $mon = $monitoring[$id] ?? null; $own = array_values(array_filter($tags[$id] ?? [], static fn ($t) => ! $autoTag($t))); ?>
                    <?php if ($edit === $id): ?>
                        <tr class="editing"><td colspan="6">
                            <?= form_open('customers/' . $cid . '/devices/' . $id, ['class' => 'rule-form']) ?>
                                <label><?= lang('Customers.name') ?><input type="text" name="name" required maxlength="128" value="<?= esc($d['name'], 'attr') ?>"></label>
                                <label><?= lang('Customers.extraTags') ?><input type="text" name="tags" list="tag-names" value="<?= esc(implode(', ', $own), 'attr') ?>"></label>
                                <label><?= lang('Customers.profile') ?><?= $profileSelect($d['timeout_profile_id'] !== null ? (int) $d['timeout_profile_id'] : null) ?></label>
                                <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255" value="<?= esc($d['note'] ?? '', 'attr') ?>"></label>
                                <button type="submit" class="button button-primary"><?= lang('Rules.save') ?></button>
                                <a class="button" href="<?= site_url('customers/' . $cid) ?>"><?= lang('Rules.cancel') ?></a>
                            <?= form_close() ?>
                        </td></tr>
                    <?php else: ?>
                        <tr class="<?= $mon && $mon['overdue'] ? 'overdue' : '' ?>">
                            <td><strong><?= esc($d['name']) ?></strong><br><code class="muted"><?= esc($d['path']) ?></code><?php if ($d['note']): ?><br><span class="muted"><?= esc($d['note']) ?></span><?php endif ?></td>
                            <td><?= esc($d['location_name'] ?? '–') ?></td>
                            <td><?php foreach ($tags[$id] ?? [] as $t): ?><a class="<?= rk_tag_class($t) ?>" href="<?= site_url('/') ?>?tag%5B0%5D=<?= urlencode($t) ?>"><?= esc(rk_tag_label($t)) ?></a><?php endforeach ?></td>
                            <td><?php if ($mon): ?>
                                    <span class="badge badge-<?= $mon['overdue'] ? 'rejected' : 'accepted' ?>"><?= $mon['overdue'] ? lang('Customers.overdue') : lang('Customers.ok') ?></span>
                                    <br><span class="muted"><?= esc($d['profile_name']) ?> · <?= lang('Customers.lastSeen') ?>: <?= $mon['last_seen'] ? esc(rk_time($mon['last_seen'], 'd.m. H:i')) : lang('Devices.never') ?></span>
                                <?php else: ?><span class="muted"><?= lang('Customers.notMonitored') ?></span><?php endif ?></td>
                            <td><?php if ($d['login']): ?><code><?= esc($d['login']) ?></code><?php if (! $d['login_enabled']): ?> <span class="badge badge-blocked"><?= lang('Devices.disabled') ?></span><?php endif ?>
                                <?php else: ?><a href="<?= site_url('devices') ?>?device=<?= $id ?>" class="write-only"><?= lang('Customers.createLogin') ?></a><?php endif ?></td>
                            <td class="actions">
                                <a class="button write-only" href="<?= site_url('customers/' . $cid) ?>?edit=<?= $id ?>"><?= lang('Customers.edit') ?></a>
                                <?= $d['login'] ? '' : $post('customers/' . $cid . '/devices/' . $id . '/delete', lang('Senders.delete'), lang('Customers.confirmDeleteDevice')) ?>
                            </td>
                        </tr>
                    <?php endif ?>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>
</div>
<?= $this->endSection() ?>
