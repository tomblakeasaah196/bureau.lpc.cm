<?php
/**
 * public/auth/password_manager.php  --  "Change my password".
 *
 * SPRINT 14 — THIS PAGE DOES ONE THING NOW.
 * -----------------------------------------
 * It had two tabs:
 *
 *   · "Changer"  — employee code + old password + new password.  Worked.
 *   · "Oublié ?" — employee code + email, POSTed action=request_reset, which
 *                  called Mail::send() with a signed token link.
 *
 * The second tab was a trap. `Mail::send()` returns TRUE and writes the body to
 * the PHP error log when MAIL_FROM is unset (includes/classes/Mail.php), and
 * MAIL_FROM is unset because SMTP has never been configured on this host. So
 * the tab always answered "Si les informations correspondent, un lien de
 * réinitialisation a été envoyé", the user waited for an email that was sitting
 * in error_log, and the only route out was ringing the office anyway.
 *
 * A recovery flow that cannot deliver is worse than no recovery flow, because
 * it costs the user their time before they discover it. The tab is gone. The
 * page states plainly who to ask, and the login page repeats it.
 *
 * Re-enabling later: set MAIL_FROM in .env, flip
 * LPC_PASSWORD_RESET_BY_EMAIL_ENABLED in api/v1/password_controller.php, and
 * restore a "forgot" form. The token machinery, the password_resets table and
 * password_reset.php are all still here and still correct.
 *
 * The identifier is the EMAIL ADDRESS, prefilled from the session when the user
 * is signed in (the common case: this page is reached from the account menu).
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

$lang      = lpc_i18n_current_lang();
$forced    = !empty($_GET['force']) || !empty($_SESSION['force_reset']);
$prefEmail = htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8');
$signedIn  = !empty($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= __t('ui.s_curit_du_compte') ?> | Bureau LPC</title>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

<style>
body{background:#051A0F;color:#eee;min-height:100vh;font-family:Inter,sans-serif}
.glass{background:rgba(255,255,255,.08);backdrop-filter:blur(16px);
       border:1px solid rgba(255,255,255,.15);box-shadow:0 25px 50px -12px rgba(0,0,0,.5)}
.field{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:#fff}
.field:focus{border-color:#8CC63F;background:rgba(255,255,255,.1);outline:none}
.field:read-only{opacity:.7;cursor:not-allowed}
.valid{color:#8CC63F;transition:color .3s}
.invalid{color:rgba(255,255,255,.4);transition:color .3s}
</style>
</head>
<body class="flex items-center justify-center p-4">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<?php /* Sprint 14: the id/role attributes were previously written INSIDE the
         class attribute's quotes — `class="w-full max-w-md glass  id="main"
         role="main"rounded-3xl p-8"`. The browser parsed `id` as part of the
         class list, so the skip link above pointed at nothing and the landmark
         never existed. Fixed here and in password_reset.php. */ ?>
<div id="main" role="main" class="w-full max-w-md glass rounded-3xl p-8 my-8">

    <div class="text-center mb-6">
        <h1 class="text-white text-2xl font-semibold"><?= __t('ui.s_curit_du_compte') ?></h1>
        <p class="text-lpc-light text-xs mt-2 uppercase tracking-[0.2em] font-medium"><?= htmlspecialchars(CompanyProfile::displayName()) ?></p>
    </div>

    <?php if ($forced): ?>
    <div class="mb-4 p-3 rounded-lg text-sm bg-amber-500/10 border border-amber-500/30 text-amber-100">
        <?= __t('ui.un_changement_de_mot_de_passe_est_requis') ?>
    </div>
    <?php endif; ?>

    <div id="alert" class="hidden mb-4 p-3 rounded-lg text-sm font-medium border" role="alert" aria-live="polite"></div>

<?php if (!$signedIn): ?>
    <?php /* Sprint 14 audit: the change endpoint now REQUIRES a session — it
             previously accepted an anonymous caller who knew (email, current
             password), which let anyone who had seen a credential lock the
             real owner out. Since the API will refuse, say so here rather
             than presenting a form that fails on submit. Someone who has
             forgotten their password cannot use this page at all; the note
             below the form already tells them to ask an administrator. */ ?>
    <div class="mb-5 p-4 rounded-xl border border-amber-400/30 bg-amber-400/10">
        <p class="text-sm text-amber-100 font-semibold mb-1">
            <i class="fas fa-lock mr-1"></i> <?= __t('ui.connexion_requise') ?>
        </p>
        <p class="text-xs text-amber-100/80 leading-relaxed">
            <?= __t('ui.connectez_vous_pour_changer_mdp') ?>
        </p>
        <a href="/index.php?lang=<?= $lang ?>"
           class="inline-block mt-3 px-4 py-2 rounded-lg bg-lpc-light/90 hover:bg-lpc-light text-white text-xs font-bold uppercase tracking-wider transition-colors">
            <?= __t('ui.se_connecter') ?>
        </a>
    </div>
