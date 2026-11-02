<?php
/**
 * modules/mail/mounts/compose_ai.php — Écrire avec l'IA (PR 5; docs/MAIL.md §6.2, §7.6).
 * -----------------------------------------------------------------------------
 * The button in PR 3's composer toolbar and its dialog: the person says what the
 * message should say, Praxis AI writes a draft (LPC\Mail\Ai\WriteWithAi) from
 * that, what they wrote so far and, for a reply or a forward, the conversation;
 * the person reads it and chooses "Utiliser ce brouillon" (the composer's
 * replaceBody) or "Réessayer". The AI never sends anything.
 *
 * Reads and writes the draft only through window.LPCMailComposer (§6.3); resets
 * on mail:compose-closed. Nothing shows until the owner has entered the Gemini
 * key. Driven by assets/js/modules/mail-ai.js, which moves the dialog to the
 * end of <body> (no form inside the composer's form, none of its key handlers).
 *
 * In scope: $mailMount (user_id, account_id, folder, thread_id, shared), through
 * \LPC\Mail\Mount::render('compose_ai', $mailMount) inside the composer markup.
 * -----------------------------------------------------------------------------
 */

if (!isset($mailMount)) {   // reached by URL, not through Mount::render()
    http_response_code(404);
    return;
}

if (!\LPC\Mail\Ai\Ui::enabled((int) ($mailMount['user_id'] ?? 0))) {
    return;
}
\LPC\Mail\Ai\Ui::assets($mailMount);
?>
<button type="button" class="mb-btn mb-btn--ghost mb-btn--sm mai-write-open" data-mai-write-open aria-haspopup="dialog" aria-controls="mai-write" title="Écrire avec l’IA">
    <?= \LPC\Mail\Ai\Ui::icon('sparkles') ?><span class="mai-write-label">Écrire avec l’IA</span>
</button>

<dialog class="mb-dialog mai-write" id="mai-write" aria-labelledby="mai-write-title">
    <div class="mb-form" data-mai-write-form>
        <header class="mai-panel-head mai-panel-head--plain">
            <div>
                <h2 class="mb-dialog-title mai-title" id="mai-write-title"><?= \LPC\Mail\Ai\Ui::icon('sparkles') ?> Écrire avec l’IA</h2>
                <p class="mb-hint" data-mai-write-hint>Dites ce que le message doit dire. Praxis AI propose un brouillon ; vous le relisez avant de l’utiliser.</p>
            </div>
            <button type="button" class="mai-close" data-mai-close aria-label="Fermer" title="Fermer"><?= \LPC\Mail\Ai\Ui::icon('x') ?></button>
        </header>

        <div class="mb-field">
            <label for="mai-instruction">Que doit dire le message ?</label>
            <textarea id="mai-instruction" name="instruction" rows="3" maxlength="2000" aria-describedby="mai-instruction-hint"></textarea>
            <p class="mb-field-error" data-error-for="instruction" hidden></p>
            <p class="mb-hint" id="mai-instruction-hint">Par exemple : remercier pour la commande et proposer une livraison jeudi matin.</p>
        </div>

        <p class="mai-busy" data-mai-write-busy role="status" hidden><span class="mai-spinner" aria-hidden="true"></span> Praxis AI écrit un brouillon…</p>

        <section class="mai-draft" data-mai-draft hidden aria-labelledby="mai-draft-title">
            <h3 class="mai-sources-title" id="mai-draft-title">Brouillon proposé</h3>
            <div class="mai-draft-text" data-mai-draft-text tabindex="0"></div>
            <p class="mb-hint" data-mai-draft-note>Relisez-le et complétez les repères entre crochets. Praxis AI peut se tromper et n’envoie jamais rien.</p>
        </section>

        <div class="mb-dialog-actions">
            <button type="button" class="mb-btn mb-btn--ghost" data-mai-close>Fermer</button>
            <button type="button" class="mb-btn" data-mai-write-retry hidden>Réessayer</button>
            <button type="button" class="mb-btn mb-btn--primary" data-mai-write-submit>Écrire le brouillon</button>
            <button type="button" class="mb-btn mb-btn--primary" data-mai-write-use hidden>Utiliser ce brouillon</button>
        </div>
    </div>
</dialog>
