<?php
/**
 * includes/classes/DataWipeStaff.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — deleting an inactive staff account from the data wipe tool's
 * "one by one" tab (owner's decisions of 3 Nov 2026; docs/DATA_WIPE.md). Read
 * by DataWipe::planRecords().
 *
 * THE PERSON
 *   The login and the employee record go together: matricule, contract,
 *   payslips, advances, profile, sessions, devices, preferences, notifications
 *   (DataWipeMap::STAFF_OWN), with what depends on them (a payslip's treasury
 *   movement and entry). Courrier: the person's own mailbox goes, bureau's copy
 *   only (the mail stays on the mail server, and the safety copy keeps the
 *   mailbox's settings, never its messages); so do the person's access to
 *   shared mailboxes, signature and presence.
 *
 * ITS RECORDS: every other row that names the account, in any of the ~150
 *   columns holding a staff id (most without a foreign key, so they are found
 *   by name too: DataWipeMap::STAFF_COLUMN_PATTERN). For each account:
 *     · hand over: the column is set to the active account picked instead,
 *       activity lines, notes and assignments in shared mailboxes included;
 *     · wipe them too: a row the account made (created, logged, operated,
 *       drove), or one that can't be kept without it (NOT NULL), goes with what
 *       depends on it; a row it only approved, validated or last edited stays,
 *       its name cleared; its activity lines and its notes in shared mailboxes
 *       go. A protected row that needs the name (a treasury account it created)
 *       refuses the wipe, with the reason: hand over that account instead.
 *
 * WHO CAN'T BE DELETED HERE
 *   An active account (deactivate it in Données de Base first), the
 *   WIPE_ADMIN_EMAIL account, and the MD's (matricule LPC-MD-01).
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

require_once __DIR__ . '/DataWipeMap.php';
require_once __DIR__ . '/DataWipeAccess.php';

final class DataWipeStaff
{
    private PDO $db;
    private DataWipe $wipe;
    private ?array $columns = null;

    public function __construct(PDO $db, DataWipe $wipe)
    {
        $this->db = $db;
        $this->wipe = $wipe;
    }

    /** Every column holding a staff id: ['table.column' => ['table', 'column', 'nullable']]. */
    public function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }
        $fk = [];
        foreach ($this->db->query("SELECT table_name AS t, column_name AS c FROM information_schema.key_column_usage
                                    WHERE table_schema = DATABASE() AND referenced_table_name = 'users' AND referenced_column_name = 'id'") as $r) {
            $fk[$r['t'] . '.' . $r['c']] = true;
        }
        $out = [];
        foreach ($this->db->query("SELECT c.table_name AS t, c.column_name AS c, c.is_nullable AS n, c.data_type AS d
                                     FROM information_schema.columns c
                                     JOIN information_schema.tables b ON b.table_schema = c.table_schema AND b.table_name = c.table_name AND b.table_type = 'BASE TABLE'
                                    WHERE c.table_schema = DATABASE() ORDER BY c.table_name, c.ordinal_position") as $r) {
            $tc = $r['t'] . '.' . $r['c'];
            $int = in_array(strtolower((string) $r['d']), ['int', 'integer', 'bigint', 'mediumint', 'smallint'], true);
            if (in_array($r['t'], DataWipeMap::STAFF_IGNORED, true) || !(isset($fk[$tc]) || ($int && preg_match(DataWipeMap::STAFF_COLUMN_PATTERN, $r['c'])))) {
                continue;
            }
            $out[$tc] = ['table' => $r['t'], 'column' => $r['c'], 'nullable' => $r['n'] === 'YES'];
        }
        return $this->columns = $out;
    }

    /** An account as the screen and the checks need it, or null. */
    public function account(int $id): ?array
    {
        $st = $this->db->prepare("SELECT u.id, u.status, u.email, CONCAT(u.first_name, ' ', u.last_name) AS name,
                                         COALESCE(NULLIF(u.employee_code, ''), e.employee_code) AS matricule, u.employee_id
                                    FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Why this account can't be deleted here, or null. */
    public function refusal(array $account): ?string
    {
        $wipeEmail = strtolower(trim((string) env('WIPE_ADMIN_EMAIL', '')));
        if ($wipeEmail !== '' && strtolower(trim((string) $account['email'])) === $wipeEmail) {
            return 'C\'est le compte qui efface : il ne peut pas être effacé.';
        }
        $st = $this->db->prepare("SELECT 1 FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.id = ? AND (u.employee_code = ? OR e.employee_code = ?)");
        $st->execute([(int) $account['id'], DataWipeAccess::MD_MATRICULE, DataWipeAccess::MD_MATRICULE]);
        if ($st->fetchColumn()) {
            return 'C\'est le compte du DG (matricule ' . DataWipeAccess::MD_MATRICULE . ') : il n\'est jamais effacé.';
        }
        if ($account['status'] !== 'inactive') {
            return 'Ce compte est actif : désactivez-le d\'abord dans Données de Base, puis revenez.';
        }
        return null;
    }

    /** Active accounts that can take over someone's records: [['id', 'name', 'matricule']]. */
    public function targets(): array
    {
        return $this->db->query("SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, COALESCE(NULLIF(u.employee_code, ''), e.employee_code, '') AS matricule
                                   FROM users u LEFT JOIN employees e ON e.id = u.employee_id
                                  WHERE u.status = 'active' ORDER BY u.first_name, u.last_name, u.id")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * What happens to the rows naming these accounts. $accounts: [user id =>
     * ['mode' => 'handover' | 'wipe', 'to' => user id or null, 'name']].
     * Returns:
     *   seed      [table => [key => true]]  rows that go (the closure adds what depends on them)
     *   why       [table => reason]
     *   reassign  [table => [column => [key => user id]]]
     *   unlink    [table => [column => [key => true]]]  names cleared
     *   blocked   [messages]
     *   mailboxes [['account', 'email', 'messages']]  personal mailboxes removed
     */
    public function plan(array $accounts): array
    {
        $own = self::pairs(DataWipeMap::STAFF_OWN);
        $cleared = self::pairs(DataWipeMap::STAFF_CLEARED);
        $logs = self::pairs(DataWipeMap::STAFF_LOGS);
        $out = ['seed' => [], 'why' => [], 'reassign' => [], 'unlink' => [], 'blocked' => [], 'mailboxes' => []];
        $take = function (string $t, array $keys, string $why) use (&$out) {
            foreach ($keys as $k => $_) {
                $out['seed'][$t][$k] = true;
            }
            $out['why'][$t] ??= $why;
        };

        foreach ($accounts as $uid => $a) {
            $uid = (int) $uid;
            $name = (string) ($a['name'] ?? ('compte ' . $uid));
            $take('users', [$uid => true], 'compte effacé');
            $emp = $this->wipe->rowKeys('users', '`id` = ? AND `employee_id` IS NOT NULL', [$uid], 'employee_id');
            if ($emp && $this->wipe->hasTable('employees')) {
                $take('employees', $emp, 'fiche de l\'employé effacé');
            }

            // Its own mailbox goes whole; the database drops its folders, mail and conversations with it
            // (foreign keys, ON DELETE CASCADE), which are not copied: private mail never lands in a file.
            $personal = [];
            if ($this->wipe->hasColumn('mail_accounts', 'owner_user_id') && $this->wipe->hasColumn('mail_accounts', 'kind')) {
                $personal = $this->wipe->rowKeys('mail_accounts', "`owner_user_id` = ? AND `kind` = 'personal'", [$uid]);
                if ($personal) {
                    $take('mail_accounts', $personal, 'boîte mail personnelle (copie de bureau)');
                    foreach (array_keys($personal) as $box) {
                        $email = $this->db->query("SELECT email FROM mail_accounts WHERE id = " . (int) $box)->fetchColumn();
                        $n = $this->wipe->hasTable('mail_messages') ? (int) $this->db->query("SELECT COUNT(*) FROM mail_messages WHERE account_id = " . (int) $box)->fetchColumn() : 0;
                        $out['mailboxes'][] = ['account' => $name, 'email' => (string) $email, 'messages' => $n];
                    }
                }
            }

            foreach ($this->columns() as $tc => $col) {
                [$t, $c] = [$col['table'], $col['column']];
                [$where, $params] = $this->scope($t, "`{$c}` = ?", [$uid], array_keys($personal));
                $keys = $this->wipe->rowKeys($t, $where, $params);
                if ($t === 'mail_accounts') {
                    $keys = array_diff_key($keys, $personal);
                }
                if (!$keys) {
                    continue;
                }
                if (!$this->wipe->keyColumnsOf($t)) {
                    $out['blocked'][] = sprintf('« %s » nomme %s mais n\'a pas de clé : rien ne peut y être changé sans risque.', DataWipeMap::label($t), $name);
                    continue;
                }
                if (isset($own[$tc])) {
                    $take($t, $keys, 'à la personne effacée');
                    continue;
                }
                if (isset($cleared[$tc])) {
                    $this->clear($out, $t, $c, $keys, $col['nullable'], $name);
                    continue;
                }
                if (($a['mode'] ?? '') === 'handover') {
                    foreach ($keys as $k => $_) {
                        $out['reassign'][$t][$c][$k] = (int) $a['to'];
                    }
                    continue;
                }
                // Wipe them too
                if (isset($logs[$tc])) {
                    $take($t, $keys, 'activité du compte effacé');
                    continue;
                }
                $takes = in_array($t, DataWipeMap::STAFF_WIPE_TAKES, true);
                if (DataWipeMap::isProtected($t) && !$takes) {
                    $this->clear($out, $t, $c, $keys, $col['nullable'], $name);
                    continue;
                }
                if ($takes || !$col['nullable'] || in_array($c, DataWipeMap::STAFF_MADE_BY, true)) {
                    $take($t, $keys, "fait par le compte effacé ({$c})");
                } else {
                    $this->clear($out, $t, $c, $keys, true, $name);
                }
            }
        }
        return $out;
    }

    /** A name cleared, or the reason it can't be. */
    private function clear(array &$out, string $t, string $c, array $keys, bool $nullable, string $name): void
    {
        if ($nullable) {
            foreach ($keys as $k => $_) {
                $out['unlink'][$t][$c][$k] = true;
            }
            return;
        }
        $out['blocked'][] = sprintf('« %s » : %d ligne(s) gardent le nom de %s (%s) et ne sont jamais effacées. Choisissez « Passer à un autre compte » pour ce compte.',
            DataWipeMap::label($t), count($keys), $name, $c);
    }

    /**
     * Rows of a personal mailbox being removed are left to the database (they go with it), so
     * nothing names them in the copy: [where, params] without them.
     */
    private function scope(string $t, string $where, array $params, array $boxes): array
    {
        if (!$boxes || !str_starts_with($t, 'mail_') || $t === 'mail_accounts') {
            return [$where, $params];
        }
        $in = implode(',', array_map('intval', $boxes));
        if ($this->wipe->hasColumn($t, 'account_id')) {
            return ["{$where} AND `account_id` NOT IN ({$in})", $params];
        }
        if ($this->wipe->hasColumn($t, 'thread_id') && $this->wipe->hasTable('mail_threads')) {
            return ["{$where} AND (`thread_id` IS NULL OR `thread_id` NOT IN (SELECT id FROM mail_threads WHERE account_id IN ({$in})))", $params];
        }
        return [$where, $params];
    }

    private static function pairs(array $list): array
    {
        $out = [];
        foreach ($list as [$t, $c]) {
            $out["{$t}.{$c}"] = true;
        }
        return $out;
    }
}
