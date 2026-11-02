<?php
/**
 * An older message (past bureau's 12 months) in the reader, read live from the server and not kept
 * (docs/MAIL.md §2). Drawn by Read\Partial with: $inbox (Read\Inbox), $item (Reader::live row, or null when the
 * server no longer has it or did not answer), $uid
 */

use LPC\Mail\Read\Format;
use LPC\Mail\View;

if (!defined('LPC_MAIL_READ_PARTIAL')) {
    http_response_code(404);
    return;
}

$e = [View::class, 'e'];
?>
<div class="mr-toolbar" role="toolbar" aria-label="Actions sur le message">
    <button type="button" class="mr-icon-btn" data-action="close" data-back aria-label="Retour à la liste" title="Retour à la liste (u)"><?= Format::icon('arrow-left') ?></button>
    <span class="mr-toolbar-note"><?= Format::icon('server') ?> Lu sur le serveur : plus ancien que 12 mois</span>
</div>
<?php if (!$item): ?>
    <div class="mr-reader-pad">
        <div class="mr-alert" role="status">
            <?= Format::icon('circle-exclamation') ?>
            <span>Le serveur de messagerie n’a pas envoyé ce message. Il a peut-être été déplacé ou supprimé.</span>
            <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-live-uid="<?= (int) $uid ?>">Réessayer</button>
        </div>
    </div>
<?php else:
    $body = $item['body'];
    $to = Format::people($item['to'], '', 4);
    ?>
    <header class="mr-subject-head">
        <h2 class="mr-subject" tabindex="-1"><?= $e($item['subject'] ?: '(sans objet)') ?></h2>
    </header>
    <div class="mr-messages">
        <article class="mr-msg is-open" aria-label="Message de <?= $e($item['from']) ?>" data-live-message="<?= (int) $item['uid'] ?>">
            <div class="mr-msg-head mr-msg-head--static">
                <span class="mr-avatar" aria-hidden="true"><?= $e($item['initials']) ?></span>
                <span class="mr-msg-from"><strong><?= $e($item['from']) ?></strong><?php if ($item['from_email'] && $item['from_email'] !== $item['from']): ?> <span class="mr-msg-addr">&lt;<?= $e($item['from_email']) ?>&gt;</span><?php endif; ?></span>
                <span class="mr-msg-date"><?= $e($item['date_full']) ?></span>
                <span class="mr-msg-sub mr-msg-sub--open">à <?= $e($to ?: 'destinataires non divulgués') ?></span>
            </div>
            <div class="mr-msg-open">
                <div class="mr-msg-info">
                    <?php if ($item['to']): ?><span><b>À</b> <?= $e(Format::addresses($item['to'])) ?></span><?php endif; ?>
                    <?php if ($item['cc']): ?><span><b>Cc</b> <?= $e(Format::addresses($item['cc'])) ?></span><?php endif; ?>
                    <span><b>Dans</b> <?= $e($item['folder']) ?></span>
                </div>
                <div class="mr-msg-body">
                    <?php if (($body['blocked'] ?? 0) > 0 && !$item['remote']): ?>
                        <div class="mr-images" role="status">
                            <?= Format::icon('image') ?>
                            <span>Les images de cet expéditeur sont masquées pour protéger votre vie privée.</span>
                            <span class="mr-images-actions">
                                <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-action="live-images">Afficher les images</button>
                                <?php if ($item['from_email']): ?>
                                    <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-action="live-trust">Toujours pour cet expéditeur</button>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if ($body['kind'] === 'html'): ?>
                        <iframe class="mr-frame" title="Message de <?= $e($item['from']) ?>" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                                referrerpolicy="no-referrer" srcdoc="<?= $e($body['doc']) ?>"></iframe>
                    <?php elseif ($body['kind'] === 'text'): ?>
                        <?= $body['html'] /* escaped by TextFormatter */ ?>
                    <?php else: ?>
                        <p class="mb-muted">Ce message n’a pas de texte.</p>
                    <?php endif; ?>

                    <?php if ($item['attachments']): ?>
                        <div class="mr-files" aria-label="Pièces jointes">
                            <?php foreach ($item['attachments'] as $a): ?>
                                <span class="mr-file">
                                    <?= Format::icon('paperclip') ?>
                                    <span class="mr-file-name" title="<?= $e($a['filename']) ?>"><?= $e($a['filename']) ?></span>
                                    <span class="mr-file-size"><?= $e(View::size((int) $a['size'])) ?></span>
                                    <?php if ($a['viewable']): ?>
                                        <a class="mr-icon-btn mr-icon-btn--sm" href="<?= $e($a['url']) ?>&amp;view=1" target="_blank" rel="noopener" aria-label="Ouvrir <?= $e($a['filename']) ?>" title="Ouvrir"><?= Format::icon('eye') ?></a>
                                    <?php endif; ?>
                                    <a class="mr-icon-btn mr-icon-btn--sm" href="<?= $e($a['url']) ?>" download aria-label="Télécharger <?= $e($a['filename']) ?>" title="Télécharger"><?= Format::icon('download') ?></a>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <p class="mb-muted mr-live-note">Répondre à un courrier de plus de 12 mois n’est pas encore possible dans bureau.</p>
                </div>
            </div>
        </article>
    </div>
<?php endif; ?>
