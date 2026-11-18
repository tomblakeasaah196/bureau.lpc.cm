<?php
/**
 * includes/functions/books_period.php
 * -----------------------------------------------------------------------------
 * Two helpers the États Financiers, the TFT and the closing share
 * (Comptabilité 3, VIDEO_QA_FINDINGS 13.5).
 *
 *   lpc_books_start_year()  where a year's cumulative balances start counting.
 *       A closed year's « Clôture définitive » posts the À-nouveaux of the next
 *       year (reference AN-YYYY, dated 01/01): the balances of classes 1 to 5
 *       and the result on 131 / 139. From then on the history before that date
 *       is already inside the À-nouveaux, so a balance « at the end of N » is
 *       the À-nouveaux plus the entries of the years since. Summing every entry
 *       up to N, as the statements did, counted each balance twice once a
 *       year had been closed.
 *
 *   lpc_books_account()  the chart account for a SYSCOHADA number, created
 *       from its OHADA parent when the chart has none yet (a fresh chart has
 *       no 131, 139 or 89: the closing needs them once a year).
 * -----------------------------------------------------------------------------
 */

if (defined('LPC_BOOKS_PERIOD_LOADED')) { return; }
define('LPC_BOOKS_PERIOD_LOADED', true);

/**
 * The first year whose entries count in a balance at the end of $year: the
 * latest year up to $year that opens with posted À-nouveaux, or null when no
 * year was ever closed (every entry counts).
 */
function lpc_books_start_year(PDO $pdo, int $year): ?int
{
    static $cache = [];
    if (array_key_exists($year, $cache)) return $cache[$year];
    $st = $pdo->prepare(
        "SELECT MAX(YEAR(date)) FROM journal_entries
          WHERE status = 'posted' AND reference LIKE 'AN-%'
            AND reference = CONCAT('AN-', YEAR(date)) AND DATE_FORMAT(date, '%m-%d') = '01-01'
            AND YEAR(date) <= ?"
    );
    $st->execute([$year]);
    $v = $st->fetchColumn();
    return $cache[$year] = ($v !== null && $v !== false) ? (int) $v : null;
}

/**
 * SQL condition (on the alias $alias for journal_entries) and its parameters
 * for « counts in the balance at the end of $year ».
 * @return array{0:string,1:array}
 */
function lpc_books_cumulative_where(PDO $pdo, int $year, string $alias = 'je'): array
{
    $from = lpc_books_start_year($pdo, $year);
    if ($from === null) return ["YEAR({$alias}.date) <= ?", [$year]];
    return ["YEAR({$alias}.date) BETWEEN ? AND ?", [$from, $year]];
}

/**
 * chart_of_accounts.id for a SYSCOHADA account number: the chart row whose code
 * is that number, else one created from the OHADA row of that number. Returns
 * null when neither exists.
 */
function lpc_books_account(PDO $pdo, string $number): ?int
{
    $st = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE code = ? ORDER BY is_active DESC, id ASC LIMIT 1");
    $st->execute([$number]);
    $id = $st->fetchColumn();
    if ($id) return (int) $id;

    $st = $pdo->prepare("SELECT id, name, type FROM ohada_accounts WHERE account_number = ? LIMIT 1");
    $st->execute([$number]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) return null;
    $type = in_array($o['type'], ['asset', 'liability', 'equity', 'revenue', 'expense'], true) ? $o['type'] : 'liability';
    $pdo->prepare("INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active) VALUES (?, ?, ?, ?, 1)")
        ->execute([(int) $o['id'], $number, $o['name'], $type]);
    return (int) $pdo->lastInsertId();
}

/** The first of several account numbers that exists or can be created. */
function lpc_books_first_account(PDO $pdo, array $numbers): ?int
{
    foreach ($numbers as $n) {
        $id = lpc_books_account($pdo, (string) $n);
        if ($id) return $id;
    }
    return null;
}
