<?php
/**
 * includes/classes/DataWipe.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — clears test data, module by module, before real data starts
 * (owner's decisions of 2 Nov 2026; runbook docs/DATA_WIPE.md). Only the account
 * named by WIPE_ADMIN_EMAIL reaches it (DataWipeAccess).
 *
 * PLAN — what goes when tables are ticked
 *   Every row of the tables ticked, then, until nothing new turns up:
 *     · rows that point at a wiped row through a foreign key (read from
 *       information_schema, so a new table is followed): a NOT NULL or CASCADE
 *       column means the row goes; an optional column is cleared instead (a
 *       delivery keeps going without its truck);
 *     · rows that belong to a wiped row without a foreign key (DataWipeMap
 *       soft links: the journal entries a document posted, its treasury and
 *       stock movements, PDFs, signatures, notifications and activity lines);
 *     · the whole document when one of its lines goes (DataWipeMap::WHOLE);
 *     · what a wiped row owns: a client's 411 / supplier's 401 sub-account,
 *       the journal entry a document posted.
 *   A protected table (DataWipeMap::PROTECTED) never loses a row, except a
 *   sub-account owned by a wiped client or supplier; a pick that would need it
 *   is refused with the reason.
 *
 * RUN — one transaction, so a wipe that can't be copied doesn't happen
 *   lock (one wipe at a time) → clear optional links → posted entries back to
 *   draft (the accounting trigger refuses to delete a posted entry) → copy and
 *   delete, children before parents → recalculate what is left
 *   (DataWipeBalances) → restart the counters of emptied documents → write the
 *   safety copy (restore.sql + files + README, a .tar.gz outside the web root)
 *   → commit. Then: files nobody uses any more are deleted, AUTO_INCREMENT of
 *   emptied tables restarts, and the MD is told (DataWipeAccess).
 *
 *   restore.sql undoes every step, last first, so importing it in phpMyAdmin
 *   puts the database back as it was before the wipe.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

require_once __DIR__ . '/DataWipeMap.php';
require_once __DIR__ . '/DataWipeBalances.php';
require_once __DIR__ . '/DataWipeStaff.php';

final class DataWipe
{
    public const KEEP_DAYS = 30;
    private const CHUNK = 500;

    private PDO $db;
    /** @var array{tables: array, cols: array, keys: array, fks: array}|null */
    private ?array $schema = null;
    private ?array $edges = null;
    /** The full reset takes every journal entry: a sub-account never keeps one (DataWipe::planFull). */
    private bool $full = false;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // =========================================================================
    // What can be picked
    // =========================================================================

    /** Tables that exist, are not protected and are not "followers only". */
    public function wipeable(string $table): bool
    {
        $s = $this->schema();
        return isset($s['tables'][$table]) && !DataWipeMap::isProtected($table) && !in_array($table, DataWipeMap::FOLLOWERS_ONLY, true)
            && !empty($s['keys'][$table]);
    }

    /** The screen's modules with their tables and row counts: [key => ['label', 'tables' => [table => ['label', 'rows']]]]. */
    public function modules(): array
    {
        $s = $this->schema();
        $placed = [];
        $out = [];
        foreach (DataWipeMap::MODULES as $key => [$label, $tables]) {
            $rows = [];
            foreach ($tables as $t) {
                if ($this->wipeable($t)) {
                    $rows[$t] = ['label' => DataWipeMap::label($t), 'rows' => $this->count($t)];
                }
                $placed[$t] = true;
            }
            if ($rows) {
                $out[$key] = ['label' => $label, 'tables' => $rows];
            }
        }
        $other = [];
        foreach (array_keys($s['tables']) as $t) {
            if (!isset($placed[$t]) && $this->wipeable($t)) {
                $other[$t] = ['label' => DataWipeMap::label($t), 'rows' => $this->count($t)];
            }
        }
        if ($other) {
            ksort($other);
            $out['autres'] = ['label' => 'Autres', 'tables' => $other];
        }
        return $out;
    }

    /** The picked tables that may be wiped, sorted (also what a code is tied to). */
    public function cleanPick(array $tables): array
    {
        $ok = array_values(array_unique(array_filter(array_map('strval', $tables), fn ($t) => $this->wipeable($t))));
        sort($ok);
        return $ok;
    }

    public static function pickHash(array $picked): string
    {
        return hash('sha256', implode(',', $picked));
    }

    // =========================================================================
    // Plan
    // =========================================================================

    /**
     * What a wipe of $tables would take, without changing anything:
     *   picked   the tables ticked that may be wiped
     *   delete   [table => [key => true]]  rows that go
     *   unlink   [table => [column => [key => true]]]  links cleared on rows that stay
     *   why      [table => 'reason it was added']
     *   counts   [table => rows]  and total
     *   blocked  [messages]  why it can't run as picked
     *   balances what is recalculated (DataWipeBalances::preview)
     */
    public function plan(array $tables): array
    {
        $picked = $this->cleanPick($tables);
        $start = [];
        $why = [];
        foreach ($picked as $t) {
            $keys = $this->allKeys($t);
            $why[$t] = 'coché';
            if ($keys) {
                $start[$t] = $keys;
            }
        }
        [$delete, $unlink, $why, $blocked] = $this->closure($start, $why);
        return $this->finish(['picked' => $picked], $delete, $unlink, $why, $blocked);
    }

    /**
     * « Remise à zéro avant démarrage » (the owner's decisions 16 and 17, 18/11/2026): every table
     * that may be wiped, plus the treasury accounts and their own sub-accounts; the numbering
     * restarts. Kept: users, roles, settings, the chart (parents and categories), the employees,
     * Courrier and the help centre, and, if asked, the products and their prices ($keepProducts)
     * and the suppliers with their tariffs and ristourne ladders ($keepSuppliers).
     */
    public function fullResetTables(bool $keepProducts, bool $keepSuppliers): array
    {
        $tables = [];
        foreach ($this->modules() as $m) {
            foreach (array_keys($m['tables']) as $t) {
                $tables[] = $t;
            }
        }
        $keep = array_merge($keepProducts ? DataWipeMap::FULL_RESET_PRODUCTS : [], $keepSuppliers ? DataWipeMap::FULL_RESET_SUPPLIERS : []);
        $tables = array_values(array_diff($tables, $keep));
        sort($tables);
        return $tables;
    }

    /** What the full reset would take, without changing anything (plan() plus 'mode' => 'full', 'options'). */
    public function planFull(bool $keepProducts, bool $keepSuppliers): array
    {
        $picked = $this->fullResetTables($keepProducts, $keepSuppliers);
        $start = [];
        $why = [];
        $allow = [];
        foreach ($picked as $t) {
            $why[$t] = 'remise à zéro';
            if ($keys = $this->allKeys($t)) {
                $start[$t] = $keys;
            }
        }
        foreach (DataWipeMap::FULL_RESET_ALSO as $t) {
            if (!$this->hasTable($t) || empty($this->schema()['keys'][$t])) {
                continue;
            }
            $allow[$t] = true;
            $why[$t] = 'remise à zéro';
            if ($keys = $this->allKeys($t)) {
                $start[$t] = $keys;
            }
        }
        // Every entry goes, so a sub-account never keeps one: the « manual entry left » guard is off
        if (!$this->full) {
            $this->full = true;
            $this->edges = null;
        }
        // The activity log keeps what it says about staff, roles, settings, the chart and Courrier;
        // its lines about the wiped tables go (most name rows that no longer exist)
        foreach (['audit_logs', 'audit_logs_archive'] as $log) {
            if ($this->hasColumn($log, 'table_name')) {
                $names = array_merge($picked, array_keys($allow));
                $in = implode(',', array_map([$this->db, 'quote'], $names));
                if ($keys = $this->rowKeys($log, "table_name IN ({$in})")) {
                    $start[$log] = ($start[$log] ?? []) + $keys;
                    $why[$log] = 'activité sur les données effacées';
                }
            }
        }
        // Kept products or suppliers keep what they hold; anything that would take them blocks the run
        [$delete, $unlink, $why, $blocked] = $this->closure($start, $why, $allow);
        foreach ([$keepProducts ? DataWipeMap::FULL_RESET_PRODUCTS : [], $keepSuppliers ? DataWipeMap::FULL_RESET_SUPPLIERS : []] as $kept) {
            foreach ($kept as $t) {
                // A price-history line a wiped purchase order wrote goes with it; the tariff itself stays
                if ($t === 'supplier_price_history') {
                    continue;
                }
                if (!empty($delete[$t])) {
                    $blocked['keep' . $t] = sprintf('« %s » doit être gardé, mais %d ligne(s) partiraient avec ce qui est effacé.', DataWipeMap::label($t), count($delete[$t]));
                }
            }
        }
        $picked = array_values(array_unique(array_merge($picked, array_keys($allow))));
        sort($picked);
        return $this->finish(['picked' => $picked, 'mode' => 'full',
                              'options' => ['products' => $keepProducts, 'suppliers' => $keepSuppliers]], $delete, $unlink, $why, $blocked);
    }

    /** Run the full reset (planFull), the same way as run(): one transaction, the copy first, every counter restarted. */
    public function runFull(bool $keepProducts, bool $keepSuppliers, array $user, string $reason, ?callable $inTransaction = null): array
    {
        return $this->execute(fn () => $this->planFull($keepProducts, $keepSuppliers), $user, $reason, $inTransaction);
    }

    /** What a one-time code for the full reset is tied to: the choices, not a table list that may grow. */
    public static function fullPick(bool $keepProducts, bool $keepSuppliers): array
    {
        return ['@remise-a-zero', 'produits=' . ($keepProducts ? 'gardés' : 'effacés'), 'fournisseurs=' . ($keepSuppliers ? 'gardés' : 'effacés')];
    }

    /** SQL: no line on account $col that a document's own entry did not post. */
    private function noEntryLeftOn(string $col): string
    {
        $types = [];
        foreach (DataWipeMap::JOURNAL_SOURCES as $list) {
            foreach ($list as $t) {
                $types[] = $this->db->quote($t);
                $types[] = $this->db->quote($t . '_reversal');
            }
        }
        return "NOT EXISTS (SELECT 1 FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
                             WHERE jl.account_id = {$col}
                               AND NOT (COALESCE(je.source_type, '') IN (" . implode(',', $types) . ")
                                        OR (je.source_type IS NULL AND je.reference LIKE 'PAY-%')))";
    }

    /**
     * Everything that goes with the $start rows ([table => [key => true]]), until nothing new
     * turns up. $allow: protected tables that may lose the rows reached (a staff delete);
     * $stop: tables whose rows lead nowhere further (handled by the caller); $unlink: links
     * already to clear. Returns [delete, unlink, why, blocked (keyed)].
     */
    private function closure(array $start, array $why, array $allow = [], array $stop = [], array $unlink = []): array
    {
        $delete = [];
        $blocked = [];
        $frontier = [];
        foreach ($start as $t => $keys) {
            if ($keys) {
                $delete[$t] = $keys;
                $frontier[$t] = $keys;
            }
        }

        $edges = $this->edges();
        $guard = 0;
        while ($frontier && $guard++ < 100) {
            $next = [];
            foreach ($frontier as $table => $keys) {
                if (isset($stop[$table])) {
                    continue;
                }
                foreach ($edges[$table] ?? [] as $e) {
                    $found = $this->follow($e, array_keys($keys));
                    if (!$found) {
                        continue;
                    }
                    if ($e['mode'] === 'unlink') {
                        foreach ($found as $k) {
                            $unlink[$e['to']][$e['col']][$k] = true;
                        }
                        continue;
                    }
                    if (($e['mode'] === 'block' || (DataWipeMap::isProtected($e['to']) && empty($e['owned']))) && !isset($allow[$e['to']])) {
                        $blocked[$e['to'] . '<' . $table] = sprintf('%s : %d ligne(s) de « %s » en dépendent, et « %s » n\'est jamais effacé.',
                            DataWipeMap::label($table), count($found), DataWipeMap::label($e['to']), DataWipeMap::label($e['to']));
                        continue;
                    }
                    foreach ($found as $k) {
                        if (!isset($delete[$e['to']][$k])) {
                            $delete[$e['to']][$k] = true;
                            $next[$e['to']][$k] = true;
                        }
                    }
                    if (!isset($why[$e['to']]) && isset($next[$e['to']])) {
                        $why[$e['to']] = $e['why'] . ' (' . DataWipeMap::label($table) . ')';
                    }
                }
            }
            $frontier = $next;
        }

        // A link on a row that goes anyway is not cleared, it goes with the row
        foreach ($unlink as $t => $cols) {
            foreach ($cols as $c => $keys) {
                $keys = array_diff_key($keys, $delete[$t] ?? []);
                if ($keys) {
                    $unlink[$t][$c] = $keys;
                } else {
                    unset($unlink[$t][$c]);
                }
            }
            if (!$unlink[$t]) {
                unset($unlink[$t]);
            }
        }

        // Posted entries of a locked year can't be put back to draft, so they can't be deleted
        foreach ($this->lockedYears(array_keys($delete['journal_entries'] ?? [])) as $y) {
            $blocked['year' . $y] = "L'exercice {$y} est verrouillé : ses écritures ne peuvent pas être effacées. Rouvrez-le d'abord (Clôture d'exercice).";
        }
        return [$delete, $unlink, $why, $blocked];
    }

    /** Years among these journal entries' that are locked (the accounting trigger refuses any change there). */
    private function lockedYears(array $ids): array
    {
        $years = [];
        if (!$ids || !$this->hasColumn('financial_years', 'state')) {
            return [];
        }
        foreach ($this->chunks($ids) as $chunk) {
            foreach ($this->db->query("SELECT DISTINCT YEAR(je.date) FROM journal_entries je JOIN financial_years fy ON fy.year = YEAR(je.date)
                                        WHERE je.id IN (" . implode(',', array_map('intval', $chunk)) . ") AND fy.state IN ('locked', 'final_closed')")->fetchAll(PDO::FETCH_COLUMN) as $y) {
                $years[$y] = $y;
            }
        }
        return array_values($years);
    }

    /** The plan as callers read it: counts, totals, balances recalculated. */
    private function finish(array $head, array $delete, array $unlink, array $why, array $blocked): array
    {
        $counts = [];
        foreach ($delete as $t => $keys) {
            $counts[$t] = count($keys);
        }
        arsort($counts);
        $unlinked = [];
        foreach ($unlink as $t => $cols) {
            foreach ($cols as $c => $keys) {
                $unlinked["{$t}.{$c}"] = count($keys);
            }
        }

        $plan = $head + [
            'delete' => $delete, 'unlink' => $unlink, 'why' => $why, 'counts' => $counts,
            'unlinked' => $unlinked, 'total' => array_sum($counts), 'blocked' => array_values($blocked),
        ];
        $plan['balances'] = (new DataWipeBalances($this->db))->preview($plan);

        return $plan;
    }

    // =========================================================================
    // One by one (the second tab; owner's decisions of 3 Nov 2026)
    // =========================================================================

    /** The kinds of record this database can search: [table => label]. */
    public function recordKinds(): array
    {
        $out = [];
        foreach (DataWipeMap::RECORDS as $t => [$label]) {
            if ($this->recordSql($t) !== null) {
                $out[$t] = $label;
            }
        }
        return $out;
    }

    /**
     * Records found by number or name, newest first: [['table', 'id', 'label', 'sub', 'refused']].
     * 'refused' says why one can't be picked (a staff account still active…).
     */
    public function searchRecords(string $table, string $q, int $limit = 20): array
    {
        $sql = $this->recordSql($table);
        if ($sql === null) {
            return [];
        }
        $q = trim($q);
        $where = '1 = 1';
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where = '(' . implode(' OR ', array_map(fn ($m) => "{$m} LIKE ?", $sql['match'])) . ')';
            $params = array_fill(0, count($sql['match']), $like);
        }
        $st = $this->db->prepare("SELECT x.id, {$sql['fields']} FROM {$sql['from']} WHERE {$where} ORDER BY x.id DESC LIMIT " . max(1, min(50, $limit)));
        $st->execute($params);
        $staff = $table === 'users' ? new DataWipeStaff($this->db, $this) : null;
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $refused = $staff ? $staff->refusal($staff->account((int) $r['id']) ?? []) : null;
            $out[] = ['table' => $table, 'id' => (int) $r['id'], 'label' => (string) $r['label'], 'sub' => self::recordSub($r), 'refused' => $refused];
        }
        return $out;
    }

    /** How a record is named on the screen and in the log ("Facture FAC-2611-0003"), or null when it doesn't exist. */
    public function recordLabel(string $table, int $id): ?string
    {
        $sql = $this->recordSql($table);
        if ($sql === null) {
            return null;
        }
        $st = $this->db->prepare("SELECT x.id, {$sql['fields']} FROM {$sql['from']} WHERE x.id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $who = trim((string) ($r['who'] ?? ''));
        return DataWipeMap::RECORDS[$table][1] . ' ' . $r['label'] . ($who !== '' && $table !== 'users' ? ' · ' . $who : '')
             . ($table === 'users' && $who !== '' ? ' (' . $who . ')' : '');
    }

    /**
     * The records picked that may go, sorted, with what was chosen for staff accounts:
     * [records, accounts, problems]. Each record: ['table', 'id', 'label', 'pick'] (+ 'mode', 'to',
     * 'to_name' for an account); 'pick' is what a one-time code is tied to ("invoices#12",
     * "users#7:handover>3", "users#9:wipe"). Problems block the plan.
     */
    public function cleanRecords(array $records): array
    {
        $picked = [];
        $accounts = [];
        $problems = [];
        $staff = null;
        foreach ($records as $r) {
            $t = (string) ($r['table'] ?? '');
            $id = (int) ($r['id'] ?? 0);
            if (!isset(DataWipeMap::RECORDS[$t]) || $id <= 0 || isset($picked["{$t}#{$id}"]) || $this->recordSql($t) === null) {
                continue;
            }
            $label = $this->recordLabel($t, $id);
            if ($label === null) {
                $problems[] = DataWipeMap::RECORDS[$t][1] . " n° {$id} n'existe plus : retirez-le de la liste.";
                continue;
            }
            $item = ['table' => $t, 'id' => $id, 'label' => $label, 'pick' => "{$t}#{$id}"];
            if ($t === 'users') {
                $staff ??= new DataWipeStaff($this->db, $this);
                $account = $staff->account($id);
                if ($refused = $staff->refusal($account)) {
                    $problems[] = "{$label} : {$refused}";
                    continue;
                }
                $item['mode'] = ($r['mode'] ?? '') === 'wipe' ? 'wipe' : 'handover';
                if ($item['mode'] === 'handover') {
                    $to = (int) ($r['to'] ?? 0);
                    $target = $to > 0 && $to !== $id ? $staff->account($to) : null;
                    if (!$target || $target['status'] !== 'active') {
                        $problems[] = "{$label} : choisissez le compte actif qui reprend ses enregistrements.";
                        $target = null;
                    }
                    $item['to'] = $target ? (int) $target['id'] : 0;
                    $item['to_name'] = $target['name'] ?? null;
                    $item['pick'] .= ':handover>' . $item['to'];
                } else {
                    $item['pick'] .= ':wipe';
                }
                $accounts[$id] = ['mode' => $item['mode'], 'to' => $item['to'] ?? null, 'name' => $account['name']];
            }
            $picked["{$t}#{$id}"] = $item;
        }
        ksort($picked, SORT_STRING);
        return [array_values($picked), $accounts, $problems];
    }

    /** What a code for these records is tied to: their 'pick' strings, sorted. */
    public static function recordPick(array $records): array
    {
        $out = array_column($records, 'pick');
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * What deleting these records would take, without changing anything: plan()'s keys, plus
     *   mode 'records', records (cleanRecords), reassign [table => [column => [key => user id]]]
     *   (hand-overs), reassigned [['table', 'column', 'rows', 'to']], mailboxes (personal ones
     *   removed), numbering [['kind' => 'back' | 'gap', 'text']].
     */
    public function planRecords(array $records): array
    {
        [$picked, $accounts, $problems] = $this->cleanRecords($records);
        $start = [];
        $why = [];
        foreach ($picked as $r) {
            if ($r['table'] !== 'users') {
                $start[$r['table']][$r['id']] = true;
                $why[$r['table']] ??= 'choisi';
            }
        }
        $staff = ['seed' => [], 'why' => [], 'reassign' => [], 'unlink' => [], 'blocked' => [], 'mailboxes' => []];
        if ($accounts) {
            $staff = (new DataWipeStaff($this->db, $this))->plan($accounts);
            foreach ($staff['seed'] as $t => $keys) {
                $start[$t] = ($start[$t] ?? []) + $keys;
                $why[$t] ??= $staff['why'][$t] ?? 'compte effacé';
            }
        }
        // Rows of the accounts and of a personal mailbox lead nowhere further: DataWipeStaff decided
        // for every row that names the account, and the mailbox's mail goes with it in the database
        [$delete, $unlink, $why, $blocked] = $this->closure($start, $why, $accounts ? array_flip(DataWipeMap::STAFF_TABLES) : [],
                                                             ['users' => true, 'mail_accounts' => true], $staff['unlink']);

        $reassign = [];
        $reassigned = [];
        $names = array_column(array_filter($picked, fn ($r) => isset($r['to'])), 'to_name', 'to');
        foreach ($staff['reassign'] as $t => $cols) {
            foreach ($cols as $c => $keys) {
                $keys = array_diff_key($keys, $delete[$t] ?? []);
                if (!$keys) {
                    continue;
                }
                $reassign[$t][$c] = $keys;
                foreach (array_count_values($keys) as $to => $n) {
                    $reassigned[] = ['table' => $t, 'column' => $c, 'rows' => $n, 'to' => $names[$to] ?? null];
                }
            }
        }
        $touched = array_keys(($reassign['journal_entries'] ?? []) ? array_replace(...array_values($reassign['journal_entries'])) : []);
        $touched = array_merge($touched, array_keys(($unlink['journal_entries'] ?? []) ? array_replace(...array_values($unlink['journal_entries'])) : []));
        foreach ($this->lockedYears($touched) as $y) {
            $blocked['name' . $y] = "L'exercice {$y} est verrouillé : ses écritures ne peuvent pas changer de nom. Rouvrez-le d'abord (Clôture d'exercice).";
        }

        $head = ['mode' => 'records', 'picked' => self::recordPick($picked), 'records' => $picked, 'reassign' => $reassign,
                 'reassigned' => $reassigned, 'mailboxes' => $staff['mailboxes']];
        $plan = $this->finish($head, $delete, $unlink, $why, array_merge($problems, $staff['blocked'], array_values($blocked)));
        $plan['numbering'] = $this->numberSteps($this->numberHeld($delete));
        return $plan;
    }

    /**
     * The numbered documents among the rows that go, by counter:
     * [type => [period => [n => ['reference', 'ts']]]]. A reference that doesn't match its
     * counter's format (one written before numbering) is left out: no counter holds it.
     */
    private function numberHeld(array $delete): array
    {
        if (!$this->hasTable('document_counters') || !class_exists('DocCounter')) {
            return [];
        }
        $held = [];
        foreach ($delete as $t => $keys) {
            $types = DocCounter::typesFor($t);
            if (!$types || !$this->hasColumn($t, 'reference') || $this->keyCols($t) !== ['id']) {
                continue;
            }
            // The date the number was taken with: the order's own date, otherwise the day it was saved
            $dates = array_values(array_filter($t === 'sales_orders' ? ['date', 'created_at'] : ['created_at', 'date'], fn ($c) => $this->hasColumn($t, $c)));
            if (!$dates) {
                continue;
            }
            foreach ($this->chunks(array_keys($keys)) as $chunk) {
                $rows = $this->db->query('SELECT `reference`, ' . $this->colList($dates) . " FROM `{$t}` WHERE id IN (" . implode(',', array_map('intval', $chunk)) . ')')->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $ref = (string) $row['reference'];
                    preg_match_all('/\d+/', $ref, $m);
                    foreach ($types as $type) {
                        foreach ($dates as $d) {
                            $ts = $row[$d] ? strtotime((string) $row[$d]) : false;
                            foreach ($ts ? $m[0] : [] as $digits) {
                                $n = (int) $digits;
                                if ($n > 0 && DocCounter::format($type, $n, $ts) === $ref) {
                                    $held[$type][DocCounter::periodOf($type, $ts)][$n] = ['reference' => $ref, 'ts' => $ts];
                                    continue 4;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $held;
    }

    /**
     * Owner's rule (3 Nov 2026): a counter whose last number goes steps back, so the next
     * document takes that number again; one from the middle leaves a gap. Says so in plain
     * words. With $undo (an open restore step) the counters are changed, locked first.
     */
    private function numberSteps(array $held, $undo = null): array
    {
        $notes = [];
        foreach ($held as $type => $periods) {
            foreach ($periods as $period => $nums) {
                $sel = $this->db->prepare('SELECT ' . $this->colList(array_unique(array_merge(['doc_type', 'period', 'last_number'], $this->stampCols('document_counters'))))
                                        . ' FROM document_counters WHERE doc_type = ? AND period = ?' . ($undo ? ' FOR UPDATE' : ''));
                $sel->execute([$type, (string) $period]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    continue;
                }
                $last = (int) $row['last_number'];
                $to = $last;
                while (isset($nums[$to])) {
                    $to--;
                }
                if ($to < $last) {
                    $back = array_map(fn ($n) => $nums[$n]['reference'], range($to + 1, $last));
                    $notes[] = ['kind' => 'back', 'text' => (count($back) > 1 ? implode(', ', $back) . ' étaient les derniers numéros' : $back[0] . ' était le dernier numéro')
                              . ' : ' . (DataWipeMap::COUNTER_NEXT[$type] ?? 'le prochain document') . ' reprendra ' . $back[0] . '.'];
                    if ($undo) {
                        fwrite($undo, $this->sqlUpdate('document_counters', [$row]));
                        $this->db->prepare("UPDATE document_counters SET last_number = ? WHERE doc_type = ? AND period = ?")->execute([$to, $type, (string) $period]);
                    }
                }
                ksort($nums);
                foreach ($nums as $n => $doc) {
                    if ($n < $to) {
                        $notes[] = ['kind' => 'gap', 'text' => $doc['reference'] . ' : des numéros plus récents existent, donc il restera un trou. Ce numéro ne sera pas réutilisé.'];
                    }
                }
            }
        }
        return $notes;
    }

    /** Search and label SQL of a kind of record, from the columns this database has; null when it can't be searched. */
    private function recordSql(string $t): ?array
    {
        if ($t === 'users') {
            if (!$this->hasTable('users') || !$this->hasColumn('users', 'status')) {
                return null;
            }
            $emp = $this->hasColumn('employees', 'employee_code');
            $code = $emp ? "COALESCE(NULLIF(x.employee_code, ''), e.employee_code)" : 'x.employee_code';
            return ['from' => '`users` x' . ($emp ? ' LEFT JOIN `employees` e ON e.id = x.employee_id' : ''),
                    'fields' => "CONCAT(x.first_name, ' ', x.last_name) AS label, {$code} AS who, NULL AS day, NULL AS amount, x.status AS status",
                    'match' => ["CONCAT(x.first_name, ' ', x.last_name)", 'x.email', $code]];
        }
        $def = DataWipeMap::RECORD_SEARCH[$t] ?? null;
        if ($def === null || !$this->hasTable($t) || !$this->hasColumn($t, $def[0]) || $this->keyCols($t) !== ['id']) {
            return null;
        }
        [$name, $second, $dates, $amount, $status] = $def;
        $from = "`{$t}` x";
        $match = ["x.`{$name}`"];
        $who = 'NULL';
        if (is_array($second) && $this->hasColumn($t, $second[0]) && $this->hasColumn($second[1], $second[2])) {
            $from .= " LEFT JOIN `{$second[1]}` o ON o.id = x.`{$second[0]}`";
            $who = "o.`{$second[2]}`";
            $match[] = $who;
        } elseif (is_string($second) && $this->hasColumn($t, $second)) {
            $who = "x.`{$second}`";
            $match[] = $who;
        }
        $day = 'NULL';
        foreach ($dates as $d) {
            if ($this->hasColumn($t, $d)) {
                $day = "x.`{$d}`";
                break;
            }
        }
        $amt = $amount && $this->hasColumn($t, $amount) ? "x.`{$amount}`" : 'NULL';
        $st = $status && $this->hasColumn($t, $status) ? "x.`{$status}`" : 'NULL';
        return ['from' => $from, 'fields' => "x.`{$name}` AS label, {$who} AS who, {$day} AS day, {$amt} AS amount, {$st} AS status", 'match' => $match];
    }

    /** "Alpha · 02/11/2026 · 100 000 · paid" */
    private static function recordSub(array $r): string
    {
        $parts = [];
        if (($r['who'] ?? null) !== null && $r['who'] !== '') {
            $parts[] = (string) $r['who'];
        }
        if (!empty($r['day']) && ($ts = strtotime((string) $r['day']))) {
            $parts[] = date('d/m/Y', $ts);
        }
        if ($r['amount'] !== null) {
            $parts[] = number_format((float) $r['amount'], 0, ',', ' ');
        }
        if (($r['status'] ?? null) !== null && $r['status'] !== '') {
            $parts[] = ['active' => 'actif', 'inactive' => 'inactif'][$r['status']] ?? (string) $r['status'];
        }
        return implode(' · ', $parts);
    }

    /** Keys of the rows of $table matching $where ([key => true]); with $column, that column's values instead. */
    public function rowKeys(string $table, string $where, array $params = [], ?string $column = null): array
    {
        $cols = $column !== null ? [$column] : $this->keyCols($table);
        if (!$cols || !$this->hasTable($table)) {
            return [];
        }
        $st = $this->db->prepare('SELECT ' . ($column !== null ? 'DISTINCT ' : '') . $this->colList($cols) . " FROM `{$table}` WHERE {$where}");
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) {
            if ($row[0] !== null) {
                $out[$this->keyOf($row)] = true;
            }
        }
        return $out;
    }

    /** The primary key columns of $table (none: [], and its rows can't be told apart). */
    public function keyColumnsOf(string $table): array
    {
        return $this->keyCols($table);
    }

    // =========================================================================
    // Run
    // =========================================================================

    /**
     * Wipe $tables. $user: ['id', 'name']. $inTransaction runs first inside the
     * transaction (the controller consumes the one-time code there, so a wipe
     * that fails leaves the code usable). Returns ['id', 'counts', 'total',
     * 'copy_file', 'expires', 'balances', 'emptied']. Throws, changing nothing,
     * when any of it fails.
     */
    public function run(array $tables, array $user, string $reason, ?callable $inTransaction = null): array
    {
        return $this->execute(fn () => $this->plan($tables), $user, $reason, $inTransaction);
    }

    /**
     * Delete records picked one by one (planRecords), the same way: one transaction, the copy
     * first. Before the rows go, the records of deleted staff accounts are handed over or their
     * names cleared; after, counters whose last number went step back. Also returns 'records'
     * (their labels) and 'numbering' (what the plan said).
     */
    public function runRecords(array $records, array $user, string $reason, ?callable $inTransaction = null): array
    {
        return $this->execute(fn () => $this->planRecords($records), $user, $reason, $inTransaction);
    }

    private function execute(callable $makePlan, array $user, string $reason, ?callable $inTransaction): array
    {
        $dir = self::copyDir();
        $work = $dir . '/tmp-' . bin2hex(random_bytes(8));
        if (!@mkdir($work . '/files', 0700, true)) {
            throw new RuntimeException("Le dossier des copies n'est pas inscriptible, donc rien n'a été effacé.");
        }
        $archive = null;
        $files = [];
        $db = $this->db;

        $db->beginTransaction();
        try {
            // One wipe at a time
            $db->query("SELECT id FROM data_wipe_lock WHERE id = 1 FOR UPDATE")->fetchColumn();
            if ($inTransaction) {
                $inTransaction($db);
            }

            $plan = $makePlan();
            $records = ($plan['mode'] ?? 'tables') === 'records';
            if (!$plan['picked']) {
                throw new DomainException($records ? 'Choisissez au moins un enregistrement.' : 'Cochez au moins une table.');
            }
            if ($plan['blocked']) {
                throw new DomainException(implode(' ', $plan['blocked']));
            }

            // The log names records by their label: the screen and the MD read it, ids alone say nothing
            $logged = $records ? json_encode(['mode' => 'records', 'records' => array_column($plan['records'], 'label'), 'picked' => $plan['picked']], JSON_UNESCAPED_UNICODE)
                    : (($plan['mode'] ?? '') === 'full'
                        ? json_encode(['mode' => 'full', 'options' => $plan['options'], 'picked' => $plan['picked']], JSON_UNESCAPED_UNICODE)
                        : json_encode($plan['picked']));
            $db->prepare("INSERT INTO data_wipes (user_id, user_name, picked, reason) VALUES (?, ?, ?, ?)")
               ->execute([(int) $user['id'], mb_substr((string) $user['name'], 0, 160), $logged, mb_substr($reason, 0, 1000)]);
            $id = (int) $db->lastInsertId();
            $db->exec('SET @lpc_current_user_id = ' . (int) $user['id']);
            $auditBefore = $this->hasTable('audit_logs') ? (int) $db->query("SELECT COALESCE(MAX(id), 0) FROM audit_logs")->fetchColumn() : 0;

            $steps = [];   // undo files, in the order the changes were made
            $step = function (string $name) use ($work, &$steps) {
                $f = $work . '/' . count($steps) . '-' . $name . '.sql';
                $steps[] = $f;
                return fopen($f, 'wb');
            };

            // 1. Links on rows that stay are cleared
            $out = $step('unlink');
            foreach ($plan['unlink'] as $t => $cols) {
                foreach ($cols as $c => $keys) {
                    foreach ($this->chunks(array_keys($keys)) as $chunk) {
                        [$where, $params] = $this->keyWhere($t, $chunk);
                        $pk = $this->keyCols($t);
                        $sel = $db->prepare('SELECT ' . $this->colList(array_unique(array_merge($pk, [$c], $this->stampCols($t)))) . " FROM `{$t}` WHERE {$where} FOR UPDATE");
                        $sel->execute($params);
                        fwrite($out, $this->sqlUpdate($t, $sel->fetchAll(PDO::FETCH_ASSOC)));
                        $db->prepare("UPDATE `{$t}` SET `{$c}` = NULL WHERE {$where}")->execute($params);
                    }
                }
            }
            fclose($out);

            // 1b. Records of a deleted staff account handed over to the account picked instead
            $out = $step('reassign');
            foreach ($plan['reassign'] ?? [] as $t => $cols) {
                foreach ($cols as $c => $keys) {
                    $byTarget = [];
                    foreach ($keys as $k => $to) {
                        $byTarget[(int) $to][] = $k;
                    }
                    foreach ($byTarget as $to => $list) {
                        foreach ($this->chunks($list) as $chunk) {
                            [$where, $params] = $this->keyWhere($t, $chunk);
                            $sel = $db->prepare('SELECT ' . $this->colList(array_unique(array_merge($this->keyCols($t), [$c], $this->stampCols($t)))) . " FROM `{$t}` WHERE {$where} FOR UPDATE");
                            $sel->execute($params);
                            fwrite($out, $this->sqlUpdate($t, $sel->fetchAll(PDO::FETCH_ASSOC)));
                            $db->prepare("UPDATE `{$t}` SET `{$c}` = ? WHERE {$where}")->execute(array_merge([$to], $params));
                        }
                    }
                }
            }
            fclose($out);

            // 2. Posted entries back to draft, or the accounting trigger refuses the delete
            $out = $step('drafts');
            if (!empty($plan['delete']['journal_entries'])) {
                foreach ($this->chunks(array_keys($plan['delete']['journal_entries'])) as $chunk) {
                    $in = implode(',', array_map('intval', $chunk));
                    $rows = $db->query('SELECT ' . $this->colList(array_unique(array_merge(['id', 'status'], $this->stampCols('journal_entries'))))
                                     . " FROM journal_entries WHERE id IN ({$in}) AND status <> 'draft' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
                    fwrite($out, $this->sqlUpdate('journal_entries', $rows));
                    if ($rows) {
                        $db->exec("UPDATE journal_entries SET status = 'draft' WHERE id IN (" . implode(',', array_map('intval', array_column($rows, 'id'))) . ")");
                    }
                }
            }
            fclose($out);

            // 3. What balances lose, and which numbers go, read before the rows go
            $balances = new DataWipeBalances($db);
            $prepared = $balances->prepare($plan);
            $held = $records ? $this->numberHeld($plan['delete']) : [];

            // 4. Copy, then delete, children before parents
            $out = $step('rows');
            $counts = [];
            foreach ($this->deleteOrder(array_keys($plan['delete'])) as $t) {
                $n = 0;
                foreach ($this->chunks(array_keys($plan['delete'][$t])) as $chunk) {
                    [$where, $params] = $this->keyWhere($t, $chunk);
                    $sel = $db->prepare('SELECT ' . $this->colList($this->copyCols($t)) . " FROM `{$t}` WHERE {$where} FOR UPDATE");
                    $sel->execute($params);
                    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
                    if (!$rows) {
                        continue;
                    }
                    fwrite($out, $this->sqlInsert($t, $rows));
                    foreach (DataWipeMap::FILES[$t] ?? [] as $col => $base) {
                        foreach ($rows as $r) {
                            if (($rel = self::filePath((string) ($r[$col] ?? ''), $base)) !== null) {
                                $files[$rel] = [$t, $col, (string) $r[$col]];
                            }
                        }
                    }
                    $del = $db->prepare("DELETE FROM `{$t}` WHERE {$where}");
                    $del->execute($params);
                    $n += $del->rowCount();
                }
                if ($n) {
                    $counts[$t] = $n;
                }
            }
            fclose($out);

            // 5. Balances match what is left
            $out = $step('balances');
            $summary = $balances->apply($prepared, $out);
            fclose($out);

            // 6. Numbering of documents whose table is now empty starts again at 0001
            $out = $step('counters');
            $emptied = [];
            foreach (array_keys($counts) as $t) {
                if (!$db->query("SELECT 1 FROM `{$t}` LIMIT 1")->fetchColumn()) {
                    $emptied[] = $t;
                }
            }
            $reset = [];
            if ($records) {
                // One by one: a counter whose last number went steps back; a number from the middle stays a gap
                $summary['numbering'] = $this->numberSteps($held, $out);
            } elseif (($plan['mode'] ?? '') === 'full' && $this->hasTable('document_counters')) {
                // The full reset: every series starts again at 00001 (decision 17, « numbering counters »)
                $rows = $db->query('SELECT ' . $this->colList($this->copyCols('document_counters')) . " FROM document_counters FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
                if ($rows) {
                    fwrite($out, $this->sqlInsert('document_counters', $rows));
                    $db->exec("DELETE FROM document_counters");
                    $reset = array_values(array_unique(array_column($rows, 'doc_type')));
                }
            } elseif ($this->hasTable('document_counters') && class_exists('DocCounter')) {
                foreach ($emptied as $t) {
                    foreach (DocCounter::typesFor($t) as $type) {
                        $sel = $db->prepare('SELECT ' . $this->colList($this->copyCols('document_counters')) . " FROM document_counters WHERE doc_type = ? FOR UPDATE");
                        $sel->execute([$type]);
                        if ($rows = $sel->fetchAll(PDO::FETCH_ASSOC)) {
                            fwrite($out, $this->sqlInsert('document_counters', $rows));
                            $db->prepare("DELETE FROM document_counters WHERE doc_type = ?")->execute([$type]);
                            $reset[] = $type;
                        }
                    }
                }
            }
            fclose($out);
            $summary['counters_reset'] = $reset;

            // The audit trigger noted the draft step on rows that are gone: not history worth keeping.
            // One by one, it also noted the hand-overs and an account's delete: the tool leaves no line there.
            if ($this->hasTable('audit_logs')) {
                $db->prepare("DELETE FROM audit_logs WHERE id > ? AND user_id <=> ?" . ($records ? '' : " AND table_name = 'journal_entries'"))
                   ->execute([$auditBefore, (int) $user['id']]);
            }

            // 7. The safety copy
            $restore = $work . '/restore.sql';
            $r = fopen($restore, 'wb');
            fwrite($r, "-- Bureau LPC : ce que l'effacement n° {$id} du " . date('d/m/Y à H:i') . " a retiré.\n"
                     . "-- L'importer dans la base de bureau (phpMyAdmin > Importer) remet tout comme avant. Voir LISEZMOI.txt.\n"
                     . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
            foreach (array_reverse($steps) as $f) {
                $in = fopen($f, 'rb');
                stream_copy_to_stream($in, $r);
                fclose($in);
            }
            fwrite($r, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
            fclose($r);
            foreach ($steps as $f) {
                @unlink($f);
            }
            foreach (array_keys($files) as $rel) {
                $src = self::appRoot() . '/' . $rel;
                if (is_file($src)) {
                    @mkdir(dirname($work . '/files/' . $rel), 0700, true);
                    if (!copy($src, $work . '/files/' . $rel)) {
                        throw new RuntimeException("Le fichier {$rel} n'a pas pu être copié, donc rien n'a été effacé.");
                    }
                }
            }
            file_put_contents($work . '/LISEZMOI.txt', $this->readme($id, $plan, $user, $reason, $counts, $summary));
            $archive = $this->pack($work, $dir, $id);

            $expires = date('Y-m-d H:i:s', strtotime('+' . self::KEEP_DAYS . ' days'));
            $db->prepare("UPDATE data_wipes SET counts = ?, total = ?, balances = ?, copy_file = ?, copy_size = ?, copy_sha256 = ?, copy_expires_at = ? WHERE id = ?")
               ->execute([json_encode(['deleted' => $counts, 'unlinked' => $plan['unlinked']]), array_sum($counts), json_encode($summary, JSON_UNESCAPED_UNICODE),
                          basename($archive), filesize($archive), hash_file('sha256', $archive), $expires, $id]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($archive && is_file($archive)) {
                @unlink($archive);
            }
            throw $e;
        } finally {
            $db->exec('SET @lpc_current_user_id = NULL');
            self::removeTree($work);
        }

        // After the commit: files nothing uses, numbering of emptied tables, nothing that could undo the wipe.
        // Problems here are reported on the screen, never in the error log admins read.
        $warnings = $this->forgetFiles($files);
        foreach ($emptied as $t) {
            try {
                $db->exec("ALTER TABLE `{$t}` AUTO_INCREMENT = 1");
            } catch (Throwable $e) {
                $warnings[] = "Numérotation de {$t} : " . $e->getMessage();
            }
        }

        $done = ['id' => $id, 'counts' => $counts, 'total' => array_sum($counts), 'copy_file' => basename($archive),
                 'expires' => $expires, 'balances' => $summary, 'emptied' => $emptied, 'picked' => $plan['picked'], 'warnings' => $warnings];
        if (($plan['mode'] ?? '') === 'full') {
            $done += ['mode' => 'full', 'options' => $plan['options']];
        }
        if ($records) {
            $done += ['mode' => 'records', 'records' => array_column($plan['records'], 'label'), 'numbering' => $summary['numbering'] ?? [],
                      'reassigned' => $plan['reassigned'], 'mailboxes' => $plan['mailboxes']];
        }
        return $done;
    }

    /** What a logged wipe took, as the history list says it ("Factures, Paiements" or "Un par un : Facture FAC-…"). */
    public static function pickedText(string $json): string
    {
        $picked = json_decode($json, true) ?: [];
        if (($picked['mode'] ?? null) === 'records') {
            return 'Un par un : ' . implode(', ', (array) ($picked['records'] ?? []));
        }
        if (($picked['mode'] ?? null) === 'full') {
            $o = (array) ($picked['options'] ?? []);
            return 'Remise à zéro avant démarrage (produits ' . (!empty($o['products']) ? 'gardés' : 'effacés')
                 . ', fournisseurs ' . (!empty($o['suppliers']) ? 'gardés' : 'effacés') . ')';
        }
        return implode(', ', array_map([DataWipeMap::class, 'label'], $picked));
    }

    // =========================================================================
    // Copies
    // =========================================================================

    /**
     * Where safety copies live: WIPE_COPY_DIR, else bureau_wipe_copies two
     * levels above the app (the cPanel home, next to public_html), never
     * inside the web root.
     */
    public static function copyDir(): string
    {
        $dir = rtrim(str_replace('\\', '/', (string) (function_exists('env') ? env('WIPE_COPY_DIR', '') : '')), '/');
        if ($dir === '') {
            $dir = str_replace('\\', '/', dirname(self::appRoot(), 2)) . '/bureau_wipe_copies';
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new RuntimeException("Le dossier des copies ({$dir}) ne peut pas être créé, donc rien n'a été effacé.");
        }
        $real = str_replace('\\', '/', (string) realpath($dir));
        $root = str_replace('\\', '/', (string) realpath(self::appRoot()));
        if ($real === '' || str_starts_with($real . '/', $root . '/')) {
            throw new RuntimeException('Le dossier des copies doit être hors du site, donc rien n\'a été effacé.');
        }
        return $real;
    }

    /** The full path of a wipe's copy while it is kept, else null. */
    public static function copyPath(array $wipe): ?string
    {
        $name = (string) ($wipe['copy_file'] ?? '');
        if ($name === '' || !preg_match('/^wipe-\d+-[a-f0-9]+\.tar\.gz$/', $name)) {
            return null;
        }
        try {
            $path = self::copyDir() . '/' . $name;
        } catch (Throwable $e) {
            return null;
        }
        return is_file($path) ? $path : null;
    }

    /** The cron: copies past KEEP_DAYS are deleted. Returns how many. */
    public static function purgeExpired(PDO $db): int
    {
        $n = 0;
        foreach ($db->query("SELECT id, copy_file FROM data_wipes WHERE copy_file IS NOT NULL AND copy_expires_at < NOW()")->fetchAll(PDO::FETCH_ASSOC) as $w) {
            if ($path = self::copyPath($w)) {
                @unlink($path);
            }
            $db->prepare("UPDATE data_wipes SET copy_file = NULL, copy_deleted_at = NOW() WHERE id = ?")->execute([$w['id']]);
            $n++;
        }
        return $n;
    }

    public static function history(PDO $db, int $limit = 50): array
    {
        return $db->query("SELECT * FROM data_wipes ORDER BY id DESC LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // Internals: schema, edges, keys
    // =========================================================================

    private function schema(): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }
        $db = $this->db;
        $tables = [];
        foreach ($db->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'") as $r) {
            $tables[$r['t']] = true;
        }
        $cols = [];
        foreach ($db->query("SELECT table_name AS t, column_name AS c, is_nullable AS n, extra AS x FROM information_schema.columns
                              WHERE table_schema = DATABASE() ORDER BY table_name, ordinal_position") as $r) {
            if (isset($tables[$r['t']])) {
                // Generated columns can't be inserted; MySQL 8 also says DEFAULT_GENERATED for a plain
                // DEFAULT CURRENT_TIMESTAMP, which must be copied like any value
                $cols[$r['t']][$r['c']] = ['nullable' => $r['n'] === 'YES',
                                           'generated' => (bool) preg_match('/\b(VIRTUAL|STORED|PERSISTENT)\s+GENERATED\b/i', (string) $r['x']),
                                           'onupdate' => stripos((string) $r['x'], 'on update') !== false];
            }
        }
        $keys = [];
        foreach ($db->query("SELECT table_name AS t, column_name AS c FROM information_schema.key_column_usage
                              WHERE table_schema = DATABASE() AND constraint_name = 'PRIMARY' ORDER BY table_name, ordinal_position") as $r) {
            $keys[$r['t']][] = $r['c'];
        }
        $fks = [];
        $byConstraint = [];
        foreach ($db->query("SELECT k.table_name AS child, k.column_name AS col, k.referenced_table_name AS parent, k.referenced_column_name AS ref,
                                    k.constraint_name AS name, r.delete_rule AS rule
                               FROM information_schema.key_column_usage k
                               JOIN information_schema.referential_constraints r
                                 ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name AND r.table_name = k.table_name
                              WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL") as $r) {
            $byConstraint[$r['child'] . '/' . $r['name']][] = $r;
        }
        foreach ($byConstraint as $parts) {
            if (count($parts) === 1) {   // composite foreign keys don't occur in bureau; skipped rather than guessed
                $fks[] = $parts[0];
            }
        }
        return $this->schema = ['tables' => $tables, 'cols' => $cols, 'keys' => $keys, 'fks' => $fks];
    }

    /** Every way a wiped row leads to others: [from table => [edge]] */
    private function edges(): array
    {
        if ($this->edges !== null) {
            return $this->edges;
        }
        $s = $this->schema();
        $edges = [];
        $add = function (array $e) use (&$edges, $s) {
            if (isset($s['tables'][$e['from']], $s['tables'][$e['to']]) && !empty($s['keys'][$e['to']])) {
                $edges[$e['from']][] = $e;
            }
        };

        foreach ($s['fks'] as $fk) {
            $child = $fk['child'];
            $col = $fk['col'];
            if (!isset($s['cols'][$child][$col])) {
                continue;
            }
            $nullable = $s['cols'][$child][$col]['nullable'];
            $rule = strtoupper((string) $fk['rule']);
            if ($fk['parent'] === 'journal_entries' && !DataWipeMap::isProtected($child)) {
                // The document owns the entry it posted; an entry wiped on its own leaves the document
                $add(['from' => $child, 'to' => 'journal_entries', 'mode' => 'delete', 'owned' => true, 'why' => 'écriture du document',
                      'sql' => "SELECT `{$col}` FROM `{$child}` WHERE %KEYS% AND `{$col}` IS NOT NULL", 'keysOf' => $child]);
            }
            if (DataWipeMap::isProtected($child)) {
                $mode = $nullable ? 'unlink' : 'block';
            } elseif ($rule === 'SET NULL') {
                $mode = 'unlink';
            } elseif (!$nullable || $rule === 'CASCADE' || in_array("{$child}.{$col}", DataWipeMap::NULLABLE_BUT_DEPENDENT, true)) {
                $mode = 'delete';
            } else {
                $mode = 'unlink';
            }
            $add(['from' => $fk['parent'], 'to' => $child, 'mode' => $mode, 'col' => $col, 'ref' => $fk['ref'], 'why' => 'lié par ' . $col, 'fk' => true]);
        }

        foreach (DataWipeMap::VIRTUAL_FKS as [$child, $col, $parent, $mode]) {
            if (isset($s['cols'][$child][$col], $s['keys'][$parent]) && count($s['keys'][$parent]) === 1) {
                $add(['from' => $parent, 'to' => $child, 'mode' => $mode, 'col' => $col, 'ref' => $s['keys'][$parent][0], 'why' => 'lié par ' . $col, 'fk' => true]);
            }
        }
        foreach (DataWipeMap::WHOLE as [$line, $col, $doc]) {
            if (isset($s['cols'][$line][$col])) {
                $add(['from' => $line, 'to' => $doc, 'mode' => 'delete', 'why' => 'document dont une ligne part',
                      'sql' => "SELECT DISTINCT `{$col}` FROM `{$line}` WHERE %KEYS% AND `{$col}` IS NOT NULL", 'keysOf' => $line]);
            }
        }
        foreach (DataWipeMap::OWNED as [$owner, $col, $owned, $cond]) {
            if (isset($s['cols'][$owner][$col])) {
                $pk = $s['keys'][$owned][0] ?? 'id';
                $not = "NOT EXISTS (SELECT 1 FROM `{$owner}` x WHERE x.`{$col}` = o.`{$pk}` AND NOT (%XKEYS%))";
                // A sub-account goes only when no entry will be left on it (the owner's decision 13,
                // 18/11/2026): every line on it must come from a document's own entry (JOURNAL_SOURCES,
                // a client payment), which goes with the document. A manual OD keeps the account.
                if ($owned === 'chart_of_accounts' && isset($s['cols']['journal_lines']['account_id'])) {
                    if (!$this->full) {
                        $not .= ' AND ' . $this->noEntryLeftOn('o.`' . $pk . '`');
                    }
                }
                // Never a parent's own row (« 411 Clients », « 5211 »'s parent…), whatever points at it:
                // only the sub-accounts made for a client, supplier, lender or treasury account go.
                if ($owned === 'chart_of_accounts' && isset($s['cols']['chart_of_accounts']['ohada_account_id'], $s['tables']['ohada_accounts'])) {
                    $not .= " AND NOT EXISTS (SELECT 1 FROM ohada_accounts pa WHERE pa.id = o.ohada_account_id AND pa.account_number = o.code)";
                }
                $add(['from' => $owner, 'to' => $owned, 'mode' => 'delete', 'owned' => true, 'why' => 'sous-compte / écriture propre',
                      'sql' => "SELECT o.`{$pk}` FROM `{$owned}` o JOIN `{$owner}` w ON w.`{$col}` = o.`{$pk}` WHERE %WKEYS%"
                             . ($cond !== '' ? " AND {$cond}" : '') . " AND {$not}", 'keysOf' => $owner]);
            }
        }
        foreach (DataWipeMap::softLinks() as $l) {
            $add(['from' => $l['parent'], 'to' => $l['child'], 'mode' => 'delete', 'why' => $l['why'], 'soft' => $l['sql']]);
        }
        // Notifications and activity lines about a wiped row
        foreach (array_keys($s['tables']) as $t) {
            if (DataWipeMap::isProtected($t) || empty($s['keys'][$t]) || count($s['keys'][$t]) !== 1) {
                continue;
            }
            foreach (['audit_logs', 'audit_logs_archive'] as $log) {
                if (isset($s['cols'][$log]['table_name'], $s['cols'][$log]['record_id'])) {
                    $add(['from' => $t, 'to' => $log, 'mode' => 'delete', 'why' => 'activité sur l\'enregistrement',
                          'soft' => "SELECT id FROM `{$log}` WHERE table_name = " . $this->db->quote($t) . " AND record_id IN ({ids})"]);
                }
            }
            foreach (['notifications', 'notifications_archive'] as $box) {
                if (isset(DataWipeMap::NOTIFICATION_URLS[$t]) && isset($s['cols'][$box]['related_url'])) {
                    $url = $this->db->quote(DataWipeMap::NOTIFICATION_URLS[$t]);
                    $add(['from' => $t, 'to' => $box, 'mode' => 'delete', 'why' => 'notification sur le document', 'idsOnly' => true,
                          'soft' => "SELECT id FROM `{$box}` WHERE related_url IN (SELECT CONCAT({$url}, x) FROM (SELECT {ids_rows}) z)"]);
                }
                if (in_array($t, DataWipeMap::NAMED_BY_REFERENCE, true) && isset($s['cols'][$t]['reference'], $s['cols'][$box]['message'])) {
                    $add(['from' => $t, 'to' => $box, 'mode' => 'delete', 'why' => 'notification qui cite le document',
                          // The reference as a whole word: REC-2611-0001 must not match REC-2611-00010
                          'soft' => "SELECT n.id FROM `{$box}` n JOIN `{$t}` d ON CONCAT(n.title, ' ', n.message) LIKE CONCAT('%', d.reference, '%')
                                      WHERE d.id IN ({ids}) AND d.reference <> ''
                                        AND CONCAT(n.title, ' ', n.message, ' ') REGEXP CONCAT(d.reference, '[^0-9A-Za-z]')"]);
                }
            }
        }
        return $this->edges = $edges;
    }

    /** The keys of `to` rows an edge reaches from these keys of `from`. */
    private function follow(array $e, array $keys): array
    {
        $found = [];
        foreach ($this->chunks($keys) as $chunk) {
            if (!empty($e['fk'])) {
                $vals = $this->refValues($e['from'], $e['ref'], $chunk);
                if (!$vals) {
                    continue;
                }
                $pk = $this->keyCols($e['to']);
                $st = $this->db->prepare('SELECT ' . $this->colList($pk) . " FROM `{$e['to']}` WHERE `{$e['col']}` IN (" . rtrim(str_repeat('?,', count($vals)), ',') . ')');
                $st->execute($vals);
                foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) {
                    $found[] = $this->keyOf($row);
                }
                continue;
            }
            if (isset($e['soft'])) {
                $ints = array_map('intval', $chunk);
                $sql = str_replace(['{ids_rows}', '{ids}'], [implode(' UNION ALL SELECT ', array_map(fn ($i) => "{$i} AS x", $ints)), implode(',', $ints)], $e['soft']);
                foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $v) {
                    if ($v !== null) {
                        $found[] = $this->keyOf([$v]);
                    }
                }
                continue;
            }
            // %KEYS% / %WKEYS% / %XKEYS%: these rows of keysOf, unaliased / as w / as x (each the same params)
            $sql = $e['sql'];
            $params = [];
            foreach (['%KEYS%' => '', '%WKEYS%' => 'w', '%XKEYS%' => 'x'] as $ph => $alias) {
                if ($n = substr_count($sql, $ph)) {
                    [$where, $p] = $this->keyWhere($e['keysOf'], $chunk, $alias);
                    $sql = str_replace($ph, $where, $sql);
                    for ($i = 0; $i < $n; $i++) {
                        array_push($params, ...$p);
                    }
                }
            }
            $st = $this->db->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
                if ($v !== null) {
                    $found[] = $this->keyOf([$v]);
                }
            }
        }
        return array_values(array_unique($found));
    }

    /** Values of `col` on these rows of `table` (the column a foreign key points at). */
    private function refValues(string $table, string $col, array $keys): array
    {
        $pk = $this->keyCols($table);
        if ($pk === [$col]) {
            return $keys;
        }
        [$where, $params] = $this->keyWhere($table, $keys);
        $st = $this->db->prepare("SELECT DISTINCT `{$col}` FROM `{$table}` WHERE {$where} AND `{$col}` IS NOT NULL");
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Children before parents, by the foreign keys among the tables wiped. */
    private function deleteOrder(array $tables): array
    {
        $in = array_flip($tables);
        $parentsOf = array_fill_keys($tables, []);
        foreach ($this->schema()['fks'] as $fk) {
            if (isset($in[$fk['child']], $in[$fk['parent']]) && $fk['child'] !== $fk['parent']) {
                $parentsOf[$fk['child']][$fk['parent']] = true;
            }
        }
        $childrenLeft = array_fill_keys($tables, 0);
        foreach ($parentsOf as $child => $parents) {
            foreach (array_keys($parents) as $p) {
                $childrenLeft[$p]++;
            }
        }
        $order = [];
        $ready = array_keys(array_filter($childrenLeft, fn ($n) => $n === 0));
        sort($ready);
        while ($ready) {
            $t = array_shift($ready);
            $order[] = $t;
            foreach (array_keys($parentsOf[$t]) as $p) {
                if (--$childrenLeft[$p] === 0) {
                    $ready[] = $p;
                }
            }
        }
        // A cycle (none known in bureau): what is left goes last, the database says if it can't
        foreach ($tables as $t) {
            if (!in_array($t, $order, true)) {
                $order[] = $t;
            }
        }
        return $order;
    }

    private function allKeys(string $table): array
    {
        $keys = [];
        foreach ($this->db->query('SELECT ' . $this->colList($this->keyCols($table)) . " FROM `{$table}`")->fetchAll(PDO::FETCH_NUM) as $row) {
            $keys[$this->keyOf($row)] = true;
        }
        return $keys;
    }

    private function count(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }

    private function keyCols(string $table): array
    {
        return $this->schema()['keys'][$table] ?? [];
    }

    /** A row's key: the value, or the values joined by \x1F for a composite key. */
    private function keyOf(array $values): string|int
    {
        if (count($values) === 1) {
            $v = (string) $values[0];
            return $v === (string) (int) $v ? (int) $v : $v;
        }
        return implode("\x1F", array_map('strval', $values));
    }

    /** [SQL condition, params] matching these keys of $table. */
    private function keyWhere(string $table, array $keys, string $alias = ''): array
    {
        $pk = $this->keyCols($table);
        $a = $alias !== '' ? $alias . '.' : '';
        if (count($pk) === 1) {
            return ["{$a}`{$pk[0]}` IN (" . rtrim(str_repeat('?,', count($keys)), ',') . ')', array_values($keys)];
        }
        $tuple = '(' . rtrim(str_repeat('?,', count($pk)), ',') . ')';
        $params = [];
        foreach ($keys as $k) {
            array_push($params, ...explode("\x1F", (string) $k));
        }
        $cols = implode(', ', array_map(fn ($c) => "{$a}`{$c}`", $pk));
        return ["({$cols}) IN (" . rtrim(str_repeat($tuple . ',', count($keys)), ',') . ')', $params];
    }

    private function copyCols(string $table): array
    {
        return array_keys(array_filter($this->schema()['cols'][$table] ?? [], fn ($c) => !$c['generated']));
    }

    /** Columns MySQL stamps on every UPDATE (ON UPDATE CURRENT_TIMESTAMP): put back too, or a restore would date them today. */
    private function stampCols(string $table): array
    {
        return array_keys(array_filter($this->schema()['cols'][$table] ?? [], fn ($c) => $c['onupdate']));
    }

    private function colList(array $cols): string
    {
        return implode(', ', array_map(fn ($c) => "`{$c}`", $cols));
    }

    private function chunks(array $keys): array
    {
        return $keys ? array_chunk($keys, self::CHUNK) : [];
    }

    public function hasTable(string $table): bool
    {
        return isset($this->schema()['tables'][$table]);
    }

    public function hasColumn(string $table, string $col): bool
    {
        return isset($this->schema()['cols'][$table][$col]);
    }

    // =========================================================================
    // Internals: restore.sql, files, archive
    // =========================================================================

    /** INSERT IGNORE statements putting $rows back, 100 a statement. */
    private function sqlInsert(string $table, array $rows): string
    {
        if (!$rows) {
            return '';
        }
        $cols = $this->colList(array_keys($rows[0]));
        $sql = '';
        foreach (array_chunk($rows, 100) as $chunk) {
            $values = array_map(fn ($row) => '(' . implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $this->db->quote((string) $v), $row)) . ')', $chunk);
            $sql .= "INSERT IGNORE INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $values) . ";\n";
        }
        return $sql;
    }

    /** UPDATE statements putting back the columns of $rows (key columns first) as they were. */
    public function sqlUpdate(string $table, array $rows): string
    {
        $pk = $this->keyCols($table);
        $sql = '';
        foreach ($rows as $row) {
            $where = implode(' AND ', array_map(fn ($c) => "`{$c}` = " . $this->db->quote((string) $row[$c]), $pk));
            $set = [];
            foreach ($row as $c => $v) {
                if (!in_array($c, $pk, true)) {
                    $set[] = "`{$c}` = " . ($v === null ? 'NULL' : $this->db->quote((string) $v));
                }
            }
            if ($set) {
                $sql .= "UPDATE `{$table}` SET " . implode(', ', $set) . " WHERE {$where};\n";
            }
        }
        return $sql;
    }

    /** uploads/… path of a stored value, or null when it isn't a file under uploads/. */
    public static function filePath(string $value, string $base): ?string
    {
        $v = ltrim(str_replace('\\', '/', trim($value)), '/');
        if ($v === '' || str_contains($v, '..') || str_starts_with($v, 'data:')) {
            return null;
        }
        if (!str_starts_with($v, 'uploads/')) {
            $v = rtrim($base, '/') . '/' . $v;
        }
        return preg_match('~^uploads/[A-Za-z0-9_./\-]+$~', $v) ? $v : null;
    }

    /** Delete the wiped rows' files that no remaining row uses. Returns what went wrong, if anything. */
    private function forgetFiles(array $files): array
    {
        $warnings = [];
        $root = realpath(self::appRoot() . '/uploads');
        foreach ($files as $rel => [$t, $col, $raw]) {
            try {
                $st = $this->db->prepare("SELECT COUNT(*) FROM `{$t}` WHERE `{$col}` = ?");
                $st->execute([$raw]);
                if ((int) $st->fetchColumn() > 0) {
                    continue;
                }
                $full = realpath(self::appRoot() . '/' . $rel);
                if ($root && $full && str_starts_with($full, $root . DIRECTORY_SEPARATOR) && is_file($full)) {
                    @unlink($full);
                }
            } catch (Throwable $e) {
                $warnings[] = "Fichier {$rel} : " . $e->getMessage();
            }
        }
        return $warnings;
    }

    /** $work packed as wipe-<id>-<random>.tar.gz in $dir (PharData: the server has no zip extension guaranteed). */
    private function pack(string $work, string $dir, int $id): string
    {
        $tar = $dir . '/wipe-' . $id . '-' . bin2hex(random_bytes(10)) . '.tar';
        try {
            $phar = new PharData($tar);
            $phar->buildFromDirectory($work);
            $phar->compress(Phar::GZ);
            unset($phar);
        } catch (Throwable $e) {
            throw new RuntimeException('La copie de sécurité n\'a pas pu être écrite, donc rien n\'a été effacé. (' . $e->getMessage() . ')');
        } finally {
            if (is_file($tar)) {
                @unlink($tar);
            }
        }
        $gz = $tar . '.gz';
        if (!is_file($gz) || filesize($gz) === 0) {
            throw new RuntimeException('La copie de sécurité est vide, donc rien n\'a été effacé.');
        }
        @chmod($gz, 0600);
        return $gz;
    }

    private function readme(int $id, array $plan, array $user, string $reason, array $counts, array $summary): string
    {
        $lines = [];
        arsort($counts);
        foreach ($counts as $t => $n) {
            $lines[] = '  ' . mb_str_pad(DataWipeMap::label($t), 40) . ' ' . $n;
        }
        $what = ($plan['mode'] ?? 'tables') === 'records'
            ? "Effacés un par un : " . implode(', ', array_column($plan['records'], 'label')) . ".\r\n"
              . ($plan['mailboxes'] ? "Boîtes mail personnelles retirées (la copie de bureau ; le courrier reste sur le serveur mail, il n'est pas dans cette copie) : "
                                      . implode(', ', array_column($plan['mailboxes'], 'email')) . ".\r\n" : '')
            : "Tables cochées : " . implode(', ', array_map([DataWipeMap::class, 'label'], $plan['picked'])) . ".\r\n";
        return "Bureau LPC : effacement n° {$id}, le " . date('d/m/Y à H:i') . ", par " . $user['name'] . ".\r\n"
             . $what
             . "Motif : {$reason}\r\n\r\n"
             . "Lignes effacées :\r\n" . implode("\r\n", $lines) . "\r\n\r\n"
             . "restore.sql   remet tout comme avant l'effacement : les lignes, les liens et les\r\n"
             . "              soldes recalculés. Dans phpMyAdmin, ouvrez la base de bureau, puis\r\n"
             . "              Importer ce fichier. À faire AVANT de saisir de nouvelles données :\r\n"
             . "              ensuite, les nouveaux enregistrements et ceux-ci partageraient des numéros.\r\n"
             . "              L'import ajoute au journal d'activité une ligne « créé » par facture,\r\n"
             . "              encaissement et écriture remis.\r\n"
             . "files/        les PDF et justificatifs effacés. Les remettre dans le dossier du site\r\n"
             . "              (public_html/bureau.lpc.cm) en gardant les dossiers tels quels.\r\n\r\n"
             . "Cette copie est gardée " . self::KEEP_DAYS . " jours après l'effacement, puis supprimée.\r\n";
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private static function appRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
