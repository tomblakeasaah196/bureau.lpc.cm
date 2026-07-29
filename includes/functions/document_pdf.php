<?php
/**
 * includes/functions/document_pdf.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 4 (parallel) · shared PDF dispatcher.
 *
 * HTML-first contract
 * -------------------
 * Every document page owns a rich, branded, client-facing HTML view. That view
 * is the deliverable: customers open it from a token link, sign it, share it by
 * WhatsApp/email, and export it themselves with html2canvas + jsPDF. So the
 * HTML always renders by default and this dispatcher is strictly opt-in.
 *
 * Called from each document page like so:
 *
 *   require_once __DIR__ . '/../../includes/bootstrap.php';
 *   require_once __DIR__ . '/../../includes/functions/document_pdf.php';
 *   if (($_GET['pdf'] ?? '') === '1') {
 *       lpc_serve_document_pdf('invoice');   // exits after streaming
 *   }
 *   // ...page's own HTML follows and renders in every other case.
 *
 * When it is called, the dispatcher loads the source record, builds a condensed
 * one-page HTML from the inline template below, hands it to PdfRenderer,
 * streams the PDF and exits. Absent a token it returns and the page renders.
 *
 * payslip.php is the one exception: it has no designed HTML page (only a plain
 * amounts table for verification), so it still PDFs by default via ?html=1.
 * The html=1 early-return below exists for it.
 *
 * Cache: PdfRenderer::saveDocument SHA-256's the source HTML — if nothing
 * has changed we serve the cached file straight from /uploads/documents/
 * and emit `X-LPC-Cache: HIT` for verification scripts.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../classes/PdfRenderer.php';
// The letterhead the facture and the devis share. Before it existed each
// template carried its own, and they disagreed — the invoice printed a
// placeholder NIU and RC to real customers while the quote showed neither.
require_once __DIR__ . '/../pdf_templates/document_header.php';
// Every string on the devis is editable in the Proposal Studio. Required here
// so the dompdf quote renders the same wording as the HTML page rather than a
// second, drifting copy — which is exactly what this file used to be.
require_once __DIR__ . '/proposal_template.php';

/**
 * Public entry point. Types map 1:1 to the pdf_documents.type enum.
 * The calling public/documents/*.php file should call this immediately after
 * its bootstrap require — if the function returns, HTML rendering can proceed.
 */
function lpc_serve_document_pdf(string $type): void
{
    // Explicit HTML fallback. Only payslip.php still reaches the dispatcher
    // without an explicit ?pdf=1; every other page gates the call itself.
    if (!empty($_GET['html']) && $_GET['html'] === '1') {
        return;
    }

    $token = (string) ($_GET['token'] ?? '');
    if ($token === '') return;   // no token → let the HTML page render its own 404

    try {
        $db = Database::getInstance()->getConnection();
    } catch (Throwable $e) {
        error_log('document_pdf: db unavailable: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Erreur serveur.";
        exit;
    }

    $doc = lpc_load_document($db, $type, $token);
    if (!$doc) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Document introuvable.";
        exit;
    }

    $html = lpc_render_document_html($type, $doc);
    try {
        $rel_path = PdfRenderer::saveDocument($type, (int)$doc['record_id'], $token, $html, [
            'paper'       => 'A4',
            'orientation' => 'portrait',
            'footer_html' => '__page_counter__',
        ]);
    } catch (Throwable $e) {
        error_log('document_pdf render: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Erreur PDF : " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        exit;
    }

    $filename = strtoupper($type) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $doc['reference'] ?? $token) . '.pdf';
    PdfRenderer::streamFile($rel_path, $filename, true);
    exit;
}

/**
 * Load a source record + its dependent rows and return a normalized array
 * usable by the templates. Returns null on not-found / expired token.
 *
 * The shape is deliberately generic:
 *   [
 *     'record_id' => int,
 *     'reference' => string,
 *     'date'      => 'Y-m-d',
 *     'client'    => ['name','address','niu','phone'],
 *     'items'     => [ ['name','qty','unit','unit_price','total'], ... ],
 *     'totals'    => ['subtotal','discount','tax','grand_total','words'],
 *     'notes'     => string,
 *     'source_updated_at' => 'Y-m-d H:i:s'
 *   ]
 */
