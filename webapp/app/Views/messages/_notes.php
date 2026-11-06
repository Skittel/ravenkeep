<?php
/**
 * Notes of a message: oldest first, newest at the bottom; written once, never changed.
 *
 * @var array $m
 * @var list<array> $notes
 * @var bool $hidden
 */
?>
<div class="tab notes" data-tab="notes" <?= $hidden ? 'hidden' : '' ?>>
    <?php if ($notes === []): ?>
        <p class="muted"><?= lang('Ui.noNotes') ?></p>
    <?php endif ?>
    <ol class="note-list">
        <?php foreach ($notes as $n): ?>
            <li class="note">
                <div class="note-meta"><strong title="<?= esc((string) ($n['author_username'] ?? ''), 'attr') ?>"><?= esc($n['author_name']) ?></strong>
                    <span><?= esc(rk_time($n['created_at'], 'd.m.Y H:i')) ?></span>
                    <span class="muted">· <?= esc(rk_relative_time($n['created_at'])) ?></span></div>
                <div class="note-body"><?= esc($n['body']) ?></div>
            </li>
        <?php endforeach ?>
    </ol>
    <?= form_open('messages/' . $m['id'] . '/notes', ['class' => 'note-form']) ?>
        <textarea name="body" rows="3" maxlength="<?= App\Libraries\Messages\MessageWork::NOTE_MAX ?>" required placeholder="<?= esc(lang('Ui.notePlaceholder'), 'attr') ?>"></textarea>
        <div><button type="submit" class="button"><?= lang('Ui.addNote') ?></button> <span class="hint"><?= lang('Ui.noteHint') ?></span></div>
    <?= form_close() ?>
</div>
