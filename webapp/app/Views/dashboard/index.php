<?php
/**
 * @var list<array> $overdue
 * @var int $monitored
 * @var list<array> $upcoming
 * @var array<string, int> $counts
 * @var int $pending
 * @var list<array> $errors
 * @var list<array> $nodeIssues
 * @var int $addresses
 */
$this->setData(['active' => 'dashboard', 'title' => lang('Customers.navDashboard')]);
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page">
    <div class="tiles">
        <a class="tile <?= $overdue !== [] ? 'tile-bad' : 'tile-good' ?>" href="#overdue"><span class="tile-num"><?= count($overdue) ?></span><?= lang('Customers.tileOverdue', [$monitored]) ?></a>
        <a class="tile <?= $errors !== [] ? 'tile-bad' : 'tile-good' ?>" href="<?= site_url('/') ?>?range=24h&amp;tag%5B0%5D=status%3Aerror"><span class="tile-num"><?= count($errors) ?></span><?= lang('Customers.tileErrors') ?></a>
        <a class="tile" href="<?= site_url('/') ?>?range=24h&amp;status=accepted"><span class="tile-num"><?= $counts['accepted'] ?></span><?= lang('Customers.tileAccepted') ?></a>
        <a class="tile <?= $counts['rejected'] > 0 ? 'tile-warn' : '' ?>" href="<?= site_url('/') ?>?range=24h&amp;status=rejected"><span class="tile-num"><?= $counts['rejected'] ?></span><?= lang('Customers.tileRejected') ?></a>
        <a class="tile <?= $pending > 0 ? 'tile-warn' : '' ?>" href="<?= site_url('senders') ?>"><span class="tile-num"><?= $pending ?></span><?= lang('Customers.tilePending') ?></a>
        <a class="tile <?= $nodeIssues !== [] ? 'tile-bad' : 'tile-good' ?>" href="<?= site_url('nodes') ?>"><span class="tile-num"><?= count($nodeIssues) ?></span><?= lang('Customers.tileNodes') ?></a>
        <a class="tile <?= $addresses > 0 ? 'tile-warn' : 'tile-good' ?>" href="<?= site_url('addresses') ?>"><span class="tile-num"><?= $addresses ?></span><?= lang('Addresses.tile') ?></a>
    </div>

    <section class="card" id="overdue">
        <h1><?= lang('Customers.overdueTitle') ?></h1>
        <?php if ($monitored === 0): ?>
            <p class="muted"><?= lang('Customers.noMonitoring') ?></p>
        <?php elseif ($overdue === []): ?>
            <p class="ok-text"><?= lang('Customers.allOnTime', [$monitored]) ?></p>
        <?php else: ?>
            <table class="grid">
                <thead><tr><th><?= lang('Customers.device') ?></th><th><?= lang('Customers.customer') ?></th><th><?= lang('Customers.profile') ?></th>
                    <th><?= lang('Customers.lastSeen') ?></th><th><?= lang('Customers.overdueSince') ?></th></tr></thead>
                <tbody>
                <?php foreach ($overdue as $d): ?>
                    <tr class="overdue">
                        <td><a href="<?= site_url('/') ?>?range=all&amp;tag%5B0%5D=<?= urlencode('geraet:' . $d['path']) ?>"><strong><?= esc($d['name']) ?></strong></a><br><code class="muted"><?= esc($d['path']) ?></code></td>
                        <td><?= esc($d['customer_name']) ?></td>
                        <td><?= esc($d['profile']) ?> (<?= $d['hours'] ?> h)</td>
                        <td><?= $d['last_seen'] ? esc(rk_time($d['last_seen'], 'd.m.Y H:i')) : lang('Devices.never') ?></td>
                        <td><span class="badge badge-rejected"><?= esc(rk_time($d['due_at'], 'd.m. H:i')) ?> · <?= lang('Customers.hoursAgo', [$d['overdue_hours']]) ?></span></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
        <?php if ($upcoming !== []): ?>
            <h2><?= lang('Customers.nextDue') ?></h2>
            <table class="grid">
                <tbody>
                <?php foreach ($upcoming as $d): ?>
                    <tr><td><?= esc($d['name']) ?> <code class="muted"><?= esc($d['path']) ?></code></td><td><?= esc($d['profile']) ?></td>
                        <td><?= lang('Customers.lastSeen') ?>: <?= $d['last_seen'] ? esc(rk_time($d['last_seen'], 'd.m. H:i')) : lang('Devices.never') ?></td>
                        <td><?= lang('Customers.dueAt') ?>: <?= esc(rk_time($d['due_at'], 'd.m. H:i')) ?></td></tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>

    <?php if ($errors !== []): ?>
        <section class="card">
            <h1><?= lang('Customers.errorsTitle') ?></h1>
            <table class="grid"><tbody>
            <?php foreach ($errors as $e): ?>
                <tr><td><?= esc(rk_time($e['received_at'], 'd.m. H:i')) ?></td><td><span class="<?= rk_tag_class($e['tag']) ?>"><?= esc(rk_tag_label($e['tag'])) ?></span></td>
                    <td><a href="<?= site_url('/') ?>?id=<?= (int) $e['id'] ?>"><?= esc($e['subject'] ?? '') ?></a></td></tr>
            <?php endforeach ?>
            </tbody></table>
        </section>
    <?php endif ?>

    <?php if ($nodeIssues !== []): ?>
        <section class="card">
            <h1><?= lang('Customers.nodesTitle') ?></h1>
            <ul><?php foreach ($nodeIssues as $n): ?><li><a href="<?= site_url('nodes') ?>"><?= esc($n['name']) ?></a> <span class="error"><?= esc(trim(($n['last_error'] ?? '') . ' ' . ($n['config_error'] ?? ''))) ?: lang('Customers.nodeStale') ?></span></li><?php endforeach ?></ul>
        </section>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
