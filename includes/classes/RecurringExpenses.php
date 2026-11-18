<?php
/**
 * includes/classes/RecurringExpenses.php
 * -----------------------------------------------------------------------------
 * The month's draft of each active recurring expense (« Récurrentes » in
 * Gestion des Dépenses), once its day has come: an UNPAID expense that Finance
 * validates and pays like any other.
 *
 * It used to run only from scripts/generate_recurring_expenses.php by cron, and
 * that cron is in no documented list for the server (scripts/cron/README.md):
 * on live the page promised drafts that never came (Trésorerie & Dépenses
 * video QA, 18/11/2026). Gestion des Dépenses now calls generateDue() when it
 * loads, and the script stays for a cron.
 *
 * Idempotent: each template is locked and its last_generated_date checked
 * inside the same transaction, so two pages loading at once, or the cron and a
 * page, create one draft per template per month.
 * -----------------------------------------------------------------------------
 */
final class RecurringExpenses
{
    /** @return array<int,array{template:int,name:string,reference:string,date:string,amount:float}> drafts created */
    public static function generateDue(PDO $db, ?DateTimeImmutable $today = null): array
    {
        $today   = $today ?? new DateTimeImmutable('today');
        $day     = (int) $today->format('j');
        $ymFirst = $today->format('Y-m-01');

        $due = $db->prepare("
            SELECT id FROM expense_recurring_templates
             WHERE is_active = 1 AND day_of_month <= ?
               AND (last_generated_date IS NULL OR last_generated_date < ?)
        ");
        $due->execute([$day, $ymFirst]);
        $ids = $due->fetchAll(PDO::FETCH_COLUMN);

        $created = [];
        foreach ($ids as $id) {
            $db->beginTransaction();
            try {
                $lock = $db->prepare("SELECT * FROM expense_recurring_templates WHERE id = ? FOR UPDATE");
                $lock->execute([(int) $id]);
                $t = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$t || (int) $t['is_active'] !== 1
                    || ($t['last_generated_date'] !== null && $t['last_generated_date'] >= $ymFirst)) {
                    $db->rollBack();
                    continue;
                }
                $dom = (int) $t['day_of_month'];
                $date = checkdate((int) $today->format('n'), $dom, (int) $today->format('Y'))
                    ? sprintf('%s-%02d', $today->format('Y-m'), $dom)
                    : $today->format('Y-m-d');

                $ref = DocCounter::next($db, 'expense_recurring');
                $db->prepare("
                    INSERT INTO expenses
                        (reference, title, description, category_id, supplier_id, amount,
                         expense_date, payment_status, treasury_account_id, payment_date,
                         recurring_template_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, NULL, ?, ?)
                ")->execute([
                    $ref, $t['name'], 'Dépense récurrente du mois (modèle « ' . $t['name'] . ' »).',
                    $t['category_id'], $t['supplier_id'], $t['amount'], $date,
                    $t['treasury_account_id'], $t['id'], $t['created_by'],
                ]);
                $db->prepare("UPDATE expense_recurring_templates SET last_generated_date = ? WHERE id = ?")
                   ->execute([$today->format('Y-m-d'), $t['id']]);
                $db->commit();
                $created[] = ['template' => (int) $t['id'], 'name' => (string) $t['name'], 'reference' => $ref,
                              'date' => $date, 'amount' => (float) $t['amount']];
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('RecurringExpenses: template #' . $id . ' : ' . $e->getMessage());
            }
        }

        if ($created && function_exists('lpc_notify_permission')) {
            foreach ($created as $c) {
                lpc_notify_permission($db, 'accounting.expenses.mark_paid', 'Dépense récurrente à valider',
                    "{$c['reference']} ({$c['name']}) : " . number_format($c['amount'], 0, ',', ' ')
                        . ' FCFA, du ' . date('d/m/Y', strtotime($c['date'])) . '. À valider et payer dans Gestion des Dépenses.',
                    '/modules/accounting/expenses.php', 'info');
            }
        }
        return $created;
    }
}
