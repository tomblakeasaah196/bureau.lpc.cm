<?php
/**
 * scripts/generate_recurring_expenses.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · recurring-expense drafts, for a cron.
 *
 * For every active row in expense_recurring_templates whose day_of_month has
 * arrived this month and which has not yet generated for this month, an UNPAID
 * expense is created; Finance validates and pays it in Gestion des Dépenses.
 * The work is RecurringExpenses::generateDue(), which Gestion des Dépenses also
 * runs when it loads (18/11/2026: no cron ran this on live). Idempotent: safe
 * to run several times a day.
 *
 * Suggested cron:  5 6 * * *   /usr/local/bin/php /path/to/scripts/generate_recurring_expenses.php
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions/notify.php';
require_once __DIR__ . '/../includes/classes/RecurringExpenses.php';

$db = Database::getInstance()->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$created = RecurringExpenses::generateDue($db);
foreach ($created as $c) {
    echo "[OK]  Template #{$c['template']} '{$c['name']}' → expense {$c['reference']} @ {$c['date']}\n";
}
echo "Done. " . count($created) . " draft(s) created.\n";
