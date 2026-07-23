<?php
// index.php - Main Entry Point & Login Screen
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/functions/helpers.php';

// If already logged in, hop straight to the best available landing.
if (!empty($_SESSION['user_id'])) {
    $landing = '/modules/dashboard/views/driver_dashboard.php';
    if      (Rbac::hasPermission('dashboard.md.view'))      $landing = '/modules/dashboard/views/md_dashboard.php';
    else if (Rbac::hasPermission('dashboard.finance.view')) $landing = '/modules/dashboard/views/finance_dashboard.php';
    else if (Rbac::hasPermission('dashboard.ops.view'))     $landing = '/modules/dashboard/views/ops_dashboard.php';
    header("Location: $landing"); exit;
}

$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang == 'fr' ? 'Connexion' : 'Login'; ?> | Bureau LPC</title>
    
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    

    <style>
        /* Custom Utilities for Glassmorphism */
        body {
            background-color: #051A0F; /* Extremely dark green base */
            overflow: hidden; /* Prevent scrolling on login */
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .glass-input {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
        }
        .glass-input:focus {
            background: rgba(255, 255, 255, 0.1);
            border-color: #8CC63F;
            box-shadow: 0 0 0 2px rgba(140, 198, 63, 0.2);
            outline: none;
        }
        .glass-input::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }
        .animation-delay-2000 { animation-delay: 2s; }
        .animation-delay-4000 { animation-delay: 4s; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center relative font-sans p-4 antialiased">

    <div class="absolute inset-0 w-full h-full overflow-hidden z-0 pointer-events-none">
        <div class="absolute top-0 -left-4 w-72 h-72 bg-lpc-light rounded-full mix-blend-multiply filter blur-2xl opacity-20 animate-blob"></div>
        <div class="absolute top-0 -right-4 w-72 h-72 bg-lpc-dark rounded-full mix-blend-multiply filter blur-2xl opacity-40 animate-blob animation-delay-2000"></div>
        <div class="absolute -bottom-8 left-20 w-72 h-72 bg-emerald-600 rounded-full mix-blend-multiply filter blur-2xl opacity-30 animate-blob animation-delay-4000"></div>
    </div>

    <div class="absolute top-6 right-6 z-20 flex space-x-3 text-sm font-medium tracking-wide">
        <a href="?lang=fr" class="transition-colors <?php echo $lang == 'fr' ? 'text-lpc-light drop-shadow-md' : 'text-white/70 hover:text-white'; ?>">FR</a>
        <span class="text-white/20">|</span>
        <a href="?lang=en" class="transition-colors <?php echo $lang == 'en' ? 'text-lpc-light drop-shadow-md' : 'text-white/70 hover:text-white'; ?>">EN</a>
    </div>

    <div class="w-full max-w-md glass-card rounded-3xl p-8 z-10 animate-fade-in-up">
        
        <div class="text-center mb-10">
            <div class="relative inline-block mb-4">
                <div class="absolute inset-0 bg-lpc-light rounded-full blur-md opacity-50"></div>
                <img src="/assets/img/small_logo.svg" alt="LPC Logo" class="relative w-24 h-24 rounded-full border-2 border-white/20 shadow-xl object-cover">
            </div>
            <h1 class="text-white text-2xl font-semibold tracking-tight">Ets. La Petite Cour</h1>
            <p class="text-lpc-light text-xs mt-2 uppercase tracking-[0.2em] font-medium">Distribution Agroalimentaire</p>
        </div>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="mb-6 p-4 rounded-xl backdrop-blur-md border 
                <?php echo $_GET['error'] === 'system_error' ? 'bg-amber-500/10 border-amber-500/30 text-amber-200' : 'bg-red-500/10 border-red-500/30 text-red-200'; ?> 
                animate-fade-in-up flex items-start">
                
                <svg class="w-5 h-5 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                        d="<?php echo $_GET['error'] === 'system_error' ? 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'; ?>">
                    </path>
                </svg>

                <div class="text-sm font-medium">
                    <?php 
                        $error = $_GET['error'];
                        if ($error === 'invalid_credentials') {
                            echo $lang == 'fr' ? 'Code employé ou mot de passe incorrect.' : 'Invalid employee code or password.';
                        } elseif ($error === 'empty_fields') {
                            echo $lang == 'fr' ? 'Veuillez remplir tous les champs.' : 'Please fill in all fields.';
                        } elseif ($error === 'system_error') {
                            echo $lang == 'fr' ? 'Erreur système. Veuillez contacter l\'administrateur.' : 'System error. Please contact the administrator.';
                        } else {
                            echo $lang == 'fr' ? 'Une erreur est survenue.' : 'An error occurred.';
                        }
                    ?>
                </div>
            </div>
        <?php endif; ?>
        
        <form action="api/v1/auth.php" method="POST" class="space-y-5" autocomplete="on">
            <?= Csrf::field() ?>

            <div>
                <label for="employee_code" class="block text-xs font-medium text-white/70 mb-1.5 ml-1 uppercase tracking-wider">
                    <?php echo $lang == 'fr' ? 'Code Employé' : 'Employee Code'; ?>
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-white/70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                    <input type="text" id="employee_code" name="employee_code" required autocomplete="username" autofocus
                        class="w-full glass-input rounded-xl py-3.5 pl-11 pr-4 text-base transition-all duration-300"
                        placeholder="Ex: LPC-001">
                </div>
            </div>

            <div>
                <label for="password" class="block text-xs font-medium text-white/70 mb-1.5 ml-1 uppercase tracking-wider">
                    <?php echo $lang == 'fr' ? 'Mot de Passe' : 'Password'; ?>
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-white/70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <input type="password" id="password" name="password" required autocomplete="current-password"
                        class="w-full glass-input rounded-xl py-3.5 pl-11 pr-4 text-base transition-all duration-300"
                        placeholder="••••••••">
                </div>
            </div>

            <div class="flex items-center justify-between text-sm mt-2 mb-6 ml-1">
                <a href="password_manager.php?lang=<?php echo $lang; ?>" class="text-white/60 hover:text-lpc-light transition-colors font-medium">
                    <?php echo $lang == 'fr' ? 'Mot de passe oublié / Changer ?' : 'Forgot / Change Password?'; ?>
                </a>
            </div>

            <button type="submit" 
                class="relative w-full overflow-hidden rounded-xl p-[1px] group active:scale-[0.98] transition-all duration-300">
                <span class="absolute inset-0 bg-gradient-to-r from-lpc-dark to-lpc-light opacity-80 group-hover:opacity-100 transition-opacity duration-300"></span>
                <div class="relative bg-black/20 backdrop-blur-sm w-full py-3.5 rounded-xl flex items-center justify-center border border-white/10 group-hover:border-white/20 transition-all">
                    <span class="text-white font-semibold text-lg tracking-wide shadow-sm">
                        <?php echo $lang == 'fr' ? 'Accéder au Bureau' : 'Access Bureau'; ?>
                    </span>
                    <svg class="ml-2 w-5 h-5 text-white transform group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </div>
            </button>
        </form>
        
        <div class="mt-8 text-center">
            <p class="text-[10px] text-white/30 uppercase tracking-widest">
                © <?php echo date('Y'); ?> Ets. La Petite Cour.<br>Secured by JBS Praxis.
            </p>
        </div>
    </div>

</body>
</html>