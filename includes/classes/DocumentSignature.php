<?php
/**
 * includes/classes/DocumentSignature.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — universal digital signature system across every document
 * type. This file is the single choke point every signature (internal or
 * external, quote / cre / bl / facture / bon_commande / payslip / contract)
 * has to pass through. Full spec in docs/SIGNATURES.md.
 *
 * TWO PARTIES, NEVER MIXED
 *   internal  — LPC staff, already authenticated in the ERP, attesting to
 *               the figures on one specific rendering of one specific
 *               document. Identity resolved server-side from UserProfile,
 *               NEVER from POST. No image is ever recorded — hash-only,
 *               same shape migration 048 established for the devis one-pager.
 *   external  — the counterparty (customer, recipient, supplier), signing on
 *               their phone via a token link. Draws a signature; the Base64
 *               PNG data URL is stored inline (Feb-era behaviour, per Q2 of
 *               the design conversation). Also types name / role / phone.
 *               No login, no OTP — the token IS the credential.
 *
 * WHY A SIGNATURE CAN GO STALE
 *   content_hash is sha256(canonicalPayload($type, $doc)) at signing time.
 *   getActive() recomputes that same hash from the document as it stands
 *   RIGHT NOW and only returns a row if it matches. Edit a price after
 *   signing and the old row stops being "active" — the PDF falls back to
 *   unsigned rather than stamping a figure nobody actually attested to. The
 *   row itself is never deleted or overwritten, so
 *   "who signed which exact figures, and when" survives every later edit.
 *
 * ADDING A NEW DOCUMENT TYPE
 *   1. Add its slug to self::TYPES.
 *   2. Implement canonicalPayload_{slug}($doc) below.
 *   3. Add the two permissions in a migration:
 *        signatures.{slug}.internal.sign, signatures.{slug}.external.sign
 *   4. Have the PDF template include includes/components/signature_block.php.
 *   That is the entire contract — nothing else in the codebase gets to
 *   invent its own signature scheme. docs/SIGNATURES.md is the human copy of
 *   this rule; self::TYPES + the missing-method throw in
 *   canonicalPayload() are the code enforcement.
 *
 * USAGE
 *   // internal — LPC staff, from inside the ERP:
 *   DocumentSignature::signInternal('quote', $proposalId, $doc, $_SESSION['user_id']);
 *
 *   // external — counterparty via token link (from signatures_controller.php):
 *   DocumentSignature::signExternal('cre', $creId, $doc, [
 *       'name'  => $postedName,
 *       'role'  => $postedRole,
 *       'phone' => $postedPhone,
 *   ], $base64PngDataUrl);
 *
 *   DocumentSignature::getActive('quote', $proposalId, $doc);          // any party
 *   DocumentSignature::getActiveByParty('cre', $creId, $doc, 'external');
 *   DocumentSignature::verifyByToken($publicVerifyToken);              // public/verify.php
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class DocumentSignature
{
    /**
     * Every document type this system supports. Registering a slug here is
     * step 1 of "how to add a new document type" — see the docblock above.
     * Callers that pass an unknown slug get a RuntimeException, not a
     * silently-dropped signature.
     */
    public const TYPES = [
        'quote',
        'cre',
        'bl',
        'facture',
        'bon_commande',
        'payslip',
        'contract',
    ];

    public const PARTIES = ['internal', 'external'];

    /**
     * Whether a party may sign a doc type. Right now every type accepts both
     * parties, but a future type (e.g. an internal-only audit report) can
     * override this without changing every call site.
     */
    public static function partyAllowed(string $type, string $party): bool
    {
        if (!in_array($type, self::TYPES, true))    return false;
        if (!in_array($party, self::PARTIES, true)) return false;
        return true;
    }

    /**
     * The permission name a caller needs to sign as this (type, party).
     * External signatures never actually check this — the token is the
     * credential — but the operator UI uses it to gate WHO can invite the
     * counterparty to sign.
     */
    public static function permissionFor(string $type, string $party): string
    {
        return "signatures.{$type}.{$party}.sign";
    }

    // ==========================================================================
    // Canonical payload — the fields a signature attests to, per doc type.
    //
    // Order and key names are load-bearing: changing them silently
    // invalidates every signature issued before the change (the recomputed
    // hash would no longer match any stored one). If a payload ever needs to
    // change, bump 'v' rather than editing a field in place, and keep the
    // old version's logic reachable so historical signatures still verify.
    //
    // scripts/tests/signature_canonical_payload.test.php pins one fixed
    // input JSON per type to a known sha256 — that test is what stops a
    // future refactor from breaking every signature at once.
    // ==========================================================================

    /**
     * Dispatcher. Throws if the type has no canonical payload function.
     * @return array<string,mixed>
     */
    public static function canonicalPayload(string $type, array $doc): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException("Type de document invalide : {$type}");
        }
        $method = 'canonicalPayload_' . $type;
        if (!method_exists(__CLASS__, $method)) {
            throw new RuntimeException(
                "Canonical payload manquant pour le type '{$type}'. "
                . "Voir docs/SIGNATURES.md — étape 2."
            );
        }
        return self::{$method}($doc);
    }

    /** sha256 of canonicalPayload(...), stable key order via JSON. */
    public static function hash(string $type, array $doc): string
    {
        $json = json_encode(
            self::canonicalPayload($type, $doc),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        return hash('sha256', (string) $json);
    }

    // --------------------------------------------------------------------------
    // quote — the Sprint 10 devis one-pager. Unchanged from migration 048.
    // Do NOT edit these fields in place — bump 'v' if the shape ever changes,
    // and keep the old branch reachable so 2025-signed offers still verify.
    // --------------------------------------------------------------------------
    private static function canonicalPayload_quote(array $doc): array
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
            'type'        => 'quote',
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

    // --------------------------------------------------------------------------
    // cre — Certificat de Restitution d'Emballages. The contract-relevant
    // facts are: which client returned what quantity of what packaging, on
    // what date, on which document reference. No fiscal ladder.
    // --------------------------------------------------------------------------
    private static function canonicalPayload_cre(array $doc): array
    {
        $items = array_map(static function ($it) {
            return [
                'name' => (string) ($it['name'] ?? ''),
                'qty'  => round((float) ($it['qty'] ?? 0), 3),
            ];
        }, array_values($doc['items'] ?? []));

        $c = $doc['client'] ?? [];

        return [
            'v'         => 1,
            'type'      => 'cre',
            'reference' => (string) ($doc['reference'] ?? ''),
            'date'      => (string) ($doc['date'] ?? ''),
            'client'    => [
                'name' => (string) ($c['name'] ?? ''),
                'niu'  => (string) ($c['niu'] ?? ''),
            ],
            'site'      => (string) ($doc['site_name'] ?? $c['site'] ?? ''),
            'items'     => $items,
            'total_qty' => round((float) array_sum(array_column($items, 'qty')), 3),
        ];
    }

    // --------------------------------------------------------------------------
    // bl — Bon de Livraison. Fields the customer's signature actually
    // certifies: the goods delivered, the empties returned, the amount paid
    // to the driver on the spot, and any reserves the customer noted.
    // --------------------------------------------------------------------------
    private static function canonicalPayload_bl(array $doc): array
    {
        $items = array_map(static function ($it) {
            return [
                'name'          => (string) ($it['name'] ?? ''),
                'qty_shipped'   => round((float) ($it['qty'] ?? 0), 3),
                'qty_delivered' => round((float) ($it['delivered_qty'] ?? $it['qty'] ?? 0), 3),
                'empties_back'  => round((float) ($it['returned_empty_qty'] ?? 0), 3),
                'unit_price'    => round((float) ($it['unit_price'] ?? 0), 2),
            ];
        }, array_values($doc['items'] ?? []));

        $t = $doc['totals'] ?? [];
        $c = $doc['client'] ?? [];

        return [
            'v'                => 1,
            'type'             => 'bl',
            'reference'        => (string) ($doc['reference'] ?? ''),
            'date'             => (string) ($doc['date'] ?? ''),
            'client'           => [
                'name' => (string) ($c['name'] ?? ''),
                'niu'  => (string) ($c['niu'] ?? ''),
            ],
            'items'            => $items,
            'grand_total'      => round((float) ($t['grand_total'] ?? 0), 2),
            'payment_collected'=> round((float) ($doc['payment_collected'] ?? 0), 2),
            'observations'     => trim((string) ($doc['observations'] ?? '')),
        ];
    }

    // --------------------------------------------------------------------------
    // facture — invoice. Same fiscal ladder shape as quote/invoice
    // rendering. Includes precompte + AIR because they change the net
    // payable, which is what a signature on a facture actually vouches for.
    // --------------------------------------------------------------------------
    private static function canonicalPayload_facture(array $doc): array
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
            'type'        => 'facture',
            'reference'   => (string) ($doc['reference'] ?? ''),
            'date'        => (string) ($doc['date'] ?? ''),
            'due_date'    => (string) ($doc['due_date'] ?? ''),
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
            'precompte'   => round((float) ($t['precompte'] ?? 0), 2),
            'air'         => round((float) ($t['air'] ?? 0), 2),
            'grand_total' => round((float) ($t['grand_total'] ?? 0), 2),
            'net_payable' => round((float) ($t['net_payable'] ?? $t['grand_total'] ?? 0), 2),
        ];
    }

    // --------------------------------------------------------------------------
    // bon_commande — purchase order to a supplier. What signing attests to:
    // who the supplier is, what LPC is committing to buy, and at what
    // agreed price.
    // --------------------------------------------------------------------------
    private static function canonicalPayload_bon_commande(array $doc): array
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
            'type'        => 'bon_commande',
            'reference'   => (string) ($doc['reference'] ?? ''),
            'date'        => (string) ($doc['date'] ?? ''),
            'supplier'    => [
                'name'    => (string) ($c['name'] ?? ''),
                'address' => (string) ($c['address'] ?? ''),
            ],
            'items'       => $items,
            'grand_total' => round((float) ($t['grand_total'] ?? 0), 2),
        ];
    }

    // --------------------------------------------------------------------------
    // payslip — a bulletin de paie. Signing attests to: employee, period,
    // gross, net, and social contributions. Line breakdown is included so
    // "gross - deductions = net" can be recomputed from the hashed payload.
    //
    // lpc_normalize_payslip() keeps the raw row under $doc['raw'] rather
    // than lifting each column to the top level (see the function in
    // includes/functions/document_pdf.php). We read gross/net from there
    // rather than a made-up 'totals' sub-array, so the hash is stable
    // regardless of any later reshuffling of the normalizer's output.
    // --------------------------------------------------------------------------
    private static function canonicalPayload_payslip(array $doc): array
    {
        $emp = $doc['employee'] ?? [];
        $raw = $doc['raw'] ?? [];

        $lines = array_map(static function ($l) {
            return [
                'label'  => (string) ($l['label'] ?? ''),
                'amount' => round((float) ($l['amount'] ?? 0), 2),
                'type'   => (string) ($l['type'] ?? 'earning'),
            ];
        }, array_values($doc['breakdown'] ?? []));

        return [
            'v'         => 1,
            'type'      => 'payslip',
            'reference' => (string) ($doc['reference'] ?? ''),
            'date'      => (string) ($doc['date'] ?? ''),
            'employee'  => [
                'name' => (string) ($emp['name'] ?? ''),
                'cnps' => (string) ($emp['cnps'] ?? ''),
            ],
            'lines'     => $lines,
            'gross'     => round((float) ($raw['gross_salary'] ?? 0), 2),
            'net'       => round((float) ($raw['net_pay']      ?? 0), 2),
        ];
    }

    // --------------------------------------------------------------------------
    // contract — placeholder. No contracts module exists yet, but the whole
    // point of this design is that when it lands it can call signInternal /
    // signExternal without touching this class. This canonicalPayload has
    // to be redefined in the same PR that ships the contracts module.
    // --------------------------------------------------------------------------
    private static function canonicalPayload_contract(array $doc): array
    {
        $c = $doc['client'] ?? $doc['counterparty'] ?? [];
        return [
            'v'         => 1,
            'type'      => 'contract',
            'reference' => (string) ($doc['reference'] ?? ''),
            'date'      => (string) ($doc['date'] ?? ''),
            'title'     => (string) ($doc['title'] ?? ''),
            'counterparty' => [
                'name' => (string) ($c['name'] ?? ''),
                'niu'  => (string) ($c['niu'] ?? ''),
            ],
            'body_hash' => hash('sha256', (string) ($doc['body'] ?? '')),
        ];
    }

    // ==========================================================================
    // Signing — one function per party.
    // ==========================================================================

    /**
     * Record a signature by an LPC staff member, session-authenticated.
     * Identity comes from UserProfile, NEVER from $doc or from a POST field.
     * A caller cannot sign as "Le Directeur Général" by typing it into a
     * form.
     *
     * @throws RuntimeException on invalid input, incomplete profile, or DB failure
     */
    public static function signInternal(string $type, int $docId, array $doc, int $userId): array
    {
        if (!self::partyAllowed($type, 'internal')) {
            throw new RuntimeException("Signature interne non autorisée pour le type '{$type}'.");
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

        return self::insert([
            'document_type'   => $type,
            'document_id'     => $docId,
            'party'           => 'internal',
            'signer_user_id'  => $userId,
            'signer_name'     => $name,
            'signer_role'     => $role,
            'content_hash'    => self::hash($type, $doc),
        ]);
    }

    /**
     * Backwards-compat alias. Old callers of DocumentSignature::sign() were
     * always internal — see migration 048 — so this keeps them working
     * without any signature-count regression.
     */
    public static function sign(string $type, int $docId, array $doc, int $userId): array
    {
        return self::signInternal($type, $docId, $doc, $userId);
    }

    /**
     * Record a signature by the counterparty via a token link. Identity
     * comes from the phone form ($identity), the image is the raw Base64
     * data URL the pad produced.
     *
     * The token that got the counterparty to the signing page is NOT the
     * verify_token — this method mints a fresh 40-hex verify_token whose
     * only key-lookup path is public/verify.php.
     *
     * @param array{name:string,role:string,phone:string} $identity
     * @throws RuntimeException on invalid input, oversized image, or DB failure
     */
    public static function signExternal(
        string $type,
        int $docId,
        array $doc,
        array $identity,
        string $imageDataUrl
    ): array {
        if (!self::partyAllowed($type, 'external')) {
            throw new RuntimeException("Signature externe non autorisée pour le type '{$type}'.");
        }
        if ($docId <= 0) {
            throw new RuntimeException('Contexte de signature manquant.');
        }

        $name  = trim((string) ($identity['name']  ?? ''));
        $role  = trim((string) ($identity['role']  ?? ''));
        $phone = trim((string) ($identity['phone'] ?? ''));
        if ($name === '' || $role === '' || $phone === '') {
            throw new RuntimeException("Nom, fonction et téléphone du signataire sont requis.");
        }
        if (strlen($name)  > 160) $name  = substr($name, 0, 160);
        if (strlen($role)  > 160) $role  = substr($role, 0, 160);
        if (strlen($phone) > 30)  $phone = substr($phone, 0, 30);

        // Validate the pad image. It must be a PNG data URL under a
        // conservative size cap — the pad's own downscaler in
        // assets/js/lpc-image-compress.js keeps the average tap well under
        // this, so anything larger is almost certainly an attack.
        $image = trim($imageDataUrl);
        if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=\r\n]+$#', $image)) {
            throw new RuntimeException("Signature invalide : format PNG attendu.");
        }
        // 512KB after base64 decoding is generous for a 600x300 pad PNG.
        $rawLen = (int) (strlen($image) * 3 / 4);
        if ($rawLen > 512 * 1024) {
            throw new RuntimeException("Signature trop volumineuse.");
        }

        return self::insert([
            'document_type'       => $type,
            'document_id'         => $docId,
            'party'               => 'external',
            'signer_user_id'      => null,
            'signer_name'         => null,
            'signer_role'         => null,
            'content_hash'        => self::hash($type, $doc),
            'signature_image_b64' => $image,
            'signatory_name'      => $name,
            'signatory_role'      => $role,
            'signatory_phone'     => $phone,
        ]);
    }

    /**
     * Shared INSERT for both parties. Keeps the SQL in one place so a
     * future column addition only touches this method.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function insert(array $row): array
    {
        // 40 hex chars — same shape as the token/document_id.token columns
        // elsewhere in the app, but a DIFFERENT value: this one identifies a
        // signature EVENT, not the document itself, and a document may
        // accumulate several over its life (signed, edited, re-signed).
        $verifyToken = bin2hex(random_bytes(20));

        $row += [
            'signature_image_b64' => null,
            'signatory_name'      => null,
            'signatory_role'      => null,
            'signatory_phone'     => null,
        ];

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare('
                INSERT INTO document_signatures (
                    document_type, document_id, party,
                    signer_user_id, signer_name, signer_role,
                    content_hash, signature_image_b64,
                    signatory_name, signatory_role, signatory_phone,
                    verify_token, ip, user_agent
                ) VALUES (?,?,?, ?,?,?, ?,?, ?,?,?, ?,?,?)
            ');
            $stmt->execute([
                $row['document_type'],
                $row['document_id'],
                $row['party'],
                $row['signer_user_id'],
                $row['signer_name'],
                $row['signer_role'],
                $row['content_hash'],
                $row['signature_image_b64'],
                $row['signatory_name'],
                $row['signatory_role'],
                $row['signatory_phone'],
                $verifyToken,
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            $id = (int) $db->lastInsertId();
        } catch (Throwable $e) {
            error_log('DocumentSignature::insert: ' . $e->getMessage());
            throw new RuntimeException('La signature n\'a pas pu être enregistrée. Réessayez.');
        }

        $stored = self::rowById($id);
        if (!$stored) {
            throw new RuntimeException('La signature a été enregistrée mais n\'a pas pu être relue.');
        }
        return $stored;
    }

    // ==========================================================================
    // Lookups — same three questions the internal UI and public verify page
    // need answered: "signature valid for the current doc?", "any signature
    // at all?", "signature from this specific party?".
    // ==========================================================================

    /**
     * The most recent non-revoked signature (either party) whose stored
     * hash still matches $doc as it stands. Returns null if the document
     * was never signed, every signature was revoked, or the document
     * changed since the last signing.
     *
     * @return array<string,mixed>|null
     */
    public static function getActive(string $type, int $docId, array $doc): ?array
    {
        if ($docId <= 0) return null;
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare('
                SELECT * FROM document_signatures
                 WHERE document_type = ? AND document_id = ?
                   AND content_hash = ? AND revoked_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1
            ');
            $stmt->execute([$type, $docId, self::hash($type, $doc)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('DocumentSignature::getActive: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Same as getActive() but scoped to one party. Used by the PDF
     * renderer to place the internal stamp and the external drawn image
     * side by side on the same document.
     *
     * @return array<string,mixed>|null
     */
    public static function getActiveByParty(string $type, int $docId, array $doc, string $party): ?array
    {
        if ($docId <= 0) return null;
        if (!in_array($party, self::PARTIES, true)) return null;
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare('
                SELECT * FROM document_signatures
                 WHERE document_type = ? AND document_id = ? AND party = ?
                   AND content_hash = ? AND revoked_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1
            ');
            $stmt->execute([$type, $docId, $party, self::hash($type, $doc)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('DocumentSignature::getActiveByParty: ' . $e->getMessage());
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
        if ($docId <= 0) return null;
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
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) return null;
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
        if ($signatureId <= 0) return false;
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
