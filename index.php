<?php
// index.php - Main Entry Point & Login Screen
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/functions/helpers.php';

// If already logged in, hop straight to the best available landing —
// personal override, then role default, then best-available dashboard
// permission. See Rbac::redirectToLanding().
if (!empty($_SESSION['user_id'])) {
    Rbac::redirectToLanding();
}

$lang = lpc_i18n_current_lang();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang == 'fr' ? 'Connexion' : 'Login'; ?> | Bureau LPC</title>
    
    <link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
    
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
                <?php /* Sprint 8: logo, name and tagline come from company_profile
                         (migration 034) instead of being hardcoded here. */ ?>
                <img src="<?= htmlspecialchars(CompanyProfile::logo('mark'), ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars(CompanyProfile::displayName(), ENT_QUOTES, 'UTF-8') ?>"
                     class="relative w-24 h-24 rounded-full border-2 border-white/20 shadow-xl object-cover">
            </div>
            <h1 class="text-white text-2xl font-semibold tracking-tight"><?= htmlspecialchars(CompanyProfile::displayName()) ?></h1>
            <?php $__tagline = CompanyProfile::erpTagline() ?: CompanyProfile::field('activity_sector', ''); ?>
            <?php if ($__tagline !== ''): ?>
                <p class="text-lpc-light text-xs mt-2 uppercase tracking-[0.2em] font-medium"><?= htmlspecialchars($__tagline) ?></p>
            <?php endif; ?>
        </div>
        
        <?php
            $error = isset($_GET['error']) ? htmlspecialchars((string)$_GET['error'], ENT_QUOTES, 'UTF-8') : null;
        ?>
        <?php if ($error !== null): ?>
            <div class="mb-6 p-4 rounded-xl backdrop-blur-md border 
                <?php echo $error === 'system_error' ? 'bg-amber-500/10 border-amber-500/30 text-amber-200' : 'bg-red-500/10 border-red-500/30 text-red-200'; ?>
                animate-fade-in-up flex items-start">
                
                <svg class="w-5 h-5 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                        d="<?php echo $error === 'system_error' ? 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'; ?>">
                    </path>
                </svg>

                <div class="text-sm font-medium">
                    <?php 
                        if ($error === 'invalid_credentials') {
                            echo $lang == 'fr' ? 'Adresse email ou mot de passe incorrect.' : 'Invalid email address or password.';
                        } elseif ($error === 'empty_fields') {
                            echo $lang == 'fr' ? 'Veuillez remplir tous les champs.' : 'Please fill in all fields.';
                        } elseif ($error === 'account_locked') {
                            // Sprint 14: this case was reachable from auth.php since Sprint 2
                            // but had no message here, so a locked-out employee got the
                            // useless generic "Une erreur est survenue" and rang the office.
                            echo $lang == 'fr'
                                ? 'Ce compte est désactivé. Contactez votre administrateur.'
                                : 'This account is disabled. Contact your administrator.';
                        } elseif ($error === 'reset_disabled') {
                            echo $lang == 'fr'
                                ? 'La réinitialisation par email n\'est pas disponible. Votre administrateur peut définir un nouveau mot de passe.'
                                : 'Email password reset is unavailable. Your administrator can set a new password for you.';
                        } elseif ($error === 'reset_invalid') {
                            echo $lang == 'fr'
                                ? 'Ce lien de réinitialisation n\'est plus valide.'
                                : 'This reset link is no longer valid.';
                        } elseif ($error === 'system_error') {
                            echo $lang == 'fr' ? 'Erreur système. Veuillez contacter l\'administrateur.' : 'System error. Please contact the administrator.';
                        } elseif ($error === 'csrf_expired') {
                            // A stale login form (browser tab kept open across a deploy,
                            // or a session recycled by PHP-FPM) submits with an outdated
                            // CSRF token. auth.php redirects here instead of dumping the
                            // raw JSON 419 payload into the address bar.
                            echo $lang == 'fr'
                                ? 'Votre session a expiré. Rechargez la page et réessayez.'
                                : 'Your session has expired. Please reload the page and try again.';
                        } else {
                            echo $lang == 'fr' ? 'Une erreur est survenue.' : 'An error occurred.';
                        }
                    ?>
                </div>
            </div>
        <?php endif; ?>
        
        <form action="api/v1/auth.php" method="POST" class="space-y-5" autocomplete="on">
            <?= Csrf::field() ?>

            <?php /* Sprint 14: the login identifier is the email address. The
                     employee code is no longer a credential — see
                     docs/SPRINT14_EMAIL_LOGIN.md. type="email" gives mobile
                     users the @ keyboard; autocomplete="username" is correct
                     for an email used as a login name and is what password
                     managers expect to pair with current-password. */ ?>
            <div>
                <label for="email" class="block text-xs font-medium text-white/70 mb-1.5 ml-1 uppercase tracking-wider">
                    <?php echo $lang == 'fr' ? 'Adresse Email' : 'Email Address'; ?>
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-white/70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <input type="email" id="email" name="email" required autocomplete="username" autofocus
                        inputmode="email" autocapitalize="none" spellcheck="false"
                        class="w-full glass-input rounded-xl py-3.5 pl-11 pr-4 text-base transition-all duration-300"
                        placeholder="prenom.nom@lpc.cm">
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
                        class="w-full glass-input rounded-xl py-3.5 pl-11 pr-12 text-base transition-all duration-300"
                        placeholder="••••••••">
                    <button type="button" id="togglePassword" aria-controls="password" aria-pressed="false"
                        aria-label="<?php echo $lang == 'fr' ? 'Afficher le mot de passe' : 'Show password'; ?>"
                        class="absolute inset-y-0 right-0 pr-4 flex items-center text-white/50 hover:text-white/80 transition-colors">
                        <svg id="eyeOpenIcon" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        <svg id="eyeClosedIcon" class="h-5 w-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a10.025 10.025 0 012.132-3.411m3.66-2.66A9.958 9.958 0 0112 5c4.478 0 8.268 2.943 9.542 7a9.977 9.977 0 01-1.563 3.029M3 3l18 18" />
                        </svg>
                    </button>
                </div>
            </div>

            <?php /* Sprint 14: there is no self-service recovery. SMTP is not
                     configured, and the old "Oublié ?" tab called Mail::send(),
                     which logs-and-returns-true when MAIL_FROM is empty — it
                     reported success and delivered nothing. A dead end that
                     LOOKS like it worked is worse than an honest instruction,
                     so the honest instruction is what the page now shows.
                     "Changer" still links out, because that one works. */ ?>
            <div class="mt-2 mb-6 ml-1 space-y-1.5">
                <a href="password_manager.php?lang=<?php echo $lang; ?>" class="block text-sm text-white/60 hover:text-lpc-light transition-colors font-medium">
                    <?php echo $lang == 'fr' ? 'Changer mon mot de passe' : 'Change my password'; ?>
                </a>
                <p class="text-xs text-white/40 leading-relaxed">
                    <?php echo $lang == 'fr'
                        ? 'Mot de passe oublié ? Contactez votre administrateur : il peut en définir un nouveau pour vous.'
                        : 'Forgot your password? Contact your administrator — they can set a new one for you.'; ?>
                </p>
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
                © <?php echo date('Y'); ?> <?= htmlspecialchars(CompanyProfile::displayName()) ?>.<br>Secured by JBS Praxis.
            </p>
        </div>
    </div>

    <script>
        // Show/hide the password field. Purely cosmetic — never touches what
        // gets submitted, just the input's `type`, so it can't interfere with
        // the CSRF/rate-limit/credential handling in api/v1/auth.php.
        (function () {
            var toggle     = document.getElementById('togglePassword');
            var input      = document.getElementById('password');
            var eyeOpen    = document.getElementById('eyeOpenIcon');
            var eyeClosed  = document.getElementById('eyeClosedIcon');
            if (!toggle || !input || !eyeOpen || !eyeClosed) return;

            var labels = {
                show: <?= json_encode($lang == 'fr' ? 'Afficher le mot de passe' : 'Show password') ?>,
                hide: <?= json_encode($lang == 'fr' ? 'Masquer le mot de passe' : 'Hide password') ?>
            };

            toggle.addEventListener('click', function () {
                var willShow = input.type === 'password';
                input.type = willShow ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', String(willShow));
                toggle.setAttribute('aria-label', willShow ? labels.hide : labels.show);
                eyeOpen.classList.toggle('hidden', willShow);
                eyeClosed.classList.toggle('hidden', !willShow);
            });
        })();
    </script>

</body>
</html>