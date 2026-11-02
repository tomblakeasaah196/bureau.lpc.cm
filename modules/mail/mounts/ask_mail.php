<?php
/**
 * modules/mail/mounts/ask_mail.php — Demander à votre courrier (PR 5; docs/MAIL.md §6.2, §7.6).
 * -----------------------------------------------------------------------------
 * The button in the Mail page's toolbar and its panel (a side panel on desktop,
 * full screen on phones): Praxis AI answers a question from the mail the person
 * can read (LPC\Mail\Ai\MailAsk) and links to the messages it used. The panel
 * follows mailbox switches (mail:thread-opened / mail:refresh carry accountId):
 * "Toutes mes boîtes" or the open mailbox only. Nothing is stored.
 *
 * Shown only when the owner has entered the Gemini key; until then an admin sees
 * a "Configurer Praxis AI" link to modules/mail/ai_settings.php and everyone else
 * nothing. Driven by assets/js/modules/mail-ai.js.
 *
 * In scope: $mailMount (user_id, account_id, folder, thread_id, shared), through
 * \LPC\Mail\Mount::render('ask_mail', $mailMount).
 * -----------------------------------------------------------------------------
 */

if (!isset($mailMount)) {   // reached by URL, not through Mount::render()
    http_response_code(404);
    return;
}

$__uid = (int) ($mailMount['user_id'] ?? 0);
if (!\LPC\Mail\Ai\Ui::enabled($__uid)) {
    if (\LPC\Mail\MailAccess::canUse($__uid) && \LPC\Mail\Ai\Settings::canManage($__uid)) {
        echo '<a class="mb-btn mb-btn--ghost mai-setup" href="/modules/mail/ai_settings.php" title="Saisir la clé Gemini pour activer Praxis AI dans Courrier">'
            . \LPC\Mail\Ai\Ui::icon('sparkles') . '<span>Configurer Praxis AI</span></a>';
    }
    return;
}
$__many = count(\LPC\Mail\MailAccess::accountIds($__uid)) > 1;
\LPC\Mail\Ai\Ui::assets($mailMount);
?>
<button type="button" class="mb-btn mb-btn--ghost mai-ask-open" data-mai-ask-open aria-haspopup="dialog" aria-controls="mai-ask" title="Demander à votre courrier">
    <?= \LPC\Mail\Ai\Ui::icon('sparkles') ?><span class="mai-ask-label">Demander à votre courrier</span>
</button>

<dialog class="mb-dialog mai-panel" id="mai-ask" aria-labelledby="mai-ask-title">
    <div class="mai-panel-in">
        <header class="mai-panel-head">
            <div>
                <h2 class="mb-dialog-title mai-title" id="mai-ask-title"><?= \LPC\Mail\Ai\Ui::icon('sparkles') ?> Demander à votre courrier</h2>
                <p class="mb-hint">Praxis AI répond à partir du courrier que vous pouvez lire dans bureau et montre les messages utilisés.</p>
            </div>
            <button type="button" class="mai-close" data-mai-close aria-label="Fermer" title="Fermer"><?= \LPC\Mail\Ai\Ui::icon('x') ?></button>
        </header>

        <div class="mai-panel-body" data-mai-ask-body aria-live="polite">
            <div class="mai-examples" data-mai-ask-examples>
                <p>Posez votre question avec vos mots, par exemple :</p>
                <ul>
                    <li><button type="button" class="mai-example" data-mai-example>Marc a-t-il renvoyé la facture de septembre ?</button></li>
                    <li><button type="button" class="mai-example" data-mai-example>Quelles commandes ont été confirmées cette semaine ?</button></li>
                    <li><button type="button" class="mai-example" data-mai-example>Quel jour le client de Bonamoussadi veut-il être livré ?</button></li>
                </ul>
            </div>
            <p class="mai-asked" data-mai-asked hidden></p>
            <div class="mai-answer" data-mai-answer hidden></div>
            <div class="mai-sources" data-mai-sources hidden>
                <h3 class="mai-sources-title">Messages utilisés</h3>
                <ol data-mai-sources-list></ol>
            </div>
            <p class="mb-hint" data-mai-caution hidden>Praxis AI peut se tromper : ouvrez les messages avant d’agir sur une réponse.</p>
            <p class="mb-form-error" data-mai-ask-error role="alert" hidden></p>
            <p class="mai-busy" data-mai-ask-busy role="status" hidden><span class="mai-spinner" aria-hidden="true"></span> Praxis AI lit votre courrier…</p>
        </div>

        <div class="mai-panel-foot" data-mai-ask-form>
            <div class="mb-field">
                <label for="mai-question">Votre question</label>
                <textarea id="mai-question" name="question" rows="2" maxlength="500" aria-describedby="mai-question-hint"></textarea>
                <p class="mb-field-error" data-error-for="question" hidden></p>
                <p class="mb-hint" id="mai-question-hint">Entrée pour demander · Maj+Entrée pour aller à la ligne</p>
            </div>
            <div class="mai-row">
                <?php if ($__many): ?>
                    <div class="mai-scope">
                        <label for="mai-scope">Chercher dans</label>
                        <select id="mai-scope" class="mb-select" name="scope">
                            <option value="all">Toutes mes boîtes</option>
                            <option value="box" data-mai-scope-box>Cette boîte seulement</option>
                        </select>
                    </div>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <button type="button" class="mb-btn mb-btn--primary" data-mai-ask-submit>Demander</button>
            </div>
        </div>
    </div>
</dialog>
