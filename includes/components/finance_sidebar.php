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
            <h2 class="font-bold text-lg tracking-wide">LPC Finance</h2>
            <p class="text-[10px] text-lpc-light uppercase tracking-widest font-semibold">OHADA Compliant</p>
        </div>
    </div>

    <nav class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto custom-scrollbar">
        
        <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-2"><?php echo __t('ui.tr_sorerie'); ?></p>
        <a href="/modules/dashboard/views/finance_dashboard.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <?php echo __t('ui.tableau_de_bord'); ?>
        </a>
        <a href="/modules/accounting/cashflow.php?lang=<?php echo $lang; ?>#reconciliation" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <?php echo __t('ui.validations_caisse_eod'); ?>
        </a>
        
        <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-6"><?php echo __t('ui.achats_logistique'); ?></p>
        <a href="/modules/inventory/procurement.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
            <?php echo __t('ui.gestion_des_achats'); ?>
        </a>
        <a href="/modules/sales/orders.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"></path></svg>
            <?php echo __t('ui.ventes_dispatch'); ?>
        </a>
        
        <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-6"><?php echo __t('ui.tiers_ar_ap'); ?></p>
        <a href="/modules/accounting/invoices.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
            <?php echo __t('ui.factures_clients_ar'); ?>
        </a>
<?php // Sprint 7B: "Dettes fournisseurs (AP)" link removed — target module was never built.
      //            Suppliers-due is surfaced in the Vue Dirigeant widget on modules/accounting/reports.php. ?>

        <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-6"><?php echo __t('ui.comptabilit_g_n_rale'); ?></p>
        <a href="/modules/accounting/journal_entry.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
            <?php echo __t('ui.saisie_des_journaux'); ?>
        </a>
        <a href="/modules/accounting/ledger.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"></path></svg>
            <?php echo __t('ui.grand_livre'); ?>
        </a>
        <a href="/modules/accounting/fixed_assets.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 20a2 2 0 100-4 2 2 0 000 4zm10 0a2 2 0 100-4 2 2 0 000 4zm-8-2h6m4-2v-4a2 2 0 00-1.5-1.93l-3-1A2 2 0 0013.88 9H11m-8 7V7a2 2 0 012-2h8v10H5a2 2 0 00-2 2v2h2"></path></svg>
            <?php echo __t('ui.immobilisations_amortissements'); ?>
        </a>
        <a href="/modules/accounting/reports.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            <?php echo __t('ui.tats_financiers_bilan'); ?>
        </a>

        <p class="px-4 text-[10px] font-bold text-white/70 uppercase tracking-wider mb-2 mt-6"><?php echo __t('ui.contr_le_rh'); ?></p>
        <a href="/modules/accounting/budgets.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 13v-1m4 1v-3m4 3V8M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg>
            <?php echo __t('ui.budgets_cibles'); ?>
        </a>
        <a href="/modules/hr/payroll_finance.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            <?php echo __t('ui.paie_acomptes'); ?>
        </a>
        
        <a href="/modules/admin/master_data.php?lang=<?php echo $lang; ?>" class="flex items-center px-4 py-2.5 text-white/70 hover:bg-white/5 hover:text-white rounded-xl transition-colors text-sm font-medium">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
            <?php echo __t('ui.donn_es_de_base_gdb'); ?>
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
    const inactivityTracker = function () {
        let time;
        const timeoutLength = 1800000; 
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
    inactivityTracker();

    document.addEventListener('DOMContentLoaded', () => {
        const currentPath = window.location.pathname;
        const sidebarLinks = document.querySelectorAll('#sidebar nav a');
        
        sidebarLinks.forEach(link => {
            const linkPath = link.getAttribute('href').split('?')[0];
            if (linkPath === currentPath) {
                link.classList.remove('text-white/70', 'hover:bg-white/5', 'hover:text-white', 'border-transparent');
                link.classList.add('bg-white/10', 'text-[#8CC63F]', 'font-black', 'border', 'border-white/5', 'shadow-inner');
                link.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    });
</script>