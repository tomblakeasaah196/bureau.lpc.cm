<?php
/**
 * The top of the reader (docs/MAIL.md §2): the toolbar (archiver, indésirable, corbeille, déplacer, non lu, suivi,
 * supprimer définitivement, répondre…) and the conversation's subject. Drawn by Read\Partial with:
 *   $inbox (Read\Inbox), $thread (thread row), $items (Reader::item rows), $searching (bool)
 * PR 4's team bar and PR 5's summary sit just below this (their mounts stay on the page, §6.2).
 */

use LPC\Mail\Read\Format;
use LPC\Mail\Store;
use LPC\Mail\View;

if (!defined('LPC_MAIL_READ_PARTIAL')) {
    http_response_code(404);
    return;
}

$e = [View::class, 'e'];
$role = $inbox->folder['role'] ?? null;
$organise = $inbox->can('organise');
$send = $inbox->can('send');
$latest = $items ? end($items) : null;
$flagged = (bool) array_filter($items, fn ($m) => $m['is_flagged']);
$folded = count(array_filter($items, fn ($m) => !$m['open']));
$btn = fn (string $action, string $icon, string $label, string $key = '', string $extra = '') =>
    '<button type="button" class="mr-icon-btn" data-action="' . $action . '" aria-label="' . $e($label) . '" title="' . $e($label . ($key !== '' ? " ({$key})" : '')) . '"' . $extra . '>' . Format::icon($icon) . '</button>';
?>
<div class="mr-toolbar" role="toolbar" aria-label="Actions sur la conversation">
    <?= $btn('close', 'arrow-left', 'Retour à la liste', 'u', ' data-back') ?>
    <?php if ($organise): ?>
        <?php if (!in_array($role, ['archive', 'trash', 'spam'], true)): ?>
            <?= $btn('archive', 'box-archive', 'Archiver', 'e') ?>
        <?php endif; ?>
        <?php if ($role === 'spam'): ?>
            <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-action="inbox"><?= Format::icon('inbox') ?> Pas un indésirable</button>
        <?php else: ?>
            <?= $btn('spam', 'shield-halved', 'Placer dans Indésirables', '!') ?>
        <?php endif; ?>
        <?php if (in_array($role, ['trash', 'archive'], true)): ?>
            <?= $btn('inbox', 'inbox', 'Remettre dans la boîte de réception') ?>
        <?php endif; ?>
        <?php if (in_array($role, ['trash', 'spam'], true)): ?>
            <button type="button" class="mb-btn mb-btn--danger-ghost mb-btn--sm" data-action="delete" title="Supprimer définitivement (#)"><?= Format::icon('trash-can') ?> Supprimer définitivement</button>
        <?php else: ?>
            <?= $btn('trash', 'trash-can', 'Placer dans la Corbeille', '#') ?>
        <?php endif; ?>
        <label class="mr-sr" for="mr-move">Déplacer vers un dossier</label>
        <select id="mr-move" class="mb-select mr-move" data-move>
            <option value="">Déplacer…</option>
            <?php foreach ($inbox->folders as $f): ?>
                <?php if (!$inbox->folder || $f['id'] !== $inbox->folder['id']): ?>
                    <option value="<?= (int) $f['id'] ?>"><?= $e(str_repeat("\u{00A0}\u{00A0}", $inbox->depth($f)) . Store::folderLabel($f)) ?></option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
        <span class="mr-sep" aria-hidden="true"></span>
    <?php endif; ?>
    <?= $btn('unread', 'envelope', 'Marquer comme non lue', 'Maj+U') ?>
    <?php if ($organise): ?>
        <?= $btn($flagged ? 'unflag' : 'flag', 'flag', $flagged ? 'Retirer le suivi' : 'Marquer pour suivi', 's', ' aria-pressed="' . ($flagged ? 'true' : 'false') . '"' . ($flagged ? ' data-on' : '')) ?>
    <?php endif; ?>
    <span class="mr-grow"></span>
    <?php if ($send && $latest): ?>
        <?php if ($latest['is_draft']): ?>
            <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-compose="draft" data-message="<?= (int) $latest['id'] ?>"><?= Format::icon('pen') ?> Modifier le brouillon</button>
        <?php else: ?>
            <button type="button" class="mr-icon-btn" data-compose="reply" data-message="<?= (int) $latest['id'] ?>" aria-label="Répondre" title="Répondre (r)"><?= Format::icon('reply') ?></button>
            <button type="button" class="mr-icon-btn" data-compose="reply_all" data-message="<?= (int) $latest['id'] ?>" aria-label="Répondre à tous" title="Répondre à tous (a)"><?= Format::icon('reply-all') ?></button>
            <button type="button" class="mr-icon-btn" data-compose="forward" data-message="<?= (int) $latest['id'] ?>" aria-label="Transférer" title="Transférer (f)"><?= Format::icon('share') ?></button>
        <?php endif; ?>
    <?php endif; ?>
</div>
<header class="mr-subject-head">
    <h2 class="mr-subject" tabindex="-1"><?= $e($thread['subject'] ?: '(sans objet)') ?></h2>
    <div class="mr-subject-meta">
        <?php if (count($items) > 1): ?><span class="mr-tag"><?= count($items) ?> messages</span><?php endif; ?>
        <?php if ($inbox->shared() && $thread['status'] === 'done'): ?><span class="mb-pill mb-pill--success">Traitée</span><?php endif; ?>
        <?php if ($flagged): ?><span class="mr-tag mr-tag--flag"><?= Format::icon('flag') ?> Suivi</span><?php endif; ?>
        <?php if ($folded > 0): ?>
            <button type="button" class="mr-link-btn" data-action="open-all">Tout ouvrir (<?= count($items) ?>)</button>
        <?php endif; ?>
    </div>
</header>
