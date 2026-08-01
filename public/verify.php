<?php
/**
 * public/verify.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — universal public verification page. Reached from the QR
 * printed on every document (see includes/components/signature_block.php).
 *
 * ROUTES (see .htaccess)
 *   /verify/{40-hex-token}      pretty URL printed under the QR
 *   /verify.php?token=…         what the pretty URL rewrites to
 *
 * WHAT CHANGED FROM SPRINT 10
 *   The old page was quote-only and internal-only. It's now universal — it
 *   accepts a verify_token for ANY document type + ANY party (internal or
 *   external) and renders the right summary block for each. Every document
 *   type's PDF prints a QR that lands here; every one of those QRs is
 *   resolvable to a single verify_token via DocumentSignature::verifyByToken.
 *
 *   Layout is now three stacked sections rather than one card:
 *     1. Auth banner        — "Ceci est la vérification officielle d'une
 *                              signature électronique émise par [LPC]."
 *     2. Structured summary — what the signature attests to, per doc type
 *                              (reference, client, total, party, signer,
 *                              timestamp, hash fragment).
 *     3. LPC contact block  — email, phone, address, RCCM, NIU for anyone
 *                              who wants to verify physically or reach the
 *                              company directly.
 *
 * THREE STATES (unchanged)
 *   unknown  — 404. Never leaks whether the token was malformed vs. never
 *              existed.
 *   revoked  — 200, shown plainly as revoked. Not a 404, so someone holding
 *              an old printed PDF can't claim "the link is just broken".
 *   valid    — 200, full summary.
 *
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/classes/DocumentSignature.php';
require_once __DIR__ . '/../includes/classes/CompanyProfile.php';

$e = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$token = trim((string) ($_GET['token'] ?? ''));
$sig   = $token !== '' ? DocumentSignature::verifyByToken($token) : null;

// -----------------------------------------------------------------------------
// Resolve the document behind this signature, in the shape the render
// summary needs. Every doc type reads from a different table — quote from
// proposals, cre from cre_documents, bl from deliveries, etc. — but the
// summary only ever needs three or four fields per type. Kept inline here
// so the whole verify path is one file to read end to end.
// -----------------------------------------------------------------------------

/**
 * @return array{title:string, fields:array<string,string>}|null
 */
