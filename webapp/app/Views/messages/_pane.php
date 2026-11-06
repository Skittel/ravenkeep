<?php
/**
 * Reading pane for one message.
 *
 * @var array $m
 * @var string $node
 * @var list<string> $reasons
 * @var array|null $auth
 * @var App\Libraries\MessageReader|null $reader
 * @var string|null $error
 * @var list<array> $tags
 * @var list<string> $tagTypes tag types for manual tags (E-023)
 * @var string|null $listCheck check of the matching allow rule (E-021)
 * @var list<array> $notes notes, oldest first
 * @var string|null $tab tab to open ("notes" after saving a note)
 */
$statusLabels = [
    'accepted' => lang('Ui.statusAccepted'),
    'rejected' => lang('Ui.statusRejected'),
    'pending'  => lang('Ui.statusPending'),
    'blocked'  => lang('Ui.statusBlocked'),
];
$row = static function (string $label, ?string $value): string {
    if ($value === null || $value === '') {
        return '';
    }

    return '<tr><th>' . esc($label) . '</th><td>' . esc($value) . '</td></tr>';
};
$quick = static function (string $address, string $target, int $id): string {
    $address = strtolower(trim($address, '<> '));
    if (! str_contains($address, '@')) {
        return '';
    }
    $domain = substr((string) strrchr($address, '@'), 1);
    $form   = static fn (string $pattern, string $action, string $label, string $class) => form_open('senders', ['class' => 'inline'])
        . form_hidden(['pattern' => $pattern, 'target' => $target, 'action' => $action, 'return_to' => '/?id=' . $id])
        . '<button type="submit" class="button ' . $class . '">' . esc($label) . '</button>' . form_close();

    return '<tr><th>' . esc($target === 'header' ? lang('Ui.headerFrom') : lang('Ui.envelopeFrom')) . '</th><td><code>' . esc($address) . '</code> '
        . $form($domain, 'allow', lang('Senders.allowDomain') . ' (' . $domain . ')', 'button-allow')
        . $form($address, 'allow', lang('Senders.allowAddress'), 'button-allow')
        . $form($domain, 'block', lang('Senders.blockDomain'), 'button-block') . '</td></tr>';
};
$text = $reader?->textBody();
$html = $reader?->htmlBody();
?>
<article class="message" data-id="<?= $m['id'] ?>">
    <header class="message-head">
        <h1><?= esc($m['subject'] ?? '') ?: '<em>' . lang('Ui.noSubject') . '</em>' ?></h1>
        <?php if ($m['status'] !== 'accepted'): // "accepted" is the normal case, shown only when different ?>
            <span class="badge badge-<?= esc($m['status'], 'attr') ?>"><?= esc($statusLabels[$m['status']] ?? $m['status']) ?></span>
        <?php endif ?>
        <span class="work-badges"><?= view('messages/_work', ['m' => $m]) ?></span>
        <a class="button" href="<?= site_url('messages/' . $m['id'] . '/eml') ?>"><?= lang('Ui.downloadEml') ?></a>
    </header>

    <table class="kv message-summary">
        <?= $row(lang('Ui.from'), $m['header_from'] ?: $m['envelope_sender']) ?>
        <?= $row(lang('Ui.to'), $m['header_to'] ?: $m['envelope_recipient']) ?>
        <?= $row(lang('Ui.dateReceived'), rk_time($m['received_at'])) ?>
        <tr><th><?= lang('Ui.rating') ?></th><td><?= lang('Ui.rating_' . ($m['rating'] ?? 'none')) ?></td></tr>
        <tr><th><?= lang('Ui.handling') ?></th><td>
            <?= form_open('messages/' . $m['id'] . '/handling', ['class' => 'inline handling-form']) ?>
                <select name="handling" data-handling>
                    <?php foreach (App\Libraries\Messages\MessageWork::HANDLING as $h): ?>
                        <option value="<?= $h ?>"<?= ($m['handling'] ?? 'new') === $h ? ' selected' : '' ?>><?= lang('Ui.handling_' . $h) ?></option>
                    <?php endforeach ?>
                </select>
                <input type="date" name="handling_until" value="<?= esc((string) ($m['handling_until'] ?? ''), 'attr') ?>" min="<?= esc((new DateTimeImmutable('tomorrow', new DateTimeZone(config('Ravenkeep')->displayTimezone)))->format('Y-m-d'), 'attr') ?>" data-handling-until<?= ($m['handling'] ?? 'new') === 'waiting' ? '' : ' hidden' ?>>
                <button type="submit" class="button"><?= lang('Ui.save') ?></button>
            <?= form_close() ?>
            <span class="read-only-value"><?= ($m['handling'] ?? 'new') === 'waiting' && ! empty($m['handling_until']) ? esc(lang('Ui.handlingUntil', [rk_time($m['handling_until'] . ' 12:00:00', 'd.m.Y')])) : lang('Ui.handling_' . ($m['handling'] ?? 'new')) ?></span>
            <?php if (! empty($m['handling_by'])): ?><span class="hint"><?= esc(lang('Ui.handlingBy', [$m['handling_by'], rk_relative_time($m['handling_at'])])) ?></span><?php endif ?>
        </td></tr>
        <tr><th><?= lang('Rules.tags') ?></th><td>
            <div class="message-tags">
                <?php foreach ($tags as $tg): ?>
                    <span class="<?= rk_tag_class($tg['name']) ?>" title="<?= esc(match ($tg['source']) { 'manual' => lang('Rules.manual'), 'device' => lang('Rules.fromDevice'), 'address' => lang('Rules.fromAddress'), 'sender' => lang('Rules.fromSender'), default => lang('Rules.byRule', [$tg['rule_name'] ?? '?']) }, 'attr') ?>"><?= esc(rk_tag_label($tg['name'])) ?><?php if ($tg['source'] === 'manual'): ?><?= form_open('messages/' . $m['id'] . '/tags/' . $tg['id'] . '/delete', ['class' => 'inline tag-remove']) ?><button type="submit" title="<?= esc(lang('Rules.removeTag'), 'attr') ?>">×</button><?= form_close() ?><?php endif ?></span>
                <?php endforeach ?>
                <?= form_open('messages/' . $m['id'] . '/tags', ['class' => 'inline tag-add']) ?>
                    <select name="type" required title="<?= esc(lang('Customers.tagType'), 'attr') ?>"><option value=""><?= lang('Customers.chooseType') ?></option><?php foreach ($tagTypes ?? [] as $tt): ?><option value="<?= esc($tt, 'attr') ?>"><?= esc($tt) ?></option><?php endforeach ?></select>
                    <input type="text" name="value" maxlength="63" placeholder="<?= esc(lang('Rules.addTag'), 'attr') ?>" required>
                <?= form_close() ?>
                <?php if ($m['status'] !== 'accepted'): ?><span class="hint"><?= lang('Rules.onlyAccepted') ?></span><?php endif ?>
                <?php if (! empty($m['address_unknown'])): ?><span class="hint address-hint"><?= lang('Addresses.paneHint') ?>: <?= esc(implode(', ', array_map(static fn ($p) => lang('Addresses.part_' . $p['part'], [$p['value']]), json_decode($m['address_unknown'], true) ?: []))) ?> – <a href="<?= site_url('addresses') ?>"><?= lang('Addresses.paneLink') ?></a></span><?php endif ?>
                <?php if (! empty($m['sender_unknown'])): ?><span class="hint address-hint"><?= lang('Addresses.senderHint') ?>: <?= esc(implode(', ', array_map(static fn ($p) => lang('Addresses.part_' . $p['part'], [$p['value']]), json_decode($m['sender_unknown'], true) ?: []))) ?> – <a href="<?= site_url('addresses') ?>"><?= lang('Addresses.paneLink') ?></a></span><?php endif ?>
                <?php if (! empty($m['sender_unverified'])): ?><span class="hint address-hint"><?= lang('Addresses.senderUnverified') ?>: <?= esc(rk_sender_reason($m['sender_unverified'])) ?></span><?php endif ?>
            </div>
        </td></tr>
    </table>

    <section class="body">
        <?php $active = ($tab ?? null) === 'notes' ? 'notes' : ($text !== null || $html === null ? 'text' : 'html'); ?>
        <?php if ($error !== null): ?>
            <p class="error"><?= esc($error) ?></p>
            <?= view('messages/_notes', ['m' => $m, 'notes' => $notes ?? [], 'hidden' => false]) ?>
        <?php else: ?>
            <div class="tabs" role="tablist">
                <button type="button" data-tab="text" class="<?= $active === 'text' ? 'active' : '' ?>"><?= lang('Ui.viewText') ?></button>
                <?php if ($html !== null): ?>
                    <button type="button" data-tab="html" class="<?= $active === 'html' ? 'active' : '' ?>"><?= lang('Ui.viewHtml') ?></button>
                <?php endif ?>
                <button type="button" data-tab="headers"><?= lang('Ui.viewHeaders') ?></button>
                <button type="button" data-tab="details"><?= lang('Ui.viewDetails') ?></button>
                <button type="button" data-tab="rules"><?= lang('Ui.viewRules') ?></button>
                <button type="button" data-tab="notes" class="<?= $active === 'notes' ? 'active' : '' ?>"><?= lang('Ui.viewNotes') ?><?php if (($notes ?? []) !== []): ?> <span class="count"><?= count($notes) ?></span><?php endif ?></button>
            </div>
            <div class="tab" data-tab="text" <?= $active === 'text' ? '' : 'hidden' ?>>
                <pre class="text"><?= esc($text ?? lang('Ui.noBody')) ?></pre>
            </div>
            <?php if ($html !== null): ?>
                <div class="tab" data-tab="html" <?= $active === 'html' ? '' : 'hidden' ?>>
                    <p class="hint"><?= lang('Ui.htmlHint') ?></p>
                    <iframe sandbox="" referrerpolicy="no-referrer" data-src="<?= site_url('messages/' . $m['id'] . '/html') ?>" title="HTML"></iframe>
                </div>
            <?php endif ?>
            <div class="tab" data-tab="headers" hidden>
                <pre class="headers"><?= esc($reader->rawHeaders()) ?></pre>
            </div>
            <div class="tab" data-tab="details" hidden>
                <div class="facts">
        <table class="kv">
            <?= $row(lang('Ui.received'), rk_time($m['received_at'])) ?>
            <?= $row(lang('Ui.headerFrom'), $m['header_from']) ?>
            <?= $row(lang('Ui.envelopeFrom'), $m['envelope_sender'] ?: '<>') ?>
            <?= $row(lang('Ui.headerTo'), $m['header_to']) ?>
            <?= $row(lang('Ui.envelopeTo'), $m['envelope_recipient']) ?>
            <?= $row(lang('Ui.date'), $m['header_date']) ?>
            <?= $row(lang('Ui.messageId'), $m['message_id']) ?>
            <?= $row(lang('Ui.size'), rk_size((int) $m['size'])) ?>
        </table>
        <table class="kv">
            <?= $row(lang('Ui.sender'), $m['mode'] === 'auth' ? lang('Ui.modeAuth') : lang('Ui.modeMx')) ?>
            <?= $row(lang('Ui.login'), $m['sasl_username']) ?>
            <?= $row(lang('Ui.clientIp'), $m['client_ip']) ?>
            <?= $row(lang('Ui.clientHostname'), $m['client_hostname']) ?>
            <?= $row(lang('Ui.clientPtr'), $m['client_ptr']) ?>
            <?= $row(lang('Ui.clientHelo'), $m['client_helo']) ?>
            <?= $row(lang('Ui.node'), $node) ?>
        </table>
                </div>
                <section class="checks">
        <h2><?= lang('Ui.checks') ?></h2>
        <?php if ($m['mode'] === 'auth'): ?>
            <p><?= lang('Ui.notChecked') ?></p>
        <?php else: ?>
            <table class="kv">
                <?php if ($auth !== null): ?>
                    <?= $row(lang('Ui.spf'), ($auth['spf']['result'] ?? '?') . ' · ' . ($auth['spf']['domain'] ?? '') . ' (' . ($auth['spf']['identity'] ?? '') . ')') ?>
                    <?php if (empty($auth['dkim'])): ?>
                        <?= $row(lang('Ui.dkim'), lang('Ui.noSignature')) ?>
                    <?php else: foreach ($auth['dkim'] as $sig): ?>
                        <?= $row(lang('Ui.dkim'), $sig['result'] . ' · d=' . $sig['domain'] . ' s=' . $sig['selector'] . ($sig['result'] !== 'pass' ? ' – ' . $sig['reason'] : '')) ?>
                    <?php endforeach; endif ?>
                    <?php $al = $auth['alignment'] ?? []; ?>
                    <?php if ($al !== []): ?>
                        <?= $row(lang('Ui.alignment'), 'SPF ' . (! empty($al['spf_aligned']) ? lang('Ui.aligned') : lang('Ui.notAligned'))
                            . ' · DKIM ' . (! empty($al['dkim_aligned']) ? lang('Ui.aligned') : lang('Ui.notAligned'))
                            . ' · ' . ($auth['from_domain'] ?? '') . (isset($al['dmarc_policy']) ? ' (DMARC p=' . $al['dmarc_policy'] . ')' : '')) ?>
                    <?php endif ?>
                <?php endif ?>
                <?php if ($m['list_match_pattern']): ?>
                    <?= $row(lang('Ui.listMatch'), $m['list_match_pattern'] . ' (' . ($m['list_match_target'] === 'header' ? lang('Ui.matchHeader') : lang('Ui.matchEnvelope')) . ')'
                        . (isset($listCheck) ? ' · ' . lang('Senders.check') . ': ' . lang('Senders.check_' . $listCheck) : '')) ?>
                <?php endif ?>
            </table>
            <?php if ($reasons !== []): ?>
                <ul class="reasons">
                    <?php foreach ($reasons as $reason): ?><li><?= esc($reason) ?></li><?php endforeach ?>
                </ul>
            <?php endif ?>
        <?php endif ?>
                </section>
            </div>
            <div class="tab" data-tab="rules" hidden>
                <h2><?= lang('Ui.appliedRules') ?></h2>
                <?php $byRule = array_filter($tags, static fn ($t) => $t['source'] === 'rule'); ?>
                <?php if ($byRule === []): ?>
                    <p class="muted"><?= lang('Ui.noRuleMatched') ?></p>
                <?php else: ?>
                    <table class="kv">
                        <?php foreach ($byRule as $tg): ?>
                            <tr><th><span class="<?= rk_tag_class($tg['name']) ?>"><?= esc(rk_tag_label($tg['name'])) ?></span></th>
                                <td><?php if (! empty($tg['rule_id'])): ?><a href="<?= site_url('rules/' . $tg['rule_id']) ?>"><?= esc($tg['rule_name'] ?? '?') ?></a><?php else: ?><?= esc($tg['rule_name'] ?? '?') ?><?php endif ?></td></tr>
                        <?php endforeach ?>
                    </table>
                <?php endif ?>
                <?php if ($m['mode'] !== 'auth'): ?>
            <h2><?= lang('Senders.quickTitle') ?></h2>
            <table class="kv quick">
                <?= $quick((string) $m['envelope_sender'], 'envelope', (int) $m['id']) ?>
                <?php if (($m['header_from_address'] ?? '') !== '' && strtolower((string) $m['header_from_address']) !== strtolower((string) $m['envelope_sender'])): ?>
                    <?= $quick((string) $m['header_from_address'], 'header', (int) $m['id']) ?>
                <?php endif ?>
            </table>
                <?php endif ?>
            </div>
            <?= view('messages/_notes', ['m' => $m, 'notes' => $notes ?? [], 'hidden' => $active !== 'notes']) ?>
            <?php $attachments = $reader->attachments(); ?>
            <?php if ($attachments !== []): ?>
                <h2><?= lang('Ui.attachments') ?></h2>
                <ul class="attachments">
                    <?php foreach ($attachments as $a): ?>
                        <li><?= esc($a['name']) ?> <span class="muted">(<?= esc($a['type']) ?>, <?= rk_size($a['size']) ?>)</span></li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        <?php endif ?>
    </section>
</article>
