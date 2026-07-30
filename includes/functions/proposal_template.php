<?php
/**
 * includes/functions/proposal_template.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7E · Proposal Studio.
 *
 * Single source of truth for the editable content of the 4-page commercial
 * proposal (public/documents/quote.php) and its one-page dompdf sibling.
 *
 * Before this file, every string existed twice — once in the HTML, once in the
 * `dictionary` object in assets/js/modules/documents-quote.js — and changing a
 * sentence meant a code change and a deploy. Now the values live in
 * `proposal_template_settings` (migration 030) and are edited from
 * modules/crm/proposal_studio.php.
 *
 * FAILURE MODE, BY DESIGN
 * -----------------------
 * Every accessor falls back to the exact string that was hardcoded on
 * 28 July 2026. If the table is missing (migration not yet run), the DB is
 * unreachable, or a single key was deleted, the proposal still renders
 * correctly — it just isn't editable. A client-facing document must never
 * render with an empty heading because an admin cleared a field, so a blank
 * stored value falls back too. The one deliberate exception is
 * lpc_proposal_logos(), where blank means "hide this slot" and is honoured.
 *
 * Usage:
 *   require_once __DIR__ . '/proposal_template.php';
 *   echo lpc_proposal_text('cover_title');          // current UI language
 *   echo lpc_proposal_text('cover_title', 'en');    // explicit
 *   $all = lpc_proposal_dictionary();               // ['fr'=>[...], 'en'=>[...]]
 * -----------------------------------------------------------------------------
 */

/**
 * Hardcoded defaults — the 28 July 2026 live content, verbatim.
 * Also used by migrations/030 as its seed, and by the studio's "reset" action.
 * Shape: key => [fr, en].
 */
