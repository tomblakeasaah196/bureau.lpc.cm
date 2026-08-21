<?php
/**
 * api/v1/opening_balance_controller.php
 * -----------------------------------------------------------------------------
 * Bilan d'Ouverture — first-year opening-balance entry.
 *
 * WHAT IT IS FOR
 *   A brand-new tenant (e.g. La Petite Cour after handover) has no year to
 *   close from, so the standard A-Nouveaux mechanism in financials_controller
 *   never fires. This controller lets someone with `accounting.opening_balance
 *   .enter` seed the very first fiscal year by posting one balanced OD
 *   journal entry dated Jan 1, pinned to
 *   financial_years.opening_balance_journal_id.
 *
 * FLOW
 *   GET  ?action=load&year=YYYY
 *     Returns every active chart_of_accounts row plus its current opening
 *     amount (0 unless a prior AN- exists for that year), plus the treasury
 *     accounts with their stored balance for context. Also returns a lock
 *     flag: once the year has ANY posted journal entry other than the
 *     opening OD itself, the opening balance is frozen.
 *
 *   POST action=save
 *     Body: { year, lines: [{account_id, debit, credit}, ...] }
 *     Validates Σdebit == Σcredit, deletes the previous opening OD (if the
 *     year is still editable), inserts a fresh balanced JE dated Jan 1,
 *     posts it, updates financial_years.opening_balance_journal_id.
 *     Treasury CoA rows also update the mirror treasury_accounts.balance
 *     so the cashflow screen matches.
 *
 * NON-GOALS
 *   Per-tier subledger (each client's opening AR, each supplier's opening
 *   AP) — the app tracks those via invoices, not per-tier CoA rows. The UI
 *   surfaces a clear note pointing operators at the invoice screen for
 *   that seeding.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('accounting.chart.view');

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'Non authentifié.']);
    exit;
}

try {
    require_once __DIR__ . '/../../includes/classes/Database.php';
    $pdo = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur.']);
    exit;
}

function jr(string $status, string $message, array $data = []): void
{
    $r = ['status' => $status, 'message' => $message];
    if ($data) $r['data'] = $data;
    echo json_encode($r);
    exit;
}

/**
 * Reference an opening JE gets. Deterministic per year so a re-save is a
 * clean delete-and-insert against the same handle.
 */
function opening_ref(int $year): string { return "OUV-{$year}"; }

/**
 * Is the year still editable?
 *
 * Rule: the opening balance is editable ONLY while the opening OD is the
 * only journal entry for that year (posted or draft). The moment any other
 * entry lands, opening balances freeze — matches SYSCOHADA's "opening is
 * definitive once the year has moved". If no opening OD exists yet, the
 * year is always editable.
 */
