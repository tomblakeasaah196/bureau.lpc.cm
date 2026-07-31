<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';   // Sprint 5
require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
require_once __DIR__ . '/../../includes/functions/procurement.php';
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
            $suppliers = $db->query("SELECT id, name FROM suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $products = $db->query("SELECT id, name, format, base_price FROM products WHERE category != 'Emballage' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $delivery_places = $db->query("SELECT id, name FROM delivery_places WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

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
                    'suppliers_with_ladder' => array_map('intval', $suppliers_with_ladder)
                ]
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

            $subtotal = 0;
            foreach ($items as $item) {
                $qty        = (int)   ($item['quantity']   ?? 0);
                $unit_price = (float) ($item['unit_price'] ?? 0);
                // Negative quantities or prices would let a line subtract from
                // the order total, and on reception would subtract from stock
                // through an 'in_supplier' movement.
                if ($qty <= 0 || $unit_price < 0) {
                    throw new UserFacingException("Ligne de commande invalide : quantité et prix doivent être positifs.");
                }
                $subtotal += $qty * $unit_price;
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

            $total_amount = max(0, $subtotal - $discount_amount);

            $hash = strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
            $reference = 'PO-' . date('ym') . '-' . $hash;
            $token = bin2hex(random_bytes(16));

            // 1. CREATE PO AS PENDING (No stock, no accounting yet)
            $stmtPO = $db->prepare("
                INSERT INTO purchase_orders
                (reference, supplier_id, delivery_place_id, date, status, subtotal, discount_amount, discount_note, total_amount, payment_status, token, created_by)
                VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtPO->execute([
                $reference, $supplier_id, $delivery_place_id, $date, $subtotal, $discount_amount, $discount_note, $total_amount, $payment_status, $token, $user_id
            ]);
            $po_id = $db->lastInsertId();

            $stmtLine = $db->prepare("INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            foreach ($items as $item) {
                $stmtLine->execute([$po_id, (int)$item['product_id'], (int)$item['quantity'], (float)$item['unit_price']]);
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
            $tax = lpc_rebate_tax_settings($db);
            $stmtProdCat = $db->prepare("SELECT category_id FROM products WHERE id = ?");
            $category_ttc_totals = []; // category_id => TTC subtotal for this PO
            foreach ($items as $item) {
                $stmtProdCat->execute([(int) $item['product_id']]);
                $cat_id = $stmtProdCat->fetchColumn();
                if (!$cat_id) continue; // product has no category assigned — cannot ladder it
                $cat_id = (int) $cat_id;
                $line_ttc = (int) $item['quantity'] * (float) $item['unit_price'];
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
            if ($discount_amount > 0) {
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

                if ($discount_amount > $rebate['balance']) {
                    throw new UserFacingException(sprintf(
                        "Ristourne insuffisante : solde %s FCFA, demandé %s FCFA.",
                        number_format($rebate['balance'], 0, ',', ' '),
                        number_format($discount_amount, 0, ',', ' ')
                    ));
                }

                lpc_rebate_ledger_add(
                    $db, $supplier_id, (int) $po_id, $date, $reference,
                    'deduction', $discount_amount, "Utilisation Ristourne (Remise)", $user_id
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
                    $discount_amount,
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
                $unit_price = (float) $price_row;

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
            $reversals = array_merge(
                JournalPoster::reverseSource('purchase_order', $po_id, $reason),
                JournalPoster::reverseSource('rebate_accrual', $po_id, $reason),
                JournalPoster::reverseSource('rebate_usage',   $po_id, $reason)
            );

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