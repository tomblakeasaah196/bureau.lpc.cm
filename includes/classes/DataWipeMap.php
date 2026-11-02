<?php
/**
 * includes/classes/DataWipeMap.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — what the data wipe tool may take, and the links bureau keeps
 * WITHOUT a foreign key. Read by DataWipe (the engine).
 *
 * Foreign keys are read from information_schema at run time, so a table added
 * later is followed automatically. What lives here is what the schema cannot
 * say: which tables are never wiped, how the screen groups tables, which rows
 * point at a document by a type + id pair, a reference string or a time, and
 * which files on disk belong to a row.
 *
 * ADDING A TABLE
 *   · it has foreign keys only → nothing to do; it shows under "Autres" until
 *     it is placed in a module below;
 *   · it points at a document without a foreign key → add a soft link here,
 *     or a wipe will leave its rows behind;
 *   · it must never be wiped → add it to PROTECTED.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class DataWipeMap
{
    /**
     * Never wiped (owner's decision, 2 Nov 2026): staff logins, users, roles,
     * permissions and employees; the chart of accounts; settings and
     * preferences; the tool's own log. Only exception: a client's 411 or a
     * supplier's 401 sub-account goes with that client or supplier (OWNED).
     * A link from a protected row to a wiped one is cleared (set to NULL).
     */
    public const PROTECTED = [
        // Staff and access
        'users', 'roles', 'permissions', 'role_permissions', 'employees', 'employee_documents', 'employee_profiles',
        'hr_contracts', 'user_sessions', 'login_attempts', 'password_resets', 'trusted_devices', 'user_passkeys',
        'user_pin_attempts', 'user_prefs',
        // Chart of accounts and reporting structure
        'chart_of_accounts', 'ohada_accounts', 'financial_mappings', 'financial_report_lines', 'tft_lines',
        // Settings and preferences
        'app_preferences', 'company_profile', 'company_tax_settings', 'company_annexes', 'proposal_template_settings',
        'purchase_rebate_tax_settings', 'payroll_rates', 'payroll_irpp_brackets', 'payroll_crtv_brackets',
        'ai_settings', 'ai_role_limits', 'document_counters', 'treasury_accounts', 'product_categories',
        'expense_categories', 'schema_migrations',
        // Structure the migrations seed (budget envelopes of the MD view, the warehouses)
        'budget_buckets', 'budget_bucket_accounts', 'delivery_places',
        // The tool's own log
        'data_wipes', 'data_wipe_codes', 'data_wipe_lock',
    ];

    /** Whole families never wiped: Courrier holds a copy of real mail; the help centre is content. */
    public const PROTECTED_PREFIXES = ['mail_', 'help_'];

    /** Rows that only ever go WITH what they describe; never picked on their own. */
    public const FOLLOWERS_ONLY = ['audit_logs', 'audit_logs_archive'];

    /**
     * The screen's modules, in order: [key => [label, tables]]. A table that is
     * neither protected nor listed lands in "Autres", so a new table is never
     * hidden.
     */
    public const MODULES = [
        'ventes'       => ['Ventes', ['proposals', 'proposal_items', 'sales_orders', 'sales_order_items', 'deliveries', 'delivery_items',
                                      'invoices', 'invoice_items', 'invoice_deliveries', 'invoice_payment_accounts', 'payments',
                                      'withholding_certificates']],
        'clients'      => ['Clients (CRM)', ['clients', 'client_sites', 'client_prices', 'client_price_history', 'client_wallets']],
        'emballages'   => ['Emballages', ['cre_documents', 'cre_items', 'client_empties_ledger', 'recycling_sales', 'recycling_sale_items',
                                         'recycling_price_overrides']],
        'achats'       => ['Achats', ['purchase_orders', 'purchase_order_items', 'purchase_order_category_rebates', 'supplier_rebate_ledger',
                                     'suppliers', 'supplier_prices', 'supplier_price_history', 'supplier_product_rebates',
                                     'supplier_category_rebates', 'supplier_category_rebate_tiers']],
        'stock'        => ['Stock', ['products', 'inventory_movements', 'inventory_reports', 'inventory_report_items']],
        'tresorerie'   => ['Trésorerie', ['treasury_transactions', 'treasury_edit_requests', 'cash_reconciliations', 'driver_debts']],
        'depenses'     => ['Dépenses', ['expenses', 'expense_attachments', 'expense_recurring_templates', 'overheads']],
        'comptabilite' => ['Comptabilité', ['journal_entries', 'journal_lines', 'fixed_assets', 'depreciation_logs', 'fixed_asset_disposals',
                                           'tax_declarations', 'tax_declaration_lines', 'closing_runs', 'year_end_adjustments', 'financial_years']],
        'budgets'      => ['Budgets et objectifs', ['budgets', 'budget_lines', 'budget_transfers', 'budget_transfer_requests',
                                                   'budget_bucket_targets', 'performance_targets', 'operational_targets']],
        'flotte'       => ['Flotte', ['vehicles', 'vehicle_assignments', 'vehicle_expenses', 'vehicle_maintenance', 'fuel_logs']],
        'rh'           => ['RH (paie et avances)', ['hr_payslips', 'hr_advances']],
        'documents'    => ['Documents et notifications', ['pdf_documents', 'document_signatures', 'signer_otp', 'notifications', 'notifications_archive']],
    ];

    /** How a table is named on the screen; anything missing shows its table name. */
    public const LABELS = [
        'proposals' => 'Devis', 'proposal_items' => 'Lignes de devis', 'sales_orders' => 'Commandes', 'sales_order_items' => 'Lignes de commande',
        'deliveries' => 'Bons de livraison', 'delivery_items' => 'Lignes de BL', 'invoices' => 'Factures', 'invoice_items' => 'Lignes de facture',
        'invoice_deliveries' => 'Liens facture–BL', 'invoice_payment_accounts' => 'Comptes de paiement des factures', 'payments' => 'Encaissements',
        'withholding_certificates' => 'Attestations AIR', 'clients' => 'Clients', 'client_sites' => 'Sites clients', 'client_prices' => 'Tarifs clients',
        'client_price_history' => 'Historique des tarifs clients', 'client_wallets' => 'Avoirs clients (portefeuilles)',
        'cre_documents' => 'CRE (retours d\'emballages)', 'cre_items' => 'Lignes de CRE', 'client_empties_ledger' => 'Emballages dus par client',
        'recycling_sales' => 'Ventes au recycleur', 'recycling_sale_items' => 'Lignes de vente recycleur', 'recycling_price_overrides' => 'Prix recycleur modifiés',
        'purchase_orders' => 'Bons de commande', 'purchase_order_items' => 'Lignes de BC', 'purchase_order_category_rebates' => 'Ristournes par catégorie (BC)',
        'supplier_rebate_ledger' => 'Ristournes fournisseurs', 'suppliers' => 'Fournisseurs', 'supplier_prices' => 'Prix fournisseurs',
        'supplier_price_history' => 'Historique des prix fournisseurs', 'supplier_product_rebates' => 'Remises par produit',
        'supplier_category_rebates' => 'Barèmes de ristourne', 'supplier_category_rebate_tiers' => 'Paliers de ristourne', 'delivery_places' => 'Lieux de livraison',
        'products' => 'Produits', 'inventory_movements' => 'Mouvements de stock', 'inventory_reports' => 'Inventaires', 'inventory_report_items' => 'Lignes d\'inventaire',
        'treasury_transactions' => 'Opérations de trésorerie', 'treasury_edit_requests' => 'Demandes de correction (trésorerie)',
        'cash_reconciliations' => 'Retours de tournée', 'driver_debts' => 'Dettes chauffeurs', 'expenses' => 'Dépenses', 'expense_attachments' => 'Justificatifs de dépenses',
        'expense_recurring_templates' => 'Dépenses récurrentes', 'overheads' => 'Frais généraux', 'journal_entries' => 'Écritures comptables',
        'journal_lines' => 'Lignes d\'écritures', 'fixed_assets' => 'Immobilisations', 'depreciation_logs' => 'Amortissements', 'fixed_asset_disposals' => 'Cessions',
        'tax_declarations' => 'Déclarations fiscales', 'tax_declaration_lines' => 'Lignes de déclaration', 'closing_runs' => 'Clôtures (assistant)',
        'year_end_adjustments' => 'Écritures de clôture', 'financial_years' => 'Exercices', 'budgets' => 'Budgets', 'budget_lines' => 'Lignes de budget',
        'budget_transfers' => 'Virements de budget', 'budget_transfer_requests' => 'Demandes de virement', 'budget_buckets' => 'Enveloppes budgétaires',
        'budget_bucket_accounts' => 'Comptes des enveloppes', 'budget_bucket_targets' => 'Objectifs des enveloppes', 'performance_targets' => 'Objectifs de performance',
        'operational_targets' => 'Objectifs opérationnels', 'vehicles' => 'Véhicules', 'vehicle_assignments' => 'Affectations de véhicules',
        'vehicle_expenses' => 'Dépenses véhicules', 'vehicle_maintenance' => 'Entretiens', 'fuel_logs' => 'Carburant', 'hr_payslips' => 'Bulletins de paie',
        'hr_advances' => 'Avances sur salaire', 'pdf_documents' => 'PDF générés', 'document_signatures' => 'Signatures', 'signer_otp' => 'Codes de signature',
        'notifications' => 'Notifications', 'notifications_archive' => 'Notifications archivées', 'audit_logs' => 'Journal d\'activité',
        'audit_logs_archive' => 'Journal d\'activité archivé', 'chart_of_accounts' => 'Sous-comptes clients / fournisseurs',
    ];

    /**
     * A foreign key whose column may be NULL is cleared, not followed, when its
     * parent goes (a delivery keeps going without its truck)… except these,
     * where the row has no meaning without its parent.
     */
    public const NULLABLE_BUT_DEPENDENT = [
        'payments.invoice_id',   // a payment of a wiped invoice goes with it
    ];

    /**
     * Columns that point at a row with no foreign key declared, followed like
     * one: [table, column, parent table, 'delete' | 'unlink'].
     * (Quotes name their client in text, so a client wipe leaves them.)
     */
    public const VIRTUAL_FKS = [
        ['proposal_items', 'product_id', 'products', 'unlink'],
    ];

    /**
     * Lines and link rows: when one goes for any reason (its product was
     * wiped, say), the whole document goes, never a document missing a line.
     * [line table, column pointing at the document, document table]
     */
    public const WHOLE = [
        ['invoice_items', 'invoice_id', 'invoices'],
        ['invoice_deliveries', 'invoice_id', 'invoices'],
        ['sales_order_items', 'sales_order_id', 'sales_orders'],
        ['delivery_items', 'delivery_id', 'deliveries'],
        ['purchase_order_items', 'purchase_order_id', 'purchase_orders'],
        ['proposal_items', 'proposal_id', 'proposals'],
        ['cre_items', 'cre_document_id', 'cre_documents'],
        ['recycling_sale_items', 'sale_id', 'recycling_sales'],
        ['inventory_report_items', 'report_id', 'inventory_reports'],
        ['journal_lines', 'journal_entry_id', 'journal_entries'],
        ['tax_declaration_lines', 'declaration_id', 'tax_declarations'],
    ];

    /**
     * Rows a wiped row owns although the link points the other way:
     * [owner table, owner column, owned table, extra condition on the owned row `o`].
     * Every column that points at journal_entries is added at run time (a
     * document owns the entry it posted); these are the ones the schema
     * cannot say.
     */
    public const OWNED = [
        // The 411xxx / 401xxx sub-account created for a client or supplier
        ['clients',   'account_id', 'chart_of_accounts', "o.code LIKE '411%'"],
        ['suppliers', 'account_id', 'chart_of_accounts', "o.code LIKE '401%'"],
        // Tax declarations record their entries without a foreign key
        ['tax_declarations', 'payable_je_id', 'journal_entries', ''],
        ['tax_declarations', 'payment_je_id', 'journal_entries', ''],
    ];

    /** journal_entries.source_type values that point at a row of each table ("<type>_reversal" too). */
    public const JOURNAL_SOURCES = [
        'invoices'         => ['invoice', 'invoice_credit_note'],
        'deliveries'       => ['delivery'],
        'purchase_orders'  => ['purchase_order', 'rebate_accrual', 'rebate_usage', 'purchase_order_payment'],
        'hr_advances'      => ['advance_disbursement'],
        'hr_payslips'      => ['payroll_settlement'],
        'recycling_sales'  => ['recycling_sale'],
        'payments'         => ['wallet_apply'],
        'tax_declarations' => ['tax_declaration'],
    ];

    /** pdf_documents.type → the table its record_id points at */
    public const PDF_TYPES = [
        'invoice' => 'invoices', 'delivery' => 'deliveries', 'po' => 'purchase_orders', 'quote' => 'proposals',
        'cre' => 'cre_documents', 'audit' => 'inventory_reports', 'payslip' => 'hr_payslips',
    ];

    /** document_signatures.document_type and signer_otp.document_type → table */
    public const SIGNED_TYPES = [
        'quote' => 'proposals', 'cre' => 'cre_documents', 'bl' => 'deliveries', 'delivery' => 'deliveries',
        'invoice' => 'invoices', 'po' => 'purchase_orders',
    ];

    /** Notification pages that name one record: [table => related_url prefix + id] */
    public const NOTIFICATION_URLS = [
        'deliveries'   => '/modules/sales/bl_detail.php?id=',
        'sales_orders' => '/modules/sales/order_detail.php?id=',
    ];

    /**
     * Soft links: rows of `child` that belong to the given rows of `parent`
     * although no foreign key says so. {ids} is replaced by the parent ids;
     * the SQL returns the child ids. Each was traced to the code that writes it.
     */
    public static function softLinks(): array
    {
        $links = [];
        $add = function (string $parent, string $child, string $sql, string $why) use (&$links) {
            $links[] = ['parent' => $parent, 'child' => $child, 'sql' => $sql, 'why' => $why];
        };

        // Journal entries a document posted (JournalPoster::createDraftJe source_type / source_id)
        foreach (self::JOURNAL_SOURCES as $table => $types) {
            $all = [];
            foreach ($types as $t) {
                $all[] = "'{$t}'";
                $all[] = "'{$t}_reversal'";
            }
            $add($table, 'journal_entries', "SELECT id FROM journal_entries WHERE source_type IN (" . implode(',', $all) . ") AND source_id IN ({ids})",
                 'écriture générée par le document');
        }
        // Client payment entries carry no source: reference 'PAY-<payment reference>-<suffix>' (postInvoicePayment)
        $add('payments', 'journal_entries', "SELECT je.id FROM journal_entries je JOIN payments p ON je.reference LIKE CONCAT('PAY-', p.reference, '-%')
                                              WHERE p.id IN ({ids}) AND je.source_type IS NULL", 'écriture de l\'encaissement');
        // An entry and its reversal go together
        $add('journal_entries', 'journal_entries', "SELECT reversed_by_entry_id FROM journal_entries WHERE id IN ({ids}) AND reversed_by_entry_id IS NOT NULL",
             'contrepassation');
        $add('journal_entries', 'journal_entries', "SELECT id FROM journal_entries WHERE reversed_by_entry_id IN ({ids})", 'écriture contrepassée');

        // Treasury movements, by the reference the posting code writes
        $add('payments', 'treasury_transactions', "SELECT t.id FROM treasury_transactions t JOIN payments p ON t.reference = CONCAT('PAY-', p.reference) WHERE p.id IN ({ids})",
             'encaissement en trésorerie');
        $add('purchase_orders', 'treasury_transactions', "SELECT t.id FROM treasury_transactions t JOIN purchase_orders po
                                  ON t.reference IN (CONCAT('PAY-FRS-', po.reference), CONCAT('REV-PAY-FRS-', po.reference)) WHERE po.id IN ({ids})",
             'règlement fournisseur');
        $add('hr_advances', 'treasury_transactions', "SELECT t.id FROM treasury_transactions t JOIN hr_advances a ON t.reference = CONCAT('PAY-ADV-', a.id) WHERE a.id IN ({ids})",
             'versement de l\'avance');
        $add('hr_payslips', 'treasury_transactions', "SELECT t.id FROM treasury_transactions t JOIN hr_payslips s ON t.reference LIKE CONCAT('PAY-SAL-%-', s.id)
                                  AND t.transaction_type = 'out_payroll' WHERE s.id IN ({ids})", 'paiement des salaires');
        $add('recycling_sales', 'treasury_transactions', "SELECT t.id FROM treasury_transactions t JOIN recycling_sales r ON t.reference = CONCAT('REC-', r.reference) WHERE r.id IN ({ids})",
             'encaissement recycleur');
        $add('expenses', 'treasury_transactions', "SELECT t.id FROM treasury_transactions t JOIN expenses e ON t.reference = e.reference
                                  AND t.transaction_type IN ('out_expense', 'in_other') WHERE e.id IN ({ids})", 'paiement de la dépense');
        // The two legs of an internal transfer go together
        $add('treasury_transactions', 'treasury_transactions', "SELECT linked_transfer_id FROM treasury_transactions WHERE id IN ({ids}) AND linked_transfer_id IS NOT NULL",
             'autre jambe du virement');
        $add('treasury_transactions', 'treasury_transactions', "SELECT id FROM treasury_transactions WHERE linked_transfer_id IN ({ids})", 'autre jambe du virement');

        // Stock movements: reference_id means a different table per movement type, and two kinds share
        // types, so each also matches a line of the document and its time (same transaction ±1 day).
        $add('purchase_orders', 'inventory_movements', "SELECT id FROM inventory_movements WHERE movement_type = 'in_supplier' AND reference_id IN ({ids})",
             'réception en stock');
        $add('deliveries', 'inventory_movements', "SELECT m.id FROM inventory_movements m JOIN deliveries d ON d.id = m.reference_id
                 WHERE d.id IN ({ids}) AND m.movement_type IN ('out_delivery', 'in_adjustment')
                   AND EXISTS (SELECT 1 FROM delivery_items di WHERE di.delivery_id = d.id AND di.product_id = m.product_id)
                   AND m.date BETWEEN d.created_at - INTERVAL 1 DAY AND COALESCE(d.signed_at, d.updated_at, d.created_at) + INTERVAL 1 DAY",
             'sortie / retour de stock du BL');
        $add('deliveries', 'inventory_movements', "SELECT m.id FROM inventory_movements m JOIN deliveries d ON d.id = m.reference_id
                 WHERE d.id IN ({ids}) AND m.movement_type = 'in_return_emp'
                   AND EXISTS (SELECT 1 FROM delivery_items di JOIN products p ON p.id = di.product_id
                                WHERE di.delivery_id = d.id AND p.linked_empty_id = m.product_id)
                   AND m.date BETWEEN d.created_at - INTERVAL 1 DAY AND COALESCE(d.signed_at, d.updated_at, d.created_at) + INTERVAL 1 DAY",
             'emballages rentrés au BL');
        $add('recycling_sales', 'inventory_movements', "SELECT m.id FROM inventory_movements m JOIN recycling_sales r ON r.id = m.reference_id
                 WHERE r.id IN ({ids}) AND m.movement_type = 'out_delivery'
                   AND EXISTS (SELECT 1 FROM recycling_sale_items i WHERE i.sale_id = r.id AND i.product_id = m.product_id)
                   AND m.date BETWEEN r.created_at - INTERVAL 1 DAY AND r.created_at + INTERVAL 1 DAY", 'sortie de stock au recycleur');
        $add('inventory_reports', 'inventory_movements', "SELECT m.id FROM inventory_movements m JOIN inventory_reports r ON r.id = m.reference_id
                 WHERE r.id IN ({ids}) AND m.movement_type IN ('in_adjustment', 'out_adjustment')
                   AND EXISTS (SELECT 1 FROM inventory_report_items i WHERE i.report_id = r.id AND i.product_id = m.product_id)
                   AND m.date BETWEEN r.created_at - INTERVAL 1 DAY AND r.created_at + INTERVAL 1 DAY", 'écart d\'inventaire');
        // A signed CRE puts its empties back in stock without a reference (lpc_signature_side_effects_cre):
        // same product, quantity and operator, at the signature
        $add('cre_documents', 'inventory_movements', "SELECT m.id FROM inventory_movements m JOIN cre_documents c ON m.logged_by = c.operator_id
                 JOIN cre_items i ON i.cre_document_id = c.id AND i.product_id = m.product_id AND i.quantity = m.quantity
                 WHERE c.id IN ({ids}) AND m.movement_type = 'in_return_emp' AND m.reference_id IS NULL AND c.signed_at IS NOT NULL
                   AND m.date BETWEEN c.signed_at - INTERVAL 1 HOUR AND c.signed_at + INTERVAL 1 HOUR", 'emballages rentrés au CRE');

        // Price history written by an order or a purchase order (source_reference = its reference)
        $add('sales_orders', 'client_price_history', "SELECT h.id FROM client_price_history h JOIN sales_orders s ON h.source_reference = s.reference
                 WHERE s.id IN ({ids}) AND h.source = 'order'", 'prix modifié sur la commande');
        $add('purchase_orders', 'supplier_price_history', "SELECT h.id FROM supplier_price_history h JOIN purchase_orders po ON h.source_reference = po.reference
                 WHERE po.id IN ({ids}) AND h.source = 'purchase_order'", 'prix modifié sur le BC');
        $add('purchase_orders', 'supplier_rebate_ledger', "SELECT id FROM supplier_rebate_ledger WHERE purchase_order_id IN ({ids})", 'ristourne du BC');

        // Generated PDFs, signatures and signing codes of a document
        foreach (self::PDF_TYPES as $type => $table) {
            $add($table, 'pdf_documents', "SELECT id FROM pdf_documents WHERE type = '{$type}' AND record_id IN ({ids})", 'PDF du document');
        }
        foreach (self::SIGNED_TYPES as $type => $table) {
            $add($table, 'document_signatures', "SELECT id FROM document_signatures WHERE document_type = '{$type}' AND document_id IN ({ids})", 'signature du document');
        }
        foreach (['delivery' => 'deliveries', 'cre' => 'cre_documents'] as $type => $table) {
            $add($table, 'signer_otp', "SELECT id FROM signer_otp WHERE document_type = '{$type}' AND document_id IN ({ids})", 'code de signature');
        }

        return $links;
    }

    /**
     * Columns that hold a file under uploads/ ([table => [column => folder the
     * value may be relative to]]). Copied into the safety copy, deleted once no
     * remaining row uses them.
     */
    public const FILES = [
        'pdf_documents'            => ['path' => 'uploads/documents'],
        'expense_attachments'      => ['stored_path' => 'uploads/expenses'],
        'withholding_certificates' => ['file_path' => 'uploads/withholding'],
        'tax_declarations'         => ['snapshot_pdf_path' => 'uploads/tax_declarations', 'quittance_path' => 'uploads/tax_declarations/quittances'],
        'fuel_logs'                => ['receipt_image' => 'uploads/receipts'],
    ];

    /** Tables whose `reference` names the record in notification texts */
    public const NAMED_BY_REFERENCE = [
        'invoices', 'sales_orders', 'deliveries', 'purchase_orders', 'payments', 'proposals', 'cre_documents', 'expenses',
        'recycling_sales', 'inventory_reports',
    ];

    public static function isProtected(string $table): bool
    {
        if (in_array($table, self::PROTECTED, true)) {
            return true;
        }
        foreach (self::PROTECTED_PREFIXES as $p) {
            if (str_starts_with($table, $p)) {
                return true;
            }
        }
        return false;
    }

    public static function label(string $table): string
    {
        return self::LABELS[$table] ?? $table;
    }
}
