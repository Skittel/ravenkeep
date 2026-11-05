<?php
/**
 * Edit an SMTP login: device, fixed tags, note; suggestion read once from the user name (E-024).
 *
 * @var array $identity
 * @var list<string> $own fixed tags of the login
 * @var array{device: ?string, device_id: ?int, tags: list<string>, unknown: list<array>, customer: ?string, scheme: bool} $suggest
 * @var list<array> $devices
 * @var list<string> $deviceTags tags coming from the linked device
 * @var list<string> $tagNames
 * @var array{host: string, username: string, password: ?string}|null $access shown after "show access data"
 */
use App\Libraries\Rules\AddressTags;

$this->setData(['active' => 'devices', 'title' => $identity['username']]);
$id     = (int) $identity['id'];
$create = static fn (string $kind, string $value, string $label): string => form_open('devices/' . $id . '/create', ['class' => 'inline'])
    . form_hidden(['kind' => $kind, 'value' => $value]) . '<button type="submit" class="button">' . esc(lang($label, [$value])) . '</button>' . form_close();
$deviceSelect = static function (?int $selected) use ($devices): string {
    $html = '<select name="device_id"><option value="">' . esc(lang('Devices.noDevice')) . '</option>';
    foreach ($devices as $d) {
        $html .= '<option value="' . (int) $d['id'] . '"' . ((int) $d['id'] === $selected ? ' selected' : '') . '>' . esc($d['customer_name'] . ' – ' . $d['name'] . ' (' . $d['path'] . ')') . '</option>';
    }

    return $html . '</select>';
};
$suggestedTags = array_values(array_unique([...$own, ...$suggest['tags']]));
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?= rk_tag_data($tagNames) ?>
<div class="page">
    <section class="card">
        <h1><a href="<?= site_url('devices') ?>"><?= lang('Devices.title') ?></a> › <code><?= esc($identity['username']) ?></code></h1>
        <p class="hint"><?= lang('Devices.editHint') ?></p>
        <?= form_open('devices/' . $id, ['class' => 'rule-form']) ?>
            <label><?= lang('Customers.device') ?><?= $deviceSelect($identity['device_id'] ? (int) $identity['device_id'] : null) ?></label>
            <label><?= lang('Senders.fixedTags') ?><input type="text" name="tags" autocomplete="off" value="<?= esc(implode(', ', $own), 'attr') ?>"<?= rk_tag_picker_attrs('multi') ?>></label>
            <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255" value="<?= esc($identity['note'] ?? '', 'attr') ?>"></label>
            <input type="hidden" name="options" value="1">
            <label><?= lang('Devices.allowedNetworks') ?><input type="text" name="allowed_networks" maxlength="255" value="<?= esc($identity['allowed_networks'] ?? '', 'attr') ?>" placeholder="<?= esc(lang('Devices.allowedNetworksHint'), 'attr') ?>"></label>
            <label class="check"><input type="checkbox" name="plaintext_port25" value="1" <?= $identity['plaintext_port25'] ? 'checked' : '' ?>> <?= lang('Devices.plaintext25') ?></label>
            <button type="submit" class="button button-primary"><?= lang('Rules.save') ?></button>
            <a class="button" href="<?= site_url('devices') ?>"><?= lang('Rules.cancel') ?></a>
        <?= form_close() ?>
        <?php if ($access === null): ?>
            <?= form_open('devices/' . $id . '/show', ['class' => 'inline write-only']) ?><button type="submit" class="button"><?= lang('Devices.showAccess') ?></button><?= form_close() ?>
        <?php else: ?>
            <table class="kv access">
                <tr><th><?= lang('Devices.server') ?></th><td><code class="select"><?= esc($access['host']) ?></code></td></tr>
                <tr><th><?= lang('Devices.port') ?></th><td><code>587</code> (STARTTLS) <?= lang('Devices.or') ?> <code>465</code> (SSL/TLS)<?= lang('Devices.port25') ?></td></tr>
                <tr><th><?= lang('Devices.username') ?></th><td><code class="select"><?= esc($access['username']) ?></code></td></tr>
                <tr><th><?= lang('Devices.recipient') ?></th><td><code class="select">status@<?= esc(explode('@', $access['username'])[1] ?? '') ?></code> <span class="muted"><?= lang('Devices.recipientHint') ?></span></td></tr>
                <tr><th><?= lang('Devices.password') ?></th><td>
                    <?php if ($access['password'] === null): ?>
                        <span class="check-none"><?= lang('Devices.passwordNotStored') ?></span>
                    <?php else: ?>
                        <span class="secret-field"><input type="password" id="access-password" value="<?= esc($access['password'], 'attr') ?>" readonly autocomplete="off" spellcheck="false">
                        <button type="button" class="reveal" data-reveal="access-password" title="<?= esc(lang('Devices.reveal'), 'attr') ?>" aria-label="<?= esc(lang('Devices.reveal'), 'attr') ?>">👁</button></span>
                    <?php endif ?>
                </td></tr>
            </table>
        <?php endif ?>
        <?php if ($deviceTags !== []): ?>
            <p class="hint"><?= lang('Devices.fromDevice') ?> <?php foreach ($deviceTags as $t): ?><span class="<?= rk_tag_class($t) ?>"><?= esc(rk_tag_label($t)) ?></span><?php endforeach ?></p>
        <?php endif ?>
    </section>

    <section class="card">
        <h1><?= lang('Devices.captureTitle') ?></h1>
        <p class="hint"><?= lang('Devices.captureHint') ?></p>
        <?php if (! $suggest['scheme']): ?>
            <p class="muted"><?= lang('Devices.captureNoScheme') ?></p>
        <?php elseif ($suggest['unknown'] !== []): ?>
            <table class="kv">
            <?php foreach ($suggest['unknown'] as $p): ?>
                <tr><th><?= esc(lang('Addresses.part_' . $p['part'], [$p['value']])) ?></th><td class="write-only">
                    <?php if ($p['part'] === AddressTags::CUSTOMER): ?><?= $create('customer', $p['value'], 'Addresses.createCustomer') ?>
                    <?php elseif ($p['part'] === AddressTags::DEVICE): ?><?= $create('device', $p['value'], 'Addresses.createDevice') ?>
                    <?php elseif ($p['part'] === AddressTags::LOCATION): ?><?= $create('location', $p['value'], 'Addresses.createLocation') ?>
                    <?php elseif ($p['part'] === AddressTags::SOURCE): ?><?= $create('source', $p['value'], 'Addresses.createSource') ?>
                    <?php elseif ($p['part'] === AddressTags::DEVICE_OR_SOURCE): ?><?= $create('device', $p['value'], 'Addresses.createDevice') ?> <?= $create('source', $p['value'], 'Addresses.createSource') ?>
                    <?php elseif ($p['part'] === AddressTags::LOCATION_OR_SOURCE): ?><?= $create('location', $p['value'], 'Addresses.createLocation') ?> <?= $create('source', $p['value'], 'Addresses.createSource') ?>
                    <?php endif ?>
                </td></tr>
            <?php endforeach ?>
            </table>
            <p class="hint"><?= lang('Devices.captureMissing') ?></p>
        <?php else: ?>
            <table class="kv">
                <tr><th><?= lang('Customers.device') ?></th><td><?= $suggest['device'] !== null ? '<code>' . esc($suggest['device']) . '</code>' : '<span class="muted">–</span>' ?></td></tr>
                <tr><th><?= lang('Senders.fixedTags') ?></th><td><?php foreach ($suggest['tags'] as $t): ?><span class="<?= rk_tag_class($t) ?>"><?= esc(rk_tag_label($t)) ?></span><?php endforeach ?><?= $suggest['tags'] === [] ? '<span class="muted">–</span>' : '' ?></td></tr>
            </table>
            <?= form_open('devices/' . $id, ['class' => 'inline write-only']) ?>
                <?= form_hidden(['device_id' => (string) ($suggest['device_id'] ?? ''), 'tags' => implode(', ', $suggestedTags), 'note' => (string) ($identity['note'] ?? '')]) ?>
                <button type="submit" class="button button-primary"><?= lang('Devices.captureApply') ?></button>
            <?= form_close() ?>
        <?php endif ?>
    </section>
</div>
<?= $this->endSection() ?>
