<?php
/**
 * public/auth/password_forgot.php  --  "Mot de passe oublié" (2 Nov 2026).
 *
 * Self-service recovery, two ways in:
 *   · the sign-in address -> a reset link valid one hour, by email;
 *   · the matricule, for someone who forgot the address -> the address masked
 *     (j***.n***@lpc.cm), and the same link sent to it without showing it.
 *
 * Both post to api/v1/password_controller.php (request_reset, email_hint). The
 * link form only shows when mail really leaves this host
 * (lpc_reset_by_email_enabled()); without it the page says to ask an
 * administrator, as Sprint 14 did, rather than promise an email.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/account_recovery.php';

$lang    = lpc_i18n_current_lang();
$t       = static fn (string $fr, string $en): string => $lang === 'fr' ? $fr : $en;
$canSend = lpc_reset_by_email_enabled();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $t('Mot de passe oublié', 'Forgot password') ?> | Bureau LPC</title>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

<style>
body{background:#051A0F;color:#eee;min-height:100vh;font-family:Inter,sans-serif}
.glass{background:rgba(255,255,255,.08);backdrop-filter:blur(16px);
       border:1px solid rgba(255,255,255,.15);box-shadow:0 25px 50px -12px rgba(0,0,0,.5)}
.field{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:#fff}
.field:focus{border-color:#8CC63F;background:rgba(255,255,255,.1);outline:none}
</style>
</head>
<body class="flex items-center justify-center p-4">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<div id="main" role="main" class="w-full max-w-md glass rounded-3xl p-8 my-8">

    <div class="text-center mb-6">
        <h1 class="text-white text-2xl font-semibold"><?= $t('Mot de passe oublié', 'Forgot password') ?></h1>
        <p class="text-lpc-light text-xs mt-2 uppercase tracking-[0.2em] font-medium"><?= htmlspecialchars(CompanyProfile::displayName()) ?></p>
    </div>

    <div id="alert" class="hidden mb-4 p-3 rounded-lg text-sm font-medium border" role="alert" aria-live="polite"></div>

<?php if ($canSend): ?>
    <form id="form-forgot" class="space-y-4">
        <div>
            <label for="fg-email" class="block text-xs uppercase tracking-wider text-white/70 mb-1.5"><?= __t('ui.adresse_email') ?></label>
            <input type="email" id="fg-email" name="email" required autocomplete="username"
                   inputmode="email" autocapitalize="none" spellcheck="false"
                   class="w-full field rounded-xl py-3 px-4 text-sm" placeholder="prenom.nom@lpc.cm">
            <p class="text-[11px] text-white/50 mt-1.5">
                <?= $t('Nous vous envoyons un lien pour choisir un nouveau mot de passe. Il est valable 1 heure.',
                       'We will email you a link to choose a new password. It is valid for 1 hour.') ?>
            </p>
        </div>
        <button type="submit" id="btn-forgot"
                class="w-full py-3.5 rounded-xl bg-gradient-to-r from-lpc-dark to-lpc-light text-white font-bold text-sm uppercase tracking-widest transition-all hover:opacity-90">
            <?= $t('Recevoir le lien', 'Email me a link') ?>
        </button>
    </form>
<?php else: ?>
    <div class="p-4 rounded-xl border border-amber-400/30 bg-amber-400/10">
        <p class="text-xs text-amber-100/90 leading-relaxed">
            <i class="fas fa-circle-info mr-1"></i>
            <?= $t("Cette installation n'envoie pas encore d'emails : demandez à un administrateur de définir un nouveau mot de passe pour vous (Paramètres → Utilisateurs).",
                   'This installation does not send email yet: ask an administrator to set a new password for you (Settings → Users).') ?>
        </p>
    </div>
<?php endif; ?>

    <div class="mt-6 pt-5 border-t border-white/10">
        <h2 class="text-sm font-semibold text-white mb-1"><?= $t('Adresse de connexion oubliée ?', 'Forgot your sign-in address?') ?></h2>
        <p class="text-[11px] text-white/50 mb-3">
            <?= $t('Indiquez votre matricule (il figure sur votre bulletin de paie) : nous vous montrons votre adresse, en partie masquée.',
                   'Enter your employee code (it is on your payslip): we show you your address, partly hidden.') ?>
        </p>
        <form id="form-hint" class="flex gap-2">
            <label for="fg-matricule" class="sr-only"><?= $t('Matricule', 'Employee code') ?></label>
            <input type="text" id="fg-matricule" name="matricule" required autocomplete="off"
                   autocapitalize="characters" spellcheck="false"
                   class="flex-1 min-w-0 field rounded-xl py-2.5 px-3 text-sm" placeholder="<?= $t('Matricule', 'Employee code') ?>">
            <button type="submit" id="btn-hint"
                    class="shrink-0 px-4 rounded-xl border border-white/20 text-white text-xs font-bold uppercase tracking-wider hover:bg-white/10 transition-colors">
                <?= $t('Retrouver', 'Find it') ?>
            </button>
        </form>
        <div id="hint-result" class="hidden mt-3 p-3 rounded-xl bg-white/5 border border-white/10">
            <p class="text-[11px] text-white/60"><?= $t('Votre adresse de connexion :', 'Your sign-in address:') ?></p>
            <p id="hint-email" class="text-sm font-mono text-white mt-0.5 break-all"></p>
            <button type="button" id="btn-hint-send"
                    class="hidden mt-3 w-full py-2.5 rounded-xl bg-gradient-to-r from-lpc-dark to-lpc-light text-white font-bold text-xs uppercase tracking-widest hover:opacity-90">
                <?= $t('Envoyer le lien à cette adresse', 'Send the link to this address') ?>
            </button>
        </div>
    </div>

    <div class="text-center mt-6">
        <a href="/index.php?lang=<?= $lang ?>" class="text-xs text-white/70 hover:text-white font-medium uppercase tracking-wider">
            <i class="fas fa-arrow-left mr-1"></i> <?= __t('ui.retour_la_connexion') ?>
        </a>
    </div>
</div>

<?= Rbac::jsBootstrap() ?>
<script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"  defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-rbac.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/modules/auth-password_forgot.js') ?>" defer></script>
</body>
</html>
