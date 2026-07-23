<?php
/**
 * includes/pdf_templates/executive_summary.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7A D2 · Vue Dirigeant PDF template.
 *
 * Consumes the exact same array shape produced by fetch_executive_summary()
 * so the on-screen dashboard and the PDF export can never drift.
 *
 * Called via:
 *   ob_start();
 *   $data = fetch_executive_summary($pdo, $year);
 *   include __DIR__ . '/../pdf_templates/executive_summary.php';
 *   $html = ob_get_clean();
 *   $pdf = PdfRenderer::fromHtml($html, ['paper'=>'A4','orientation'=>'portrait']);
 *
 * dompdf ships with DejaVu Sans which handles é / î / FCFA cleanly.
 * -----------------------------------------------------------------------------
 */

if (!isset($data) || !is_array($data)) {
    echo '<p style="color:red">executive_summary template: $data missing.</p>';
    return;
}

// Local helpers — kept in the template to avoid polluting the global scope.
$fcfa = function ($n): string {
    if (!is_numeric($n)) return '0 FCFA';
    $r = (int) round((float)$n);
    $s = number_format(abs($r), 0, ',', "\xE2\x80\xAF");
    if ($r < 0) $s = '-' . $s;
    return $s . ' FCFA';
};
$pct = function ($n, $d = 1): string {
    if (!is_numeric($n)) return '—';
    return number_format((float)$n, $d, ',', ' ') . ' %';
};
$esc = function ($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); };

