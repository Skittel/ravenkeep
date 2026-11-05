<?php
/**
 * Unresolved recipient and sender addresses, unverified senders and the address map (E-019, E-020).
 *
 * @var list<array> $unknown
 * @var list<array> $mappings
 * @var list<array> $unverified
 * @var list<array> $devices
 * @var int $backlog
 */
use App\Libraries\Rules\AddressTags;

$this->setData(['active' => 'addresses', 'title' => lang('Addresses.title')]);
$create = static function (array $u, string $kind, string $value, string $label): string {
    return form_open('addresses/create', ['class' => 'inline'])
        . form_hidden(['address' => $u['address'], 'kind' => $kind, 'value' => $value, 'direction' => $u['direction']])
        . '<button type="submit" class="button">' . esc(lang($label, [$value])) . '</button>' . form_close();
};
$byCustomer = [];
foreach ($devices as $d) {
    $byCustomer[$d['customer_name']][] = $d;
}
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page">
    <section class="card">
        <h1><?= lang('Addresses.title') ?></h1>
        <p class="hint"><?= lang('Addresses.hint') ?></p>
        <?php if ($backlog > 0): ?><p class="hint"><?= lang('Rules.backlog', [$backlog]) ?></p><?php endif ?>
        <?php if ($unknown === []): ?>
            <p class="ok-text"><?= lang('Addresses.none') ?></p>
        <?php else: ?>
            <table class="grid addresses">
                <thead><tr><th><?= lang('Addresses.address') ?></th><th><?= lang('Addresses.direction') ?></th><th class="num"><?= lang('Addresses.messages') ?></th><th><?= lang('Addresses.period') ?></th>
                    <th><?= lang('Addresses.problem') ?></th><th><?= lang('Addresses.actions') ?></th></tr></thead>
                <tbody>
                <?php foreach ($unknown as $u): ?>
                    <tr>
                        <td><code><?= esc($u['address']) ?></code><br><a class="muted" href="<?= site_url('/') ?>?range=all&amp;q=<?= urlencode($u['address']) ?>&amp;id=<?= $u['last_id'] ?>"><?= lang('Addresses.showMessages') ?></a></td>
                        <td><?= lang('Addresses.dir_' . $u['direction']) ?></td>
                        <td class="num"><?= $u['messages'] ?></td>
                        <td><?= esc(rk_time($u['first'], 'd.m. H:i')) ?><?= $u['first'] !== $u['last'] ? ' – ' . esc(rk_time($u['last'], 'd.m. H:i')) : '' ?></td>
                        <td><?php foreach ($u['unknown'] as $p): ?><div><?= esc(lang('Addresses.part_' . $p['part'], [$p['value']])) ?></div><?php endforeach ?></td>
                        <td class="address-actions">
                            <div class="write-only">
                            <?php foreach ($u['customer'] === null ? [] : $u['unknown'] as $p): // without customer only mapping is possible ?>
                                <?php if ($p['part'] === AddressTags::CUSTOMER): ?>
                                    <?= $create($u, 'customer', $p['value'], 'Addresses.createCustomer') ?>
                                <?php elseif ($p['part'] === AddressTags::DEVICE): ?>
                                    <?= $create($u, 'device', $p['value'], 'Addresses.createDevice') ?>
                                <?php elseif ($p['part'] === AddressTags::LOCATION): ?>
                                    <?= $create($u, 'location', $p['value'], 'Addresses.createLocation') ?>
                                <?php elseif ($p['part'] === AddressTags::SOURCE): ?>
                                    <?= $create($u, 'source', $p['value'], 'Addresses.createSource') ?>
                                <?php elseif ($p['part'] === AddressTags::DEVICE_OR_SOURCE): ?>
                                    <?= $create($u, 'device', $p['value'], 'Addresses.createDevice') ?>
                                    <?= $create($u, 'source', $p['value'], 'Addresses.createSource') ?>
                                <?php elseif ($p['part'] === AddressTags::LOCATION_OR_SOURCE): ?>
                                    <?= $create($u, 'location', $p['value'], 'Addresses.createLocation') ?>
                                    <?= $create($u, 'source', $p['value'], 'Addresses.createSource') ?>
                                <?php endif ?>
                            <?php endforeach ?>
                            <?php if ($devices !== []): ?>
                                <?= form_open('addresses/map', ['class' => 'inline address-map']) ?>
                                    <?= form_hidden('address', $u['address']) ?>
                                    <select name="device_id" required>
                                        <option value=""><?= lang('Addresses.mapTo') ?></option>
                                        <?php $own = array_filter($byCustomer, static fn ($l) => $u['customer'] !== null && str_starts_with($l[0]['path'], $u['customer'] . '.'));
                                              foreach ($own + $byCustomer as $customerName => $list): ?>
                                            <optgroup label="<?= esc($customerName, 'attr') ?>">
                                                <?php foreach ($list as $d): ?>
                                                    <option value="<?= $d['id'] ?>"><?= esc($d['name']) ?> (<?= esc($d['path']) ?>)</option>
                                                <?php endforeach ?>
                                            </optgroup>
                                        <?php endforeach ?>
                                    </select>
                                    <button type="submit" class="button button-primary"><?= lang('Addresses.map') ?></button>
                                <?= form_close() ?>
                            <?php endif ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>

    <?php if ($unverified !== []): ?>
    <section class="card">
        <h1><?= lang('Addresses.unverifiedTitle') ?></h1>
        <p class="hint"><?= lang('Addresses.unverifiedHint') ?></p>
        <table class="grid">
            <thead><tr><th><?= lang('Addresses.address') ?></th><th class="num"><?= lang('Addresses.messages') ?></th><th><?= lang('Addresses.reason') ?></th></tr></thead>
            <tbody>
            <?php foreach ($unverified as $v): ?>
                <tr>
                    <td><code><?= esc($v['address']) ?></code><br><a class="muted" href="<?= site_url('/') ?>?range=all&amp;q=<?= urlencode($v['address']) ?>&amp;id=<?= $v['last_id'] ?>"><?= lang('Addresses.showMessages') ?></a></td>
                    <td class="num"><?= $v['messages'] ?></td>
                    <td><?= esc(rk_sender_reason($v['reason'])) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </section>
    <?php endif ?>

    <section class="card">
        <h1><?= lang('Addresses.mappingsTitle') ?></h1>
        <p class="hint"><?= lang('Addresses.mappingsHint') ?></p>
        <?php if ($mappings === []): ?>
            <p class="muted"><?= lang('Addresses.noMappings') ?></p>
        <?php else: ?>
            <table class="grid">
                <thead><tr><th><?= lang('Addresses.address') ?></th><th><?= lang('Customers.device') ?></th><th><?= lang('Customers.customer') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($mappings as $m): ?>
                    <tr>
                        <td><code><?= esc($m['address']) ?></code></td>
                        <td><a href="<?= site_url('customers/' . $m['customer_id']) ?>"><?= esc($m['device_name']) ?></a> <code class="muted"><?= esc($m['path']) ?></code></td>
                        <td><?= esc($m['customer_name']) ?></td>
                        <td class="actions"><?= form_open('addresses/map/' . $m['id'] . '/delete', ['class' => 'inline', 'data-confirm' => lang('Addresses.confirmUnmap')]) ?>
                            <button type="submit" class="button"><?= lang('Addresses.unmap') ?></button><?= form_close() ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>
</div>
<?= $this->endSection() ?>
