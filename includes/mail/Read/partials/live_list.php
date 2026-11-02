<?php
/**
 * Mail past bureau's copy, read live from the server and not kept (docs/MAIL.md §2): "Charger le courrier plus
 * ancien" (older than 12 months, a page at a time) or "Chercher … sur le serveur". Drawn by Read\Partial with:
 *   $inbox (Read\Inbox), $mode ('older' | 'server'), $items (Reader::liveRow rows), $next (?int), $count (int)
 */

use LPC\Mail\Read\Format;
use LPC\Mail\Store;
use LPC\Mail\View;

if (!defined('LPC_MAIL_READ_PARTIAL')) {
    http_response_code(404);
    return;
}

$e = [View::class, 'e'];
$label = $inbox->folder ? Store::folderLabel($inbox->folder) : '';
?>
<div class="mr-live-head">
    <span>
        <?= Format::icon('server') ?>
        <?php if ($mode === 'older'): ?>
            Plus ancien que 12 mois, lu sur le serveur
        <?php else: ?>
            Sur le serveur : <?= $e(Format::count($count, 'résultat', 'résultats')) ?> dans <?= $e($label) ?>
        <?php endif; ?>
    </span>
    <button type="button" class="mr-icon-btn mr-icon-btn--sm" data-action="close-live" aria-label="Masquer ces résultats" title="Masquer"><?= Format::icon('xmark') ?></button>
</div>
<?php if (!$items): ?>
    <div class="mr-empty">
        <p class="mr-empty-title"><?= $mode === 'older' ? 'Aucun courrier plus ancien dans ce dossier' : 'Rien non plus sur le serveur' ?></p>
    </div>
<?php else: ?>
    <ul class="mr-rows" role="list">
        <?php foreach ($items as $item): ?>
            <li>
                <a class="mr-row<?= $item['is_seen'] ? '' : ' is-unread' ?>" href="#" data-live-uid="<?= (int) $item['uid'] ?>">
                    <span class="mr-row-from"><?= $e($item['from']) ?></span>
                    <span class="mr-row-date" title="<?= $e(Format::full($item['received_at'])) ?>"><?= $e(Format::when($item['received_at'])) ?></span>
                    <span class="mr-row-subject"><?= $e($item['subject'] ?: '(sans objet)') ?></span>
                    <span class="mr-row-snippet"></span>
                    <span class="mr-row-badges">
                        <?php if ($item['has_attachments']): ?><span title="Pièce jointe"><?= Format::icon('paperclip') ?><span class="mr-sr">Pièce jointe</span></span><?php endif; ?>
                        <?php if ($item['is_flagged']): ?><span class="mr-flag" title="Suivi"><?= Format::icon('flag') ?><span class="mr-sr">Suivi</span></span><?php endif; ?>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($mode === 'older' && $next): ?>
    <div class="mr-list-foot">
        <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-action="older" data-before="<?= (int) $next ?>"><?= Format::icon('clock-rotate-left') ?> Charger encore</button>
    </div>
<?php endif; ?>
