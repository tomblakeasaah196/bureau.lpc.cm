-- =============================================================================
-- migrations/030_proposal_template.sql
-- -----------------------------------------------------------------------------
-- Sprint 7E — Proposal Studio.
--
-- Every string, logo and brand detail on the 4-page commercial proposal
-- (public/documents/quote.php) was hardcoded in two places: the HTML itself
-- and the `dictionary` object in assets/js/modules/documents-quote.js. Changing
-- a single sentence — or the list of client logos — meant a code edit, a
-- commit, a push and a deploy.
--
-- This migration moves all of it into `proposal_template_settings`, a
-- key/value store with one row per editable element and a column per
-- language. modules/crm/proposal_studio.php is the editor.
--
-- Design notes:
--   * FR and EN live in the SAME ROW, not as two rows with a lang column.
--     A translated pair is one editable unit; splitting it makes it possible
--     to save one side and silently orphan the other.
--   * `kind` drives the widget the studio renders (text / textarea / image /
--     url) and nothing else. Adding a kind never requires a schema change.
--   * `sort_order` is spaced by 10 so a field can be inserted between two
--     existing ones without renumbering.
--   * Seeded values are the exact strings that were live on 28 July 2026, so
--     running this migration changes nothing visible. It only makes the
--     existing proposal editable.
--
-- Idempotent: safe to re-run. Re-running does NOT clobber edits made in the
-- studio — see the ON DUPLICATE KEY UPDATE clause on the seed, which only
-- refreshes the metadata columns (kind/group/sort/label), never the values.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. The store.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS proposal_template_settings (
    id           INT(11)      NOT NULL AUTO_INCREMENT,
    setting_key  VARCHAR(64)  NOT NULL,
    value_fr     TEXT         NULL,
    value_en     TEXT         NULL,
    kind         ENUM('text','textarea','image','url') NOT NULL DEFAULT 'text',
    group_key    VARCHAR(32)  NOT NULL DEFAULT 'general',
    label_fr     VARCHAR(160) NOT NULL DEFAULT '',
    help_fr      VARCHAR(255) NULL,
    sort_order   SMALLINT     NOT NULL DEFAULT 0,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by   INT(11)      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_setting_key (setting_key),
    KEY idx_group_sort (group_key, sort_order),
    KEY idx_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FK on updated_by. Wrapped because migration 006's helper proc may or may not
-- still exist on a given environment; a missing FK here is cosmetic.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'proposal_template_settings'
       AND CONSTRAINT_NAME = 'fk_pts_updated_by'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE proposal_template_settings
       ADD CONSTRAINT fk_pts_updated_by FOREIGN KEY (updated_by)
       REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. Permission gating the studio.
--    Deliberately separate from crm.proposals.create: writing a quote for one
--    client and rewriting the contract terms every client will see are very
--    different levels of authority.
-- -----------------------------------------------------------------------------
INSERT INTO permissions (name, module, description)
VALUES ('crm.proposals.template', 'crm',
        "Modifier le modèle de proposition commerciale (Studio)")
ON DUPLICATE KEY UPDATE
    module = VALUES(module),
    description = VALUES(description);

-- Granted to nobody by default beyond admin's `*`. An admin can hand it to
-- a commercial lead from Administration → Rôles & Permissions.

-- -----------------------------------------------------------------------------
-- 3. Seed — the live 28 July 2026 content.
-- -----------------------------------------------------------------------------
INSERT INTO proposal_template_settings
    (setting_key, value_fr, value_en, kind, group_key, label_fr, help_fr, sort_order)
VALUES
-- ---- Marque & identité -------------------------------------------------------
('brand_company_name', 'La Petite Cour', 'La Petite Cour', 'text', 'brand', 'Nom commercial', 'Affiché dans la barre de navigation et le pied de page.', 10),
('brand_logo_main', '/assets/img/full_logo.svg', '/assets/img/full_logo.svg', 'image', 'brand', 'Logo principal', 'Utilisé sur la page de garde et la barre de navigation.', 20),
('brand_legal_line', 'LA PETITE COUR | RC/DLA/2019/A/A/687', 'LA PETITE COUR | RC/DLA/2019/A/A/687', 'text', 'brand', 'Mention légale (pied de page p.2)', NULL, 30),
('brand_footer_email', 'LA PETITE COUR | info@lpc.cm', 'LA PETITE COUR | info@lpc.cm', 'text', 'brand', 'Pied de page (p.3)', NULL, 40),
('brand_footer_contact', 'LA PETITE COUR | B.P. 5120 DOUALA | +237 696 291 800 | +237 679 535 564', 'LA PETITE COUR | P.O. BOX 5120 DOUALA | +237 696 291 800 | +237 679 535 564', 'text', 'brand', 'Coordonnées (pied de page p.4)', NULL, 50),

-- ---- Boutons -----------------------------------------------------------------
('btn_pdf', 'Générer PDF', 'Generate PDF', 'text', 'ui', 'Bouton — PDF complet', NULL, 10),
('btn_offer', 'Offre commerciale', 'Commercial Offer', 'text', 'ui', 'Bouton — offre 1 page', NULL, 20),
('btn_email', 'Email', 'Email', 'text', 'ui', 'Bouton — Email', NULL, 30),
('btn_whatsapp', 'WhatsApp', 'WhatsApp', 'text', 'ui', 'Bouton — WhatsApp', NULL, 40),

-- ---- Page 1 : couverture -----------------------------------------------------
('cover_title', 'Proposition Commerciale', 'Commercial Proposal', 'text', 'p1', 'Titre de couverture', NULL, 10),
('cover_subtitle', "Solutions d'Hydratation d'Entreprise", 'Corporate Hydration Solutions', 'text', 'p1', 'Sous-titre', NULL, 20),
('cover_prepared_for', 'Préparé pour :', 'Prepared For:', 'text', 'p1', 'Libellé « préparé pour »', NULL, 30),
('cover_prepared_by', 'Préparé par', 'Prepared By', 'text', 'p1', 'Libellé « préparé par »', NULL, 40),
('meta_ref', 'Référence', 'Reference', 'text', 'p1', 'Libellé « référence »', NULL, 50),
('meta_date', 'Date', 'Date', 'text', 'p1', 'Libellé « date »', NULL, 60),
('meta_validity', 'Validité', 'Validity', 'text', 'p1', 'Libellé « validité »', NULL, 70),
('meta_days', "Jours à compter de l'émission", 'Days from issue date', 'text', 'p1', 'Suffixe « jours »', NULL, 80),

-- ---- Page 2 : profil & contexte ----------------------------------------------
('header_p2', 'Profil & Contexte', 'Profile & Context', 'text', 'p2', 'En-tête page 2', NULL, 10),
('sec1_title', '1. Résumé Exécutif', '1. Executive Summary', 'text', 'p2', 'Titre — résumé exécutif', NULL, 20),
('sec1_p1', "La santé, le bien-être et la productivité de vos collaborateurs dépendent d'une hydratation de qualité supérieure. LA PETITE COUR a le plaisir de vous soumettre cette proposition formelle pour devenir votre partenaire privilégié en approvisionnement d'eau minérale naturelle.", 'The health, well-being, and productivity of your workforce depend on premium hydration. LA PETITE COUR is pleased to submit this formal proposal to become your preferred partner for natural mineral water supply.', 'textarea', 'p2', 'Résumé — paragraphe 1', NULL, 30),
('sec1_p2', "Notre approche repose sur trois piliers : une qualité d'eau irréprochable (certifiée ANOR), une logistique de pointe garantissant zéro rupture de stock, et un service client dédié aux comptes corporatifs.", 'Our approach rests on three pillars: impeccable water quality (ANOR certified), state-of-the-art logistics ensuring zero stock-outs, and dedicated corporate customer service.', 'textarea', 'p2', 'Résumé — paragraphe 2', NULL, 40),
('sec2_title', '2. À Propos de La Petite Cour', '2. About La Petite Cour', 'text', 'p2', 'Titre — à propos', NULL, 50),
('sec2_intro', "LA PETITE COUR se spécialise dans la fourniture et la distribution d'eau potable de première qualité, destinée spécifiquement aux milieux professionnels. Nous avons conçu une offre flexible et évolutive, adaptée à la réalité des bureaux, espaces de coworking, ateliers, commerces et établissements recevant du public.", 'LA PETITE COUR specializes in the supply and distribution of premium drinking water, designed specifically for professional environments. We have designed a flexible and scalable offer, adapted to the reality of offices, coworking spaces, workshops, shops, and public establishments.', 'textarea', 'p2', 'À propos — introduction', NULL, 60),
('sec2_services', 'Nos services incluent :', 'Our services include:', 'text', 'p2', 'Titre — liste services', NULL, 70),
('sec2_l1', "L'installation, la maintenance et le suivi des équipements de distribution", 'Installation, maintenance, and monitoring of distribution equipment', 'text', 'p2', 'Service 1', NULL, 80),
('sec2_l2', 'Un service à la clientèle personnalisé et réactif', 'Personalized and responsive customer service', 'text', 'p2', 'Service 2', NULL, 90),
('sec2_l3', "Des solutions écoresponsables favorisant le recyclage et la réduction de l'empreinte environnementale", 'Eco-responsible solutions promoting recycling and reducing the environmental footprint', 'text', 'p2', 'Service 3', NULL, 100),
('sec2_l4', "La gestion des stocks et l'adaptation des volumes en fonction de l'évolution de vos effectifs", 'Inventory management and volume adaptation based on your workforce evolution', 'text', 'p2', 'Service 4', NULL, 110),
('sec2_body', "Avec pour mission de redéfinir la distribution B2B au Cameroun, LA PETITE COUR est distributeur corporate agréé par Sources du Pays, dédié exclusivement à la fourniture des marques Supermont et Opur aux professionnels.", 'Driven by a mission to redefine B2B distribution in Cameroon, LA PETITE COUR is an authorized corporate distributor for Sources du Pays, exclusively supplying the Supermont and Opur brands to professional environments.', 'textarea', 'p2', 'À propos — corps', NULL, 120),
('sec2_gamme_title', 'Notre Gamme & Équipements', 'Our Products & Equipment', 'text', 'p2', 'Titre — gamme', NULL, 130),
('sec2_gamme_1', 'Bonbonnes consignées (10L, 20L) pour distributeurs', 'Reusable bottles (10L, 20L) for water dispensers.', 'text', 'p2', 'Gamme 1', NULL, 140),
('sec2_gamme_2', 'Bouteilles individuelles (33cl, 50cl, 1L, 1.5L)', 'Individual bottles (33cl, 50cl, 1L, 1.5L).', 'text', 'p2', 'Gamme 2', NULL, 150),
('sec2_gamme_3', 'Fontaines réfrigérées, tempérées et systèmes sans contact', 'Refrigerated, ambient, and contactless water dispensers.', 'text', 'p2', 'Gamme 3', NULL, 160),
('sec2_gamme_4', "Accessoires (gobelets recyclables, supports, kits d'entretien)", 'Accessories (recyclable cups, stands, maintenance kits).', 'text', 'p2', 'Gamme 4', NULL, 170),
('sec2_av_title', 'Avantages pour votre Entreprise', 'Corporate Advantages', 'text', 'p2', 'Titre — avantages', NULL, 180),
('sec2_av_1_title', 'Santé et Bien-être', 'Health & Well-being', 'text', 'p2', 'Avantage 1 — titre', NULL, 190),
('sec2_av_1_desc', "Une bonne hydratation améliore la concentration et l'efficacité.", 'Proper hydration improves focus and workplace efficiency.', 'textarea', 'p2', 'Avantage 1 — texte', NULL, 200),
('sec2_av_2_title', 'Image et Attractivité', 'Brand Image', 'text', 'p2', 'Avantage 2 — titre', NULL, 210),
('sec2_av_2_desc', 'Un espace bien équipé valorise votre marque employeur.', 'A well-equipped workspace enhances your employer brand.', 'textarea', 'p2', 'Avantage 2 — texte', NULL, 220),
('sec2_b1_title', 'Logistique Multimodale', 'Multimodal Logistics', 'text', 'p2', 'Atout 1 — titre', NULL, 230),
('sec2_b1_desc', 'Camions anti-UV et tricycles pour une livraison rapide, même en zone dense.', 'Anti-UV trucks and tricycles for rapid delivery, even in dense urban zones.', 'textarea', 'p2', 'Atout 1 — texte', NULL, 240),
('sec2_b2_title', 'Stock Tampon', 'Buffer Stock', 'text', 'p2', 'Atout 2 — titre', NULL, 250),
('sec2_b2_desc', "Capacité de stockage dédiée pour pallier aux éventuelles pénuries d'usine.", 'Dedicated storage capacity to mitigate potential factory shortages.', 'textarea', 'p2', 'Atout 2 — texte', NULL, 260),
('sec2_b3_title', 'Accompagnement Dédié', 'Dedicated Support', 'text', 'p2', 'Atout 3 — titre', NULL, 270),
('sec2_b3_desc', 'Conseiller unique, audits réguliers et maintenance technique de vos équipements.', 'Single point of contact, regular audits, and technical maintenance of equipment.', 'textarea', 'p2', 'Atout 3 — texte', NULL, 280),
('sec2_clients', 'Ils nous font confiance', 'They Trust Us', 'text', 'p2', 'Titre — références clients', NULL, 290),

-- ---- Logos de références (page 2) --------------------------------------------
-- Blank value_fr hides the slot entirely; see lpc_proposal_logos().
('ref_logo_1', '/assets/img/prometal.png', 'Prometal', 'image', 'logos', 'Logo référence 1', "Laisser vide pour masquer. Le champ EN sert de texte alternatif.", 10),
('ref_logo_2', '/assets/img/logo-smart.webp', 'Smart Logistics', 'image', 'logos', 'Logo référence 2', NULL, 20),
('ref_logo_3', '/assets/img/acep_logo.svg', 'ACEP', 'image', 'logos', 'Logo référence 3', NULL, 30),
('ref_logo_4', '/assets/img/profab.png', 'Profab', 'image', 'logos', 'Logo référence 4', NULL, 40),
('ref_logo_5', '/assets/img/cafe_vienna.svg', 'Cafe Vienna', 'image', 'logos', 'Logo référence 5', NULL, 50),
('ref_logo_6', '/assets/img/base_cameroon.svg', 'Base Cameroon', 'image', 'logos', 'Logo référence 6', NULL, 60),

-- ---- Page 3 : offre & tarification -------------------------------------------
('header_p3', 'Offre Commerciale', 'Commercial Offer', 'text', 'p3', 'En-tête page 3', NULL, 10),
('sec3_title', "3. Détails de l'Offre et Tarification", '3. Offer Details & Pricing', 'text', 'p3', 'Titre — offre', NULL, 20),
('tbl_desc', 'Désignation du Produit', 'Product Description', 'text', 'p3', 'Colonne — désignation', NULL, 30),
('tbl_qty', 'Qté (Mensuelle)', 'Est. Monthly Qty', 'text', 'p3', 'Colonne — quantité', NULL, 40),
('tbl_price', 'Prix Unitaire', 'Unit Price', 'text', 'p3', 'Colonne — prix unitaire', NULL, 50),
('tbl_total', 'Prix Total', 'Total Price', 'text', 'p3', 'Colonne — total', NULL, 60),
('tbl_grand', 'Montant Total Mensuel Estimé (FCFA) :', 'Estimated Monthly Total (FCFA):', 'text', 'p3', 'Libellé — total général', NULL, 70),
('tbl_note', '* Note : La facturation réelle se fera sur la base des bons de livraison (BL) signés. Les prix sont exprimés en Francs CFA.', '* Note: Actual billing will be based on signed delivery notes (BL). Prices are in CFA Francs.', 'textarea', 'p3', 'Note sous le tableau', NULL, 80),
('sec4_title', "4. Accord de Niveau de Service (SLA)", '4. Service Level Agreement (SLA)', 'text', 'p3', 'Titre — SLA', NULL, 90),
('sla_freq', 'Fréq. de Livraison', 'Delivery Frequency', 'text', 'p3', 'SLA — fréquence', NULL, 100),
('sla_buffer', 'Stock de Sécurité Alloué', 'Allocated Buffer Stock', 'text', 'p3', 'SLA — stock tampon', NULL, 110),
('sla_weeks', 'Semaines', 'Weeks', 'text', 'p3', 'SLA — unité semaines', NULL, 120),
('sla_pay', 'Modalités de Paiement', 'Payment Terms', 'text', 'p3', 'SLA — paiement', NULL, 130),
('sla_empties', 'Gestion des Emballages (Vides)', 'Empties Management', 'text', 'p3', 'SLA — emballages', NULL, 140),
('sla_validity', "Validité de l'Offre", 'Offer Validity', 'text', 'p3', 'SLA — validité', NULL, 150),

-- ---- Page 4 : conditions & signatures ----------------------------------------
('header_p4', 'Conditions & Signatures', 'Terms & Signatures', 'text', 'p4', 'En-tête page 4', NULL, 10),
('sec5_title', '5. Conditions Générales de Service', '5. General Terms of Service', 'text', 'p4', 'Titre — conditions', NULL, 20),
('tc_1_title', 'Article 1 : Gestion Écoresponsable des Emballages.', 'Article 1: Eco-Responsible Packaging.', 'text', 'p4', 'Article 1 — titre', 'Laisser le titre vide pour masquer entièrement cet article.', 30),
('tc_1_body', "Dans le cadre de notre démarche environnementale, les bonbonnes de 10L et 20L sont réutilisables et mises à votre disposition. Pour assurer une rotation fluide, nous appliquons un principe d'échange (1 vide pour 1 plein). Nous comptons sur notre partenariat pour le bon soin de ces contenants.", 'As part of our environmental commitment, 10L and 20L bottles are reusable and provided for your convenience. To ensure a smooth rotation, we apply a 1-for-1 exchange principle. We rely on our collaborative partnership for the proper care of these containers.', 'textarea', 'p4', 'Article 1 — texte', NULL, 40),
('tc_2_title', 'Article 2 : Maintien du Parc Matériel.', 'Article 2: Equipment Maintenance.', 'text', 'p4', 'Article 2 — titre', NULL, 50),
('tc_2_body', "Afin de garantir une hygiène irréprochable, les bonbonnes ne doivent contenir que de l'eau. En cas de perte ou de détérioration majeure d'un contenant, des frais de remplacement au tarif en vigueur pourront être appliqués afin d'assurer le renouvellement qualitatif de notre flotte.", 'To guarantee impeccable hygiene, bottles must contain only water. In the event of loss or significant damage to a container, replacement fees at the current rate may be applied to help us maintain a high-quality fleet.', 'textarea', 'p4', 'Article 2 — texte', NULL, 60),
('tc_3_title', 'Article 3 : Facturation et Souplesse.', 'Article 3: Invoicing and Flexibility.', 'text', 'p4', 'Article 3 — titre', NULL, 70),
('tc_3_body', "La facturation s'effectue sur la base des bons de livraison (BL) dûment validés par vos équipes. Nous accordons des délais de paiement standards (précisés dans le SLA) pour faciliter votre comptabilité. Un suivi régulier et conjoint permet d'éviter tout retard pouvant impacter la fluidité du service.", 'Invoicing is based on delivery notes (BL) validated by your team. We grant standard payment terms (as detailed in the SLA) to facilitate your accounting. Regular, joint monitoring helps avoid delays that could impact service fluidity.', 'textarea', 'p4', 'Article 3 — texte', NULL, 80),
('tc_4_title', 'Article 4 : Transparence et Évolutivité.', 'Article 4: Transparency and Scalability.', 'text', 'p4', 'Article 4 — titre', NULL, 90),
-- NOTE: the FR value below fixes a mojibake corruption ("dégressivit\xEF\xBF\xBD")
-- that was live in documents-quote.js. Restored to "dégressivité".
('tc_4_body', "Notre politique tarifaire est conçue pour être transparente et compétitive. Les tarifs peuvent bénéficier d'une dégressivité selon l'évolution de vos volumes et la durée de notre collaboration. Absolument aucun frais caché n'est appliqué.", 'Our pricing policy is designed to be transparent and competitive. Rates may benefit from a sliding scale based on your evolving volumes and the duration of our collaboration. There are absolutely no hidden fees.', 'textarea', 'p4', 'Article 4 — texte', NULL, 100),
('tc_5_title', 'Article 5 : Installation et Continuité.', 'Article 5: Setup and Service Continuity.', 'text', 'p4', 'Article 5 — titre', NULL, 110),
('tc_5_body', "Notre service logistique s'adapte à vos horaires pour garantir des livraisons sans perturbation de votre activité. Nos techniciens assurent l'installation, l'entretien régulier des fontaines et une réactivité maximale pour vous éviter toute rupture de stock.", 'Our logistics team adapts to your schedule to ensure deliveries do not disrupt your operations. Our technicians handle installation, regular dispenser maintenance, and guarantee maximum responsiveness to prevent any stock-outs.', 'textarea', 'p4', 'Article 5 — texte', NULL, 120),
('tc_6_title', 'Article 6 : Engagement de Satisfaction.', 'Article 6: Satisfaction Commitment.', 'text', 'p4', 'Article 6 — titre', NULL, 130),
('tc_6_body', "Votre satisfaction est au cœur de notre démarche. Un conseiller dédié vous est affecté pour analyser vos besoins, ajuster les volumes et intervenir rapidement en cas de requête spécifique. Nous garantissons le remplacement immédiat de tout équipement défectueux.", 'Your satisfaction is at the heart of our approach. A dedicated advisor is assigned to analyze your needs, adjust volumes, and intervene rapidly for any specific requests. We guarantee the prompt replacement of any defective equipment.', 'textarea', 'p4', 'Article 6 — texte', NULL, 140),
('sig_lpc', 'Pour La Petite Cour :', 'For La Petite Cour:', 'text', 'p4', 'Signature — libellé société', NULL, 150),
('sig_role_lpc', 'Le Responsable Commerciale', 'Sales Department', 'text', 'p4', 'Signature — fonction', NULL, 160),
('sig_prep', 'Préparé par :', 'Prepared By:', 'text', 'p4', 'Signature — préparé par', NULL, 170),
('sig_stamp', 'Approuvé', 'Approved', 'text', 'p4', 'Cachet « approuvé »', NULL, 180),
('sig_client', 'Bon pour Accord (Le Client) :', 'Agreed & Accepted (The Client):', 'text', 'p4', 'Signature — client', NULL, 190),
('sig_date_client', 'Date et Cachet :', 'Date & Stamp:', 'text', 'p4', 'Signature — date et cachet', NULL, 200),

-- ---- Messages de partage -----------------------------------------------------
-- {contact} {reference} {link} {sender} are substituted at send time.
('share_wa_body', "Bonjour {contact},\n\nVoici le lien sécurisé vers la proposition commerciale de La Petite Cour ({reference}).\n\nLien : {link}\n\nN'hésitez pas si vous avez des questions.\n\nCordialement,\n{sender}", "Hello {contact},\n\nHere is the secure link to La Petite Cour's commercial proposal ({reference}).\n\nLink: {link}\n\nPlease do not hesitate if you have any questions.\n\nBest regards,\n{sender}", 'textarea', 'share', 'Message WhatsApp', 'Variables disponibles : {contact} {reference} {link} {sender}', 10),
('share_email_subject', 'Proposition Commerciale - La Petite Cour ({reference})', 'Commercial Proposal - La Petite Cour ({reference})', 'text', 'share', 'Objet de l''email', 'Variables disponibles : {contact} {reference} {link} {sender}', 20),
('share_email_body', "Bonjour {contact},\n\nVeuillez trouver via le lien sécurisé ci-dessous notre proposition commerciale technique et financière concernant l'approvisionnement en eau minérale de vos locaux.\n\nConsulter le Devis en ligne : {link}\n\n(Note: Vous pouvez télécharger le document en PDF directement depuis ce lien).\n\nNous restons à votre entière disposition pour tout échange technique ou commercial.\n\nCordialement,\n\n{sender}\nLa Petite Cour", "Hello {contact},\n\nPlease find below the secure link to our technical and commercial proposal for the mineral water supply of your premises.\n\nView the quote online: {link}\n\n(Note: You can download the document as a PDF directly from this link).\n\nWe remain at your entire disposal for any technical or commercial discussion.\n\nBest regards,\n\n{sender}\nLa Petite Cour", 'textarea', 'share', 'Corps de l''email', 'Variables disponibles : {contact} {reference} {link} {sender}', 30)

ON DUPLICATE KEY UPDATE
    -- Metadata is code-owned and refreshed on every run.
    kind       = VALUES(kind),
    group_key  = VALUES(group_key),
    label_fr   = VALUES(label_fr),
    help_fr    = VALUES(help_fr),
    sort_order = VALUES(sort_order);
    -- value_fr / value_en are deliberately ABSENT: they are user-owned once
    -- the studio has been used. Re-running this migration must never silently
    -- revert somebody's edits. Use the studio's per-field "Réinitialiser"
    -- action to go back to a seeded default.

COMMIT;

-- =============================================================================
-- Verification:
--   SELECT COUNT(*) FROM proposal_template_settings;
--   -- expect 90 rows on a fresh install.
--
--   SELECT group_key, COUNT(*) FROM proposal_template_settings GROUP BY group_key;
--   -- brand 5 | ui 4 | p1 8 | p2 29 | logos 6 | p3 15 | p4 20 | share 3
--
--   SELECT setting_key FROM proposal_template_settings
--    WHERE value_fr IS NULL OR value_fr = '' OR value_en IS NULL OR value_en = '';
--   -- expect 0 rows immediately after seeding.
--
--   SELECT p.name FROM permissions p WHERE p.name = 'crm.proposals.template';
--   -- expect 1 row.
-- =============================================================================
