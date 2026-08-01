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
// Sprint 10: the devis one-pager's internal sign-off (migration 048) and its
// verification QR. Both degrade to a harmless "unsigned" / text-fallback
// render if their migration hasn't run or their composer package isn't
// installed yet — see the docblocks in each file — so neither can turn a
// missing deploy step into a broken document.
require_once __DIR__ . '/../classes/DocumentSignature.php';
require_once __DIR__ . '/qr.php';

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
/**
 * Load a document by token using the DocumentSignature slug vocabulary
 * (quote / cre / bl / facture / bon_commande / payslip) rather than this
 * file's older one (quote / cre / delivery / invoice / po / payslip).
 *
 * WHY IT EXISTS
 *   The rich HTML pages under public/documents/ fetch their FIGURES over the
 *   API and render them with JavaScript, so they have no $doc on the server.
 *   The signature block cannot work that way: whether a signature is still
 *   valid depends on hashing the document server-side, and a client-supplied
 *   payload could be tampered with before hashing. Those pages therefore call
 *   this to load the row themselves before requiring
 *   includes/components/signature_block.php.
 *
 *   It lives HERE and not in that partial because callers invoke it to decide
 *   whether to require the partial at all — defining it there would be too
 *   late, and PHP would fatal on an undefined function.
 *
 * Returns null on any failure (unknown slug, missing token, DB down) so the
 * caller renders the unsigned state rather than failing the whole page.
 *
 * @return array<string,mixed>|null
 */
