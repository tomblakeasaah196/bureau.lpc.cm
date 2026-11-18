<?php
/**
 * scripts/video/router.php: PHP's built-in server standing in for Apache on the filming copy
 * (docs/TRAINING_VIDEOS.md §4.1). Never used on the server: scripts/ is refused there (.htaccess), and
 * this file refuses to run under anything but `php -S`.
 *
 *   php -S 127.0.0.1:8140 -t <repo> scripts/video/router.php      (scripts/video/make.js serve does it)
 *
 * It applies what .htaccess does for a visitor: the same refusals (includes/, scripts/, .env, .sql…),
 * the same short addresses (/facture.php, /reset/<token>, /sign/bl/<token>…), uploads served as files,
 * and everything else that is not a file sent to index.php. The address bar reads http://bureau.lpc.cm:
 * the camera's Chromium sends that host here (film.js).
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$uri  = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
$path = ltrim($uri, '/');

// The refusals (.htaccess lines 41-48 and the FilesMatch blocks).
$denied = [
    '#^(includes|scripts|migrations|docs|vendor|node_modules)/#',
    '#(^|/)\.(git|env|ht|svn|hg)#',
    '#\.(sql|sqlite|sqlite3|bak|backup|old|orig|save|swp|log|ini|conf|yml|yaml|dist|lock)$#i',
    '#^(composer\.(json|lock)|package(-lock)?\.json|README\.md|AUDIT_REPORT\.md|error_log)$#',
];
foreach ($denied as $re) {
    if (preg_match($re, $path)) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
}

// The short addresses (.htaccess lines 66-96).
$rewrites = [
    '#^bon_commande\.php$#'     => '/public/documents/bon_commande.php',
    '#^bon_livraison\.php$#'    => '/public/documents/bon_livraison.php',
    '#^facture\.php$#'          => '/public/documents/facture.php',
    '#^quote\.php$#'            => '/public/documents/quote.php',
    '#^sign_bl\.php$#'          => '/public/documents/sign_bl.php',
    '#^sign_cre\.php$#'         => '/public/documents/sign_cre.php',
    '#^sign_quote\.php$#'       => '/public/documents/sign_quote.php',
    '#^sign_document\.php$#'    => '/public/documents/sign_document.php',
    '#^print_cre\.php$#'        => '/public/documents/print_cre.php',
    '#^verify\.php$#'           => '/public/verify.php',
    '#^password_manager\.php$#' => '/public/auth/password_manager.php',
    '#^password_reset\.php$#'   => '/public/auth/password_reset.php',
    '#^password_forgot\.php$#'  => '/public/auth/password_forgot.php',
    '#^device_setup\.php$#'     => '/public/auth/device_setup.php',
    '#^reset/([A-Fa-f0-9]{32,128})/?$#'      => '/public/auth/password_reset.php?token=$1',
    '#^invoice/([A-Fa-f0-9]{16,64})/?$#'     => '/public/documents/facture.php?token=$1',
    '#^delivery/([A-Fa-f0-9]{16,64})/?$#'    => '/public/documents/bon_livraison.php?token=$1',
    '#^quote/([A-Fa-f0-9]{16,64})/?$#'       => '/public/documents/quote.php?token=$1',
    '#^po/([A-Fa-f0-9]{16,64})/?$#'          => '/public/documents/bon_commande.php?token=$1',
    '#^sign/bl/([A-Fa-f0-9]{16,64})/?$#'     => '/public/documents/sign_bl.php?token=$1',
    '#^sign/cre/([A-Fa-f0-9]{16,64})/?$#'    => '/public/documents/sign_cre.php?token=$1',
    '#^sign/quote/([A-Fa-f0-9]{16,64})/?$#'  => '/public/documents/sign_quote.php?token=$1',
    '#^sign/([a-z_]+)/([A-Fa-f0-9]{16,64})/?$#' => '/public/documents/sign_document.php?type=$1&token=$2',
    '#^verify/([a-f0-9]{40})/?$#'            => '/public/verify.php?token=$1',
];

$target = null;
foreach ($rewrites as $re => $to) {
    if (preg_match($re, $path, $m)) {
        $target = preg_replace($re, $to, $path);
        break;
    }
}

// The page is required at the top level of this file, as Apache runs it, not inside a function:
// a page or a component that reads $GLOBALS (signature_share_button.php reads $GLOBALS['share_type'])
// found nothing, and « Faire signer le client » never showed on the copy.
$run = function (string $file, string $script, array $query = []) use ($root): string {
    // Courrier and its Praxis AI never reach a real server from the copy (scripts/video/mail-fake.php).
    require_once __DIR__ . '/mail-fake.php';
    video_mail_install();
    foreach ($query as $k => $v) {
        $_GET[$k] = $v;
        $_REQUEST[$k] = $v;
    }
    $_SERVER['SCRIPT_FILENAME'] = $file;
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $script;
    chdir(dirname($file));
    return $file;
};

$lpcVideoPage = null;
if ($target !== null) {
    [$script, $qs] = array_pad(explode('?', $target, 2), 2, '');
    parse_str($qs, $query);
    $lpcVideoPage = $run($root . $script, $script, $query);
} else {
    $file = $root . '/' . $path;
    if ($path !== '' && is_file($file)) {
        if (!str_ends_with($file, '.php')) {
            return false; // a static file: the built-in server sends it with its type
        }
        $lpcVideoPage = $run($file, '/' . $path);
    } elseif ($path !== '' && is_dir($file) && is_file($file . '/index.php')) {
        $lpcVideoPage = $run($file . '/index.php', '/' . trim($path, '/') . '/index.php');
    } else {
        // Anything else goes to the sign-in page, as Apache's last rule sends it (index.php?route=…).
        $lpcVideoPage = $run($root . '/index.php', '/index.php', $path === '' ? [] : ['route' => $path]);
    }
}
unset($file, $script, $qs, $query);
require $lpcVideoPage;
return true;