function lpc_proposal_defaults(): array
{
    static $d = null;
    if ($d !== null) return $d;

    return $d = [
        // Brand / identity
        'brand_company_name'   => ['La Petite Cour', 'La Petite Cour'],
        'brand_logo_main'      => ['/assets/img/full_logo.svg', '/assets/img/full_logo.svg'],
        'brand_legal_line'     => ['LA PETITE COUR | RC/DLA/2019/A/A/687', 'LA PETITE COUR | RC/DLA/2019/A/A/687'],
        'brand_footer_email'   => ['LA PETITE COUR | info@lpc.cm', 'LA PETITE COUR | info@lpc.cm'],
        'brand_footer_contact' => ['LA PETITE COUR | B.P. 5120 DOUALA | +237 696 291 800 | +237 679 535 564', 'LA PETITE COUR | P.O. BOX 5120 DOUALA | +237 696 291 800 | +237 679 535 564'],

        // Buttons
        'btn_pdf'      => ['Générer PDF', 'Generate PDF'],
        'btn_offer'    => ['Offre commerciale', 'Commercial Offer'],
        'btn_email'    => ['Email', 'Email'],
        'btn_whatsapp' => ['WhatsApp', 'WhatsApp'],

        // Page 1 — cover
        'cover_title'        => ['Proposition Commerciale', 'Commercial Proposal'],
        'cover_subtitle'     => ["Solutions d'Hydratation d'Entreprise", 'Corporate Hydration Solutions'],
        'cover_prepared_for' => ['Préparé pour :', 'Prepared For:'],
        'cover_prepared_by'  => ['Préparé par', 'Prepared By'],
        'meta_ref'           => ['Référence', 'Reference'],
        'meta_date'          => ['Date', 'Date'],
        'meta_validity'      => ['Validité', 'Validity'],
        'meta_days'          => ["Jours à compter de l'émission", 'Days from issue date'],

        // Page 2 — profile & context
        'header_p2'        => ['Profil & Contexte', 'Profile & Context'],
        'sec1_title'       => ['1. Résumé Exécutif', '1. Executive Summary'],
        'sec1_p1'          => ["La santé, le bien-être et la productivité de vos collaborateurs dépendent d'une hydratation de qualité supérieure. LA PETITE COUR a le plaisir de vous soumettre cette proposition formelle pour devenir votre partenaire privilégié en approvisionnement d'eau minérale naturelle.", 'The health, well-being, and productivity of your workforce depend on premium hydration. LA PETITE COUR is pleased to submit this formal proposal to become your preferred partner for natural mineral water supply.'],
        'sec1_p2'          => ["Notre approche repose sur trois piliers : une qualité d'eau irréprochable (certifiée ANOR), une logistique de pointe garantissant zéro rupture de stock, et un service client dédié aux comptes corporatifs.", 'Our approach rests on three pillars: impeccable water quality (ANOR certified), state-of-the-art logistics ensuring zero stock-outs, and dedicated corporate customer service.'],
        'sec2_title'       => ['2. À Propos de La Petite Cour', '2. About La Petite Cour'],
        'sec2_intro'       => ["LA PETITE COUR se spécialise dans la fourniture et la distribution d'eau potable de première qualité, destinée spécifiquement aux milieux professionnels. Nous avons conçu une offre flexible et évolutive, adaptée à la réalité des bureaux, espaces de coworking, ateliers, commerces et établissements recevant du public.", 'LA PETITE COUR specializes in the supply and distribution of premium drinking water, designed specifically for professional environments. We have designed a flexible and scalable offer, adapted to the reality of offices, coworking spaces, workshops, shops, and public establishments.'],
        'sec2_services'    => ['Nos services incluent :', 'Our services include:'],
        'sec2_l1'          => ["L'installation, la maintenance et le suivi des équipements de distribution", 'Installation, maintenance, and monitoring of distribution equipment'],
        'sec2_l2'          => ['Un service à la clientèle personnalisé et réactif', 'Personalized and responsive customer service'],
        'sec2_l3'          => ["Des solutions écoresponsables favorisant le recyclage et la réduction de l'empreinte environnementale", 'Eco-responsible solutions promoting recycling and reducing the environmental footprint'],
        'sec2_l4'          => ["La gestion des stocks et l'adaptation des volumes en fonction de l'évolution de vos effectifs", 'Inventory management and volume adaptation based on your workforce evolution'],
        'sec2_body'        => ['Avec pour mission de redéfinir la distribution B2B au Cameroun, LA PETITE COUR est distributeur corporate agréé par Sources du Pays, dédié exclusivement à la fourniture des marques Supermont et Opur aux professionnels.', 'Driven by a mission to redefine B2B distribution in Cameroon, LA PETITE COUR is an authorized corporate distributor for Sources du Pays, exclusively supplying the Supermont and Opur brands to professional environments.'],
        'sec2_gamme_title' => ['Notre Gamme & Équipements', 'Our Products & Equipment'],
        'sec2_gamme_1'     => ['Bonbonnes consignées (10L, 20L) pour distributeurs', 'Reusable bottles (10L, 20L) for water dispensers.'],
        'sec2_gamme_2'     => ['Bouteilles individuelles (33cl, 50cl, 1L, 1.5L)', 'Individual bottles (33cl, 50cl, 1L, 1.5L).'],
        'sec2_gamme_3'     => ['Fontaines réfrigérées, tempérées et systèmes sans contact', 'Refrigerated, ambient, and contactless water dispensers.'],
        'sec2_gamme_4'     => ["Accessoires (gobelets recyclables, supports, kits d'entretien)", 'Accessories (recyclable cups, stands, maintenance kits).'],
        'sec2_av_title'    => ['Avantages pour votre Entreprise', 'Corporate Advantages'],
        'sec2_av_1_title'  => ['Santé et Bien-être', 'Health & Well-being'],
        'sec2_av_1_desc'   => ["Une bonne hydratation améliore la concentration et l'efficacité.", 'Proper hydration improves focus and workplace efficiency.'],
        'sec2_av_2_title'  => ['Image et Attractivité', 'Brand Image'],
        'sec2_av_2_desc'   => ['Un espace bien équipé valorise votre marque employeur.', 'A well-equipped workspace enhances your employer brand.'],
        'sec2_b1_title'    => ['Logistique Multimodale', 'Multimodal Logistics'],
        'sec2_b1_desc'     => ['Camions anti-UV et tricycles pour une livraison rapide, même en zone dense.', 'Anti-UV trucks and tricycles for rapid delivery, even in dense urban zones.'],
        'sec2_b2_title'    => ['Stock Tampon', 'Buffer Stock'],
        'sec2_b2_desc'     => ["Capacité de stockage dédiée pour pallier aux éventuelles pénuries d'usine.", 'Dedicated storage capacity to mitigate potential factory shortages.'],
        'sec2_b3_title'    => ['Accompagnement Dédié', 'Dedicated Support'],
        'sec2_b3_desc'     => ['Conseiller unique, audits réguliers et maintenance technique de vos équipements.', 'Single point of contact, regular audits, and technical maintenance of equipment.'],
        'sec2_clients'     => ['Ils nous font confiance', 'They Trust Us'],

        // Reference logos — value_fr is the path, value_en is the alt text.
        'ref_logo_1' => ['/assets/img/prometal.png', 'Prometal'],
        'ref_logo_2' => ['/assets/img/logo-smart.webp', 'Smart Logistics'],
        'ref_logo_3' => ['/assets/img/acep_logo.svg', 'ACEP'],
        'ref_logo_4' => ['/assets/img/profab.png', 'Profab'],
        'ref_logo_5' => ['/assets/img/cafe_vienna.svg', 'Cafe Vienna'],
        'ref_logo_6' => ['/assets/img/base_cameroon.svg', 'Base Cameroon'],

        // Page 3 — offer & pricing
        'header_p3'    => ['Offre Commerciale', 'Commercial Offer'],
        'sec3_title'   => ["3. Détails de l'Offre et Tarification", '3. Offer Details & Pricing'],
        // The one-pager's own heading for this table (Sprint 10, migration
        // 048's companion change) — NOT sec3_title. The one-pager is a
        // standalone document with no page 1 or 2 of its own, so "3." in
        // front of the heading was a leftover from the 4-page proposal's
        // numbering, printed on a document where it means nothing. Kept as
        // a separate key rather than dropping the number from sec3_title,
        // because sec3_title's "3." is CORRECT there — it really is page 3
        // of 4 — and the two documents must stay free to diverge.
        'onepager_items_title' => ['Désignation et Tarification', 'Product Designation & Pricing'],
        'tbl_desc'     => ['Désignation du Produit', 'Product Description'],
        'tbl_qty'      => ['Qté (Mensuelle)', 'Est. Monthly Qty'],
        'tbl_price'    => ['Prix Unitaire', 'Unit Price'],
        'tbl_total'    => ['Prix Total', 'Total Price'],
        'tbl_grand'    => ['Montant Total Mensuel Estimé (FCFA) :', 'Estimated Monthly Total (FCFA):'],
        'tbl_note'     => ['* Note : La facturation réelle se fera sur la base des bons de livraison (BL) signés. Les prix sont exprimés en Francs CFA.', '* Note: Actual billing will be based on signed delivery notes (BL). Prices are in CFA Francs.'],

        // --- Sprint 9: the fiscal ladder ------------------------------------
        // DEV-2607-841B printed one figure, "MONTANT TOTAL MENSUEL ESTIMÉ
        // (FCFA) : 100 000", with nothing saying whether it was HT or TTC. A
        // client who budgets TTC and is invoiced HT + 19,25 % has a legitimate
        // dispute — and since the water is exonerated, the silence also threw
        // away a selling point. Same wording as the invoice, so the offer and
        // the eventual facture can never state different tax treatments.
        'tbl_subtotal'       => ['Total Hors Taxe (HT)', 'Subtotal (excl. tax)'],
        'tbl_tva'            => ['TVA', 'VAT'],
        'tbl_ttc'            => ['Montant Total Mensuel Estimé (TTC)', 'Estimated Monthly Total (incl. tax)'],
        'tbl_exempt_default' => ['Exonéré de TVA — régime applicable au produit facturé (art. 128 CGI).', 'VAT exempt — regime applicable to the goods supplied (s. 128 CGI).'],
        'tbl_words'          => ['Arrêtée la présente offre à la somme de :', 'This offer is closed at the sum of:'],
        'sec4_title'   => ['4. Accord de Niveau de Service (SLA)', '4. Service Level Agreement (SLA)'],
        'sla_freq'     => ['Fréq. de Livraison', 'Delivery Frequency'],
        'sla_buffer'   => ['Stock de Sécurité Alloué', 'Allocated Buffer Stock'],
        'sla_weeks'    => ['Semaines', 'Weeks'],
        'sla_pay'      => ['Modalités de Paiement', 'Payment Terms'],
        'sla_empties'  => ['Gestion des Emballages (Vides)', 'Empties Management'],
        'sla_validity' => ["Validité de l'Offre", 'Offer Validity'],

        // Page 4 — terms & signatures
        'header_p4'       => ['Conditions & Signatures', 'Terms & Signatures'],
        'sec5_title'      => ['5. Conditions Générales de Service', '5. General Terms of Service'],
        'tc_1_title'      => ['Article 1 : Gestion Écoresponsable des Emballages.', 'Article 1: Eco-Responsible Packaging.'],
        'tc_1_body'       => ["Dans le cadre de notre démarche environnementale, les bonbonnes de 10L et 20L sont réutilisables et mises à votre disposition. Pour assurer une rotation fluide, nous appliquons un principe d'échange (1 vide pour 1 plein). Nous comptons sur notre partenariat pour le bon soin de ces contenants.", 'As part of our environmental commitment, 10L and 20L bottles are reusable and provided for your convenience. To ensure a smooth rotation, we apply a 1-for-1 exchange principle. We rely on our collaborative partnership for the proper care of these containers.'],
        'tc_2_title'      => ['Article 2 : Maintien du Parc Matériel.', 'Article 2: Equipment Maintenance.'],
        'tc_2_body'       => ["Afin de garantir une hygiène irréprochable, les bonbonnes ne doivent contenir que de l'eau. En cas de perte ou de détérioration majeure d'un contenant, des frais de remplacement au tarif en vigueur pourront être appliqués afin d'assurer le renouvellement qualitatif de notre flotte.", 'To guarantee impeccable hygiene, bottles must contain only water. In the event of loss or significant damage to a container, replacement fees at the current rate may be applied to help us maintain a high-quality fleet.'],
        'tc_3_title'      => ['Article 3 : Facturation et Souplesse.', 'Article 3: Invoicing and Flexibility.'],
        'tc_3_body'       => ["La facturation s'effectue sur la base des bons de livraison (BL) dûment validés par vos équipes. Nous accordons des délais de paiement standards (précisés dans le SLA) pour faciliter votre comptabilité. Un suivi régulier et conjoint permet d'éviter tout retard pouvant impacter la fluidité du service.", 'Invoicing is based on delivery notes (BL) validated by your team. We grant standard payment terms (as detailed in the SLA) to facilitate your accounting. Regular, joint monitoring helps avoid delays that could impact service fluidity.'],
        'tc_4_title'      => ['Article 4 : Transparence et Évolutivité.', 'Article 4: Transparency and Scalability.'],
        // Restores "dégressivité" — the live JS carried a U+FFFD corruption here.
        'tc_4_body'       => ["Notre politique tarifaire est conçue pour être transparente et compétitive. Les tarifs peuvent bénéficier d'une dégressivité selon l'évolution de vos volumes et la durée de notre collaboration. Absolument aucun frais caché n'est appliqué.", 'Our pricing policy is designed to be transparent and competitive. Rates may benefit from a sliding scale based on your evolving volumes and the duration of our collaboration. There are absolutely no hidden fees.'],
        'tc_5_title'      => ['Article 5 : Installation et Continuité.', 'Article 5: Setup and Service Continuity.'],
        'tc_5_body'       => ["Notre service logistique s'adapte à vos horaires pour garantir des livraisons sans perturbation de votre activité. Nos techniciens assurent l'installation, l'entretien régulier des fontaines et une réactivité maximale pour vous éviter toute rupture de stock.", 'Our logistics team adapts to your schedule to ensure deliveries do not disrupt your operations. Our technicians handle installation, regular dispenser maintenance, and guarantee maximum responsiveness to prevent any stock-outs.'],
        'tc_6_title'      => ['Article 6 : Engagement de Satisfaction.', 'Article 6: Satisfaction Commitment.'],
        'tc_6_body'       => ["Votre satisfaction est au cœur de notre démarche. Un conseiller dédié vous est affecté pour analyser vos besoins, ajuster les volumes et intervenir rapidement en cas de requête spécifique. Nous garantissons le remplacement immédiat de tout équipement défectueux.", 'Your satisfaction is at the heart of our approach. A dedicated advisor is assigned to analyze your needs, adjust volumes, and intervene rapidly for any specific requests. We guarantee the prompt replacement of any defective equipment.'],
        // --- Sprint 9: consigne schedule -------------------------------------
        // Articles 1 and 2 promise "des frais de remplacement au tarif en
        // vigueur" and never state one. The amounts come from
        // products.deposit_value / replacement_value (migration 045).
        'sec_consigne_title'   => ['Barème des Consignes et Remplacements', 'Deposit & Replacement Schedule'],
        'sec_consigne_intro'   => ["Les contenants réutilisables sont mis à disposition sous consigne. La consigne est intégralement restituée au retour du contenant en bon état. Le tarif de remplacement s'applique uniquement en cas de perte ou de détérioration majeure, conformément à l'Article 2.", 'Returnable containers are provided against a refundable deposit. The deposit is returned in full when the container is returned in good condition. The replacement tariff applies only in the event of loss or major damage, per Article 2.'],
        'tbl_consigne_item'    => ['Contenant', 'Container'],
        'tbl_consigne_deposit' => ['Consigne (FCFA)', 'Deposit (FCFA)'],
        'tbl_consigne_replace' => ['Remplacement (FCFA)', 'Replacement (FCFA)'],

        // --- Sprint 9: delivery & transfer of risk ---------------------------
        // "Livraison rapide, même en zone dense" was a claim with no commitment
        // behind it, and nothing said who bore the risk in transit.
        'sec_delivery_title'      => ['Livraison et Transfert des Risques', 'Delivery & Transfer of Risk'],
        'sec_delivery_zone_label' => ['Zone de livraison', 'Delivery area'],
        'sec_delivery_zone'       => ['Douala et périphérie. Livraison hors zone sur devis complémentaire.', 'Douala and surrounding areas. Deliveries outside this area are quoted separately.'],
        'sec_delivery_cost_label' => ['Frais de transport', 'Transport cost'],
        'sec_delivery_cost'       => ['Inclus dans les prix indiqués pour la zone de livraison ci-dessus.', 'Included in the quoted prices within the delivery area above.'],
        'sec_delivery_risk_label' => ['Transfert des risques', 'Transfer of risk'],
        'sec_delivery_risk'       => ["Les marchandises voyagent sous la responsabilité de LA PETITE COUR jusqu'à leur réception sur site. Le transfert des risques s'opère à la signature du bon de livraison par le client ou son représentant. Toute réserve doit être portée sur le bon de livraison au moment de la réception.", "Goods travel at LA PETITE COUR's risk until receipt on site. Risk transfers upon signature of the delivery note by the client or their representative. Any reservation must be recorded on the delivery note at the time of receipt."],

        // --- Sprint 9: dated validity ----------------------------------------
        // "30 Jours à compter de l'émission" made the reader do arithmetic and
        // was buried on page 3 under the SLA.
        //
        // cover_summary_title / cover_summary_total were here too. They fed the
        // "En bref" box on the 4-page dompdf replica of the proposal; that
        // replica is gone and the one-pager shows the same figure in the fiscal
        // ladder, where it also carries its tax treatment. Migration 046 drops
        // the rows. A key that renders nowhere is worse than no key at all — an
        // admin edits it in the Studio, saves, and the document is unchanged.
        'meta_valid_until'    => ["Offre valable jusqu'au", 'Offer valid until'],
        'meta_revision'       => ['Révision', 'Revision'],

        // --- Sprint 9: annexes -----------------------------------------------
        'sec_annex_title' => ['Annexes', 'Appendices'],
        'sec_annex_intro' => ['Les documents suivants sont joints à la présente offre :', 'The following documents are attached to this offer:'],

        // --- One-page offre commerciale (?pdf=1) ------------------------------
        // The one-pager prints neither the six articles, nor the consigne
        // schedule, nor the annex list — there is no room for them on a page a
        // buyer initials. `onepager_terms_ref` is therefore the clause that
        // incorporates them by reference, which is what makes a signed
        // one-pager a complete contract rather than a price note. Edit it with
        // that in mind.
        'onepager_conditions_title' => ['Conditions essentielles', 'Key Terms'],
        'onepager_terms_ref'        => ["Les conditions générales de service (articles 1 à 6), le barème des consignes et les annexes font partie intégrante de la présente offre et sont remis avec la proposition commerciale complète.", 'The general terms of service (articles 1 to 6), the deposit schedule and the appendices form an integral part of this offer and are supplied with the full commercial proposal.'],
        'onepager_more_lines'       => ['Autres articles', 'Further items'],

        'sig_lpc'         => ['Pour La Petite Cour :', 'For La Petite Cour:'],
        'sig_role_lpc'    => ['Le Responsable Commerciale', 'Sales Department'],
        'sig_prep'        => ['Préparé par :', 'Prepared By:'],
        'sig_stamp'       => ['Approuvé', 'Approved'],
        'sig_client'      => ['Bon pour Accord (Le Client) :', 'Agreed & Accepted (The Client):'],
        'sig_date_client' => ['Date et Cachet :', 'Date & Stamp:'],

        // Share messages. {contact} {reference} {link} {sender} are substituted.
        'share_wa_body'       => ["Bonjour {contact},\n\nVoici le lien sécurisé vers la proposition commerciale de La Petite Cour ({reference}).\n\nLien : {link}\n\nN'hésitez pas si vous avez des questions.\n\nCordialement,\n{sender}", "Hello {contact},\n\nHere is the secure link to La Petite Cour's commercial proposal ({reference}).\n\nLink: {link}\n\nPlease do not hesitate if you have any questions.\n\nBest regards,\n{sender}"],
        'share_email_subject' => ['Proposition Commerciale - La Petite Cour ({reference})', 'Commercial Proposal - La Petite Cour ({reference})'],
        'share_email_body'    => ["Bonjour {contact},\n\nVeuillez trouver via le lien sécurisé ci-dessous notre proposition commerciale technique et financière concernant l'approvisionnement en eau minérale de vos locaux.\n\nConsulter le Devis en ligne : {link}\n\n(Note: Vous pouvez télécharger le document en PDF directement depuis ce lien).\n\nNous restons à votre entière disposition pour tout échange technique ou commercial.\n\nCordialement,\n\n{sender}\nLa Petite Cour", "Hello {contact},\n\nPlease find below the secure link to our technical and commercial proposal for the mineral water supply of your premises.\n\nView the quote online: {link}\n\n(Note: You can download the document as a PDF directly from this link).\n\nWe remain at your entire disposal for any technical or commercial discussion.\n\nBest regards,\n\n{sender}\nLa Petite Cour"],
    ];
}

