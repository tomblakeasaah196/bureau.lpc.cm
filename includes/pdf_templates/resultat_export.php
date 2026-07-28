<?php
/**
 * includes/pdf_templates/resultat_export.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7A D3 · Compte de Résultat (SIG cascade) PDF template.
 *
 * Same data-source rule as bilan_export.php — consumes the array from
 * getFinancialAggregates() so the on-screen résultat and PDF agree line by
 * line. SIG subtotals are computed per section (fix already landed in the
 * on-screen render — mirror it here).
 * -----------------------------------------------------------------------------
 */

if (!isset($data) || !is_array($data)) {
    echo '<p style="color:red">resultat_export template: $data missing.</p>';
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

// Walk the resultat lines with per-section SIG subtotals.
$rows = [];   // pre-rendered rows collected in reading order.
$current_sig = '';
$sig_totals = ['N' => 0.0, 'N_1' => 0.0];

foreach ($data['resultat'] as $line) {
    $lineCat = $line['sig_category'] ?? '';
    if ($lineCat !== $current_sig && $current_sig !== '') {
        $rows[] = ['kind' => 'sig', 'name' => $current_sig, 'N' => $sig_totals['N'], 'N_1' => $sig_totals['N_1']];
        $sig_totals = ['N' => 0.0, 'N_1' => 0.0];
    }
    $current_sig = $lineCat;

    $rows[] = [
        'kind'     => 'line',
        'name'     => $line['name'],
        'operator' => $line['operator'] ?? '+',
        'N'        => (float)$line['net_N'],
        'N_1'      => (float)$line['net_N_1'],
    ];

    if (($line['operator'] ?? '+') === '+') {
        $sig_totals['N']   += (float)$line['net_N'];
        $sig_totals['N_1'] += (float)$line['net_N_1'];
    } else {
        $sig_totals['N']   -= (float)$line['net_N'];
        $sig_totals['N_1'] -= (float)$line['net_N_1'];
    }
}
if ($current_sig !== '') {
    $rows[] = ['kind' => 'sig', 'name' => $current_sig, 'N' => $sig_totals['N'], 'N_1' => $sig_totals['N_1']];
}
// Final net result (server already computed it).
$rows[] = [
    'kind' => 'sig',
    'name' => "RÉSULTAT NET DE L'EXERCICE",
    'N'    => (float)($data['resultat_net_N']   ?? 0),
    'N_1'  => (float)($data['resultat_net_N_1'] ?? 0),
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Compte de Résultat — <?= $year ?></title>
<style>
    @page { margin: 14mm 12mm 16mm 12mm; }
    body  { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2937; }
    h1    { font-size: 15pt; margin: 0 0 2mm 0; color: #3730a3; }
    .meta { color: #6b7280; font-size: 8pt; margin-bottom: 5mm; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
    th, td { padding: 1.6mm 2.5mm; border-bottom: 0.3pt solid #e5e7eb; }
    th   { text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; background: #f9fafb; }
    td.n { text-align: right; font-variant-numeric: tabular-nums; }
    tr.sig td { background: #eef2ff; color: #3730a3; font-weight: bold; border-top: 0.7pt solid #6366f1; border-bottom: 0.7pt solid #6366f1; text-transform: uppercase; letter-spacing: 0.03em; }
    .op { color: #9ca3af; font-size: 8pt; width: 3mm; }
</style>
</head>
<body>

<h1>Compte de Résultat (Cascade SIG) — <?= $year ?></h1>
<div class="meta">Généré le <?= $esc($data['generated_at']) ?> · <?= $esc(CompanyProfile::displayName()) ?> · Solde Intermédiaire de Gestion OHADA</div>

<table>
    <thead>
        <tr>
            <th style="width:56%">Indicateur / Rubrique</th>
            <th style="text-align:right">Exercice <?= $year ?></th>
            <th style="text-align:right">Exercice <?= $year - 1 ?></th>
            <th style="text-align:right;width:15%">Var %</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $r):
            $varPct = ($r['N_1'] != 0) ? (($r['N'] - $r['N_1']) / abs($r['N_1'])) * 100 : 0;
            $varStr = ($varPct == 0) ? '—' : (($varPct > 0) ? '+' . number_format($varPct, 1, ',', ' ') . ' %'
                                                            :        number_format($varPct, 1, ',', ' ') . ' %');
        ?>
        <?php if ($r['kind'] === 'sig'): ?>
        <tr class="sig">
            <td>&#9656; <?= $esc(str_replace('_', ' ', $r['name'])) ?></td>
            <td class="n"><?= $fcfa($r['N']) ?></td>
            <td class="n"><?= $fcfa($r['N_1']) ?></td>
            <td class="n"><?= $esc($varStr) ?></td>
        </tr>
        <?php else: ?>
        <tr>
            <td><span class="op">(<?= $esc($r['operator']) ?>)</span> <?= $esc($r['name']) ?></td>
            <td class="n"><?= $fcfa($r['N']) ?></td>
            <td class="n"><?= $fcfa($r['N_1']) ?></td>
            <td class="n"><?= $esc($varStr) ?></td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