function lpc_load_document(PDO $db, string $type, string $token): ?array
{
    switch ($type) {
        case 'invoice':
            // Sprint 9: pulls the client's fiscal identity, the issuer (for the
            // certification stamp) and the validated payments, so the PDF can
            // carry the same information as the HTML view instead of a subset.
            $stmt = $db->prepare("
                SELECT i.*,
                       c.name AS client_name, c.address AS client_address, c.niu AS client_niu,
                       c.rc AS client_rc, c.phone AS client_phone, c.email AS client_email,
                       c.type AS client_type,
                       CONCAT(u.first_name, ' ', LEFT(u.last_name, 1), '.') AS creator_name,
                       r.name AS role_name,
                       (SELECT COALESCE(SUM(p.amount), 0) FROM payments p
                         WHERE p.invoice_id = i.id AND p.status = 'validated') AS paid_amount
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  LEFT JOIN users u ON u.id = i.created_by
                  LEFT JOIN roles r ON r.id = u.role_id
                 WHERE i.token = ? LIMIT 1
            ");
            $stmt->execute([$token]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $items = $db->prepare("
                SELECT ii.*, p.name AS product_name, p.format
                  FROM invoice_items ii LEFT JOIN products p ON p.id = ii.product_id
                 WHERE ii.invoice_id = ? ORDER BY ii.id ASC
            ");
            $items->execute([$r['id']]);
            $lines = $items->fetchAll(PDO::FETCH_ASSOC);
            return lpc_normalize_invoice($r, $lines);

        case 'delivery':
            $stmt = $db->prepare("
                SELECT d.*, c.name AS client_name, c.address AS client_address, c.niu AS client_niu, c.phone AS client_phone
                  FROM deliveries d JOIN clients c ON c.id = d.client_id
                 WHERE d.token = ? LIMIT 1
            ");
            $stmt->execute([$token]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $items = $db->prepare("
                SELECT di.*, p.name AS product_name, p.format
                  FROM delivery_items di LEFT JOIN products p ON p.id = di.product_id
                 WHERE di.delivery_id = ? ORDER BY di.id ASC
            ");
            $items->execute([$r['id']]);
            $lines = $items->fetchAll(PDO::FETCH_ASSOC);
            return lpc_normalize_delivery($r, $lines);

        case 'po':
            $stmt = $db->prepare("
                SELECT po.*, s.name AS supplier_name, s.address AS supplier_address
                  FROM purchase_orders po LEFT JOIN suppliers s ON s.id = po.supplier_id
                 WHERE po.token = ? LIMIT 1
            ");
            $stmt->execute([$token]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $items = $db->prepare("
                SELECT poi.*, p.name AS product_name
                  FROM purchase_order_items poi LEFT JOIN products p ON p.id = poi.product_id
                 WHERE poi.purchase_order_id = ? ORDER BY poi.id ASC
            ");
            $items->execute([$r['id']]);
            return lpc_normalize_po($r, $items->fetchAll(PDO::FETCH_ASSOC));

        case 'quote':
            $stmt = $db->prepare("
                SELECT p.*
                  FROM proposals p
                 WHERE p.token = ? LIMIT 1
            ");
            $stmt->execute([$token]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            // proposals don't always have line items in the same shape;
            // best-effort load, missing table is fine.
            $lines = [];
            try {
                $items = $db->prepare("SELECT *, product_description AS product_name, product_format AS format FROM proposal_items WHERE proposal_id = ? ORDER BY id ASC");
                $items->execute([$r['id']]);
                $lines = $items->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { /* proposal_items may not exist */ }

            // The consigne schedule. Articles 1 and 2 of the terms threaten
            // "des frais de remplacement au tarif en vigueur" without ever
            // publishing one — the most likely source of a later dispute.
            // A product is consigned when it has a linked empty, which is how
            // the empties ledger already identifies them.
            $r['consigne'] = [];
            try {
                $r['consigne'] = $db->query("
                    SELECT name, format, deposit_value, replacement_value
                      FROM products
                     WHERE linked_empty_id IS NOT NULL
                       AND is_active = 1
                       AND (deposit_value > 0 OR replacement_value > 0)
                     ORDER BY name ASC
                ")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { /* migration 045 not yet run */ }

            // Evidence appended to the offer — ANOR certificate, RCCM extract.
            $r['annexes'] = [];
            try {
                $r['annexes'] = $db->query("
                    SELECT label_fr, file_path, kind FROM company_annexes
                     WHERE is_active = 1 AND show_on_quote = 1
                     ORDER BY sort_order ASC, id ASC
                ")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { /* migration 045 not yet run */ }

            return lpc_normalize_quote($r, $lines);

        case 'cre':
            $stmt = $db->prepare("SELECT * FROM cre_documents WHERE token = ? LIMIT 1");
            $stmt->execute([$token]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            return lpc_normalize_cre($r);

        case 'audit':
            $stmt = $db->prepare("
                SELECT ir.*, CONCAT(u.first_name,' ',u.last_name) AS operator_name
                  FROM inventory_reports ir LEFT JOIN users u ON u.id = ir.operator_id
                 WHERE ir.token = ? LIMIT 1
            ");
            $stmt->execute([$token]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $items = $db->prepare("
                SELECT iri.*, p.name AS product_name
                  FROM inventory_report_items iri LEFT JOIN products p ON p.id = iri.product_id
                 WHERE iri.report_id = ? ORDER BY iri.id ASC
            ");
            $items->execute([$r['id']]);
            return lpc_normalize_audit($r, $items->fetchAll(PDO::FETCH_ASSOC));

        case 'payslip':
            $stmt = $db->prepare("
                SELECT p.*, CONCAT(u.first_name,' ',u.last_name) AS employee_name,
                       c.cnps_number, c.marital_status, c.dependents_count
                  FROM hr_payslips p
                  JOIN users u ON u.id = p.user_id
                  LEFT JOIN hr_contracts c ON c.user_id = u.id
                 WHERE p.token = ? LIMIT 1
            ");
            $stmt->execute([$token]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            if (!empty($r['token_expires_at']) && strtotime($r['token_expires_at']) < time()) return null;
            return lpc_normalize_payslip($r);
    }
    return null;
}

// ---------------------------------------------------------------------------
// Normalizers — turn each raw row into the shape lpc_render_document_html uses.
// ---------------------------------------------------------------------------

/**
 * Sprint 9 — this normalizer used to read columns that do not exist on the
 * invoices table:
 *
 *     $disc = (float)($r['discount_amount'] ?? 0);   // no such column
 *     $tax  = (float)($r['tax_amount']      ?? 0);   // no such column — it is tva_amount
 *     $grand = $sub - $disc + $tax;                  // therefore always == $sub
 *
 * So every server-rendered invoice PDF printed TVA 0 and a grand total equal to
 * the HT, silently understating any 19,25 % invoice by the whole tax. It went
 * unnoticed because the button in the UI pointed at the html2canvas path, so
 * almost nothing exercised this branch.
 *
 * It also labelled lines from products.name while the client-facing HTML uses
 * invoice_items.description ("20L Opur", "Livraison du 12 Fév"). The two
 * documents therefore disagreed line by line. description wins, with the
 * product name as the fallback.
 *
 * Totals are now read from the stored columns rather than recomputed. A reprint
 * must reproduce the invoice as issued, even if a product price has moved since.
 */
function lpc_normalize_invoice(array $r, array $lines): array {
    $items = [];
    foreach ($lines as $l) {
        $q = (float)$l['quantity'];
        $u = (float)$l['unit_price'];
        $items[] = [
            // total_price is a STORED generated column — prefer it over q*u so
            // the PDF cannot drift from what the database holds.
            'name'       => $l['description'] ?: ($l['product_name'] ?? ''),
            'format'     => $l['format'] ?? '',
            'qty'        => $q,
            'unit_price' => $u,
            'total'      => isset($l['total_price']) ? (float)$l['total_price'] : $q * $u,
        ];
    }

    $sub       = (float)($r['subtotal'] ?? array_sum(array_column($items, 'total')));
    $excise    = (float)($r['excise_amount'] ?? 0);
    $tva       = (float)($r['tva_amount'] ?? 0);
    $precompte = (float)($r['precompte_amount'] ?? 0);
    $air       = (float)($r['air_amount'] ?? 0);
    $grand     = (float)($r['total_amount'] ?? ($sub + $excise + $tva));

    $net_payable = (float)($r['net_payable'] ?? 0);
    if ($net_payable <= 0) {
        $net_payable = max(0.0, $grand - $air - $precompte);
    }

    $tva_rate  = (float)($r['tva_rate'] ?? 0);
    $exemption = trim((string)($r['tva_exemption_reason'] ?? ''));
    if ($tva_rate <= 0 && $exemption === '') {
        $exemption = 'Exonéré de TVA — régime applicable au produit facturé (art. 128 CGI).';
    }

    return [
        'record_id' => (int)$r['id'],
        'reference' => $r['reference'],
        'date'      => $r['date'] ?? $r['created_at'],
        'due_date'  => $r['due_date'] ?? null,
        'client' => [
            'name'    => $r['client_name']    ?? '',
            'address' => $r['client_address'] ?? '',
            'niu'     => $r['client_niu']     ?? '',
            'rccm'    => $r['client_rc']      ?? '',
            'phone'   => $r['client_phone']   ?? '',
            'email'   => $r['client_email']   ?? '',
            'is_b2b'  => strtoupper((string)($r['client_type'] ?? '')) === 'B2B',
        ],
        'items' => $items,
        'totals' => [
            'subtotal'         => $sub,
            'discount'         => 0.0,
            'excise_rate'      => (float)($r['excise_rate'] ?? 0) * 100,
            'excise'           => $excise,
            'tva_rate'         => $tva_rate,
            'tax'              => $tva,
            'tva_exemption'    => $exemption,
            'precompte_rate'   => (float)($r['precompte_rate'] ?? 0) * 100,
            'precompte'        => $precompte,
            'air_rate'         => (float)($r['air_rate'] ?? 0) * 100,
            'air'              => $air,
            'grand_total'      => $grand,
            'net_payable'      => $net_payable,
            'paid'             => (float)($r['paid_amount'] ?? 0),
            'balance'          => max(0.0, $grand - (float)($r['paid_amount'] ?? 0)),
            'words'            => lpc_amount_in_words($grand),
        ],
        'notes' => $r['notes'] ?? '',
        'source_updated_at' => $r['updated_at'] ?? $r['created_at'] ?? null,
        'status' => $r['status'] ?? 'unpaid',
        // Frozen at issue by lpc_attach_invoice_payment_accounts(); [] for
        // invoices raised before migration 044, which then fall back to the
        // single company_profile account in the template.
        'payment_blocks' => (function ($json) {
            if (empty($json)) return [];
            $d = json_decode((string) $json, true);
            return is_array($d) ? $d : [];
        })($r['payment_block_snapshot'] ?? null),
        'stamp' => [
            'created_by' => trim((string)($r['creator_name'] ?? '')),
            'role'       => (string)($r['role_name'] ?? 'Finance / Comptabilité'),
            'timestamp'  => !empty($r['created_at']) ? date('d/m/Y H:i', strtotime($r['created_at'])) : '',
            'hash'       => implode('-', str_split(
                strtoupper(substr(hash('sha256', (string)($r['token'] ?? '') . (string)($r['created_at'] ?? '')), 0, 16)), 4
            )),
        ],
    ];
}
function lpc_normalize_delivery(array $r, array $lines): array {
    $items = [];
    foreach ($lines as $l) {
        $items[] = ['name'=>$l['product_name'] ?? '', 'format'=>$l['format'] ?? '',
                    'qty'=>(float)$l['quantity'], 'delivered_qty'=>(float)($l['delivered_quantity'] ?? $l['quantity']),
                    'returned_empty_qty' => (float)($l['returned_empty_qty'] ?? 0),
                    'unit_price'=>(float)$l['unit_price'],
                    'total'=>((float)($l['delivered_quantity'] ?? $l['quantity'])) * (float)$l['unit_price']];
    }
    $sub = array_sum(array_column($items,'total'));
    return ['record_id'=>(int)$r['id'], 'reference'=>$r['reference'], 'date'=>$r['date'],
            'client'=>['name'=>$r['client_name'] ?? '', 'address'=>$r['client_address'] ?? '', 'niu'=>$r['client_niu'] ?? '', 'phone'=>$r['client_phone'] ?? ''],
            'items'=>$items, 'totals'=>['subtotal'=>$sub,'discount'=>0,'tax'=>0,'grand_total'=>$sub,'words'=>lpc_amount_in_words($sub)],
            'notes'=>$r['rejection_reason'] ?? '', 'source_updated_at'=>$r['updated_at'] ?? $r['signed_at'] ?? $r['date'],
            'signature_image'=>$r['signature_image'] ?? null, 'driver_signature_image'=>$r['driver_signature_image'] ?? null,
            'signatory_name'=>$r['signatory_name'] ?? '', 'signed_at'=>$r['signed_at'] ?? ''];
}
function lpc_normalize_po(array $r, array $lines): array {
    $items = [];
    foreach ($lines as $l) {
        $q = (float)$l['quantity']; $u = (float)($l['unit_cost'] ?? $l['unit_price'] ?? 0);
        $items[] = ['name'=>$l['product_name'] ?? '', 'qty'=>$q, 'unit_price'=>$u, 'total'=>$q*$u];
    }
    $sub = array_sum(array_column($items,'total'));
    return ['record_id'=>(int)$r['id'], 'reference'=>$r['reference'], 'date'=>$r['date'] ?? $r['created_at'],
            'client'=>['name'=>$r['supplier_name'] ?? '', 'address'=>$r['supplier_address'] ?? '', 'niu'=>'', 'phone'=>''],
            'items'=>$items, 'totals'=>['subtotal'=>$sub,'discount'=>0,'tax'=>0,'grand_total'=>$sub,'words'=>lpc_amount_in_words($sub)],
            'notes'=>$r['notes'] ?? '', 'source_updated_at'=>$r['updated_at'] ?? $r['created_at'] ?? null];
}
/**
 * Sprint 9 — the devis gained everything the facture already had.
 *
 * A quotation is not a fiscal document, so art. 150 CGI's mandatory mentions do
 * not bind it. But once a client signs "Bon pour accord" it is a contract under
 * OHADA, and contract formation needs identified parties, a precise object, a
 * price AND ITS TAX TREATMENT, and a validity. DEV-2607-841B had no tax
 * treatment at all — one bare figure — no client NIU, no amount in words, and a
 * validity expressed as "30 Jours à compter de l'émission", which asks the
 * reader to do arithmetic and offers nothing to hold you to.
 *
 * The totals keys mirror lpc_normalize_invoice() exactly, so the two templates
 * can render the same ladder and the offer can never quote a different tax
 * treatment from the invoice that follows it.
 */
function lpc_normalize_quote(array $r, array $lines): array {
    $items = [];
    foreach ($lines as $l) {
        $q = (float)($l['quantity'] ?? 0);
        $u = (float)($l['unit_price'] ?? 0);
        $items[] = [
            'name'       => $l['product_name'] ?? $l['name'] ?? '',
            'format'     => $l['format'] ?? '',
            'qty'        => $q,
            'unit_price' => $u,
            'total'      => isset($l['total_price']) ? (float)$l['total_price'] : $q * $u,
        ];
    }

    $line_sum = array_sum(array_column($items, 'total'));
    $sub      = (float)($r['subtotal'] ?? 0) ?: $line_sum;
    $excise   = (float)($r['excise_amount'] ?? 0);
    $tva      = (float)($r['tva_amount'] ?? 0);
    $grand    = (float)($r['total_amount'] ?? 0) ?: ($sub + $excise + $tva);

    $tva_rate  = (float)($r['tva_rate'] ?? 0);
    $exemption = trim((string)($r['tva_exemption_reason'] ?? ''));
    if ($tva_rate <= 0 && $exemption === '') {
        $exemption = lpc_proposal_text('tbl_exempt_default', 'fr');
    }

    // Frozen at issue where migration 045 has run; derived for older rows so a
    // reprint of a 2025 offer still shows the date the client was given rather
    // than one recomputed from today's validity_days setting.
    $valid_until = $r['valid_until'] ?? null;
    if (empty($valid_until) && !empty($r['date'])) {
        $valid_until = date('Y-m-d', strtotime($r['date'] . ' +' . (int)($r['validity_days'] ?? 30) . ' days'));
    }

    return [
        'record_id' => (int)$r['id'],
        'reference' => $r['reference'] ?? ('QUOTE-' . $r['id']),
        'revision'  => (int)($r['revision'] ?? 1),
        'date'      => $r['date'] ?? $r['created_at'],
        'valid_until'    => $valid_until,
        'validity_days'  => (int)($r['validity_days'] ?? 30),
        'client' => [
            'name'    => $r['client_name'] ?? $r['prospect_name'] ?? '',
            'contact' => $r['client_contact'] ?? '',
            'title'   => $r['client_title'] ?? '',
            'address' => $r['client_address'] ?? '',
            'niu'     => $r['client_niu'] ?? '',
            'rccm'    => $r['client_rccm'] ?? '',
            'phone'   => $r['client_phone'] ?? '',
            'email'   => $r['client_email'] ?? '',
        ],
        'items' => $items,
        'totals' => [
            'subtotal'      => $sub,
            'discount'      => 0.0,
            'excise_rate'   => (float)($r['excise_rate'] ?? 0) * 100,
            'excise'        => $excise,
            'tva_rate'      => $tva_rate,
            'tax'           => $tva,
            'tva_exemption' => $exemption,
            'grand_total'   => $grand,
            'words'         => lpc_amount_in_words($grand),
        ],
        // SLA fields the offer already carried, now surfaced to the template.
        'sla' => [
            'frequency'     => $r['delivery_frequency'] ?? '',
            'buffer_weeks'  => (int)($r['buffer_stock_weeks'] ?? 0),
            'payment_terms' => $r['payment_terms'] ?? '',
            'empties'       => $r['empties_policy'] ?? '',
        ],
        'consigne'  => $r['consigne'] ?? [],
        'annexes'   => $r['annexes'] ?? [],
        'sales_rep' => $r['sales_rep_name'] ?? '',
        'status'    => $r['status'] ?? 'draft',
        'notes'     => $r['notes'] ?? '',
        'source_updated_at' => $r['updated_at'] ?? $r['created_at'] ?? null,
    ];
}
function lpc_normalize_cre(array $r): array {
    return ['record_id'=>(int)$r['id'], 'reference'=>$r['reference'] ?? ('CRE-'.$r['id']),
            'date'=>$r['date'] ?? $r['created_at'],
            'client'=>['name'=>'', 'address'=>'', 'niu'=>'', 'phone'=>''],
            'items'=>[], 'totals'=>['subtotal'=>0,'discount'=>0,'tax'=>0,'grand_total'=>0,'words'=>''],
            'notes'=>$r['notes'] ?? '', 'source_updated_at'=>$r['updated_at'] ?? $r['created_at'] ?? null,
            'raw'=>$r];
}
function lpc_normalize_audit(array $r, array $lines): array {
    return ['record_id'=>(int)$r['id'], 'reference'=>$r['reference'] ?? ('AUDIT-'.$r['id']),
            'date'=>$r['created_at'],
            'client'=>['name'=>$r['operator_name'] ?? '', 'address'=>'', 'niu'=>'', 'phone'=>''],
            'items'=>array_map(fn($l) => [
                'name'=>$l['product_name'] ?? '', 'qty'=>(float)($l['physical_qty'] ?? 0),
                'unit_price'=>(float)($l['theoretical_qty'] ?? 0), 'total'=>0.0,
            ], $lines),
            'totals'=>['subtotal'=>0,'discount'=>0,'tax'=>0,'grand_total'=>0,'words'=>''],
            'notes'=>$r['notes'] ?? '', 'source_updated_at'=>$r['updated_at'] ?? $r['created_at'] ?? null];
}
function lpc_normalize_payslip(array $r): array {
    $breakdown = [];
    if (!empty($r['breakdown_json'])) {
        $decoded = json_decode($r['breakdown_json'], true);
        if (is_array($decoded)) $breakdown = $decoded;
    }
    return [
        'record_id' => (int)$r['id'], 'reference' => sprintf('PAIE-%04d%02d-%d', $r['year'], $r['month'], $r['user_id']),
        'date' => sprintf('%04d-%02d-01', $r['year'], $r['month']),
        'employee' => ['name'=>$r['employee_name'] ?? '', 'cnps'=>$r['cnps_number'] ?? '',
                       'marital'=>$r['marital_status'] ?? '', 'dependents'=>$r['dependents_count'] ?? 0],
        'raw' => $r, 'breakdown' => $breakdown,
        'source_updated_at' => $r['updated_at'] ?? $r['created_at'] ?? null,
    ];
}

// ---------------------------------------------------------------------------
// Template dispatcher + tiny helpers
// ---------------------------------------------------------------------------

function lpc_render_document_html(string $type, array $doc): string
{
    ob_start();
    $title_map = [
        'invoice'  => 'Facture',
        'delivery' => 'Bon de Livraison',
        'po'       => 'Bon de Commande',
        'quote'    => 'Devis',
        'cre'      => 'CRE — Collecte Recyclage',
        'audit'    => 'Rapport d\'Inventaire',
        'payslip'  => 'Bulletin de Paie',
    ];
    $title = ($title_map[$type] ?? 'Document') . ' — ' . ($doc['reference'] ?? '');
    $token = (string)($_GET['token'] ?? '');

    // -------------------------------------------------------------------------
    // Sprint 8 — letterhead from company_profile (migration 034).
    //
    // This block used to be three hardcoded lines inside the template:
    //     "Ets. La Petite Cour"
    //     "NIU M12200000000L"
    //     "Tél. +237 6XX XX XX XX"   <- a placeholder, on real customer invoices
    // none of which agreed with company_tax_settings ('Bureau LPC SARL',
    // NIU 'P000000000000') or the proposal template ('La Petite Cour').
    // All three now read from one row, editable at
    // Administration → Paramètres → Entreprise.
    //
    // The brand colours are interpolated into the stylesheet below, so changing
    // the primary colour in Settings restyles every document.
    // -------------------------------------------------------------------------
    $lh      = CompanyProfile::letterhead('fr');
    $brand   = $lh['color'];   // validated as #RRGGBB on save
    $accent  = $lh['accent'];
    // dompdf cannot resolve web-root-relative URLs, so the logo needs a real
    // filesystem path. '' means no readable file -> the <img> is skipped.
    $lhLogo  = CompanyProfile::logoFsPath('document');

    // Invoices and quotes get dedicated templates. The generic body below is a
    // condensed summary — fine for a bon de commande, not for the two documents
    // that have to stand up to a DGI control or form a contract.
    if ($type === 'invoice') {
        ob_end_clean();
        return lpc_render_invoice_pdf_html($doc, $lh, $lhLogo, $token);
    }
    if ($type === 'quote') {
        ob_end_clean();
        return lpc_render_quote_pdf_html($doc, $lh, $lhLogo, $token);
    }

    // Inline template — dompdf handles this fine and it stays in one place.
    ?>
    <!DOCTYPE html>
    <html lang="fr"><head><meta charset="UTF-8"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        @page { margin: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1F2937; margin: 15mm 12mm 20mm 12mm; }
        h1 { font-size: 20pt; margin: 0 0 4pt 0; color: <?= $brand ?>; }
        h2 { font-size: 12pt; margin: 12pt 0 6pt 0; color: <?= $brand ?>; border-bottom: 1px solid <?= $brand ?>; padding-bottom: 2pt; }
        table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: top; }
        .header-table .logo-cell { width: 65%; }
        .header-table .meta-cell { text-align: right; font-size: 9pt; color: #4B5563; }
        .items-table th { background: <?= $brand ?>; color: white; font-weight: bold; padding: 6pt 4pt; text-align: left; font-size: 9pt; }
        .items-table td { padding: 5pt 4pt; border-bottom: 1px solid #E5E7EB; font-size: 9pt; }
        .items-table td.num, .items-table th.num { text-align: right; }
        .totals-table { width: 45%; float: right; margin-top: 8pt; }
        .totals-table td { padding: 4pt 6pt; font-size: 10pt; }
        .totals-table .grand td { border-top: 2pt solid #111827; border-bottom: 2pt solid #111827; font-weight: bold; font-size: 12pt; background: #F3F4F6; }
        .box { border: 1pt solid #E5E7EB; padding: 6pt 8pt; margin-bottom: 6pt; }
        .muted { color: #6B7280; font-size: 8pt; }
        .stamp { border: 2pt solid <?= $brand ?>; color: <?= $brand ?>; padding: 4pt 8pt; display: inline-block; transform: rotate(-2deg); font-weight: bold; }
        /* Legal mentions, repeated at the foot of every page. Required on a
           Cameroonian invoice; previously absent entirely. */
        .doc-footer { position: fixed; bottom: 10mm; left: 12mm; right: 12mm; text-align: center;
                      color: #9CA3AF; font-size: 7pt; border-top: 0.5pt solid #E5E7EB; padding-top: 3pt; }
        .fallback-link { position: fixed; bottom: 6mm; left: 12mm; color: #9CA3AF; font-size: 7pt; text-decoration: none; }
    </style></head><body>
    <table class="header-table"><tr>
        <td class="logo-cell">
            <?php if ($lhLogo !== ''): ?>
                <img src="<?= htmlspecialchars($lhLogo, ENT_QUOTES, 'UTF-8') ?>" style="max-height:52pt;max-width:180pt;margin-bottom:4pt">
            <?php endif; ?>
            <h1 style="color: <?= htmlspecialchars($lh['color'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lh['name'], ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="muted">
                <?= htmlspecialchars($lh['address'], ENT_QUOTES, 'UTF-8') ?><br>
                <?php if ($lh['mentions'] !== ''): ?><?= htmlspecialchars($lh['mentions'], ENT_QUOTES, 'UTF-8') ?><br><?php endif; ?>
                <?= htmlspecialchars($lh['contact'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        </td>
        <td class="meta-cell">
            <div style="font-size: 16pt; font-weight: bold; color: <?= $brand ?>;"><?= htmlspecialchars($title_map[$type] ?? 'Document', ENT_QUOTES, 'UTF-8') ?></div>
            <div style="font-size: 12pt; font-weight: bold;">N° <?= htmlspecialchars($doc['reference'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div>Date : <?= htmlspecialchars(substr((string)($doc['date'] ?? ''), 0, 10), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if (!empty($doc['status'])): ?>
                <div class="stamp" style="margin-top: 6pt;"><?= strtoupper(htmlspecialchars($doc['status'], ENT_QUOTES, 'UTF-8')) ?></div>
            <?php endif; ?>
        </td>
    </tr></table>

    <?php if ($type === 'payslip'): ?>
        <?= lpc_render_payslip_body($doc) ?>
    <?php else: ?>
        <h2>Destinataire</h2>
        <div class="box">
            <div style="font-weight: bold; font-size: 11pt;"><?= htmlspecialchars($doc['client']['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="muted">
                <?= nl2br(htmlspecialchars($doc['client']['address'] ?? '', ENT_QUOTES, 'UTF-8')) ?><br>
                <?php if (!empty($doc['client']['niu'])): ?>NIU : <?= htmlspecialchars($doc['client']['niu'], ENT_QUOTES, 'UTF-8') ?><br><?php endif; ?>
                <?php if (!empty($doc['client']['phone'])): ?>Tél : <?= htmlspecialchars($doc['client']['phone'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
            </div>
        </div>

        <h2>Articles</h2>
        <table class="items-table">
            <thead><tr>
                <th>Désignation</th><th class="num">Qté</th><th class="num">P.U. (FCFA)</th><th class="num">Total (FCFA)</th>
            </tr></thead><tbody>
            <?php foreach ($doc['items'] as $it): ?>
                <tr>
                    <td><?= htmlspecialchars($it['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        <?php if (!empty($it['format'])): ?><span class="muted"> — <?= htmlspecialchars($it['format'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    </td>
                    <td class="num"><?= lpc_fcfa($it['qty'] ?? 0, '') ?></td>
                    <td class="num"><?= lpc_fcfa($it['unit_price'] ?? 0, '') ?></td>
                    <td class="num"><?= lpc_fcfa($it['total'] ?? 0, '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($doc['items'])): ?>
                <tr><td colspan="4" class="muted" style="text-align:center; padding: 20pt;">Aucun article.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <table class="totals-table">
            <tr><td>Sous-total</td><td class="num"><?= lpc_fcfa($doc['totals']['subtotal'] ?? 0) ?></td></tr>
            <?php if (($doc['totals']['discount'] ?? 0) > 0): ?>
                <tr><td>Remise</td><td class="num"><?= lpc_fcfa(-abs($doc['totals']['discount'])) ?></td></tr>
            <?php endif; ?>
            <?php if (($doc['totals']['tax'] ?? 0) > 0): ?>
                <tr><td>TVA</td><td class="num"><?= lpc_fcfa($doc['totals']['tax']) ?></td></tr>
            <?php endif; ?>
            <tr class="grand"><td>Total à payer</td><td class="num"><?= lpc_fcfa($doc['totals']['grand_total'] ?? 0) ?></td></tr>
        </table>
        <div style="clear: both;"></div>

        <?php if (!empty($doc['totals']['words'])): ?>
            <div class="box" style="margin-top: 12pt;">
                <span class="muted">Arrêtée à la somme de :</span><br>
                <em><?= htmlspecialchars($doc['totals']['words'], ENT_QUOTES, 'UTF-8') ?></em>
            </div>
        <?php endif; ?>

        <?php if (!empty($doc['notes'])): ?>
            <h2>Notes</h2>
            <div class="box"><?= nl2br(htmlspecialchars($doc['notes'], ENT_QUOTES, 'UTF-8')) ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($lh['footer'])): ?>
        <div class="doc-footer"><?= htmlspecialchars($lh['footer'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <a class="fallback-link" href="?token=<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>&amp;html=1">Voir en HTML</a>
    </body></html>
    <?php
    return (string) ob_get_clean();
}

/**
 * The invoice, rendered for dompdf.
 * -----------------------------------------------------------------------------
 * This is the deliverable that leaves the company, so it is built to match
 * public/documents/facture.php block for block: same green rule, same letterhead,
 * same "Facturé à" card, same fiscal ladder, same certification stamp. A customer
 * who saves the PDF and a customer who opens the link must be looking at the
 * same document.
 *
 * Written against dompdf's CSS subset, not a browser's:
 *   · Layout is tables and floats. flexbox and grid are not supported.
 *   · `position: fixed` renders on EVERY page — which is exactly what the
 *     statutory footer needs, since the legal mentions are required on each one.
 *   · <thead> repeats automatically across page breaks, so a 40-line invoice
 *     keeps its column headings.
 *   · `page-break-inside: avoid` keeps the totals ladder and the stamp from
 *     being split down the middle.
 *   · DejaVu Sans is the only font assumed present — it is the one dompdf ships,
 *     and it has the accented glyphs and the space-separated thousands the
 *     French formatting needs.
 *
 * Mandatory mentions carried, per art. 150 CGI and OHADA practice:
 *   seller raison sociale + forme juridique, address, RCCM, NIU, share capital,
 *   régime fiscal, centre des impôts · buyer name, address and NIU · unique
 *   sequential invoice number · issue and due dates · per-line description,
 *   quantity, unit price HT and line total · total HT · droit d'accises where it
 *   applies · TVA rate and amount, or the legal basis for its absence ·
 *   withholdings (précompte, AIR) · total TTC · currency · amount in words.
 */
function lpc_render_invoice_pdf_html(array $doc, array $lh, string $lhLogo, string $token): string
{
    $t      = $doc['totals'];
    $client = $doc['client'];
    $stamp  = $doc['stamp'] ?? [];
    $brand  = $lh['color'];

    $e = static function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    // Rates print French-style: "19,25 %", "5 %" — never "19.2500".
    $rate = static function ($v) {
        $s = rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
        return $s === '' ? '0' : $s;
    };
    $fdate = static function ($v) {
        return $v ? date('d/m/Y', strtotime((string) $v)) : '—';
    };

    $status_labels = ['paid' => 'PAYÉE', 'partial' => 'PARTIEL', 'unpaid' => 'NON PAYÉE'];
    $status_key    = (string) ($doc['status'] ?? 'unpaid');
    $status_label  = $status_labels[$status_key] ?? strtoupper($status_key);
    $status_colors = [
        'paid'    => ['#10B981', '#ECFDF5'],
        'partial' => ['#F59E0B', '#FFFBEB'],
        'unpaid'  => ['#EF4444', '#FEF2F2'],
    ];
    [$sc_border, $sc_bg] = $status_colors[$status_key] ?? $status_colors['unpaid'];

    // The accounts this invoice advertised, frozen at issue (migration 044).
    // Read the snapshot, never the live treasury_accounts rows — a reprint has
    // to show what the client was actually told to pay into. Invoices issued
    // before 041 fall back to the single company_profile account.
    $payment_blocks = $doc['payment_blocks'] ?? [];
    if (!$payment_blocks) {
        $legacy = CompanyProfile::bankDetails();
        if ($legacy) $payment_blocks = [['title' => 'Coordonnées de règlement', 'lines' => $legacy]];
    }

    $has_withholding = ($t['precompte'] > 0) || ($t['air'] > 0);

    ob_start(); ?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8"><title>Facture <?= $e($doc['reference']) ?></title>
<style>
    @page { margin: 0; }
    body  { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1F2937;
            margin: 0 0 26mm 0; }

<?= lpc_document_header_css($brand) ?>
<?= lpc_document_footer_css() ?>

    table       { width: 100%; border-collapse: collapse; }
    td, th      { vertical-align: top; }
    .num        { text-align: right; }
    .muted      { color: #6B7280; }
    .tiny       { font-size: 7pt; }
    .xtiny      { font-size: 6.5pt; }
    .caps       { text-transform: uppercase; letter-spacing: 0.6pt; font-weight: bold; }
    .b          { font-weight: bold; }

    .card       { background: #F9FAFB; border: 0.5pt solid #E5E7EB; border-radius: 3mm;
                  padding: 3.5mm 4mm; }

    .items th   { border-bottom: 1.5pt solid #111827; padding: 2.5mm 1.5mm;
                  font-size: 6.5pt; color: #9CA3AF; text-transform: uppercase;
                  letter-spacing: 0.6pt; }
    .items td   { border-bottom: 0.5pt solid #E5E7EB; padding: 3mm 1.5mm; font-size: 8.5pt; }

    .ladder td       { padding: 1.4mm 0; font-size: 8pt; }
    .ladder .grand td{ border-top: 1.5pt solid #111827; padding-top: 2.5mm;
                       font-size: 10.5pt; font-weight: bold; }
    .ladder .exempt  { font-size: 6.5pt; font-style: italic; color: #6B7280;
                       padding-top: 0; padding-bottom: 2mm; }

    .stamp { border: 1.2pt solid <?= $brand ?>; color: <?= $brand ?>; border-radius: 2mm;
             padding: 2.5mm 3.5mm; font-size: 6.5pt; line-height: 1.5; }

    .no-break   { page-break-inside: avoid; }
</style></head>
<body>

<div class="lpc-rule"></div>

<!-- ── Letterhead ───────────────────────────────────────────────────────────
     Shared with the devis — see includes/pdf_templates/document_header.php.
     Only the title and the meta rows differ between the two documents. The
     N° / Date / Échéance box below is the block that came out completely
     BLANK in the html2canvas PDF (FAC-2607-6341): the screenshot was taken
     before those nodes had settled. Here it is text in the document tree,
     so no capture race can lose it. -->
<div class="lpc-pad" style="padding-top: 9mm;">
<?= lpc_document_header([
    'title' => 'Facture',
    'logo'  => $lhLogo,
    'badge' => [$status_label, $sc_border, $sc_bg],
    'meta'  => [
        ['N° Facture', $doc['reference']],
        ['Date',       $fdate($doc['date'])],
        ['Échéance',   $fdate($doc['due_date']), 'danger'],
        ['Devise',     'FCFA (XAF)', 'rule'],
    ],
]) ?>
</div>

<!-- ── Buyer ──────────────────────────────────────────────────────────────── -->
<div class="lpc-pad" style="margin-top: 6mm;">
    <div class="card no-break">
        <div class="caps muted xtiny" style="border-bottom: 1pt solid #E5E7EB;
                    display: inline-block; padding-bottom: 1mm; margin-bottom: 2mm;">Facturé à</div>
        <div class="b" style="font-size: 12.5pt; color: #111827;"><?= $e($client['name']) ?></div>
        <?php if (!empty($client['address'])): ?>
            <div class="muted" style="margin-top: 1mm; font-size: 8.5pt;"><?= nl2br($e($client['address'])) ?></div>
        <?php endif; ?>
        <div class="muted" style="margin-top: 1mm; font-size: 8.5pt;">
            Tél : <?= $e($client['phone'] ?: 'N/A') ?>
            &nbsp;|&nbsp; Email : <?= $e($client['email'] ?: 'N/A') ?>
        </div>
        <?php
        // The buyer's NIU is what makes the purchase deductible for them; art.
        // 150 CGI requires it on a B2B invoice. Print it, and say so plainly
        // when it is missing rather than leaving a silent blank.
        ?>
        <div class="b" style="margin-top: 2mm; padding-top: 1.8mm; border-top: 0.5pt solid #E5E7EB; font-size: 8pt;">
            NIU : <?= $e($client['niu'] ?: '—') ?>
            &nbsp;|&nbsp; RCCM : <?= $e($client['rccm'] ?: '—') ?>
        </div>
        <?php if (empty($client['niu']) && !empty($client['is_b2b'])): ?>
            <div class="xtiny b caps" style="margin-top: 2mm; color: #B45309; background: #FFFBEB;
                        border: 0.5pt solid #FDE68A; border-radius: 1.5mm; padding: 1.5mm 2.5mm;">
                NIU client manquant — requis pour la déductibilité B2B
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Lines ──────────────────────────────────────────────────────────────── -->
<div class="lpc-pad" style="margin-top: 6mm;">
<table class="items">
    <thead>
        <tr>
            <th style="text-align: left;">Désignation</th>
            <th style="text-align: center; width: 15mm;">Qté</th>
            <th class="num" style="width: 30mm;">P.U. (FCFA)</th>
            <th class="num" style="width: 33mm;">Montant (FCFA)</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($doc['items'] as $it): ?>
        <tr>
            <td class="b"><?= $e($it['name']) ?><?php
                if (!empty($it['format'])) {
                    echo '<span class="muted" style="font-weight:normal"> — ' . $e($it['format']) . '</span>';
                } ?></td>
            <td class="b" style="text-align: center;"><?= $e(lpc_fcfa($it['qty'], '')) ?></td>
            <td class="num"><?= $e(lpc_fcfa($it['unit_price'], '')) ?></td>
            <td class="num b"><?= $e(lpc_fcfa($it['total'], '')) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($doc['items'])): ?>
        <tr><td colspan="4" class="muted" style="text-align: center; padding: 12mm 0;">Aucun article.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<!-- ── Payment info + fiscal ladder ───────────────────────────────────────── -->
<div class="lpc-pad" style="margin-top: 7mm;">
<table class="no-break">
    <tr>
        <td style="width: 50%; padding-right: 7mm;">
            <?php if (!empty($doc['notes'])): ?>
                <div class="caps muted xtiny" style="margin-bottom: 1.5mm;">Notes / Conditions</div>
                <div style="font-size: 7.5pt; color: #4B5563; margin-bottom: 4mm;"><?= nl2br($e($doc['notes'])) ?></div>
            <?php endif; ?>

            <div class="caps muted xtiny" style="margin-bottom: 1.5mm;">Informations de Paiement</div>
            <div class="card" style="font-size: 7.5pt; line-height: 1.7;">
                <?php if ($payment_blocks): ?>
                    <?php foreach ($payment_blocks as $bi => $block): ?>
                        <?php if (empty($block['lines'])) continue; ?>
                        <div style="<?= $bi > 0 ? 'margin-top: 2.5mm; padding-top: 2mm; border-top: 0.5pt solid #E5E7EB;' : '' ?>">
                            <div class="caps b" style="font-size: 6.5pt; color: #111827; margin-bottom: 0.8mm;">
                                <?= $e($block['title'] ?? '') ?>
                            </div>
                            <?php foreach ($block['lines'] as $label => $value): ?>
                                <div><span class="b" style="color: #111827;"><?= $e($label) ?> :</span> <?= $e($value) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="muted" style="font-style: italic;">Coordonnées de règlement non renseignées.</div>
                <?php endif; ?>
            </div>
            <div class="xtiny muted" style="margin-top: 1.5mm; font-style: italic;">
                Merci de préciser le N° de facture en motif du règlement.
            </div>

            <div class="caps muted xtiny" style="margin-top: 4mm; margin-bottom: 1.5mm;">Conditions de Règlement</div>
            <div class="xtiny muted" style="line-height: 1.6;">
                Paiement exigible au plus tard à la date d'échéance indiquée. Passé ce délai, une
                pénalité de retard est applicable de plein droit, sans mise en demeure préalable.
                Les marchandises restent la propriété du vendeur jusqu'au paiement intégral du prix.
            </div>
        </td>

        <td style="width: 50%;">
            <div class="card">
            <table class="ladder">
                <tr>
                    <td>Total Hors Taxe (HT)</td>
                    <td class="num b"><?= $e(lpc_fcfa($t['subtotal'])) ?></td>
                </tr>

                <?php if ($t['excise'] > 0): ?>
                    <?php // Accises are assessed on the HT and then join the TVA base. ?>
                    <tr>
                        <td>Droit d'accises (<?= $e($rate($t['excise_rate'])) ?> %)</td>
                        <td class="num b"><?= $e(lpc_fcfa($t['excise'])) ?></td>
                    </tr>
                <?php endif; ?>

                <tr>
                    <td>TVA (<?= $e($rate($t['tva_rate'])) ?> %)</td>
                    <td class="num b"><?= $e(lpc_fcfa($t['tax'])) ?></td>
                </tr>

                <?php if ($t['tva_rate'] <= 0 && !empty($t['tva_exemption'])): ?>
                    <?php // A bare "TVA 0 %" cannot be told apart from an omission. ?>
                    <tr><td colspan="2" class="exempt"><?= $e($t['tva_exemption']) ?></td></tr>
                <?php endif; ?>

                <tr class="grand">
                    <td>NET À PAYER (TTC)</td>
                    <td class="num"><?= $e(lpc_fcfa($t['grand_total'])) ?></td>
                </tr>

                <?php if ($has_withholding): ?>
                    <?php // Withholdings do not reduce the TTC owed — they split it
                          // between the supplier and the DGI. ?>
                    <tr><td colspan="2" style="padding-top: 3mm;">
                        <div class="caps xtiny muted" style="border-top: 0.5pt dashed #D1D5DB; padding-top: 2mm;">
                            Retenues à la source
                        </div>
                    </td></tr>
                    <?php if ($t['precompte'] > 0): ?>
                        <tr>
                            <td class="tiny">Précompte sur achats (<?= $e($rate($t['precompte_rate'])) ?> %)</td>
                            <td class="num b tiny">- <?= $e(lpc_fcfa($t['precompte'])) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($t['air'] > 0): ?>
                        <tr>
                            <td class="tiny">AIR — Acompte d'Impôt sur le Revenu (<?= $e($rate($t['air_rate'])) ?> %)</td>
                            <td class="num b tiny">- <?= $e(lpc_fcfa($t['air'])) ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr><td colspan="2" style="padding-top: 2mm;">
                        <table style="background: #111827; border-radius: 1.5mm;">
                            <tr>
                                <td class="caps xtiny" style="color: #fff; padding: 2mm 3mm;">Net à virer au fournisseur</td>
                                <td class="num b" style="color: #fff; padding: 2mm 3mm; font-size: 9pt;">
                                    <?= $e(lpc_fcfa($t['net_payable'])) ?>
                                </td>
                            </tr>
                        </table>
                    </td></tr>
                <?php endif; ?>

                <tr><td colspan="2" style="padding-top: 2.5mm; border-top: 0.5pt solid #E5E7EB;"></td></tr>
                <tr>
                    <td class="caps xtiny" style="color: #059669;">Déjà réglé (avances)</td>
                    <td class="num b" style="color: #059669;"><?= $e(lpc_fcfa($t['paid'])) ?></td>
                </tr>
                <tr>
                    <td class="caps tiny b" style="color: #DC2626;">Reste à payer</td>
                    <td class="num b" style="color: #DC2626; font-size: 9.5pt;"><?= $e(lpc_fcfa($t['balance'])) ?></td>
                </tr>
            </table>
            </div>
        </td>
    </tr>
</table>
</div>

<!-- ── Certification stamp + amount in words ──────────────────────────────── -->
<div class="lpc-pad" style="margin-top: 8mm;">
<table class="no-break">
    <tr>
        <td style="width: 42%;">
            <div class="stamp">
                <div class="caps" style="border-bottom: 0.5pt solid <?= $brand ?>;
                            padding-bottom: 1mm; margin-bottom: 1mm; font-size: 7pt;">Certifié Conforme</div>
                <div><span class="b">Par :</span> <?= $e($stamp['created_by'] ?? '—') ?></div>
                <div><span class="b">Rôle :</span> <?= $e($stamp['role'] ?? '—') ?></div>
                <div><span class="b">Le :</span> <?= $e($stamp['timestamp'] ?? '—') ?></div>
                <div class="xtiny" style="opacity: 0.75; margin-top: 0.8mm;">Hash : <?= $e($stamp['hash'] ?? '') ?></div>
            </div>
        </td>
        <td style="width: 58%; text-align: right; padding-left: 6mm;">
            <?php // OHADA practice: close the invoice with the total spelled out. ?>
            <div class="muted" style="font-size: 8pt; font-style: italic;">
                Arrêtée la présente facture à la somme de :
            </div>
            <div class="b" style="font-size: 9.5pt; color: #111827; margin-top: 1.2mm;">
                <?= $e($t['words']) ?>
            </div>
        </td>
    </tr>
</table>
</div>

<?= lpc_document_footer() ?>

</body></html>
    <?php
    return (string) ob_get_clean();
}

/**
 * The commercial offer, rendered for dompdf.
 * -----------------------------------------------------------------------------
 * Replaces a 4-page html2canvas capture. `pdftotext` extracted FOUR CHARACTERS
 * from the whole of DEV-2607-841B: every page was a 1588×2246 JPEG at 192 ppi.
 * A prospect could not copy the price into a budget sheet, could not search it,
 * and printed it at screen resolution. On the document that wins the business
 * that mattered more than it did on the invoice.
 *
 * Structure, and why each part is here:
 *   Cover     — the shared header band, then the existing arcs and title, plus
 *               an "En bref" box so a decision-maker who reads only page 1 has
 *               the monthly figure and the expiry date.
 *   Profile   — executive summary, capabilities, references (unchanged copy).
 *   Offer     — the pricing table, now with the same fiscal ladder as the
 *               invoice: HT → accises → TVA or its exemption basis → TTC, and
 *               the total in words. The old version printed one bare figure
 *               with no indication whether it was HT or TTC.
 *   Terms     — the six articles, plus the consigne schedule that Article 2
 *               presupposes, plus delivery zone and transfer of risk.
 *   Signature — Bon pour accord, with a dated expiry rather than "30 jours".
 *
 * Every string comes from lpc_proposal_text() so the Proposal Studio keeps
 * editing them; every figure comes from the proposals row.
 */
function lpc_render_quote_pdf_html(array $doc, array $lh, string $lhLogo, string $token): string
{
    $t      = $doc['totals'];
    $client = $doc['client'];
    $sla    = $doc['sla'];
    $brand  = $lh['color'];
    $accent = $lh['accent'];

    $e = static function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    $p = static function ($key) use ($e) {
        return $e(lpc_proposal_text($key, 'fr'));
    };
    $rate = static function ($v) {
        $s = rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
        return $s === '' ? '0' : $s;
    };
    $fdate = static function ($v) {
        return $v ? date('d/m/Y', strtotime((string) $v)) : '—';
    };

    $logos    = lpc_proposal_logos();
    $consigne = $doc['consigne'] ?? [];
    $annexes  = $doc['annexes'] ?? [];

    // The reference a client quotes back at you has to identify the version
    // they accepted. Rev. 1 is implicit and not printed.
    $ref_full = $doc['reference'] . ($doc['revision'] > 1 ? ' rév. ' . $doc['revision'] : '');

    ob_start(); ?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8"><title>Devis <?= $e($doc['reference']) ?></title>
<style>
    @page { margin: 0; }
    body  { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1F2937;
            margin: 0 0 26mm 0; }

<?= lpc_document_header_css($brand) ?>
<?= lpc_document_footer_css() ?>

    table   { width: 100%; border-collapse: collapse; }
    td, th  { vertical-align: top; }
    .num    { text-align: right; }
    .muted  { color: #6B7280; }
    .tiny   { font-size: 7.5pt; }
    .xtiny  { font-size: 6.5pt; }
    .b      { font-weight: bold; }
    .caps   { text-transform: uppercase; letter-spacing: 0.6pt; font-weight: bold; }
    .card   { background: #F9FAFB; border: 0.5pt solid #E5E7EB; border-radius: 3mm;
              padding: 3.5mm 4mm; }
    .no-break { page-break-inside: avoid; }

    h2.sec  { font-size: 13pt; color: #111827; margin: 0 0 3mm 0; font-weight: bold; }
    h3.sub  { font-size: 8pt; color: #111827; margin: 5mm 0 2mm 0;
              text-transform: uppercase; letter-spacing: 0.8pt; }
    p       { margin: 0 0 2.5mm 0; line-height: 1.55; text-align: justify; }

    /* Section band — the green strip that headed pages 2-4 of the original. */
    .band   { background: <?= $brand ?>; color: #fff; padding: 3.5mm 14mm;
              font-size: 8pt; text-transform: uppercase; letter-spacing: 1pt;
              font-weight: bold; }
    .band .ref { color: <?= $accent ?>; }

    .items th { background: <?= $brand ?>; color: #fff; padding: 2.5mm 2mm;
                font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.6pt;
                text-align: left; }
    .items td { border-bottom: 0.5pt solid #E5E7EB; padding: 3mm 2mm; font-size: 8.5pt; }

    .ladder td        { padding: 1.4mm 0; font-size: 8.5pt; }
    .ladder .grand td { border-top: 1.5pt solid #111827; padding-top: 2.5mm;
                        font-size: 11pt; font-weight: bold; }
    .ladder .exempt   { font-size: 6.5pt; font-style: italic; color: #6B7280;
                        padding-top: 0; padding-bottom: 2mm; }

    .sla td { padding: 2.5mm 0; border-bottom: 0.5pt solid #E5E7EB; }
    .art    { margin-bottom: 3mm; }
    .art .t { font-weight: bold; color: #111827; }
</style></head>
<body>

<!-- ══ COVER ═══════════════════════════════════════════════════════════════ -->
<div class="lpc-rule"></div>
<div class="lpc-pad" style="padding-top: 9mm;">
<?php
// The same header the facture carries, per the decision to run it on every
// page including the cover. Only the title and the meta rows differ.
// Validité sits in the meta box because on an offer it matters as much as the
// date — it used to be buried on page 3 under the SLA as "30 jours".
$meta = [
    [lpc_proposal_text('meta_ref', 'fr'), $doc['reference']],
    [lpc_proposal_text('meta_date', 'fr'), $fdate($doc['date'])],
    [lpc_proposal_text('meta_valid_until', 'fr'), $fdate($doc['valid_until']), 'danger'],
];
if ($doc['revision'] > 1) {
    $meta[] = [lpc_proposal_text('meta_revision', 'fr'), (string) $doc['revision'], 'rule'];
}
echo lpc_document_header([
    'title' => 'Devis',
    'logo'  => $lhLogo,
    'meta'  => $meta,
]);
?>

<div style="margin-top: 16mm;">
    <div style="width: 26mm; height: 1.6mm; background: <?= $accent ?>;"></div>
    <h1 style="font-size: 26pt; font-weight: bold; color: #111827; margin: 5mm 0 1mm 0;">
        <?= $p('cover_title') ?>
    </h1>
    <div style="font-size: 12pt; color: #6B7280;"><?= $p('cover_subtitle') ?></div>
</div>

<table style="margin-top: 14mm;">
    <tr>
        <td style="width: 56%; padding-right: 8mm;">
            <div class="card no-break" style="border-left: 1.5mm solid <?= $brand ?>;">
                <div class="caps xtiny muted" style="margin-bottom: 2mm;"><?= $p('cover_prepared_for') ?></div>
                <div class="b" style="font-size: 14pt; color: #111827;"><?= $e($client['name']) ?></div>
                <?php if (!empty($client['contact'])): ?>
                    <div style="margin-top: 1.5mm; font-size: 9pt;">
                        Attn : <?= $e($client['contact']) ?><?php
                        if (!empty($client['title'])) echo ' — ' . $e($client['title']); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($client['address'])): ?>
                    <div class="muted tiny" style="margin-top: 1mm;"><?= $e($client['address']) ?></div>
                <?php endif; ?>
                <?php if (!empty($client['phone']) || !empty($client['email'])): ?>
                    <div class="muted tiny" style="margin-top: 1mm;">
                        <?= $e(trim(implode(' · ', array_filter([$client['phone'], $client['email']])))) ?>
                    </div>
                <?php endif; ?>
                <?php
                // The prospect's NIU, collected during the sale rather than
                // chased after the first invoice is rejected. Printed only when
                // known — a prospect is not always ready to hand it over on
                // first contact, and a blank field on a sales document is worse
                // than no field.
                if (!empty($client['niu']) || !empty($client['rccm'])): ?>
                    <div class="tiny b" style="margin-top: 2.5mm; padding-top: 2mm; border-top: 0.5pt solid #E5E7EB;">
                        <?php if (!empty($client['niu'])): ?>NIU : <?= $e($client['niu']) ?><?php endif; ?>
                        <?php if (!empty($client['niu']) && !empty($client['rccm'])): ?> &nbsp;|&nbsp; <?php endif; ?>
                        <?php if (!empty($client['rccm'])): ?>RCCM : <?= $e($client['rccm']) ?><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </td>
        <td style="width: 44%;">
            <?php
            // "En bref" — the commercial essentials for a decision-maker who
            // reads page 1 and forwards the rest to procurement.
            ?>
            <div class="card no-break" style="background: #111827; border: 0;">
                <div class="caps xtiny" style="color: <?= $accent ?>; margin-bottom: 2.5mm;">
                    <?= $p('cover_summary_title') ?>
                </div>
                <div class="xtiny" style="color: #9CA3AF;"><?= $p('cover_summary_total') ?></div>
                <div class="b" style="color: #fff; font-size: 15pt; margin-top: 0.8mm;">
                    <?= $e(lpc_fcfa($t['grand_total'])) ?>
                </div>
                <div class="xtiny" style="color: #9CA3AF; margin-top: 0.8mm;">
                    <?= $t['tva_rate'] > 0
                        ? 'TTC · TVA ' . $e($rate($t['tva_rate'])) . ' %'
                        : 'HT · ' . $p('tbl_tva') . ' 0 %' ?>
                </div>

                <div style="margin-top: 4mm; padding-top: 3mm; border-top: 0.5pt solid #374151;">
                    <div class="xtiny" style="color: #9CA3AF;"><?= $p('meta_valid_until') ?></div>
                    <div class="b" style="color: #fff; font-size: 10pt; margin-top: 0.8mm;">
                        <?= $e($fdate($doc['valid_until'])) ?>
                    </div>
                </div>

                <?php if (!empty($sla['payment_terms'])): ?>
                    <div style="margin-top: 3mm;">
                        <div class="xtiny" style="color: #9CA3AF;"><?= $p('sla_pay') ?></div>
                        <div class="b" style="color: #fff; font-size: 9pt; margin-top: 0.8mm;">
                            <?= $e($sla['payment_terms']) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </td>
    </tr>
</table>

<table style="margin-top: 20mm; border-top: 0.5pt solid #E5E7EB;">
    <tr>
        <td style="padding-top: 3mm;">
            <div class="caps xtiny muted"><?= $p('meta_date') ?></div>
            <div class="b" style="margin-top: 1mm;"><?= $e($fdate($doc['date'])) ?></div>
        </td>
        <td style="padding-top: 3mm;">
            <div class="caps xtiny muted"><?= $p('meta_ref') ?></div>
            <div class="b" style="margin-top: 1mm;"><?= $e($ref_full) ?></div>
        </td>
        <td style="padding-top: 3mm; text-align: right;">
            <div class="caps xtiny muted"><?= $p('cover_prepared_by') ?></div>
            <div class="b" style="margin-top: 1mm; color: <?= $brand ?>;"><?= $e($doc['sales_rep']) ?></div>
        </td>
    </tr>
</table>
</div>

<!-- ══ PROFILE & CONTEXT ═══════════════════════════════════════════════════ -->
<div style="page-break-before: always;"></div>
<div class="band"><?= $p('header_p2') ?> <span style="float: right;" class="ref"><?= $e($ref_full) ?></span></div>
<div class="lpc-pad" style="padding-top: 8mm;">
    <h2 class="sec"><?= $p('sec1_title') ?></h2>
    <p><?= $p('sec1_p1') ?></p>
    <p><?= $p('sec1_p2') ?></p>

    <h2 class="sec" style="margin-top: 7mm;"><?= $p('sec2_title') ?></h2>
    <p><?= $p('sec2_intro') ?></p>

    <h3 class="sub"><?= $p('sec2_services') ?></h3>
    <table>
        <tr>
            <td style="width: 50%; padding-right: 5mm;">
                <p class="tiny">✓ <?= $p('sec2_l1') ?></p>
                <p class="tiny">✓ <?= $p('sec2_l3') ?></p>
            </td>
            <td style="width: 50%;">
                <p class="tiny">✓ <?= $p('sec2_l2') ?></p>
                <p class="tiny">✓ <?= $p('sec2_l4') ?></p>
            </td>
        </tr>
    </table>

    <p style="margin-top: 3mm;"><?= $p('sec2_body') ?></p>

    <table style="margin-top: 5mm;">
        <tr>
            <td style="width: 58%; padding-right: 6mm;">
                <h3 class="sub" style="margin-top: 0;"><?= $p('sec2_gamme_title') ?></h3>
                <p class="tiny">• <?= $p('sec2_gamme_1') ?></p>
                <p class="tiny">• <?= $p('sec2_gamme_3') ?></p>
                <p class="tiny">• <?= $p('sec2_gamme_2') ?></p>
                <p class="tiny">• <?= $p('sec2_gamme_4') ?></p>

                <h3 class="sub"><?= $p('sec2_av_title') ?></h3>
                <table>
                    <tr>
                        <td style="width: 50%; padding-right: 3mm;">
                            <div class="card" style="background: #F0FDF4; border-color: #BBF7D0;">
                                <div class="b tiny"><?= $p('sec2_av_1_title') ?></div>
                                <div class="xtiny muted" style="margin-top: 1mm;"><?= $p('sec2_av_1_desc') ?></div>
                            </div>
                        </td>
                        <td style="width: 50%;">
                            <div class="card" style="background: #F0FDF4; border-color: #BBF7D0;">
                                <div class="b tiny"><?= $p('sec2_av_2_title') ?></div>
                                <div class="xtiny muted" style="margin-top: 1mm;"><?= $p('sec2_av_2_desc') ?></div>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 42%;">
                <div class="card">
                    <?php foreach ([['b1'], ['b2'], ['b3']] as $i => $blk): ?>
                        <div style="<?= $i > 0 ? 'margin-top: 3.5mm;' : '' ?>">
                            <div class="b tiny"><?= $p('sec2_' . $blk[0] . '_title') ?></div>
                            <div class="xtiny muted" style="margin-top: 1mm;"><?= $p('sec2_' . $blk[0] . '_desc') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </td>
        </tr>
    </table>

    <?php if ($logos): ?>
        <div class="caps xtiny muted" style="text-align: center; margin-top: 7mm;"><?= $p('sec2_clients') ?></div>
        <table style="margin-top: 3mm;">
            <tr>
            <?php foreach ($logos as $lg):
                // dompdf needs a filesystem path; a missing file is skipped
                // rather than rendering a broken-image box on a sales document.
                $fs = realpath(__DIR__ . '/../..' . $lg['src']);
                ?>
                <td style="text-align: center; padding: 0 2mm;">
                    <?php if ($fs && is_readable($fs)): ?>
                        <img src="<?= $e($fs) ?>" style="max-height: 9mm; max-width: 24mm;">
                    <?php else: ?>
                        <span class="xtiny muted"><?= $e($lg['alt']) ?></span>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
            </tr>
        </table>
    <?php endif; ?>
</div>

<!-- ══ OFFER & PRICING ═════════════════════════════════════════════════════ -->
<div style="page-break-before: always;"></div>
<div class="band"><?= $p('header_p3') ?> <span style="float: right;" class="ref"><?= $e($ref_full) ?></span></div>
<div class="lpc-pad" style="padding-top: 8mm;">
    <h2 class="sec"><?= $p('sec3_title') ?></h2>

    <table class="items">
        <thead>
            <tr>
                <th><?= $p('tbl_desc') ?></th>
                <th style="text-align: center; width: 24mm;"><?= $p('tbl_qty') ?></th>
                <th class="num" style="width: 28mm;"><?= $p('tbl_price') ?></th>
                <th class="num" style="width: 32mm;"><?= $p('tbl_total') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($doc['items'] as $it): ?>
            <tr>
                <td class="b"><?= $e($it['name']) ?><?php
                    if (!empty($it['format'])) {
                        echo '<span class="muted" style="font-weight:normal"> — ' . $e($it['format']) . '</span>';
                    } ?></td>
                <td style="text-align: center;"><?= $e(lpc_fcfa($it['qty'], '')) ?></td>
                <td class="num"><?= $e(lpc_fcfa($it['unit_price'], '')) ?></td>
                <td class="num b"><?= $e(lpc_fcfa($it['total'], '')) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($doc['items'])): ?>
            <tr><td colspan="4" class="muted" style="text-align: center; padding: 12mm 0;">Aucune ligne.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php
    // The fiscal ladder. The old document printed a single figure with no
    // indication of HT or TTC — the one thing a buyer must know to compare
    // suppliers or get an approval through their own finance team.
    ?>
    <table style="margin-top: 5mm;" class="no-break">
        <tr>
            <td style="width: 52%; padding-right: 8mm; vertical-align: bottom;">
                <p class="xtiny muted" style="font-style: italic;"><?= $p('tbl_note') ?></p>
            </td>
            <td style="width: 48%;">
                <div class="card">
                <table class="ladder">
                    <tr>
                        <td><?= $p('tbl_subtotal') ?></td>
                        <td class="num b"><?= $e(lpc_fcfa($t['subtotal'])) ?></td>
                    </tr>
                    <?php if ($t['excise'] > 0): ?>
                        <tr>
                            <td>Droit d'accises (<?= $e($rate($t['excise_rate'])) ?> %)</td>
                            <td class="num b"><?= $e(lpc_fcfa($t['excise'])) ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td><?= $p('tbl_tva') ?> (<?= $e($rate($t['tva_rate'])) ?> %)</td>
                        <td class="num b"><?= $e(lpc_fcfa($t['tax'])) ?></td>
                    </tr>
                    <?php if ($t['tva_rate'] <= 0 && !empty($t['tva_exemption'])): ?>
                        <tr><td colspan="2" class="exempt"><?= $e($t['tva_exemption']) ?></td></tr>
                    <?php endif; ?>
                    <tr class="grand">
                        <td><?= $t['tva_rate'] > 0 ? $p('tbl_ttc') : $p('tbl_grand') ?></td>
                        <td class="num"><?= $e(lpc_fcfa($t['grand_total'])) ?></td>
                    </tr>
                </table>
                </div>
            </td>
        </tr>
    </table>

    <div class="card no-break" style="margin-top: 4mm;">
        <span class="muted tiny" style="font-style: italic;"><?= $p('tbl_words') ?></span>
        <span class="b" style="font-size: 9.5pt;"><?= $e($t['words']) ?></span>
    </div>

    <h2 class="sec" style="margin-top: 8mm; font-size: 11pt;"><?= $p('sec4_title') ?></h2>
    <table class="sla">
        <tr>
            <td style="width: 50%; padding-right: 6mm;">
                <div class="caps xtiny muted"><?= $p('sla_freq') ?></div>
                <div class="b" style="margin-top: 1mm;"><?= $e($sla['frequency'] ?: '—') ?></div>
            </td>
            <td style="width: 50%;">
                <div class="caps xtiny muted"><?= $p('sla_buffer') ?></div>
                <div class="b" style="margin-top: 1mm;"><?= (int) $sla['buffer_weeks'] ?> <?= $p('sla_weeks') ?></div>
            </td>
        </tr>
        <tr>
            <td style="padding-right: 6mm;">
                <div class="caps xtiny muted"><?= $p('sla_pay') ?></div>
                <div class="b" style="margin-top: 1mm;"><?= $e($sla['payment_terms'] ?: '—') ?></div>
            </td>
            <td>
                <div class="caps xtiny muted"><?= $p('sla_empties') ?></div>
                <div class="b" style="margin-top: 1mm;"><?= $e($sla['empties'] ?: '—') ?></div>
            </td>
        </tr>
        <tr>
            <td colspan="2" style="border-bottom: 0;">
                <div class="caps xtiny muted"><?= $p('sla_validity') ?></div>
                <div class="b" style="margin-top: 1mm;">
                    <?= $p('meta_valid_until') ?> <?= $e($fdate($doc['valid_until'])) ?>
                    <span class="muted" style="font-weight: normal;">
                        (<?= (int) $doc['validity_days'] ?> <?= $p('meta_days') ?>)
                    </span>
                </div>
            </td>
        </tr>
    </table>

    <?php
    // Delivery zone and transfer of risk. "Livraison rapide, même en zone
    // dense" was a claim with nothing behind it, and nothing said who carried
    // the risk between the depot and the client's door.
    ?>
    <h2 class="sec" style="margin-top: 8mm; font-size: 11pt;"><?= $p('sec_delivery_title') ?></h2>
    <table class="sla">
        <tr>
            <td style="width: 50%; padding-right: 6mm;">
                <div class="caps xtiny muted"><?= $p('sec_delivery_zone_label') ?></div>
                <div style="margin-top: 1mm;" class="tiny"><?= $p('sec_delivery_zone') ?></div>
            </td>
            <td style="width: 50%;">
                <div class="caps xtiny muted"><?= $p('sec_delivery_cost_label') ?></div>
                <div style="margin-top: 1mm;" class="tiny"><?= $p('sec_delivery_cost') ?></div>
            </td>
        </tr>
        <tr>
            <td colspan="2" style="border-bottom: 0;">
                <div class="caps xtiny muted"><?= $p('sec_delivery_risk_label') ?></div>
                <div style="margin-top: 1mm;" class="tiny"><?= $p('sec_delivery_risk') ?></div>
            </td>
        </tr>
    </table>
</div>

<!-- ══ TERMS & SIGNATURES ══════════════════════════════════════════════════ -->
<div style="page-break-before: always;"></div>
<div class="band"><?= $p('header_p4') ?> <span style="float: right;" class="ref"><?= $e($ref_full) ?></span></div>
<div class="lpc-pad" style="padding-top: 8mm;">
    <h2 class="sec"><?= $p('sec5_title') ?></h2>
    <?php for ($i = 1; $i <= 6; $i++): ?>
        <div class="art">
            <span class="t tiny"><?= $p("tc_{$i}_title") ?></span>
            <span class="tiny"><?= $p("tc_{$i}_body") ?></span>
        </div>
    <?php endfor; ?>

    <?php
    // The schedule Article 2 presupposes. Without published figures, "des frais
    // de remplacement au tarif en vigueur" is unenforceable and, worse, reads
    // as an open-ended liability to a cautious buyer.
    if ($consigne): ?>
        <h2 class="sec no-break" style="margin-top: 7mm; font-size: 11pt;"><?= $p('sec_consigne_title') ?></h2>
        <p class="tiny"><?= $p('sec_consigne_intro') ?></p>
        <table class="items no-break" style="margin-top: 3mm;">
            <thead>
                <tr>
                    <th><?= $p('tbl_consigne_item') ?></th>
                    <th class="num" style="width: 34mm;"><?= $p('tbl_consigne_deposit') ?></th>
                    <th class="num" style="width: 34mm;"><?= $p('tbl_consigne_replace') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($consigne as $c): ?>
                <tr>
                    <td class="b"><?= $e($c['name']) ?><?php
                        if (!empty($c['format'])) {
                            echo '<span class="muted" style="font-weight:normal"> — ' . $e($c['format']) . '</span>';
                        } ?></td>
                    <td class="num"><?= $e(lpc_fcfa($c['deposit_value'], '')) ?></td>
                    <td class="num b"><?= $e(lpc_fcfa($c['replacement_value'], '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($annexes): ?>
        <h2 class="sec no-break" style="margin-top: 7mm; font-size: 11pt;"><?= $p('sec_annex_title') ?></h2>
        <p class="tiny"><?= $p('sec_annex_intro') ?></p>
        <div class="card no-break">
            <?php foreach ($annexes as $a): ?>
                <div class="tiny" style="padding: 0.8mm 0;">• <?= $e($a['label_fr']) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <table style="margin-top: 10mm;" class="no-break">
        <tr>
            <td style="width: 48%; padding-right: 8mm;">
                <div class="card" style="min-height: 32mm;">
                    <div class="caps xtiny muted"><?= $p('sig_lpc') ?></div>
                    <div class="b" style="margin-top: 2mm;"><?= $p('sig_role_lpc') ?></div>
                    <div style="margin-top: 12mm; border-top: 0.5pt solid #9CA3AF; padding-top: 1.5mm;">
                        <div class="caps xtiny muted"><?= $p('sig_prep') ?></div>
                        <div class="b" style="margin-top: 1mm; color: <?= $brand ?>;"><?= $e($doc['sales_rep']) ?></div>
                        <div class="xtiny muted"><?= $e($fdate($doc['date'])) ?></div>
                    </div>
                </div>
            </td>
            <td style="width: 52%;">
                <div style="min-height: 32mm;">
                    <div class="caps xtiny muted"><?= $p('sig_client') ?></div>
                    <div style="margin-top: 16mm; border-top: 0.5pt solid #9CA3AF; padding-top: 1.5mm;">
                        <div class="b" style="font-size: 10pt;"><?= $e($client['name']) ?></div>
                        <?php if (!empty($client['contact'])): ?>
                            <div class="tiny muted"><?= $e($client['contact']) ?></div>
                        <?php endif; ?>
                        <div class="xtiny muted" style="margin-top: 1.5mm;"><?= $p('sig_date_client') ?></div>
                    </div>
                </div>
            </td>
        </tr>
    </table>
</div>

<?= lpc_document_footer() ?>

</body></html>
    <?php
    return (string) ob_get_clean();
}

function lpc_render_payslip_body(array $doc): string
{
    $r  = $doc['raw'] ?? [];
    $e  = $doc['employee'];
    ob_start(); ?>
    <h2>Employé</h2>
    <div class="box">
        <div style="font-weight: bold; font-size: 11pt;"><?= htmlspecialchars($e['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="muted">
            N° CNPS : <?= htmlspecialchars($e['cnps'] ?? '—', ENT_QUOTES, 'UTF-8') ?><br>
            Situation : <?= htmlspecialchars($e['marital'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
            &nbsp; · &nbsp; Personnes à charge : <?= (int) ($e['dependents'] ?? 0) ?>
        </div>
    </div>

    <h2>Détail des calculs</h2>
    <table class="items-table"><thead><tr><th>Rubrique</th><th class="num">Montant (FCFA)</th></tr></thead><tbody>
        <tr><td>Salaire de base</td><td class="num"><?= lpc_fcfa((float)($r['base_salary'] ?? 0), '') ?></td></tr>
        <tr><td>Primes</td><td class="num"><?= lpc_fcfa((float)($r['bonuses'] ?? 0), '') ?></td></tr>
        <tr style="font-weight: bold; background: #F3F4F6;"><td>Salaire brut</td><td class="num"><?= lpc_fcfa((float)($r['gross_salary'] ?? 0), '') ?></td></tr>
        <tr><td>Base imposable (post frais pro./abattement)</td><td class="num"><?= lpc_fcfa((float)($r['taxable_base'] ?? 0), '') ?></td></tr>
        <tr><td>CNPS (part salariale 4,2%)</td><td class="num">-<?= lpc_fcfa((float)($r['cnps_employee'] ?? 0), '') ?></td></tr>
        <tr><td>IRPP</td><td class="num">-<?= lpc_fcfa((float)($r['irpp'] ?? 0), '') ?></td></tr>
        <tr><td>CFC (part salariale 1%)</td><td class="num">-<?= lpc_fcfa((float)($r['cfc'] ?? 0), '') ?></td></tr>
        <tr><td>CAC (10% de l'IRPP)</td><td class="num">-<?= lpc_fcfa((float)($r['cac'] ?? 0), '') ?></td></tr>
        <tr><td>CRTV (Redevance Audio-Visuelle)</td><td class="num">-<?= lpc_fcfa((float)($r['crtv'] ?? 0), '') ?></td></tr>
        <tr><td>TDL</td><td class="num">-<?= lpc_fcfa((float)($r['tdl'] ?? 0), '') ?></td></tr>
        <tr><td>Acomptes déduits</td><td class="num">-<?= lpc_fcfa((float)($r['advances_deducted'] ?? 0), '') ?></td></tr>
        <tr><td>Dettes chauffeur déduites</td><td class="num">-<?= lpc_fcfa((float)($r['driver_debt_deducted'] ?? 0), '') ?></td></tr>
        <tr><td>Absences</td><td class="num">-<?= lpc_fcfa((float)($r['absences_deducted'] ?? 0), '') ?></td></tr>
        <tr style="font-weight: bold; background: #ECFDF5; color: #065F46;"><td>NET À PAYER</td><td class="num"><?= lpc_fcfa((float)($r['net_pay'] ?? 0), '') ?></td></tr>
    </tbody></table>

    <h2>Charges patronales</h2>
    <table class="items-table"><thead><tr><th>Rubrique</th><th class="num">Montant (FCFA)</th></tr></thead><tbody>
        <tr><td>CNPS (part patronale 16,2%)</td><td class="num"><?= lpc_fcfa((float)($r['cnps_employer'] ?? 0), '') ?></td></tr>
    </tbody></table>

    <div class="muted" style="margin-top: 12pt;">
        Mode de paiement : <?= strtoupper(htmlspecialchars($r['payment_method'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
        &nbsp; · &nbsp; Statut : <?= strtoupper(htmlspecialchars($r['status'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** Very simple amount-in-words in French — good enough for FCFA integer amounts. */
function lpc_amount_in_words(float $amount): string
{
    $amount = (int) round($amount);
    if (class_exists(NumberFormatter::class)) {
        try {
            $fmt = new NumberFormatter('fr_FR', NumberFormatter::SPELLOUT);
            return ucfirst($fmt->format($amount)) . ' Francs CFA';
        } catch (Throwable $e) { /* fall through */ }
    }
    return number_format($amount, 0, ',', ' ') . ' Francs CFA';
}