/**
 * Load every stored row, keyed by setting_key.
 * Cached per-request. Returns [] on any failure — callers fall back to defaults.
 *
 * @param bool $withMeta true → include kind/group/label/help (studio needs it).
 */
function lpc_proposal_settings_raw(bool $withMeta = false): array
{
    static $cache = ['plain' => null, 'meta' => null];
    $slot = $withMeta ? 'meta' : 'plain';
    if ($cache[$slot] !== null) return $cache[$slot];

    $rows = [];
    try {
        $db = Database::getInstance()->getConnection();
        $cols = $withMeta
            ? 'setting_key, value_fr, value_en, kind, group_key, label_fr, help_fr, sort_order, updated_at'
            : 'setting_key, value_fr, value_en';
        $stmt = $db->query(
            "SELECT {$cols} FROM proposal_template_settings
              WHERE is_active = 1
              ORDER BY group_key ASC, sort_order ASC"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[$r['setting_key']] = $r;
        }
    } catch (Throwable $e) {
        // Table missing (migration 030 not yet run) or DB down. Not fatal —
        // the document renders from lpc_proposal_defaults(). Logged once so a
        // genuinely broken DB is still visible in the error monitor.
        error_log('proposal_template: falling back to defaults: ' . $e->getMessage());
    }

    return $cache[$slot] = $rows;
}

