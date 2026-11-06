<?php
/**
 * Rating ("Status 1") and handling ("Status 2") of a message as small badges.
 *
 * @var array $m row with rating, handling, handling_until
 */
$rating   = $m['rating'] ?? null;
$handling = $m['handling'] ?? 'new';
$until    = $m['handling_until'] ?? null;
$today    = (new DateTimeImmutable('now', new DateTimeZone(config('Ravenkeep')->displayTimezone)))->format('Y-m-d');
$due      = $handling === 'waiting' && $until !== null && $until <= $today;
$untilFmt = $until !== null ? (new DateTimeImmutable($until))->format('d.m.Y') : '';
?>
<span class="work work-rating rating-<?= esc($rating ?? 'none', 'attr') ?>"><?= lang('Ui.rating_' . ($rating ?? 'none')) ?></span><span class="work work-handling handling-<?= esc($handling, 'attr') ?><?= $due ? ' handling-due' : '' ?>"<?= $until !== null ? ' title="' . esc(lang($due ? 'Ui.handlingDue' : 'Ui.handlingUntil', [$untilFmt]), 'attr') . '"' : '' ?>><?= $handling === 'waiting' && $until !== null ? esc(lang('Ui.handlingUntil', [(new DateTimeImmutable($until))->format('d.m.')])) : lang('Ui.handling_' . $handling) ?></span>
