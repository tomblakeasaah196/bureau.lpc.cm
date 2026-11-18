<?php
/**
 * includes/classes/FuelReconciliation.php
 * -----------------------------------------------------------------------------
 * Carburant : Finance et Opérations, période par période (the owner's answer 6,
 * 18/11/2026: « Finance gave 1 000 000 for fuel this quarter, Operations logged
 * 990 000 »; both records are kept so they can be compared and audited, and the
 * other side is told when they differ, to regularise).
 *
 *   Finance  = the money given for fuel: paid expenses in « Carburant &
 *              Transport » (slug logistique), and every paid fleet fuel request
 *              whatever its category; dated by the expense's date.
 *   Opérations = the fuel logged in Flotte (fuel_logs.total_cost), by its date.
 *   En attente = fuel logged whose request Finance has not paid yet: the normal
 *              flow, not a difference.
 *   Écart à régulariser = Finance − (Opérations − En attente). It is what
 *              neither side can explain yet:
 *                · money given with no fill-up logged (an advance), to be logged
 *                  in Flotte and attached (« Rattacher »), or returned;
 *                · a request paid at another amount than the fill-up;
 *                · a fill-up whose request Finance refused (deleted).
 *
 * The links come from migration 133 (expenses.source_type 'fleet_fuel' /
 * source_id = fuel_logs.id; fuel_logs.expense_id). The last gap alerted for a
 * month is kept in fuel_reconciliation_alerts (migration 139), so a side is told
 * once per change, not on every click.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../functions/notify.php';

final class FuelReconciliation
{
    public const CATEGORY_SLUG = 'logistique';
    public const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
                           'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /** [start, end, label] for a month (n 1..12), a quarter (n 1..4) or a year. */
    public static function period(string $kind, int $year, int $n = 1): array
    {
        if ($kind === 'quarter') {
            $n = max(1, min(4, $n));
            $m1 = ($n - 1) * 3 + 1;
            $start = sprintf('%04d-%02d-01', $year, $m1);
            return [$start, date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $m1 + 2))), "T$n $year"];
        }
        if ($kind === 'year') {
            return [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year), (string) $year];
        }
        $n = max(1, min(12, $n));
        $start = sprintf('%04d-%02d-01', $year, $n);
        return [$start, date('Y-m-t', strtotime($start)), self::MONTHS[$n - 1] . " $year"];
    }

    /** Both sides for one period, with the lines that explain the gap. */
    public static function summary(PDO $db, string $start, string $end): array
    {
        $paid = $db->prepare("
            SELECT e.id, e.reference, e.title, e.amount, e.expense_date, e.payment_date,
                   v.plate_number, f.id AS fuel_id, f.total_cost AS logged, f.date AS log_date, f.liters
              FROM expenses e
              JOIN expense_categories c ON c.id = e.category_id
         LEFT JOIN fuel_logs f ON e.source_type = 'fleet_fuel' AND f.id = e.source_id AND f.deleted_at IS NULL
         LEFT JOIN vehicles v  ON v.id = COALESCE(e.vehicle_id, f.vehicle_id)
             WHERE e.payment_status = 'paid'
               AND (c.slug = ? OR e.source_type = 'fleet_fuel')
               AND e.expense_date BETWEEN ? AND ?
             ORDER BY e.expense_date, e.id
        ");
        $paid->execute([self::CATEGORY_SLUG, $start, $end]);
        $paidRows = $paid->fetchAll(PDO::FETCH_ASSOC);

        $logs = $db->prepare("
            SELECT f.id, f.date, f.liters, f.total_cost, f.expense_id, v.plate_number,
                   e.reference, e.payment_status, e.amount AS expense_amount
              FROM fuel_logs f
              JOIN vehicles v ON v.id = f.vehicle_id
         LEFT JOIN expenses e ON e.id = f.expense_id
             WHERE f.deleted_at IS NULL AND f.date BETWEEN ? AND ?
             ORDER BY f.date, f.id
        ");
        $logs->execute([$start, $end]);
        $logRows = $logs->fetchAll(PDO::FETCH_ASSOC);

        $finance = 0.0; $ops = 0.0; $pending = 0.0;
        $advances = []; $changed = []; $refused = []; $waiting = []; $matched = 0;
        foreach ($paidRows as $r) {
            $finance += (float) $r['amount'];
            if ($r['fuel_id'] === null) {
                $advances[] = self::line($r, ['amount' => (float) $r['amount']]);
            } elseif (round((float) $r['amount']) !== round((float) $r['logged'])) {
                $changed[] = self::line($r, ['amount' => (float) $r['amount'], 'logged' => (float) $r['logged'],
                                             'gap' => (float) $r['amount'] - (float) $r['logged']]);
            } else {
                $matched++;
            }
        }
        foreach ($logRows as $l) {
            $ops += (float) $l['total_cost'];
            if ($l['expense_id'] === null) {
                $refused[] = ['id' => (int) $l['id'], 'date' => $l['date'], 'plate' => $l['plate_number'],
                              'liters' => (float) $l['liters'], 'logged' => (float) $l['total_cost']];
            } elseif ($l['payment_status'] !== 'paid') {
                $pending += (float) $l['total_cost'];
                $waiting[] = ['id' => (int) $l['id'], 'date' => $l['date'], 'plate' => $l['plate_number'],
                              'liters' => (float) $l['liters'], 'logged' => (float) $l['total_cost'],
                              'expense_id' => (int) $l['expense_id'], 'reference' => $l['reference']];
            }
        }
        $toRegularise = round($finance - ($ops - $pending));
        return [
            'start'         => $start,
            'end'           => $end,
            'finance'       => round($finance),
            'operations'    => round($ops),
            'pending'       => round($pending),
            'gap'           => round($finance - $ops),
            'to_regularise' => $toRegularise,
            'status'        => $toRegularise == 0 ? 'ok' : 'gap',
            'matched'       => $matched,
            'advances'      => $advances,
            'changed'       => $changed,
            'refused'       => $refused,
            'waiting'       => $waiting,
        ];
    }

    /** Twelve months or four quarters of a year, figures only. */
    public static function year(PDO $db, int $year, string $kind = 'month'): array
    {
        $rows = [];
        $count = $kind === 'quarter' ? 4 : 12;
        for ($n = 1; $n <= $count; $n++) {
            [$s, $e, $label] = self::period($kind, $year, $n);
            $sum = self::summary($db, $s, $e);
            $rows[] = ['n' => $n, 'label' => $label, 'finance' => $sum['finance'], 'operations' => $sum['operations'],
                       'pending' => $sum['pending'], 'gap' => $sum['gap'], 'to_regularise' => $sum['to_regularise'],
                       'status' => $sum['status']];
        }
        return $rows;
    }

    /**
     * After a change on either side (a payment, a refusal, an edit, a fill-up),
     * tell the other side when the month's gap to regularise has changed.
     * $side is who acted: 'finance' or 'operations'. Best effort: a failure here
     * never undoes the change that triggered it.
     */
    public static function afterChange(PDO $db, string $date, string $side, int $actorId): void
    {
        try {
            $ts = strtotime($date) ?: time();
            $year = (int) date('Y', $ts); $month = (int) date('n', $ts);
            [$s, $e, $label] = self::period('month', $year, $month);
            $sum = self::summary($db, $s, $e);
            $key = sprintf('%04d-%02d', $year, $month);

            $prev = $db->prepare("SELECT to_regularise FROM fuel_reconciliation_alerts WHERE period = ?");
            $prev->execute([$key]);
            $before = $prev->fetchColumn();
            $before = $before === false ? 0.0 : (float) $before;
            // A fill-up logged while an advance waits unattached: Finance must hear of it
            // even though the gap has not moved yet (the fill-up is only « en attente »).
            $attachHint = ($side === 'operations' && $sum['advances'] && $sum['waiting']);
            if (round($before) == $sum['to_regularise'] && !$attachHint) return;

            $db->prepare("INSERT INTO fuel_reconciliation_alerts (period, to_regularise, finance, operations, alerted_at)
                          VALUES (?, ?, ?, ?, NOW())
                          ON DUPLICATE KEY UPDATE to_regularise = VALUES(to_regularise), finance = VALUES(finance),
                                                  operations = VALUES(operations), alerted_at = NOW()")
               ->execute([$key, $sum['to_regularise'], $sum['finance'], $sum['operations']]);

            $f = self::fcfa($sum['finance']); $o = self::fcfa($sum['operations']); $p = self::fcfa($sum['pending']);
            $url = '/modules/accounting/expenses.php#fuel';
            if ($sum['to_regularise'] == 0) {
                $msg = "Carburant de $label : la Finance a donné $f, les Opérations ont relevé $o"
                     . ($sum['pending'] > 0 ? " (dont $p en attente de paiement)" : '') . ". Plus d'écart à régulariser.";
                lpc_notify_permission($db, ['fleet.fuel.log', 'accounting.expenses.mark_paid'],
                    'Carburant : écart régularisé', $msg, $url, 'success', [$actorId]);
                return;
            }
            $gap = self::fcfa(abs($sum['to_regularise']));
            $msg = "Carburant de $label : la Finance a donné $f, les Opérations ont relevé $o"
                 . ($sum['pending'] > 0 ? " (dont $p en attente de paiement)" : '') . ". Écart à régulariser : $gap. ";
            if ($side === 'finance') {
                $msg .= $sum['to_regularise'] > 0
                    ? "De l'argent donné pour le carburant n'a pas de plein relevé dans Flotte : saisissez les pleins faits avec cet argent."
                    : "Un plein relevé a été refusé ou payé à un autre montant : voyez le détail.";
                $to = 'fleet.fuel.log';
            } else {
                $msg .= $attachHint
                    ? "Un plein vient d'être relevé alors qu'une avance carburant n'est rattachée à aucun plein : s'il a été payé avec l'avance, rattachez-le plutôt que de le payer deux fois."
                    : "Voyez le détail dans Gestion des Dépenses, onglet Carburant.";
                $to = 'accounting.expenses.mark_paid';
            }
            lpc_notify_permission($db, $to, 'Carburant : écart à régulariser', $msg, $url, 'warning', [$actorId]);
        } catch (Throwable $e) {
            error_log('FuelReconciliation::afterChange: ' . $e->getMessage());
        }
    }

    /** True when an expense row counts as fuel money (for the callers' « should I check? »). */
    public static function isFuel(PDO $db, int $categoryId, ?string $sourceType): bool
    {
        if ($sourceType === 'fleet_fuel') return true;
        $s = $db->prepare("SELECT slug FROM expense_categories WHERE id = ?");
        $s->execute([$categoryId]);
        return $s->fetchColumn() === self::CATEGORY_SLUG;
    }

    private static function line(array $r, array $extra): array
    {
        return array_merge([
            'id' => (int) $r['id'], 'reference' => $r['reference'], 'title' => $r['title'],
            'date' => $r['expense_date'], 'plate' => $r['plate_number'],
        ], $extra);
    }

    private static function fcfa(float $n): string
    {
        return number_format($n, 0, ',', ' ') . ' F';
    }
}
