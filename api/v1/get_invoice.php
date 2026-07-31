<?php
/**
 * api/v1/get_invoice.php
 * -----------------------------------------------------------------------------
 * Public endpoint (token-gated). Feeds public/documents/facture.php.
 *
 * Sprint 9 — the payload grew to carry everything a compliant Cameroonian
 * invoice must print. It used to return the seller's identity not at all: the
 * facture.php template had the letterhead hardcoded, including a placeholder
 * NIU ("M123456789012A") and RC ("DLA/2026/B/1234") that went out on real
 * customer invoices. Both now come from company_profile via CompanyProfile,
 * which is the single source Administration → Paramètres → Entreprise edits.
 *
 * Also added: the client's NIU (mandatory for the transaction to be deductible
 * B2B), and the full fiscal ladder — droit d'accises, TVA or its exemption
 * basis, précompte sur achats, AIR — so the totals block can show how the TTC
 * and the net transferable were actually reached instead of a bare 0 %.
 *
 * Sprint 9 also removed `error_reporting(E_ALL)` from this file. On a public
 * endpoint that is a disclosure risk, and bootstrap.php already sets the
 * environment-appropriate level.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/CompanyProfile.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// 1. Verify Token Presence
if (!isset($_GET['token']) || empty($_GET['token'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Token de facture invalide ou manquant.']);
    exit;
}

$token = trim($_GET['token']);

/**
 * Amount in words. OHADA practice closes an invoice with the total spelled
 * out, because the figure alone is alterable.
 *
 * intl's SPELLOUT stops at the integer, so any centimes are spelled separately.
 * FCFA has no subdivision in circulation, but a rounded total can still carry a
 * fraction, and "et cinquante centimes" beats silently truncating it.
 */
function getAmountInWords($amount) {
    $amount   = round((float) $amount, 2);
    $integer  = (int) floor($amount);
    $centimes = (int) round(($amount - $integer) * 100);

    if (class_exists('NumberFormatter')) {
        $formatter = new NumberFormatter('fr', NumberFormatter::SPELLOUT);
        $words = ucfirst($formatter->format($integer)) . ' francs CFA';
        if ($centimes > 0) {
            $words .= ' et ' . $formatter->format($centimes) . ' centimes';
        }
        return $words;
    }

    // intl is not guaranteed on shared cPanel hosting — degrade to the figure
    // rather than emit a half-spelled string.
    return number_format($amount, 0, ',', ' ') . ' francs CFA';
}

/** Read a column a migration may not have applied yet, without notices. */
function invcol(array $row, string $key, $default = null) {
    return array_key_exists($key, $row) && $row[$key] !== null ? $row[$key] : $default;
}

