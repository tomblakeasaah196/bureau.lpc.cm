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
    $rows = $pdo->query("
        SELECT c.id, c.code, c.name, c.type, c.is_active,
               o.account_number AS ohada_number,
               o.name           AS ohada_name,
               t.id             AS treasury_id,
               t.name           AS treasury_name,
               t.balance        AS treasury_balance
          FROM chart_of_accounts c
          JOIN ohada_accounts    o ON o.id = c.ohada_account_id
          LEFT JOIN treasury_accounts t ON t.coa_account_id = c.id
         WHERE c.is_active = 1
         ORDER BY c.code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $accounts = array_map(function ($r) use ($existing_amounts) {
        $id = (int) $r['id'];
        $ex = $existing_amounts[$id] ?? ['debit' => 0.0, 'credit' => 0.0];
        return [
            'id'               => $id,
            'code'             => $r['code'],
            'name'             => $r['name'],
            'type'             => $r['type'],
            'class'            => substr((string)$r['code'], 0, 1),
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
    $clean = [];
    foreach ($lines as $l) {
        $account_id = (int) ($l['account_id'] ?? 0);
        $debit  = (float) ($l['debit']  ?? 0);
        $credit = (float) ($l['credit'] ?? 0);
        if ($account_id <= 0)          continue;
        if ($debit <= 0 && $credit <= 0) continue;
        if ($debit > 0 && $credit > 0) jr('error', "Une ligne ne peut être à la fois débit et crédit (compte id $account_id).");
        $clean[] = ['account_id' => $account_id, 'debit' => round($debit, 2), 'credit' => round($credit, 2)];
    }
    if (!$clean) jr('error', 'Aucune ligne non nulle.');

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

        // 3. Delete the previous opening JE (lines cascade via FK).
        if ($existing_id) {
            $pdo->prepare("DELETE FROM journal_lines WHERE journal_entry_id = ?")->execute([$existing_id]);
            $pdo->prepare("DELETE FROM journal_entries WHERE id = ?")->execute([$existing_id]);
        }

        // 4. Ensure the fiscal year row exists (unlocked).
        $pdo->prepare("
            INSERT IGNORE INTO financial_years (year, status) VALUES (?, 'open')
        ")->execute([$year]);

        // 5. Insert the new opening JE.
        $ref  = opening_ref($year);
        $date = sprintf('%04d-01-01', $year);
        $desc = "Bilan d'ouverture exercice $year — saisi manuellement";

        // journal_entries can be either 'draft'/'posted' or 'draft'/'approved'
        // depending on when the migration set landed. Try 'posted' first and
        // fall back — either terminal state means the ledger consumes it.
        $insHeader = $pdo->prepare("
            INSERT INTO journal_entries
                (reference, journal_code, date, description, status, created_by, approved_by)
            VALUES (?, 'OD', ?, ?, 'posted', ?, ?)
        ");
        try {
            $insHeader->execute([$ref, $date, $desc, $user_id, $user_id]);
        } catch (PDOException $e) {
            // Retry with 'approved' if the enum on this DB rejects 'posted'.
            $insHeader = $pdo->prepare("
                INSERT INTO journal_entries
                    (reference, journal_code, date, description, status, created_by, approved_by)
                VALUES (?, 'OD', ?, ?, 'approved', ?, ?)
            ");
            $insHeader->execute([$ref, $date, $desc, $user_id, $user_id]);
        }
        $je_id = (int) $pdo->lastInsertId();

        $insLine = $pdo->prepare("
            INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($clean as $l) {
            $insLine->execute([$je_id, $l['account_id'], $l['debit'], $l['credit']]);
        }

        // 6. Pin it on financial_years.
        $pdo->prepare("
            UPDATE financial_years
               SET opening_balance_journal_id = ?
             WHERE year = ?
        ")->execute([$je_id, $year]);

        // 7. Mirror to treasury_accounts.balance so the cashflow screen
        //    matches. For a treasury CoA row the "opening balance" IS the
        //    starting cash: whichever side (debit for asset accounts, credit
        //    for liability) becomes the signed balance.
        //
        //    Asset side (521/571/etc) → balance = debit − credit.
        //    We look up which of the entered rows are linked to a treasury
        //    account and write that.
        $treasuryMap = $pdo->prepare("
            SELECT t.id AS treasury_id, c.id AS coa_id
              FROM treasury_accounts t
              JOIN chart_of_accounts c ON c.id = t.coa_account_id
        ");
        $treasuryMap->execute();
        $coa_to_treasury = [];
        foreach ($treasuryMap->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $coa_to_treasury[(int)$r['coa_id']] = (int)$r['treasury_id'];
        }
        $updTreasury = $pdo->prepare("UPDATE treasury_accounts SET balance = ? WHERE id = ?");
        foreach ($clean as $l) {
            if (isset($coa_to_treasury[$l['account_id']])) {
                $balance = $l['debit'] - $l['credit']; // asset convention
                $updTreasury->execute([$balance, $coa_to_treasury[$l['account_id']]]);
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
