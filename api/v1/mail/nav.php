<?php
/**
 * api/v1/mail/nav.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier: the unread count shown next to Courrier in the
 * sidebar, for pages that change it without a reload (PR 2's inbox). GET only:
 * {status: 'success', data: {unread: int}}. ?fresh=1 skips the 30-second cache.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/mail/autoload.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

Rbac::requireAuth();
$userId = (int) ($_SESSION['user_id'] ?? 0);
if (!empty($_GET['fresh'])) {
    \LPC\Mail\MailNav::forget();
}
echo json_encode(['status' => 'success', 'data' => ['unread' => \LPC\Mail\MailNav::unread($userId)]]);
