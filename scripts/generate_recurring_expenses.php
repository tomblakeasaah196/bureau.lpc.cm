<?php
/**
 * scripts/generate_recurring_expenses.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — recurring-expense draft generator.
 *
 * Run daily from cron. For every active row in expense_recurring_templates
 * whose day_of_month has arrived this month and which has not yet generated
 * for this month, insert a matching row into `expenses` with payment_status
 * 'unpaid' and recurring_template_id set. Users then review the draft in
 * Gestion des Dépenses and click "Marquer payée" when the money actually moves.
 *
 * IDEMPOTENT: last_generated_date guards against double-inserts if the cron
 * fires twice on the same day (network hiccup, retried job, etc.). Safe to
 * run multiple times per day; drafts only ever appear once per template per
 * month.
 *
 * Suggested cron:  5 6 * * *   /usr/bin/php /path/to/scripts/generate_recurring_expenses.php
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$db = Database::getInstance()->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$today = new DateTimeImmutable('today');
$day   = (int) $today->format('j');
$ymFirst = $today->format('Y-m-01');

// Fetch active templates whose day has come and which haven't fired this month.
$stmt = $db->prepare("
    SELECT id, name, category_id, supplier_id, treasury_account_id, amount, day_of_month, created_by
      FROM expense_recurring_templates
     WHERE is_active = 1
       AND day_of_month <= ?
       AND (last_generated_date IS NULL OR last_generated_date < ?)
");
$stmt->execute([$day, $ymFirst]);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$created = 0;
foreach ($templates as $t) {
    // Date the draft on the template's requested day-of-month, not today —
    // that way "Loyer, day 1" always looks correct in the ledger no matter
    // when the cron actually ran. Falls back to today if the composed date
    // is somehow invalid.
    $target = sprintf('%s-%02d', $today->format('Y-m'), (int)$t['day_of_month']);
    $expenseDate = (checkdate((int)$today->format('n'), (int)$t['day_of_month'], (int)$today->format('Y')))
        ? $target
        : $today->format('Y-m-d');

    $db->beginTransaction();
    try {
        $ref = 'EXR-' . date('ym') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $ins = $db->prepare("
            INSERT INTO expenses
                (reference, title, description, category_id, supplier_id, amount,
                 expense_date, payment_status, treasury_account_id, payment_date,
                 recurring_template_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, NULL, ?, ?)
        ");
        $ins->execute([
            $ref,
            $t['name'],
            'Généré automatiquement — modèle récurrent #' . $t['id'],
            $t['category_id'],
            $t['supplier_id'],
            $t['amount'],
            $expenseDate,
            $t['treasury_account_id'],
            $t['id'],
            $t['created_by'],
        ]);
        $db->prepare("UPDATE expense_recurring_templates SET last_generated_date = ? WHERE id = ?")
           ->execute([$today->format('Y-m-d'), $t['id']]);
        $db->commit();
        $created++;
        echo "[OK]  Template #{$t['id']} '{$t['name']}' → expense $ref @ $expenseDate\n";
    } catch (Throwable $e) {
        $db->rollBack();
        echo "[ERR] Template #{$t['id']} '{$t['name']}': " . $e->getMessage() . "\n";
        error_log('generate_recurring_expenses: template #' . $t['id'] . ' — ' . $e->getMessage());
    }
}

echo "Done. $created draft(s) created.\n";
