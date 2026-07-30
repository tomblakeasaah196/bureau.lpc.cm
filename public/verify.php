<?php
/**
 * public/verify.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 10 · public verification page behind the devis QR
 * code (migration 048, includes/classes/DocumentSignature.php).
 *
 * Reached two ways (see .htaccess):
 *   /verify/{40-hex-token}      the pretty URL printed under the QR
 *   /verify.php?token=…         what the pretty URL rewrites to
 *
 * Deliberately unauthenticated — anyone holding a printed devis, or anyone who
 * just scanned the QR on it, must be able to load this page. The verify_token
 * itself (40 random hex chars, never enumerable, never the document's own
 * access token) IS the credential: DocumentSignature::verifyByToken() is the
 * only place it is ever looked up.
 *
 * THREE STATES, NOT TWO
 *   A signature is never deleted, only revoked (see the migration's own
 *   comment on document_signatures.revoked_at). So this page has to answer
 *   three different questions, not "valid or 404":
 *     - unknown token            → generic not-found. Never distinguishes
 *                                   "malformed" from "never existed" — that
 *                                   distinction is not this page's to give an
 *                                   anonymous visitor.
 *     - known, revoked           → shown plainly as revoked, with who signed
 *                                   and when still visible. A 404 here would
 *                                   let a bad-faith holder of an old printed
 *                                   PDF claim "the link is just broken".
 *     - known, active            → the reference, client and total this
 *                                   signature actually attests to, re-read
 *                                   fresh from the proposals table (not from
 *                                   the hash — the hash is what PROVES these
 *                                   figures haven't changed since signing,
 *                                   not what displays them).
 *
 * This page shows the client name and total amount alongside the signer.
 * That is a deliberate default, not a confirmed requirement — the
 * alternative (authenticity-only: signer + timestamp + hash, no commercial
 * detail) is one line to switch to if that turns out to be too much to put
 * behind a scannable, unauthenticated URL. See ps_verify_render() below.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/classes/DocumentSignature.php';

$e = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$token = trim((string) ($_GET['token'] ?? ''));
$sig   = $token !== '' ? DocumentSignature::verifyByToken($token) : null;

// The devis this signature belongs to — re-read fresh, not reconstructed from
// the hash, so what is shown always reflects the CURRENT row (a revoked
// signature's document may since have moved on to a new revision entirely).
$doc = null;
if ($sig && $sig['document_type'] === 'quote') {
    try {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare('
            SELECT reference, revision, date, client_name, total_amount
              FROM proposals
             WHERE id = ?
             LIMIT 1
        ');
        $stmt->execute([(int) $sig['document_id']]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $ex) {
        error_log('verify.php: proposal lookup failed: ' . $ex->getMessage());
    }
}

$lh    = CompanyProfile::letterhead('fr');
$brand = $lh['color'];

// State: 'unknown' | 'revoked' | 'valid'
$state = !$sig ? 'unknown' : (!empty($sig['revoked_at']) ? 'revoked' : 'valid');

http_response_code($state === 'unknown' ? 404 : 200);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Vérification de document — <?= $e($lh['name']) ?></title>
<style>
    :root { --brand: <?= $e($brand) ?>; }
    * { box-sizing: border-box; }
    body {
        margin: 0; padding: 24px 16px 48px;
        background: #F3F4F6; color: #1F2937;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        display: flex; flex-direction: column; align-items: center;
    }
    .wrap { width: 100%; max-width: 480px; }
    .brand { display: flex; align-items: center; gap: 10px; justify-content: center; margin-bottom: 20px; }
    .brand img { height: 34px; width: auto; }
    .brand-name { font-weight: 700; color: #111827; font-size: 15px; }
    .card {
        background: #fff; border-radius: 14px; padding: 28px 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
        text-align: center;
    }
    .badge {
        display: inline-flex; align-items: center; gap: 8px;
        border-radius: 999px; padding: 8px 18px; font-weight: 700;
        font-size: 13px; letter-spacing: 0.3px; text-transform: uppercase;
        margin-bottom: 18px;
    }
    .badge.valid   { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
    .badge.revoked { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }
    .badge.unknown { background: #F3F4F6; color: #6B7280; border: 1px solid #E5E7EB; }
    h1 { font-size: 19px; margin: 0 0 6px; color: #111827; }
    .sub { font-size: 13.5px; color: #6B7280; margin: 0 0 22px; line-height: 1.5; }
    .kv { text-align: left; border-top: 1px solid #E5E7EB; margin-top: 6px; }
    .kv .row { display: flex; justify-content: space-between; gap: 12px;
               padding: 11px 0; border-bottom: 1px solid #F3F4F6; font-size: 13.5px; }
    .kv .row:last-child { border-bottom: none; }
    .kv .k { color: #9CA3AF; }
    .kv .v { color: #111827; font-weight: 600; text-align: right; }
    .hash { font-size: 11px; color: #9CA3AF; word-break: break-all; margin-top: 18px;
            padding-top: 14px; border-top: 1px dashed #E5E7EB; }
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

    <div class="card">
        <?php if ($state === 'valid'): ?>
            <div class="badge valid">&#10003; Signature valide</div>
            <h1>Document authentique</h1>
            <p class="sub">Ce devis a été signé électroniquement par un représentant habilité de <?= $e($lh['name']) ?> et n'a pas été modifié depuis sa signature.</p>
            <div class="kv">
                <?php if ($doc): ?>
                    <div class="row"><span class="k">Référence</span><span class="v"><?= $e($doc['reference']) ?><?= (int) $doc['revision'] > 1 ? ' rév. ' . (int) $doc['revision'] : '' ?></span></div>
                    <div class="row"><span class="k">Client</span><span class="v"><?= $e($doc['client_name']) ?></span></div>
                    <div class="row"><span class="k">Montant TTC</span><span class="v"><?= $e(lpc_fcfa($doc['total_amount'])) ?></span></div>
                <?php endif; ?>
                <div class="row"><span class="k">Signé par</span><span class="v"><?= $e($sig['signer_name']) ?></span></div>
                <div class="row"><span class="k">Fonction</span><span class="v"><?= $e($sig['signer_role']) ?></span></div>
                <?php $signedTs = strtotime($sig['signed_at']); ?>
                <div class="row"><span class="k">Le</span><span class="v"><?= $e($signedTs ? date('d/m/Y', $signedTs) . ' à ' . date('H\hi', $signedTs) : '—') ?></span></div>
            </div>
            <div class="hash">Empreinte : <?= $e($sig['content_hash']) ?></div>

        <?php elseif ($state === 'revoked'): ?>
            <div class="badge revoked">&#10007; Signature révoquée</div>
            <h1>Ne pas se fier à ce document</h1>
            <p class="sub">Cette signature a été révoquée par <?= $e($lh['name']) ?> et n'est plus valable. Contactez-nous pour obtenir la version en cours de ce devis.</p>
            <div class="kv">
                <div class="row"><span class="k">Signé par</span><span class="v"><?= $e($sig['signer_name']) ?></span></div>
                <div class="row"><span class="k">Le</span><span class="v"><?= $e(date('d/m/Y', strtotime($sig['signed_at']))) ?></span></div>
                <div class="row"><span class="k">Révoquée le</span><span class="v"><?= $e(date('d/m/Y', strtotime($sig['revoked_at']))) ?></span></div>
            </div>

        <?php else: ?>
            <div class="badge unknown">? Introuvable</div>
            <h1>Signature introuvable</h1>
            <p class="sub">Ce lien de vérification est invalide ou incomplet. Si vous venez de scanner un code QR sur un devis <?= $e($lh['name']) ?>, vérifiez que le code entier a bien été capturé.</p>
        <?php endif; ?>
    </div>

    <div class="foot">
        <?= $e($lh['name']) ?> · <?= $e($lh['contact']) ?><br>
        Vérification indépendante de la validité d'une signature électronique interne.
    </div>
</div>
</body>
</html>