/**
 * Resolve the current UI language to 'fr' or 'en'.
 */
function lpc_proposal_lang(?string $lang = null): string
{
    if ($lang === 'en' || $lang === 'fr') return $lang;
    if (isset($_GET['lang']) && $_GET['lang'] === 'en') return 'en';
    if (function_exists('lpc_current_lang')) {
        $c = lpc_current_lang();
        if ($c === 'en' || $c === 'fr') return $c;
    }
    return 'fr';
}

/**
 * One value, in one language, with fallback.
 *
 * Resolution order: stored value → stored value in the other language →
 * hardcoded default → ''. The cross-language step matters: if somebody adds a
 * new EN string but leaves FR blank, an English reader should not see a hole.
 */
function lpc_proposal_text(string $key, ?string $lang = null): string
{
    $lang  = lpc_proposal_lang($lang);
    $col   = $lang === 'en' ? 'value_en' : 'value_fr';
    $other = $lang === 'en' ? 'value_fr' : 'value_en';
    $rows  = lpc_proposal_settings_raw();

    if (isset($rows[$key])) {
        $v = trim((string) ($rows[$key][$col] ?? ''));
        if ($v !== '') return $v;
        $v = trim((string) ($rows[$key][$other] ?? ''));
        if ($v !== '') return $v;
    }

    $def = lpc_proposal_defaults()[$key] ?? null;
    if ($def === null) return '';
    return $lang === 'en' ? ($def[1] ?? $def[0]) : $def[0];
}

