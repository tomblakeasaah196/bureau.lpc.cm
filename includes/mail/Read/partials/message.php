<?php
/**
 * One message in the open conversation (docs/MAIL.md §2): folded (sender, date, first words) or open (people,
 * "Ouvert par", the body made safe, pictures held back, attachments, reply buttons). Drawn by Read\Partial with:
 *   $inbox (Read\Inbox), $m (Reader::item row)
 * HTML bodies go in a sandboxed frame without scripts (Reader::document()); text bodies are already escaped.
 */

use LPC\Mail\Read\Format;
use LPC\Mail\View;

if (!defined('LPC_MAIL_READ_PARTIAL')) {
    http_response_code(404);
    return;
}

$e = [View::class, 'e'];
$body = $m['body'];
$send = $inbox->can('send');
$to = Format::people($m['to'], '', 4);
$cc = Format::people($m['cc'], '', 3);
?>
<article class="mr-msg<?= $m['open'] ? ' is-open' : '' ?><?= $m['is_unread'] ? ' is-unread' : '' ?>" data-message="<?= (int) $m['id'] ?>" data-loaded="<?= $body !== null ? '1' : '' ?>" aria-label="Message de <?= $e($m['from']) ?>">
    <button type="button" class="mr-msg-head" data-action="toggle" aria-expanded="<?= $m['open'] ? 'true' : 'false' ?>">
        <span class="mr-avatar" aria-hidden="true"><?= $e($m['initials']) ?></span>
        <span class="mr-msg-from"><strong><?= $e($m['from']) ?></strong><?php if ($m['from_email'] && $m['from_email'] !== $m['from']): ?> <span class="mr-msg-addr">&lt;<?= $e($m['from_email']) ?>&gt;</span><?php endif; ?></span>
        <span class="mr-msg-date" title="<?= $e($m['date_full']) ?>">
            <?php if ($m['is_flagged']): ?><span class="mr-flag"><?= Format::icon('flag') ?><span class="mr-sr">Suivi</span></span><?php endif; ?>
            <?php if ($m['attachments']): ?><span class="mr-msg-clip"><?= Format::icon('paperclip') ?><span class="mr-sr">Pièce jointe</span></span><?php endif; ?>
            <?= $e($m['date']) ?>
        </span>
        <span class="mr-msg-sub mr-msg-sub--open">à <?= $e($to ?: 'destinataires non divulgués') ?><?= $cc !== '' ? ', cc ' . $e($cc) : '' ?></span>
        <span class="mr-msg-sub mr-msg-sub--folded"><?= $e($m['snippet']) ?></span>
    </button>

    <div class="mr-msg-open" data-message-body>
        <?php if ($body !== null): ?>
            <div class="mr-msg-info">
                <span><b>Date</b> <?= $e($m['date_full']) ?></span>
                <?php if ($m['to']): ?><span><b>À</b> <?= $e(Format::addresses($m['to'])) ?></span><?php endif; ?>
                <?php if ($m['cc']): ?><span><b>Cc</b> <?= $e(Format::addresses($m['cc'])) ?></span><?php endif; ?>
                <?php if ($m['folder']): ?><span><b>Dans</b> <?= $e($m['folder']) ?></span><?php endif; ?>
                <?php if ($m['sent_by']): ?><span><b>Envoyé depuis bureau par</b> <?= $e($m['sent_by']) ?></span><?php endif; ?>
                <?php if ($m['opened_by']): ?><span><b>Ouvert par</b> <?= $e($m['opened_by']) ?></span><?php endif; ?>
            </div>

            <div class="mr-msg-body">
                <?php if (($body['blocked'] ?? 0) > 0 && !$m['remote']): ?>
                    <div class="mr-images" role="status">
                        <?= Format::icon('image') ?>
                        <span>Les images de cet expéditeur sont masquées pour protéger votre vie privée.</span>
                        <span class="mr-images-actions">
                            <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-action="images">Afficher les images</button>
                            <?php if ($m['from_email']): ?>
                                <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-action="trust">Toujours pour cet expéditeur</button>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($body['kind'] === 'html'): ?>
                    <iframe class="mr-frame" title="Message de <?= $e($m['from']) ?>" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                            referrerpolicy="no-referrer" srcdoc="<?= $e($body['doc']) ?>"></iframe>
                <?php elseif ($body['kind'] === 'text'): ?>
                    <?= $body['html'] /* escaped by TextFormatter */ ?>
                <?php elseif ($body['kind'] === 'error'): ?>
                    <div class="mr-alert" role="status">
                        <?= Format::icon('circle-exclamation') ?>
                        <span>Le serveur de messagerie n’a pas envoyé le texte de ce message.</span>
                        <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-action="reload-message">Réessayer</button>
                    </div>
                <?php else: ?>
                    <p class="mb-muted">Ce message n’a pas de texte.</p>
                <?php endif; ?>

                <?php if ($m['attachments']): ?>
                    <div class="mr-files" aria-label="Pièces jointes">
                        <?php foreach ($m['attachments'] as $a): ?>
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

                <?php if ($send): ?>
                    <div class="mr-msg-actions">
                        <?php if ($m['is_draft']): ?>
                            <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-compose="draft" data-message="<?= (int) $m['id'] ?>"><?= Format::icon('pen') ?> Modifier le brouillon</button>
                        <?php else: ?>
                            <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-compose="reply" data-message="<?= (int) $m['id'] ?>"><?= Format::icon('reply') ?> Répondre</button>
                            <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-compose="reply_all" data-message="<?= (int) $m['id'] ?>"><?= Format::icon('reply-all') ?> Répondre à tous</button>
                            <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-compose="forward" data-message="<?= (int) $m['id'] ?>"><?= Format::icon('share') ?> Transférer</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</article>