try {
    $db = Database::getInstance()->getConnection();

    // 2. Fetch Master Invoice, Client, and Creator Data
    $stmt = $db->prepare("
        SELECT
            i.*,
            c.name as client_name, c.address as client_address, c.phone as client_phone,
            c.email as client_email, c.niu as client_niu, c.rc as client_rc, c.type as client_type,
            cr.first_name as creator_fn, cr.last_name as creator_ln,
            r.name as role_name
        FROM invoices i
        JOIN clients c ON i.client_id = c.id
        JOIN users cr ON i.created_by = cr.id
        LEFT JOIN roles r ON cr.role_id = r.id
        WHERE i.token = :token
    ");

    $stmt->execute(['token' => $token]);
    $invData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invData) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Facture introuvable ou lien expiré.']);
        exit;
    }

    $invoice_id = $invData['id'];

    // 3. Fetch Validated Payments to calculate Balance
    $stmtPay = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = 'validated'");
    $stmtPay->execute([$invoice_id]);
    $paid_amount = (float) $stmtPay->fetchColumn();

    $balance = max(0, (float)$invData['total_amount'] - $paid_amount);

    // 4. Fetch Line Items
    $stmtItems = $db->prepare("
        SELECT description, quantity, unit_price, total_price
        FROM invoice_items
        WHERE invoice_id = ?
        ORDER BY id ASC
    ");
    $stmtItems->execute([$invoice_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // 4b. Fetch the BL(s) this invoice was generated from — replaces the old
    // generic "conditions de règlement" boilerplate with the actual delivery
    // references and quantities the client can reconcile against their own
    // stock. Quantity is what the client actually accepted
    // (delivered_quantity), falling back to the dispatched quantity for any
    // item that has not been through the return/reconciliation step yet.
    $stmtBL = $db->prepare("
        SELECT d.reference,
               COALESCE(SUM(COALESCE(di.delivered_quantity, di.quantity)), 0) AS total_qty
        FROM invoice_deliveries invd
        JOIN deliveries d ON d.id = invd.delivery_id
        LEFT JOIN delivery_items di ON di.delivery_id = d.id
        WHERE invd.invoice_id = ?
        GROUP BY d.id, d.reference
        ORDER BY d.date ASC, d.id ASC
    ");
    $stmtBL->execute([$invoice_id]);
    $deliveries = $stmtBL->fetchAll(PDO::FETCH_ASSOC);

    // 5. Format Data for the Frontend
    $creator_name = $invData['creator_fn'] . ' ' . substr($invData['creator_ln'], 0, 1) . '.';
    $role_name    = $invData['role_name'] ?? 'Finance / Comptabilité';

    // Cryptographic verification hash for the stamp
    $verification_hash = strtoupper(substr(hash('sha256', $invData['token'] . $invData['created_at']), 0, 16));

    // --- fiscal ladder --------------------------------------------------------
    // The order is not cosmetic: accises are assessed on the HT and then join
    // the TVA base, so TVA is charged on (HT + accises). Précompte and AIR are
    // withholdings — they do not change the TTC the client owes, they change
    // how much of it travels to us rather than straight to the DGI.
    $subtotal   = (float) $invData['subtotal'];
    $excise     = (float) invcol($invData, 'excise_amount', 0);
    $tva_amount = (float) $invData['tva_amount'];
    $precompte  = (float) invcol($invData, 'precompte_amount', 0);
    $air        = (float) invcol($invData, 'air_amount', 0);
    $total      = (float) $invData['total_amount'];

    // net_payable is stored by migration 020, but recompute as a fallback for
    // rows written before it and for rows where a withholding was added later.
    $net_payable = (float) invcol($invData, 'net_payable', 0);
    if ($net_payable <= 0) {
        $net_payable = max(0, $total - $air - $precompte);
    }

    $tva_rate  = (float) $invData['tva_rate'];
    $exemption = trim((string) invcol($invData, 'tva_exemption_reason', ''));
    if ($tva_rate <= 0 && $exemption === '') {
        // A 0 % line with no stated basis is the most common reason a
        // Cameroonian invoice is rejected by the buyer's accountant. Never
        // print a bare 0 %.
        $exemption = 'Exonéré de TVA — régime applicable au produit facturé (art. 128 CGI).';
    }

    $profile = CompanyProfile::get();
    $regimes = CompanyProfile::FISCAL_REGIMES;
    $regime_key = (string) ($profile['fiscal_regime'] ?? '');

    // --- payment blocks -------------------------------------------------------
    // The accounts this invoice advertised, frozen at issue. Read the snapshot
    // rather than the live treasury_accounts rows: a reprint must show what the
    // client was actually told to pay into, even if that bank has since closed
    // or the IBAN was restructured.
    //
    // Falls back to the single company_profile account for invoices issued
    // before migration 044, so old documents keep rendering unchanged.
    $payment_blocks = [];
    $raw_snapshot = (string) invcol($invData, 'payment_block_snapshot', '');
    if ($raw_snapshot !== '') {
        $decoded = json_decode($raw_snapshot, true);
        if (is_array($decoded)) $payment_blocks = $decoded;
    }
    if (!$payment_blocks) {
        $legacy = CompanyProfile::bankDetails();
        if ($legacy) {
            $payment_blocks = [['title' => 'Coordonnées de règlement', 'lines' => $legacy]];
        }
    }

    // 6. Construct Final JSON Payload
    $response = [
        'status' => 'success',
        'data' => [
            // The seller. Hardcoded in the template until Sprint 9.
            'company' => [
                'name'                => CompanyProfile::displayName(),
                'legal_name'          => CompanyProfile::legalName(),
                'legal_form'          => (string) ($profile['legal_form'] ?? ''),
                'address_lines'       => CompanyProfile::addressLines(),
                'contact'             => CompanyProfile::contactLine(),
                'niu'                 => (string) ($profile['niu'] ?? ''),
                'rccm'                => (string) ($profile['rccm_number'] ?? ''),
                'share_capital'       => (float)  ($profile['share_capital'] ?? 0),
                'fiscal_regime'       => $regime_key,
                'fiscal_regime_label' => $regimes[$regime_key] ?? '',
                'tax_office'          => (string) ($profile['tax_office'] ?? ''),
                'legal_mentions'      => CompanyProfile::legalMentions(),
                // One entry per account the client may settle into: {title, lines{}}.
                'payment_blocks'      => $payment_blocks,
                'footer'              => CompanyProfile::letterhead('fr')['footer'],
                'brand_color'         => (string) ($profile['brand_color_primary'] ?? '#005A2B'),
            ],
            'invoice' => [
                'reference'        => $invData['reference'],
                'date'             => $invData['date'],
                'due_date'         => $invData['due_date'],
                'currency'         => 'XAF',
                'subtotal'         => $subtotal,
                'excise_rate'      => (float) invcol($invData, 'excise_rate', 0) * 100,
                'excise_amount'    => $excise,
                'tva_rate'         => $tva_rate,
                'tva_amount'       => $tva_amount,
                'tva_exemption'    => $exemption,
                'precompte_rate'   => (float) invcol($invData, 'precompte_rate', 0) * 100,
                'precompte_amount' => $precompte,
                'air_rate'         => (float) invcol($invData, 'air_rate', 0) * 100,
                'air_amount'       => $air,
                'total_amount'     => $total,
                'net_payable'      => $net_payable,
                'status'           => $invData['status'],
                'paid_amount'      => $paid_amount,
                'balance'          => $balance,
                'notes'            => $invData['notes'],
                'amount_in_words'  => getAmountInWords($total),
                'token'            => $invData['token'],
            ],
            'client' => [
                'name'    => $invData['client_name'],
                'address' => $invData['client_address'],
                'phone'   => $invData['client_phone'],
                'email'   => $invData['client_email'],
                'niu'     => $invData['client_niu'],
                'rccm'    => $invData['client_rc'],
                'is_b2b'  => strtoupper((string) $invData['client_type']) === 'B2B',
            ],
            'stamp' => [
                'created_by' => $creator_name,
                'role'       => $role_name,
                'timestamp'  => date('d/m/Y H:i', strtotime($invData['created_at'])),
                'hash'       => implode('-', str_split($verification_hash, 4)), // XXXX-XXXX-XXXX-XXXX
            ],
            'items' => $items,
            // {reference, total_qty} per BL this invoice was raised from.
            // Empty for invoices not generated from a delivery batch.
            'deliveries' => $deliveries,
        ],
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('get_invoice: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur Serveur DB.']);
}