/**
 * The full FR + EN dictionary, ready to hand to the browser.
 * This is what replaces the hardcoded `dictionary` object in
 * assets/js/modules/documents-quote.js.
 */
function lpc_proposal_dictionary(): array
{
    $out = ['fr' => [], 'en' => []];
    foreach (array_keys(lpc_proposal_defaults()) as $key) {
        // Reference logos are structural, not translatable copy — they are
        // delivered by lpc_proposal_logos() instead and would otherwise inject
        // file paths into the text dictionary.
        if (str_starts_with($key, 'ref_logo_')) continue;
        $out['fr'][$key] = lpc_proposal_text($key, 'fr');
        $out['en'][$key] = lpc_proposal_text($key, 'en');
    }
    return $out;
}

/**
 * The reference-logo strip on page 2.
 *
 * Unlike text, a blank path here means "hide this slot" and is honoured
 * rather than falled back — that is the only way to show fewer than six
 * logos without a code change.
 *
 * @return array<int, array{src:string, alt:string}>
 */
function lpc_proposal_logos(): array
{
    $rows = lpc_proposal_settings_raw();
    $out  = [];

    for ($i = 1; $i <= 6; $i++) {
        $key = 'ref_logo_' . $i;

        if (isset($rows[$key])) {
            $src = trim((string) ($rows[$key]['value_fr'] ?? ''));
            $alt = trim((string) ($rows[$key]['value_en'] ?? ''));
        } else {
            // No stored row at all → migration hasn't run; use the default.
            $def = lpc_proposal_defaults()[$key] ?? null;
            if ($def === null) continue;
            [$src, $alt] = $def;
        }

        if ($src === '') continue;                 // deliberately hidden
        $out[] = ['src' => $src, 'alt' => $alt !== '' ? $alt : 'Client'];
    }

    return $out;
}

