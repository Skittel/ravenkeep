<?php
/**
 * @var list<array> $nodes
 * @var string $currentHash
 * @var array<string, ?string> $webhook
 */
$this->setData(['active' => 'nodes', 'title' => lang('Senders.nodesTitle')]);
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page">
    <section class="card">
        <h1><?= lang('Senders.nodesTitle') ?></h1>
        <table class="grid">
            <thead><tr>
                <th><?= lang('Senders.nodeName') ?></th><th><?= lang('Senders.transport') ?></th>
                <th><?= lang('Senders.lastCollect') ?></th><th><?= lang('Senders.configStatus') ?></th><th><?= lang('Senders.error') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($nodes as $n): ?>
                <?php
                $state = match (true) {
                    ! \App\Libraries\Nodes\NodeConfigSync::hasConfigPush($n) => ['accepted', lang('Senders.configNotManaged')],
                    ! empty($n['config_error'])                  => ['blocked', lang('Senders.error')],
                    $n['config_applied_hash'] === $currentHash   => ['accepted', lang('Senders.inSync')],
                    $n['config_hash'] === $currentHash           => ['pending', lang('Senders.waiting')],
                    default                                      => ['rejected', lang('Senders.outdated')],
                };
                ?>
                <tr>
                    <td><strong><?= esc($n['name']) ?></strong><br><span class="muted"><?= esc($n['hostname']) ?></span></td>
                    <td><?= esc($n['transport']) ?></td>
                    <td><?= esc(rk_time($n['last_collect_at'])) ?><br><span class="muted"><?= (int) $n['last_collect_count'] ?> <?= lang('Senders.imported') ?></span></td>
                    <td><span class="badge badge-<?= $state[0] ?>"><?= esc($state[1]) ?></span>
                        <?php if (\App\Libraries\Nodes\NodeConfigSync::hasConfigPush($n)): ?>
                        <br><span class="muted"><?= lang('Senders.pushed') ?>: <code><?= esc(substr($n['config_hash'] ?? '', 0, 12)) ?></code> <?= esc(rk_time($n['config_pushed_at'], 'd.m. H:i:s')) ?></span>
                        <br><span class="muted"><?= lang('Senders.applied') ?>: <code><?= esc(substr($n['config_applied_hash'] ?? '', 0, 12)) ?></code> <?= esc(rk_time($n['config_applied_at'], 'd.m. H:i:s')) ?></span>
                        <?php endif ?></td>
                    <td class="error"><?= nl2br(esc(trim(($n['last_error'] ?? '') . "\n" . ($n['config_error'] ?? '')))) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h1><?= lang('Senders.webhookTitle') ?></h1>
        <p class="hint"><?= lang('Senders.webhookHint') ?></p>
        <?php
        $lateRecent = $webhook['webhook_late_at'] !== null && strtotime($webhook['webhook_late_at'] . ' UTC') > time() - 86400;
        $state = match (true) {
            $webhook['webhook_token'] === null      => ['blocked', lang('Senders.webhookOff')],
            $webhook['webhook_rotation_error'] !== null || $lateRecent => ['pending', lang('Senders.webhookWarn')],
            default                                 => ['accepted', lang('Senders.webhookOn')],
        };
        ?>
        <table class="kv">
            <tr><th><?= lang('Senders.configStatus') ?></th><td><span class="badge badge-<?= $state[0] ?>"><?= esc($state[1]) ?></span></td></tr>
            <tr><th><?= lang('Senders.webhookLastPing') ?></th><td><?= esc($webhook['webhook_last_ping_at'] ? rk_time($webhook['webhook_last_ping_at']) : '–') ?></td></tr>
            <tr><th><?= lang('Senders.webhookRotated') ?></th><td><?= esc($webhook['webhook_rotated_at'] ? rk_time($webhook['webhook_rotated_at']) : '–') ?></td></tr>
            <?php if ($webhook['webhook_rotation_error'] !== null): ?>
                <tr><th><?= lang('Senders.error') ?></th><td class="error"><?= esc($webhook['webhook_rotation_error']) ?></td></tr>
            <?php endif ?>
            <?php if ($lateRecent): ?>
                <tr><th><?= lang('Senders.webhookLate') ?></th><td class="error"><?= esc(rk_time($webhook['webhook_late_at'])) ?> – <?= lang('Senders.webhookLateHint') ?></td></tr>
            <?php endif ?>
        </table>
    </section>
</div>
<?= $this->endSection() ?>
