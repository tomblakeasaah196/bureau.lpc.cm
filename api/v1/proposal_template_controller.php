<?php
/**
 * api/v1/proposal_template_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7E · Proposal Studio backend.
 *
 * Backs modules/crm/proposal_studio.php. Every action is gated on
 * `crm.proposals.template`, which is deliberately NOT granted to anyone who
 * merely creates quotes: editing the contract terms every future client will
 * sign is a different level of authority from writing one devis.
 *
 * Actions:
 *   get           → full studio payload (grouped, with defaults + metadata)
 *   save          → bulk upsert of edited values
 *   reset_field   → restore one key to its hardcoded default
 *   reset_all     → restore the entire template
 *   upload_image  → store a logo under /uploads/proposal/ and return its path
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/proposal_template.php';
require_once __DIR__ . '/../../includes/classes/Uploads.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requireAuth();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/** Uniform JSON exit. */
function pt_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Uniform failure. Never leaks $e->getMessage() to the client. */
function pt_fail(string $msg, int $code = 400): void
{
    pt_out(['status' => 'error', 'message' => $msg], $code);
}

/**
 * Keys are code-owned: the studio may only write keys that exist in
 * lpc_proposal_defaults(). This is the guard that stops a crafted POST from
 * inserting arbitrary rows into the settings table.
 */
function pt_is_known_key(string $key): bool
{
    return array_key_exists($key, lpc_proposal_defaults());
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    error_log('proposal_template_controller: DB unavailable: ' . $e->getMessage());
    pt_fail('Service indisponible.', 503);
}