/**
 * Substitute the share-message placeholders.
 * Unknown placeholders are left untouched rather than blanked, so a typo in
 * the studio is visible to the person who made it instead of silently
 * deleting half a sentence.
 */
function lpc_proposal_fill(string $template, array $vars): string
{
    foreach ($vars as $k => $v) {
        $template = str_replace('{' . $k . '}', (string) $v, $template);
    }
    return $template;
}

/**
 * Length budget for one field, per language.
 *
 * The 4-page proposal is a fixed-geometry document: each `.a4-page` is exactly
 * 297mm tall with `overflow: hidden`. Text that runs long does not reflow onto
 * another page — it is silently clipped, and nobody notices until a client
 * receives a proposal with half of Article 4 missing. So the studio caps how
 * far each field can grow.
 *
 * The budget is derived from the length of the SEEDED DEFAULT, which is the
 * text the layout was actually designed around: ±10% is the safety margin.
 * Deriving it in code rather than storing it in the DB means the limits can
 * never drift away from the defaults they were computed from.
 *
 * Three deliberate exceptions:
 *   · Very short labels — ±10% of "Date" is half a character, which would make
 *     the field uneditable. Anything under ~12 chars gets a flat +8 headroom
 *     instead; a few extra characters on a one-word label cannot break a page.
 *   · `share` messages — WhatsApp/email bodies, not laid out on the page at
 *     all. Generous cap purely to bound abuse.
 *   · `image` paths — a filesystem path has no relationship to the default's
 *     length. Capped at the column's practical limit.
 *
 * @return array{min:int, max:int}
 */
