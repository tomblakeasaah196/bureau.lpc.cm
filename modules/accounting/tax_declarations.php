<?php
/**
 * MODULE: Déclarations Fiscales & Sociales (Cameroun)
 * DESCRIPTION: One place for every DGI / CNPS / commune filing —
 *              TVA, AIR (2.2% / 5.5%), DIPE (IRPP + CAC + CFC + CRTV + TDL
 *              + CNPS), TSR, patente. No expert-comptable required for
 *              routine monthly ops: everything comes from the accounting
 *              records already in the system.
 *
 * The page has 4 tabs:
 *   • Tableau de bord — next deadlines, YTD paid, credit reportable
 *   • Déclarations mensuelles — compute + save + mark filed/paid
 *   • Retenues à la source — upload client attestations
 *   • Paramètres fiscaux — company régime, CIT rate, CNPS group, etc.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('accounting.invoices.view');
require_once __DIR__ . '/../../includes/classes/TaxEngine.php';
$lang = ($_GET['lang'] ?? 'fr') === 'en' ? 'en' : 'fr';
$user_role = $_SESSION['user_role'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Déclarations Fiscales | LPC ERP</title>
<link rel="stylesheet" href="/assets/css/tailwind.css">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<style>
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    .badge-status { padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; }
    .st-draft { background: #f1f5f9; color: #475569; }
    .st-ready { background: #fef3c7; color: #92400e; }
    .st-filed { background: #dbeafe; color: #1e40af; }
    .st-paid  { background: #dcfce7; color: #166534; }
    .amt { font-variant-numeric: tabular-nums; }
</style>
</head>
<body class="bg-slate-50 font-sans text-gray-800 flex h-screen overflow-hidden">

<?php
$sidebar_path = $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
if (file_exists($sidebar_path)) require_once $sidebar_path;
?>

<div class="flex-1 flex flex-col min-w-0 overflow-hidden">

    <header class="bg-white border-b border-gray-200 px-8 py-5 flex justify-between items-center shrink-0 shadow-sm">
        <div class="flex items-center gap-5">
            <div class="w-14 h-14 bg-gradient-to-br from-emerald-700 to-emerald-500 rounded-2xl flex items-center justify-center text-white shadow-lg">
                <i class="fas fa-landmark text-2xl"></i>
            </div>
            <div>
                <h1 class="text-2xl font-black text-gray-900 tracking-tight">Déclarations Fiscales & Sociales</h1>
                <p class="text-xs text-gray-500 font-bold uppercase tracking-widest mt-1 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span> DGI · CNPS · Commune — Cameroun 2026
                </p>
            </div>
        </div>
        <div class="text-right">
            <p class="text-sm font-black text-gray-900"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur') ?></p>
            <p class="text-[10px] font-bold text-emerald-600 uppercase mt-1"><?= htmlspecialchars($user_role) ?></p>
        </div>
    </header>

    <nav class="bg-white border-b border-gray-200 px-8 flex items-center gap-8 shrink-0 shadow-sm">
        <button onclick="switchTab('dashboard')"   id="tab-dashboard"   class="tab-link py-4 border-b-[3px] border-emerald-600 text-emerald-700 font-black text-sm uppercase tracking-wider"><i class="fas fa-chart-line mr-2"></i>Échéances</button>
        <button onclick="switchTab('monthly')"     id="tab-monthly"     class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 font-bold text-sm uppercase tracking-wider"><i class="fas fa-file-invoice mr-2"></i>Déclarations mensuelles</button>
        <button onclick="switchTab('withholding')" id="tab-withholding" class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 font-bold text-sm uppercase tracking-wider"><i class="fas fa-receipt mr-2"></i>Retenues à la source</button>
        <button onclick="switchTab('settings')"    id="tab-settings"    class="tab-link py-4 border-b-[3px] border-transparent text-gray-400 font-bold text-sm uppercase tracking-wider"><i class="fas fa-sliders-h mr-2"></i>Paramètres</button>
    </nav>

    <main class="flex-1 overflow-y-auto p-8">

        <!-- ============ DASHBOARD ============ -->
        <div id="content-dashboard" class="tab-content active">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Prochaine échéance</p>
                    <h3 class="text-2xl font-black text-gray-900 mt-2" id="kpi_next_due">—</h3>
                </div>
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Total à décaisser (30j)</p>
                    <h3 class="text-2xl font-black text-red-600 mt-2 amt" id="kpi_next_amount">—</h3>
                </div>
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">AIR retenu à récupérer</p>
                    <h3 class="text-2xl font-black text-emerald-600 mt-2 amt" id="kpi_air_credit">—</h3>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="font-black text-gray-900">Échéances imminentes</h2>
                    <button onclick="loadDashboard()" class="text-xs font-bold text-emerald-700 hover:text-emerald-900"><i class="fas fa-sync-alt mr-1"></i>Actualiser</button>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] font-black text-gray-500 uppercase">
                        <tr>
                            <th class="text-left px-6 py-3">Type</th>
                            <th class="text-left px-6 py-3">Période</th>
                            <th class="text-right px-6 py-3">Brut</th>
                            <th class="text-right px-6 py-3">Crédit</th>
                            <th class="text-right px-6 py-3">À payer</th>
                            <th class="text-left px-6 py-3">Échéance</th>
                            <th class="text-right px-6 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="upcoming-body" class="divide-y divide-gray-100">
                        <tr><td colspan="7" class="px-6 py-8 text-center text-gray-400">Chargement…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============ MONTHLY DECLARATIONS ============ -->
        <div id="content-monthly" class="tab-content">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 mb-6">
                <h3 class="font-black text-gray-900 mb-4">Calculer une déclaration</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <select id="cf_kind" class="border border-gray-300 rounded-lg px-3 py-2 font-semibold">
                        <option value="tva">TVA — 19.25%</option>
                        <option value="air" selected>AIR — Acompte IS (2.2% / 5.5%)</option>
                        <option value="irpp_dipe">DIPE — IRPP + CAC + CFC + CRTV + CNPS</option>
                        <option value="tsr">TSR — Services étrangers</option>
                        <option value="patente">Patente (annuel)</option>
                    </select>
                    <input id="cf_year"  type="number" value="<?= date('Y') ?>" class="border border-gray-300 rounded-lg px-3 py-2 font-semibold">
                    <input id="cf_month" type="number" min="1" max="12" value="<?= (int) date('n') - 1 ?: 12 ?>" class="border border-gray-300 rounded-lg px-3 py-2 font-semibold">
                    <button onclick="computeDeclaration()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-black text-sm uppercase tracking-wider rounded-lg px-4 py-2"><i class="fas fa-calculator mr-2"></i>Calculer</button>
                </div>
            </div>

            <div id="compute-result" class="hidden bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <div>
                        <h3 class="font-black text-gray-900" id="cr_title">—</h3>
                        <p class="text-xs text-gray-500 mt-1" id="cr_period">—</p>
                    </div>
                    <div class="flex gap-3">
                        <button onclick="persistDeclaration()" class="bg-blue-600 hover:bg-blue-700 text-white font-black text-xs uppercase tracking-wider rounded-lg px-4 py-2"><i class="fas fa-save mr-2"></i>Enregistrer</button>
                        <button onclick="window.print()" class="bg-slate-600 hover:bg-slate-700 text-white font-black text-xs uppercase tracking-wider rounded-lg px-4 py-2"><i class="fas fa-print mr-2"></i>Imprimer</button>
                    </div>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] font-black text-gray-500 uppercase">
                        <tr><th class="text-left px-6 py-3">Code</th><th class="text-left px-6 py-3">Libellé</th><th class="text-right px-6 py-3">Montant (FCFA)</th></tr>
                    </thead>
                    <tbody id="cr-lines" class="divide-y divide-gray-100"></tbody>
                    <tfoot class="bg-slate-100 text-sm font-black">
                        <tr><td colspan="2" class="px-6 py-3 text-right">Brut</td><td class="px-6 py-3 text-right amt" id="cr_total">—</td></tr>
                        <tr><td colspan="2" class="px-6 py-3 text-right text-emerald-700">Crédit</td><td class="px-6 py-3 text-right text-emerald-700 amt" id="cr_credit">—</td></tr>
                        <tr class="border-t-2 border-emerald-600"><td colspan="2" class="px-6 py-4 text-right text-lg">NET à payer</td><td class="px-6 py-4 text-right text-lg amt text-red-600" id="cr_net">—</td></tr>
                    </tfoot>
                </table>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="font-black text-gray-900">Historique</h3>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] font-black text-gray-500 uppercase">
                        <tr><th class="text-left px-6 py-3">Type</th><th class="text-left px-6 py-3">Période</th><th class="text-right px-6 py-3">Brut</th><th class="text-right px-6 py-3">Net</th><th class="text-left px-6 py-3">Statut</th><th class="text-right px-6 py-3">Actions</th></tr>
                    </thead>
                    <tbody id="history-body" class="divide-y divide-gray-100"><tr><td colspan="6" class="px-6 py-8 text-center text-gray-400">Chargement…</td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- ============ WITHHOLDING CERTIFICATES ============ -->
        <div id="content-withholding" class="tab-content">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 mb-6">
                <h3 class="font-black text-gray-900 mb-2">Charger une attestation de retenue à la source</h3>
                <p class="text-xs text-gray-500 mb-4">Prometal, SONARA, PMUC et autres clients habilités DGI vous délivrent chaque mois une attestation prouvant qu'ils ont reversé la retenue AIR (2.2 % ou 5.5 %) à l'État. Cette attestation est <strong>obligatoire</strong> pour qu'on impute cette retenue en crédit sur votre AIR mensuel — sinon vous risquez de payer deux fois.</p>
                <form id="cert-form" onsubmit="uploadCertificate(event)" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <select name="client_id" id="wc_client" required class="border border-gray-300 rounded-lg px-3 py-2 font-semibold col-span-1"><option value="">— Client —</option></select>
                    <input name="certificate_ref" type="text" placeholder="N° attestation" required class="border border-gray-300 rounded-lg px-3 py-2 font-semibold">
                    <input name="issue_date" type="date" required class="border border-gray-300 rounded-lg px-3 py-2 font-semibold">
                    <input name="period_year" type="number" value="<?= date('Y') ?>" required class="border border-gray-300 rounded-lg px-3 py-2 font-semibold">
                    <input name="period_month" type="number" min="1" max="12" value="<?= (int) date('n') - 1 ?: 12 ?>" required class="border border-gray-300 rounded-lg px-3 py-2 font-semibold">
                    <input name="total_amount" type="number" step="1" placeholder="Montant AIR (FCFA)" required class="border border-gray-300 rounded-lg px-3 py-2 font-semibold">
                    <input name="file" type="file" accept="application/pdf,image/*" class="col-span-2">
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-black text-sm uppercase tracking-wider rounded-lg px-4 py-2"><i class="fas fa-upload mr-2"></i>Enregistrer</button>
                </form>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                <h3 class="font-black text-gray-900 mb-4">Attestations reçues</h3>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] font-black text-gray-500 uppercase">
                        <tr><th class="text-left px-4 py-3">Client</th><th class="text-left px-4 py-3">N° attest.</th><th class="text-left px-4 py-3">Période</th><th class="text-right px-4 py-3">Montant</th><th class="text-left px-4 py-3">Fichier</th></tr>
                    </thead>
                    <tbody id="cert-body" class="divide-y divide-gray-100"><tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Aucune attestation.</td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- ============ SETTINGS ============ -->
        <div id="content-settings" class="tab-content">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 max-w-3xl">
                <h3 class="font-black text-gray-900 mb-4">Paramètres fiscaux de l'entreprise</h3>
                <div id="settings-form" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">Régime</label><div id="s_regime" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">Taux AIR</label><div id="s_air" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">Taux TVA</label><div id="s_tva" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">Taux IS</label><div id="s_cit" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">Groupe CNPS (AT)</label><div id="s_cnps" class="font-bold text-gray-900 mt-1">—</div></div>
                    <div><label class="text-[10px] font-black text-gray-500 uppercase">NIU</label><div id="s_niu" class="font-bold text-gray-900 mt-1">—</div></div>
                </div>
                <p class="text-xs text-gray-500 mt-4">Les paramètres sont modifiables depuis <code>company_tax_settings</code> — un module dédié sera ajouté; en attendant contactez l'administrateur.</p>
            </div>
        </div>

    </main>
</div>

<script type="application/json" id="lpc-page-data"><?= json_encode(['v1' => htmlspecialchars(TaxEngine::settings()["tax_regime"] ?? "reel"),'v2' => (float) (TaxEngine::settings()["air_rate"] ?? 0.022),'v3' => (float) (TaxEngine::settings()["tva_rate"] ?? 0.1925),'v4' => (float) (TaxEngine::settings()["cit_rate"] ?? 0.33),'v5' => htmlspecialchars(TaxEngine::settings()["cnps_group"] ?? "A"),'v6' => htmlspecialchars(TaxEngine::settings()["niu"] ?? "—")], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="/assets/js/modules/accounting-tax_declarations.js" defer></script>
</body>
</html>
