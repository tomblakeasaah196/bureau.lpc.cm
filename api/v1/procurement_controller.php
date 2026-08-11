<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';   // Sprint 5
require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
require_once __DIR__ . '/../../includes/functions/procurement.php';
// Supplier tariff + the price-change log. Migration 063 — a supplier changing
// their price is a new price, not a remise and not a ristourne.
require_once __DIR__ . '/../../includes/functions/pricing.php';
require_once __DIR__ . '/../../includes/functions/notify.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Baseline gate. Every write action below re-checks with its own permission:
// save_po needs .create_po, cancel_inventory needs .approve. Before that, a
// read-only role could create and delete purchase orders.
Rbac::requirePermission('inventory.procurement.view');
// api/v1/procurement_controller.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

// ==========================================
// 1. STRICT RBAC SECURITY GATE
// ==========================================
$user_id = (int)$_SESSION['user_id'];

// ==========================================
// 2. PAYLOAD ROUTER (Handles both JSON and POST)
// ==========================================
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// If action isn't in GET/POST, it might be a JSON payload (used by the PO Editor)
$jsonData = [];
if (empty($action)) {
    $rawInput = file_get_contents('php://input');
    $jsonData = json_decode($rawInput, true);
    $action = $jsonData['action'] ?? '';
}

if (empty($action)) {
    echo json_encode(['status' => 'error', 'message' => 'Action non spécifiée']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    switch ($action) {

        // ==========================================
        // ACTION: FETCH DROPDOWN METADATA
        // ==========================================
        case 'fetch_metadata':
            // suppliers.default_payment_account_id — migration 093. Detected
            // rather than assumed so the metadata call still returns on an
            // install that has not run 093 yet; the picker in the PO form
            // will simply have no pre-selection until the migration lands.
            $has_default = (int) $db->query("
                SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'suppliers'
                   AND column_name = 'default_payment_account_id'
            ")->fetchColumn() > 0;

            $supplier_cols = $has_default
                ? "id, name, default_payment_account_id"
                : "id, name, NULL AS default_payment_account_id";
            $suppliers = $db->query("SELECT {$supplier_cols} FROM suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

            $products = $db->query("SELECT id, name, format, base_price FROM products WHERE category != 'Emballage' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $delivery_places = $db->query("SELECT id, name FROM delivery_places WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

            // Migration 093 · treasury_accounts for the "Payé" branch of the
            // PO form. Same shape as expenses_controller::form_meta returns —
            // the picker in modules/inventory/procurement.php reuses the
            // dropdown behaviour already familiar from Gestion des Dépenses.
            $treasury_accounts = $db->query("
                SELECT id, name, type, balance
                  FROM treasury_accounts
                 WHERE status = 'active'
                 ORDER BY sort_order ASC, type, name
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Suppliers with at least one active, non-zero ladder tier — used
            // by the Ristournes modal to default to a supplier that actually
            // has something configured, instead of whichever supplier sorts
            // first alphabetically.
            $suppliers_with_ladder = $db->query("
                SELECT DISTINCT s.id
                  FROM suppliers s
                  JOIN supplier_category_rebates scr ON scr.supplier_id = s.id AND scr.is_active = 1
                  JOIN supplier_category_rebate_tiers t ON t.supplier_category_rebate_id = scr.id AND t.rate > 0
                 ORDER BY s.id ASC
            ")->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'suppliers' => $suppliers,
                    'products' => $products,
                    'delivery_places' => $delivery_places,
                    'treasury_accounts' => $treasury_accounts,
                    'suppliers_with_ladder' => array_map('intval', $suppliers_with_ladder)
                ]
            ]);
            break;

        // ==========================================
        // ACTION: LIST UNPAID POs BY SUPPLIER (PR-1, migration 093)
        // ------------------------------------------------------------------
        // Drives the Payer Fournisseur modal on modules/inventory/
        // procurement.php. Returns non-cancelled POs with payment_status =
        // 'unpaid', newest first, so the buyer can tick which to settle in
        // one go against a chosen treasury account.
        //
        // Cancelled orders are excluded — their payable has already been
        // reversed by JournalPoster::reverseSource('purchase_order', …) and
        // there is nothing to pay.
        //
        // Partial receptions are still listed at their total_amount. The
        // JE fires at settlement, not at reception, so what we owe the
        // supplier is what the order committed to — a partial reception
        // does not reduce the payable, only the goods received. This is
        // also consistent with what the goods-receipt JE credits to 401.
        // ==========================================
        case 'list_unpaid_pos_by_supplier':
            Rbac::requirePermission('inventory.procurement.view');
            $supplier_id = (int)($_GET['supplier_id'] ?? 0);
            if ($supplier_id <= 0) throw new UserFacingException("Fournisseur non spécifié.");

            $stmt = $db->prepare("
                SELECT id, reference, date, total_amount, status
                  FROM purchase_orders
                 WHERE supplier_id = ?
                   AND payment_status = 'unpaid'
                   AND status <> 'cancelled'
                 ORDER BY date DESC, id DESC
            ");
            $stmt->execute([$supplier_id]);
            $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'pos'         => $pos,
                    'grand_total' => array_sum(array_map(fn($p) => (float)$p['total_amount'], $pos)),
                ],
            ]);
            break;

        // ==========================================
        // ACTION: SETTLE ONE OR MORE UNPAID POs (PR-1, migration 093)
        // ------------------------------------------------------------------
        // The standalone Payer Fournisseur path. Given a supplier, a
        // treasury account, a payment date, and a list of PO ids, this
        // action fires JournalPoster::postSupplierPayment once per PO in
        // ONE transaction. Either every JE posts and every treasury row
        // updates, or the whole batch rolls back — no partial state.
        //
        // The JE per PO (not one bulk JE for the batch) is deliberate: it
        // matches the AR-side convention (one JE per invoice payment), it
        // preserves the source_type/source_id link that cancel_po relies
        // on to reverse the payment, and it keeps the treasury journal
        // readable ("PAY-FRS-PO-2608-… 200 000" instead of an opaque
        // "Batch règlement 4 factures 800 000").
        //
        // Permission gate: only .approve, mirroring cancel — settling
        // multiple POs at once has the same authority requirement as any
        // other ledger-changing operation on the payable side.
        // ==========================================
        case 'settle_supplier_pos':
            Rbac::requirePermission('inventory.procurement.approve');

            $supplier_id         = (int)($jsonData['supplier_id'] ?? 0);
            $treasury_account_id = (int)($jsonData['treasury_account_id'] ?? 0);
            $payment_date        = trim((string)($jsonData['payment_date'] ?? ''));
            $po_ids              = $jsonData['po_ids'] ?? [];
            $note                = trim((string)($jsonData['note'] ?? ''));

            if ($supplier_id <= 0)         throw new UserFacingException("Fournisseur non spécifié.");
            if ($treasury_account_id <= 0) throw new UserFacingException("Compte de trésorerie non spécifié.");
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
                throw new UserFacingException("Date de paiement invalide.");
            }
            if (!is_array($po_ids) || empty($po_ids)) {
                throw new UserFacingException("Aucun bon de commande sélectionné.");
            }

            // Migration 093 must have run for postSupplierPayment to have
            // anywhere to stamp payment_je_id. Fail loudly before opening a
            // transaction rather than half-way through, so nothing partial.
            if (!lpc_po_has_payment_columns($db)) {
                throw new UserFacingException(
                    "La migration 093 n'a pas été appliquée. Contactez l'administrateur."
                );
            }

            // Verify the treasury account is active before we start (same
            // check save_po uses).
            $chk = $db->prepare("SELECT 1 FROM treasury_accounts WHERE id = ? AND status = 'active'");
            $chk->execute([$treasury_account_id]);
            if (!$chk->fetchColumn()) {
                throw new UserFacingException("Compte de trésorerie invalide ou inactif.");
            }

            $db->beginTransaction();

            // Re-fetch each PO INSIDE the transaction. Two reasons: (1) the
            // client payload cannot be trusted for amounts — the amount to
            // pay comes from purchase_orders.total_amount, not from the
            // browser; (2) FOR UPDATE (inside postSupplierPayment) serialises
            // against a concurrent Payer Fournisseur click on the same PO.
            $verify = $db->prepare("
                SELECT id, reference, supplier_id, payment_status, total_amount
                  FROM purchase_orders
                 WHERE id = ? AND status <> 'cancelled'
            ");

            $posted     = [];
            $grand_paid = 0.0;
            foreach ($po_ids as $raw_id) {
                $po_id = (int) $raw_id;
                if ($po_id <= 0) continue;

                $verify->execute([$po_id]);
                $po = $verify->fetch(PDO::FETCH_ASSOC);
                if (!$po) {
                    throw new UserFacingException("Bon de commande #{$po_id} introuvable ou annulé.");
                }
                if ((int) $po['supplier_id'] !== $supplier_id) {
                    // The picker is scoped by supplier; a mismatch means
                    // either payload tampering or a UI bug. Refuse either way.
                    throw new UserFacingException(
                        "Le bon de commande {$po['reference']} n'appartient pas à ce fournisseur."
                    );
                }
                if ($po['payment_status'] === 'paid') {
                    // Somebody else settled it between the list and the
                    // click. Roll back and let the buyer refresh, rather
                    // than silently skipping one PO out of the batch.
                    throw new UserFacingException(
                        "Le bon de commande {$po['reference']} est déjà payé. Rafraîchissez la liste."
                    );
                }

                $je_id = JournalPoster::postSupplierPayment(
                    $po_id,
                    $treasury_account_id,
                    (float) $po['total_amount'],
                    $payment_date,
                    $note
                );

                $posted[]    = ['po_id' => $po_id, 'reference' => $po['reference'], 'je_id' => $je_id, 'amount' => (float)$po['total_amount']];
                $grand_paid += (float) $po['total_amount'];
            }

            $db->commit();

            $supName = $db->prepare("SELECT name FROM suppliers WHERE id = ?");
            $supName->execute([$supplier_id]);
            lpc_notify_permission(
                $db,
                'accounting.cashflow.view',
                'Règlement fournisseur',
                ($_SESSION['user_name'] ?? 'Un opérateur') . " a réglé " . count($posted) . " bon(s) de commande pour "
                    . ($supName->fetchColumn() ?: "#$supplier_id") . " — " . number_format($grand_paid, 0, ',', ' ') . " FCFA.",
                '/modules/inventory/procurement.php',
                'info',
                [$user_id]
            );

            echo json_encode([
                'status'  => 'success',
                'message' => sprintf(
                    'Règlement de %d bon(s) de commande effectué (%s FCFA).',
                    count($posted),
                    number_format($grand_paid, 0, ',', ' ')
                ),
                'data'    => ['posted' => $posted, 'grand_paid' => $grand_paid],
            ]);
            break;

        // ==========================================
        // ACTION: FETCH RISTOURNE DATA (SDP ONLY)
        // ==========================================
        case 'get_ristourne_data':
            $supplier_id = (int)($_GET['supplier_id'] ?? 0);

            $stmt = $db->prepare("SELECT * FROM supplier_rebate_ledger WHERE supplier_id = ? ORDER BY date DESC, id DESC");
            $stmt->execute([$supplier_id]);
            $ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Totals come from the shared helper rather than being re-derived
            // here, so the panel, the discount check on order creation and the
            // cancellation path can never disagree about the balance. The
            // helper also excludes rows a cancellation has reversed; the full
            // ledger above is still returned unfiltered so the history shows
            // the claw-back rather than hiding it.
            $totals = lpc_rebate_balance($db, $supplier_id);

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'balance' => $totals['balance'],
                    'earned'  => $totals['earned'],
                    'used'    => $totals['used'],
                    'ledger'  => $ledger
                ]
            ]);
            break;

        // ==========================================
        // ACTION: RISTOURNE LADDER CONFIG (modules/inventory/rebate_config.php)
        // Per (supplier, category) ladder — migration 052. General capability:
        // any supplier can be configured against any category.
        // ==========================================
        case 'rebate_categories_list':
            $rows = $db->query("SELECT id, code, name FROM product_categories WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $rows]);
            break;

        // One row per category (whether or not it has a ladder yet), each
        // with its tiers (empty array if none configured) — the UI shows
        // every category for the chosen supplier and lets you build a ladder
        // from scratch on any of them.
        case 'rebate_ladder_get':
            $supplier_id = (int)($_GET['supplier_id'] ?? 0);
            if ($supplier_id <= 0) throw new UserFacingException("Fournisseur non spécifié.");

            $categories = $db->query("SELECT id, code, name FROM product_categories WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);

            $stmtScr = $db->prepare("SELECT id, is_active FROM supplier_category_rebates WHERE supplier_id = ? AND category_id = ?");
            $stmtTiers = $db->prepare("SELECT id, tier_order, threshold_from, threshold_to, rate FROM supplier_category_rebate_tiers WHERE supplier_category_rebate_id = ? ORDER BY tier_order ASC");

            foreach ($categories as &$cat) {
                $stmtScr->execute([$supplier_id, $cat['id']]);
                $scr = $stmtScr->fetch(PDO::FETCH_ASSOC);
                if ($scr) {
                    $stmtTiers->execute([$scr['id']]);
                    $cat['ladder_id'] = (int) $scr['id'];
                    $cat['is_active'] = (bool) $scr['is_active'];
                    $cat['tiers']     = $stmtTiers->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $cat['ladder_id'] = null;
                    $cat['is_active'] = false;
                    $cat['tiers']     = [];
                }
            }
            unset($cat);

            echo json_encode(['status' => 'success', 'data' => $categories]);
            break;

        // Toggles (and creates on first activation) the (supplier, category)
        // ladder row itself. A category with is_active=0 or no row at all
        // earns nothing, regardless of what tiers exist.
        case 'rebate_ladder_toggle':
            Rbac::requirePermission('inventory.procurement.create_po');
            $supplier_id = (int)($jsonData['supplier_id'] ?? 0);
            $category_id = (int)($jsonData['category_id'] ?? 0);
            $is_active   = !empty($jsonData['is_active']) ? 1 : 0;
            if ($supplier_id <= 0 || $category_id <= 0) {
                throw new UserFacingException("Fournisseur et catégorie sont requis.");
            }
            $db->prepare("
                INSERT INTO supplier_category_rebates (supplier_id, category_id, is_active)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)
            ")->execute([$supplier_id, $category_id, $is_active]);
            echo json_encode(['status' => 'success']);
            break;

        // Create or update one tier band. tier_id present = update; absent =
        // insert (auto-creating the parent supplier_category_rebates row,
        // active, if this is the first tier for that pair).
        case 'rebate_tier_save':
            Rbac::requirePermission('inventory.procurement.create_po');

            $supplier_id    = (int)($jsonData['supplier_id'] ?? 0);
            $category_id    = (int)($jsonData['category_id'] ?? 0);
            $tier_id        = (int)($jsonData['tier_id'] ?? 0);
            $tier_order     = (int)($jsonData['tier_order'] ?? 1);
            $threshold_from = ($jsonData['threshold_from'] ?? null);
            $threshold_to   = ($jsonData['threshold_to'] ?? null);
            $rate_pct       = (float)($jsonData['rate_pct'] ?? 0); // e.g. 3 = 3%

            if ($supplier_id <= 0 || $category_id <= 0) {
                throw new UserFacingException("Fournisseur et catégorie sont requis.");
            }
            if ($rate_pct < 0 || $rate_pct > 100) {
                throw new UserFacingException("Le taux doit être compris entre 0 et 100%.");
            }
            $threshold_from = ($threshold_from === null || $threshold_from === '') ? null : (float) $threshold_from;
            $threshold_to   = ($threshold_to   === null || $threshold_to   === '') ? null : (float) $threshold_to;
            if ($threshold_from !== null && $threshold_to !== null && $threshold_from >= $threshold_to) {
                throw new UserFacingException("Le seuil de départ doit être inférieur au seuil de fin.");
            }
            $rate = round($rate_pct / 100, 4);

            $db->beginTransaction();

            $stmtScr = $db->prepare("SELECT id FROM supplier_category_rebates WHERE supplier_id = ? AND category_id = ?");
            $stmtScr->execute([$supplier_id, $category_id]);
            $scr_id = $stmtScr->fetchColumn();
            if (!$scr_id) {
                $db->prepare("INSERT INTO supplier_category_rebates (supplier_id, category_id, is_active) VALUES (?, ?, 1)")
                   ->execute([$supplier_id, $category_id]);
                $scr_id = $db->lastInsertId();
            }

            if ($tier_id > 0) {
                $db->prepare("
                    UPDATE supplier_category_rebate_tiers
                       SET tier_order = ?, threshold_from = ?, threshold_to = ?, rate = ?
                     WHERE id = ? AND supplier_category_rebate_id = ?
                ")->execute([$tier_order, $threshold_from, $threshold_to, $rate, $tier_id, $scr_id]);
            } else {
                $db->prepare("
                    INSERT INTO supplier_category_rebate_tiers
                        (supplier_category_rebate_id, tier_order, threshold_from, threshold_to, rate)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$scr_id, $tier_order, $threshold_from, $threshold_to, $rate]);
            }

            $db->commit();
            echo json_encode(['status' => 'success', 'message' => 'Palier enregistré.']);
            break;

        case 'rebate_tier_delete':
            Rbac::requirePermission('inventory.procurement.create_po');
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new UserFacingException("Palier non spécifié.");
            $db->prepare("DELETE FROM supplier_category_rebate_tiers WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success']);
            break;

        // Shared TVA / précompte rates. One row, editable — never a literal.
        case 'rebate_tax_settings_get':
            echo json_encode(['status' => 'success', 'data' => lpc_rebate_tax_settings($db)]);
            break;

        case 'rebate_tax_settings_save':
            Rbac::requirePermission('inventory.procurement.create_po');
            $vat_pct       = (float)($jsonData['vat_rate_pct'] ?? 0);
            $precompte_pct = (float)($jsonData['precompte_rate_pct'] ?? 0);
            if ($vat_pct < 0 || $vat_pct > 100 || $precompte_pct < 0 || $precompte_pct > 100) {
                throw new UserFacingException("Les taux doivent être compris entre 0 et 100%.");
            }
            $vat_rate       = round($vat_pct / 100, 4);
            $precompte_rate = round($precompte_pct / 100, 4);

            $exists = (int) $db->query("SELECT COUNT(*) FROM purchase_rebate_tax_settings")->fetchColumn();
            if ($exists > 0) {
                $db->prepare("UPDATE purchase_rebate_tax_settings SET vat_rate = ?, precompte_rate = ? ORDER BY id ASC LIMIT 1")
                   ->execute([$vat_rate, $precompte_rate]);
            } else {
                $db->prepare("INSERT INTO purchase_rebate_tax_settings (vat_rate, precompte_rate) VALUES (?, ?)")
                   ->execute([$vat_rate, $precompte_rate]);
            }
            echo json_encode(['status' => 'success', 'message' => 'Taux mis à jour.']);
            break;

        // ==========================================
        // ACTION: DELIVERY PLACES — LIST / SAVE
        // ==========================================
        case 'delivery_places_list':
            $rows = $db->query("SELECT id, name, is_active FROM delivery_places WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $rows]);
            break;

        case 'delivery_places_save':
            Rbac::requirePermission('inventory.procurement.create_po');
            $id   = (int)($jsonData['id'] ?? 0);
            $name = trim((string)($jsonData['name'] ?? ''));
            if ($name === '') throw new UserFacingException("Le nom du lieu de livraison est requis.");

            if ($id > 0) {
                $db->prepare("UPDATE delivery_places SET name = ? WHERE id = ?")->execute([$name, $id]);
            } else {
                $db->prepare("
                    INSERT INTO delivery_places (name) VALUES (?)
                    ON DUPLICATE KEY UPDATE is_active = 1
                ")->execute([$name]);
            }
            echo json_encode(['status' => 'success']);
            break;

        case 'delivery_places_deactivate':
            Rbac::requirePermission('inventory.procurement.create_po');
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new UserFacingException("Lieu de livraison non spécifié.");
            $db->prepare("UPDATE delivery_places SET is_active = 0 WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success']);
            break;

        // ==========================================
        // ACTION: GET PO DETAIL (full page)
        // ==========================================
        case 'get_po_detail':
            $po_id = (int)($_GET['id'] ?? 0);
            if ($po_id <= 0) throw new UserFacingException("Bon de commande non spécifié.");

            $stmt = $db->prepare("
                SELECT po.*, s.name AS supplier_name, dp.name AS delivery_place_name,
                       CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
                  FROM purchase_orders po
                  JOIN suppliers s ON s.id = po.supplier_id
                  LEFT JOIN delivery_places dp ON dp.id = po.delivery_place_id
                  LEFT JOIN users u ON u.id = po.created_by
                 WHERE po.id = ?
            ");
            $stmt->execute([$po_id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$po) throw new UserFacingException("Bon de commande introuvable.");

            // Lines with ordered / received / remaining quantities. Ristourne
            // is no longer tracked per line (migration 052 moved it to per
            // category, on purchase_order_category_rebates below) — a line's
            // category is still shown so the category breakdown is legible
            // against the line list.
            $stmtLines = $db->prepare("
                SELECT poi.id, poi.product_id, p.name AS product_name, p.category_id,
                       pc.name AS category_name, poi.quantity AS qty_ordered, poi.unit_price,
                       COALESCE((
                           SELECT SUM(quantity) FROM inventory_movements
                            WHERE reference_id = poi.purchase_order_id
                              AND product_id = poi.product_id
                              AND movement_type = 'in_supplier'
                       ), 0) AS qty_received
                  FROM purchase_order_items poi
                  JOIN products p ON p.id = poi.product_id
                  LEFT JOIN product_categories pc ON pc.id = p.category_id
                 WHERE poi.purchase_order_id = ?
                 ORDER BY p.name ASC
            ");
            $stmtLines->execute([$po_id]);
            $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

            $total_ordered = 0; $total_received = 0;
            foreach ($lines as &$l) {
                $l['qty_ordered']   = (int) $l['qty_ordered'];
                $l['qty_received']  = (int) $l['qty_received'];
                $l['qty_remaining'] = max(0, $l['qty_ordered'] - $l['qty_received']);
                $l['line_total']    = $l['qty_ordered'] * (float) $l['unit_price'];
                $total_ordered      += $l['qty_ordered'];
                $total_received     += $l['qty_received'];
            }
            unset($l);

            // Per-category ristourne breakdown for this PO — the ladder tier
            // frozen at order creation, the HT base it was evaluated against,
            // and the net amount actually accrued so far across receptions.
            $stmtCatRebates = $db->prepare("
                SELECT pocr.category_id, pc.name AS category_name, pocr.category_amount_ttc,
                       pocr.category_amount_ht, pocr.tier_rate, pocr.vat_rate_applied,
                       pocr.precompte_rate_applied, pocr.rebate_amount_earned
                  FROM purchase_order_category_rebates pocr
                  JOIN product_categories pc ON pc.id = pocr.category_id
                 WHERE pocr.purchase_order_id = ?
                 ORDER BY pc.name ASC
            ");
            $stmtCatRebates->execute([$po_id]);
            $category_rebates = $stmtCatRebates->fetchAll(PDO::FETCH_ASSOC);

            $total_rebate_earned = 0;
            foreach ($category_rebates as $cr) {
                $total_rebate_earned += (float) $cr['rebate_amount_earned'];
            }

            // Reception history: one row per distinct reception "batch" is not
            // tracked separately from inventory_movements, so this reads the raw
            // movement log for the order, joined to who logged it.
            $stmtReceptions = $db->prepare("
                SELECT im.id, im.date, im.product_id, p.name AS product_name, im.quantity,
                       CONCAT(u.first_name, ' ', u.last_name) AS logged_by_name
                  FROM inventory_movements im
                  JOIN products p ON p.id = im.product_id
                  LEFT JOIN users u ON u.id = im.logged_by
                 WHERE im.reference_id = ? AND im.movement_type = 'in_supplier'
                 ORDER BY im.date ASC, im.id ASC
            ");
            $stmtReceptions->execute([$po_id]);
            $receptions = $stmtReceptions->fetchAll(PDO::FETCH_ASSOC);

            // Ristourne ledger rows tied specifically to this PO (accrual at
            // reception, deduction if this PO itself spent rebate credit).
            $stmtRebateRows = $db->prepare("
                SELECT date, reference, type, amount, notes, reversed
                  FROM supplier_rebate_ledger
                 WHERE purchase_order_id = ?
                 ORDER BY id ASC
            ");
            $stmtRebateRows->execute([$po_id]);
            $rebate_ledger = $stmtRebateRows->fetchAll(PDO::FETCH_ASSOC);

            $status = $po['status'];
            $reception_state = $status === 'received' ? 'complete'
                              : ($status === 'partial' ? 'partial'
                              : ($status === 'cancelled' ? 'cancelled' : 'pending'));

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'po' => $po,
                    'lines' => $lines,
                    'category_rebates' => $category_rebates,
                    'receptions' => $receptions,
                    'rebate_ledger' => $rebate_ledger,
                    'totals' => [
                        'qty_ordered'  => $total_ordered,
                        'qty_received' => $total_received,
                        'qty_remaining'=> max(0, $total_ordered - $total_received),
                        'rebate_earned'=> $total_rebate_earned,
                    ],
                    'reception_state' => $reception_state,
                ]
            ]);
            break;

        // ==========================================
        // ACTION: READ TABLES & KPIS (WITH DATE RANGE)
        // ==========================================
        case 'read':
            $tab = $_GET['tab'] ?? 'inventory';
            
            // Récupérer la plage de dates (par défaut: du 1er janvier 2000 au 31 décembre 2099 pour "Tout")
            $start_date = $_GET['start'] ?? '2000-01-01';
            $end_date = $_GET['end'] ?? '2099-12-31';
            
            $responseData = ['table' => [], 'kpis' => []];
            $lpc_q = trim((string) ($_GET['q'] ?? ''));   // Sprint 5 · server-side search

            if ($tab === 'inventory') {
                // Sprint 5: paginated + searchable purchase-orders list.
                $body   = "
                    FROM purchase_orders po
                    JOIN suppliers s ON po.supplier_id = s.id
                    LEFT JOIN delivery_places dp ON dp.id = po.delivery_place_id
                    WHERE po.date BETWEEN ? AND ?
                ";
                $params = [$start_date, $end_date];
                if ($lpc_q !== '') {
                    [$body, $params] = Paginator::addWhere(
                        $body, $params, $lpc_q,
                        ['po.reference', 's.name', 'po.status', 'po.payment_status', 'dp.name']
                    );
                }
                $body .= " ORDER BY po.date DESC, po.id DESC";
                $page = Paginator::paginate($db, $body, $params,
                    "po.id, po.reference, po.date, po.status, po.payment_status, po.total_amount, po.token,
                     s.name as supplier_name, s.id as supplier_id, dp.name as delivery_place_name",
                    null, null, "procurement.read.inventory:$start_date:$end_date");
                $responseData['table']      = $page['data'];
                $responseData['pagination'] = [
                    'page'        => $page['page'],
                    'per_page'    => $page['per_page'],
                    'total'       => $page['total'],
                    'total_pages' => $page['total_pages'],
                    'has_prev'    => $page['has_prev'],
                    'has_next'    => $page['has_next'],
                ];

                // Cancelled orders are excluded from every KPI. They stay in the
                // table below (greyed out, so the history is visible) but they
                // are not purchases, they are not owed, and the ristourne they
                // consumed has been given back — counting them would overstate
                // spend and, worse, show supplier debt that no longer exists.
                $stmtKpi = $db->prepare("
                    SELECT
                        COALESCE(SUM(total_amount), 0) as kpi_inv_total,
                        COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN total_amount ELSE 0 END), 0) as kpi_inv_unpaid,
                        COALESCE(SUM(discount_amount), 0) as kpi_inv_discount,
                        COUNT(id) as kpi_inv_count
                    FROM purchase_orders
                    WHERE date BETWEEN ? AND ?
                      AND status <> 'cancelled'
                ");
                $stmtKpi->execute([$start_date, $end_date]);
                $kpis = $stmtKpi->fetch(PDO::FETCH_ASSOC);

                $responseData['kpis'] = [
                    'kpi_inv_total'    => number_format($kpis['kpi_inv_total'], 0, ',', ' ') . ' FCFA',
                    'kpi_inv_unpaid'   => number_format($kpis['kpi_inv_unpaid'], 0, ',', ' ') . ' FCFA',
                    'kpi_inv_discount' => number_format($kpis['kpi_inv_discount'], 0, ',', ' ') . ' FCFA',
                    'kpi_inv_count'    => $kpis['kpi_inv_count'] . ' Cmd(s)'
                ];

            } elseif ($tab === 'overheads') {
                $body   = "
                    FROM overheads
                    WHERE date BETWEEN ? AND ?
                ";
                $params = [$start_date, $end_date];
                if ($lpc_q !== '') {
                    [$body, $params] = Paginator::addWhere(
                        $body, $params, $lpc_q,
                        ['reference', 'title', 'category', 'payment_status']
                    );
                }
                $body .= " ORDER BY date DESC, id DESC";
                $page = Paginator::paginate($db, $body, $params,
                    "id, reference, title, category, amount, date, payment_status",
                    null, null, "procurement.read.overheads:$start_date:$end_date");
                $responseData['table']      = $page['data'];
                $responseData['pagination'] = [
                    'page'        => $page['page'],
                    'per_page'    => $page['per_page'],
                    'total'       => $page['total'],
                    'total_pages' => $page['total_pages'],
                    'has_prev'    => $page['has_prev'],
                    'has_next'    => $page['has_next'],
                ];

                $stmtKpi = $db->prepare("
                    SELECT 
                        COALESCE(SUM(amount), 0) as kpi_oh_total,
                        COALESCE(SUM(CASE WHEN category = 'Logistique' THEN amount ELSE 0 END), 0) as kpi_oh_log,
                        COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN amount ELSE 0 END), 0) as kpi_oh_unpaid,
                        COUNT(id) as kpi_oh_count
                    FROM overheads 
                    WHERE date BETWEEN ? AND ?
                ");
                $stmtKpi->execute([$start_date, $end_date]);
                $kpis = $stmtKpi->fetch(PDO::FETCH_ASSOC);

                $responseData['kpis'] = [
                    'kpi_oh_total'  => number_format($kpis['kpi_oh_total'], 0, ',', ' ') . ' FCFA',
                    'kpi_oh_log'    => number_format($kpis['kpi_oh_log'], 0, ',', ' ') . ' FCFA',
                    'kpi_oh_unpaid' => number_format($kpis['kpi_oh_unpaid'], 0, ',', ' ') . ' FCFA',
                    'kpi_oh_count'  => $kpis['kpi_oh_count'] . ' Factures'
                ];
            }

            echo json_encode(['status' => 'success', 'data' => $responseData]);
            break;

        // ==========================================
        // ACTION: SAVE PURCHASE ORDER (PROCUREMENT)
        // ==========================================
        case 'save_po':
            // Creating an order commits the company to a payable and can spend
            // rebate credit. 'view' is not authority to do that.
            Rbac::requirePermission('inventory.procurement.create_po');

            $db->beginTransaction();

            $supplier_id = (int)$jsonData['supplier_id'];
            $date = $jsonData['date'];
            $payment_status = $jsonData['payment_status'];
            $discount_amount = (float)($jsonData['discount_amount'] ?? 0);
            $discount_note = trim($jsonData['discount_note'] ?? '');
            $delivery_place_id = (int)($jsonData['delivery_place_id'] ?? 0) ?: null;
            $treasury_account_id = !empty($jsonData['treasury_account_id']) ? (int) $jsonData['treasury_account_id'] : null;
            $items = $jsonData['items'] ?? [];

            if (empty($supplier_id) || empty($items)) {
                throw new UserFacingException("Données de commande incomplètes.");
            }
            if ($discount_amount < 0) {
                throw new UserFacingException("Le montant de la remise ne peut pas être négatif.");
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                throw new UserFacingException("Date de commande invalide.");
            }
            if (!in_array($payment_status, ['paid', 'unpaid'], true)) {
                throw new UserFacingException("Statut de paiement invalide.");
            }

            // Migration 093 — a PO created "Payé" must name the treasury
            // account the money is coming from, so the payment JE posted at
            // reception (inventory_controller::receive_po) can debit 401 and
            // credit the right sub-ledger (571 Caisse vs 521 Banques vs 552
            // MoMo). Without this, the payment JE either can't post or has
            // to guess an account — the whole reason this hole existed.
            //
            // "Non payé" (à crédit) legitimately has no account yet — the
            // Payer Fournisseur screen fills it in when the settlement
            // happens later. The picker is only required on the paid path.
            if ($payment_status === 'paid') {
                if (!$treasury_account_id) {
                    throw new UserFacingException("Compte de trésorerie requis pour un achat payé.");
                }
                // Verify the account exists and is active. Detected rather
                // than assumed, so a badly-formed payload from an old client
                // still surfaces as a UserFacingException instead of a raw
                // FK violation later.
                $chk = $db->prepare("SELECT 1 FROM treasury_accounts WHERE id = ? AND status = 'active'");
                $chk->execute([$treasury_account_id]);
                if (!$chk->fetchColumn()) {
                    throw new UserFacingException("Compte de trésorerie invalide ou inactif.");
                }
            } else {
                // Discard any account the client sent — a PO à crédit stores
                // NULL and the standalone Payer Fournisseur screen picks the
                // account at settlement time.
                $treasury_account_id = null;
            }

            // The TVA rate in force right now, resolved once and frozen onto the
            // order below. Also used by the ristourne ladder further down, which
            // reads the same settings row — resolving it here keeps the order's
            // stored HT and the ladder's HT base derived from one identical rate
            // rather than two lookups that could straddle a settings change.
            $tax_now = lpc_rebate_tax_settings($db);

            // Exonerated / non-deductible purchase. Defaults to deductible,
            // which is the ordinary case; a caller that knows better (water is
            // exonerated under art. 128 CGI, and a supplier outside the régime
            // réel invoices no reclaimable VAT) says so and the whole TTC stays
            // in 601, which is the correct SYSCOHADA treatment for VAT that
            // cannot be recovered — it genuinely is part of the cost.
            $vat_recoverable = array_key_exists('vat_recoverable', $jsonData)
                ? (!empty($jsonData['vat_recoverable']) ? 1 : 0)
                : 1;

            $clean_items = [];
            foreach ($items as $item) {
                $pid        = (int)   ($item['product_id'] ?? 0);
                $qty        = (int)   ($item['quantity']   ?? 0);
                $unit_price = round((float) ($item['unit_price'] ?? 0), 2);
                // Negative quantities or prices would let a line subtract from
                // the order total, and on reception would subtract from stock
                // through an 'in_supplier' movement.
                if ($pid <= 0) {
                    throw new UserFacingException("Ligne de commande sans produit.");
                }
                if ($qty <= 0 || $unit_price < 0) {
                    throw new UserFacingException("Ligne de commande invalide : quantité et prix doivent être positifs.");
                }
                $clean_items[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $unit_price];
            }

            // -----------------------------------------------------------------
            // Migration 063 · a supplier price change is a price change.
            //
            // The subtotal used to be simply Σ(qty × typed price), which meant a
            // supplier's price movement and a negotiated one-off reduction were
            // the same event as far as this system was concerned: both just
            // changed a number, and neither was recorded as anything.
            //
            // Nothing is inferred here. Lines differing from the supplier's
            // standing tariff are returned to the browser, the buyer declares
            // which they are, and this endpoint records the declaration —
            // exactly as save_order does on the sell side.
            //
            // First order for a (supplier, product) pair: no tariff exists, so
            // no question is raised and the typed price BECOMES the tariff.
            // That is what lets supplier_prices start empty and fill itself
            // from real decisions rather than from a seeded guess (see 063).
            // -----------------------------------------------------------------
            $price_decisions = is_array($jsonData['price_decisions'] ?? null)
                             ? $jsonData['price_decisions'] : [];

            $po_pids    = array_column($clean_items, 'product_id');
            $sup_tariff = lpc_supplier_tariff($db, $supplier_id, $po_pids);
            $disparities = lpc_supplier_price_disparities($clean_items, $sup_tariff);

            $undecided = [];
            foreach ($disparities as $pid => $d) {
                if (!array_key_exists((string) $pid, $price_decisions)
                    && !array_key_exists($pid, $price_decisions)) {
                    $undecided[] = $pid;
                }
            }

            if (!empty($undecided)) {
                // Nothing written. Roll back the transaction opened above
                // before returning, or the connection is left holding it.
                if ($db->inTransaction()) $db->rollBack();

                $nameStmt = $db->query(
                    'SELECT id, name, format FROM products WHERE id IN ('
                    . implode(',', array_map('intval', array_keys($disparities))) . ')'
                );
                $names = [];
                foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
                    $names[(int) $n['id']] = trim($n['name'] . ' ' . ($n['format'] ?? ''));
                }
                $payload = [];
                foreach ($disparities as $pid => $d) {
                    $d['product_name'] = $names[$pid] ?? ('#' . $pid);
                    $payload[] = $d;
                }
                echo json_encode(['status' => 'needs_price_confirmation', 'disparities' => $payload]);
                break;
            }

            // Every disparity carries a decision. Price the lines.
            $subtotal = 0.0;
            $line_discount_total = 0.0;
            $priced_lines = [];
            $repriced_sup = [];

            foreach ($clean_items as $l) {
                $pid = $l['product_id']; $qty = $l['quantity']; $typed = $l['unit_price'];
                $has_tariff = array_key_exists($pid, $sup_tariff);
                $current = $has_tariff ? round((float) $sup_tariff[$pid], 2) : $typed;

                $changed = $has_tariff && abs($typed - $current) >= 0.005;
                $confirm = $changed
                    && (($price_decisions[$pid] ?? $price_decisions[(string) $pid] ?? false) ? true : false);

                if (!$has_tariff) {
                    // No tariff on file. The typed price establishes it — this
                    // is not a change, so it is not logged as one and carries
                    // no discount.
                    $list = $typed; $unit = $typed; $lineDisc = 0.0;
                    $repriced_sup[$pid] = $typed;
                } elseif (!$changed) {
                    $list = $current; $unit = $current; $lineDisc = 0.0;
                } elseif ($confirm) {
                    // Declared: the supplier's new price. No discount.
                    $list = $typed; $unit = $typed; $lineDisc = 0.0;
                    $repriced_sup[$pid] = $typed;
                } else {
                    // Declared: a one-off reduction. Tariff stands.
                    if ($typed > $current) {
                        // Paying ABOVE tariff while refusing to call it the new
                        // price would have to be a negative remise — a
                        // surcharge dressed as a discount, which would corrupt
                        // every total that sums this column.
                        throw new UserFacingException(
                            "Prix supérieur au tarif fournisseur sur une ligne sans confirmation. "
                            . "Confirmez le nouveau prix, ou ramenez la ligne au tarif."
                        );
                    }
                    $list = $current; $unit = $typed;
                    $lineDisc = round(($current - $typed) * $qty, 2);
                }

                $subtotal            += $list * $qty;
                $line_discount_total += $lineDisc;
                $priced_lines[] = [
                    'product_id' => $pid, 'quantity' => $qty,
                    'unit_price' => $unit, 'list_price' => $list, 'discount' => $lineDisc,
                ];
            }

            $subtotal = round($subtotal, 2);
            // The typed "Remise (FCFA)" field plus whatever was declared line by
            // line. discount_amount keeps its existing meaning — everything
            // obtained as a reduction on this order — and gains a breakdown.
            $rebate_spent    = round($discount_amount, 2);   // the typed field ONLY
            $discount_amount = round($discount_amount + $line_discount_total, 2);

            // ---------------------------------------------------------------
            // WHY $rebate_spent IS NOT $discount_amount — SYSCOHADA audit fix.
            //
            // These two numbers were the same variable, and conflating them
            // double-counted every line-level reduction:
            //
            //   · $subtotal is summed at LIST price, and $line_discount_total
            //     is the gap between list and what is actually being paid. So
            //     the line reduction is already inside $subtotal − $discount.
            //   · But reception books 601/401 at purchase_order_items
            //     .unit_price, which is the TYPED price — already net of that
            //     same line reduction.
            //   · Deducting it AGAIN through postRebateUsage (Dr 401 / Cr 4098)
            //     left the supplier's account under-credited by exactly
            //     $line_discount_total. The books said we owed the supplier
            //     less than the order did.
            //
            // Worse operationally: a line reduction is a negotiated price cut,
            // not ristourne credit. Charging it to supplier_rebate_ledger drained
            // a pool the supplier had actually granted for volume, and the
            // sufficiency check below then refused ordinary orders with
            // "Ristourne insuffisante" because a price negotiation had eaten the
            // balance.
            //
            // Only the typed "Remise (FCFA)" field is a draw on the rebate pool.
            // Line reductions stay in discount_amount for reporting — the KPI and
            // the PO document both read that column and their meaning is
            // unchanged — but they no longer touch the ledger or the pool.
            // ---------------------------------------------------------------

            if ($discount_amount > 0 && $discount_note === '') {
                throw new UserFacingException("Motif de la remise obligatoire.");
            }

            // A discount cannot exceed what is being ordered. Without this the
            // order total clamps to zero at max(0, ...) while the full discount
            // is still deducted from the rebate ledger — credit vanishes with
            // nothing to show for it.
            if ($discount_amount > $subtotal) {
                throw new UserFacingException(sprintf(
                    "La remise (%s FCFA) dépasse le montant de la commande (%s FCFA).",
                    number_format($discount_amount, 0, ',', ' '),
                    number_format($subtotal, 0, ',', ' ')
                ));
            }

            // Ceiling on the declared remise. Repricing a supplier is ordinary
            // commercial work and is never gated by this.
            $po_max_pct = Prefs::float('purchase_discount_max_pct', 15);
            if ($po_max_pct > 0 && $subtotal > 0) {
                $po_pct = ($discount_amount / $subtotal) * 100;
                if ($po_pct > $po_max_pct
                    && !Rbac::hasPermission('inventory.procurement.discount_approve')) {
                    throw new UserFacingException(sprintf(
                        "Remise de %.1f%% au-delà du plafond de %.0f%%. Approbation requise.",
                        $po_pct, $po_max_pct
                    ));
                }
            }

            $total_amount = max(0, $subtotal - $discount_amount);

            $hash = strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
            $reference = 'PO-' . date('ym') . '-' . $hash;
            $token = bin2hex(random_bytes(16));

            // 1. CREATE PO AS PENDING (No stock, no accounting yet)
            //
            // FREEZE THE VAT RATE ON THE ORDER — SYSCOHADA audit fix.
            //
            // Prices on this order are TTC. Reception has to split that into
            // the 601 debit (HT) and the 4452 debit (deductible VAT), and it
            // may happen weeks later. Reading the rate live at reception time
            // would mean a change to purchase_rebate_tax_settings silently
            // re-splits orders already placed at the old rate — the exact
            // problem migration 040 solved for invoices and
            // purchase_order_category_rebates.vat_rate_applied solved for the
            // ristourne base. Stored here, once, at the rate agreed.
            $po_vat_rate = (float) $tax_now['vat_rate'];
            $po_subtotal_ht = ($po_vat_rate > 0) ? round($subtotal / (1 + $po_vat_rate), 2) : $subtotal;
            $po_vat_amount  = round($subtotal - $po_subtotal_ht, 2);

            // Migration 093 · treasury_account_id captures the ACCOUNT this PO
            // is (or will be) paid from. Detected the same way vat_columns is,
            // so the code keeps working before the migration lands — the
            // payment JE at reception simply can't post until 093 is applied,
            // and the picker gate above guarantees no "paid" PO gets there
            // without an account.
            $has_pay_cols = lpc_po_has_payment_columns($db);
            if (lpc_po_has_vat_columns($db)) {
                if ($has_pay_cols) {
                    $stmtPO = $db->prepare("
                        INSERT INTO purchase_orders
                        (reference, supplier_id, delivery_place_id, date, status, subtotal, discount_amount, discount_note, total_amount, payment_status, token, created_by,
                         vat_rate, subtotal_ht, vat_amount, vat_recoverable,
                         treasury_account_id)
                        VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtPO->execute([
                        $reference, $supplier_id, $delivery_place_id, $date, $subtotal, $discount_amount, $discount_note, $total_amount, $payment_status, $token, $user_id,
                        $po_vat_rate, $po_subtotal_ht, $po_vat_amount, $vat_recoverable,
                        $treasury_account_id,
                    ]);
                } else {
                    $stmtPO = $db->prepare("
                        INSERT INTO purchase_orders
                        (reference, supplier_id, delivery_place_id, date, status, subtotal, discount_amount, discount_note, total_amount, payment_status, token, created_by,
                         vat_rate, subtotal_ht, vat_amount, vat_recoverable)
                        VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtPO->execute([
                        $reference, $supplier_id, $delivery_place_id, $date, $subtotal, $discount_amount, $discount_note, $total_amount, $payment_status, $token, $user_id,
                        $po_vat_rate, $po_subtotal_ht, $po_vat_amount, $vat_recoverable,
                    ]);
                }
            } else {
                // Migration 065 not applied yet. Post exactly as before —
                // reception falls back to the current setting and the entry
                // degrades to the single 601 line it has always been.
                if ($has_pay_cols) {
                    $stmtPO = $db->prepare("
                        INSERT INTO purchase_orders
                        (reference, supplier_id, delivery_place_id, date, status, subtotal, discount_amount, discount_note, total_amount, payment_status, token, created_by, treasury_account_id)
                        VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtPO->execute([
                        $reference, $supplier_id, $delivery_place_id, $date, $subtotal, $discount_amount, $discount_note, $total_amount, $payment_status, $token, $user_id, $treasury_account_id
                    ]);
                } else {
                    $stmtPO = $db->prepare("
                        INSERT INTO purchase_orders
                        (reference, supplier_id, delivery_place_id, date, status, subtotal, discount_amount, discount_note, total_amount, payment_status, token, created_by)
                        VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtPO->execute([
                        $reference, $supplier_id, $delivery_place_id, $date, $subtotal, $discount_amount, $discount_note, $total_amount, $payment_status, $token, $user_id
                    ]);
                }
            }
            $po_id = $db->lastInsertId();

            $stmtLine = $db->prepare("
                INSERT INTO purchase_order_items
                    (purchase_order_id, product_id, quantity, unit_price, list_price, discount_amount)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($priced_lines as $p) {
                $stmtLine->execute([
                    $po_id, $p['product_id'], $p['quantity'],
                    $p['unit_price'], $p['list_price'], $p['discount'],
                ]);
            }

            // Repricing commits with the order that caused it. A PO that rolls
            // back must not leave a new supplier tariff standing — the buyer
            // confirmed a price FOR THIS ORDER, and if the order did not happen
            // neither did the decision.
            foreach ($repriced_sup as $rp_pid => $rp_price) {
                lpc_apply_supplier_price_change(
                    $db, $supplier_id, (int) $rp_pid, (float) $rp_price, $user_id,
                    'purchase_order', $reference, 'Confirmé à la saisie du bon de commande'
                );
            }

            // 1b. RISTOURNE LADDER TIER — frozen per category, at order
            //     creation, on the WHOLE order's category subtotal (confirmed
            //     with the supplier: it is not evaluated per reception, and
            //     later partial receptions each earn a share at this same
            //     frozen rate — see receive_po in inventory_controller.php).
            //
            //     Purchase order prices are confirmed TTC (the supplier's
            //     quoted price already includes TVA), so the HT base the
            //     ladder is evaluated against is backed out of the entered
            //     price here, not read directly from it.
            $tax = $tax_now;   // resolved once at the top of this action
            $stmtProdCat = $db->prepare("SELECT category_id FROM products WHERE id = ?");
            $category_ttc_totals = []; // category_id => TTC subtotal for this PO
            // Iterates $priced_lines, not the raw $items payload, and values at
            // list_price rather than the typed price. Both matter since 063:
            // the ladder is evaluated against the order's category subtotal,
            // and that subtotal must be the same figure written to
            // purchase_orders.subtotal. Valuing it at the typed price instead
            // would make a line carrying a declared remise contribute less to
            // the rebate base than it contributed to the order — so a
            // negotiated reduction would quietly shrink the ristourne earned on
            // it, which is not what the ladder agreement says.
            foreach ($priced_lines as $p) {
                $stmtProdCat->execute([$p['product_id']]);
                $cat_id = $stmtProdCat->fetchColumn();
                if (!$cat_id) continue; // product has no category assigned — cannot ladder it
                $cat_id = (int) $cat_id;
                $line_ttc = $p['quantity'] * (float) $p['list_price'];
                $category_ttc_totals[$cat_id] = ($category_ttc_totals[$cat_id] ?? 0) + $line_ttc;
            }

            $stmtInsCatRebate = $db->prepare("
                INSERT INTO purchase_order_category_rebates
                    (purchase_order_id, category_id, category_amount_ttc, category_amount_ht,
                     tier_rate, vat_rate_applied, precompte_rate_applied)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($category_ttc_totals as $cat_id => $ttc) {
                $ht = $ttc / (1 + $tax['vat_rate']);
                $tier_rate = lpc_category_tier_rate($db, $supplier_id, $cat_id, $ht);
                $stmtInsCatRebate->execute([
                    $po_id, $cat_id, $ttc, $ht, $tier_rate, $tax['vat_rate'], $tax['precompte_rate']
                ]);
            }

            // 2. DEDUCT RISTOURNE IF USED (Consumption happens at order creation).
            //    Sprint-4-Batch-B: lock the supplier's rebate ledger before
            //    reading the running balance so two concurrent POs against
            //    the same rebate pool can't double-spend.
            if ($rebate_spent > 0) {
                // Whether a supplier can spend rebate credit now comes from
                // having at least one active category ladder (migration 052)
                // — see includes/functions/procurement.php.
                if (!lpc_supplier_has_any_rebate($db, $supplier_id)) {
                    throw new UserFacingException("Ce fournisseur ne dispose pas d'un compte ristourne.");
                }

                // Lock the rebate rows for this supplier before reading the
                // running balance; MySQL FOR UPDATE acquires row-level locks on
                // the read set so two concurrent orders against the same pool
                // cannot both pass the sufficiency check and double-spend.
                $rebate = lpc_rebate_balance($db, $supplier_id, true);

                if ($rebate_spent > $rebate['balance']) {
                    throw new UserFacingException(sprintf(
                        "Ristourne insuffisante : solde %s FCFA, demandé %s FCFA.",
                        number_format($rebate['balance'], 0, ',', ' '),
                        number_format($rebate_spent, 0, ',', ' ')
                    ));
                }

                lpc_rebate_ledger_add(
                    $db, $supplier_id, (int) $po_id, $date, $reference,
                    'deduction', $rebate_spent, "Utilisation Ristourne (Remise)", $user_id
                );

                // And the matching general-ledger entry, in this same
                // transaction: Debit the supplier's 401 sub-account (we owe
                // them that much less), Credit 4098 (the rebate receivable is
                // consumed). Previously this write existed only in the
                // operational ledger, so the books never reflected any rebate
                // and the payable stayed overstated by every discount taken.
                JournalPoster::postRebateUsage(
                    (int) $po_id,
                    $supplier_id,
                    $rebate_spent,
                    $date,
                    $reference
                );
            }

            // Sprint-4-Batch-B note: creating a PO NO LONGER touches
            // inventory_movements. Reception (inventory_controller::receive_po)
            // is the single authoritative stock-write path. The UI subtitle
            // in modules/inventory/procurement.php line 142 has been updated
            // to reflect this — see the "réception" wording.

            $db->commit();

            lpc_notify_permission(
                $db,
                'inventory.stock.receive',
                'Nouveau bon de commande',
                ($_SESSION['user_name'] ?? 'Un opérateur') . " a créé le BC $reference — "
                    . number_format($total_amount, 0, ',', ' ') . " FCFA. En attente de réception.",
                '/modules/inventory/procurement.php',
                'info',
                [$user_id]
            );

            echo json_encode(['status' => 'success', 'message' => 'Commande créée (En attente de réception logistique).', 'token' => $token]);
            break;

        // ==========================================
        // ACTION: CANCEL PURCHASE ORDER (REVERSE, DO NOT DELETE)
        // ------------------------------------------------------------------
        // This replaces the old 'delete_inventory' hard delete, which removed
        // the purchase_orders row and its items and left behind:
        //   · the journal entries posted at reception, now pointing at a
        //     document that no longer exists;
        //   · the supplier_rebate_ledger accruals earned by that reception, so
        //     "Total généré" kept counting rebate from deleted orders;
        //   · any rebate deduction spent on it, unrecoverable.
        // It also did not reverse the stock valuation: the movements were
        // deleted but products.cump kept the average they had shifted it to.
        //
        // Now that receptions post genuinely ('posted' status rather than the
        // old invalid 'pending'), migration 004's bd_je_posted trigger would
        // refuse to delete the entry anyway — a hard delete would start failing
        // with SQLSTATE 45000. Cancellation is the correct operation and the
        // only one that leaves an audit trail.
        //
        // 'delete_inventory' is kept as an alias so an open browser tab mid-
        // session does not hit "Action inconnue" after deploy.
        // ==================================================================
        case 'delete_inventory':
        case 'cancel_inventory':
            Rbac::requirePermission('inventory.procurement.approve');

            $po_id  = (int)($_POST['id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($po_id <= 0) throw new UserFacingException("Bon de commande non spécifié.");

            $db->beginTransaction();

            $stmtPO = $db->prepare("
                SELECT id, reference, supplier_id, status, discount_amount
                  FROM purchase_orders WHERE id = ? FOR UPDATE
            ");
            $stmtPO->execute([$po_id]);
            $po = $stmtPO->fetch(PDO::FETCH_ASSOC);
            if (!$po) throw new UserFacingException("Bon de commande introuvable.");
            if ($po['status'] === 'cancelled') {
                throw new UserFacingException("Cette commande est déjà annulée.");
            }

            // 1. Unwind stock, product by product, so CUMP can be corrected as
            //    we go. Deleting the movements wholesale (what the old code did)
            //    silently left every affected product carrying a weighted
            //    average that included a delivery which no longer exists.
            $stmtMoves = $db->prepare("
                SELECT product_id, SUM(quantity) AS qty
                  FROM inventory_movements
                 WHERE reference_id = ? AND movement_type = 'in_supplier'
                 GROUP BY product_id
            ");
            $stmtMoves->execute([$po_id]);
            $received_moves = $stmtMoves->fetchAll(PDO::FETCH_ASSOC);

            // Same cost basis reception used. Reception now values stock at HT
            // when the VAT is recoverable, so the inversion below MUST use the
            // identical figure — unwinding a TTC value out of a CUMP built from
            // HT would leave every affected product permanently mis-valued, and
            // silently, since nothing downstream re-derives CUMP from source.
            // lpc_po_vat_context reads the rate frozen on the order, so an order
            // cancelled after a rate change still unwinds at what it booked.
            $cancel_vat_ctx = lpc_po_vat_context($db, $po_id);

            $stmtItemPrice = $db->prepare("
                SELECT unit_price FROM purchase_order_items
                 WHERE purchase_order_id = ? AND product_id = ?
            ");
            $stmtProdState = $db->prepare("
                SELECT cump,
                       COALESCE((SELECT SUM(CASE WHEN movement_type LIKE 'in_%' THEN quantity ELSE -quantity END)
                                   FROM inventory_movements WHERE product_id = ?), 0) AS current_qty
                  FROM products WHERE id = ? FOR UPDATE
            ");
            $stmtSetCump  = $db->prepare("UPDATE products SET cump = ? WHERE id = ?");
            $stmtDropMove = $db->prepare("
                DELETE FROM inventory_movements
                 WHERE reference_id = ? AND movement_type = 'in_supplier' AND product_id = ?
            ");

            foreach ($received_moves as $mv) {
                $pid = (int) $mv['product_id'];
                $qty = (int) $mv['qty'];
                if ($qty <= 0) continue;

                $stmtItemPrice->execute([$po_id, $pid]);
                $price_row = $stmtItemPrice->fetchColumn();
                if ($price_row === false) {
                    // A received movement with no matching order line means the
                    // line was edited or removed after reception. Reversing the
                    // valuation would need a price we do not have, and guessing
                    // one silently corrupts CUMP — refuse and let a human decide.
                    throw new UserFacingException(
                        "Annulation impossible : le produit #{$pid} a été réceptionné mais ne figure plus "
                        . "sur les lignes de la commande. Corrigez la commande ou passez par un ajustement d'inventaire."
                    );
                }
                $unit_price = lpc_po_unit_cost((float) $price_row, $cancel_vat_ctx);

                $stmtProdState->execute([$pid, $pid]);
                $state = $stmtProdState->fetch(PDO::FETCH_ASSOC);
                if (!$state) continue;

                $qty_now  = (int)   $state['current_qty'];
                $cump_now = (float) $state['cump'];
                $qty_after = $qty_now - $qty;

                if ($qty_after < 0) {
                    throw new UserFacingException(
                        "Annulation impossible : le stock du produit #{$pid} a déjà été consommé "
                        . "(il resterait {$qty_after} unités). Passez par un ajustement d'inventaire."
                    );
                }

                // Invert the weighted average: remove this delivery's value and
                // quantity from the pool. When nothing is left, the average is
                // undefined, so the last known cost is kept rather than zeroed —
                // zeroing it would value the next sale's COGS at nothing.
                if ($qty_after > 0) {
                    $value_after = ($qty_now * $cump_now) - ($qty * $unit_price);
                    $stmtSetCump->execute([max(0, $value_after / $qty_after), $pid]);
                }

                $stmtDropMove->execute([$po_id, $pid]);
            }

            // 2. Reverse the general-ledger entries this order produced —
            //    goods receipt, rebate accrual and rebate usage each get an
            //    opposing entry dated today. JournalPoster skips anything
            //    already reversed, so re-running is harmless.
            //
            // 'purchase_order_payment' — migration 093. If the PO was paid at
            // reception (postSupplierPayment fired), cancellation must reverse
            // the payment JE too. Otherwise the Dr 601 / Cr 401 gets reversed
            // but the Dr 401 / Cr treasury stays, leaving 401 with a debit
            // balance the size of the cancelled purchase — the same imbalance
            // in the opposite direction. The reversing entry mirrors the
            // debits and credits, so it Debits the treasury account (re-adding
            // the cash) and Credits 401 (removing the payment claim).
            $reversals = array_merge(
                JournalPoster::reverseSource('purchase_order',         $po_id, $reason),
                JournalPoster::reverseSource('purchase_order_payment', $po_id, $reason),
                JournalPoster::reverseSource('rebate_accrual',         $po_id, $reason),
                JournalPoster::reverseSource('rebate_usage',           $po_id, $reason)
            );

            // The reversal above corrected the GENERAL LEDGER. It does NOT
            // touch the operational treasury_accounts.balance column, because
            // reverseSource only knows about journal_entries. If a payment JE
            // was reversed, hand the cash back to the treasury account it
            // came out of AND stamp a compensating treasury_transactions row
            // so the audit trail on the Trésorerie page shows both sides.
            if (lpc_po_has_payment_columns($db)) {
                $pay_row = $db->prepare("
                    SELECT treasury_account_id, total_amount, payment_je_id
                      FROM purchase_orders
                     WHERE id = ? AND treasury_account_id IS NOT NULL AND payment_je_id IS NOT NULL
                ");
                $pay_row->execute([$po_id]);
                if ($pay = $pay_row->fetch(PDO::FETCH_ASSOC)) {
                    $refund_amount = (float) $pay['total_amount'];
                    $refund_desc   = "Extourne règlement — annulation {$po['reference']}"
                                   . ($reason !== '' ? " · {$reason}" : '');
                    $db->prepare("
                        INSERT INTO treasury_transactions
                          (account_id, transaction_type, amount, reference, description, logged_by)
                        VALUES (?, 'in_other', ?, ?, ?, ?)
                    ")->execute([
                        (int) $pay['treasury_account_id'],
                        $refund_amount,
                        "REV-PAY-FRS-{$po['reference']}",
                        $refund_desc,
                        $user_id,
                    ]);
                    $db->prepare("UPDATE treasury_accounts SET balance = balance + ? WHERE id = ?")
                       ->execute([$refund_amount, (int) $pay['treasury_account_id']]);
                    // Null the payment linkage on the PO so a re-run of
                    // cancellation is a no-op instead of double-refunding.
                    $db->prepare("
                        UPDATE purchase_orders
                           SET payment_status = 'unpaid',
                               payment_je_id  = NULL,
                               payment_date   = NULL
                         WHERE id = ?
                    ")->execute([$po_id]);
                }
            }

            // 3. Claw back the operational rebate ledger to match. Each live row
            //    for this order gets an opposing row and is flagged reversed, so
            //    the history in the Ristournes SDP panel shows both sides
            //    instead of a credit quietly disappearing.
            if (lpc_rebate_ledger_has_reversed($db)) {
                $stmtRebate = $db->prepare("
                    SELECT id, supplier_id, type, amount
                      FROM supplier_rebate_ledger
                     WHERE purchase_order_id = ? AND reversed = 0
                     FOR UPDATE
                ");
                $stmtRebate->execute([$po_id]);
                $rebate_rows = $stmtRebate->fetchAll(PDO::FETCH_ASSOC);

                $stmtRebateFlag = $db->prepare("UPDATE supplier_rebate_ledger SET reversed = 1 WHERE id = ?");

                foreach ($rebate_rows as $row) {
                    // An accrual is undone by a deduction and vice versa. Both
                    // sides of the pair are flagged reversed so the live balance
                    // ignores them while the history still shows what happened.
                    $opposite = ($row['type'] === 'accrual') ? 'deduction' : 'accrual';
                    lpc_rebate_ledger_add(
                        $db, (int) $row['supplier_id'], $po_id, date('Y-m-d'), $po['reference'],
                        $opposite, (float) $row['amount'],
                        "Extourne — annulation " . $po['reference'],
                        $user_id,
                        true
                    );
                    $stmtRebateFlag->execute([$row['id']]);
                }
            }

            // 4. Flag the order. The row and its items stay: a cancelled order
            //    is a fact about the business, not an accident to be erased.
            $db->prepare("
                UPDATE purchase_orders
                   SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?
                 WHERE id = ?
            ")->execute([$user_id, ($reason !== '' ? $reason : null), $po_id]);

            $db->commit();

            lpc_notify_permission(
                $db,
                'inventory.procurement.approve',
                'Bon de commande annulé',
                ($_SESSION['user_name'] ?? 'Un opérateur') . " a annulé le BC {$po['reference']} — stock et écritures extournés."
                    . ($reason !== '' ? " Motif : $reason" : ''),
                '/modules/inventory/procurement.php',
                'warning',
                [$user_id]
            );

            echo json_encode([
                'status'  => 'success',
                'message' => 'Commande annulée. Écritures comptables extournées ('
                           . count($reversals) . ') et stock ajusté.'
            ]);
            break;

        // ==========================================
        // ACTION: DELETE OVERHEAD
        // ==========================================
        case 'delete_overheads':
            // Was reachable by anyone holding only 'view'.
            Rbac::requirePermission('inventory.procurement.overhead');

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new UserFacingException("Dépense non spécifiée.");
            $stmt = $db->prepare("DELETE FROM overheads WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => "Action inconnue: {$action}"]);
            break;
    }

} catch (UserFacingException $e) {
    // A business rule said no. The message was written for the operator and is
    // the whole point of the refusal — passing it through is what lets someone
    // who over-spent their ristourne see the actual balance instead of
    // "Erreur serveur. Veuillez réessayer." and retrying forever.
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code($e->getHttpStatus());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);

} catch (Throwable $e) {
    // Anything else is unexpected: log it in full, tell the client nothing.
    // Throwable rather than Exception so a TypeError or a division by zero is
    // also rolled back rather than escaping with the transaction still open.
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}