function lpc_proposal_limit(string $key, string $lang = 'fr'): array
{
    $defaults = lpc_proposal_defaults();
    $def      = $defaults[$key] ?? ['', ''];
    $text     = $lang === 'en' ? ($def[1] ?? $def[0]) : $def[0];
    $len      = mb_strlen((string) $text);

    if (str_starts_with($key, 'ref_logo_') || str_starts_with($key, 'brand_logo_')) {
        return ['min' => 0, 'max' => 255];
    }
    if (str_starts_with($key, 'share_')) {
        return ['min' => 0, 'max' => 2000];
    }
    if ($len === 0) {
        return ['min' => 0, 'max' => 200];
    }

    // ±10% of a short label is worthless — 10% of "La Petite Cour" is one and
    // a half characters, so you could not add "SARL" to your own company name.
    // Every field therefore gets whichever is larger: the percentage, or a flat
    // 8 characters of headroom. The two cross over around 80 characters, which
    // lands the behaviour where it should be:
    //
    //   · short labels ("Date", "Référence")  → absolute room, percentage is noise
    //   · long paragraphs (Article 1, résumé) → proportional room, which is what
    //     actually protects a fixed-height page from overflowing
    //
    // Measured against the seeded content this moves the tightest field from
    // +2 characters to +8, and leaves the paragraph budgets untouched.
    $max = max((int) ceil($len * 1.1), $len + 8);

    // The minimum is advisory only and never enforced. Text much shorter than
    // the design length leaves a visible gap rather than breaking the layout,
    // so it is worth flagging but not worth blocking. Short labels get no
    // minimum at all — "Date" has no business having one.
    $min = $len >= 12 ? (int) floor($len * 0.9) : 0;

    return ['min' => $min, 'max' => $max];
}

