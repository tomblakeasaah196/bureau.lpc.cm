<?php
// api/v1/sales_controller.php
// Bootstrap loads env, DB, hardened session, CSRF, Rbac. Do not add session_start here.
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';   // Sprint 5
// Tariff resolution + the price-change log. Migration 062 — a price change is
// a price change, never a discount; see the header of that file for why this
// is centralised rather than reimplemented per call site.
require_once __DIR__ . '/../../includes/functions/pricing.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('sales_controller: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
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

// -----------------------------------------------------------------------------
// Sprint-2: per-action RBAC enforcement.
// 'PUBLIC' = accessible via token URL without login (customer-facing sign flow).
//            The action's own logic MUST validate the token cryptographically.
// Any other value = required permission key (implicitly requires login).
// -----------------------------------------------------------------------------
$ACTION_PERMS = [
    // Customer signs the BL on their device via /sign_bl.php?token=... — public.
    'sign_bl'                 => 'PUBLIC',
    'reject_bl'               => 'PUBLIC',
    // Driver-side (authenticated).
    'driver_confirm_delivery' => 'sales.deliveries.close',
    // Ops / sales dashboards (authenticated).
    'fetch_metadata'          => 'sales.orders.view',
    'read'                    => 'sales.orders.view',
    'save_order'              => 'sales.orders.create',
    'generate_dispatch'       => 'sales.orders.dispatch',
    'get_delivery_items'      => 'sales.deliveries.view',
    'delete_order'            => 'sales.orders.delete',
    // Sprint 10 · Ventes/Achats parity (migration 062).
    //
    // Every read here is gated on .view and every write on the narrowest
    // permission that fits, exactly as Achats does — 'view' is not authority
    // to cancel an order or move money.
    'kpi_detail'              => 'sales.orders.view',
    'get_order_detail'        => 'sales.orders.view',
    'get_delivery_detail'     => 'sales.deliveries.view',
    'get_client_tariff'       => 'sales.orders.view',
    'update_order'            => 'sales.orders.edit',
    // Not .delete: delete destroys a pre-BL row, cancel keeps a post-BL one
    // and reverses what it committed. Different blast radius. See 062.
    'cancel_order'            => 'sales.orders.cancel',
];
if (!isset($ACTION_PERMS[$action])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action inconnue.']);
    exit;
}
if ($ACTION_PERMS[$action] !== 'PUBLIC') {
    Rbac::requirePermission($ACTION_PERMS[$action]);
    // State-changing endpoints called by the app also require a CSRF token.
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST','PUT','PATCH','DELETE'], true)) {
        Csrf::requireValid();
    }
}

// ==========================================
// PUBLIC ROUTES (Digital Signature by Client)
// ==========================================

if ($action === 'driver_confirm_delivery') {
    $token = $jsonData['token'] ?? '';
    $payment_collected = (float)$jsonData['payment_collected'];
    $adjustments = $jsonData['adjustments'] ?? [];

    if (empty($token)) {
        echo json_encode(['status' => 'error', 'message' => 'Token manquant.']);
        exit;
    }

    try {
        $db->beginTransaction();
        $stmtDel = $db->prepare("SELECT id, status, token FROM deliveries WHERE token = ? FOR UPDATE");
        $stmtDel->execute([$token]);
        $delivery = $stmtDel->fetch(PDO::FETCH_ASSOC);

        if (!$delivery || $delivery['status'] === 'completed') {
            throw new UserFacingException("Ce BL est déjà clôturé.");
        }

        $stmtUpdateItem = $db->prepare("UPDATE delivery_items SET delivered_quantity = ?, returned_empty_qty = ? WHERE id = ?");
        foreach ($adjustments as $adj) {
            $stmtUpdateItem->execute([(int)$adj['accepted_qty'], (int)($adj['returned_empty_qty'] ?? 0), (int)$adj['item_id']]);
        }

        $stmtCloseDel = $db->prepare("UPDATE deliveries SET status = 'driver_confirmed', payment_collected = ? WHERE id = ?");
        $stmtCloseDel->execute([$payment_collected, $delivery['id']]);

        $db->commit();
        echo json_encode(['status' => 'success', 'token' => $delivery['token']]);
        exit;
    } catch (UserFacingException $e) {
        // Same split as the main handler below: a rule the caller can act on
        // ("Ce BL est deja cloture") must reach them, or the driver retries a
        // request that can never succeed. These two routes are public, so the
        // generic branch stays deliberately mute about internals.
        if ($db->inTransaction()) $db->rollBack();
        http_response_code($e->getHttpStatus());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez reessayer.']);
        exit;
    }
}

if ($action === 'sign_bl' || $action === 'reject_bl') {
    $token = $jsonData['token'] ?? '';
    if (empty($token)) {
        echo json_encode(['status' => 'error', 'message' => 'Token manquant.']);
        exit;
    }

    // -----------------------------------------------------------------------
    // sign_bl is DEPRECATED here. The unified signature system lives at
    // /api/v1/signatures_controller.php?action=sign_external — sign_bl.php
    // now posts there directly. This stub returns 410 Gone with a pointer
    // for any queued/offline request that still targets the old URL.
    //
    // reject_bl still lives here: it's not a signature action, just a
    // status flip back to 'dispatched' so the driver can correct numbers.
    // -----------------------------------------------------------------------
    if ($action === 'sign_bl') {
        http_response_code(410);
        echo json_encode([
            'status'  => 'error',
            'code'    => 'endpoint_moved',
            'message' => "Cette route a été remplacée. Rechargez la page pour utiliser le nouveau flux.",
            'moved_to' => '/api/v1/signatures_controller.php?action=sign_external&type=bl',
        ]);
        exit;
    }

    try {
        $db->beginTransaction();

        $stmtCheck = $db->prepare("SELECT id, reference, sales_order_id, client_id, payment_collected, driver_id FROM deliveries WHERE token = ? AND status IN ('driver_confirmed','dispatched') FOR UPDATE");
        $stmtCheck->execute([$token]);
        $delivery = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) throw new UserFacingException("Ce Bon de Livraison est introuvable, déjà signé, ou dans un état inattendu.");

        // CLIENT REJECTS THE NUMBERS
        if ($action === 'reject_bl') {
            $reason = trim($jsonData['reason'] ?? '');
            if ($reason === '') throw new UserFacingException('Motif du refus requis.');
            // Push it back to the driver to correct the numbers
            $db->prepare("UPDATE deliveries SET status = 'dispatched', rejection_reason = ? WHERE id = ?")->execute([$reason, $delivery['id']]);
            $db->commit();
            echo json_encode(['status' => 'success', 'message' => 'BL refusé. Le chauffeur doit corriger les quantités.']);
            exit;
        }

    } catch (UserFacingException $e) {
        // Same split as the main handler below: a rule the caller can act on
        // ("Ce BL est deja cloture") must reach them, or the driver retries a
        // request that can never succeed. These two routes are public, so the
        // generic branch stays deliberately mute about internals.
        if ($db->inTransaction()) $db->rollBack();
        http_response_code($e->getHttpStatus());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez reessayer.']);
        exit;
    }
}


// ==========================================
// STRICT RBAC SECURITY GATE (Internal Routes)
// ==========================================
$user_id = (int)$_SESSION['user_id'];