$ratios    = $data['ratios']    ?? [];
$cash      = $data['cash']      ?? [];
$snapshot  = $data['snapshot']  ?? [];
$alerts    = $data['alerts']    ?? [];
$debtors   = $data['top_debtors']   ?? [];
$suppliers = $data['top_suppliers'] ?? [];
$year      = (int)($data['year'] ?? date('Y'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Vue Dirigeant — <?= $year ?></title>
<style>
    @page { margin: 14mm 12mm 16mm 12mm; }
    body  { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1f2937; }
    h1    { font-size: 16pt; margin: 0 0 2mm 0; color: #005A2B; }
    h2    { font-size: 11pt; margin: 6mm 0 2mm 0; padding: 2mm 3mm; background: #eef2ff; color: #3730a3; border-left: 3pt solid #6366f1; }
    .meta { color: #6b7280; font-size: 8pt; margin-bottom: 5mm; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
    th, td { padding: 2mm 2.5mm; border-bottom: 0.3pt solid #e5e7eb; }
    th   { text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; background: #f9fafb; }
    td.n { text-align: right; font-variant-numeric: tabular-nums; }
    td.b { font-weight: bold; color: #111827; }
    .ratios td { padding: 3mm 3mm; text-align: center; }
    .ratios .lbl { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; }
    .ratios .val { font-size: 14pt; font-weight: bold; color: #005A2B; margin-top: 1mm; }
    .card-row td { padding: 4mm 3mm; border: 0.3pt solid #e5e7eb; text-align: center; vertical-align: top; }
    .card-row td.warn .val { color: #d97706; }
    .card-row td.bad  .val { color: #dc2626; }
    .flag   { display: inline-block; padding: 1mm 3mm; border-radius: 2mm; font-weight: bold; font-size: 8pt; }
    .flag--ok  { background: #d1fae5; color: #065f46; }
    .flag--bad { background: #fee2e2; color: #991b1b; }
    .flag--warn{ background: #fef3c7; color: #92400e; }
    .grid2 td { width: 50%; vertical-align: top; padding-right: 4mm; }
</style>
</head>
<body>

<h1>Vue Dirigeant — Exercice <?= $year ?></h1>
<div class="meta">
    Généré le <?= $esc($data['generated_at'] ?? date('Y-m-d H:i:s')) ?>
    · Ets. La Petite Cour · Confidentiel — usage interne uniquement.
</div>

<h2>1 · Ratios de rentabilité</h2>
<table class="ratios">
    <tr class="card-row">
        <td>
            <div class="lbl">Marge brute</div>
            <div class="val"><?= $pct($ratios['margin_gross_pct'] ?? 0) ?></div>
            <div class="lbl"><?= $fcfa(($ratios['revenue'] ?? 0) - ($ratios['cogs'] ?? 0)) ?></div>
        </td>
        <td>
            <div class="lbl">EBE</div>
            <div class="val"><?= $pct($ratios['ebe_pct'] ?? 0) ?></div>
            <div class="lbl"><?= $fcfa($ratios['ebe_value'] ?? 0) ?></div>
        </td>
        <td>
            <div class="lbl">Résultat net</div>
            <div class="val"><?= $pct($ratios['net_margin_pct'] ?? 0) ?></div>
            <div class="lbl"><?= $fcfa($ratios['net_result'] ?? 0) ?></div>
        </td>
        <td>
            <div class="lbl">DSO</div>
            <div class="val"><?= number_format((float)($ratios['dso_days'] ?? 0), 1, ',', ' ') ?> j</div>
            <div class="lbl">AR : <?= $fcfa($ratios['ar_balance'] ?? 0) ?></div>
        </td>
        <td>
            <div class="lbl">DPO</div>
            <div class="val"><?= number_format((float)($ratios['dpo_days'] ?? 0), 1, ',', ' ') ?> j</div>
            <div class="lbl">AP : <?= $fcfa($ratios['ap_balance'] ?? 0) ?></div>
        </td>
    </tr>
</table>

<h2>2 · Trésorerie</h2>
<table>
    <thead>
        <tr>
            <th>Compte</th>
            <th>Type</th>
            <th style="text-align:right">Solde</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach (($cash['breakdown'] ?? []) as $row): ?>
        <tr>
            <td><?= $esc($row['name']) ?></td>
            <td><?= $esc(strtoupper($row['type'])) ?></td>
            <td class="n b"><?= $fcfa($row['balance']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr>
            <td class="b" colspan="2">Trésorerie totale</td>
            <td class="n b" style="border-top:0.7pt solid #111"><?= $fcfa($cash['total'] ?? 0) ?></td>
        </tr>
    </tbody>
</table>

<h2>3 · Créances & Dettes — Top 5</h2>
<table class="grid2"><tr><td>
    <table>
        <thead><tr><th>Client débiteur</th><th style="text-align:right">Créance</th></tr></thead>
        <tbody>
        <?php if (empty($debtors)): ?>
            <tr><td colspan="2" style="color:#9ca3af;font-style:italic">Aucun débiteur listé.</td></tr>
        <?php else: foreach ($debtors as $d): ?>
            <tr>
                <td><?= $esc($d['client_name']) ?><br><span style="color:#9ca3af;font-size:8pt"><?= $esc($d['client_code']) ?></span></td>
                <td class="n b"><?= $fcfa($d['total_due']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</td><td>
    <table>
        <thead><tr><th>Fournisseur</th><th style="text-align:right">Dû</th></tr></thead>
        <tbody>
        <?php if (empty($suppliers)): ?>
            <tr><td colspan="2" style="color:#9ca3af;font-style:italic">Aucun fournisseur en attente.</td></tr>
        <?php else: foreach ($suppliers as $s): ?>
            <tr>
                <td><?= $esc($s['supplier_name']) ?><br><span style="color:#9ca3af;font-size:8pt"><?= $esc($s['supplier_code']) ?> · dernière commande : <?= $esc($s['latest_po_date']) ?></span></td>
                <td class="n b"><?= $fcfa($s['outstanding']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</td></tr></table>

<h2>4 · Aperçu opérationnel</h2>
<table>
    <thead><tr><th>Indicateur</th><th style="text-align:right">Valeur</th></tr></thead>
    <tbody>
        <?php foreach (($snapshot['vehicles_by_status'] ?? []) as $vs): ?>
        <tr>
            <td>Véhicules — <?= $esc(strtoupper($vs['status'])) ?></td>
            <td class="n b"><?= (int)$vs['count'] ?></td>
        </tr>
        <?php endforeach; ?>
        <tr>
            <td>Paie du mois (<?= $esc($snapshot['payroll_month_ymd'] ?? '') ?>)</td>
            <td class="n b"><?= $fcfa($snapshot['payroll_month'] ?? 0) ?></td>
        </tr>
        <tr>
            <td>Carburant du mois (<?= $esc($snapshot['fuel_month_ymd'] ?? '') ?>)</td>
            <td class="n b"><?= $fcfa($snapshot['fuel_month'] ?? 0) ?></td>
        </tr>
    </tbody>
</table>

<h2>5 · Alertes (30 derniers jours)</h2>
<table>
    <tbody>
        <tr>
            <td>Écritures en attente d'approbation</td>
            <td class="n"><span class="flag <?= ((int)$alerts['je_pending_30d'] > 0) ? 'flag--warn' : 'flag--ok' ?>"><?= (int)($alerts['je_pending_30d'] ?? 0) ?></span></td>
        </tr>
        <tr>
            <td>Factures échues non réglées</td>
            <td class="n"><span class="flag <?= ((int)$alerts['overdue_payments'] > 0) ? 'flag--bad' : 'flag--ok' ?>"><?= (int)($alerts['overdue_payments'] ?? 0) ?></span></td>
        </tr>
        <tr>
            <td>Budgets consommés à plus de 90 %</td>
            <td class="n"><span class="flag <?= ((int)$alerts['budgets_over_90pct'] > 0) ? 'flag--warn' : 'flag--ok' ?>"><?= (int)($alerts['budgets_over_90pct'] ?? 0) ?></span></td>
        </tr>
    </tbody>
</table>

</body>
</html>