function lpc_signature_doc(string $type, string $token): ?array
{
    static $map = [
        'quote'        => 'quote',
        'cre'          => 'cre',
        'bl'           => 'delivery',
        'facture'      => 'invoice',
        'bon_commande' => 'po',
        'payslip'      => 'payslip',
    ];
    $token = trim($token);
    if ($token === '' || !isset($map[$type])) return null;
    try {
        $db = Database::getInstance()->getConnection();
        return lpc_load_document($db, $map[$type], $token) ?: null;
    } catch (Throwable $e) {
        error_log('lpc_signature_doc(' . $type . '): ' . $e->getMessage());
        return null;
    }
}

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
            // Sprint 11: the CRE loader used to fetch the bare cre_documents
            // row, leaving lpc_normalize_cre() with no client name and no
            // items. That was survivable while the CRE PDF printed its own
            // figures from `raw`, but DocumentSignature::canonicalPayload_cre()
            // hashes client + items — with both empty, every CRE signature
            // would have hashed the same near-empty payload and attested to
            // nothing. Client and lines are now joined in.
            $stmt = $db->prepare("
                SELECT d.*, c.name AS client_name, c.address AS client_address,
                       c.niu AS client_niu, c.phone AS client_phone,
                       s.name AS site_name
                  FROM cre_documents d
                  JOIN clients c ON c.id = d.client_id
                  LEFT JOIN client_sites s ON s.id = d.site_id
                 WHERE d.token = ? LIMIT 1
            ");
            $stmt->execute([$token]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $creItems = $db->prepare("
                SELECT ci.quantity, p.name AS product_name, p.bottle_size, p.has_cork
                  FROM cre_items ci
                  JOIN products p ON p.id = ci.product_id
                 WHERE ci.cre_document_id = ? AND ci.quantity > 0
                 ORDER BY ci.id ASC
            ");
            $creItems->execute([$r['id']]);
            return lpc_normalize_cre($r, $creItems->fetchAll(PDO::FETCH_ASSOC));

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
            // Sprint 11: payment_collected and client_observation are on the
            // deliveries row (the loader selects d.*) but used to be dropped
            // here. DocumentSignature::canonicalPayload_bl() hashes both —
            // the cash handed to the driver and any reserves the customer
            // noted are precisely what a delivery dispute turns on, so they
            // have to be inside the signed payload, not alongside it.
            'payment_collected'=>(float)($r['payment_collected'] ?? 0),
            'observations'=>(string)($r['client_observation'] ?? ''),
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
/**
 * A CRE has no money on it — it certifies that N empty containers came back,
 * nothing more. So totals stay zeroed while items and client are real: those
 * two are what DocumentSignature::canonicalPayload_cre() hashes, and what a
 * dispute would actually turn on.
 */
function lpc_normalize_cre(array $r, array $lines = []): array {
    $items = [];
    foreach ($lines as $l) {
        $items[] = [
            'name'   => $l['product_name'] ?? '',
            'format' => trim((string)($l['bottle_size'] ?? '')
                      . (isset($l['has_cork']) ? ($l['has_cork'] ? ' avec bouchon' : ' sans bouchon') : '')),
            'qty'        => (float)($l['quantity'] ?? 0),
            'unit_price' => 0.0,
            'total'      => 0.0,
        ];
    }
    return ['record_id'=>(int)$r['id'], 'reference'=>$r['reference'] ?? ('CRE-'.$r['id']),
            'date'=>$r['date'] ?? $r['created_at'],
            'client'=>['name'=>$r['client_name'] ?? '', 'address'=>$r['client_address'] ?? '',
                       'niu'=>$r['client_niu'] ?? '', 'phone'=>$r['client_phone'] ?? ''],
            'site_name'=>$r['site_name'] ?? '',
            'items'=>$items, 'totals'=>['subtotal'=>0,'discount'=>0,'tax'=>0,'grand_total'=>0,'words'=>''],
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

    <?php
    // Universal signature block — the same partial every document uses.
    // Map the internal renderer's type slug to the DocumentSignature slug.
    // Anything not in this map (currently 'audit') has no signature area.
    $sig_type_map = [
        'invoice'  => 'facture',
        'delivery' => 'bl',
        'po'       => 'bon_commande',
        'quote'    => 'quote',
        'cre'      => 'cre',
        'payslip'  => 'payslip',
    ];
    if (isset($sig_type_map[$type]) && !empty($doc['record_id'])) {
        $sig_type    = $sig_type_map[$type];
        $sig_doc_id  = (int) $doc['record_id'];
        $sig_doc     = $doc;
        $sig_context = 'pdf';
        require __DIR__ . '/../components/signature_block.php';
    }
    ?>

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

<!-- ── Universal signature block (internal + external) ─────────────────────── -->
<div class="lpc-pad" style="margin-top: 6mm;">
<?php
// The "Certifié Conforme" stamp above records WHO CREATED the invoice at
// issuance time — it's not a signature. This block below is the real
// signature area, shared with every other document type via
// includes/components/signature_block.php.
$sig_type    = 'facture';
$sig_doc_id  = (int) ($doc['record_id'] ?? 0);
$sig_doc     = $doc;
$sig_context = 'pdf';
require __DIR__ . '/../components/signature_block.php';
?>
</div>

<?= lpc_document_footer() ?>

</body></html>
    <?php
    return (string) ob_get_clean();
}

/**
 * The commercial offer, rendered for dompdf — ONE A4 PAGE, ALWAYS.
 * -----------------------------------------------------------------------------
 * There are two devis deliverables and they are not interchangeable:
 *
 *   public/documents/quote.php        the 4-page bilingual proposal. The HTML IS
 *                                    the document — the prospect opens it from a
 *                                    WhatsApp link, reads it in their language,
 *                                    and exports it themselves via html2canvas.
 *   …?pdf=1  (this function)          the one-page "offre commerciale". What a
 *                                    buyer prints, initials and walks into a
 *                                    procurement meeting with.
 *
 * A previous pass rebuilt this as a 4-page dompdf replica of the HTML, with
 * three `page-break-before: always` rules. That made the two deliverables
 * duplicates of each other and left the one-pager with no implementation at
 * all. This is the one-pager, restored.
 *
 * SPRINT 10 — LAYOUT REBUILD + INTERNAL SIGNATURE + QR VERIFICATION
 * -------------------------------------------------------------------------
 * Rebuilt against a mock the sales team hand-approved, replacing the shared
 * lpc_document_header()/lpc_document_footer() with a layout specific to this
 * one document:
 *   - the reference / date / validity used to sit in a narrow right-aligned
 *     card and wrapped ("RÉFÉRENCE : DEV-2607-E226" onto two lines at some
 *     lengths). It is now a single full-width bar directly under the header
 *     band, one line, `white-space: nowrap` on the value so it cannot wrap
 *     again regardless of reference length.
 *   - RCCM / NIU used to appear twice — once in the compact identity block
 *     under the header, once in the statutory footer. It now prints ONCE,
 *     in the footer only. The header carries nothing but the logo and the
 *     document title, which is also what bought back the height this
 *     spends on the signature block below.
 *   - the LPC-side signature is now a real attestation, not a blank rule to
 *     sign by hand: DocumentSignature (migration 048) records a content hash
 *     of the priced figures, the logged-in staff member's own name and role
 *     (UserProfile — never a value typed into a form), and a verify_token. If
 *     an active signature exists for the CURRENT figures, this template
 *     prints a stamp — signer, role, timestamp, a hash fragment — plus a QR
 *     to /verify/{token} (public/verify.php). If the document was signed and
 *     then edited, the stored hash no longer matches and this falls back to
 *     the unsigned appearance automatically (see DocumentSignature::getActive);
 *     nothing here has to know the difference between "never signed" and
 *     "signed, then changed".
 *
 * ONE PAGE IS A HARD CONSTRAINT, NOT AN ASPIRATION
 * ------------------------------------------------
 * Nothing here may introduce a page break, so the height of every block is
 * budgeted. A4 is 297 mm and `body` reserves 17 mm at the foot for the fixed
 * statutory footer (now two lines — name/legal mentions, then address/contact
 * — since it carries the identity block the header no longer does), leaving
 * 280 mm. Measured off the CSS below, at the worst case for each block
 * (company name wrapping to two lines, the longest fiscal ladder — HT +
 * accises + TVA + TTC — and four lines of small print):
 *
 *     rule + top padding .........  10 mm
 *     header band (logo | title) .  24 mm   logo capped at 24mm, not 17mm
 *     meta bar (one line, always).   8 mm   was a wrapping card; now a bar
 *     client card row ............  38 mm
 *     h2 + items table header ....  16 mm
 *     priced rows ................ 6.4 mm each  <- the only variable block
 *     ladder (words ride beside)..  31 mm
 *     h2 + conditions + fine .....  35 mm
 *     signatures (stamp + QR) ....  34 mm   was 30mm; +4mm for the stamp/QR
 *     ---------------------------------------
 *     fixed ...................... 196 mm     -> 84 mm for priced rows
 *
 * 84 mm buys 13 plain rows. The clamp below is still 9 — deliberately left at
 * the old, more pessimistic figure rather than raised to match, because the
 * 40-line stress case in scripts/tests/quote_onepager.test.php asserts the
 * exact "8 printed + 1 summed (32)" shape of the spill row, and changing the
 * clamp changes that count. The extra headroom this layout buys is margin,
 * not an invitation to loosen the clamp casually — if you do want to raise
 * it, update the test's expectations deliberately, in the same change.
 * Past 9 the tail is summed into a single "autres articles (N)" row: the ladder
 * still reconciles to the same total, and the full itemisation is one click away
 * in the HTML proposal. Truncating silently would be worse than spilling onto
 * page 2; summing visibly is not.
 *
 * If you change a font size, a padding or a margin in here, re-run
 * scripts/tests/quote_onepager.test.php. It renders nine records through dompdf
 * — including 40 line items and a two-line client name — and asserts one page
 * each. The budget above is arithmetic; that test is the fact. I have not been
 * able to execute it myself while writing this (no PHP runtime in this
 * environment) — run it before you trust this comment over the test's output.
 *
 * What the 4-page proposal carries and this deliberately does not: the cover
 * title block, the company profile and capability prose, the reference logos,
 * the six articles in full, the consigne schedule and the annex list. Their
 * existence is asserted in one contractual line instead, so a signed one-pager
 * still incorporates them.
 *
 * What it does carry, because a signed devis is a contract under OHADA and a
 * buyer cannot approve a figure they cannot classify: the shared letterhead and
 * legal identity, the client's own identifiers, the priced lines, the full
 * fiscal ladder (HT -> accises -> TVA or its exemption basis -> TTC), the total
 * in words, the validity date, the four commercial terms that decide the deal,
 * and Bon pour accord.
 *
 * Written against dompdf's CSS subset: tables and floats, no flexbox, no grid.
 * The signature stamp's rotation (`transform: rotate(-2deg)`) follows the same
 * pattern already used for the generic document stamp further up this file
 * (search `.stamp`) rather than introducing a new untested technique — if
 * dompdf silently ignores the rotation the stamp still reads fine unrotated.
 *
 * Wording comes from lpc_proposal_text() so the Proposal Studio keeps editing
 * it, and every figure comes from the proposals row. Two exceptions inherited
 * from the previous template and left alone here: the "Droit d'accises" ladder
 * label, and the fact that tbl_grand's seeded value carries its own trailing
 * colon. Both are shared with the facture and worth fixing together, not in a
 * layout change.
 */
function lpc_render_quote_pdf_html(array $doc, array $lh, string $lhLogo, string $token): string
{
    // $token is unused here, as it is in lpc_render_invoice_pdf_html: the
    // signature is uniform across the per-type templates so the dispatcher can
    // call any of them the same way. $lh['accent'] is likewise not used — the
    // accent was the 4-page replica's cover flourish, and a document meant to be
    // photocopied and faxed reads better in one colour.
    $t      = $doc['totals'];
    $client = $doc['client'];
    $sla    = $doc['sla'];
    $brand  = $lh['color'];

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
    // ($fdatetime lived here until Sprint 11. Its only consumer was the
    //  inline signature stamp, which moved to
    //  includes/components/signature_block.php and formats its own dates.)

    $consigne = $doc['consigne'] ?? [];
    $annexes  = $doc['annexes'] ?? [];

    // The reference a client quotes back at you has to identify the version they
    // accepted. Rev. 1 is implicit and not printed. It rides on the meta box's
    // référence row rather than taking a fourth row of its own — the budget
    // above allows three.
    $ref_full = $doc['reference'] . ($doc['revision'] > 1 ? ' rév. ' . $doc['revision'] : '');

    // ---- internal sign-off (migration 048) -----------------------------
    // null when never signed, revoked, or signed-then-edited (the stored hash
    // no longer matches these exact figures) — DocumentSignature::getActive()
    // makes those three cases indistinguishable on purpose: a document whose
    // figures changed after signing must render exactly as unsigned, not as
    // "signed" with stale numbers.
    //
    // Sprint 11: the lookup, the stamp markup and the QR all moved into
    // includes/components/signature_block.php — the shared partial every
    // document type now uses. This function no longer resolves a signature
    // itself; see the SIGNATURES section near the bottom of the template.
    // The partial catches its own DB errors, so a failed attestation lookup
    // still cannot break this render.
    //
    // Historical note worth keeping: do NOT name a catch variable `$e`
    // anywhere in this function. `$e` is the HTML-escaping closure defined
    // above and PHP catch variables are not block-scoped, so `catch (
    // Throwable $e)` would silently replace the closure for the rest of the
    // function and every $e(...) call below would fatal.

    // ---- the one variable-height block, clamped ----------------------------
    // 9 = the pessimistic row count from the height budget in the docblock. The
    // table never renders more than 9 rows: either 9 priced ones, or 8 plus the
    // summed tail. $t['subtotal'] is untouched, so the ladder still adds up.
    $max_rows = 9;
    $rows     = $doc['items'];
    $spill    = [];
    if (count($rows) > $max_rows) {
        $spill = array_slice($rows, $max_rows - 1);
        $rows  = array_slice($rows, 0, $max_rows - 1);
    }
    $spill_total = array_sum(array_column($spill, 'total'));

    ob_start(); ?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8"><title>Devis <?= $e($doc['reference']) ?></title>
<style>
    /* One page. No rule in here may create a break, and nothing declares
       page-break-before — see the height budget in the function docblock. */
    @page { margin: 0; }
    /* 17mm clears the fixed footer: it sits at bottom 7mm and is two lines of
       6pt text over a padded rule, so it occupies ~15mm top-to-bottom. */
    body  { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1F2937;
            margin: 0 0 17mm 0; }

    .lpc-rule { height: 4mm; background: <?= $brand ?>; }
    .lpc-pad  { padding: 0 14mm; }

    /* ---- header band: logo | title ---------------------------------------- */
    .dhead        { width: 100%; border-collapse: collapse; }
    .dhead td     { vertical-align: middle; padding: 0; }
    .dhead-logo   { width: 58%; }
    .dhead-title  { width: 42%; text-align: right; }
    .doctitle     { font-size: 25pt; font-weight: bold; margin: 0; letter-spacing: -0.6pt;
                    color: #111827; text-transform: uppercase; }

    /* ---- meta bar: référence / date / validité — ALWAYS one line ----------
       A full-width bar under the header, not the narrow right-aligned card
       this replaces: the card wrapped "RÉFÉRENCE : DEV-2607-E226" onto two
       lines at some reference lengths. white-space:nowrap on the value is the
       actual guarantee; the width is just what makes nowrap never necessary. */
    .mbar          { width: 100%; border-collapse: collapse; margin-top: 3mm;
                     background: #F9FAFB; border: 0.5pt solid #E5E7EB; border-radius: 2mm; }
    .mbar td       { padding: 2mm 4mm; font-size: 8pt; white-space: nowrap;
                     border-right: 0.5pt solid #E5E7EB; }
    .mbar td.last  { border-right: none; }
    .mbar .k       { color: #9CA3AF; font-size: 6.5pt; text-transform: uppercase;
                     letter-spacing: 0.6pt; font-weight: bold; margin-right: 1.5mm; }
    .mbar .v       { font-weight: bold; color: #111827; }
    .mbar .v.danger{ color: #B91C1C; }

    /* ---- signature stamp ---------------------------------------------------
       Nested elements with a fixed mm border-radius (= half the box side)
       rather than border-radius: 50% — an explicit radius is the more-proven
       path in dompdf. The rotation follows the existing `.stamp` pattern
       elsewhere in this file (search transform: rotate) rather than a new
       technique; if a given dompdf build ignores it the stamp still reads
       fine unrotated. */
    .stampring    { width: 21mm; height: 21mm; border: 1.1pt solid <?= $brand ?>;
                    border-radius: 10.5mm; text-align: center; padding-top: 3.2mm;
                    transform: rotate(-4deg); }
    .stampcheck   { font-size: 15pt; font-weight: bold; color: <?= $brand ?>; line-height: 1; }
    .stampcaption { font-size: 5.3pt; font-weight: bold; letter-spacing: 0.5pt;
                    color: <?= $brand ?>; text-transform: uppercase; margin-top: 1mm; line-height: 1.3; }

    /* ---- statutory footer ---------------------------------------------------
       Left-aligned and capped at 145mm — deliberately NOT centred full-width
       — so it can never collide with the automatic page-counter dompdf draws
       in the bottom-right corner (PdfRenderer::fromHtml, canvas_width-100px
       from the left; see lpc_serve_document_pdf). A long legal-mentions line
       in a centred/full-width footer could reach into that corner; this
       footer's right edge stops 51mm short of the page edge, well clear. */
    .dfoot        { position: fixed; bottom: 7mm; left: 14mm; width: 145mm;
                    color: #9CA3AF; font-size: 6pt; line-height: 1.55;
                    border-top: 0.5pt solid #E5E7EB; padding-top: 1.8mm; }
    .dfoot .nm    { font-weight: bold; color: #6B7280; }

    table   { width: 100%; border-collapse: collapse; }
    td, th  { vertical-align: top; }
    .num    { text-align: right; }
    .muted  { color: #6B7280; }
    .tiny   { font-size: 7.5pt; }
    .xtiny  { font-size: 6.5pt; }
    .b      { font-weight: bold; }
    .caps   { text-transform: uppercase; letter-spacing: 0.6pt; font-weight: bold; }
    .card   { background: #F9FAFB; border: 0.5pt solid #E5E7EB; border-radius: 2mm;
              padding: 2.5mm 3mm; }

    /* Section headings earn 4mm of air above, not 6 — three of them at 6mm cost
       a priced row between them. */
    h2.sec  { font-size: 9.5pt; color: #111827; margin: 4mm 0 2mm 0; font-weight: bold;
              text-transform: uppercase; letter-spacing: 0.7pt; }

    /* 6.4mm per row at 8pt with 1.5mm padding — the divisor the row budget in the
       docblock uses. Change either and the clamp is wrong. */
    .items th { background: <?= $brand ?>; color: #fff; padding: 1.8mm;
                font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.6pt;
                text-align: left; }
    .items td { border-bottom: 0.5pt solid #E5E7EB; padding: 1.5mm 2mm; font-size: 8pt; }
    .items tr.spill td { background: #F9FAFB; font-style: italic; }

    .ladder td        { padding: 0.8mm 0; font-size: 8.5pt; }
    .ladder .grand td { border-top: 1.5pt solid #111827; padding-top: 1.6mm;
                        font-size: 11pt; font-weight: bold; }
    .ladder .exempt   { font-size: 6.5pt; font-style: italic; color: #6B7280;
                        padding-top: 0; padding-bottom: 1.2mm; }

    .terms td   { padding: 0 6mm 2mm 0; }
    /* The small print. One paragraph, 6.5pt, because it is read once by a lawyer
       and never by the buyer — but it has to be on the page. */
    .fine       { font-size: 6.5pt; color: #6B7280; line-height: 1.45; }
</style></head>
<body>

<div class="lpc-rule"></div>
<div class="lpc-pad" style="padding-top: 6mm;">
<?php
// Sprint 10: a header specific to this document rather than the shared
// lpc_document_header() — logo and title only. The legal identity moved to
// the footer (see the function docblock and the RCCM/NIU question it
// answers), and the reference/date/validity became the full-width bar below
// instead of a narrow right-aligned card — the card is what used to wrap
// "RÉFÉRENCE : DEV-2607-E226" onto two lines at some reference lengths.
?>
<table class="dhead">
    <tr>
        <td class="dhead-logo">
            <?php if ($lhLogo !== ''): ?>
                <img src="<?= $e($lhLogo) ?>" style="max-height: 24mm; max-width: 62mm;">
            <?php else: ?>
                <div style="font-size: 20pt; font-weight: bold; color: <?= $brand ?>;">
                    <?= $e($lh['name']) ?>
                </div>
            <?php endif; ?>
        </td>
        <td class="dhead-title">
            <h1 class="doctitle">Devis</h1>
        </td>
    </tr>
</table>

<?php
// One line, guaranteed: white-space:nowrap on .mbar .v (see CSS) rather than
// relying on the bar being wide enough — a guarantee, not a hope.
?>
<table class="mbar">
    <tr>
        <td><span class="k"><?= $p('meta_ref') ?></span><span class="v"><?= $e($ref_full) ?></span></td>
        <td><span class="k"><?= $p('meta_date') ?></span><span class="v"><?= $e($fdate($doc['date'])) ?></span></td>
        <td class="last"><span class="k"><?= $p('meta_valid_until') ?></span><span class="v danger"><?= $e($fdate($doc['valid_until'])) ?></span></td>
    </tr>
</table>

<!-- ══ WHO IT IS FOR, WHO WROTE IT ═════════════════════════════════════════ -->
<table style="margin-top: 4.5mm;">
    <tr>
        <td style="width: 66%; padding-right: 6mm;">
            <div class="card" style="border-left: 1.2mm solid <?= $brand ?>;">
                <div class="caps xtiny muted" style="margin-bottom: 1.2mm;"><?= $p('cover_prepared_for') ?></div>
                <div class="b" style="font-size: 11pt; color: #111827;"><?= $e($client['name']) ?></div>
                <?php if (!empty($client['contact'])): ?>
                    <div class="tiny" style="margin-top: 0.8mm;">
                        Attn : <?= $e($client['contact']) ?><?php
                        if (!empty($client['title'])) echo ' — ' . $e($client['title']); ?>
                    </div>
                <?php endif; ?>
                <?php
                // Address, phone and email share one line: three stacked lines
                // cost 8mm, which is more than a priced row is worth. Anything
                // unknown disappears rather than printing a blank field on a
                // document that leaves the building.
                $reach = array_filter([
                    preg_replace('/\s*\R\s*/u', ', ', trim((string) $client['address'])),
                    $client['phone'],
                    $client['email'],
                ]);
                if ($reach): ?>
                    <div class="muted xtiny" style="margin-top: 0.8mm;"><?= $e(implode(' · ', $reach)) ?></div>
                <?php endif; ?>
                <?php $ids = array_filter([
                        !empty($client['niu'])  ? 'NIU : '  . $client['niu']  : '',
                        !empty($client['rccm']) ? 'RCCM : ' . $client['rccm'] : '',
                      ]);
                if ($ids): ?>
                    <div class="xtiny b" style="margin-top: 1.4mm; padding-top: 1.2mm; border-top: 0.5pt solid #E5E7EB;">
                        <?= $e(implode('  |  ', $ids)) ?>
                    </div>
                <?php endif; ?>
            </div>
        </td>
        <td style="width: 34%;">
            <div class="caps xtiny muted"><?= $p('cover_prepared_by') ?></div>
            <div class="b" style="margin-top: 1mm; color: <?= $brand ?>; font-size: 10pt;">
                <?= $e($doc['sales_rep'] ?: '—') ?>
            </div>
            <div class="xtiny muted" style="margin-top: 0.8mm;">
                <?= $e($fdate($doc['date'])) ?> ·
                <?= $p('sla_validity') ?> <?= (int) $doc['validity_days'] ?> <?= $p('meta_days') ?>
            </div>
        </td>
    </tr>
</table>

<!-- ══ THE OFFER ═══════════════════════════════════════════════════════════ -->
<?php
// onepager_items_title, NOT sec3_title: sec3_title is correctly "3. …" on the
// 4-page HTML proposal (it really is page 3 of 4 there). This one-pager needs
// its own untitled-number heading — see migration 048's companion change in
// lpc_proposal_defaults() for why this is a separate key rather than a
// conditional numbering hack on the shared one.
?>
<h2 class="sec"><?= $p('onepager_items_title') ?></h2>
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
    <?php foreach ($rows as $it): ?>
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
    <?php if ($spill): ?>
        <tr class="spill">
            <td><?= $p('onepager_more_lines') ?> (<?= count($spill) ?>)</td>
            <td style="text-align: center;">—</td>
            <td class="num">—</td>
            <td class="num b"><?= $e(lpc_fcfa($spill_total, '')) ?></td>
        </tr>
    <?php endif; ?>
    <?php if (empty($rows)): ?>
        <tr><td colspan="4" class="muted" style="text-align: center; padding: 8mm 0;">Aucune ligne.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php
// The fiscal ladder. A single bare figure with no indication of HT or TTC is the
// one thing a buyer cannot work with: they can neither compare suppliers nor get
// the number through their own finance team.
?>
<table style="margin-top: 3mm;">
    <tr>
        <td style="width: 52%; padding-right: 8mm; vertical-align: bottom;">
            <p class="xtiny muted" style="font-style: italic; margin: 0 0 2mm 0;"><?= $p('tbl_note') ?></p>
            <?php
            // The words go under the note rather than in a full-width card of
            // their own: as its own strip it cost 12mm, here it costs nothing —
            // the ladder beside it is taller either way.
            ?>
            <div class="xtiny" style="border-top: 0.5pt solid #E5E7EB; padding-top: 1.5mm;">
                <span class="muted" style="font-style: italic;"><?= $p('tbl_words') ?></span>
                <span class="b"><?= $e($t['words']) ?></span>
            </div>
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

<!-- ══ THE FOUR TERMS THAT DECIDE THE DEAL ═════════════════════════════════ -->
<h2 class="sec"><?= $p('onepager_conditions_title') ?></h2>
<table class="terms">
    <tr>
        <td style="width: 25%;">
            <div class="caps xtiny muted"><?= $p('sla_freq') ?></div>
            <div class="b tiny" style="margin-top: 1mm;"><?= $e($sla['frequency'] ?: '—') ?></div>
        </td>
        <td style="width: 25%;">
            <div class="caps xtiny muted"><?= $p('sla_pay') ?></div>
            <div class="b tiny" style="margin-top: 1mm;"><?= $e($sla['payment_terms'] ?: '—') ?></div>
        </td>
        <td style="width: 25%;">
            <div class="caps xtiny muted"><?= $p('sla_buffer') ?></div>
            <div class="b tiny" style="margin-top: 1mm;"><?= (int) $sla['buffer_weeks'] ?> <?= $p('sla_weeks') ?></div>
        </td>
        <td style="width: 25%; padding-right: 0;">
            <div class="caps xtiny muted"><?= $p('sla_empties') ?></div>
            <div class="b tiny" style="margin-top: 1mm;"><?= $e($sla['empties'] ?: '—') ?></div>
        </td>
    </tr>
</table>

<?php
// The small print, as ONE paragraph. Two stacked blocks cost 15mm; run together
// they cost 9mm, and nobody reads them differently for it.
//
// The second half is load-bearing: the articles, the consigne schedule and the
// annexes are not printed on a one-pager, so this clause is what incorporates
// them when the client signs Bon pour accord. It names what is being
// incorporated and where to read it, which a bare "voir CGV" would not.
$carried = [];
if ($consigne) $carried[] = lpc_proposal_text('sec_consigne_title', 'fr');
if ($annexes)  $carried[] = lpc_proposal_text('sec_annex_title', 'fr');
?>
<div class="fine">
    <span class="b"><?= $p('sec_delivery_risk_label') ?> :</span> <?= $p('sec_delivery_risk') ?>
    <?= $p('onepager_terms_ref') ?>
    <?php if ($carried): ?><span class="b"><?= $e(implode(' · ', $carried)) ?>.</span><?php endif; ?>
</div>

<!-- ══ SIGNATURES ══════════════════════════════════════════════════════════ -->
<?php
// The devis used to render its own bespoke stamp+QR block here (Sprint 10);
// that block is now the shared includes/components/signature_block.php —
// the same partial every document uses. Setting $sig_* variables and
// require'ing the file emits the internal + external signature area in the
// house style. If either party has not signed, the partial renders a blank
// rule with a "Nom / fonction / cachet" hint — same as before.
$sig_type    = 'quote';
$sig_doc_id  = (int) ($doc['record_id'] ?? 0);
$sig_doc     = $doc;
$sig_context = 'pdf';
// The devis keeps its Proposal-Studio-editable column headings — an admin
// who rewords "Bon pour Accord (Le Client) :" in the Studio still controls
// what prints here, exactly as before this block was shared.
$sig_labels  = [
    'internal' => lpc_proposal_text('sig_lpc', 'fr'),
    'external' => lpc_proposal_text('sig_client', 'fr'),
];
require __DIR__ . '/../components/signature_block.php';
?>
</div>

<?php
// The statutory footer — RCCM/NIU/capital/address/contact, ONCE, here only
// (see the function docblock for the decision not to also print it in the
// header). Custom rather than lpc_document_footer(): that helper renders one
// centred line; this is two, left-aligned and width-capped so it can never
// reach the automatic page-counter dompdf draws in the bottom-right corner —
// see .dfoot in the stylesheet above for the exact reasoning.
?>
<div class="dfoot">
    <div class="nm"><?= $e($lh['name']) ?></div>
    <?= $e(implode(' · ', array_filter([$lh['mentions'], $lh['address']]))) ?><br>
    <?= $e($lh['contact']) ?>
</div>

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