try {
    switch ($action) {
        case 'fetch_metadata':
            $clients = $db->query("SELECT id, name FROM clients WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $products = $db->query("SELECT id, name, format, base_price FROM products WHERE category != 'Emballage' AND is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            
            // Migration 061 · business-model change.
            //
            // This used to be the ONLY source of drivers for the BL modal:
            //
            //   FROM vehicle_assignments a JOIN users u ... JOIN vehicles v ...
            //   WHERE a.assignment_date = CURRENT_DATE()
            //
            // i.e. sales could dispatch to a driver only if Flotte had already
            // recorded that morning's affectation. No affectation row, no
            // driver in the dropdown, no BL — a sales-blocking dependency on a
            // fleet-office task. Now that suppliers deliver direct to client
            // sites, that dependency is not merely inconvenient, it is wrong:
            // a supplier delivery has no LPC affectation by definition.
            //
            // Three independent lists are returned instead, and the affectation
            // is demoted to a CONVENIENCE — it pre-selects the vehicle for a
            // driver who happens to have one today, and is silent otherwise.
            $drivers = $db->query("
                SELECT u.id, u.first_name, u.last_name
                  FROM users u
                  JOIN roles r ON u.role_id = r.id
                 WHERE u.status = 'active' AND r.name = 'driver'
                 ORDER BY u.first_name ASC, u.last_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Free choice of vehicle: no longer locked to the day's pairing.
            $vehicles = $db->query("
                SELECT id, plate_number, type
                  FROM vehicles
                 WHERE status = 'active' AND is_active = 1
                 ORDER BY plate_number ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Suppliers who can deliver straight to the client site.
            $suppliers = $db->query("
                SELECT id, name
                  FROM suppliers
                 WHERE is_active = 1
                 ORDER BY name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Today's affectation, kept only as a hint for the UI's vehicle
            // pre-fill. Nothing downstream may treat an empty result as a
            // reason to refuse a dispatch.
            $stmt = $db->query("
                SELECT a.driver_id, a.vehicle_id
                  FROM vehicle_assignments a
                  JOIN users u    ON a.driver_id  = u.id
                  JOIN vehicles v ON a.vehicle_id = v.id
                 WHERE a.assignment_date = CURRENT_DATE() AND u.status = 'active'
            ");
            $daily_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => [
                'clients'     => $clients,
                'products'    => $products,
                'drivers'     => $drivers,
                'vehicles'    => $vehicles,
                'suppliers'   => $suppliers,
                'assignments' => $daily_assignments,
            ]]);
            break;

        case 'read':
            $tab = $_GET['tab'] ?? 'orders';
            $responseData = ['table' => [], 'kpis' => []];
            $lpc_q = trim((string) ($_GET['q'] ?? ''));   // Sprint 5 · server-side search

            // -----------------------------------------------------------------
            // Sprint 10 · the period. Previously absent on this module entirely.
            //
            // The KPI block below used to be hardcoded to
            // `MONTH(date) = MONTH(CURRENT_DATE())`, so "Ventes (Mois)" could
            // only ever mean the calendar month you happened to be standing in.
            // There was no way to look at last month, at the year, or at a
            // range — from this page the rest of the sales history did not
            // exist. Achats fixed the same problem for purchases; these are the
            // matching bounds, with the same defaults.
            //
            // Wide defaults (2000→2099) rather than CURRENT_DATE so a caller
            // that omits the params gets everything, not an empty page.
            // -----------------------------------------------------------------
            $start_date = $_GET['start'] ?? '2000-01-01';
            $end_date   = $_GET['end']   ?? '2099-12-31';

            if ($tab === 'orders') {
                // Sprint 5: replace unbounded fetch with Paginator.
                $body = "
                    FROM sales_orders so
                    JOIN clients c ON so.client_id = c.id
                    WHERE so.date BETWEEN ? AND ?
                ";
                $params = [$start_date, $end_date];
                if ($lpc_q !== '') {
                    [$body, $params] = Paginator::addWhere(
                        $body, $params, $lpc_q,
                        ['so.reference', 'c.name', 'so.status', 'so.payment_status']
                    );
                }
                $body .= " ORDER BY so.date DESC, so.id DESC";
                // Facturation status is resolved per order through its
                // deliveries: an order is invoiced when every BL it produced
                // appears in invoice_deliveries. Correlated subqueries rather
                // than JOINs because a JOIN through deliveries would multiply
                // the order row per BL and break both the count and the paging.
                //
                // This is the link the sales side never had. `invoice_deliveries`
                // has existed all along and the invoicing module reads it, but
                // nothing on this page ever showed whether a delivered order had
                // been billed — you had to go and look in Facturation.
                $page = Paginator::paginate($db, $body, $params,
                    "so.id, so.reference, so.date, so.status, so.payment_status,
                     so.subtotal, so.discount_amount, so.discount_note, so.total_amount,
                     so.cancelled_at, so.cancellation_reason,
                     c.name as client_name, c.id as client_id,
                     (SELECT COUNT(*) FROM deliveries d WHERE d.sales_order_id = so.id)
                        AS delivery_count,
                     (SELECT COUNT(*) FROM deliveries d
                        JOIN invoice_deliveries idl ON idl.delivery_id = d.id
                       WHERE d.sales_order_id = so.id)
                        AS invoiced_count,
                     (SELECT i.reference FROM deliveries d
                        JOIN invoice_deliveries idl ON idl.delivery_id = d.id
                        JOIN invoices i ON i.id = idl.invoice_id
                       WHERE d.sales_order_id = so.id
                       ORDER BY i.id DESC LIMIT 1)
                        AS invoice_reference,
                     (SELECT idl.invoice_id FROM deliveries d
                        JOIN invoice_deliveries idl ON idl.delivery_id = d.id
                       WHERE d.sales_order_id = so.id
                       ORDER BY idl.invoice_id DESC LIMIT 1)
                        AS invoice_id",
                    null, null, "sales.read.orders:$start_date:$end_date");
                $responseData['table']      = $page['data'];
                $responseData['pagination'] = [
                    'page'        => $page['page'],
                    'per_page'    => $page['per_page'],
                    'total'       => $page['total'],
                    'total_pages' => $page['total_pages'],
                    'has_prev'    => $page['has_prev'],
                    'has_next'    => $page['has_next'],
                ];

                // Cancelled orders are excluded from every KPI, exactly as
                // Achats excludes cancelled purchase orders. They stay in the
                // table (greyed) so the history is visible, but they are not
                // revenue, they are not owed, and they are not awaiting
                // delivery — counting them would overstate all three.
                $stmtKpi = $db->prepare("
                    SELECT
                        COALESCE(SUM(total_amount), 0) as kpi_so_total,
                        COUNT(CASE WHEN status = 'pending' THEN 1 END) as kpi_so_pending,
                        COALESCE(SUM(CASE WHEN payment_status != 'paid' THEN total_amount ELSE 0 END), 0) as kpi_so_debt,
                        COALESCE(SUM(discount_amount), 0) as kpi_so_discount,
                        COUNT(id) as kpi_so_count
                    FROM sales_orders
                    WHERE date BETWEEN ? AND ?
                      AND status <> 'cancelled'
                ");
                $stmtKpi->execute([$start_date, $end_date]);
                $kpis = $stmtKpi->fetch(PDO::FETCH_ASSOC);

                $responseData['kpis'] = [
                    'kpi_so_total'    => number_format($kpis['kpi_so_total'], 0, ',', ' ') . ' FCFA',
                    'kpi_so_pending'  => $kpis['kpi_so_pending'] . ' Cmd(s)',
                    'kpi_so_debt'     => number_format($kpis['kpi_so_debt'], 0, ',', ' ') . ' FCFA',
                    // Remises Accordées — the sell-side mirror of Achats'
                    // "Remises Obtenues". discount_amount has been written by
                    // save_order since this module was built and read back by
                    // nothing at all until now.
                    'kpi_so_discount' => number_format($kpis['kpi_so_discount'], 0, ',', ' ') . ' FCFA',
                    'kpi_so_count'    => $kpis['kpi_so_count'] . ' Cmd(s)'
                ];
            } elseif ($tab === 'dispatch') {
                // Migration 061: the transporter column can now be a supplier
                // as well as a driver, so the supplier table joins in here and
                // the free-text search covers supplier names too — otherwise a
                // BL delivered by "Prometal" would be unfindable by that word.
                $body = "
                    FROM deliveries d
                    JOIN clients c ON d.client_id = c.id
                    LEFT JOIN users u ON d.driver_id = u.id
                    LEFT JOIN suppliers s ON d.delivery_supplier_id = s.id
                    WHERE d.date BETWEEN ? AND ?
                ";
                $params = [$start_date, $end_date];
                if ($lpc_q !== '') {
                    [$body, $params] = Paginator::addWhere(
                        $body, $params, $lpc_q,
                        ['d.reference', 'c.name', 'd.status', 'u.first_name', 'u.last_name', 's.name']
                    );
                }
                $body .= " ORDER BY d.date DESC, d.id DESC";
                // so.reference and the invoice link are carried on each BL row
                // so the dispatch list can navigate both upstream (which order
                // is this?) and downstream (has it been billed?) without a
                // second round trip.
                $page = Paginator::paginate($db, $body, $params,
                    "d.id, d.reference, d.date, d.status, d.token, d.payment_collected,
                     d.delivery_mode, d.sales_order_id,
                     c.name as client_name,
                     CONCAT(u.first_name, ' ', u.last_name) as driver_name,
                     s.name as supplier_name,
                     (SELECT so2.reference FROM sales_orders so2 WHERE so2.id = d.sales_order_id)
                        AS so_reference,
                     (SELECT i.reference FROM invoice_deliveries idl
                        JOIN invoices i ON i.id = idl.invoice_id
                       WHERE idl.delivery_id = d.id LIMIT 1)
                        AS invoice_reference",
                    null, null, "sales.read.dispatch:$start_date:$end_date");
                $responseData['table']      = $page['data'];
                $responseData['pagination'] = [
                    'page'        => $page['page'],
                    'per_page'    => $page['per_page'],
                    'total'       => $page['total'],
                    'total_pages' => $page['total_pages'],
                    'has_prev'    => $page['has_prev'],
                    'has_next'    => $page['has_next'],
                ];

                // "Camions en Route" counted every dispatched row as a truck.
                // Since Migration 061 an in-transit row may be a supplier
                // delivering on their own means, or a client who drove off with
                // their own vehicle — neither is an LPC truck, and counting
                // them as one overstates fleet load. Split by channel so the
                // number means what its label says.
                //
                // Sprint 10 · all four now respect the period selector. Three
                // of them were pinned to CURRENT_DATE() and the fourth
                // ("Rejets") to all-time, so the ribbon mixed today, all-time
                // and in-flight figures under one set of labels and changing
                // the period moved nothing.
                //
                // "En Transit" is the exception and stays unbounded on purpose:
                // a truck that left last month is still on the road today, and
                // filtering it into a period would hide exactly the rows an ops
                // user opens this tab to chase. Labelled "(en cours)" in the UI
                // so the inconsistency is stated rather than hidden.
                $stmtKpi = $db->prepare("
                    SELECT
                        COUNT(CASE WHEN d.status = 'dispatched' AND d.delivery_mode = 'own_fleet' THEN 1 END) as kpi_dl_fleet,
                        COUNT(CASE WHEN d.status = 'dispatched' AND d.delivery_mode = 'supplier'  THEN 1 END) as kpi_dl_supplier,
                        COUNT(CASE WHEN d.status = 'completed' AND d.date BETWEEN ? AND ? THEN 1 END) as kpi_dl_completed,
                        COALESCE(SUM(CASE WHEN d.date BETWEEN ? AND ? THEN d.payment_collected ELSE 0 END), 0) as kpi_dl_cash,
                        (SELECT COUNT(*)
                           FROM delivery_items di
                           JOIN deliveries d2 ON d2.id = di.delivery_id
                          WHERE di.delivered_quantity < di.quantity
                            AND di.delivered_quantity IS NOT NULL
                            AND d2.date BETWEEN ? AND ?) as kpi_dl_returns
                    FROM deliveries d
                ");
                $stmtKpi->execute([
                    $start_date, $end_date,
                    $start_date, $end_date,
                    $start_date, $end_date,
                ]);
                $kpis = $stmtKpi->fetch(PDO::FETCH_ASSOC);

                $responseData['kpis'] = [
                    'kpi_dl_dispatched' => $kpis['kpi_dl_fleet'] . ' Camion(s) · ' . $kpis['kpi_dl_supplier'] . ' Frn.',
                    'kpi_dl_completed'  => $kpis['kpi_dl_completed'] . ' BL(s)',
                    'kpi_dl_cash'       => number_format($kpis['kpi_dl_cash'], 0, ',', ' ') . ' FCFA',
                    'kpi_dl_returns'    => $kpis['kpi_dl_returns'] . ' Ligne(s)'
                ];
            }
            echo json_encode(['status' => 'success', 'data' => $responseData]);
            break;

        // =====================================================================
        // ACTION: SAVE SALES ORDER
        // ---------------------------------------------------------------------
        // Migration 062 · a price change is a price change, never a discount.
        //
        // The old body multiplied whatever price was typed by the quantity and
        // wrote it. A seller could quote 850 and charge 700 and the 150 existed
        // nowhere — not as a price, not as a remise. It was simply gone.
        //
        // The fix is NOT to detect it. Subtracting the typed price from the
        // tariff and calling the gap a discount produces a fiction: prices move
        // for a dozen ordinary reasons and every one of them would surface as
        // money given away. So nothing is inferred here. The disparity is put
        // back to the operator, who declares which of the two things it is, and
        // this endpoint records the declaration:
        //
        //   confirmed  → the client's new price. client_prices moves,
        //                client_price_history logs it, the line carries no
        //                discount (list_price = unit_price).
        //   declined   → a one-off remise. The tariff is untouched, and the gap
        //                is stored on the line as a DECLARED discount.
        //
        // Two round trips: the first returns `needs_price_confirmation` and
        // writes nothing; the browser opens the reconciliation modal and posts
        // again with `price_decisions`. Deliberately not one round trip with a
        // "force" flag — that degrades into the client deciding, which is the
        // thing being prevented.
        // =====================================================================
        case 'save_order':
            $client_id       = (int) ($jsonData['client_id'] ?? 0);
            $date            = $jsonData['date'] ?? date('Y-m-d');
            $order_discount  = round((float) ($jsonData['discount_amount'] ?? 0), 2);
            $discount_note   = trim((string) ($jsonData['discount_note'] ?? ''));
            $items           = $jsonData['items'] ?? [];
            // product_id => bool. true = "this is the new price".
            $price_decisions = is_array($jsonData['price_decisions'] ?? null)
                             ? $jsonData['price_decisions'] : [];

            if ($client_id <= 0 || empty($items)) throw new UserFacingException("Données incomplètes.");

            $stmtCli = $db->prepare("SELECT id FROM clients WHERE id = ? AND deleted_at IS NULL");
            $stmtCli->execute([$client_id]);
            if (!$stmtCli->fetchColumn()) throw new UserFacingException("Client introuvable.");

            // Normalise and validate the lines before anything is priced, so a
            // bad quantity fails on its own terms rather than as an arithmetic
            // oddity three steps later.
            $clean = [];
            foreach ($items as $item) {
                $pid = (int) ($item['product_id'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 0);
                $up  = round((float) ($item['unit_price'] ?? 0), 2);
                if ($pid <= 0)  throw new UserFacingException("Ligne sans produit.");
                if ($qty <= 0)  throw new UserFacingException("Quantité invalide sur une ligne.");
                if ($up  <  0)  throw new UserFacingException("Prix négatif sur une ligne.");
                $clean[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $up];
            }

            $pids      = array_column($clean, 'product_id');
            $catalogue = lpc_catalogue_prices($db, $pids);
            $tariff    = lpc_client_tariff($db, $client_id, $pids);

            foreach ($pids as $pid) {
                if (!array_key_exists($pid, $catalogue)) {
                    throw new UserFacingException("Produit #$pid introuvable ou inactif.");
                }
            }

            $disparities = lpc_price_disparities($clean, $tariff, $catalogue);

            // ---- Round 1: nothing decided yet. Ask, write nothing. ----------
            $undecided = [];
            foreach ($disparities as $pid => $d) {
                if (!array_key_exists((string) $pid, $price_decisions)
                    && !array_key_exists($pid, $price_decisions)) {
                    $undecided[] = $pid;
                }
            }

            if (!empty($undecided)) {
                // Names are attached here rather than in the helper so that
                // pricing.php stays free of presentation concerns and can be
                // reused by the PO form (063) without carrying a products join.
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

                echo json_encode([
                    'status'      => 'needs_price_confirmation',
                    'disparities' => $payload,
                ]);
                break;
            }

            // ---- Round 2: every disparity carries a decision. Write. --------
            $db->beginTransaction();

            $subtotal           = 0.0;   // at tariff
            $line_discount_total = 0.0;
            $priced             = [];
            $repriced           = [];

            foreach ($clean as $l) {
                $pid     = $l['product_id'];
                $qty     = $l['quantity'];
                $typed   = $l['unit_price'];
                $current = round(lpc_effective_price($tariff, $pid, $catalogue[$pid]), 2);

                $changed = abs($typed - $current) >= 0.005;
                $confirm = $changed
                    && (($price_decisions[$pid] ?? $price_decisions[(string) $pid] ?? false) ? true : false);

                if (!$changed) {
                    // Sold at tariff. The ordinary case.
                    $list = $current; $unit = $current; $lineDisc = 0.0;

                } elseif ($confirm) {
                    // Declared: this is the client's new price. No discount —
                    // there is nothing to discount against once the tariff has
                    // moved, which is exactly why list_price = unit_price here.
                    $list = $typed; $unit = $typed; $lineDisc = 0.0;
                    $repriced[$pid] = $typed;

                } else {
                    // Declared: a one-off reduction. Tariff stands.
                    if ($typed > $current) {
                        // Charging ABOVE tariff while refusing to call it the
                        // new price would have to be stored as a negative
                        // remise — a surcharge dressed as a discount, which is
                        // meaningless and would corrupt every discount total
                        // that sums this column. There is no third option here
                        // and guessing one would be worse than refusing.
                        throw new UserFacingException(
                            "Prix supérieur au tarif sur une ligne sans confirmation. "
                            . "Confirmez le nouveau prix, ou ramenez la ligne au tarif."
                        );
                    }
                    $list = $current; $unit = $typed;
                    $lineDisc = round(($current - $typed) * $qty, 2);
                }

                $subtotal            += $list * $qty;
                $line_discount_total += $lineDisc;
                $priced[] = [
                    'product_id' => $pid, 'quantity' => $qty,
                    'unit_price' => $unit, 'list_price' => $list,
                    'discount'   => $lineDisc,
                ];
            }

            $subtotal        = round($subtotal, 2);
            $total_discount  = round($line_discount_total + $order_discount, 2);

            if ($order_discount < 0) throw new UserFacingException("Remise négative interdite.");
            if ($order_discount > 0 && $discount_note === '') {
                throw new UserFacingException("Motif de la remise obligatoire.");
            }
            if ($total_discount > $subtotal) {
                throw new UserFacingException(sprintf(
                    "Remise totale (%s FCFA) supérieure au sous-total (%s FCFA).",
                    number_format($total_discount, 0, ',', ' '),
                    number_format($subtotal, 0, ',', ' ')
                ));
            }

            // Ceiling on the declared remise. Repricing is never gated by this:
            // moving a client's price is ordinary commercial work, giving money
            // away on a single order is not. 0 disables the ceiling.
            $max_pct = Prefs::float('sales_discount_max_pct', 15);
            if ($max_pct > 0 && $subtotal > 0) {
                $pct = ($total_discount / $subtotal) * 100;
                if ($pct > $max_pct && !Rbac::hasPermission('sales.orders.discount_approve')) {
                    throw new UserFacingException(sprintf(
                        "Remise de %.1f%% au-delà du plafond de %.0f%%. Approbation requise.",
                        $pct, $max_pct
                    ));
                }
            }

            $total_amount = round($subtotal - $total_discount, 2);

            $hash = strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
            // Was 'CMD-' . date('ym') hardcoded, which ignored the configured
            // doc_prefix_order / doc_number_format that every other document
            // type in the app respects (Prefs::FALLBACKS already carries 'CMD').
            $reference = Prefs::docNumber('order', $hash, $date);

            $stmtSO = $db->prepare("
                INSERT INTO sales_orders
                    (reference, client_id, date, subtotal, discount_amount, discount_note, total_amount, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtSO->execute([
                $reference, $client_id, $date, $subtotal, $total_discount,
                $discount_note !== '' ? $discount_note : null,
                $total_amount, $user_id,
            ]);
            $so_id = (int) $db->lastInsertId();

            $stmtLine = $db->prepare("
                INSERT INTO sales_order_items
                    (sales_order_id, product_id, quantity, unit_price, list_price, discount_amount)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($priced as $p) {
                $stmtLine->execute([
                    $so_id, $p['product_id'], $p['quantity'],
                    $p['unit_price'], $p['list_price'], $p['discount'],
                ]);
            }

            // Repricing happens inside the same transaction as the order that
            // caused it. An order that rolls back must not leave a new tariff
            // standing — the operator confirmed a price *for this sale*, and if
            // the sale did not happen neither did the decision.
            foreach ($repriced as $pid => $newPrice) {
                lpc_apply_client_price_change(
                    $db, $client_id, (int) $pid, (float) $newPrice, $user_id,
                    'order', $reference, 'Confirmé à la saisie de la commande'
                );
            }

            $db->commit();
            echo json_encode([
                'status'    => 'success',
                'message'   => 'Commande enregistrée.',
                'reference' => $reference,
                'id'        => $so_id,
                'repriced'  => count($repriced),
                'discount'  => $total_discount,
            ]);
            break;

        case 'generate_dispatch':
            $db->beginTransaction();
            $so_id = (int)$jsonData['sales_order_id'];
            $date = $jsonData['date'];
            $driver_id = !empty($jsonData['driver_id']) ? (int)$jsonData['driver_id'] : null;
            $vehicle_id = !empty($jsonData['vehicle_id']) ? (int)$jsonData['vehicle_id'] : null;

            // Migration 061 · the logistics channel is now explicit rather than
            // inferred from which FKs happen to be NULL. Default 'own_fleet'
            // keeps any client that has not shipped the new modal working.
            $delivery_mode = $jsonData['delivery_mode'] ?? 'own_fleet';
            $delivery_supplier_id = !empty($jsonData['delivery_supplier_id'])
                ? (int)$jsonData['delivery_supplier_id'] : null;

            $ALLOWED_MODES = ['own_fleet', 'supplier', 'client_pickup'];
            if (!in_array($delivery_mode, $ALLOWED_MODES, true)) {
                throw new UserFacingException("Mode de livraison invalide.");
            }

            // Normalise the payload to the channel, rather than trusting the
            // client to have cleared the fields it hid. A stale 'supplier'
            // select left populated after the user switched back to the fleet
            // would otherwise violate chk_delivery_supplier_mode and surface as
            // an opaque 500 instead of a clear rule.
            if ($delivery_mode === 'supplier') {
                if (!$delivery_supplier_id) {
                    throw new UserFacingException("Sélectionnez le fournisseur qui assure la livraison.");
                }
                // FK would catch a bad id, but the message would be a constraint
                // violation. Check explicitly so the user gets a usable one.
                $stmtSup = $db->prepare("SELECT id FROM suppliers WHERE id = ? AND is_active = 1");
                $stmtSup->execute([$delivery_supplier_id]);
                if (!$stmtSup->fetchColumn()) {
                    throw new UserFacingException("Fournisseur introuvable ou inactif.");
                }
                // A supplier delivery uses the supplier's own means. No LPC
                // driver, no LPC vehicle — by definition, not by omission.
                $driver_id = null;
                $vehicle_id = null;
            } elseif ($delivery_mode === 'client_pickup') {
                // Enlèvement magasin: the client's own vehicle.
                $driver_id = null;
                $vehicle_id = null;
                $delivery_supplier_id = null;
            } else { // own_fleet
                $delivery_supplier_id = null;
                // The driver is required for the fleet channel — but note what
                // is NOT required: an entry in vehicle_assignments. Any active
                // driver is dispatchable whether or not Flotte ran the morning
                // affectation. That gate is what this change removes.
                if (!$driver_id) {
                    throw new UserFacingException("Sélectionnez le chauffeur, ou choisissez un autre mode de livraison.");
                }
                $stmtDrv = $db->prepare("
                    SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id
                     WHERE u.id = ? AND u.status = 'active' AND r.name = 'driver'
                ");
                $stmtDrv->execute([$driver_id]);
                if (!$stmtDrv->fetchColumn()) {
                    throw new UserFacingException("Chauffeur introuvable ou inactif.");
                }
                if ($vehicle_id) {
                    $stmtVeh = $db->prepare("SELECT id FROM vehicles WHERE id = ? AND status = 'active' AND is_active = 1");
                    $stmtVeh->execute([$vehicle_id]);
                    if (!$stmtVeh->fetchColumn()) {
                        throw new UserFacingException("Véhicule introuvable ou hors service.");
                    }
                }
                // $vehicle_id stays optional: a driver can leave on foot with a
                // handcart, or the vehicle can be recorded later.
            }

            $stmtCheck = $db->prepare("SELECT client_id, status FROM sales_orders WHERE id = ? FOR UPDATE");
            $stmtCheck->execute([$so_id]);
            $order = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$order || $order['status'] !== 'pending') throw new UserFacingException("Cette commande a déjà été dispatchée.");

            $hash = strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
            // Sprint 8: prefix + format from app_preferences. Was 'BL-' hardcoded.
            $reference = Prefs::docNumber('delivery', $hash);
            $token = bin2hex(random_bytes(16));

            $stmtDel = $db->prepare("INSERT INTO deliveries (reference, sales_order_id, client_id, driver_id, vehicle_id, delivery_mode, delivery_supplier_id, date, status, token, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'dispatched', ?, ?)");
            $stmtDel->execute([$reference, $so_id, $order['client_id'], $driver_id, $vehicle_id, $delivery_mode, $delivery_supplier_id, $date, $token, $user_id]);
            $delivery_id = $db->lastInsertId();

            $stmtItems = $db->prepare("SELECT product_id, quantity, unit_price FROM sales_order_items WHERE sales_order_id = ?");
            $stmtItems->execute([$so_id]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $stmtDelItem = $db->prepare("INSERT INTO delivery_items (delivery_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            $stmtInvOut  = $db->prepare("INSERT INTO inventory_movements (product_id, movement_type, quantity, reference_id, logged_by) VALUES (?, 'out_delivery', ?, ?, ?)");

            // Sprint-4-Batch-B: lock the aggregated stock per product BEFORE
            // decrementing so two concurrent dispatches for the same SKU can't
            // over-issue. The FOR UPDATE on products blocks concurrent
            // damage/audit paths that also touch the same row.
            $stmtStockLock = $db->prepare("
                SELECT p.name,
                       COALESCE((SELECT SUM(CASE WHEN movement_type LIKE 'in_%' THEN quantity ELSE -quantity END)
                                   FROM inventory_movements WHERE product_id = p.id), 0) AS current_qty
                  FROM products p WHERE p.id = ? FOR UPDATE
            ");

            foreach ($items as $item) {
                $stmtStockLock->execute([$item['product_id']]);
                $prod = $stmtStockLock->fetch(PDO::FETCH_ASSOC);
                if (!$prod) throw new UserFacingException("Produit #{$item['product_id']} introuvable.");
                if ((int) $prod['current_qty'] < (int) $item['quantity']) {
                    throw new UserFacingException("Stock insuffisant pour {$prod['name']} ({$prod['current_qty']} disponible, {$item['quantity']} demandé).");
                }

                $stmtDelItem->execute([$delivery_id, $item['product_id'], $item['quantity'], $item['unit_price']]);
                $stmtInvOut->execute([$item['product_id'], $item['quantity'], $delivery_id, $user_id]);
            }

            // Sprint 9 · migration 041: mirror the physical stock movement in
            // the ledger — Dr 6031 / Cr 31x, valued at CUMP, per category.
            // Until now inventory_movements was the only record that anything
            // had left the warehouse, so stock value on the balance sheet only
            // ever grew. Same transaction: if the JE won't balance, the
            // dispatch rolls back with it.
            require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
            JournalPoster::postDeliveryCogs((int) $delivery_id, $date, $reference);

            $db->prepare("UPDATE sales_orders SET status = 'dispatched' WHERE id = ?")
               ->execute([$so_id]);
            $db->commit();
            echo json_encode(['status' => 'success', 'token' => $token]);
            break;

        case 'get_delivery_items':
            $delivery_id = (int)$_GET['id'];
            $stmt = $db->prepare("
                SELECT di.id, di.product_id, di.quantity as dispatched_qty, p.name as product_name, 
                       p.linked_empty_id, pe.name as empty_name 
                FROM delivery_items di 
                JOIN products p ON di.product_id = p.id 
                LEFT JOIN products pe ON p.linked_empty_id = pe.id
                WHERE di.delivery_id = ?
            ");
            $stmt->execute([$delivery_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'delete_order':
            $so_id = (int)$_POST['id'];
            $stmtCheck = $db->prepare("SELECT status FROM sales_orders WHERE id = ?");
            $stmtCheck->execute([$so_id]);
            if ($stmtCheck->fetchColumn() !== 'pending') throw new UserFacingException("Impossible de supprimer. BL déjà généré.");

            $db->beginTransaction();
            $db->prepare("DELETE FROM sales_order_items WHERE sales_order_id = ?")->execute([$so_id]);
            $db->prepare("DELETE FROM sales_orders      WHERE id = ?")->execute([$so_id]);
            $db->commit();
            echo json_encode(['status' => 'success']);
            break;

        // =====================================================================
        // ACTION: CLIENT TARIFF (for the order form)
        // ---------------------------------------------------------------------
        // The picker resolves prices at mount time from the client selected
        // then. Changing the client afterwards has to re-price the lines
        // already on screen, otherwise the operator is looking at client A's
        // prices on client B's order — and every one of those lines would then
        // raise a false disparity at submit.
        // =====================================================================
        case 'get_client_tariff':
            $tclient = (int) ($_GET['client_id'] ?? 0);
            if ($tclient <= 0) throw new UserFacingException("Client non spécifié.");

            $rows = $db->prepare("
                SELECT p.id, p.name, p.base_price, cp.custom_price
                  FROM products p
                  LEFT JOIN client_prices cp
                         ON cp.product_id = p.id AND cp.client_id = ?
                 WHERE p.is_active = 1
            ");
            $rows->execute([$tclient]);

            $tariffOut = [];
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $negotiated = $r['custom_price'] !== null;
                $tariffOut[(int) $r['id']] = [
                    'price'         => (float) ($negotiated ? $r['custom_price'] : $r['base_price']),
                    'base_price'    => (float) $r['base_price'],
                    'is_negotiated' => $negotiated,
                ];
            }
            echo json_encode(['status' => 'success', 'data' => $tariffOut]);
            break;

        // =====================================================================
        // ACTION: ORDER DETAIL  (modules/sales/order_detail.php)
        // ---------------------------------------------------------------------
        // The sell-side mirror of get_po_detail. Everything about one order in
        // a single round trip: lines with what was ordered against what was
        // actually delivered, every BL it produced, the invoice(s) that
        // followed, payments, and the discount breakdown.
        //
        // Before this, an order had no detail view at all — the list row was
        // the entire truth available about it, and answering "what happened to
        // CMD-2604-90B6?" meant opening three other modules.
        // =====================================================================
        case 'get_order_detail':
            $so_id = (int) ($_GET['id'] ?? 0);
            if ($so_id <= 0) throw new UserFacingException("Commande non spécifiée.");

            $stmt = $db->prepare("
                SELECT so.*, c.name AS client_name, c.phone AS client_phone, c.address AS client_address,
                       CONCAT(u.first_name, ' ', u.last_name) AS created_by_name,
                       CONCAT(uc.first_name, ' ', uc.last_name) AS cancelled_by_name
                  FROM sales_orders so
                  JOIN clients c ON c.id = so.client_id
                  LEFT JOIN users u  ON u.id  = so.created_by
                  LEFT JOIN users uc ON uc.id = so.cancelled_by
                 WHERE so.id = ?
            ");
            $stmt->execute([$so_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) throw new UserFacingException("Commande introuvable.");

            // Lines with ordered / dispatched / accepted quantities. The two
            // delivered figures are different questions and are kept apart:
            // `qty_dispatched` is what left the warehouse, `qty_accepted` is
            // what the client signed for. They diverge on a partial rejection,
            // and collapsing them would hide exactly that.
            $stmtLines = $db->prepare("
                SELECT soi.id, soi.product_id, p.name AS product_name, p.format,
                       soi.quantity AS qty_ordered, soi.unit_price, soi.list_price,
                       soi.discount_amount AS line_discount,
                       COALESCE((
                           SELECT SUM(di.quantity) FROM delivery_items di
                             JOIN deliveries d ON d.id = di.delivery_id
                            WHERE d.sales_order_id = soi.sales_order_id
                              AND di.product_id = soi.product_id
                              AND d.status <> 'cancelled'
                       ), 0) AS qty_dispatched,
                       COALESCE((
                           SELECT SUM(COALESCE(di.delivered_quantity, di.quantity))
                             FROM delivery_items di
                             JOIN deliveries d ON d.id = di.delivery_id
                            WHERE d.sales_order_id = soi.sales_order_id
                              AND di.product_id = soi.product_id
                              AND d.status = 'completed'
                       ), 0) AS qty_accepted
                  FROM sales_order_items soi
                  JOIN products p ON p.id = soi.product_id
                 WHERE soi.sales_order_id = ?
                 ORDER BY p.name ASC
            ");
            $stmtLines->execute([$so_id]);
            $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

            $tot_ordered = 0; $tot_dispatched = 0; $tot_accepted = 0; $tot_line_disc = 0.0;
            foreach ($lines as &$l) {
                $l['qty_ordered']    = (int) $l['qty_ordered'];
                $l['qty_dispatched'] = (int) $l['qty_dispatched'];
                $l['qty_accepted']   = (int) $l['qty_accepted'];
                $l['qty_remaining']  = max(0, $l['qty_ordered'] - $l['qty_dispatched']);
                $l['line_total']     = round($l['qty_ordered'] * (float) $l['list_price'], 2);
                // Present so the detail page can label the line honestly:
                // a line where unit < list is a DECLARED remise (the operator
                // was asked and said no, this is not a new price). It is never
                // computed from the prices — line_discount is what was stored.
                $l['is_discounted']  = ((float) $l['line_discount']) > 0;
                $tot_ordered    += $l['qty_ordered'];
                $tot_dispatched += $l['qty_dispatched'];
                $tot_accepted   += $l['qty_accepted'];
                $tot_line_disc  += (float) $l['line_discount'];
            }
            unset($l);

            // Every BL raised against this order.
            $stmtDel = $db->prepare("
                SELECT d.id, d.reference, d.date, d.status, d.token, d.delivery_mode,
                       d.payment_collected, d.signed_at, d.signatory_name,
                       CONCAT(u.first_name, ' ', u.last_name) AS driver_name,
                       s.name AS supplier_name,
                       (SELECT i.reference FROM invoice_deliveries idl
                          JOIN invoices i ON i.id = idl.invoice_id
                         WHERE idl.delivery_id = d.id LIMIT 1) AS invoice_reference,
                       (SELECT idl.invoice_id FROM invoice_deliveries idl
                         WHERE idl.delivery_id = d.id LIMIT 1) AS invoice_id
                  FROM deliveries d
                  LEFT JOIN users u     ON u.id = d.driver_id
                  LEFT JOIN suppliers s ON s.id = d.delivery_supplier_id
                 WHERE d.sales_order_id = ?
                 ORDER BY d.date ASC, d.id ASC
            ");
            $stmtDel->execute([$so_id]);
            $deliveries = $stmtDel->fetchAll(PDO::FETCH_ASSOC);

            // Invoices, and what has actually been paid against them. Payments
            // are filtered to 'validated': cash a driver reported but the
            // accountant has not counted is not money received, and showing it
            // as settled is how an order looks paid when it is not.
            $stmtInv = $db->prepare("
                SELECT DISTINCT i.id, i.reference, i.date, i.due_date, i.total_amount, i.status,
                       COALESCE((SELECT SUM(pay.amount) FROM payments pay
                                  WHERE pay.invoice_id = i.id AND pay.status = 'validated'), 0) AS paid_amount
                  FROM invoices i
                  JOIN invoice_deliveries idl ON idl.invoice_id = i.id
                  JOIN deliveries d ON d.id = idl.delivery_id
                 WHERE d.sales_order_id = ?
                 ORDER BY i.date ASC
            ");
            $stmtInv->execute([$so_id]);
            $invoices = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

            // Price changes this order caused. The counterpart to the discount
            // block: these are the lines where the operator said "new price".
            $stmtPrice = $db->prepare("
                SELECT h.product_id, p.name AS product_name, h.old_price, h.new_price,
                       h.changed_at, CONCAT(u.first_name, ' ', u.last_name) AS changed_by_name
                  FROM client_price_history h
                  JOIN products p ON p.id = h.product_id
                  LEFT JOIN users u ON u.id = h.changed_by
                 WHERE h.source_reference = ?
                 ORDER BY h.changed_at ASC
            ");
            $stmtPrice->execute([$order['reference']]);
            $price_changes = $stmtPrice->fetchAll(PDO::FETCH_ASSOC);

            $fulfilment = $order['status'] === 'cancelled' ? 'cancelled'
                        : ($tot_dispatched <= 0 ? 'pending'
                        : ($tot_dispatched >= $tot_ordered ? 'complete' : 'partial'));

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'order'         => $order,
                    'lines'         => $lines,
                    'deliveries'    => $deliveries,
                    'invoices'      => $invoices,
                    'price_changes' => $price_changes,
                    'totals' => [
                        'qty_ordered'    => $tot_ordered,
                        'qty_dispatched' => $tot_dispatched,
                        'qty_accepted'   => $tot_accepted,
                        'qty_remaining'  => max(0, $tot_ordered - $tot_dispatched),
                        'line_discount'  => round($tot_line_disc, 2),
                        // The order-level exceptional remise, i.e. everything
                        // given away that was not attributable to a line.
                        'order_discount' => round((float) $order['discount_amount'] - $tot_line_disc, 2),
                    ],
                    'fulfilment_state' => $fulfilment,
                ],
            ]);
            break;

        // =====================================================================
        // ACTION: DELIVERY / BL DETAIL  (modules/sales/bl_detail.php)
        // =====================================================================
        case 'get_delivery_detail':
            $del_id = (int) ($_GET['id'] ?? 0);
            if ($del_id <= 0) throw new UserFacingException("BL non spécifié.");

            $stmt = $db->prepare("
                SELECT d.*, c.name AS client_name, c.phone AS client_phone,
                       so.reference AS so_reference,
                       CONCAT(u.first_name, ' ', u.last_name) AS driver_name,
                       v.plate_number, s.name AS supplier_name,
                       CONCAT(cb.first_name, ' ', cb.last_name) AS created_by_name,
                       (SELECT i.reference FROM invoice_deliveries idl
                          JOIN invoices i ON i.id = idl.invoice_id
                         WHERE idl.delivery_id = d.id LIMIT 1) AS invoice_reference,
                       (SELECT idl.invoice_id FROM invoice_deliveries idl
                         WHERE idl.delivery_id = d.id LIMIT 1) AS invoice_id
                  FROM deliveries d
                  JOIN clients c ON c.id = d.client_id
                  LEFT JOIN sales_orders so ON so.id = d.sales_order_id
                  LEFT JOIN users u     ON u.id = d.driver_id
                  LEFT JOIN vehicles v  ON v.id = d.vehicle_id
                  LEFT JOIN suppliers s ON s.id = d.delivery_supplier_id
                  LEFT JOIN users cb    ON cb.id = d.created_by
                 WHERE d.id = ?
            ");
            $stmt->execute([$del_id]);
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$delivery) throw new UserFacingException("BL introuvable.");

            // Signature images are large base64 blobs and this endpoint feeds a
            // summary page — send whether they exist, not the payload itself.
            foreach (['signature_image', 'driver_signature_image'] as $blob) {
                if (array_key_exists($blob, $delivery)) {
                    $delivery[$blob . '_present'] = !empty($delivery[$blob]);
                    unset($delivery[$blob]);
                }
            }

            $stmtItems = $db->prepare("
                SELECT di.id, di.product_id, p.name AS product_name, p.format,
                       di.quantity AS qty_shipped,
                       di.delivered_quantity AS qty_accepted,
                       di.returned_empty_qty,
                       di.unit_price,
                       pe.name AS empty_name
                  FROM delivery_items di
                  JOIN products p ON p.id = di.product_id
                  LEFT JOIN products pe ON pe.id = p.linked_empty_id
                 WHERE di.delivery_id = ?
                 ORDER BY p.name ASC
            ");
            $stmtItems->execute([$del_id]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $short = 0;
            foreach ($items as &$it) {
                $it['qty_shipped']  = (int) $it['qty_shipped'];
                // NULL means "not yet closed out", which is not the same as
                // "zero accepted" — coalescing to 0 here would render every
                // in-transit BL as a total rejection.
                $it['qty_accepted'] = $it['qty_accepted'] === null ? null : (int) $it['qty_accepted'];
                $it['qty_short']    = $it['qty_accepted'] === null
                                    ? null : max(0, $it['qty_shipped'] - $it['qty_accepted']);
                if ($it['qty_short']) $short += $it['qty_short'];
            }
            unset($it);

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'delivery'   => $delivery,
                    'items'      => $items,
                    'short_total'=> $short,
                ],
            ]);
            break;

        // =====================================================================
        // ACTION: KPI DRILL-DOWN
        // ---------------------------------------------------------------------
        // One query per KPI rather than filtering a page of already-fetched
        // rows in the browser. Achats does the latter and caps the modal at
        // whatever 200-row page it happens to hold, which silently truncates
        // the answer; asking the database means the modal always agrees with
        // the number on the card that opened it.
        // =====================================================================
        case 'kpi_detail':
            $kpi   = (string) ($_GET['kpi'] ?? '');
            $kstart = $_GET['start'] ?? '2000-01-01';
            $kend   = $_GET['end']   ?? '2099-12-31';
            $rows  = [];
            $title = 'Détails';

            switch ($kpi) {
                case 'kpi_so_total':
                case 'kpi_so_count':
                    $title = 'Toutes les commandes (période)';
                    $q = $db->prepare("
                        SELECT so.date, so.reference, c.name AS label, so.total_amount AS amount,
                               so.id, so.status
                          FROM sales_orders so JOIN clients c ON c.id = so.client_id
                         WHERE so.date BETWEEN ? AND ? AND so.status <> 'cancelled'
                         ORDER BY so.date DESC, so.id DESC LIMIT 500");
                    $q->execute([$kstart, $kend]);
                    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'kpi_so_pending':
                    $title = 'Commandes à livrer';
                    $q = $db->prepare("
                        SELECT so.date, so.reference, c.name AS label, so.total_amount AS amount,
                               so.id, so.status
                          FROM sales_orders so JOIN clients c ON c.id = so.client_id
                         WHERE so.date BETWEEN ? AND ? AND so.status = 'pending'
                         ORDER BY so.date ASC, so.id ASC LIMIT 500");
                    $q->execute([$kstart, $kend]);
                    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'kpi_so_debt':
                    // Ordered by age, not by date: this list exists to be
                    // chased, and the oldest debt is the one that matters.
                    $title = 'Créances clients (non soldées)';
                    $q = $db->prepare("
                        SELECT so.date, so.reference, c.name AS label, so.total_amount AS amount,
                               so.id, so.status, DATEDIFF(CURRENT_DATE(), so.date) AS age_days
                          FROM sales_orders so JOIN clients c ON c.id = so.client_id
                         WHERE so.date BETWEEN ? AND ? AND so.status <> 'cancelled'
                           AND so.payment_status <> 'paid'
                         ORDER BY so.date ASC LIMIT 500");
                    $q->execute([$kstart, $kend]);
                    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'kpi_so_discount':
                    // Only DECLARED remises appear here. A repriced line is not
                    // a discount and is deliberately absent — it belongs to
                    // client_price_history, which the order detail page shows
                    // in its own section.
                    $title = 'Remises accordées';
                    $q = $db->prepare("
                        SELECT so.date, so.reference, c.name AS label,
                               so.discount_amount AS amount, so.id, so.status,
                               so.discount_note, so.subtotal,
                               ROUND(so.discount_amount / NULLIF(so.subtotal,0) * 100, 1) AS pct
                          FROM sales_orders so JOIN clients c ON c.id = so.client_id
                         WHERE so.date BETWEEN ? AND ? AND so.status <> 'cancelled'
                           AND so.discount_amount > 0
                         ORDER BY so.discount_amount DESC LIMIT 500");
                    $q->execute([$kstart, $kend]);
                    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'kpi_dl_dispatched':
                    $title = 'BL en transit';
                    $q = $db->query("
                        SELECT d.date, d.reference, c.name AS label, d.payment_collected AS amount,
                               d.id, d.status, d.delivery_mode
                          FROM deliveries d JOIN clients c ON c.id = d.client_id
                         WHERE d.status = 'dispatched'
                         ORDER BY d.date ASC LIMIT 500");
                    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'kpi_dl_completed':
                    $title = 'Livraisons terminées (période)';
                    $q = $db->prepare("
                        SELECT d.date, d.reference, c.name AS label, d.payment_collected AS amount,
                               d.id, d.status, d.delivery_mode
                          FROM deliveries d JOIN clients c ON c.id = d.client_id
                         WHERE d.status = 'completed' AND d.date BETWEEN ? AND ?
                         ORDER BY d.date DESC LIMIT 500");
                    $q->execute([$kstart, $kend]);
                    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'kpi_dl_cash':
                    $title = 'Cash collecté (période)';
                    $q = $db->prepare("
                        SELECT d.date, d.reference, c.name AS label, d.payment_collected AS amount,
                               d.id, d.status, d.delivery_mode
                          FROM deliveries d JOIN clients c ON c.id = d.client_id
                         WHERE d.payment_collected > 0 AND d.date BETWEEN ? AND ?
                         ORDER BY d.payment_collected DESC LIMIT 500");
                    $q->execute([$kstart, $kend]);
                    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'kpi_dl_returns':
                    $title = 'Rejets / avaries (lignes)';
                    $q = $db->prepare("
                        SELECT d.date, d.reference, CONCAT(c.name, ' — ', p.name) AS label,
                               (di.quantity - di.delivered_quantity) AS amount,
                               d.id, d.status, di.quantity AS qty_shipped,
                               di.delivered_quantity AS qty_accepted
                          FROM delivery_items di
                          JOIN deliveries d ON d.id = di.delivery_id
                          JOIN clients c    ON c.id = d.client_id
                          JOIN products p   ON p.id = di.product_id
                         WHERE di.delivered_quantity IS NOT NULL
                           AND di.delivered_quantity < di.quantity
                           AND d.date BETWEEN ? AND ?
                         ORDER BY (di.quantity - di.delivered_quantity) DESC LIMIT 500");
                    $q->execute([$kstart, $kend]);
                    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                    break;

                default:
                    throw new UserFacingException("Indicateur inconnu.");
            }

            echo json_encode(['status' => 'success', 'data' => ['title' => $title, 'rows' => $rows, 'kpi' => $kpi]]);
            break;

        // =====================================================================
        // ACTION: CANCEL ORDER
        // ---------------------------------------------------------------------
        // Achats has had this since it was built; Ventes never did. The only
        // removal path here was delete_order, a hard DELETE restricted to
        // pre-BL orders — so a dispatched order that fell through could not be
        // closed out at all, and a deleted one left no trace of having existed.
        //
        // Cancelling keeps the row. It is greyed in the list, excluded from
        // every KPI, and carries who/when/why. Stock and accounting are
        // deliberately NOT reversed here: undoing a dispatch means putting
        // goods back into inventory and reversing a posted COGS journal entry,
        // which is a stock operation with its own permission and its own audit
        // trail. Cancelling an order that has already shipped is refused for
        // exactly that reason rather than half-done silently.
        // =====================================================================
        case 'cancel_order':
            $so_id  = (int) ($jsonData['id'] ?? $_POST['id'] ?? 0);
            $reason = trim((string) ($jsonData['reason'] ?? $_POST['reason'] ?? ''));
            if ($so_id <= 0)     throw new UserFacingException("Commande non spécifiée.");
            if ($reason === '')  throw new UserFacingException("Motif de l'annulation obligatoire.");

            $db->beginTransaction();

            $stmtChk = $db->prepare("SELECT status, reference FROM sales_orders WHERE id = ? FOR UPDATE");
            $stmtChk->execute([$so_id]);
            $cur = $stmtChk->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new UserFacingException("Commande introuvable.");
            if ($cur['status'] === 'cancelled') throw new UserFacingException("Cette commande est déjà annulée.");

            $stmtLive = $db->prepare("
                SELECT COUNT(*) FROM deliveries
                 WHERE sales_order_id = ? AND status <> 'cancelled'
            ");
            $stmtLive->execute([$so_id]);
            if ((int) $stmtLive->fetchColumn() > 0) {
                throw new UserFacingException(
                    "Cette commande a déjà un BL actif. Annulez d'abord la livraison "
                    . "(le stock et l'écriture comptable doivent être repris)."
                );
            }

            $db->prepare("
                UPDATE sales_orders
                   SET status = 'cancelled', cancelled_at = NOW(),
                       cancelled_by = ?, cancellation_reason = ?
                 WHERE id = ?
            ")->execute([$user_id, $reason, $so_id]);

            $db->commit();
            echo json_encode(['status' => 'success', 'message' => 'Commande annulée.']);
            break;

        // =====================================================================
        // ACTION: UPDATE ORDER
        // ---------------------------------------------------------------------
        // `sales.orders.edit` has existed in includes/config/permissions.php
        // since the RBAC seed and is granted to operations and sales, but no
        // endpoint ever implemented it — the permission was dead and an order
        // was immutable from the moment it was saved. A typo in a quantity
        // meant deleting the order and re-keying it.
        //
        // Restricted to 'pending': once a BL exists the order has been acted
        // on physically, and editing the quantities underneath a delivery that
        // already moved stock would put the two permanently out of step.
        //
        // Re-runs the full pricing path, so an edit is subject to the same
        // price-vs-discount declaration as the original.
        // =====================================================================
        case 'update_order':
            $so_id           = (int) ($jsonData['id'] ?? 0);
            $order_discount  = round((float) ($jsonData['discount_amount'] ?? 0), 2);
            $discount_note   = trim((string) ($jsonData['discount_note'] ?? ''));
            $items           = $jsonData['items'] ?? [];
            $price_decisions = is_array($jsonData['price_decisions'] ?? null)
                             ? $jsonData['price_decisions'] : [];

            if ($so_id <= 0 || empty($items)) throw new UserFacingException("Données incomplètes.");

            $stmtChk = $db->prepare("SELECT status, client_id, reference, date FROM sales_orders WHERE id = ?");
            $stmtChk->execute([$so_id]);
            $existing = $stmtChk->fetch(PDO::FETCH_ASSOC);
            if (!$existing) throw new UserFacingException("Commande introuvable.");
            if ($existing['status'] !== 'pending') {
                throw new UserFacingException("Modification impossible : un BL a déjà été généré pour cette commande.");
            }

            $client_id = (int) $existing['client_id'];
            $reference = $existing['reference'];
            $date      = $jsonData['date'] ?? $existing['date'];

            $clean = [];
            foreach ($items as $item) {
                $pid = (int) ($item['product_id'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 0);
                $up  = round((float) ($item['unit_price'] ?? 0), 2);
                if ($pid <= 0) throw new UserFacingException("Ligne sans produit.");
                if ($qty <= 0) throw new UserFacingException("Quantité invalide sur une ligne.");
                if ($up  <  0) throw new UserFacingException("Prix négatif sur une ligne.");
                $clean[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $up];
            }

            $pids      = array_column($clean, 'product_id');
            $catalogue = lpc_catalogue_prices($db, $pids);
            $tariff    = lpc_client_tariff($db, $client_id, $pids);
            foreach ($pids as $pid) {
                if (!array_key_exists($pid, $catalogue)) throw new UserFacingException("Produit #$pid introuvable.");
            }

            $disparities = lpc_price_disparities($clean, $tariff, $catalogue);
            $undecided = [];
            foreach ($disparities as $pid => $d) {
                if (!array_key_exists((string) $pid, $price_decisions)
                    && !array_key_exists($pid, $price_decisions)) $undecided[] = $pid;
            }
            if (!empty($undecided)) {
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

            $db->beginTransaction();

            $subtotal = 0.0; $line_discount_total = 0.0; $priced = []; $repriced = [];
            foreach ($clean as $l) {
                $pid = $l['product_id']; $qty = $l['quantity']; $typed = $l['unit_price'];
                $current = round(lpc_effective_price($tariff, $pid, $catalogue[$pid]), 2);
                $changed = abs($typed - $current) >= 0.005;
                $confirm = $changed
                    && (($price_decisions[$pid] ?? $price_decisions[(string) $pid] ?? false) ? true : false);

                if (!$changed)      { $list = $current; $unit = $current; $lineDisc = 0.0; }
                elseif ($confirm)   { $list = $typed;   $unit = $typed;   $lineDisc = 0.0; $repriced[$pid] = $typed; }
                else {
                    if ($typed > $current) {
                        throw new UserFacingException(
                            "Prix supérieur au tarif sur une ligne sans confirmation. "
                            . "Confirmez le nouveau prix, ou ramenez la ligne au tarif."
                        );
                    }
                    $list = $current; $unit = $typed; $lineDisc = round(($current - $typed) * $qty, 2);
                }

                $subtotal            += $list * $qty;
                $line_discount_total += $lineDisc;
                $priced[] = ['product_id' => $pid, 'quantity' => $qty,
                             'unit_price' => $unit, 'list_price' => $list, 'discount' => $lineDisc];
            }

            $subtotal       = round($subtotal, 2);
            $total_discount = round($line_discount_total + $order_discount, 2);

            if ($order_discount < 0) throw new UserFacingException("Remise négative interdite.");
            if ($order_discount > 0 && $discount_note === '') throw new UserFacingException("Motif de la remise obligatoire.");
            if ($total_discount > $subtotal) throw new UserFacingException("Remise totale supérieure au sous-total.");

            $max_pct = Prefs::float('sales_discount_max_pct', 15);
            if ($max_pct > 0 && $subtotal > 0) {
                $pct = ($total_discount / $subtotal) * 100;
                if ($pct > $max_pct && !Rbac::hasPermission('sales.orders.discount_approve')) {
                    throw new UserFacingException(sprintf(
                        "Remise de %.1f%% au-delà du plafond de %.0f%%. Approbation requise.", $pct, $max_pct));
                }
            }

            $total_amount = round($subtotal - $total_discount, 2);

            $db->prepare("
                UPDATE sales_orders
                   SET date = ?, subtotal = ?, discount_amount = ?, discount_note = ?, total_amount = ?
                 WHERE id = ?
            ")->execute([
                $date, $subtotal, $total_discount,
                $discount_note !== '' ? $discount_note : null,
                $total_amount, $so_id,
            ]);

            // Replace the lines wholesale. sales_order_items has no dependants
            // while the order is still 'pending' (delivery_items are only
            // created at dispatch), so there is nothing to orphan.
            $db->prepare("DELETE FROM sales_order_items WHERE sales_order_id = ?")->execute([$so_id]);
            $stmtLine = $db->prepare("
                INSERT INTO sales_order_items
                    (sales_order_id, product_id, quantity, unit_price, list_price, discount_amount)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($priced as $p) {
                $stmtLine->execute([$so_id, $p['product_id'], $p['quantity'],
                                    $p['unit_price'], $p['list_price'], $p['discount']]);
            }

            foreach ($repriced as $pid => $newPrice) {
                lpc_apply_client_price_change(
                    $db, $client_id, (int) $pid, (float) $newPrice, $user_id,
                    'order', $reference, 'Confirmé lors de la modification de la commande'
                );
            }

            $db->commit();
            echo json_encode(['status' => 'success', 'message' => 'Commande modifiée.', 'id' => $so_id]);
            break;

        default:
            throw new UserFacingException("Action inconnue.");
    }

// -----------------------------------------------------------------------------
// Sprint 10 · split handler, copied from procurement_controller.php.
//
// This module had a single `catch (Exception $e)` that replaced EVERY message
// with "Erreur serveur. Veuillez réessayer." — including the ones written
// specifically for the operator. Someone dispatching without a driver was told
// the server had failed, so they retried, and it failed again, and the module
// looked broken while it was in fact working correctly and refusing an invalid
// request. Every business rule added below (motif obligatoire, remise au-delà
// du plafond, prix supérieur au tarif, BL déjà actif) would have been invisible
// in exactly the same way.
// -----------------------------------------------------------------------------
} catch (UserFacingException $e) {
    // A business rule said no. The message is the point of the refusal.
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code($e->getHttpStatus());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);

} catch (Throwable $e) {
    // Anything else is unexpected: log it in full, tell the client nothing.
    // Throwable rather than Exception so a TypeError is also rolled back
    // rather than escaping with the transaction still open.
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}