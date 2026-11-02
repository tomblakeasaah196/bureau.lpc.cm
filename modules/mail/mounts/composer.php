<?php
/**
 * modules/mail/mounts/composer.php — the composer (PR 3; docs/MAIL.md §6.2, §6.3, §12).
 * -----------------------------------------------------------------------------
 * Printed once on a Mail page through \LPC\Mail\Mount::render('composer', $mailMount).
 * Nothing shows until the browser event mail:compose {mode, accountId, messageId?,
 * outboxId?, to?} opens it: a modal dialog on desktop (aria-modal, so PR 2's
 * keyboard shortcuts stay quiet), full screen on phones. Driven by
 * assets/js/modules/mail-compose.js against api/v1/mail/compose.php, which saves
 * as the person types; the signature, the quote and the From line are added when
 * the message is built. Hosts PR 5's compose_ai mount in its toolbar.
 *
 * In scope: $mailMount (user_id, account_id, folder, thread_id, shared).
 * -----------------------------------------------------------------------------
 */

if (!isset($mailMount)) {   // reached by URL, not through Mount::render()
    http_response_code(404);
    return;
}

use LPC\Mail\Compose\ComposeConfig;
use LPC\Mail\Compose\Signatures;
use LPC\Mail\View;

$icon = static function (string $d): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
};
$chips = static function (string $field, string $label): void {
    ?>
    <div class="mc-row" data-row="<?= $field ?>"<?= $field !== 'to' ? ' hidden' : '' ?>>
        <label class="mc-label" for="mc-<?= $field ?>-input"><?= $label ?></label>
        <div class="mc-chips" data-chips="<?= $field ?>">
            <ul class="mc-chip-list" data-chip-list aria-label="Destinataires <?= $label ?>"></ul>
            <input id="mc-<?= $field ?>-input" class="mc-chip-input" type="text" inputmode="email" autocomplete="off" spellcheck="false"
                   role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="mc-<?= $field ?>-list"
                   aria-describedby="mc-<?= $field ?>-error">
            <ul class="mc-suggest" id="mc-<?= $field ?>-list" role="listbox" aria-label="Suggestions" hidden></ul>
        </div>
        <?php if ($field === 'to'): ?>
            <button type="button" class="mc-link" data-mc="show-cc" aria-expanded="false">Cc / Cci</button>
        <?php endif; ?>
        <p class="mc-field-error" id="mc-<?= $field ?>-error" data-error-for="<?= $field ?>" hidden></p>
    </div>
    <?php
};
?>
<dialog class="mc-dialog" id="mail-composer" aria-modal="true" aria-labelledby="mc-title" data-mail-composer>
    <form class="mc" method="dialog" novalidate data-mc-form>
        <header class="mc-head">
            <h2 class="mc-title" id="mc-title">Nouveau message</h2>
            <p class="mc-saved" data-mc-saved aria-live="polite"></p>
            <button type="button" class="mc-icon-btn" data-mc="close" aria-label="Fermer (le brouillon est gardé)" title="Fermer · Échap">
                <?= $icon('<path d="M6 6l12 12M18 6L6 18"/>') ?>
            </button>
        </header>

        <div class="mc-scroll">
            <div class="mc-fields">
                <div class="mc-row" data-row="from">
                    <label class="mc-label" for="mc-from">De</label>
                    <select id="mc-from" class="mc-from" data-mc-from></select>
                    <p class="mc-field-error" data-error-for="account_id" hidden></p>
                </div>
                <?php $chips('to', 'À'); $chips('cc', 'Cc'); $chips('bcc', 'Cci'); ?>
                <div class="mc-row" data-row="subject">
                    <label class="mc-label" for="mc-subject">Objet</label>
                    <input id="mc-subject" class="mc-subject" type="text" maxlength="250" autocomplete="off" data-mc-subject>
                </div>
            </div>

            <div class="mc-toolbar" role="toolbar" aria-label="Mise en forme" data-mc-toolbar>
                <button type="button" class="mc-tool" data-cmd="bold" aria-label="Gras" title="Gras · Ctrl+B"><?= $icon('<path d="M7 5h6a3.5 3.5 0 010 7H7zM7 12h7a3.5 3.5 0 010 7H7z"/>') ?></button>
                <button type="button" class="mc-tool" data-cmd="italic" aria-label="Italique" title="Italique · Ctrl+I"><?= $icon('<path d="M11 5h6M7 19h6M14 5l-4 14"/>') ?></button>
                <button type="button" class="mc-tool" data-cmd="underline" aria-label="Souligné" title="Souligné · Ctrl+U"><?= $icon('<path d="M7 4v7a5 5 0 0010 0V4M5 20h14"/>') ?></button>
                <span class="mc-tool-sep" aria-hidden="true"></span>
                <button type="button" class="mc-tool" data-cmd="insertUnorderedList" aria-label="Liste à puces" title="Liste à puces"><?= $icon('<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1" fill="currentColor"/><circle cx="4.5" cy="12" r="1" fill="currentColor"/><circle cx="4.5" cy="18" r="1" fill="currentColor"/>') ?></button>
                <button type="button" class="mc-tool" data-cmd="insertOrderedList" aria-label="Liste numérotée" title="Liste numérotée"><?= $icon('<path d="M10 6h10M10 12h10M10 18h10M4 5l1.5-1v5M3.5 14.5a1.5 1.5 0 113 .5L3.5 19h3"/>') ?></button>
                <button type="button" class="mc-tool" data-cmd="formatBlock" data-value="blockquote" aria-label="Citation" title="Citation"><?= $icon('<path d="M7 7H4v5h3l-1 5M17 7h-3v5h3l-1 5"/>') ?></button>
                <span class="mc-tool-sep" aria-hidden="true"></span>
                <button type="button" class="mc-tool" data-mc="link" aria-label="Lien" title="Lien · Ctrl+K"><?= $icon('<path d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1"/>') ?></button>
                <button type="button" class="mc-tool" data-cmd="removeFormat" aria-label="Effacer la mise en forme" title="Effacer la mise en forme"><?= $icon('<path d="M6 5h11M12 5l-4 14M4 20l16-16"/>') ?></button>
                <span class="mc-tool-grow" aria-hidden="true"></span>
                <div class="mc-ai-slot"><?php \LPC\Mail\Mount::render('compose_ai', $mailMount); ?></div>
            </div>

            <div class="mc-linkbar" data-mc-linkbar hidden>
                <label class="mc-label" for="mc-link-url">Lien</label>
                <input id="mc-link-url" type="text" inputmode="url" placeholder="https://… ou une adresse e-mail" autocomplete="off" spellcheck="false">
                <button type="button" class="mb-btn mb-btn--primary mb-btn--sm" data-mc="link-apply">Ajouter le lien</button>
                <button type="button" class="mb-btn mb-btn--ghost mb-btn--sm" data-mc="link-cancel">Annuler</button>
            </div>

            <div class="mc-body" data-mc-drop>
                <div class="mc-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Message" data-mc-editor></div>

                <?php /* In the order the message goes out: text, signature, then the original */ ?>
                <section class="mc-sig" aria-label="Signature" data-mc-sig>
                    <div class="mc-sig-bar">
                        <label class="mc-label" for="mc-sig-style">Signature</label>
                        <select id="mc-sig-style" class="mb-select" data-mc-sig-style>
                            <?php foreach (Signatures::STYLES as $k => $label): ?>
                                <option value="<?= $k ?>"><?= View::e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a class="mc-link" href="/modules/mail/signature.php">Ma signature</a>
                    </div>
                    <div class="mc-sig-preview" data-mc-sig-preview></div>
                </section>

                <div class="mc-quote" data-mc-quote hidden>
                    <div class="mc-quote-bar">
                        <button type="button" class="mc-link" data-mc="toggle-quote" aria-expanded="false" aria-controls="mc-quote-body">Afficher le message d’origine</button>
                        <label class="mc-check"><input type="checkbox" data-mc-quote-on checked> Joindre le message d’origine</label>
                    </div>
                    <div class="mc-quote-body" id="mc-quote-body" hidden data-mc-quote-body></div>
                </div>
            </div>

            <ul class="mc-files" data-mc-files aria-label="Pièces jointes"></ul>
            <p class="mc-field-error mc-files-error" data-error-for="attachments" hidden></p>
        </div>

        <p class="mc-error" role="alert" data-mc-error hidden></p>

        <footer class="mc-foot">
            <div class="mc-send">
                <button type="button" class="mb-btn mb-btn--primary mc-send-main" data-mc="send" title="Envoyer · Ctrl+Entrée">Envoyer</button>
                <button type="button" class="mb-btn mb-btn--primary mc-send-more" data-mc="schedule-menu" aria-haspopup="true" aria-expanded="false" aria-controls="mc-schedule" aria-label="Programmer l’envoi" title="Programmer l’envoi">
                    <?= $icon('<path d="M6 9l6 6 6-6"/>') ?>
                </button>
                <div class="mc-schedule" id="mc-schedule" data-mc-schedule hidden>
                    <p class="mc-schedule-title">Programmer l’envoi</p>
                    <button type="button" class="mc-schedule-opt" data-quick="tomorrow">Demain à 08:00</button>
                    <button type="button" class="mc-schedule-opt" data-quick="monday">Lundi prochain à 08:00</button>
                    <div class="mc-schedule-pick">
                        <label class="mc-label" for="mc-schedule-at">Autre moment</label>
                        <input id="mc-schedule-at" type="datetime-local" data-mc-schedule-at>
                        <p class="mc-field-error" data-error-for="send_at" hidden></p>
                        <button type="button" class="mb-btn mb-btn--primary mb-btn--sm" data-mc="schedule">Programmer</button>
                    </div>
                </div>
            </div>
            <label class="mb-btn mb-btn--ghost mc-attach" title="Joindre des fichiers (<?= View::e(View::size((int) ComposeConfig::get('max_attachment_bytes'))) ?> au total)">
                <?= $icon('<path d="M20 11.5l-8 8a5 5 0 01-7-7l8.5-8.5a3.3 3.3 0 014.7 4.7L9.7 17a1.7 1.7 0 01-2.4-2.4L15 7"/>') ?>
                <span>Joindre</span>
                <input type="file" multiple data-mc-file class="mc-file-input">
            </label>
            <span class="mc-grow" aria-hidden="true"></span>
            <button type="button" class="mb-btn mb-btn--danger-ghost" data-mc="discard" aria-label="Supprimer le brouillon"><span>Supprimer<span class="mc-hide-sm" aria-hidden="true"> le brouillon</span></span></button>
        </footer>

        <div class="mc-confirm" data-mc-confirm hidden role="alertdialog" aria-labelledby="mc-confirm-t">
            <p id="mc-confirm-t">Supprimer ce brouillon ? Il disparaît aussi des Brouillons du serveur.</p>
            <div class="mc-confirm-actions">
                <button type="button" class="mb-btn mb-btn--ghost" data-mc="discard-cancel">Garder le brouillon</button>
                <button type="button" class="mb-btn mb-btn--danger" data-mc="discard-yes">Supprimer le brouillon</button>
            </div>
        </div>
    </form>
</dialog>

<div class="mc-bar" data-mc-bar role="status" aria-live="polite" hidden>
    <span class="mc-bar-text" data-mc-bar-text></span>
    <button type="button" class="mc-bar-btn" data-mc-bar-action hidden></button>
    <button type="button" class="mc-bar-close" data-mc-bar-close aria-label="Fermer ce message"><?= $icon('<path d="M6 6l12 12M18 6L6 18"/>') ?></button>
</div>

<script type="application/json" id="mail-compose-data"><?= json_encode([
    'api'          => '/api/v1/mail/compose.php',
    'accountId'    => $mailMount['account_id'] ?? null,
    'maxBytes'     => (int) ComposeConfig::get('max_attachment_bytes'),
    'maxLabel'     => View::size((int) ComposeConfig::get('max_attachment_bytes')),
    'undoSeconds'  => (int) ComposeConfig::get('undo_seconds', 10),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-mail-compose.css') ?>">
<script src="<?= lpc_asset('/assets/js/modules/mail-compose.js') ?>" defer></script>
