<?php
/**
 * modules/mail/signature.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Ma signature. PLACEHOLDER from PR 1: PR 3
 * (writing and signatures) replaces this file with the signature page: one
 * company design built from the HR record (name, job title, department,
 * phone), the LPC logo and the company details, where a person edits only
 * their own numbers (owner decision; settings keys signature.*, docs/MAIL.md §5).
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/mail/autoload.php';
Rbac::requireAuth();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ma signature | Courrier | Bureau LPC</title>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-mail.css') ?>">
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>
<?php
$pageTitle    = 'Ma signature';
$pageSubtitle = 'Courrier';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>
<div id="lpc-shell-main">
    <div class="lpc-toolbar">
        <p class="lpc-toolbar-lead mb-toolbar-lead">Ma signature</p>
        <a href="/modules/mail/index.php" class="mb-btn mb-btn--ghost">Ouvrir Courrier</a>
    </div>
    <main role="main" id="main" class="lpc-page mb-page">
        <section class="mb-card mb-placeholder">
            <h2 class="mb-h2">Votre signature arrive bientôt</h2>
            <p class="mb-muted">Une signature au design de LPC, faite à partir de votre fiche du personnel (nom, poste, service, téléphone), avec le logo et les coordonnées de l’entreprise. Vous n’aurez à saisir que vos numéros.</p>
        </section>
    </main>
</div>
</body>
</html>
