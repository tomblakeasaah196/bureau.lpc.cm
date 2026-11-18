<?php
/**
 * public/auth/verify_code.php — « Code de connexion »: the second step of an
 * administrator's sign-in when Paramètres → « Imposer la 2FA aux
 * administrateurs » is on (includes/functions/admin_2fa.php). Posts to
 * api/v1/auth_2fa.php.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/account_recovery.php';

$pending = $_SESSION['pending_2fa'] ?? null;
if (!$pending) { header('Location: /index.php'); exit; }
$flash = $_SESSION['flash_2fa'] ?? '';
unset($_SESSION['flash_2fa']);
$db = Database::getInstance()->getConnection();
$em = $db->prepare("SELECT email FROM users WHERE id = ?");
$em->execute([(int) $pending['user_id']]);
$masked = lpc_mask_email((string) $em->fetchColumn());
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Code de connexion | Bureau LPC</title>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
body{background:#051A0F;color:#eee;min-height:100vh;font-family:Inter,sans-serif}
.glass{background:rgba(255,255,255,.08);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.15);box-shadow:0 25px 50px -12px rgba(0,0,0,.5)}
.field{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:#fff}
.field:focus{border-color:#8CC63F;background:rgba(255,255,255,.1);outline:none}
</style>
</head>
<body class="flex items-center justify-center p-4">
<div id="main" role="main" class="w-full max-w-md glass rounded-3xl p-8 my-8">
    <div class="text-center mb-6">
        <h1 class="text-white text-2xl font-semibold">Code de connexion</h1>
        <p class="text-lpc-light text-xs mt-2 uppercase tracking-[0.2em] font-medium"><?= htmlspecialchars(CompanyProfile::displayName()) ?></p>
    </div>
    <?php if ($flash !== ''): ?>
        <div class="mb-4 p-3 rounded-lg text-sm font-medium border border-red-400/40 bg-red-500/10 text-red-100" role="alert"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <p class="text-sm text-white/80 mb-4">Un code à 6 chiffres vient d'être envoyé à <b><?= htmlspecialchars($masked) ?></b>. Il est valable 10 minutes.</p>
    <form method="post" action="/api/v1/auth_2fa.php" class="space-y-4" autocomplete="off">
        <?= Csrf::field() ?>
        <div>
            <label for="code" class="block text-xs uppercase tracking-wider text-white/70 mb-1.5">Code reçu par email</label>
            <input type="text" id="code" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus
                   class="w-full field rounded-xl py-3 px-4 text-2xl tracking-[0.5em] text-center font-bold">
        </div>
        <button type="submit" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-lpc-dark to-lpc-light text-white font-bold text-sm uppercase tracking-widest hover:opacity-90">Se connecter</button>
    </form>
    <p class="text-[11px] text-white/50 mt-5">Pas de code ? Vérifiez les indésirables, ou <a href="/index.php" class="underline">reconnectez-vous</a> pour en recevoir un nouveau.</p>
</div>
</body>
</html>