<?php endif; ?>

    <form id="form-change" class="space-y-4"<?= $signedIn ? '' : ' aria-hidden="true" style="display:none"' ?>>
        <div>
            <label for="pm-email" class="block text-xs uppercase tracking-wider text-white/70 mb-1.5"><?= __t('ui.adresse_email') ?></label>
            <input type="email" id="pm-email" name="email" required autocomplete="username"
                   inputmode="email" autocapitalize="none" spellcheck="false"
                   value="<?= $prefEmail ?>" <?= $prefEmail !== '' ? 'readonly' : '' ?>
                   class="w-full field rounded-xl py-3 px-4 text-sm" placeholder="prenom.nom@lpc.cm">
            <?php if ($prefEmail !== ''): ?>
                <p class="text-[10px] text-white/40 mt-1"><?= __t('ui.compte_connecte_actuellement') ?></p>
            <?php endif; ?>
        </div>
        <div>
            <label for="pm-old" class="block text-xs uppercase tracking-wider text-white/70 mb-1.5"><?= __t('ui.ancien_mot_de_passe') ?></label>
            <input type="password" id="pm-old" name="old_password" required autocomplete="current-password"
                   class="w-full field rounded-xl py-3 px-4 text-sm" placeholder="••••••••">
        </div>

        <hr class="border-white/10">

        <div>
            <label for="new_password_change" class="block text-xs uppercase tracking-wider text-white/70 mb-1.5"><?= __t('ui.nouveau_mot_de_passe') ?></label>
            <div class="relative">
                <input type="password" id="new_password_change" name="new_password" required autocomplete="new-password"
                       class="w-full field rounded-xl py-3 pl-4 pr-11 text-sm" placeholder="••••••••">
                <button type="button" onclick="toggleVis('new_password_change', 'eye-c')" class="absolute inset-y-0 right-0 px-3 text-white/70 hover:text-white" aria-label="Show password">
                    <i id="eye-c" class="fas fa-eye"></i>
                </button>
            </div>
            <?php /* Sprint 14: this checklist used to be four <li> elements
                     hardcoding "Min 8 caractères" while the server enforced a
                     configurable minimum defaulting to 10 — so the page could
                     promise a password the server would refuse. The list is
                     now built by JS from window.LPC.pwPolicy, which
                     lpc_password_policy_js() emits from the same array
                     lpc_password_check() validates against. */ ?>
            <ul id="pw-rules" class="mt-2 text-[10px] space-y-1 pl-1"></ul>
        </div>
        <div>
            <label for="confirm_change" class="block text-xs uppercase tracking-wider text-white/70 mb-1.5"><?= __t('ui.modal.confirm') ?></label>
            <input type="password" id="confirm_change" required autocomplete="new-password"
                   class="w-full field rounded-xl py-3 px-4 text-sm" placeholder="••••••••">
            <p id="match-change" class="text-xs mt-1 font-bold hidden"></p>
        </div>

        <button type="submit" id="btn-change" disabled
                class="mt-4 w-full py-3.5 rounded-xl bg-gradient-to-r from-lpc-dark to-lpc-light text-white font-bold text-sm uppercase tracking-widest opacity-50 cursor-not-allowed transition-all">
            <?= __t('ui.mettre_jour') ?>
        </button>
    </form>

    <div class="mt-6 pt-5 border-t border-white/10">
        <p class="text-xs text-white/50 leading-relaxed">
            <i class="fas fa-circle-info mr-1 text-white/30"></i>
            <?= __t('ui.mot_de_passe_oublie_contactez_admin') ?>
        </p>
    </div>

    <div class="text-center mt-5">
        <?php if ($signedIn): ?>
            <a href="/api/v1/auth.php?logout=true" class="text-xs text-white/70 hover:text-white font-medium uppercase tracking-wider">
                <i class="fas fa-sign-out-alt mr-1"></i> <?= __t('ui.se_d_connecter') ?>
            </a>
        <?php else: ?>
            <a href="/index.php?lang=<?= $lang ?>" class="text-xs text-white/70 hover:text-white font-medium uppercase tracking-wider">
                <i class="fas fa-arrow-left mr-1"></i> <?= __t('ui.retour_la_connexion') ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?= Rbac::jsBootstrap() ?>
<?= lpc_password_policy_js($lang) ?>
<script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"  defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-rbac.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/modules/auth-password_manager.js') ?>" defer></script>
</body>
</html>
