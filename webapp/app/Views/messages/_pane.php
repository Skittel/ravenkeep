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
$text = $reader?->textBody();
$html = $reader?->htmlBody();
?>
<article class="message" data-id="<?= $m['id'] ?>">
    <header class="message-head">
        <h1><?= esc($m['subject'] ?? '') ?: '<em>' . lang('Ui.noSubject') . '</em>' ?></h1>
        <span class="badge badge-<?= esc($m['status'], 'attr') ?>"><?= esc($statusLabels[$m['status']] ?? $m['status']) ?></span>
        <a class="button" href="<?= site_url('messages/' . $m['id'] . '/eml') ?>"><?= lang('Ui.downloadEml') ?></a>
    </header>

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
                    <?= $row(lang('Ui.listMatch'), $m['list_match_pattern'] . ' (' . ($m['list_match_target'] === 'header' ? lang('Ui.matchHeader') : lang('Ui.matchEnvelope')) . ')') ?>
                <?php endif ?>
            </table>
            <?php if ($reasons !== []): ?>
                <ul class="reasons">
                    <?php foreach ($reasons as $reason): ?><li><?= esc($reason) ?></li><?php endforeach ?>
                </ul>
            <?php endif ?>
        <?php endif ?>
    </section>

    <section class="body">
        <?php if ($error !== null): ?>
            <p class="error"><?= esc($error) ?></p>
        <?php else: ?>
            <div class="tabs" role="tablist">
                <button type="button" data-tab="text" class="<?= $text !== null || $html === null ? 'active' : '' ?>"><?= lang('Ui.viewText') ?></button>
                <?php if ($html !== null): ?>
                    <button type="button" data-tab="html" class="<?= $text === null ? 'active' : '' ?>"><?= lang('Ui.viewHtml') ?></button>
                <?php endif ?>
                <button type="button" data-tab="headers"><?= lang('Ui.viewHeaders') ?></button>
            </div>
            <div class="tab" data-tab="text" <?= $text !== null || $html === null ? '' : 'hidden' ?>>
                <pre class="text"><?= esc($text ?? lang('Ui.noBody')) ?></pre>
            </div>
            <?php if ($html !== null): ?>
                <div class="tab" data-tab="html" <?= $text === null ? '' : 'hidden' ?>>
                    <p class="hint"><?= lang('Ui.htmlHint') ?></p>
                    <iframe sandbox="" referrerpolicy="no-referrer" data-src="<?= site_url('messages/' . $m['id'] . '/html') ?>" title="HTML"></iframe>
                </div>
            <?php endif ?>
            <div class="tab" data-tab="headers" hidden>
                <pre class="headers"><?= esc($reader->rawHeaders()) ?></pre>
            </div>
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
