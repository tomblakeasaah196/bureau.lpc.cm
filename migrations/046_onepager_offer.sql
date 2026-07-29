-- =============================================================================
-- 046_onepager_offer.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — restore the one-page "offre commerciale" and make its own
-- copy editable from the Proposal Studio.
--
-- WHY:
--   There are two devis deliverables, and 045 accidentally collapsed them into
--   one. The 4-page bilingual HTML (public/documents/quote.php) is what a
--   prospect opens from a WhatsApp link. The dompdf render behind ?pdf=1 is the
--   one-pager a buyer prints, initials and carries into a procurement meeting.
--   045 rebuilt ?pdf=1 as a 4-page replica of the HTML, so the one-pager had no
--   implementation at all and the two buttons on the proposal page produced the
--   same file.
--
--   The one-pager cannot print the six articles, the consigne schedule and the
--   annex list — there is no room on a page meant to be signed. So it needs a
--   clause that incorporates them by reference. That clause is client-facing
--   contractual wording, which means it belongs in the Studio next to the terms
--   it refers to, not hardcoded in a PHP template.
--
-- WHAT THIS ADDS:
--   1. Three proposal_template_settings rows for the one-pager's own copy.
--   2. A fix for the two group_key values 045 introduced ('offer', 'terms')
--      that the Studio never displayed, so all of Sprint 9's editable strings
--      become reachable.
--
-- Idempotent: re-running only refreshes the metadata, as in 030 and 045.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. The one-pager's own strings.
--
--    Grouped under 'terms' rather than a new tab: an admin editing the articles
--    needs to see the clause that incorporates them by reference on the same
--    screen, otherwise the two drift apart. sort_order puts it after the annex
--    block, which is what it refers to.
-- -----------------------------------------------------------------------------
INSERT INTO proposal_template_settings
    (setting_key, value_fr, value_en, kind, group_key, label_fr, help_fr, sort_order)
VALUES
('onepager_conditions_title',
 'Conditions essentielles',
 'Key Terms',
 'text', 'offer',
 'Titre — conditions essentielles (offre 1 page)',
 'Coiffe les quatre conditions commerciales imprimées sur l''offre d''une page : fréquence, paiement, stock de sécurité, emballages.',
 80),

('onepager_terms_ref',
 'Les conditions générales de service (articles 1 à 6), le barème des consignes et les annexes font partie intégrante de la présente offre et sont remis avec la proposition commerciale complète.',
 'The general terms of service (articles 1 to 6), the deposit schedule and the appendices form an integral part of this offer and are supplied with the full commercial proposal.',
 'textarea', 'terms',
 'Clause d''incorporation (offre 1 page)',
 'L''offre d''une page n''imprime ni les six articles, ni le barème des consignes, ni les annexes. C''est cette clause qui les incorpore au contrat lorsque le client signe « Bon pour accord » : ne la videz pas, et vérifiez qu''elle reste exacte si vous renumérotez les articles.',
 90),

('onepager_more_lines',
 'Autres articles',
 'Further items',
 'text', 'offer',
 'Libellé — lignes regroupées (offre 1 page)',
 'L''offre d''une page imprime au maximum 9 lignes tarifaires. Au-delà, le reliquat est regroupé sur une ligne portant ce libellé et son total, de sorte que la somme reste juste ; le détail complet reste consultable sur la proposition en ligne.',
 81)

-- VALUES() rather than the row-alias form: 030 and 045 use it, and the alias
-- syntax needs MySQL 8.0.19+, which is a constraint this file has no reason to
-- introduce on its own.
ON DUPLICATE KEY UPDATE
    kind       = VALUES(kind),
    label_fr   = VALUES(label_fr),
    help_fr    = VALUES(help_fr),
    group_key  = VALUES(group_key),
    sort_order = VALUES(sort_order);
-- NOTE: value_fr / value_en are deliberately NOT refreshed on duplicate. A
-- re-run must never overwrite wording an admin has edited in the Studio.
-- 030 and 045 do refresh them; that is a separate question and not fixed here.


-- -----------------------------------------------------------------------------
-- 2. Make Sprint 9's strings reachable in the Studio.
--
--    045 seeded rows with group_key 'offer', 'terms' and 'cover'. The Studio
--    renders a fixed tab order — ['brand','p1','p2','logos','p3','p4','share',
--    'ui'] in modules/crm/proposal_studio.php — and skips any group not in it:
--
--        foreach ($order as $g) { if (!isset($groups[$g])) continue; ... }
--
--    So every field 045 added (the fiscal labels, the exemption mention, the
--    consigne schedule copy, the delivery and risk wording) was saved to the
--    database and never shown. Remapping onto the tabs where each string
--    actually appears in the document is the smaller, safer fix — the
--    alternative is a UI change that renames the tabs for everyone.
--
--    'offer' -> p3 (the pricing page), 'terms' -> p4 (the conditions page),
--    'cover' -> p1. sort_order is preserved, so ordering within a tab is kept.
-- -----------------------------------------------------------------------------
UPDATE proposal_template_settings SET group_key = 'p3' WHERE group_key = 'offer';
UPDATE proposal_template_settings SET group_key = 'p4' WHERE group_key = 'terms';
UPDATE proposal_template_settings SET group_key = 'p1' WHERE group_key = 'cover';


-- -----------------------------------------------------------------------------
-- 3. Drop two keys that now render nowhere.
--
--    cover_summary_title / cover_summary_total fed the "En bref" box on the
--    4-page dompdf replica. The one-pager shows the same figure in the fiscal
--    ladder, where it also states whether it is HT or TTC — which the box did
--    not. Both were seeded by 045 one day before this migration, so there is no
--    customised wording to lose.
--
--    They are deleted rather than left in place because an editable field that
--    changes no document is a trap: an admin edits it, saves, reloads the
--    proposal and finds nothing different.
-- -----------------------------------------------------------------------------
DELETE FROM proposal_template_settings
 WHERE setting_key IN ('cover_summary_title', 'cover_summary_total');


-- -----------------------------------------------------------------------------
-- VERIFY
-- -----------------------------------------------------------------------------
--   -- The three new rows, on the tabs that will show them:
--   SELECT setting_key, group_key, sort_order, LEFT(value_fr, 60) AS fr
--     FROM proposal_template_settings
--    WHERE setting_key LIKE 'onepager_%' ORDER BY group_key, sort_order;
--
--   -- Must return zero rows: nothing left in a group the Studio cannot render.
--   SELECT group_key, COUNT(*) FROM proposal_template_settings
--    WHERE group_key NOT IN ('brand','p1','p2','logos','p3','p4','share','ui')
--    GROUP BY group_key;
--
--   -- Then, in the app: Administration -> CRM -> Studio Proposition should show
--   -- a field count on the "Page 3 — Offre" and "Page 4 — Conditions" tabs that
--   -- is larger than before this migration.
--
--   -- And the deliverable itself, which is the actual point:
--   --   quote.php?token=… "Générer PDF"        -> 4 pages, WYSIWYG capture
--   --   quote.php?token=…&pdf=1                -> exactly 1 page, vector text
--   -- scripts/tests/quote_onepager.test.php asserts the second one.