function verify_summary(PDO $db, array $sig): ?array
{
    $type  = (string) ($sig['document_type'] ?? '');
    $docId = (int)    ($sig['document_id']   ?? 0);
    if ($type === '' || $docId <= 0) return null;

    try {
        switch ($type) {
            case 'quote': {
                $s = $db->prepare('
                    SELECT reference, revision, client_name, total_amount
                      FROM proposals
                     WHERE id = ? LIMIT 1
                ');
                $s->execute([$docId]);
                $r = $s->fetch(PDO::FETCH_ASSOC);
                if (!$r) return null;
                return [
                    'title'  => 'Devis',
                    'fields' => [
                        'Référence'  => $r['reference'] . ((int) $r['revision'] > 1 ? ' rév. ' . (int) $r['revision'] : ''),
                        'Client'     => (string) $r['client_name'],
                        'Montant TTC'=> lpc_fcfa((float) $r['total_amount']),
                    ],
                ];
            }
            case 'cre': {
                $s = $db->prepare('
                    SELECT d.reference, c.name AS client_name, s.name AS site_name
                      FROM cre_documents d
                      JOIN clients c ON c.id = d.client_id
                      LEFT JOIN client_sites s ON s.id = d.site_id
                     WHERE d.id = ? LIMIT 1
                ');
                $s->execute([$docId]);
                $r = $s->fetch(PDO::FETCH_ASSOC);
                if (!$r) return null;
                $fields = [
                    'Référence' => (string) $r['reference'],
                    'Client'    => (string) $r['client_name'],
                ];
                if (!empty($r['site_name'])) $fields['Site'] = (string) $r['site_name'];
                return ['title' => 'Certificat de restitution d\'emballages', 'fields' => $fields];
            }
            case 'bl': {
                $s = $db->prepare('
                    SELECT d.reference, c.name AS client_name, s.total_amount
                      FROM deliveries d
                      JOIN clients c ON c.id = d.client_id
                      LEFT JOIN sales_orders s ON s.id = d.sales_order_id
                     WHERE d.id = ? LIMIT 1
                ');
                $s->execute([$docId]);
                $r = $s->fetch(PDO::FETCH_ASSOC);
                if (!$r) return null;
                return [
                    'title'  => 'Bon de livraison',
                    'fields' => [
                        'Référence' => (string) $r['reference'],
                        'Client'    => (string) $r['client_name'],
                        'Montant'   => lpc_fcfa((float) ($r['total_amount'] ?? 0)),
                    ],
                ];
            }
            case 'facture': {
                // invoices carries client_id, not client_name — the display
                // name lives on the clients table (unlike proposals, which
                // inlines the name for the prospect stage).
                $s = $db->prepare('
                    SELECT i.reference, i.total_amount, c.name AS client_name
                      FROM invoices i
                      LEFT JOIN clients c ON c.id = i.client_id
                     WHERE i.id = ? LIMIT 1
                ');
                $s->execute([$docId]);
                $r = $s->fetch(PDO::FETCH_ASSOC);
                if (!$r) return null;
                return [
                    'title'  => 'Facture',
                    'fields' => [
                        'Référence'  => (string) $r['reference'],
                        'Client'     => (string) ($r['client_name'] ?? '—'),
                        'Montant TTC'=> lpc_fcfa((float) $r['total_amount']),
                    ],
                ];
            }
            case 'bon_commande': {
                // purchase_orders carries supplier_id, not supplier_name.
                // suppliers table holds the display name.
                $s = $db->prepare('
                    SELECT po.reference, po.total_amount, s.name AS supplier_name
                      FROM purchase_orders po
                      LEFT JOIN suppliers s ON s.id = po.supplier_id
                     WHERE po.id = ? LIMIT 1
                ');
                $s->execute([$docId]);
                $r = $s->fetch(PDO::FETCH_ASSOC);
                if (!$r) return null;
                return [
                    'title'  => 'Bon de commande',
                    'fields' => [
                        'Référence'    => (string) $r['reference'],
                        'Fournisseur'  => (string) ($r['supplier_name'] ?? '—'),
                        'Montant'      => lpc_fcfa((float) ($r['total_amount'] ?? 0)),
                    ],
                ];
            }
            case 'payslip': {
                // Actual table is hr_payslips; net_pay (not net_amount);
                // employee is resolved via user_id. first_name + last_name
                // are the display fields on users.
                $s = $db->prepare("
                    SELECT p.year, p.month, p.net_pay,
                           TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS employee_name
                      FROM hr_payslips p
                      LEFT JOIN users u ON u.id = p.user_id
                     WHERE p.id = ? LIMIT 1
                ");
                $s->execute([$docId]);
                $r = $s->fetch(PDO::FETCH_ASSOC);
                if (!$r) return null;
                return [
                    'title'  => 'Bulletin de paie',
                    'fields' => [
                        'Période'     => sprintf('%02d/%04d', (int) $r['month'], (int) $r['year']),
                        'Salarié'     => (string) ($r['employee_name'] ?? '—'),
                        'Net à payer' => lpc_fcfa((float) ($r['net_pay'] ?? 0)),
                    ],
                ];
            }
            case 'contract':
            default:
                // Contract module doesn't exist yet. Show the type slug so
                // an early PoC signature is still resolvable.
                return [
                    'title'  => ucfirst(str_replace('_', ' ', $type)),
                    'fields' => ['Type' => $type],
                ];
        }
    } catch (Throwable $ex) {
        error_log('verify.php: summary lookup failed for ' . $type . '#' . $docId . ': ' . $ex->getMessage());
        return null;
    }
}

$summary = null;
if ($sig) {
    try {
        $summary = verify_summary(Database::getInstance()->getConnection(), $sig);
    } catch (Throwable $ex) {
        error_log('verify.php: db unavailable: ' . $ex->getMessage());
    }
}

$lh    = CompanyProfile::letterhead('fr');
$brand = $lh['color'];
$profile = CompanyProfile::get();

// State: 'unknown' | 'revoked' | 'valid'
$state = !$sig ? 'unknown' : (!empty($sig['revoked_at']) ? 'revoked' : 'valid');
http_response_code($state === 'unknown' ? 404 : 200);

$party = $sig ? (string) $sig['party'] : '';

// Signer identity — internal rows use signer_name/signer_role (resolved from
// UserProfile at signing time); external rows use signatory_name/signatory_role
// (typed by the counterparty on the phone). Never mix them.
$signerName = $sig
    ? ($party === 'internal' ? (string) ($sig['signer_name']    ?? '')
                             : (string) ($sig['signatory_name'] ?? ''))
    : '';
$signerRole = $sig
    ? ($party === 'internal' ? (string) ($sig['signer_role']    ?? '')
                             : (string) ($sig['signatory_role'] ?? ''))
    : '';

$signedAtTs   = $sig && !empty($sig['signed_at'])  ? strtotime((string) $sig['signed_at'])  : 0;
$revokedAtTs  = $sig && !empty($sig['revoked_at']) ? strtotime((string) $sig['revoked_at']) : 0;
$signedDate   = $signedAtTs  ? date('d/m/Y', $signedAtTs)  . ' à ' . date('H\hi', $signedAtTs)  : '—';
$revokedDate  = $revokedAtTs ? date('d/m/Y', $revokedAtTs) . ' à ' . date('H\hi', $revokedAtTs) : '—';

// Company contacts — pulled from CompanyProfile so every document uses the
// same email/phone/address strings the letterhead does.
$contactPhone   = trim((string) ($profile['phone_primary'] ?? ''));
$contactPhone2  = trim((string) ($profile['phone_secondary'] ?? ''));
$contactEmail   = trim((string) ($profile['email_general'] ?? ''));
$contactWebsite = trim((string) ($profile['website'] ?? ''));
$contactRccm    = trim((string) ($profile['rccm_number'] ?? ''));
$contactNiu     = trim((string) ($profile['niu'] ?? ''));
$addressLines   = CompanyProfile::addressLines();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Vérification de signature — <?= $e($lh['name']) ?></title>
<style>
    :root { --brand: <?= $e($brand) ?>; }
    * { box-sizing: border-box; }
    body {
        margin: 0; padding: 24px 16px 48px;
        background: #F3F4F6; color: #1F2937;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        display: flex; flex-direction: column; align-items: center; min-height: 100vh;
    }
    .wrap  { width: 100%; max-width: 520px; }
    .brand { display: flex; align-items: center; gap: 10px; justify-content: center; margin-bottom: 20px; }
    .brand img { height: 34px; width: auto; }
    .brand-name { font-weight: 700; color: #111827; font-size: 15px; }

    .authbanner {
        border-left: 4px solid var(--brand);
        background: #ECFDF5;
        padding: 14px 16px; border-radius: 6px;
        margin-bottom: 16px; font-size: 13.5px; color: #065F46;
        line-height: 1.55;
    }
    .authbanner.revoked { background: #FEF2F2; color: #7F1D1D; border-left-color: #DC2626; }
    .authbanner.unknown { background: #F9FAFB; color: #6B7280; border-left-color: #9CA3AF; }
    .authbanner strong { color: #065F46; }
    .authbanner.revoked strong { color: #7F1D1D; }
    .authbanner.unknown strong { color: #374151; }

    .card {
        background: #fff; border-radius: 14px; padding: 24px 22px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
        margin-bottom: 16px;
    }
    .badge {
        display: inline-flex; align-items: center; gap: 6px;
        border-radius: 999px; padding: 6px 14px; font-weight: 700;
        font-size: 11.5px; letter-spacing: 0.4px; text-transform: uppercase;
        margin-bottom: 14px;
    }
    .badge.valid   { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
    .badge.revoked { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }
    .badge.unknown { background: #F3F4F6; color: #6B7280; border: 1px solid #E5E7EB; }
    .party-tag {
        display: inline-block; margin-left: 8px;
        border-radius: 4px; padding: 3px 8px; font-size: 10.5px; font-weight: 700;
        letter-spacing: 0.3px; text-transform: uppercase;
    }
    .party-tag.internal { background: #EEF2FF; color: #3730A3; border: 1px solid #C7D2FE; }
    .party-tag.external { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }

    h1 { font-size: 18px; margin: 0 0 6px; color: #111827; }
    .doctype { font-size: 12px; color: #9CA3AF; text-transform: uppercase; letter-spacing: 0.6px; font-weight: 700; margin: 0 0 14px; }
    .sub { font-size: 13.5px; color: #4B5563; margin: 0 0 18px; line-height: 1.55; }

    .kv { border-top: 1px solid #E5E7EB; }
    .kv .row { display: flex; justify-content: space-between; gap: 12px;
               padding: 11px 0; border-bottom: 1px solid #F3F4F6; font-size: 13.5px; }
    .kv .row:last-child { border-bottom: none; }
    .kv .k { color: #6B7280; }
    .kv .v { color: #111827; font-weight: 600; text-align: right; word-break: break-word; }

    .section-title {
        font-size: 11px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.7px; color: #9CA3AF;
        margin: 0 0 10px;
    }
    .hash {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 11px; color: #6B7280; word-break: break-all; margin-top: 16px;
        padding-top: 12px; border-top: 1px dashed #E5E7EB; line-height: 1.5;
    }

    .contacts .row { display: flex; align-items: baseline; gap: 10px;
                     padding: 8px 0; border-bottom: 1px solid #F3F4F6; font-size: 13px; }
    .contacts .row:last-child { border-bottom: none; }
    .contacts .k { color: #9CA3AF; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
                   font-weight: 700; min-width: 90px; }
    .contacts .v { color: #111827; }
    .contacts a  { color: #111827; text-decoration: none; border-bottom: 1px dotted #9CA3AF; }

    .foot { text-align: center; font-size: 11.5px; color: #9CA3AF; margin-top: 22px; line-height: 1.6; }
    .foot a { color: #6B7280; }
</style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <?php $logoWeb = CompanyProfile::logo('mark'); ?>
        <?php if ($logoWeb !== ''): ?><img src="<?= $e($logoWeb) ?>" alt=""><?php endif; ?>
        <span class="brand-name"><?= $e($lh['name']) ?></span>
    </div>

    <!-- ══ 1. Auth banner ══════════════════════════════════════════════════ -->
    <?php if ($state === 'valid'): ?>
        <div class="authbanner">
            <strong>Ceci est la vérification officielle</strong> d'une signature
            électronique émise par <?= $e($lh['name']) ?>. La signature ci-dessous
            existe dans nos registres, n'a pas été révoquée, et couvre exactement
            les chiffres tels qu'ils apparaissent aujourd'hui sur le document.
        </div>
    <?php elseif ($state === 'revoked'): ?>
        <div class="authbanner revoked">
            <strong>Signature révoquée.</strong> Cette signature électronique a été
            annulée par <?= $e($lh['name']) ?>. Ne vous fiez pas au document que
            vous détenez — contactez-nous pour obtenir la version en vigueur.
        </div>
    <?php else: ?>
        <div class="authbanner unknown">
            <strong>Signature introuvable.</strong> Ce lien de vérification est
            invalide ou incomplet. Si vous venez de scanner un code QR sur un
            document <?= $e($lh['name']) ?>, vérifiez que le code entier a bien
            été capturé.
        </div>
    <?php endif; ?>

    <!-- ══ 2. Structured summary ═══════════════════════════════════════════ -->
    <?php if ($state !== 'unknown'): ?>
    <div class="card">
        <?php if ($state === 'valid'): ?>
            <div class="badge valid">&#10003; Signature valide
                <span class="party-tag <?= $e($party) ?>"><?= $e($party === 'internal' ? 'Interne · LPC' : 'Externe · Client') ?></span>
            </div>
        <?php else: ?>
            <div class="badge revoked">&#10007; Signature révoquée
                <span class="party-tag <?= $e($party) ?>"><?= $e($party === 'internal' ? 'Interne · LPC' : 'Externe · Client') ?></span>
            </div>
        <?php endif; ?>

        <?php if ($summary): ?>
            <p class="doctype"><?= $e($summary['title']) ?></p>
        <?php endif; ?>
        <h1>Document authentique</h1>
        <?php if ($state === 'revoked'): ?>
            <p class="sub">
                Cette signature a été révoquée. Elle reste consultable ci-dessous
                à titre d'historique&nbsp;: le document qu'elle attestait a
                probablement été remplacé par une nouvelle version.
            </p>
        <?php endif; ?>

        <div class="kv">
            <?php if ($summary && !empty($summary['fields'])): ?>
                <?php foreach ($summary['fields'] as $k => $v): ?>
                    <div class="row"><span class="k"><?= $e($k) ?></span><span class="v"><?= $e($v) ?></span></div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="row"><span class="k">Signé par</span><span class="v"><?= $e($signerName ?: '—') ?></span></div>
            <?php if ($signerRole !== ''): ?>
                <div class="row"><span class="k">Fonction</span><span class="v"><?= $e($signerRole) ?></span></div>
            <?php endif; ?>
            <?php if ($party === 'external' && !empty($sig['signatory_phone'])): ?>
                <div class="row"><span class="k">Téléphone</span><span class="v"><?= $e($sig['signatory_phone']) ?></span></div>
            <?php endif; ?>
            <div class="row"><span class="k">Le</span><span class="v"><?= $e($signedDate) ?></span></div>
            <?php if ($state === 'revoked'): ?>
                <div class="row"><span class="k">Révoquée le</span><span class="v"><?= $e($revokedDate) ?></span></div>
            <?php endif; ?>
        </div>

        <div class="hash">
            Empreinte cryptographique&nbsp;:<br>
            <?= $e($sig['content_hash']) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ 3. LPC contacts ═════════════════════════════════════════════════ -->
    <div class="card contacts">
        <p class="section-title">Vérifier auprès de <?= $e($lh['name']) ?></p>
        <?php if ($addressLines): ?>
            <div class="row"><span class="k">Adresse</span><span class="v"><?= $e(implode(', ', $addressLines)) ?></span></div>
        <?php endif; ?>
        <?php if ($contactPhone !== '' || $contactPhone2 !== ''): ?>
            <div class="row"><span class="k">Téléphone</span><span class="v">
                <?php $phones = array_filter([$contactPhone, $contactPhone2]); echo $e(implode(' / ', $phones)); ?>
            </span></div>
        <?php endif; ?>
        <?php if ($contactEmail !== ''): ?>
            <div class="row"><span class="k">Email</span><span class="v"><a href="mailto:<?= $e($contactEmail) ?>"><?= $e($contactEmail) ?></a></span></div>
        <?php endif; ?>
        <?php if ($contactWebsite !== ''): ?>
            <div class="row"><span class="k">Site</span><span class="v"><a href="<?= $e($contactWebsite) ?>" rel="noopener"><?= $e($contactWebsite) ?></a></span></div>
        <?php endif; ?>
        <?php if ($contactRccm !== ''): ?>
            <div class="row"><span class="k">RCCM</span><span class="v"><?= $e($contactRccm) ?></span></div>
        <?php endif; ?>
        <?php if ($contactNiu !== ''): ?>
            <div class="row"><span class="k">NIU</span><span class="v"><?= $e($contactNiu) ?></span></div>
        <?php endif; ?>
    </div>

    <div class="foot">
        Vérification indépendante d'une signature électronique émise par
        <?= $e($lh['name']) ?>.
    </div>
</div>
</body>
</html>
