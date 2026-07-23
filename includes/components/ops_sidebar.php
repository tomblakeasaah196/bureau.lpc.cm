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
            <img src="/favicon.ico" alt="LPC Logo" class="w-full h-full object-contain">
        </div>
        <div>
            <h2 class="font-bold text-lg tracking-wide">LPC Operations</h2>
            <p class="text-[10px] text-lpc-light uppercase tracking-widest font-semibold">Logistics & Fleet</p>
        </div>
    </div>

    <nav class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto custom-scrollbar">
        
        <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-2"><?php echo __t('ui.supervision'); ?></p>
        <a href="/modules/dashboard/views/ops_dashboard.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
            <?php echo __t('ui.tableau_de_bord_ops'); ?>
        </a>

        <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-6"><?php echo __t('ui.ventes_fulfilment'); ?></p>
        <a href="/modules/crm/clients.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            <span data-i18n="menu_clients"><?php echo __t('ui.clients_devis'); ?></span>
        </a>
        <a href="/modules/sales/orders.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
            <?php echo __t('ui.ventes_et_bl'); ?>
        </a>

        <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-6"><?php echo __t('ui.entrep_t_stock'); ?></p>
        <a href="/modules/inventory/stock.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
            <?php echo __t('ui.tat_du_stock'); ?>
        </a>
        <a href="/modules/inventory/procurement.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            <?php echo __t('ui.bons_de_commande_sdp'); ?>
        </a>

        <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-6"><?php echo __t('ui.flotte_chauffeurs'); ?></p>
        <a href="/modules/fleet/vehicles.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
            <?php echo __t('ui.v_hicules_maintenance'); ?>
        </a>
        <a href="/modules/sales/orders.php?lang=<?php echo $lang; ?>#dispatch" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            <?php echo __t('ui.affectation_dispatch'); ?>
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