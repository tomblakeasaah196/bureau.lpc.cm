<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('fleet.fuel.log');
$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Saisie Carburant | LPC</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <!-- Sprint 5: client-side image compression before upload. -->
    <script src="/assets/js/lpc-image-compress.js" defer></script>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="bg-gray-100 font-sans text-gray-800 antialiased min-h-[100dvh] flex flex-col relative">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <div id="loading-overlay" class="hidden fixed inset-0 bg-white/90 backdrop-blur-sm z-[100] flex flex-col items-center justify-center">
        <i class="fas fa-circle-notch fa-spin text-5xl text-lpc-dark mb-4"></i>
        <h2 class="text-xl font-black text-gray-900">Envoi en cours...</h2>
        <p class="text-sm font-bold text-gray-500 mt-2">Veuillez patienter.</p>
    </div>

    <header class="bg-lpc-dark text-white shadow-md h-16 flex items-center px-4 shrink-0 sticky top-0 z-50">
        <a href="/modules/dashboard/views/driver_dashboard.php" class="absolute left-4 text-xl"><i class="fas fa-arrow-left"></i></a>
        <h1 class="w-full text-center text-lg font-black tracking-wide"><i class="fas fa-gas-pump mr-2 text-lpc-light"></i> Plein Carburant</h1>
    </header>

    <main role="main" id="main" class="flex-1 w-full max-w-md mx-auto p-4 pb-12">
        
        <div id="no-vehicle-error" class="hidden bg-red-50 border border-red-200 p-4 rounded-2xl mb-4 text-center">
            <i class="fas fa-truck-slash text-3xl text-red-500 mb-2"></i>
            <p class="text-sm font-black text-red-800" id="error-msg">Aucun véhicule assigné.</p>
            <p class="text-xs font-bold text-red-600 mt-1">Veuillez contacter la logistique pour votre affectation du jour.</p>
        </div>

        <form id="form-fuel" class="hidden bg-white rounded-2xl shadow-md p-5 space-y-6" onsubmit="event.preventDefault(); submitFuel();">
            
            <input type="hidden" id="fuel_vehicle_id">

            <div class="bg-green-50 p-4 rounded-xl border border-green-200 flex justify-between items-center">
                <div>
                    <p class="text-[10px] font-black text-lpc-dark uppercase tracking-widest">Véhicule Assigné</p>
                    <p class="text-lg font-black text-gray-900 uppercase" id="display_plate">---</p>
                </div>
                <i class="fas fa-check-circle text-lpc-light text-2xl"></i>
            </div>

            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-2">Photo du Ticket (Obligatoire) <span class="text-red-500">*</span></label>
                <div class="relative w-full h-40 border-2 border-dashed border-lpc-dark bg-green-50/50 rounded-xl flex flex-col items-center justify-center text-lpc-dark cursor-pointer overflow-hidden transition-all hover:bg-green-50" onclick="document.getElementById('receipt_image').click()">
                    <i class="fas fa-camera text-4xl mb-2" id="camera-icon"></i>
                    <span class="text-sm font-bold px-4 text-center" id="camera-text">Appuyez ici pour capturer le reçu</span>
                    <img alt="" id="receipt-preview" class="absolute inset-0 w-full h-full object-cover hidden">
                    <input type="file" id="receipt_image" accept="image/*" capture="environment" class="hidden" onchange="previewImage(event)">
                </div>
            </div>

            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-2">Station Service <span class="text-red-500">*</span></label>
                <div class="relative">
                    <i class="fas fa-store absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" id="gas_station" required placeholder="Ex: Total, Tradex..." class="w-full bg-white border border-gray-300 rounded-xl pl-11 pr-4 py-3.5 text-sm font-black text-gray-900 outline-none focus:border-lpc-dark focus:ring-1 focus:ring-lpc-dark uppercase">
                </div>
            </div>

            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-2">Type de Carburant <span class="text-red-500">*</span></label>
                <select id="fuel_type" required class="w-full bg-white border border-gray-300 rounded-xl p-3.5 text-sm font-black text-gray-900 outline-none focus:border-lpc-dark focus:ring-1 focus:ring-lpc-dark">
                    <option value="diesel">Diesel (Gasoil)</option>
                    <option value="super">Essence (Super)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-2">Kilométrage Actuel <span class="text-red-500">*</span></label>
                <div class="relative">
                    <i class="fas fa-tachometer-alt absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="number" id="fuel_odometer" required placeholder="Ex: 102550" class="w-full bg-white border border-gray-300 rounded-xl pl-11 pr-4 py-3.5 text-lg font-black text-gray-900 outline-none focus:border-lpc-dark focus:ring-1 focus:ring-lpc-dark">
                </div>
                <p class="text-[10px] font-bold text-blue-600 mt-1.5"><i class="fas fa-info-circle"></i> Dernier relevé : <span id="last_odo_display">0</span> Km</p>
            </div>

            <div class="grid grid-cols-2 gap-4 border-t border-gray-100 pt-4">
                <div>
                    <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-2">Litres <span class="text-red-500">*</span></label>
                    <input type="number" step="0.01" id="fuel_liters" oninput="calcFuelCost()" required class="w-full bg-white border border-gray-300 rounded-xl p-3.5 text-lg font-black text-gray-900 outline-none focus:border-lpc-dark text-center" placeholder="0.00">
                </div>
                <div>
                    <label class="block text-xs font-black text-gray-500 uppercase tracking-widest mb-2">Prix / L <span class="text-red-500">*</span></label>
                    <input type="number" id="fuel_price" value="840" oninput="calcFuelCost()" required class="w-full bg-gray-50 border border-gray-300 rounded-xl p-3.5 text-lg font-black text-gray-900 outline-none focus:border-lpc-dark text-center">
                </div>
            </div>

            <div class="bg-gray-900 p-5 rounded-xl text-center shadow-inner">
                <span class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Montant Payé à la Pompe</span>
                <span class="text-3xl font-black text-lpc-light" id="fuel_total_display">0 F</span>
            </div>

            <button type="submit" class="w-full bg-lpc-dark hover:bg-green-800 active:scale-95 transition-transform text-white py-4 rounded-xl font-black text-lg shadow-lg flex justify-center items-center gap-2 mt-4">
                <i class="fas fa-paper-plane"></i> Soumettre le Ticket
            </button>
        </form>
    </main>

    <script src="/assets/js/modules/fleet-fuel_log.js" defer></script>
</body>
</html>