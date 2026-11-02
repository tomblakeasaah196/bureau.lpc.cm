<?php
/**
 * The conversation list of the Mail page (docs/MAIL.md §2): count and pages, rows with their badges, the empty
 * states, and the way past bureau's 12 months (older mail, server search). Drawn by Read\Partial with:
 *   $inbox (Read\Inbox), $list (ThreadList result), $q (search), $filter, $openId (open thread id or 0)
 */

use LPC\Mail\People;
use LPC\Mail\Read\Format;
use LPC\Mail\Read\ThreadList;
use LPC\Mail\Store;
use LPC\Mail\View;

if (!defined('LPC_MAIL_READ_PARTIAL')) {
    http_response_code(404);
    return;
}

$e = [View::class, 'e'];
$folder = $inbox->folder;
$role = $folder['role'] ?? null;
$label = $folder ? Store::folderLabel($folder) : 'ce dossier';
$total = $list['total'];
$page = $list['page'];
$per = ThreadList::PER_PAGE;
$lastPage = max(1, (int) ceil($total / $per));
$searching = $q !== '';
$toLine = in_array($role, ['sent', 'drafts'], true);
$shared = $inbox->shared();
$labels = $inbox->labels();
$mailbox = $inbox->account['email'] ?? '';
?>
<div class="mr-list-meta">
    <span class="mr-list-count" aria-live="polite">
        <?php if ($searching): ?>
            <?= $e(Format::count($total, 'conversation trouvée', 'conversations trouvées')) ?>
        <?php elseif ($total): ?>
            <?= ($page - 1) * $per + 1 ?>–<?= min($total, $page * $per) ?> sur <?= $total ?>
        <?php else: ?>
            <?= $e($label) ?>
        <?php endif; ?>
    </span>
    <span class="mr-pager">
        <?php if ($inbox->can('organise') && in_array($role, ['trash', 'spam'], true) && !$searching && $total): ?>
            <button type="button" class="mb-btn mb-btn--danger-ghost mb-btn--sm" data-action="empty">Vider <?= $role === 'trash' ? 'la Corbeille' : 'les indésirables' ?></button>
        <?php endif; ?>
        <?php if ($lastPage > 1): ?>
            <button type="button" class="mr-icon-btn mr-icon-btn--sm" data-page="<?= $page - 1 ?>"<?= $page <= 1 ? ' disabled' : '' ?> aria-label="Page précédente" title="Plus récents"><?= Format::icon('chevron-left') ?></button>
            <button type="button" class="mr-icon-btn mr-icon-btn--sm" data-page="<?= $page + 1 ?>"<?= $page >= $lastPage ? ' disabled' : '' ?> aria-label="Page suivante" title="Plus anciens"><?= Format::icon('chevron-right') ?></button>
        <?php endif; ?>
    </span>
</div>

<?php if (!$list['rows']): ?>
    <div class="mr-empty">
        <?php if ($searching): ?>
            <p class="mr-empty-title">Rien dans la copie de bureau ne correspond à « <?= $e(mb_strimwidth($q, 0, 60, '…')) ?> »</p>
            <p>bureau garde les 12 derniers mois. Cherchez le courrier plus ancien sur le serveur ci-dessous.</p>
        <?php elseif ($filter !== 'all'): ?>
            <p class="mr-empty-title">Rien à afficher avec ce filtre</p>
            <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-filter="all">Tout afficher</button>
        <?php elseif ($folder && !$folder['initial_synced_at']): ?>
            <p class="mr-empty-title">bureau copie ce dossier</p>
            <p>Le courrier le plus récent arrive d’abord. La liste se met à jour d’elle-même.</p>
        <?php else: ?>
            <p class="mr-empty-title">Aucun message dans « <?= $e($label) ?> »</p>
            <?php if ($role === 'inbox'): ?><p>Le nouveau courrier s’affiche ici dès son arrivée.</p><?php endif; ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <ul class="mr-rows" role="list">
        <?php foreach ($list['rows'] as $t):
            $unread = $t['row_unread'] > 0;
            $active = $t['id'] === $openId;
            $names = Format::people($t['participants'], $mailbox) ?: $mailbox;
            $tags = [];
            if ($searching) {
                foreach ($t['row_folders'] as $fid) {
                    if (isset($labels[$fid]) && !in_array($labels[$fid], $tags, true)) {
                        $tags[] = $labels[$fid];
                    }
                }
                $tags = array_slice($tags, 0, 2);
            }
            $href = '?box=' . (int) $inbox->account['id'] . ($folder ? '&folder=' . rawurlencode($inbox->key($folder)) : '') . '&thread=' . (int) $t['id'];
            ?>
            <li>
                <a class="mr-row<?= $unread ? ' is-unread' : '' ?><?= $active ? ' is-active' : '' ?>" href="<?= $e($href) ?>" data-thread="<?= (int) $t['id'] ?>"<?= $active ? ' aria-current="true"' : '' ?>>
                    <span class="mr-row-from"><?= $toLine ? 'À : ' : '' ?><?= $e($names) ?><?php if ($t['message_count'] > 1): ?><span class="mr-row-n"><?= (int) $t['message_count'] ?></span><?php endif; ?></span>
                    <span class="mr-row-date" title="<?= $e(Format::full($t['row_last_at'])) ?>"><?= $e(Format::when($t['row_last_at'])) ?></span>
                    <span class="mr-row-subject"><?php if ($unread): ?><span class="mr-sr">Non lu : </span><?php endif; ?><?= $e($t['subject'] ?: '(sans objet)') ?></span>
                    <span class="mr-row-snippet"><?= $e($t['snippet'] ?? '') ?></span>
                    <span class="mr-row-badges">
                        <?php foreach ($tags as $tag): ?><span class="mr-tag"><?= $e($tag) ?></span><?php endforeach; ?>
                        <?php if ($t['row_draft'] && $role !== 'drafts'): ?><span class="mr-tag mr-tag--draft">Brouillon</span><?php endif; ?>
                        <?php if ($shared && $t['status'] === 'done'): ?><span class="mb-pill mb-pill--success">Traitée</span><?php endif; ?>
                        <?php if ($shared && $t['assigned_to']): ?><span class="mr-tag" title="Assignée à <?= $e(People::name($t['assigned_to'])) ?>"><?= Format::icon('user-check') ?> <?= $t['assigned_to'] === $inbox->userId ? 'Vous' : $e(People::firstName($t['assigned_to'])) ?></span><?php endif; ?>
                        <?php if ($t['row_attach']): ?><span title="Pièce jointe"><?= Format::icon('paperclip') ?><span class="mr-sr">Pièce jointe</span></span><?php endif; ?>
                        <?php if ($t['row_flagged']): ?><span class="mr-flag" title="Suivi"><?= Format::icon('flag') ?><span class="mr-sr">Suivi</span></span><?php endif; ?>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<div data-live></div>

<?php if ($folder): ?>
    <div class="mr-list-foot" data-list-foot>
        <?php if ($searching): ?>
            <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-action="server-search"><?= Format::icon('server') ?> Chercher dans le courrier plus ancien sur le serveur</button>
            <p>Cherche dans tout le dossier <?= $e($label) ?>, à toutes les dates. C’est un peu plus long.</p>
        <?php elseif ($page >= $lastPage && ($folder['initial_synced_at'] || $total)): ?>
            <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-action="older"><?= Format::icon('clock-rotate-left') ?> Charger le courrier plus ancien</button>
            <p>bureau garde les 12 derniers mois. Le courrier plus ancien est lu sur le serveur.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>
