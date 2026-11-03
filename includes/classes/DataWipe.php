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

final class DataWipe
{
    public const KEEP_DAYS = 30;
    private const CHUNK = 500;

    private PDO $db;
    /** @var array{tables: array, cols: array, keys: array, fks: array}|null */
    private ?array $schema = null;
    private ?array $edges = null;

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
        $delete = [];
        $unlink = [];
        $why = [];
        $blocked = [];
        $frontier = [];

        foreach ($picked as $t) {
            $keys = $this->allKeys($t);
            $why[$t] = 'coché';
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
                    if ($e['mode'] === 'block' || (DataWipeMap::isProtected($e['to']) && empty($e['owned']))) {
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
        if (!empty($delete['journal_entries']) && $this->hasColumn('financial_years', 'state')) {
            foreach ($this->chunks(array_keys($delete['journal_entries'])) as $ids) {
                $years = $this->db->query("SELECT DISTINCT YEAR(je.date) FROM journal_entries je JOIN financial_years fy ON fy.year = YEAR(je.date)
                                            WHERE je.id IN (" . implode(',', array_map('intval', $ids)) . ") AND fy.state IN ('locked', 'final_closed')")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($years as $y) {
                    $blocked['year' . $y] = "L'exercice {$y} est verrouillé : ses écritures ne peuvent pas être effacées. Rouvrez-le d'abord (Clôture d'exercice).";
                }
            }
        }

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

        $plan = [
            'picked' => $picked, 'delete' => $delete, 'unlink' => $unlink, 'why' => $why, 'counts' => $counts,
            'unlinked' => $unlinked, 'total' => array_sum($counts), 'blocked' => array_values($blocked),
        ];
        $plan['balances'] = (new DataWipeBalances($this->db))->preview($plan);

        return $plan;
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

            $plan = $this->plan($tables);
            if (!$plan['picked']) {
                throw new DomainException('Cochez au moins une table.');
            }
            if ($plan['blocked']) {
                throw new DomainException(implode(' ', $plan['blocked']));
            }

            $db->prepare("INSERT INTO data_wipes (user_id, user_name, picked, reason) VALUES (?, ?, ?, ?)")
               ->execute([(int) $user['id'], mb_substr((string) $user['name'], 0, 160), json_encode($plan['picked']), mb_substr($reason, 0, 1000)]);
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

            // 3. What balances lose, read before the rows go
            $balances = new DataWipeBalances($db);
            $prepared = $balances->prepare($plan);

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
            if ($this->hasTable('document_counters') && class_exists('DocCounter')) {
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

            // The audit trigger noted the draft step on rows that are gone: not history worth keeping
            if ($this->hasTable('audit_logs')) {
                $db->prepare("DELETE FROM audit_logs WHERE id > ? AND user_id <=> ? AND table_name = 'journal_entries'")
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
            file_put_contents($work . '/LISEZMOI.txt', $this->readme($id, $plan['picked'], $user, $reason, $counts, $summary));
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

        return ['id' => $id, 'counts' => $counts, 'total' => array_sum($counts), 'copy_file' => basename($archive),
                'expires' => $expires, 'balances' => $summary, 'emptied' => $emptied, 'picked' => $plan['picked'], 'warnings' => $warnings];
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

    private function readme(int $id, array $picked, array $user, string $reason, array $counts, array $summary): string
    {
        $lines = [];
        arsort($counts);
        foreach ($counts as $t => $n) {
            $lines[] = '  ' . mb_str_pad(DataWipeMap::label($t), 40) . ' ' . $n;
        }
        return "Bureau LPC : effacement n° {$id}, le " . date('d/m/Y à H:i') . ", par " . $user['name'] . ".\r\n"
             . "Tables cochées : " . implode(', ', array_map([DataWipeMap::class, 'label'], $picked)) . ".\r\n"
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
