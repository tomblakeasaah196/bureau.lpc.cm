<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('fleet.breakdown.report');
$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Déclarer Panne | LPC</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    
</head>
<body class="bg-gray-100 font-sans text-gray-800 antialiased flex flex-col h-screen overflow-hidden">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <header class="bg-red-600 text-white shadow-md h-16 flex items-center px-4 shrink-0 relative">
        <a href="/modules/dashboard/views/driver_dashboard.php" class="absolute left-4 text-xl"><i class="fas fa-arrow-left"></i></a>
        <h1 class="w-full text-center text-lg font-black tracking-wide"><i class="fas fa-tools mr-2"></i> Déclarer une Panne</h1>
    </header>

    <main role="main" id="main" class="flex-1 overflow-y-auto p-4 pb-8">
        <div class="bg-red-50 border border-red-200 p-4 rounded-2xl mb-4 text-center">
            <i class="fas fa-exclamation-triangle text-3xl text-red-500 mb-2"></i>
            <p class="text-xs font-bold text-red-800">Ceci alertera immédiatement la logistique et bloquera le véhicule pour de futures affectations.</p>
        </div>

        <form id="form-breakdown" class="bg-white rounded-2xl shadow-sm p-6 space-y-5" onsubmit="event.preventDefault(); submitBreakdown();">
            
            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">Mon Véhicule *</label>
                <select id="maint_vehicle_id" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-4 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-red-500">
                    <option value="">Chargement...</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">Odomètre Actuel (Km) *</label>
                <div class="relative">
                    <i class="fas fa-tachometer-alt absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="number" id="maint_odo" required min="0" placeholder="Ex: 102550" class="w-full bg-white border border-gray-200 rounded-xl pl-12 pr-4 py-4 text-lg font-black text-gray-900 outline-none focus:border-red-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">Description du Problème *</label>
                <textarea id="maint_desc" rows="3" required placeholder="Ex: Pneu crevé à Bonabéri, impossible d'avancer..." class="w-full bg-white border border-gray-200 rounded-xl p-4 text-sm font-medium text-gray-900 outline-none focus:ring-2 focus:ring-red-500 resize-none"></textarea>
            </div>

            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-1.5">Coût Dépannage (Si payé sur place)</label>
                <div class="relative">
                    <input type="number" id="maint_cost" value="0" min="0" class="w-full bg-white border border-gray-200 rounded-xl p-4 text-lg font-black text-red-600 outline-none focus:border-red-500">
                    <span class="absolute right-4 top-1/2 -translate-y-1/2 font-black text-gray-400">FCFA</span>
                </div>
            </div>

            <button type="submit" id="btn-submit" class="w-full bg-red-600 hover:bg-red-700 active:scale-95 transition-transform text-white py-4 rounded-xl font-black text-lg shadow-lg flex justify-center items-center gap-2 mt-4">
                <i class="fas fa-bullhorn"></i> Signaler la Panne
            </button>
        </form>
    </main>

    <script src="/assets/js/modules/fleet-report_breakdown.js" defer></script>
</body>
</html>