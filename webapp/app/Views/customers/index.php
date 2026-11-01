<?php
/**
 * @var list<array> $customers
 * @var array<string, int> $overdue overdue devices per customer name
 */
$this->setData(['active' => 'customers', 'title' => lang('Customers.title')]);
?>
<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page">
    <section class="card">
        <h1><?= lang('Customers.title') ?></h1>
        <p class="hint"><?= lang('Customers.hint') ?></p>
        <?= form_open('customers', ['class' => 'rule-form']) ?>
            <label><?= lang('Customers.name') ?><input type="text" name="name" required maxlength="128" placeholder="<?= esc(lang('Customers.namePlaceholder'), 'attr') ?>"></label>
            <label><?= lang('Customers.code') ?><input type="text" name="code" required maxlength="32" pattern="[a-z0-9][a-z0-9\-]*" placeholder="acme" class="code-input"></label>
            <label><?= lang('Senders.note') ?><input type="text" name="note" maxlength="255"></label>
            <button type="submit" class="button button-primary"><?= lang('Customers.createCustomer') ?></button>
        <?= form_close() ?>
        <p class="hint"><?= lang('Customers.codeHint') ?></p>
        <?php if ($customers === []): ?>
            <p class="muted"><?= lang('Customers.none') ?></p>
        <?php else: ?>
            <table class="grid">
                <thead><tr><th><?= lang('Customers.name') ?></th><th><?= lang('Customers.code') ?></th><th class="num"><?= lang('Customers.locations') ?></th>
                    <th class="num"><?= lang('Customers.devices') ?></th><th><?= lang('Customers.monitoring') ?></th><th><?= lang('Senders.note') ?></th></tr></thead>
                <tbody>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><a href="<?= site_url('customers/' . $c['id']) ?>"><strong><?= esc($c['name']) ?></strong></a></td>
                        <td><span class="<?= rk_tag_class('kunde:' . $c['code']) ?>">kunde:<?= esc($c['code']) ?></span></td>
                        <td class="num"><?= (int) $c['locations'] ?></td>
                        <td class="num"><?= (int) $c['devices'] ?></td>
                        <td><?php if (! empty($overdue[$c['name']])): ?><span class="badge badge-rejected"><?= lang('Customers.overdueCount', [$overdue[$c['name']]]) ?></span><?php endif ?></td>
                        <td><?= esc($c['note'] ?? '') ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>
</div>
<?= $this->endSection() ?>
