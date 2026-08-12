<?php
/**
 * includes/functions/employee_code.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — allocation of the employee matricule.
 *
 * WHY THIS IS A SHARED FUNCTION
 * -----------------------------
 * Two surfaces onboard employees — Paramètres → Utilisateurs
 * (settings_controller.php) and Master Data → Employés (mdm_controller.php) —
 * and before Sprint 14 they disagreed about what a matricule even looks like:
 *
 *   · settings_controller.php took whatever the admin typed, including "".
 *   · mdm_controller.php computed a correct EMP-### and then overwrote it one
 *     line later with 'LPC-' . time():
 *
 *         $emp_code = 'EMP-' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
 *         $stmt = $db->prepare("INSERT INTO users (...) ...");
 *         $emp_code = 'LPC-' . time();          // <- the bug
 *         $stmt->execute([..., $emp_code, ...]);
 *
 *     Every employee created that way carries a LPC-<unix timestamp>
 *     matricule. Those rows are left alone by migration 105 — the numbers are
 *     already on payslips — but nothing new should be minted that way.
 *
 * Now the code is assigned in exactly one place, is never accepted from a
 * request, and is rendered read-only in both UIs.
 *
 * WHY MAX() AND NOT "THE LAST ROW"
 * --------------------------------
 * The original query was:
 *
 *     SELECT employee_code FROM users WHERE employee_code LIKE 'EMP-%'
 *     ORDER BY id DESC LIMIT 1
 *
 * which returns the most recently INSERTED code, not the highest one. Delete
 * the newest employee and the next hire is handed a matricule that already
 * belongs to somebody — now a hard failure, because migration 105 put a UNIQUE
 * index on the column. Taking the numeric maximum is correct regardless of
 * insertion order, and the retry loop below closes the race between two admins
 * clicking Save at the same instant.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('lpc_next_employee_code')) {
    /**
     * Next free matricule in the EMP-### series.
     *
     * Zero-padded to three digits, and simply longer past 999 (EMP-1000) —
     * padding is cosmetic, uniqueness is not.
     *
     * Only rows matching ^EMP-[0-9]+$ take part in the numbering. The legacy
     * LPC-<timestamp> and hand-typed codes are deliberately ignored: they are
     * not part of the series, and folding a 10-digit timestamp into MAX()
     * would jump the counter to EMP-1754900002.
     *
     * @param PDO $db
     * @param int $attempt Internal — recursion depth for the collision retry.
     */
    function lpc_next_employee_code(PDO $db, int $attempt = 0): string
    {
        $max = (int) $db->query("
            SELECT COALESCE(MAX(CAST(SUBSTRING(employee_code, 5) AS UNSIGNED)), 0)
              FROM users
             WHERE employee_code REGEXP '^EMP-[0-9]+$'
        ")->fetchColumn();

        $candidate = 'EMP-' . str_pad((string) ($max + 1 + $attempt), 3, '0', STR_PAD_LEFT);

        // Belt and braces against a concurrent insert between the MAX() above
        // and the INSERT the caller is about to run. Ten attempts is far more
        // than a company of this size will ever need; past that, something is
        // wrong that a bigger loop would only hide.
        if ($attempt < 10) {
            $chk = $db->prepare("SELECT 1 FROM users WHERE employee_code = ? LIMIT 1");
            $chk->execute([$candidate]);
            if ($chk->fetchColumn()) {
                return lpc_next_employee_code($db, $attempt + 1);
            }
        }

        return $candidate;
    }
}
