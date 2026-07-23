<?php
// Define variables securely from the session to avoid warnings
$lang = $_GET['lang'] ?? 'fr';
$user_name = $_SESSION['user_name'] ?? 'Utilisateur';
$user_role = $_SESSION['user_role'] ?? 'Rôle';
$initials = strtoupper(substr($user_name, 0, 2));
?>
<aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-72 bg-lpc-dark text-white transform -translate-x-full lg:translate-x-0 lg:static transition-transform duration-300 flex flex-col shadow-2xl">
    <div class="h-20 flex items-center px-6 border-b border-white/10 shrink-0">
        <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center p-1 mr-3 shadow-md">
            <img src="/assets/img/small_logo.svg" alt="LPC Logo" class="w-full h-full object-contain" onerror="this.src='https://i.ibb.co/SXdjzBs1/LPC-Logo.jpg'">
        </div>
        <div>
            <h2 class="font-bold text-lg tracking-wide">La Petite Cour</h2>
            <p class="text-[10px] text-lpc-light uppercase tracking-widest font-semibold">ERP System</p>
        </div>
    </div>

    <nav class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto custom-scrollbar">
    
    <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-2">
        <?php echo __t('ui.strat_gie_bi'); ?>
    </p>
    <a href="/modules/dashboard/views/md_dashboard.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
        <?php echo __t('ui.tableau_de_bord_ex_cutif'); ?>
    </a>
    <a href="/modules/analytics/reports.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg>
        <?php echo __t('ui.rapports_consolid_s'); ?>
    </a>

    <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-6">
        <?php echo __t('ui.commerce_crm'); ?>
    </p>
    <a href="/modules/crm/clients.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
        <?php echo __t('ui.base_clients_devis'); ?>
    </a>
    <a href="/modules/sales/orders.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
        <?php echo __t('ui.ventes_commandes'); ?>
    </a>

    <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-6">
        <?php echo __t('ui.logistique_stock'); ?>
    </p>
    <a href="/modules/inventory/procurement.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
        <?php echo __t('ui.gestion_des_achats'); ?>
    </a>
    <a href="/modules/inventory/stock.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
        <?php echo __t('ui.stock_emballages'); ?>
    </a>
    
    <a href="/modules/inventory/fiche_stock.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
        <?php echo __t('ui.fiche_de_stock'); ?>
    </a>

    <a href="/modules/operations/empties_collection.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
        <?php echo __t('ui.gestion_des_vides'); ?>
    </a>
    <a href="/modules/fleet/vehicles.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
        <?php echo __t('ui.flotte_maintenance'); ?>
    </a>

    <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-6">
        <?php echo __t('ui.comptabilit_finance'); ?>
    </p>
    <a href="/modules/accounting/invoices.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 012 2z"></path></svg>
        <?php echo __t('ui.facturation_ar'); ?>
    </a>
    <a href="/modules/accounting/ledger.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"></path></svg>
        <?php echo __t('ui.grand_livre_ohada'); ?>
    </a>
    <a href="/modules/accounting/cashflow.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        <?php echo __t('ui.tr_sorerie_banque'); ?>
    </a>
    <a href="/modules/accounting/budgets.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path></svg>
        <?php echo __t('ui.budgets_performance'); ?>
    </a>

    <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-6">
        <?php echo __t('ui.administration_rh'); ?>
    </p>
    <a href="/modules/hr/payroll_finance.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
        <?php echo __t('ui.gestion_du_personnel'); ?>
    </a>
    <a href="/modules/admin/master_data.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
        <?php echo __t('ui.donn_es_de_base_gdb'); ?>
    </a>
    <a href="/modules/settings/index.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
        </svg>
        <?php echo __t('ui.param_tres_syst_me_audit'); ?>
    </a>
</nav>

    <div class="mt-auto border-t border-white/10 bg-black/10 flex flex-col shrink-0">
        <div class="p-4 flex items-center">
            <?php if (!empty($_SESSION['avatar'])): ?>
                <img src="<?php echo htmlspecialchars($_SESSION['avatar']); ?>" alt="Avatar" class="w-10 h-10 rounded-xl object-cover border border-white/20 shadow-md shrink-0">
            <?php else: ?>
                <div class="w-10 h-10 bg-lpc-light rounded-xl flex items-center justify-center text-lpc-dark font-black border border-white/20 shadow-md shrink-0">
                    <?php echo htmlspecialchars($initials); ?>
                </div>
            <?php endif; ?>
            <div class="ml-3 overflow-hidden">
                <p class="text-sm font-black text-white truncate"><?php echo htmlspecialchars($user_name); ?></p>
                <p class="text-[10px] font-bold text-lpc-light uppercase tracking-wider truncate"><?php echo htmlspecialchars($user_role); ?></p>
            </div>
        </div>

        <div class="px-4 pb-4">
            <a href="/api/v1/auth.php?logout=true" class="flex items-center justify-center w-full py-2.5 bg-red-500/20 text-red-200 rounded-xl font-bold hover:bg-red-500/30 transition-colors text-sm">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                <?php echo __t('ui.d_connexion'); ?>
            </a>
        </div>
    </div>
</aside>

<script>
    // 30-Minute Auto-Logout Logic
    const inactivityTracker = function () {
        let time;
        // 30 minutes = 30 * 60 * 1000 milliseconds
        const timeoutLength = 1800000; 

        // Reset timer on any user interaction
        window.onload = resetTimer;
        document.onmousemove = resetTimer;
        document.onkeypress = resetTimer;
        document.onclick = resetTimer;
        document.onscroll = resetTimer;

        function logout() {
            alert("Votre session a expiré pour cause d'inactivité (30 minutes).");
            window.location.href = '/api/v1/auth.php?logout=true';
        }

        function resetTimer() {
            clearTimeout(time);
            time = setTimeout(logout, timeoutLength);
        }
    };
    
    // Initialize the tracker
    inactivityTracker();

    // --- DYNAMIC SIDEBAR ACTIVE STATE ENGINE ---
    document.addEventListener('DOMContentLoaded', () => {
        // 1. Get the current URL path (e.g., "/modules/admin/master_data.php")
        const currentPath = window.location.pathname;
        
        // 2. Select every single link inside the sidebar navigation
        const sidebarLinks = document.querySelectorAll('#sidebar nav a');
        
        sidebarLinks.forEach(link => {
            // Strip the "?lang=fr" part to match purely the file path
            const linkPath = link.getAttribute('href').split('?')[0];
            
            // 3. If the link matches the current page URL, light it up!
            if (linkPath === currentPath) {
                // Remove the dull inactive classes
                link.classList.remove('text-white/70', 'hover:bg-white/5', 'hover:text-white', 'border-transparent');
                
                // Inject the bright active classes 
                link.classList.add('bg-white/10', 'text-[#8CC63F]', 'font-black', 'border', 'border-white/5', 'shadow-inner');
                
                // Optional: Auto-scroll the sidebar so the active link is visible if the menu is long
                link.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    });
</script>