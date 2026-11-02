<?php
/**
 * public/auth/device_setup.php  --  "Sécuriser cet appareil" (2 Nov 2026).
 *
 * Reached right after a password sign-in with "Se souvenir de cet appareil"
 * ticked (api/v1/auth.php), or at any time from "Changer mon mot de passe".
 * Sets this browser's 4-digit PIN and adds a passkey, so the next sign-in here
 * is "Bon retour" + PIN or fingerprint/face instead of the password. Posts to
 * api/v1/device_auth.php. "Continuer" goes on to where sign-in was heading.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/sign_in.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

$lang = lpc_i18n_current_lang();
$t    = static fn (string $fr, string $en): string => $lang === 'fr' ? $fr : $en;
// Only a path on this site: never an open redirect
$next = (string) ($_GET['next'] ?? '');
if (!preg_match('#^/(?![/\\\\])[^\s]*$#', $next)) $next = '/index.php';

$db       = Database::getInstance()->getConnection();
$device   = lpc_device_current($db);
$isMine   = $device && (int) $device['user_id'] === (int) $_SESSION['user_id'];
$pkCount  = $db->prepare("SELECT COUNT(*) FROM user_passkeys WHERE user_id = ? AND revoked_at IS NULL");
$pkCount->execute([(int) $_SESSION['user_id']]);
$passkeys = (int) $pkCount->fetchColumn();
$first    = trim(explode(' ', (string) ($_SESSION['user_name'] ?? ''))[0] ?? '');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $t('Sécuriser cet appareil', 'Secure this device') ?> | Bureau LPC</title>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
<style>
body{background:#051A0F;color:#eee;min-height:100vh;font-family:Inter,sans-serif}
.glass{background:rgba(255,255,255,.08);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.15);box-shadow:0 25px 50px -12px rgba(0,0,0,.5)}
.field{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:#fff}
.field:focus{border-color:#8CC63F;background:rgba(255,255,255,.1);outline:none}
</style>
</head>
<body class="flex items-center justify-center p-4">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<div id="main" role="main" class="w-full max-w-md glass rounded-3xl p-8 my-8"
     data-next="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
    <div class="text-center mb-6">
        <h1 class="text-white text-2xl font-semibold"><?= $t('Sécuriser cet appareil', 'Secure this device') ?></h1>
        <p class="text-white/60 text-sm mt-2">
            <?= $first !== '' ? htmlspecialchars($first) . ', ' : '' ?><?= $t(
                'la prochaine fois ici, entrez simplement votre code ou utilisez votre empreinte.',
                'next time on this device, just enter your PIN or use your fingerprint.') ?>
        </p>
    </div>

    <div id="alert" class="hidden mb-4 p-3 rounded-lg text-sm font-medium border" role="alert" aria-live="polite"></div>

<?php if (!$isMine): ?>
    <div class="p-4 rounded-xl border border-amber-400/30 bg-amber-400/10 mb-4">
        <p class="text-xs text-amber-100/90 mb-3"><?= $t("Cet appareil n'est pas encore mémorisé pour votre compte.", 'This device is not remembered for your account yet.') ?></p>
        <button type="button" id="btn-remember" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-lpc-dark to-lpc-light text-white font-bold text-xs uppercase tracking-widest">
            <?= $t('Mémoriser cet appareil (' . LPC_DEVICE_DAYS . ' jours)', 'Remember this device (' . LPC_DEVICE_DAYS . ' days)') ?>
        </button>
    </div>
<?php else: ?>
    <p class="text-[11px] text-white/50 mb-4 text-center"><i class="fas fa-laptop mr-1"></i><?= htmlspecialchars($device['label'] ?? '') ?> · <?= $t('mémorisé ' . LPC_DEVICE_DAYS . ' jours', 'remembered for ' . LPC_DEVICE_DAYS . ' days') ?></p>

    <form id="form-pin" class="space-y-3">
        <h2 class="text-sm font-semibold text-white">
            <?= $t('Code à ' . LPC_PIN_LENGTH . ' chiffres', LPC_PIN_LENGTH . '-digit PIN') ?>
            <?php if (!empty($device['pin_hash'])): ?><span class="ml-2 text-[11px] text-lpc-light"><i class="fas fa-check"></i> <?= $t('défini', 'set') ?></span><?php endif; ?>
        </h2>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="pin" class="sr-only"><?= $t('Code', 'PIN') ?></label>
                <input type="password" id="pin" name="pin" inputmode="numeric" pattern="\d{<?= LPC_PIN_LENGTH ?>}" maxlength="<?= LPC_PIN_LENGTH ?>" required
                       autocomplete="off" class="w-full field rounded-xl py-3 text-center text-xl tracking-[0.6em]" placeholder="<?= $t('Code', 'PIN') ?>">
            </div>
            <div>
                <label for="pin_confirm" class="sr-only"><?= $t('Confirmer le code', 'Confirm PIN') ?></label>
                <input type="password" id="pin_confirm" name="pin_confirm" inputmode="numeric" pattern="\d{<?= LPC_PIN_LENGTH ?>}" maxlength="<?= LPC_PIN_LENGTH ?>" required
                       autocomplete="off" class="w-full field rounded-xl py-3 text-center text-xl tracking-[0.6em]" placeholder="<?= $t('Confirmer', 'Confirm') ?>">
            </div>
        </div>
        <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-lpc-dark to-lpc-light text-white font-bold text-xs uppercase tracking-widest hover:opacity-90">
            <?= !empty($device['pin_hash']) ? $t('Changer le code', 'Change PIN') : $t('Enregistrer le code', 'Save PIN') ?>
        </button>
        <p class="text-[11px] text-white/40"><?= $t(
            'Après ' . LPC_PIN_MAX_FAILURES . ' codes erronés, l\'appareil est oublié et le mot de passe redevient nécessaire.',
            'After ' . LPC_PIN_MAX_FAILURES . ' wrong PINs the device is forgotten and the password is needed again.') ?></p>
    </form>

    <div id="passkey-box" class="mt-6 pt-5 border-t border-white/10 hidden">
        <h2 class="text-sm font-semibold text-white">
            <?= $t("Clé d'accès (empreinte ou visage)", 'Passkey (fingerprint or face)') ?>
            <?php if ($passkeys > 0): ?><span class="ml-2 text-[11px] text-lpc-light"><i class="fas fa-check"></i> <?= $passkeys ?></span><?php endif; ?>
        </h2>
        <p class="text-[11px] text-white/50 mt-1 mb-3"><?= $t(
            "Votre téléphone ou ordinateur garde la clé et la déverrouille avec votre empreinte, votre visage ou son code. Rien n'est transmis d'autre que la preuve.",
            'Your phone or computer keeps the key and unlocks it with your fingerprint, face or device PIN. Only the proof is sent.') ?></p>
        <button type="button" id="btn-passkey-add" class="w-full py-3 rounded-xl border border-white/20 text-white font-bold text-xs uppercase tracking-widest hover:bg-white/10">
            <?= $t("Ajouter une clé d'accès", 'Add a passkey') ?>
        </button>
    </div>
<?php endif; ?>

    <a id="btn-continue" href="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>"
       class="block mt-6 text-center text-xs text-white/70 hover:text-white font-medium uppercase tracking-wider">
        <?= $t('Continuer', 'Continue') ?> <i class="fas fa-arrow-right ml-1"></i>
    </a>
</div>

<?= Rbac::jsBootstrap() ?>
<script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"  defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-rbac.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/modules/auth-device_setup.js') ?>" defer></script>
</body>
</html>
