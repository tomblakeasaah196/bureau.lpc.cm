<?php
/**
 * includes/pdf_templates/bilan_export.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7A D3 · Bilan (Actif / Passif) PDF template.
 *
 * Called via ob_start(); include; ob_get_clean(); from financials_controller
 * ?action=export_pdf&kind=bilan (or 'both'). Reads the exact array produced
 * by getFinancialAggregates() so the on-screen bilan and the PDF cannot
 * diverge.
 * -----------------------------------------------------------------------------
 */

if (!isset($data) || !is_array($data)) {
    echo '<p style="color:red">bilan_export template: $data missing.</p>';
    return;
}

$fcfa = function ($n): string {
    if (!is_numeric($n)) return '0 FCFA';
    $r = (int) round((float)$n);
    $s = number_format(abs($r), 0, ',', "\xE2\x80\xAF");
    if ($r < 0) $s = '-' . $s;
    return $s . ' FCFA';
};
$esc = function ($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); };
$year = (int)($data['year'] ?? date('Y'));

// Compute totals for the footer rows.
$tot_actif  = ['brut_N' => 0.0, 'amort_N' => 0.0, 'net_N' => 0.0, 'net_N_1' => 0.0];
$tot_passif = ['net_N' => 0.0, 'net_N_1' => 0.0];
foreach ($data['bilan_actif'] as $l) {
    $tot_actif['brut_N']  += (float)$l['brut_N'];
    $tot_actif['amort_N'] += (float)$l['amort_N'];
    $tot_actif['net_N']   += (float)$l['net_N'];
    $tot_actif['net_N_1'] += (float)$l['net_N_1'];
}
foreach ($data['bilan_passif'] as $l) {
    // The Vue Dirigeant / on-screen bilan injects RES_NET separately; here we
    // fold the calculated net result into the passif total for parity.
    if (($l['code'] ?? '') === 'RES_NET') {
        $tot_passif['net_N']   += (float)($data['resultat_net_N']   ?? 0);
        $tot_passif['net_N_1'] += (float)($data['resultat_net_N_1'] ?? 0);
    } else {
        $tot_passif['net_N']   += (float)$l['net_N'];
        $tot_passif['net_N_1'] += (float)$l['net_N_1'];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Bilan Comptable — <?= $year ?></title>
<style>
    @page { margin: 14mm 10mm 16mm 10mm; }
    body  { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2937; }
    h1    { font-size: 15pt; margin: 0 0 2mm 0; color: #065f46; }
    h2    { font-size: 11pt; margin: 5mm 0 2mm 0; padding: 2mm 3mm; }
    h2.actif  { background: #ecfdf5; color: #065f46; border-left: 3pt solid #10b981; }
    h2.passif { background: #fef2f2; color: #991b1b; border-left: 3pt solid #dc2626; }
    .meta { color: #6b7280; font-size: 8pt; margin-bottom: 5mm; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
    th, td { padding: 1.6mm 2.5mm; border-bottom: 0.3pt solid #e5e7eb; }
    th   { text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; background: #f9fafb; }
    td.n { text-align: right; font-variant-numeric: tabular-nums; }
    tr.total td { border-top: 0.7pt solid #111827; border-bottom: none; font-weight: bold; padding-top: 2.5mm; background: #f3f4f6; }
    .amort { color: #dc2626; }
</style>
</head>
<body>

<h1>Bilan Comptable — Exercice <?= $year ?></h1>
<div class="meta">Généré le <?= $esc($data['generated_at']) ?> · Ets. La Petite Cour · États financiers OHADA</div>

<h2 class="actif">Actif (Emplois — ce que possède l'entreprise)</h2>
<table>
    <thead>
        <tr>
            <th style="width:40%">Rubrique</th>
            <th style="text-align:right">Brut N</th>
            <th style="text-align:right">Amort/Prov N</th>
            <th style="text-align:right">Net N</th>
            <th style="text-align:right">Net N-1</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($data['bilan_actif'] as $l): ?>
        <tr>
            <td><?= $esc($l['name']) ?></td>
            <td class="n"><?= $fcfa($l['brut_N']) ?></td>
            <td class="n amort"><?= ((float)$l['amort_N'] > 0) ? '(' . $fcfa($l['amort_N']) . ')' : '—' ?></td>
            <td class="n"><?= $fcfa($l['net_N']) ?></td>
            <td class="n"><?= $fcfa($l['net_N_1']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total">
            <td>TOTAL ACTIF</td>
            <td class="n"><?= $fcfa($tot_actif['brut_N']) ?></td>
            <td class="n amort"><?= ($tot_actif['amort_N'] > 0) ? '(' . $fcfa($tot_actif['amort_N']) . ')' : '—' ?></td>
            <td class="n"><?= $fcfa($tot_actif['net_N']) ?></td>
            <td class="n"><?= $fcfa($tot_actif['net_N_1']) ?></td>
        </tr>
    </tbody>
</table>

<h2 class="passif">Passif (Ressources — ce que doit l'entreprise)</h2>
<table>
    <thead>
        <tr>
            <th style="width:60%">Rubrique</th>
            <th style="text-align:right">Net N</th>
            <th style="text-align:right">Net N-1</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($data['bilan_passif'] as $l):
            $net_n   = ($l['code'] === 'RES_NET') ? (float)($data['resultat_net_N']   ?? 0) : (float)$l['net_N'];
            $net_n_1 = ($l['code'] === 'RES_NET') ? (float)($data['resultat_net_N_1'] ?? 0) : (float)$l['net_N_1'];
        ?>
        <tr>
            <td><?= $esc($l['name']) ?></td>
            <td class="n"><?= $fcfa($net_n) ?></td>
            <td class="n"><?= $fcfa($net_n_1) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total">
            <td>TOTAL PASSIF &amp; CAPITAUX <?php
                $delta = round($tot_actif['net_N'] - $tot_passif['net_N']);
                if (abs($delta) < 1) echo '<span style="color:#059669">✓ Équilibré</span>';
                else                 echo '<span style="color:#dc2626">Δ ' . $fcfa($delta) . '</span>';
            ?></td>
            <td class="n"><?= $fcfa($tot_passif['net_N']) ?></td>
            <td class="n"><?= $fcfa($tot_passif['net_N_1']) ?></td>
        </tr>
    </tbody>
</table>

</body>
</html>
