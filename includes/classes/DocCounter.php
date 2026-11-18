<?php
/**
 * includes/classes/DocCounter.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — unbroken document numbers (FAC-2611-0001, 0002, …).
 *
 * WHY
 *   References used to end in a random code (FAC-2611-A3F9), or in a count of
 *   today's rows (CRE-20261102-003) that two people saving at once could
 *   share. The DGI expects invoices numbered in an unbroken sequence, and the
 *   data wipe tool restarts numbering at 0001 once a module's test data is
 *   gone.
 *
 * HOW
 *   One row per document type and period in `document_counters` (migration
 *   350). next() locks that row FOR UPDATE, takes the next number and saves
 *   it, all in the caller's transaction:
 *     · two people saving at once queue on the row instead of sharing a number;
 *     · a save that fails rolls its number back with it, so none is skipped.
 *   Every caller already saves its document in a transaction; next() refuses
 *   to run outside one rather than hand out a number nothing protects.
 *
 *   A number that a row of the document's table already carries (an old random
 *   code that happened to be all digits, e.g. FAC-2611-0042) is stepped over.
 *
 * PERIODS
 *   The types in Paramètres → Préférences → Numérotation follow
 *   `doc_sequence_reset` (yearly 2026, monthly 202611, never '') for the
 *   document's date. CRE and inventory reports carry the day in their number
 *   and recycling sales the month, so their counters restart with it, as the
 *   numbers always did.
 *
 * USAGE (inside the transaction that inserts the document)
 *   $reference = DocCounter::next($db, 'invoice');            // FAC-2611-0001
 *   $reference = DocCounter::next($db, 'order', '2026-10-30'); // CMD-2610-0007
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class DocCounter
{
    /**
     * Each numbered document: the table its reference lives in, how the
     * reference is written ('pref' = Prefs::docNumber(), i.e. the prefix and
     * format set in Préférences) and when its counter restarts ('pref' =
     * doc_sequence_reset).
     */
    public const TYPES = [
        'invoice'           => ['table' => 'invoices',          'format' => 'pref',                      'period' => 'pref'],
        'quote'             => ['table' => 'proposals',         'format' => 'pref',                      'period' => 'pref'],
        'order'             => ['table' => 'sales_orders',      'format' => 'pref',                      'period' => 'pref'],
        'delivery'          => ['table' => 'deliveries',        'format' => 'pref',                      'period' => 'pref'],
        'purchase'          => ['table' => 'purchase_orders',   'format' => 'PO-{YYMM}-{SEQ}',           'period' => 'pref'],
        'receipt'           => ['table' => 'payments',          'format' => 'REC-{YYMM}-{SEQ}',          'period' => 'pref'],
        'wallet'            => ['table' => 'payments',          'format' => 'WLT-{YYMM}-{SEQ}',          'period' => 'pref'],
        'expense'           => ['table' => 'expenses',          'format' => 'EX-{YYMM}-{SEQ}',           'period' => 'pref'],
        'expense_recurring' => ['table' => 'expenses',          'format' => 'EXR-{YYMM}-{SEQ}',          'period' => 'pref'],
        'cre'               => ['table' => 'cre_documents',     'format' => 'CRE-{YYYY}{MM}{DD}-{SEQ3}', 'period' => 'day'],
        'inventory'         => ['table' => 'inventory_reports', 'format' => 'INV-{YYYY}{MM}{DD}-{SEQ3}', 'period' => 'day'],
        'recycling'         => ['table' => 'recycling_sales',   'format' => 'REC-{YYYY}{MM}-{SEQ3}',     'period' => 'month'],
        'loan'              => ['table' => 'loans',             'format' => 'FIN-{YYMM}-{SEQ}',          'period' => 'pref'],
    ];

    /**
     * The next reference of $type, reserved in the caller's transaction.
     *
     * @param string|null $date Y-m-d of the document; today when null
     * @throws InvalidArgumentException for an unknown type
     * @throws LogicException           outside a transaction
     */
    public static function next(PDO $db, string $type, ?string $date = null): string
    {
        $spec = self::TYPES[$type] ?? null;
        if ($spec === null) {
            throw new InvalidArgumentException("DocCounter: unknown document type '{$type}'.");
        }
        if (!$db->inTransaction()) {
            throw new LogicException("DocCounter: a {$type} number must be taken inside the transaction that saves it.");
        }

        $ts     = self::timestamp($date);
        $period = self::period($spec['period'], $ts);

        // ON DUPLICATE KEY UPDATE takes the row's exclusive lock straight away
        // (INSERT IGNORE would take a shared one, and two savers upgrading it
        // together deadlock). The FOR UPDATE read then holds it until commit.
        $db->prepare("INSERT INTO document_counters (doc_type, period, last_number) VALUES (?, ?, 0)
                      ON DUPLICATE KEY UPDATE last_number = last_number")->execute([$type, $period]);
        $read = $db->prepare("SELECT last_number FROM document_counters WHERE doc_type = ? AND period = ? FOR UPDATE");
        $read->execute([$type, $period]);
        $n = (int) $read->fetchColumn();

        $taken = $db->prepare("SELECT 1 FROM `{$spec['table']}` WHERE reference = ? LIMIT 1");
        for ($guard = 0; ; $guard++) {
            $n++;
            $reference = self::format($type, $n, $ts);
            $taken->execute([$reference]);
            if (!$taken->fetchColumn()) {
                break;
            }
            if ($guard >= 100000) {
                throw new RuntimeException("DocCounter: no free {$type} number found after {$n}.");
            }
        }

        $db->prepare("UPDATE document_counters SET last_number = ? WHERE doc_type = ? AND period = ?")
           ->execute([$n, $type, $period]);

        return $reference;
    }

    /** The reference number $n of $type would carry on $ts. */
    public static function format(string $type, int $n, int $ts): string
    {
        $format = self::TYPES[$type]['format'];
        if ($format === 'pref') {
            return Prefs::docNumber($type, $n, date('Y-m-d', $ts));
        }

        return strtr($format, [
            '{YYMM}' => date('ym', $ts),
            '{YYYY}' => date('Y', $ts),
            '{MM}'   => date('m', $ts),
            '{DD}'   => date('d', $ts),
            '{SEQ}'  => str_pad((string) $n, Prefs::int('doc_sequence_padding', 4), '0', STR_PAD_LEFT),
            '{SEQ3}' => str_pad((string) $n, 3, '0', STR_PAD_LEFT),
        ]);
    }

    /** The document types whose references live in $table (several for payments and expenses). */
    public static function typesFor(string $table): array
    {
        return array_keys(array_filter(self::TYPES, fn ($spec) => $spec['table'] === $table));
    }

    /** The counter period a $type numbered on $ts counts in (the data wipe tool steps a counter back). */
    public static function periodOf(string $type, int $ts): string
    {
        return self::period(self::TYPES[$type]['period'], $ts);
    }

    /** The counter period of a document dated $ts: '2026', '202611', '20261102' or ''. */
    private static function period(string $rule, int $ts): string
    {
        if ($rule === 'pref') {
            $rule = Prefs::str('doc_sequence_reset', 'yearly');
        }

        return match ($rule) {
            'day'              => date('Ymd', $ts),
            'month', 'monthly' => date('Ym', $ts),
            'never'            => '',
            default            => date('Y', $ts),
        };
    }

    private static function timestamp(?string $date): int
    {
        if ($date !== null && $date !== '') {
            $ts = strtotime($date);
            if ($ts !== false) {
                return $ts;
            }
        }
        return time();
    }
}