/**
 * Limits for both languages at once — the shape the studio and the controller
 * both consume, so there is exactly one definition of "too long".
 */
function lpc_proposal_limits(string $key): array
{
    $fr = lpc_proposal_limit($key, 'fr');
    $en = lpc_proposal_limit($key, 'en');
    return [
        'min_fr' => $fr['min'], 'max_fr' => $fr['max'],
        'min_en' => $en['min'], 'max_en' => $en['max'],
    ];
}

/**
 * Studio payload: every row with its metadata, grouped, with the default
 * alongside the current value so the editor can offer "reset to default"
 * and show what changed.
 */
function lpc_proposal_studio_payload(): array
{
    $rows     = lpc_proposal_settings_raw(true);
    $defaults = lpc_proposal_defaults();
    $groups   = [];

    foreach ($rows as $key => $r) {
        $def = $defaults[$key] ?? ['', ''];
        $groups[$r['group_key']][] = lpc_proposal_limits($key) + [
            'key'        => $key,
            'value_fr'   => (string) ($r['value_fr'] ?? ''),
            'value_en'   => (string) ($r['value_en'] ?? ''),
            'default_fr' => $def[0] ?? '',
            'default_en' => $def[1] ?? '',
            'kind'       => $r['kind'] ?? 'text',
            'label_fr'   => $r['label_fr'] ?? $key,
            'help_fr'    => $r['help_fr'] ?? null,
            'is_default' => ((string)($r['value_fr'] ?? '') === ($def[0] ?? ''))
                         && ((string)($r['value_en'] ?? '') === ($def[1] ?? '')),
            'updated_at' => $r['updated_at'] ?? null,
        ];
    }

    return $groups;
}

/**
 * Human labels for the studio's group tabs. Kept here rather than in the DB:
 * these name the physical pages of the proposal, which only change when the
 * template itself is redesigned in code.
 */
function lpc_proposal_group_labels(): array
{
    return [
        'brand' => ['fr' => 'Marque & Identité',      'en' => 'Brand & Identity'],
        'p1'    => ['fr' => 'Page 1 — Couverture',    'en' => 'Page 1 — Cover'],
        'p2'    => ['fr' => 'Page 2 — Profil',        'en' => 'Page 2 — Profile'],
        'logos' => ['fr' => 'Logos Références',       'en' => 'Reference Logos'],
        'p3'    => ['fr' => 'Page 3 — Offre',         'en' => 'Page 3 — Offer'],
        'p4'    => ['fr' => 'Page 4 — Conditions',    'en' => 'Page 4 — Terms'],
        'share' => ['fr' => 'Messages de Partage',    'en' => 'Share Messages'],
        'ui'    => ['fr' => 'Boutons',                'en' => 'Buttons'],
    ];
}