switch ($action) {

    // -------------------------------------------------------------------------
    case 'get':
        Rbac::requirePermission('crm.proposals.template');

        pt_out([
            'status'  => 'success',
            'groups'  => lpc_proposal_studio_payload(),
            'labels'  => lpc_proposal_group_labels(),
            'order'   => ['brand', 'p1', 'p2', 'logos', 'p3', 'p4', 'share', 'ui'],
        ]);
        break;

    // -------------------------------------------------------------------------
    case 'save':
        Rbac::requirePermission('crm.proposals.template');
        Csrf::requireValid();

        // Accept either a JSON body or a form-encoded `fields` blob.
        $raw = file_get_contents('php://input');
        $body = json_decode($raw ?: '[]', true);
        $fields = is_array($body['fields'] ?? null)
            ? $body['fields']
            : json_decode((string) ($_POST['fields'] ?? '[]'), true);

        if (!is_array($fields) || $fields === []) {
            pt_fail('Aucune modification reçue.');
        }

        $userId   = (int) ($_SESSION['user_id'] ?? 0);
        $saved    = 0;
        $skipped  = [];
        $tooLong  = [];

        try {
            $db->beginTransaction();

            $stmt = $db->prepare(
                "UPDATE proposal_template_settings
                    SET value_fr = ?, value_en = ?, updated_by = ?
                  WHERE setting_key = ?"
            );

            foreach ($fields as $key => $pair) {
                $key = (string) $key;
                if (!pt_is_known_key($key)) { $skipped[] = $key; continue; }

                // Normalize line endings so a Windows browser and the PDF
                // renderer agree on what a paragraph break is.
                $fr = str_replace("\r\n", "\n", (string) ($pair['fr'] ?? ''));
                $en = str_replace("\r\n", "\n", (string) ($pair['en'] ?? ''));

                // Length budget. The browser sets `maxlength` too, but that is
                // a convenience, not a control: a maxlength attribute is gone
                // the moment someone POSTs directly. The proposal pages are
                // fixed-height with overflow:hidden, so over-long text is
                // silently clipped in front of a client — this is the check
                // that actually prevents it.
                $lim = lpc_proposal_limits($key);
                if (mb_strlen($fr) > $lim['max_fr'] || mb_strlen($en) > $lim['max_en']) {
                    $tooLong[] = $key;
                    continue;
                }

                $stmt->execute([$fr, $en, $userId ?: null, $key]);
                $saved++;
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('proposal_template save failed: ' . $e->getMessage());
            pt_fail("Échec de l'enregistrement.", 500);
        }

        $msg = $saved . ' champ(s) enregistré(s).';
        if ($tooLong !== []) {
            $msg .= ' ' . count($tooLong) . ' champ(s) refusé(s) : texte trop long pour la mise en page.';
        }

        pt_out([
            'status'   => 'success',
            'saved'    => $saved,
            'skipped'  => $skipped,
            'too_long' => $tooLong,
            'message'  => $msg,
        ]);
        break;

    // -------------------------------------------------------------------------
    case 'reset_field':
        Rbac::requirePermission('crm.proposals.template');
        Csrf::requireValid();

        $key = (string) ($_POST['key'] ?? '');
        if (!pt_is_known_key($key)) pt_fail('Champ inconnu.');

        $def = lpc_proposal_defaults()[$key];

        try {
            $stmt = $db->prepare(
                "UPDATE proposal_template_settings
                    SET value_fr = ?, value_en = ?, updated_by = ?
                  WHERE setting_key = ?"
            );
            $stmt->execute([$def[0], $def[1], (int) ($_SESSION['user_id'] ?? 0) ?: null, $key]);
        } catch (Throwable $e) {
            error_log('proposal_template reset_field failed: ' . $e->getMessage());
            pt_fail('Échec de la réinitialisation.', 500);
        }

        pt_out([
            'status'   => 'success',
            'key'      => $key,
            'value_fr' => $def[0],
            'value_en' => $def[1],
        ]);
        break;

    // -------------------------------------------------------------------------
    case 'reset_all':
        Rbac::requirePermission('crm.proposals.template');
        Csrf::requireValid();

        // Guard against a mis-click wiping months of copywriting.
        if (($_POST['confirm'] ?? '') !== 'RESET') {
            pt_fail('Confirmation requise.');
        }

        try {
            $db->beginTransaction();
            $stmt = $db->prepare(
                "UPDATE proposal_template_settings
                    SET value_fr = ?, value_en = ?, updated_by = ?
                  WHERE setting_key = ?"
            );
            $uid = (int) ($_SESSION['user_id'] ?? 0) ?: null;
            foreach (lpc_proposal_defaults() as $key => $def) {
                $stmt->execute([$def[0], $def[1], $uid, $key]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('proposal_template reset_all failed: ' . $e->getMessage());
            pt_fail('Échec de la réinitialisation.', 500);
        }

        pt_out(['status' => 'success', 'message' => 'Modèle réinitialisé.']);
        break;

    // -------------------------------------------------------------------------
    case 'upload_image':
        Rbac::requirePermission('crm.proposals.template');
        Csrf::requireValid();

        $key = (string) ($_POST['key'] ?? '');
        if (!pt_is_known_key($key)) pt_fail('Champ inconnu.');

        try {
            // SVG is deliberately absent from the allow-list: an SVG is an XML
            // document that can carry <script>, and these files are rendered
            // inside a page we serve to clients. Raster only.
            $res = Uploads::saveUploaded('image', 'proposal', [
                'allowed_mime' => ['image/png', 'image/jpeg', 'image/webp'],
                'sanitize_img' => true,
                'max_bytes'    => 2 * 1024 * 1024,
            ]);
        } catch (Throwable $e) {
            error_log('proposal_template upload failed: ' . $e->getMessage());
            // Upload errors ARE surfaced: they are user-actionable ("file too
            // large", "wrong type") and contain no internal detail.
            pt_fail($e->getMessage(), 422);
        }

        try {
            $stmt = $db->prepare(
                "UPDATE proposal_template_settings
                    SET value_fr = ?, updated_by = ?
                  WHERE setting_key = ?"
            );
            $stmt->execute([$res['path'], (int) ($_SESSION['user_id'] ?? 0) ?: null, $key]);
        } catch (Throwable $e) {
            error_log('proposal_template upload persist failed: ' . $e->getMessage());
            pt_fail("Fichier reçu mais non enregistré.", 500);
        }

        pt_out(['status' => 'success', 'key' => $key, 'path' => $res['path']]);
        break;

    // -------------------------------------------------------------------------
    default:
        pt_fail('Action inconnue.', 400);
}
