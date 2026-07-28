<?php
/**
 * public/auth/password_reset.php
 * -----------------------------------------------------------------------------
 * Landing page for the email link. Validates the token client-side (server
 * re-validates on submit) and lets the user pick a new password. On success
 * → back to /index.php to log in fresh.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

$lang  = in_array(($_GET['lang'] ?? 'fr'), ['fr','en'], true) ? ($_GET['lang'] ?? 'fr') : 'fr';
$token = trim($_GET['token'] ?? '');
if ($token === '' || strlen($token) < 32 || strlen($token) > 128) {
    header('Location: /index.php?error=reset_invalid'); exit;
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= __t('ui.nouveau_mot_de_passe') ?> | Bureau LPC</title>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

<style>
body{background:#051A0F;color:#eee;min-height:100vh;font-family:Inter,sans-serif}
.glass{background:rgba(255,255,255,.08);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.15);box-shadow:0 25px 50px -12px rgba(0,0,0,.5)}
.field{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:#fff}
.field:focus{border-color:#8CC63F;background:rgba(255,255,255,.1);outline:none}
.valid{color:#8CC63F}.invalid{color:rgba(255,255,255,.4)}
</style>
</head>
<body class="flex items-center justify-center p-4">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


<div class="w-full max-w-md glass  id="main" role="main"rounded-3xl p-8">
    <div class="text-center mb-6">
        <h1 class="text-white text-2xl font-semibold"><?= __t('ui.nouveau_mot_de_passe') ?></h1>
        <p class="text-lpc-light text-xs mt-2 uppercase tracking-[0.2em]"><?= htmlspecialchars(CompanyProfile::displayName()) ?></p>
    </div>

    <div id="alert" class="hidden mb-4 p-3 rounded-lg text-sm font-medium border" role="alert" aria-live="polite"></div>

    <form id="form-reset" class="space-y-4">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div>
            <label class="block text-xs uppercase tracking-wider text-white/70 mb-1.5"><?= __t('ui.nouveau_mot_de_passe') ?></label>
            <div class="relative">
                <input type="password" id="np" name="new_password" required autocomplete="new-password"
                       class="w-full field rounded-xl py-3 pl-4 pr-11 text-sm" placeholder="••••••••">
                <button type="button" onclick="tv()" class="absolute inset-y-0 right-0 px-3 text-white/70 hover:text-white" aria-label="Show"><i id="eye" class="fas fa-eye"></i></button>
            </div>
            <ul class="mt-2 text-[10px] space-y-1 pl-1">
                <li id="r-len"  class="invalid"><i class="fas fa-circle text-[6px] mr-1"></i> Min 8 caractères</li>
                <li id="r-cap"  class="invalid"><i class="fas fa-circle text-[6px] mr-1"></i> 1 majuscule</li>
                <li id="r-num"  class="invalid"><i class="fas fa-circle text-[6px] mr-1"></i> 1 chiffre</li>
                <li id="r-spec" class="invalid"><i class="fas fa-circle text-[6px] mr-1"></i> 1 caractère spécial</li>
            </ul>
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wider text-white/70 mb-1.5"><?= __t('ui.modal.confirm') ?></label>
            <input type="password" id="cp" required autocomplete="new-password"
                   class="w-full field rounded-xl py-3 px-4 text-sm" placeholder="••••••••">
            <p id="mt" class="text-xs mt-1 font-bold hidden"></p>
        </div>

        <button type="submit" id="btn" disabled
                class="mt-2 w-full py-3.5 rounded-xl bg-gradient-to-r from-lpc-dark to-lpc-light text-white font-bold text-sm uppercase tracking-widest opacity-50 cursor-not-allowed transition-all">
            <?= __t('ui.enregistrer') ?>
        </button>

        <div class="text-center pt-2">
            <a href="/index.php" class="text-xs text-white/70 hover:text-white font-medium uppercase tracking-wider"><i class="fas fa-arrow-left mr-1"></i> <?= __t('ui.connexion') ?></a>
        </div>
    </form>
</div>

<?= Rbac::jsBootstrap() ?>
<script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"  defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-rbac.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/modules/auth-password_reset.js') ?>" defer></script>
</body>
</html>
