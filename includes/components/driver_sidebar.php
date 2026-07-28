<?php
// Define variables securely from the session to avoid warnings
$lang = $_GET['lang'] ?? 'fr';
$user_name = $_SESSION['user_name'] ?? 'Utilisateur';
$user_role = $_SESSION['user_role'] ?? __t('ui.x.role');
$initials = strtoupper(substr($user_name, 0, 2));
?>
<aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-72 bg-lpc-dark text-white transform -translate-x-full transition-transform duration-300 flex flex-col shadow-2xl">
    <div class="h-20 flex items-center px-6 border-b border-white/10 shrink-0">
        <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center p-1 mr-3 shadow-md">
            <img src="/favicon.ico" alt="LPC Logo" class="w-full h-full object-contain">
        </div>
        <div>
            <h2 class="font-bold text-lg tracking-wide">LPC Mobile</h2>
            <p class="text-[10px] text-lpc-light uppercase tracking-widest font-semibold">Driver Portal</p>
        </div>
    </div>

    <nav class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto custom-scrollbar">
        <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-2"><?php echo __t('ui.ma_logistique'); ?></p>
        <a href="/modules/dashboard/views/driver_dashboard.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"></path></svg>
            <?php echo __t('ui.mes_livraisons_bl'); ?>
        </a>
        <a href="/modules/dashboard/views/driver_dashboard.php?lang=<?php echo $lang; ?>#eod" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <?php echo __t('ui.d_clarer_ma_caisse_eod'); ?>
        </a>

        <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-6"><?php echo __t('ui.mon_v_hicule'); ?></p>
        <a href="/modules/fleet/fuel_log.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
            <?php echo __t('ui.saisir_carburant'); ?>
        </a>
        <a href="/modules/fleet/report_breakdown.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            <?php echo __t('ui.signaler_une_panne'); ?>
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
    // Auto-logout on inactivity.
    // Sprint 8: was a hardcoded 1800000 ms. Now driven by the
    // `sec_session_timeout_min` preference (Paramètres -> Préférences ->
    // Sécurité), so it can be tightened for drivers on shared devices without
    // a code change. Kept in step with the server-side check in bootstrap.php.
    const inactivityTracker = function () {
        let time;
        const timeoutLength = <?= (int) Prefs::sessionTimeoutMs() ?>;

        // Reset timer on any user interaction
        window.onload = resetTimer;
        document.onmousemove = resetTimer;
        document.onkeypress = resetTimer;
        document.onclick = resetTimer;
        document.onscroll = resetTimer;

        function logout() {
            // Duration interpolated from the same preference that drives the
            // timer above — the literal "30 minutes" here used to survive any
            // change to sec_session_timeout_min and tell drivers the wrong number.
            alert("Votre session a expiré pour cause d'inactivité (<?= (int) round(Prefs::sessionTimeoutMs() / 60000) ?> minutes).");
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