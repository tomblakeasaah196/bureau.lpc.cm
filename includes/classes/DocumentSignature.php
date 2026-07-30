<?php
/**
 * includes/classes/DocumentSignature.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — internal digital sign-off for outbound documents (migration
 * 048). First (and so far only) consumer: the devis one-pager.
 *
 * WHAT THIS IS NOT
 *   SignerOtp (Sprint 7C) is the CLIENT's signature — a phone OTP proving the
 *   person confirming a bon de livraison is who the delivery was scheduled
 *   against. This class is the opposite party: an LPC staff member, already
 *   authenticated in the ERP, attesting that the figures on one specific
 *   rendering of one specific document are the ones LPC stands behind. The
 *   two are never on the same document for the same reason.
 *
 * IDENTITY NEVER COMES FROM THE REQUEST
 *   sign() takes a $userId, but the name and role it PRINTS come from
 *   UserProfile — resolved server-side from the session, not from POST data.
 *   A caller cannot sign as "Le Directeur Général" by typing it into a form.
 *
 * WHY A SIGNATURE CAN GO STALE
 *   content_hash is sha256(canonicalPayload($doc)) at signing time.
 *   getActive() recomputes that same hash from the document as it stands
 *   RIGHT NOW and only returns a row if it matches. Edit a price after
 *   signing and the old row stops being "active" — the PDF falls back to
 *   unsigned rather than stamping a figure nobody actually attested to. The
 *   row itself is never deleted or overwritten, so
 *   "who signed which exact figures, and when" survives every later edit.
 *
 * USAGE
 *   DocumentSignature::sign('quote', $proposalId, $doc, $_SESSION['user_id']);
 *   DocumentSignature::getActive('quote', $proposalId, $doc);   // null if never
 *                                                                // signed / stale
 *   DocumentSignature::verifyByToken($token);                   // public/verify.php
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class DocumentSignature
{
    /** document_type values this class accepts. */
    public const TYPES = ['quote'];

    /**
     * The fields a signature attests to, in a fixed shape. Order and key
     * names are load-bearing: changing them silently invalidates every
     * signature issued before the change (the recomputed hash would no
     * longer match any stored one). If the shape ever needs to change,
     * bump 'v' rather than editing a field in place, and keep the old
     * version's logic reachable so historical signatures still verify.
     *
     * @return array<string,mixed>
     */
    public static function canonicalPayload(array $doc): array
    {
        $items = array_map(static function ($it) {
            return [
                'name'  => (string) ($it['name'] ?? ''),
                'qty'   => round((float) ($it['qty'] ?? 0), 3),
                'price' => round((float) ($it['unit_price'] ?? 0), 2),
            ];
        }, array_values($doc['items'] ?? []));

        $t = $doc['totals'] ?? [];
        $c = $doc['client'] ?? [];

        return [
            'v'           => 1,
            'reference'   => (string) ($doc['reference'] ?? ''),
            'revision'    => (int) ($doc['revision'] ?? 1),
            'date'        => (string) ($doc['date'] ?? ''),
            'valid_until' => (string) ($doc['valid_until'] ?? ''),
            'client'      => [
                'name' => (string) ($c['name'] ?? ''),
                'niu'  => (string) ($c['niu'] ?? ''),
                'rccm' => (string) ($c['rccm'] ?? ''),
            ],
            'items'       => $items,
            'subtotal'    => round((float) ($t['subtotal'] ?? 0), 2),
            'excise'      => round((float) ($t['excise'] ?? 0), 2),
            'tva_rate'    => round((float) ($t['tva_rate'] ?? 0), 2),
            'tax'         => round((float) ($t['tax'] ?? 0), 2),
            'grand_total' => round((float) ($t['grand_total'] ?? 0), 2),
        ];
    }

    /** sha256 of canonicalPayload($doc), stable key order via JSON. */
    public static function hash(array $doc): string
    {
        $json = json_encode(
            self::canonicalPayload($doc),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        return hash('sha256', (string) $json);
    }

    /**
     * Record a new signature and return the stored row.
     *
     * @throws RuntimeException on invalid input, incomplete profile, or DB failure
     */
    public static function sign(string $type, int $docId, array $doc, int $userId): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Type de document invalide.');
        }
        if ($docId <= 0 || $userId <= 0) {
            throw new RuntimeException('Contexte de signature manquant.');
        }

        if (!class_exists('UserProfile')) {
            require_once __DIR__ . '/UserProfile.php';
        }
        $name = trim((string) UserProfile::displayName());
        $role = trim((string) UserProfile::roleLabel());
        if ($name === '') {
            throw new RuntimeException("Profil utilisateur incomplet : impossible d'identifier le signataire.");
        }

        $hash  = self::hash($doc);
        // 40 hex chars — same shape as the token/document_id.token columns
        // elsewhere in the app, but a DIFFERENT value: this one identifies a
        // signature EVENT, not the document itself, and a document may
        // accumulate several over its life (signed, edited, re-signed).
        $token = bin2hex(random_bytes(20));

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare('
                INSERT INTO document_signatures
                    (document_type, document_id, signer_user_id, signer_name, signer_role,
                     content_hash, verify_token, ip, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $type,
                $docId,
                $userId,
                $name,
                $role,
                $hash,
                $token,
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            $id = (int) $db->lastInsertId();
        } catch (Throwable $e) {
            error_log('DocumentSignature::sign: ' . $e->getMessage());
            throw new RuntimeException('La signature n\'a pas pu être enregistrée. Réessayez.');
        }

        $row = self::rowById($id);
        if (!$row) {
            throw new RuntimeException('La signature a été enregistrée mais n\'a pas pu être relue.');
        }
        return $row;
    }

    /**
     * The signature that is valid for $doc RIGHT NOW: most recent,
     * not revoked, and its stored hash still matches the document as it
     * stands. Returns null if the document was never signed, every
     * signature was revoked, or the document changed since the last one.
     *
     * @return array<string,mixed>|null
     */
    public static function getActive(string $type, int $docId, array $doc): ?array
    {
        if ($docId <= 0) {
            return null;
        }
        $hash = self::hash($doc);

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare('
                SELECT * FROM document_signatures
                 WHERE document_type = ? AND document_id = ?
                   AND content_hash = ? AND revoked_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1
            ');
            $stmt->execute([$type, $docId, $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            // Table missing (migration not yet applied) or DB hiccup — the
            // document must still render, just without a signature block.
            error_log('DocumentSignature::getActive: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Most recent non-revoked signature for this document regardless of
     * whether its hash still matches — i.e. including STALE ones. Used to
     * tell "never signed" apart from "was signed, then edited" in the
     * internal UI (the public verify page never needs this: a stale
     * signature simply is not the one the QR on the current PDF points to).
     *
     * @return array<string,mixed>|null
     */
    public static function latestAny(string $type, int $docId): ?array
    {
        if ($docId <= 0) {
            return null;
        }
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare('
                SELECT * FROM document_signatures
                 WHERE document_type = ? AND document_id = ? AND revoked_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1
            ');
            $stmt->execute([$type, $docId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('DocumentSignature::latestAny: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Look up a signature by its public verify_token. Used exclusively by
     * public/verify.php, which is unauthenticated by design — the token
     * itself (40 random hex chars, never enumerable) is the credential.
     *
     * @return array<string,mixed>|null
     */
    public static function verifyByToken(string $token): ?array
    {
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare('SELECT * FROM document_signatures WHERE verify_token = ? LIMIT 1');
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('DocumentSignature::verifyByToken: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Revoke a signature without deleting it — the public verify page must
     * keep answering "revoked" (not 404) for anyone who scanned the QR
     * before the revocation.
     */
    public static function revoke(int $signatureId, int $byUserId, string $reason = ''): bool
    {
        if ($signatureId <= 0) {
            return false;
        }
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare('
                UPDATE document_signatures
                   SET revoked_at = UTC_TIMESTAMP(), revoked_by = ?, revoke_reason = ?
                 WHERE id = ? AND revoked_at IS NULL
            ');
            $stmt->execute([$byUserId > 0 ? $byUserId : null, substr($reason, 0, 255), $signatureId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('DocumentSignature::revoke: ' . $e->getMessage());
            return false;
        }
    }

    private static function rowById(int $id): ?array
    {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare('SELECT * FROM document_signatures WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('DocumentSignature::rowById: ' . $e->getMessage());
            return null;
        }
    }
}
