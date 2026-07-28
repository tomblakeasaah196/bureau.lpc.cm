<?php
/**
 * includes/functions/document_pdf.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 4 (parallel) · shared PDF dispatcher.
 *
 * Called from each public/documents/*.php file:
 *
 *   require_once __DIR__ . '/../../includes/bootstrap.php';
 *   require_once __DIR__ . '/../../includes/functions/document_pdf.php';
 *   lpc_serve_document_pdf('invoice');   // exits after streaming
 *
 * The dispatcher looks at $_GET['token'] and $_GET['html']:
 *   - html=1 → returns immediately, letting the calling file render its
 *              existing legacy HTML view for debugging.
 *   - html=0/absent → loads the source record, builds the HTML from an
 *              inline template, hands to PdfRenderer, streams the PDF, exits.
 *
 * Cache: PdfRenderer::saveDocument SHA-256's the source HTML — if nothing
 * has changed we serve the cached file straight from /uploads/documents/
 * and emit `X-LPC-Cache: HIT` for verification scripts.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../classes/PdfRenderer.php';

/**
 * Public entry point. Types map 1:1 to the pdf_documents.type enum.
 * The calling public/documents/*.php file should call this immediately after
 * its bootstrap require — if the function returns, HTML rendering can proceed.
 */
function lpc_serve_document_pdf(string $type): void
{
    // Explicit HTML fallback for debugging.
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
            $stmt = $db->prepare("
                SELECT i.*, c.name AS client_name, c.address AS client_address, c.niu AS client_niu, c.phone AS client_phone
                  FROM invoices i JOIN clients c ON c.id = i.client_id
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

function lpc_normalize_invoice(array $r, array $lines): array {
    $sub = 0.0; $items = [];
    foreach ($lines as $l) {
        $q = (float)$l['quantity']; $u = (float)$l['unit_price']; $t = $q * $u; $sub += $t;
        $items[] = ['name'=>$l['product_name'] ?? '', 'format'=>$l['format'] ?? '', 'qty'=>$q, 'unit_price'=>$u, 'total'=>$t];
    }
    $disc = (float)($r['discount_amount'] ?? 0);
    $tax  = (float)($r['tax_amount'] ?? 0);
    $grand = max(0.0, $sub - $disc + $tax);
    return [
        'record_id' => (int)$r['id'], 'reference' => $r['reference'], 'date' => $r['date'] ?? $r['created_at'],
        'client' => ['name'=>$r['client_name'] ?? '', 'address'=>$r['client_address'] ?? '', 'niu'=>$r['client_niu'] ?? '', 'phone'=>$r['client_phone'] ?? ''],
        'items' => $items, 'totals' => ['subtotal'=>$sub,'discount'=>$disc,'tax'=>$tax,'grand_total'=>$grand,'words'=>lpc_amount_in_words($grand)],
        'notes' => $r['notes'] ?? '', 'source_updated_at' => $r['updated_at'] ?? $r['created_at'] ?? null,
        'status' => $r['status'] ?? 'unpaid',
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
function lpc_normalize_quote(array $r, array $lines): array {
    $items = [];
    foreach ($lines as $l) {
        $q = (float)($l['quantity'] ?? 0); $u = (float)($l['unit_price'] ?? 0);
        $items[] = ['name'=>$l['product_name'] ?? $l['name'] ?? '', 'qty'=>$q, 'unit_price'=>$u, 'total'=>$q*$u];
    }
    $sub = array_sum(array_column($items,'total'));
    $grand = (float)($r['total_amount'] ?? $sub);
    return ['record_id'=>(int)$r['id'], 'reference'=>$r['reference'] ?? ('QUOTE-'.$r['id']),
            'date'=>$r['date'] ?? $r['created_at'],
            'client'=>['name'=>$r['client_name'] ?? $r['prospect_name'] ?? '', 'address'=>$r['client_address'] ?? '', 'niu'=>'', 'phone'=>$r['client_phone'] ?? ''],
            'items'=>$items, 'totals'=>['subtotal'=>$sub, 'discount'=>0, 'tax'=>0, 'grand_total'=>$grand, 'words'=>lpc_amount_in_words($grand)],
            'notes'=>$r['notes'] ?? '', 'source_updated_at'=>$r['updated_at'] ?? $r['created_at'] ?? null];
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
