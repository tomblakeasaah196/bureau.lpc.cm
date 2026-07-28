<?php
/**
 * public/auth/password_manager.php  --  Sprint-2 hardened flow.
 *
 * Two modes controlled by the tab UI:
 *   · change   — user knows their current password
 *   · request  — user enters employee_code + email; server emails a reset link
 *
 * The actual "set new password from token" step lives at /password_reset.php.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

$lang     = in_array(($_GET['lang'] ?? 'fr'), ['fr','en'], true) ? ($_GET['lang'] ?? 'fr') : 'fr';
$forced   = !empty($_GET['force']) || !empty($_SESSION['force_reset']);
$prefCode = htmlspecialchars($_SESSION['employee_code'] ?? '', ENT_QUOTES, 'UTF-8');
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
.valid{color:#8CC63F;transition:color .3s}
.invalid{color:rgba(255,255,255,.4);transition:color .3s}
.tab.active{background:rgba(255,255,255,.2);color:#fff}
.tab{color:rgba(255,255,255,.5)}
input[type=checkbox]{accent-color:#8CC63F}
</style>
</head>
<body class="flex items-center justify-center p-4">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


<div class="w-full max-w-md glass  id="main" role="main"rounded-3xl p-8 my-8">

    <div class="text-center mb-6">
        <h1 class="text-white text-2xl font-semibold"><?= __t('ui.s_curit_du_compte') ?></h1>
        <p class="text-lpc-light text-xs mt-2 uppercase tracking-[0.2em] font-medium"><?= htmlspecialchars(CompanyProfile::displayName()) ?></p>
    </div>

    <?php if ($forced): ?>
    <div class="mb-4 p-3 rounded-lg text-sm bg-amber-500/10 border border-amber-500/30 text-amber-100">
        <?= __t('ui.un_changement_de_mot_de_passe_est_requis') ?>
    </div>
    <?php endif; ?>

    <div class="flex bg-white/5 rounded-xl p-1 mb-6 border border-white/10"
         role="tablist" aria-label="<?= htmlspecialchars(__t('ui.mode_de_gestion') ?: 'Mode de gestion') ?>">
        <button onclick="setMode('change')"  id="tab-change"  role="tab"
                aria-selected="true"  aria-controls="panel-change"
                class="lpc-focusable tab active flex-1 py-2 text-xs font-bold uppercase tracking-wider rounded-lg transition-all">
            <?= __t('ui.changer') ?>
        </button>
        <button onclick="setMode('request')" id="tab-request" role="tab"
                aria-selected="false" aria-controls="panel-request"
                class="lpc-focusable tab flex-1 py-2 text-xs font-bold uppercase tracking-wider rounded-lg transition-all">
            <?= __t('ui.oubli') ?>
        </button>
    </div>

    <div id="alert" class="hidden mb-4 p-3 rounded-lg text-sm font-medium border" role="alert" aria-live="polite"></div>

    <!-- =========== CHANGE =========== -->
    <form id="form-change" role="tabpanel" aria-labelledby="tab-change" tabindex="0" class="space-y-4">
        <div>
            <label class="block text-xs uppercase tracking-wider text-white/70 mb-1.5"><?= __t('ui.code_employ') ?></label>
            <input type="text" name="employee_code" required autocomplete="username" value="<?= $prefCode ?>"
                   class="w-full field rounded-xl py-3 px-4 text-sm" placeholder="Ex: LPC-001">
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wider text-white/70 mb-1.5"><?= __t('ui.ancien_mot_de_passe') ?></label>
            <input type="password" name="old_password" required autocomplete="current-password"
                   class="w-full field rounded-xl py-3 px-4 text-sm" placeholder="••••••••">
        </div>

        <hr class="border-white/10">

        <div>
            <label class="block text-xs uppercase tracking-wider text-white/70 mb-1.5"><?= __t('ui.nouveau_mot_de_passe') ?></label>
            <div class="relative">
                <input type="password" id="new_password_change" name="new_password" required autocomplete="new-password"
                       class="w-full field rounded-xl py-3 pl-4 pr-11 text-sm" placeholder="••••••••">
                <button type="button" onclick="toggleVis('new_password_change', 'eye-c')" class="absolute inset-y-0 right-0 px-3 text-white/70 hover:text-white" aria-label="Show password">
                    <i id="eye-c" class="fas fa-eye"></i>
                </button>
            </div>
            <ul class="mt-2 text-[10px] space-y-1 pl-1">
                <li id="ruleC-len"  class="invalid"><i class="fas fa-circle text-[6px] mr-1"></i> Min 8 caractères</li>
                <li id="ruleC-cap"  class="invalid"><i class="fas fa-circle text-[6px] mr-1"></i> 1 majuscule</li>
                <li id="ruleC-num"  class="invalid"><i class="fas fa-circle text-[6px] mr-1"></i> 1 chiffre</li>
                <li id="ruleC-spec" class="invalid"><i class="fas fa-circle text-[6px] mr-1"></i> 1 caractère spécial (!@#$%^&amp;*(),.?":{}|&lt;&gt;)</li>
            </ul>
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wider text-white/70 mb-1.5"><?= __t('ui.modal.confirm') ?></label>
            <input type="password" id="confirm_change" required autocomplete="new-password"
                   class="w-full field rounded-xl py-3 px-4 text-sm" placeholder="••••••••">
            <p id="match-change" class="text-xs mt-1 font-bold hidden"></p>
        </div>

        <button type="submit" id="btn-change" disabled
                class="mt-4 w-full py-3.5 rounded-xl bg-gradient-to-r from-lpc-dark to-lpc-light text-white font-bold text-sm uppercase tracking-widest opacity-50 cursor-not-allowed transition-all">
            <?= __t('ui.mettre_jour') ?>
        </button>
    </form>

    <!-- =========== REQUEST RESET =========== -->
    <form id="form-request" role="tabpanel" aria-labelledby="tab-request" tabindex="0" hidden class="space-y-4">
        <p class="text-xs text-white/60">
            <?= __t('ui.saisissez_votre_code_employ_et_votre_ema') ?>
        </p>
        <div>
            <label class="block text-xs uppercase tracking-wider text-white/70 mb-1.5"><?= __t('ui.code_employ') ?></label>
            <input type="text" name="employee_code" required autocomplete="username"
                   class="w-full field rounded-xl py-3 px-4 text-sm" placeholder="Ex: LPC-001">
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wider text-white/70 mb-1.5"><?= __t('ui.email_d_entreprise') ?></label>
            <input type="email" name="email" required autocomplete="email"
                   class="w-full field rounded-xl py-3 px-4 text-sm" placeholder="nom.prenom@lapetitecour.com">
        </div>
        <button type="submit"
                class="mt-2 w-full py-3.5 rounded-xl bg-gradient-to-r from-lpc-dark to-lpc-light text-white font-bold text-sm uppercase tracking-widest transition-all hover:opacity-90">
            <?= __t('ui.envoyer_le_lien') ?>
        </button>
    </form>

    <div class="text-center mt-6">
        <?php if (!empty($_SESSION['user_id'])): ?>
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
<script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"  defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-rbac.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/modules/auth-password_manager.js') ?>" defer></script>
</body>
</html>