function opening_year_editable(PDO $pdo, int $year, ?int $opening_je_id): array
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM journal_entries
         WHERE YEAR(date) = ?
           AND (? IS NULL OR id <> ?)
    ");
    $stmt->execute([$year, $opening_je_id, $opening_je_id]);
    $other = (int) $stmt->fetchColumn();
    return [
        'editable' => $other === 0,
        'blocking_entries' => $other,
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($_POST['action'] ?? null);
if (!$action && $method === 'POST') {
    $raw = json_decode(file_get_contents('php://input'), true);
    if (is_array($raw)) $action = $raw['action'] ?? null;
}

// ----------------------------------------------------------------------------
// GET  ?action=load&year=YYYY
// ----------------------------------------------------------------------------
if ($method === 'GET' && $action === 'load') {
    $year = (int) ($_GET['year'] ?? date('Y'));
    if ($year < 2000 || $year > 2100) jr('error', 'Année invalide.');

    // Find any existing opening OD for this year.
    $stmt = $pdo->prepare("
        SELECT je.id, je.reference, je.date, je.status
          FROM journal_entries je
          JOIN financial_years fy ON fy.opening_balance_journal_id = je.id
         WHERE fy.year = ?
         LIMIT 1
    ");
    $stmt->execute([$year]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Fallback: match by reference (OUV-YYYY or AN-YYYY) if the FK is not set.
    if (!$existing) {
        $stmt = $pdo->prepare("
            SELECT id, reference, date, status
              FROM journal_entries
             WHERE reference IN (?, ?)
               AND YEAR(date) = ?
             ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([opening_ref($year), "AN-{$year}", $year]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $editability = opening_year_editable($pdo, $year, $existing['id'] ?? null);

    // Existing amounts, keyed by account_id.
    $existing_amounts = [];
    if ($existing) {
        $stmt = $pdo->prepare("
            SELECT account_id, SUM(debit) AS debit, SUM(credit) AS credit
              FROM journal_lines
             WHERE journal_entry_id = ?
             GROUP BY account_id
        ");
        $stmt->execute([$existing['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $existing_amounts[(int) $r['account_id']] = [
                'debit'  => (float) $r['debit'],
                'credit' => (float) $r['credit'],
            ];
        }
    }

    // Every active CoA row + its OHADA parent + treasury linkage.
    //
    // Inactive accounts are included ONLY when the saved opening entry
    // already carries an amount for them. Without that exception an account
    // deactivated after the opening was keyed would vanish from this screen
    // and be silently dropped from the entry on the next save — the totals
    // would then stop balancing for a reason nothing on screen explains.
    $keepInactive = array_keys($existing_amounts);
    $inactiveClause = '';
    $params = [];
    if ($keepInactive) {
        $ph = implode(',', array_fill(0, count($keepInactive), '?'));
        $inactiveClause = " OR c.id IN ($ph)";
        $params = $keepInactive;
    }

    $stmt = $pdo->prepare("
        SELECT c.id, c.code, c.name, c.type, c.is_active,
               o.account_number AS ohada_number,
               o.name           AS ohada_name,
               t.id             AS treasury_id,
               t.name           AS treasury_name,
               t.balance        AS treasury_balance
          FROM chart_of_accounts c
          JOIN ohada_accounts    o ON o.id = c.ohada_account_id
          LEFT JOIN treasury_accounts t ON t.coa_account_id = c.id
         WHERE (c.is_active = 1{$inactiveClause})
         ORDER BY c.code ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $accounts = array_map(function ($r) use ($existing_amounts) {
        $id = (int) $r['id'];
        $ex = $existing_amounts[$id] ?? ['debit' => 0.0, 'credit' => 0.0];
        return [
            'id'               => $id,
            'code'             => $r['code'],
            'name'             => $r['name'],
            'type'             => $r['type'],
            'is_active'        => (int) $r['is_active'] === 1,
            // SYSCOHADA class comes from the OHADA PARENT's number, not from
            // chart_of_accounts.code. Legacy auxiliary codes are LPC-prefixed
            // (LPC40111, LPC52111 ...), so taking the code's first character
            // grouped every one of them under a nonsense "CLASSE L" and made
            // them slip past the 6/7/8 P&L filter. The parent account_number is
            // the authoritative classification for any code shape.
            'class'            => substr((string)$r['ohada_number'], 0, 1),
            'ohada_number'     => $r['ohada_number'],
            'ohada_name'       => $r['ohada_name'],
            'treasury_id'      => $r['treasury_id'] ? (int) $r['treasury_id'] : null,
            'treasury_name'    => $r['treasury_name'],
            'treasury_balance' => $r['treasury_balance'] !== null ? (float) $r['treasury_balance'] : null,
            'debit'            => $ex['debit'],
            'credit'           => $ex['credit'],
        ];
    }, $rows);

    jr('success', 'Chargé.', [
        'year'         => $year,
        'existing'     => $existing,
        'editable'     => $editability['editable'],
        'blocking_entries' => $editability['blocking_entries'],
        'accounts'     => $accounts,
        'can_save'     => Rbac::hasPermission('accounting.opening_balance.enter'),
    ]);
}

// ----------------------------------------------------------------------------
// POST action=save
// ----------------------------------------------------------------------------
if ($method === 'POST' && $action === 'save') {
    Rbac::requirePermission('accounting.opening_balance.enter');

    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $year  = (int) ($raw['year'] ?? 0);
    $lines = is_array($raw['lines'] ?? null) ? $raw['lines'] : [];
    if ($year < 2000 || $year > 2100) jr('error', 'Année invalide.');
    if (!$lines) jr('error', 'Aucune ligne saisie.');

    // Filter zero-amount rows, normalise, validate.
    //
    // bi_journal_lines_check (migration 004) rejects a line that is negative,
    // zero on both sides, or positive on both sides. We enforce the same rules
    // here so the operator gets a readable message instead of a raw SQLSTATE
    // 45000 from the trigger.
    $clean = [];
    $seen  = [];
    foreach ($lines as $l) {
        $account_id = (int) ($l['account_id'] ?? 0);
        $debit  = (float) ($l['debit']  ?? 0);
        $credit = (float) ($l['credit'] ?? 0);
        if ($account_id <= 0)            continue;
        if ($debit < 0 || $credit < 0)   jr('error', "Montant négatif interdit (compte id $account_id).");
        if ($debit <= 0 && $credit <= 0) continue;
        if ($debit > 0 && $credit > 0)   jr('error', "Une ligne ne peut être à la fois débit et crédit (compte id $account_id).");
        // A repeated account would post two lines for the same account. The UI
        // cannot produce that, but a hand-built payload could, and the result
        // would be a ledger nobody can tie back to this screen.
        if (isset($seen[$account_id]))   jr('error', "Compte en double dans la saisie (id $account_id).");
        $seen[$account_id] = true;
        $clean[] = ['account_id' => $account_id, 'debit' => round($debit, 2), 'credit' => round($credit, 2)];
    }
    if (!$clean) jr('error', 'Aucune ligne non nulle.');

    // Every account must actually exist. journal_lines carries no FK on
    // account_id in migration 006, so an unknown id would insert an orphan
    // line that the grand livre could never resolve to an account name.
    $ph     = implode(',', array_fill(0, count($clean), '?'));
    $chkAcc = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE id IN ($ph)");
    $chkAcc->execute(array_column($clean, 'account_id'));
    $known  = array_map('intval', $chkAcc->fetchAll(PDO::FETCH_COLUMN));
    $unknown = array_diff(array_column($clean, 'account_id'), $known);
    if ($unknown) {
        jr('error', 'Compte inconnu dans la saisie : id ' . implode(', ', $unknown) . '.');
    }

    $sum_d = array_sum(array_column($clean, 'debit'));
    $sum_c = array_sum(array_column($clean, 'credit'));
    if (round($sum_d - $sum_c, 2) !== 0.0) {
        jr('error', sprintf(
            'Déséquilibré : Σ Débit %s ≠ Σ Crédit %s (écart %s).',
            number_format($sum_d, 0, ',', ' '),
            number_format($sum_c, 0, ',', ' '),
            number_format($sum_d - $sum_c, 0, ',', ' ')
        ));
    }

    try {
        $pdo->beginTransaction();

        // 1. Locate any existing opening entry for this year.
        $stmt = $pdo->prepare("
            SELECT je.id
              FROM journal_entries je
              JOIN financial_years fy ON fy.opening_balance_journal_id = je.id
             WHERE fy.year = ?
             FOR UPDATE
        ");
        $stmt->execute([$year]);
        $existing_id = $stmt->fetchColumn();
        if (!$existing_id) {
            $stmt = $pdo->prepare("
                SELECT id FROM journal_entries
                 WHERE reference IN (?, ?) AND YEAR(date) = ?
                 ORDER BY id DESC LIMIT 1 FOR UPDATE
            ");
            $stmt->execute([opening_ref($year), "AN-{$year}", $year]);
            $existing_id = $stmt->fetchColumn();
        }
        $existing_id = $existing_id ? (int) $existing_id : null;

        // 2. Freeze rule: any other JE for this year → refuse.
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM journal_entries
             WHERE YEAR(date) = ?
               AND (? IS NULL OR id <> ?)
        ");
        $stmt->execute([$year, $existing_id, $existing_id]);
        $others = (int) $stmt->fetchColumn();
        if ($others > 0) {
            $pdo->rollBack();
            jr('error', "L'exercice $year contient déjà $others écriture(s) autres que l'ouverture — le bilan d'ouverture est figé. Utilisez une écriture de correction (OD) pour ajuster.");
        }

        // 3. Ensure the fiscal year row exists BEFORE any journal write —
        //    bi_je_closed_period (migration 005) and bi_je_state_lock
        //    (migration 088) both read financial_years for YEAR(date), so the
        //    row has to be there and unlocked first.
        //
        //    financial_years.year carries a unique key (financials_controller
        //    upserts on it with ON DUPLICATE KEY UPDATE), but we check-then-
        //    insert rather than rely on INSERT IGNORE so a missing index on a
        //    given install can never silently produce a duplicate year row.
        $chkYear = $pdo->prepare("SELECT status FROM financial_years WHERE year = ? FOR UPDATE");
        $chkYear->execute([$year]);
        $yearStatus = $chkYear->fetchColumn();
        if ($yearStatus === false) {
            $pdo->prepare("INSERT INTO financial_years (year, status) VALUES (?, 'open')")
                ->execute([$year]);
        } elseif ($yearStatus === 'closed') {
            $pdo->rollBack();
            jr('error', "L'exercice $year est clôturé — le bilan d'ouverture ne peut plus être saisi.");
        }

        // 4. Remove the previous opening JE.
        //
        //    Two triggers from migration 004 constrain this and BOTH would
        //    reject a naive delete of a posted entry:
        //      · bd_journal_entries_no_post_delete — refuses DELETE while
        //        status = 'posted'.
        //      · bi_journal_lines_check — refuses INSERT of a line whose
        //        parent entry is already 'posted'.
        //
        //    So we demote the entry back to 'draft' first, then delete its
        //    lines, then the header. This is only ever reached when the
        //    freeze check above proved this entry is the ONLY journal entry
        //    for the year, so no posted history is being rewritten — the
        //    opening balance is still the only thing in the books.
        //
        //    journal_lines has no ON DELETE CASCADE in migration 006, so the
        //    lines are deleted explicitly. There is no BEFORE DELETE trigger
        //    on journal_lines, so this passes cleanly.
        if ($existing_id) {
            $pdo->prepare("UPDATE journal_entries SET status = 'draft' WHERE id = ?")
                ->execute([$existing_id]);
            $pdo->prepare("DELETE FROM journal_lines WHERE journal_entry_id = ?")
                ->execute([$existing_id]);
            $pdo->prepare("DELETE FROM journal_entries WHERE id = ?")
                ->execute([$existing_id]);
        }

        // 5. Insert the new opening JE.
        //
        //    Status vocabulary after migration 039 is strictly
        //    ENUM('draft','posted','reversed') — 'approved' is NOT a member
        //    and must never be written. And the entry MUST start as 'draft':
        //    bi_journal_lines_check refuses to attach lines to a posted
        //    entry, so posting can only happen after the lines exist.
        //
        //    The draft → posted transition goes through post_journal_entry
        //    (migration 004), which re-asserts SUM(debit) = SUM(credit)
        //    inside the database and stamps posted_at / posted_by. That is
        //    the same path accounting_controller uses, and it means the
        //    balance rule is enforced server-side even if this controller's
        //    own check were ever bypassed.
        $ref  = opening_ref($year);
        $date = sprintf('%04d-01-01', $year);
        $desc = "Bilan d'ouverture exercice $year — saisi manuellement";

        $insHeader = $pdo->prepare("
            INSERT INTO journal_entries
                (reference, journal_code, date, description, status, created_by)
            VALUES (?, 'OD', ?, ?, 'draft', ?)
        ");
        $insHeader->execute([$ref, $date, $desc, $user_id]);
        $je_id = (int) $pdo->lastInsertId();

        $insLine = $pdo->prepare("
            INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($clean as $l) {
            $insLine->execute([$je_id, $l['account_id'], $l['debit'], $l['credit']]);
        }

        // Post it. Raises SQLSTATE 45000 if the ledger does not balance.
        // closeCursor() because queries follow this CALL in the same
        // transaction — the proc only does SELECT ... INTO so it returns no
        // rowset today, but releasing the handle keeps that an implementation
        // detail of the proc rather than a dependency of this file.
        $post = $pdo->prepare("CALL post_journal_entry(?, ?)");
        $post->execute([$je_id, $user_id]);
        $post->closeCursor();

        // 6. Pin it on financial_years.
        $pdo->prepare("
            UPDATE financial_years
               SET opening_balance_journal_id = ?
             WHERE year = ?
        ")->execute([$je_id, $year]);

        // 7. Mirror to the treasury module.
        //
        //    treasury_accounts.balance is a stored running total that the
        //    cashflow screen reads directly, and every other treasury write
        //    pairs a balance change with a treasury_transactions row so the
        //    ledger explains the number. We do the same here instead of
        //    silently overwriting the balance: the prior opening marker is
        //    removed and re-inserted, so re-saving does not stack duplicate
        //    markers.
        //
        //    EVERY mapped treasury account is reconciled, not just the ones
        //    present in $clean — an account the operator cleared back to zero
        //    must have its stored balance cleared too, otherwise it would keep
        //    a stale figure that no longer appears anywhere in the books.
        //
        //    Sign convention: treasury accounts are asset accounts (class 5),
        //    so balance = debit − credit.
        $treasuryRows = $pdo->query("
            SELECT t.id AS treasury_id, t.coa_account_id AS coa_id
              FROM treasury_accounts t
             WHERE t.coa_account_id IS NOT NULL
        ")->fetchAll(PDO::FETCH_ASSOC);

        $entered = [];
        foreach ($clean as $l) {
            $entered[$l['account_id']] = $l['debit'] - $l['credit'];
        }

        $updTreasury = $pdo->prepare("UPDATE treasury_accounts SET balance = ? WHERE id = ?");
        $delMarker   = $pdo->prepare("
            DELETE FROM treasury_transactions
             WHERE account_id = ? AND description = ?
        ");
        $insMarker   = $pdo->prepare("
            INSERT INTO treasury_transactions
                (account_id, transaction_type, amount, description, logged_by)
            VALUES (?, 'in_other', ?, ?, ?)
        ");
        $markerLabel = "Solde Initial Ouverture $year";

        foreach ($treasuryRows as $tr) {
            $tid     = (int) $tr['treasury_id'];
            $balance = (float) ($entered[(int) $tr['coa_id']] ?? 0);

            $updTreasury->execute([$balance, $tid]);
            $delMarker->execute([$tid, $markerLabel]);
            if ($balance != 0.0) {
                $insMarker->execute([$tid, abs($balance), $markerLabel, $user_id]);
            }
        }

        $pdo->commit();
        jr('success', "Bilan d'ouverture enregistré ($ref).", [
            'journal_entry_id' => $je_id,
            'reference'        => $ref,
            'total_debit'      => $sum_d,
            'total_credit'     => $sum_c,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('opening_balance_controller: ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());
        jr('error', 'Erreur base de données : ' . $e->getMessage());
    }
}

jr('error', 'Action inconnue.');
