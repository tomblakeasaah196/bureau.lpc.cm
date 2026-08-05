<?php
/**
 * MODULE: Déclarations Fiscales & Sociales (Cameroun)
 * DESCRIPTION: One page for every DGI / CNPS / commune filing — TVA, AIR
 *              (2.2 % / 5.5 %), DIPE (IRPP + CAC + CFC + CRTV + TDL + FNE),
 *              CNPS (PVID + PF + AT), TSR, Commune, Patente.
 *
 * Everything the accountant needs each month:
 *   • Échéances     — dashboard with upcoming filings + AIR credit pending.
 *   • Déclarations mensuelles — grid of 12 months × 5 filings. Click any
 *                    month to open the Master Monthly Modal with the full
 *                    calc, the attestations, the CNPS/DGI/commune split,
 *                    Approve/Verrouiller, download PDF, record payment.
 *   • Retenues à la source — upload attestations from Prometal etc.
 *   • Paramètres    — read-only summary with deeplinks to Settings tabs
 *                    (Company · fiscal + Preferences · tax) and Payroll.
 *
 * No accountant re-keying: every figure comes from the accounting records
 * already in the DB (invoices, payments, hr_payslips, supplier_invoices,
 * withholding_certificates). The screen exists to let the accountant
 * review, approve, and generate the audit trail.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.invoices.view');
require_once __DIR__ . '/../../includes/classes/TaxEngine.php';
require_once __DIR__ . '/../../includes/functions/help.php';
$lang      = lpc_i18n_current_lang();
$user_role = $_SESSION['user_role'] ?? 'user';
$settings  = TaxEngine::settings();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(__t('ui.x.declarations_fiscales_lpc_erp')) ?></title>
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<style>
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    .badge-status { padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap; }
    /* Status pills and row tints read from the shell's --lpc-status-* tokens
       so they invert correctly in dark mode. Before this rewrite, .st-missing
       was #9ca3af on #f3f4f6 (2.31:1, fails AA even in light mode), and
       .month-cell.done / .late set near-white row backgrounds that stayed
       fixed under the dark theme — the text inside was remapped to near-white
       ink and the whole row became invisible (~1.1:1). All values come from
       one place now and every module using status colour follows the same
       set. */
    .st-draft   { background: var(--lpc-status-neutral-bg); color: var(--lpc-status-neutral-fg); }
    .st-ready   { background: var(--lpc-status-warning-bg); color: var(--lpc-status-warning-fg); }
    .st-filed   { background: var(--lpc-status-info-bg);    color: var(--lpc-status-info-fg);    }
    .st-paid    { background: var(--lpc-status-success-bg); color: var(--lpc-status-success-fg); }
    .st-locked  { background: var(--lpc-status-locked-bg);  color: var(--lpc-status-locked-fg);  }
    .st-missing { background: var(--lpc-status-neutral-bg); color: var(--lpc-status-neutral-fg); }
    .amt { font-variant-numeric: tabular-nums; }
    .month-cell { transition: background .1s; cursor: pointer; }
    .month-cell:hover { background: var(--lpc-status-success-tint); }
    .month-cell.done  { background: var(--lpc-status-success-tint); }
    .month-cell.late  { background: var(--lpc-status-danger-tint);  }
    .kind-chip { display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin: 1px; }
    .kind-tva     { background: var(--lpc-status-info-bg);    color: var(--lpc-status-info-fg);    }
    .kind-air     { background: var(--lpc-status-success-bg); color: var(--lpc-status-success-fg); }
    /* dipe is the one taxonomy colour we keep off the shared status palette
       (there is no "magenta" role token). color-mix over --lpc-surface makes
       it invert with the theme without needing a lpc-theme.css override. */
    .kind-dipe    { background: color-mix(in srgb, #C026D3 18%, var(--lpc-surface)); color: color-mix(in srgb, #C026D3 65%, var(--lpc-ink)); }
    .kind-cnps    { background: var(--lpc-status-warning-bg); color: var(--lpc-status-warning-fg); }
    .kind-tsr     { background: var(--lpc-status-danger-bg);  color: var(--lpc-status-danger-fg);  }
    .kind-commune { background: var(--lpc-status-neutral-bg); color: var(--lpc-status-neutral-fg); }
    /* Master monthly modal */
    #tax-monthly-modal { position: fixed; inset: 0; background: rgba(15,23,42,0.65); z-index: 90; display: none; align-items: flex-start; justify-content: center; overflow-y: auto; padding: 24px 12px; }
    #tax-monthly-modal.open { display: flex; }
    #tax-monthly-modal .panel { background: white; border-radius: 16px; width: 100%; max-width: 1080px; box-shadow: 0 30px 60px -12px rgba(0,0,0,0.4); overflow: hidden; }
    #tax-monthly-modal header { position: sticky; top: 0; background: linear-gradient(90deg, #065f46, #047857); color: white; padding: 20px 28px; z-index: 1; }
    #tax-monthly-modal .body { padding: 24px 28px; max-height: 82vh; overflow-y: auto; }
    #tax-monthly-modal .kind-section { border: 1px solid #e5e7eb; border-radius: 12px; margin-bottom: 16px; overflow: hidden; }
    #tax-monthly-modal .kind-section > header { position: static; background: #f8fafc; color: #0f172a; padding: 12px 18px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    #tax-monthly-modal .kind-section .content { padding: 12px 18px; }
    #tax-monthly-modal table.lines { width: 100%; font-size: 13px; }
    #tax-monthly-modal table.lines th, #tax-monthly-modal table.lines td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; }
    #tax-monthly-modal table.lines th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
    #tax-monthly-modal .info-line { color: #64748b; font-style: italic; }
    #tax-monthly-modal .credit-line { color: #166534; }
    #tax-monthly-modal .totals { background: #f8fafc; padding: 12px 18px; text-align: right; font-weight: 700; }
</style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">

<?php
$pageTitle    = __t('ui.x.declarations_fiscales_sociales');
$pageSubtitle = 'DGI · CNPS · Commune — Cameroun 2026';
$sidebar_path = $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
if (file_exists($sidebar_path)) require_once $sidebar_path;
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">

    <!-- Page-local toolbar. Per README §5.5 this is where the page-specific
         help button lives (the topbar "?" is for the centre as a whole).
         The chosen year drives the Déclarations tab and (for context) the
         history table on the Échéances tab. -->
    <div class="lpc-toolbar flex items-center justify-between px-4 py-3">
        <div class="flex items-center gap-3">
            <label class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Exercice</label>
            <select id="tax-year" class="border border-gray-300 rounded-lg px-3 py-1.5 font-bold text-sm">
                <?php $yy = (int) date('Y'); for ($y = $yy - 3; $y <= $yy + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y === $yy ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <span class="text-xs text-gray-400 hidden md:inline">Régime <strong class="uppercase"><?= htmlspecialchars($settings['tax_regime'] ?? 'reel') ?></strong> · NIU <strong><?= htmlspecialchars($settings['niu'] ?? '—') ?></strong></span>
        </div>
        <div class="flex items-center gap-2">
            <?= lpc_help_link('accounting.tax_declarations', $lang) ?>
        </div>
    </div>

    <nav class="lpc-tabs">
        <button onclick="switchTab('dashboard')"   id="tab-dashboard"   class="tab-link py-4 border-b-[3px] border-emerald-600 text-emerald-700 font-black text-sm uppercase tracking-wider"><i class="fas fa-chart-line mr-2"></i><?= htmlspecialchars(__t('ui.x.echeances')) ?></button>
        <button onclick="switchTab('monthly')"     id="tab-monthly"     class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 font-bold text-sm uppercase tracking-wider"><i class="fas fa-file-invoice mr-2"></i><?= htmlspecialchars(__t('ui.x.declarations_mensuelles')) ?></button>
        <button onclick="switchTab('withholding')" id="tab-withholding" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 font-bold text-sm uppercase tracking-wider"><i class="fas fa-receipt mr-2"></i><?= htmlspecialchars(__t('ui.x.retenues_a_la_source')) ?></button>
        <button onclick="switchTab('settings')"    id="tab-settings"    class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 font-bold text-sm uppercase tracking-wider"><i class="fas fa-sliders-h mr-2"></i><?= htmlspecialchars(__t('ui.x.parametres')) ?></button>
    </nav>

    <main role="main" id="main" class="lpc-page">

        <!-- ============ DASHBOARD ============ -->
        <div id="content-dashboard" class="tab-content active">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.prochaine_echeance')) ?></p>
                    <h3 class="text-2xl font-black text-gray-900 mt-2" id="kpi_next_due">—</h3>
                </div>
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.total_a_decaisser_30j')) ?></p>
                    <h3 class="text-2xl font-black text-red-600 mt-2 amt" id="kpi_next_amount">—</h3>
                </div>
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.air_retenu_a_recuperer')) ?></p>
                    <h3 class="text-2xl font-black text-emerald-600 mt-2 amt" id="kpi_air_credit">—</h3>
                </div>
            </div>

            <!-- AIR pending attestations (Batch B) -->
            <div id="pending-attest-card" class="hidden bg-amber-50 border-2 border-amber-300 rounded-2xl shadow-sm p-6 mb-8">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <p class="text-[10px] font-black text-amber-800 uppercase tracking-widest flex items-center gap-1.5"><i class="fas fa-exclamation-triangle"></i> AIR retenu en attente d'attestation</p>
                        <h3 class="text-2xl font-black text-amber-900 mt-2 amt" id="pending-attest-total">— FCFA</h3>
                        <p class="text-xs text-amber-800 mt-1" id="pending-attest-subline">Aucune retenue en attente.</p>
                    </div>
                    <button onclick="switchTab('withholding')" class="text-xs font-black text-amber-800 hover:text-amber-950 uppercase tracking-wider whitespace-nowrap"><i class="fas fa-upload mr-1"></i>Téléverser une attestation</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-[10px] font-black text-amber-900 uppercase tracking-wider">
                            <tr>
                                <th class="text-left px-3 py-2">Client</th>
                                <th class="text-left px-3 py-2">Période</th>
                                <th class="text-right px-3 py-2">Nb paiements</th>
                                <th class="text-right px-3 py-2">AIR à attester</th>
                                <th class="text-right px-3 py-2">Action</th>
                            </tr>
                        </thead>
                        <tbody id="pending-attest-body" class="divide-y divide-amber-200"></tbody>
                    </table>
                </div>
                <p class="text-[10px] text-amber-700 mt-3 italic">Seules les retenues certifiées (attestation reçue) sont créditées sur la déclaration AIR mensuelle. Cf. art. L.94 LPF.</p>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="font-black text-gray-900"><?= htmlspecialchars(__t('ui.x.echeances_imminentes')) ?></h2>
                    <button onclick="loadDashboard()" class="text-xs font-bold text-emerald-700 hover:text-emerald-900"><i class="fas fa-sync-alt mr-1"></i><?= htmlspecialchars(__t('ui.actualiser')) ?></button>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] font-black text-gray-500 uppercase">
                        <tr>
                            <th class="text-left px-6 py-3"><?= htmlspecialchars(__t('ui.x.type')) ?></th>
                            <th class="text-left px-6 py-3"><?= htmlspecialchars(__t('ui.x.periode')) ?></th>
                            <th class="text-left px-6 py-3">Destinataire</th>
                            <th class="text-right px-6 py-3"><?= htmlspecialchars(__t('ui.x.brut')) ?></th>
                            <th class="text-right px-6 py-3"><?= htmlspecialchars(__t('ui.x.credit')) ?></th>
                            <th class="text-right px-6 py-3"><?= htmlspecialchars(__t('ui.x.a_payer')) ?></th>
                            <th class="text-left px-6 py-3"><?= htmlspecialchars(__t('ui.x.echeance')) ?></th>
                            <th class="text-right px-6 py-3"><?= htmlspecialchars(__t('ui.x.actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody id="upcoming-body" class="divide-y divide-gray-100">
                        <tr><td colspan="8" class="px-6 py-8 text-center text-gray-400"><?= htmlspecialchars(__t('ui.account.loading')) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============ MONTHLY DECLARATIONS ============ -->
        <div id="content-monthly" class="tab-content">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <div>
                        <h3 class="font-black text-gray-900">Calendrier des déclarations <span id="mg_year">—</span></h3>
                        <p class="text-xs text-gray-500 mt-1">Cliquez sur un mois pour ouvrir le récapitulatif complet (TVA + AIR + DIPE + CNPS + TSR + commune) avec les attestations, l'approbation et le PDF.</p>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="badge-status st-missing">à faire</span>
                        <span class="badge-status st-ready">calculée</span>
                        <span class="badge-status st-locked">approuvée</span>
                        <span class="badge-status st-paid">payée</span>
                    </div>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] font-black text-gray-500 uppercase">
                        <tr>
                            <th class="text-left px-6 py-3">Mois</th>
                            <th class="text-left px-6 py-3">Statuts</th>
                            <th class="text-right px-6 py-3">DGI</th>
                            <th class="text-right px-6 py-3">CNPS</th>
                            <th class="text-right px-6 py-3">Commune</th>
                            <th class="text-right px-6 py-3">Total net</th>
                            <th class="text-left px-6 py-3">Échéance</th>
                        </tr>
                    </thead>
                    <tbody id="month-grid-body" class="divide-y divide-gray-100">
                        <tr><td colspan="7" class="px-6 py-8 text-center text-gray-400"><?= htmlspecialchars(__t('ui.account.loading')) ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="font-black text-gray-900"><?= htmlspecialchars(__t('ui.x.historique')) ?></h3>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] font-black text-gray-500 uppercase">
                        <tr>
                            <th class="text-left px-6 py-3"><?= htmlspecialchars(__t('ui.x.type')) ?></th>
                            <th class="text-left px-6 py-3"><?= htmlspecialchars(__t('ui.x.periode')) ?></th>
                            <th class="text-right px-6 py-3"><?= htmlspecialchars(__t('ui.x.brut')) ?></th>
                            <th class="text-right px-6 py-3"><?= htmlspecialchars(__t('ui.x.net')) ?></th>
                            <th class="text-left px-6 py-3"><?= htmlspecialchars(__t('ui.x.statut')) ?></th>
                            <th class="text-right px-6 py-3"><?= htmlspecialchars(__t('ui.x.actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody id="history-body" class="divide-y divide-gray-100">
                        <tr><td colspan="6" class="px-6 py-8 text-center text-gray-400"><?= htmlspecialchars(__t('ui.account.loading')) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============ WITHHOLDING CERTIFICATES ============ -->
        <div id="content-withholding" class="tab-content">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 mb-6">
                <h3 class="font-black text-gray-900 mb-2"><?= htmlspecialchars(__t('ui.x.charger_une_attestation_de_retenue_a_la')) ?></h3>
                <p class="text-xs text-gray-500 mb-4">Prometal, SONARA, PMUC et autres clients habilités DGI vous délivrent chaque mois une attestation prouvant qu'ils ont reversé la retenue AIR (2.2 % ou 5.5 %) à l'État. Cette attestation est <strong><?= htmlspecialchars(__t('ui.x.obligatoire')) ?></strong> <?= htmlspecialchars(__t('ui.x.pour_qu_on_impute_cette_retenue_en_credi')) ?></p>
                <form id="cert-form" onsubmit="uploadCertificate(event)" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <select name="client_id" id="wc_client" required class="border border-gray-300 rounded-lg px-3 py-2 font-semibold col-span-1"><option value=""><?= htmlspecialchars(__t('ui.x.client_2')) ?></option></select>
                    <input name="certificate_ref" type="text" placeholder="N° attestation" required class="border border-gray-300 rounded-lg px-3 py-2 font-semibold">
                    <input name="issue_date" type="date" required class="border border-gray-300 rounded-lg px-3 py-2 font-semibold">
                    <input name="period_year" type="number" value="<?= date('Y') ?>" required class="border border-gray-300 rounded-lg px-3 py-2 font-semibold">
                    <input name="period_month" type="number" min="1" max="12" value="<?= (int) date('n') - 1 ?: 12 ?>" required class="border border-gray-300 rounded-lg px-3 py-2 font-semibold">
                    <input name="total_amount" type="number" step="1" placeholder="Montant AIR (FCFA)" required class="border border-gray-300 rounded-lg px-3 py-2 font-semibold">
                    <input name="file" type="file" accept="application/pdf,image/*" class="col-span-2">
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-black text-sm uppercase tracking-wider rounded-lg px-4 py-2"><i class="fas fa-upload mr-2"></i><?= htmlspecialchars(__t('ui.x.enregistrer')) ?></button>
                </form>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                <h3 class="font-black text-gray-900 mb-4"><?= htmlspecialchars(__t('ui.x.attestations_recues')) ?></h3>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] font-black text-gray-500 uppercase">
                        <tr>
                            <th class="text-left px-4 py-3"><?= htmlspecialchars(__t('ui.x.client')) ?></th>
                            <th class="text-left px-4 py-3"><?= htmlspecialchars(__t('ui.x.n_attest')) ?></th>
                            <th class="text-left px-4 py-3"><?= htmlspecialchars(__t('ui.x.periode')) ?></th>
                            <th class="text-right px-4 py-3"><?= htmlspecialchars(__t('ui.x.montant')) ?></th>
                            <th class="text-left px-4 py-3"><?= htmlspecialchars(__t('ui.x.fichier')) ?></th>
                        </tr>
                    </thead>
                    <tbody id="cert-body" class="divide-y divide-gray-100">
                        <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400"><?= htmlspecialchars(__t('ui.account.loading')) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============ SETTINGS ============ -->
        <div id="content-settings" class="tab-content">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <a href="/modules/settings/index.php?tab=company#fiscal" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 hover:border-emerald-600 hover:shadow-md transition group no-underline text-inherit">
                    <div class="flex items-start gap-4">
                        <div class="w-11 h-11 rounded-full bg-emerald-100 grid place-items-center text-emerald-700"><i class="fas fa-building"></i></div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Identité fiscale</p>
                            <h3 class="font-black text-gray-900 mt-1">Fiche entreprise → régime, NIU, centre des impôts</h3>
                            <p class="text-xs text-gray-500 mt-1">Ouvre la fiche entreprise dans le module Administration, section "Régime fiscal & social". Modification tracée dans les logs d'audit.</p>
                        </div>
                        <i class="fas fa-arrow-up-right-from-square text-gray-300 group-hover:text-emerald-600 mt-1"></i>
                    </div>
                </a>
                <a href="/modules/settings/index.php?tab=preferences#tax" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 hover:border-emerald-600 hover:shadow-md transition group no-underline text-inherit">
                    <div class="flex items-start gap-4">
                        <div class="w-11 h-11 rounded-full bg-blue-100 grid place-items-center text-blue-700"><i class="fas fa-percent"></i></div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Taux fiscaux</p>
                            <h3 class="font-black text-gray-900 mt-1">Préférences → TVA, AIR, IS, CNPS, FNE, AT, commune</h3>
                            <p class="text-xs text-gray-500 mt-1">Toute la table des taux (%, jour d'échéance, groupe CNPS, forfait commune) sur une seule page. Sauvegarde immédiate.</p>
                        </div>
                        <i class="fas fa-arrow-up-right-from-square text-gray-300 group-hover:text-emerald-600 mt-1"></i>
                    </div>
                </a>
                <a href="/modules/hr/payroll_finance.php" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 hover:border-emerald-600 hover:shadow-md transition group no-underline text-inherit">
                    <div class="flex items-start gap-4">
                        <div class="w-11 h-11 rounded-full bg-fuchsia-100 grid place-items-center text-fuchsia-700"><i class="fas fa-users"></i></div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Paie & masse salariale</p>
                            <h3 class="font-black text-gray-900 mt-1">Générer la paie → alimente DIPE & CNPS</h3>
                            <p class="text-xs text-gray-500 mt-1">Chaque bulletin de paie valide est agrégé automatiquement dans la déclaration DIPE (IRPP, CAC, CFC, CRTV, TDL, FNE) et CNPS (PVID, PF, AT) du mois correspondant.</p>
                        </div>
                        <i class="fas fa-arrow-up-right-from-square text-gray-300 group-hover:text-emerald-600 mt-1"></i>
                    </div>
                </a>
                <a href="/modules/help/index.php?slug=declarations-fiscales-vue-densemble" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 hover:border-emerald-600 hover:shadow-md transition group no-underline text-inherit">
                    <div class="flex items-start gap-4">
                        <div class="w-11 h-11 rounded-full bg-amber-100 grid place-items-center text-amber-700"><i class="fas fa-book"></i></div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Guide fiscal</p>
                            <h3 class="font-black text-gray-900 mt-1">Ouvrir le guide "Déclarations fiscales — vue d'ensemble"</h3>
                            <p class="text-xs text-gray-500 mt-1">Toutes les règles CGI 2026 utilisées par le moteur, article par article, plus les procédures d'approbation, de paiement et d'archivage pour audit.</p>
                        </div>
                        <i class="fas fa-arrow-up-right-from-square text-gray-300 group-hover:text-emerald-600 mt-1"></i>
                    </div>
                </a>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 max-w-4xl">
                <h3 class="font-black text-gray-900 mb-4">Paramètres actuellement appliqués</h3>
                <div id="settings-form" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div><label class="text-[10px] font-black text-gray-500 uppercase"><?= htmlspecialchars(__t('ui.x.regime')) ?></label><div id="s_regime" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">NIU</label><div id="s_niu" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">Taux AIR</label><div id="s_air" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase"><?= htmlspecialchars(__t('ui.x.taux_tva')) ?></label><div id="s_tva" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">Taux IS</label><div id="s_cit" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">Taux FNE (patronal)</label><div id="s_fne" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">Groupe CNPS (AT)</label><div id="s_cnps" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">Taux AT (patronal)</label><div id="s_at" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">Taxe communale (mensuel)</label><div id="s_commune" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">Jour d'échéance DGI/CNPS</label><div id="s_filing_day" class="font-bold text-gray-900 mt-1">—</div></div>
                </div>
                <p class="text-xs text-gray-500 mt-4"><i class="fas fa-info-circle mr-1"></i>Les taux ci-dessus sont ceux effectivement utilisés par le moteur de calcul. Toute modification passe par les deux raccourcis en haut de page — sauvegardée dans <code>app_preferences</code> et propagée dans <code>company_tax_settings</code>.</p>
            </div>
        </div>

    </main>
</div>

<!-- ==================================================================
     MASTER MONTHLY MODAL
     Opens when a month is clicked in the Déclarations mensuelles grid.
     Shows every declaration for that month in one place, with the
     attestations, CNPS/DGI/commune totals, approve, PDF, payment.
     ================================================================== -->
<div id="tax-monthly-modal" role="dialog" aria-modal="true" aria-labelledby="tmm-title">
    <div class="panel">
        <header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-white/60">Récapitulatif fiscal du mois</p>
                    <h2 id="tmm-title" class="text-2xl font-black mt-1">—</h2>
                    <p id="tmm-subtitle" class="text-xs text-white/80 mt-1">—</p>
                </div>
                <button onclick="closeMonthlyModal()" class="w-9 h-9 rounded-full bg-white/15 hover:bg-white/30 text-white grid place-items-center" aria-label="Fermer"><i class="fas fa-times"></i></button>
            </div>
            <!-- Executive totals -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-5">
                <div class="rounded-lg bg-white/15 px-4 py-2"><p class="text-[10px] font-black uppercase text-white/70">Total DGI</p><p id="tmm-dgi" class="text-lg font-black amt">—</p></div>
                <div class="rounded-lg bg-white/15 px-4 py-2"><p class="text-[10px] font-black uppercase text-white/70">Total CNPS</p><p id="tmm-cnps" class="text-lg font-black amt">—</p></div>
                <div class="rounded-lg bg-white/15 px-4 py-2"><p class="text-[10px] font-black uppercase text-white/70">Total Commune</p><p id="tmm-commune" class="text-lg font-black amt">—</p></div>
                <div class="rounded-lg bg-white text-emerald-900 px-4 py-2"><p class="text-[10px] font-black uppercase text-emerald-700">Grand total</p><p id="tmm-grand" class="text-lg font-black amt">—</p></div>
            </div>
        </header>
        <div class="body" id="tmm-body">
            <p class="text-sm text-gray-400 text-center py-12"><?= htmlspecialchars(__t('ui.account.loading')) ?></p>
        </div>
    </div>
</div>

<script type="application/json" id="lpc-page-data"><?= json_encode([
    'regime'           => htmlspecialchars($settings['tax_regime'] ?? 'reel'),
    'air_rate'         => (float) ($settings['air_rate'] ?? 0.022),
    'tva_rate'         => (float) ($settings['tva_rate'] ?? 0.1925),
    'cit_rate'         => (float) ($settings['cit_rate'] ?? 0.33),
    'fne_rate'         => (float) ($settings['fne_rate'] ?? 0.01),
    'at_rate'          => (float) ($settings['at_rate']  ?? 0.0175),
    'cnps_group'       => htmlspecialchars($settings['cnps_group'] ?? 'A'),
    'commune_monthly'  => (float) ($settings['commune_monthly'] ?? 0),
    'filing_day'       => (int)   ($settings['filing_day'] ?? 15),
    'niu'              => htmlspecialchars($settings['niu'] ?? '—'),
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/accounting-tax_declarations.js') ?>" defer></script>
</body>
</html>
