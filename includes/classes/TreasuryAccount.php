<?php
/**
 * includes/classes/TreasuryAccount.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 9 · collection accounts and their ledgers.
 *
 * One row in `treasury_accounts` is one real place money sits: Afriland, the
 * microfinance account, the MTN MoMo merchant line, the till. This class is the
 * only thing that creates them, because creating one is never just an INSERT —
 * it has to provision the account's own chart_of_accounts sub-account too, or
 * the balance it tracks has no ledger to reconcile against.
 *
 * WHY THE SUB-ACCOUNT MATTERS
 *   Before this, every bank shared the single COA row mapped to OHADA 521. Two
 *   banks meant one pot: you could see a combined 521 balance but could not tie
 *   any journal line back to a statement, so neither account could be
 *   reconciled. Each account now gets 5211, 5212, 5213… under 521, and
 *   JournalPoster::coaFromTreasury() already prefers `coa_account_id` when it
 *   is set, so nothing else had to change for per-bank ledgers to work.
 *
 * OHADA PARENTS (SYSCOHADA révisé)
 *   bank   → 521 Banques
 *   momo   → 552 Monnaie électronique — téléphone portable
 *   wallet → 554 Porte-monnaie électronique
 *   caisse → 571 Caisse
 *
 *   MoMo is NOT 571. Class 55 exists so electronic float stops being counted as
 *   physical cash; see the note on JournalPoster::TREASURY_OHADA.
 *
 * FEES
 *   Not handled here, deliberately. The operator's commission is booked by hand
 *   as a treasury expense against 6317 when the payment is made — the fee
 *   schedules move, and a stale table in the code would be wrong more often
 *   than a person is. Migration 044 makes 6317 exist and be selectable.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class TreasuryAccount
{
    /** treasury_accounts.type → OHADA parent. Mirrors JournalPoster::TREASURY_OHADA. */
    public const OHADA_PARENT = [
        'bank'   => '521',
        'momo'   => '552',
        'wallet' => '554',
        'caisse' => '571',
    ];

    public const TYPE_LABELS = [
        'bank'   => 'Compte bancaire',
        'momo'   => 'Mobile Money',
        'wallet' => 'Porte-monnaie électronique',
        'caisse' => 'Caisse',
    ];

    /** Fields the settings UI is allowed to write. Anything else in POST is ignored. */
    public const EDITABLE = [
        'name', 'type', 'account_number', 'bank_name', 'iban', 'swift',
        'holder_name', 'show_on_invoice', 'sort_order', 'status',
    ];

    /**
     * Create an account and its ledger sub-account, atomically.
     *
     * @param array $data  keys from self::EDITABLE
     * @return int         treasury_accounts.id
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance()->getConnection();
        $clean = self::validate($data);

        $owns_tx = !$db->inTransaction();
        if ($owns_tx) $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                INSERT INTO treasury_accounts
                    (name, type, account_number, bank_name, iban, swift, holder_name,
                     show_on_invoice, sort_order, status, balance, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, NOW())
            ");
            $stmt->execute([
                $clean['name'], $clean['type'], $clean['account_number'],
                $clean['bank_name'], $clean['iban'], $clean['swift'], $clean['holder_name'],
                $clean['show_on_invoice'], $clean['sort_order'], $clean['status'],
                (int) ($_SESSION['user_id'] ?? 0),
            ]);
            $id = (int) $db->lastInsertId();

            // The ledger is provisioned in the same transaction. An account
            // without one would silently fall back to the shared parent and
            // re-create exactly the single-pot problem this replaces.
            $coa_id = self::provisionLedgerAccount($db, $id, $clean['name'], $clean['type']);
            $db->prepare("UPDATE treasury_accounts SET coa_account_id = ? WHERE id = ?")
               ->execute([$coa_id, $id]);

            if ($owns_tx) $db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($owns_tx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Update the display fields of an existing account.
     *
     * `type` is intentionally NOT updatable: it determines the OHADA parent,
     * and moving an account between classes after it has posted movements is a
     * reclassification for an accountant to decide, not a form field. Close the
     * account and open a new one.
     */
    public static function update(int $id, array $data): void
    {
        $db = Database::getInstance()->getConnection();

        $current = self::find($id);
        if (!$current) {
            throw new InvalidArgumentException("Compte de trésorerie #{$id} introuvable.");
        }
        $data['type'] = $current['type'];
        $clean = self::validate($data);

        $db->prepare("
            UPDATE treasury_accounts
               SET name = ?, account_number = ?, bank_name = ?, iban = ?, swift = ?,
                   holder_name = ?, show_on_invoice = ?, sort_order = ?, status = ?
             WHERE id = ?
        ")->execute([
            $clean['name'], $clean['account_number'], $clean['bank_name'], $clean['iban'],
            $clean['swift'], $clean['holder_name'], $clean['show_on_invoice'],
            $clean['sort_order'], $clean['status'], $id,
        ]);

        // Keep the ledger label readable, but never touch its code — the code
        // is referenced by posted journal entries.
        if (!empty($current['coa_account_id']) && $clean['name'] !== $current['name']) {
            $db->prepare("UPDATE chart_of_accounts SET name = ? WHERE id = ?")
               ->execute([self::ledgerLabel($clean['name'], $current['type']), $current['coa_account_id']]);
        }

        // An account that already exists but predates migration 044 has no
        // ledger of its own. Give it one on first edit.
        if (empty($current['coa_account_id'])) {
            $coa_id = self::provisionLedgerAccount($db, $id, $clean['name'], $current['type']);
            $db->prepare("UPDATE treasury_accounts SET coa_account_id = ? WHERE id = ?")
               ->execute([$coa_id, $id]);
        }
    }

    /**
     * Accounts are never deleted — journal entries and invoices reference them.
     * Closing hides the account from new invoices and from the payment picker
     * while leaving its history intact.
     */
    public static function close(int $id): void
    {
        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE treasury_accounts SET status = 'inactive', show_on_invoice = 0 WHERE id = ?")
           ->execute([$id]);
    }

    public static function find(int $id): ?array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM treasury_accounts WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * All accounts, for the settings list.
     * @return array<int,array<string,mixed>>
     */
    public static function all(bool $active_only = false): array
    {
        $db = Database::getInstance()->getConnection();
        $where = $active_only ? "WHERE t.status = 'active'" : '';
        return $db->query("
            SELECT t.*, c.code AS coa_code, c.name AS coa_name
              FROM treasury_accounts t
              LEFT JOIN chart_of_accounts c ON c.id = t.coa_account_id
              {$where}
             ORDER BY t.sort_order ASC, t.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The accounts offerable on an invoice, in display order.
     * The first one is what a new invoice pre-ticks.
     */
    public static function invoiceable(): array
    {
        $db = Database::getInstance()->getConnection();
        return $db->query("
            SELECT * FROM treasury_accounts
             WHERE status = 'active' AND show_on_invoice = 1
             ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Render one account as the label/value pairs an invoice prints.
     * Shape matches CompanyProfile::bankDetails() so both renderers can loop it
     * unchanged.
     *
     * @return array{title:string, lines:array<string,string>}
     */
    public static function printBlock(array $account): array
    {
        $lines = [];
        $type  = (string) ($account['type'] ?? 'bank');

        if ($type === 'momo') {
            if (!empty($account['account_number'])) $lines['Numéro']    = $account['account_number'];
            if (!empty($account['holder_name']))    $lines['Au nom de'] = $account['holder_name'];
        } else {
            if (!empty($account['bank_name']))      $lines['Banque']    = $account['bank_name'];
            if (!empty($account['account_number'])) $lines['RIB']       = $account['account_number'];
            if (!empty($account['iban']))           $lines['IBAN']      = $account['iban'];
            if (!empty($account['swift']))          $lines['SWIFT']     = $account['swift'];
            if (!empty($account['holder_name']))    $lines['Titulaire'] = $account['holder_name'];
        }

        return [
            'title' => (string) ($account['name'] ?? self::TYPE_LABELS[$type] ?? 'Compte'),
            'lines' => $lines,
        ];
    }

    // -------------------------------------------------------------------------
    // internals
    // -------------------------------------------------------------------------

    /**
     * Create the account's chart_of_accounts row under the right OHADA parent
     * and return its id.
     *
     * Codes are the parent plus a sequence: 5211, 5212, 5213 under 521;
     * 5521, 5522 under 552. The sequence is derived from what already exists
     * so it survives deletions and re-runs, and chart_of_accounts.code is
     * UNIQUE, so a collision fails loudly inside the transaction rather than
     * producing two accounts that look alike.
     */
    private static function provisionLedgerAccount(PDO $db, int $treasury_id, string $name, string $type): int
    {
        $parent = self::OHADA_PARENT[$type] ?? null;
        if ($parent === null) {
            throw new InvalidArgumentException("Type de compte inconnu : '{$type}'.");
        }

        $stmt = $db->prepare("SELECT id, type FROM ohada_accounts WHERE account_number = ? LIMIT 1");
        $stmt->execute([$parent]);
        $ohada = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ohada) {
            throw new RuntimeException(
                "OHADA {$parent} absent du plan comptable. Exécutez la migration 044 "
                . "(elle ajoute 552 et 6317, que la migration 003 n'avait jamais créés)."
            );
        }

        // Next free suffix under this parent.
        $stmt = $db->prepare("
            SELECT code FROM chart_of_accounts
             WHERE code REGEXP CONCAT('^', ?, '[0-9]+$')
             ORDER BY CAST(SUBSTRING(code, ?) AS UNSIGNED) DESC
             LIMIT 1
        ");
        $stmt->execute([$parent, strlen($parent) + 1]);
        $last = $stmt->fetchColumn();
        $next = $last ? ((int) substr((string) $last, strlen($parent))) + 1 : 1;
        $code = $parent . $next;

        $label = self::ledgerLabel($name, $type);
        $db->prepare("
            INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
            VALUES (?, ?, ?, ?, 1)
        ")->execute([(int) $ohada['id'], $code, $label, $ohada['type'] === 'expense' ? 'expense' : 'asset']);

        return (int) $db->lastInsertId();
    }

    /** "Afriland First Bank" under 521 → "Banque — Afriland First Bank". */
    private static function ledgerLabel(string $name, string $type): string
    {
        $prefix = [
            'bank'   => 'Banque',
            'momo'   => 'Monnaie électronique',
            'wallet' => 'Porte-monnaie électronique',
            'caisse' => 'Caisse',
        ][$type] ?? 'Trésorerie';

        return mb_substr("{$prefix} — {$name}", 0, 100);
    }

    /** @return array<string,mixed> */
    private static function validate(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException("Le nom du compte est obligatoire.");
        }

        $type = (string) ($data['type'] ?? 'bank');
        if (!isset(self::OHADA_PARENT[$type])) {
            throw new InvalidArgumentException("Type de compte invalide : '{$type}'.");
        }

        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

        $number = trim((string) ($data['account_number'] ?? ''));
        if ($type === 'momo' && $number !== '' && !preg_match('/^[0-9 +]{6,20}$/', $number)) {
            throw new InvalidArgumentException("Numéro Mobile Money invalide : '{$number}'.");
        }

        $nullable = static function ($v) {
            $v = trim((string) $v);
            return $v === '' ? null : $v;
        };

        return [
            'name'            => mb_substr($name, 0, 100),
            'type'            => $type,
            'account_number'  => $nullable($number),
            'bank_name'       => $nullable($data['bank_name']   ?? ''),
            'iban'            => $nullable(str_replace(' ', '', (string) ($data['iban'] ?? '')) === ''
                                    ? '' : (string) $data['iban']),
            'swift'           => $nullable(strtoupper((string) ($data['swift'] ?? ''))),
            'holder_name'     => $nullable($data['holder_name'] ?? ''),
            'show_on_invoice' => !empty($data['show_on_invoice']) ? 1 : 0,
            'sort_order'      => (int) ($data['sort_order'] ?? 0),
            'status'          => $status,
        ];
    }
}
