<?php
/**
 * includes/classes/DataWipeAccess.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — who reaches the data wipe tool, and how a wipe is confirmed
 * (owner's decisions 1 and 2, 2 Nov 2026).
 *
 * WHO
 *   Only the signed-in account whose email equals WIPE_ADMIN_EMAIL in the
 *   server's .env, on a URL carrying WIPE_URL_KEY (the page's address is not
 *   in this public repository). Not a permission or a role: admins don't have
 *   it. That account alone sees "Effacer les données" under Courrier in the
 *   sidebar, a link that carries the key (menuLink(), owner's decision of
 *   3 Nov 2026); for everyone else it is in no menu. Everyone else, and
 *   everyone while either setting is missing, gets exactly what a page that
 *   doesn't exist gives: bureau
 *   rewrites unknown paths to index.php (.htaccess), which shows the sign-in
 *   page, or sends a signed-in person to their dashboard. The page and every
 *   endpoint answer the same way.
 *
 * CONFIRMING A WIPE
 *   A 6-digit code emailed to that address (hashed, 10 minutes, 5 tries,
 *   single use, valid only for what was picked when it was sent: the tables,
 *   or the records with the choice made for each staff account) and a
 *   written reason. Codes are never written anywhere else: with MAIL_FROM
 *   unset, Mail::send() would put them in the error log, so none is sent.
 *
 * THE MD
 *   Told by email and in bureau's notifications once a wipe is done: what,
 *   how many rows, when, why, by whom. Bureau has no MD role; the owner named
 *   the staff matricule (2 Nov 2026). A wipe is refused while no active
 *   account carries it, since nobody would be told.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class DataWipeAccess
{
    public const MD_MATRICULE = 'LPC-MD-01';
    public const CODE_MINUTES = 10;
    public const CODE_TRIES = 5;
    public const PAGE = '/modules/admin/data_wipe.php';
    private const KEY_MIN_LENGTH = 32;

    /** The tool is on: both settings are present and well formed. */
    public static function configured(): bool
    {
        return filter_var(trim((string) env('WIPE_ADMIN_EMAIL', '')), FILTER_VALIDATE_EMAIL) !== false
            && strlen(trim((string) env('WIPE_URL_KEY', ''))) >= self::KEY_MIN_LENGTH;
    }

    /** The key the request carries (?k=, a POST field or the X-Wipe-Key header) matches WIPE_URL_KEY. */
    public static function keyOk(): bool
    {
        $want = trim((string) env('WIPE_URL_KEY', ''));
        $got = (string) ($_GET['k'] ?? $_POST['k'] ?? $_SERVER['HTTP_X_WIPE_KEY'] ?? '');
        return strlen($want) >= self::KEY_MIN_LENGTH && hash_equals($want, $got);
    }

    /**
     * The sidebar link with the key, for the WIPE_ADMIN_EMAIL account only, so
     * nothing has to be bookmarked. Null for everyone else and while the tool
     * is off: then no page says the tool exists.
     */
    public static function menuLink(PDO $db): ?string
    {
        if (!self::configured() || self::account($db) === null) {
            return null;
        }
        return self::PAGE . '?k=' . rawurlencode(trim((string) env('WIPE_URL_KEY', '')));
    }

    /** The signed-in account if it is the one WIPE_ADMIN_EMAIL names and is active, else null. */
    public static function account(PDO $db): ?array
    {
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        $want = strtolower(trim((string) env('WIPE_ADMIN_EMAIL', '')));
        if ($uid <= 0 || $want === '') {
            return null;
        }
        $st = $db->prepare("SELECT id, email, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE id = ? AND status = 'active'");
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        return $u && hash_equals($want, strtolower(trim((string) $u['email']))) ? $u : null;
    }

    /** The account, or the answer bureau gives for a page that doesn't exist. Call before any output. */
    public static function enforce(PDO $db): array
    {
        $user = self::configured() && self::keyOk() ? self::account($db) : null;
        if ($user === null) {
            self::notFound();
        }
        return $user;
    }

    /** Exactly what an unknown URL gets: .htaccess rewrites it to index.php. */
    public static function notFound(): never
    {
        $_GET = ['route' => ltrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/')];
        $_POST = [];
        if (!headers_sent()) {
            header_remove('Content-Type');
        }
        require dirname(__DIR__, 2) . '/index.php';
        exit;
    }

    /** a***@domain — what the screen shows of the address codes go to. */
    public static function maskedEmail(): string
    {
        $email = trim((string) env('WIPE_ADMIN_EMAIL', ''));
        $at = strrpos($email, '@');
        return $at ? substr($email, 0, 1) . '***' . substr($email, $at) : '***';
    }

    // =========================================================================
    // One-time codes
    // =========================================================================

    /**
     * Email a code for this pick. $what: a few lines saying what would go.
     * @throws DomainException with a message for the screen
     */
    public static function sendCode(PDO $db, array $user, array $picked, string $what): void
    {
        if (!class_exists('Mail')) {
            require_once __DIR__ . '/Mail.php';
        }
        if (!Mail::configured()) {
            throw new DomainException("L'envoi d'emails n'est pas configuré sur ce serveur (MAIL_FROM) : aucun code ne peut être envoyé.");
        }
        $st = $db->prepare("SELECT COUNT(*) AS n, MAX(created_at) AS last FROM data_wipe_codes WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR");
        $st->execute([(int) $user['id']]);
        $recent = $st->fetch(PDO::FETCH_ASSOC);
        if ((int) $recent['n'] >= 5) {
            throw new DomainException('Cinq codes ont déjà été envoyés cette dernière heure. Réessayez plus tard.');
        }
        if ($recent['last'] && strtotime((string) $recent['last']) > strtotime((string) $db->query('SELECT NOW()')->fetchColumn()) - 60) {
            throw new DomainException('Un code vient d\'être envoyé. Attendez une minute avant d\'en demander un autre.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        // Earlier codes stop working: only the latest one counts
        $db->prepare("UPDATE data_wipe_codes SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL")->execute([(int) $user['id']]);
        $db->prepare("INSERT INTO data_wipe_codes (user_id, code_hash, picked_hash, expires_at, ip) VALUES (?, ?, ?, NOW() + INTERVAL " . self::CODE_MINUTES . " MINUTE, ?)")
           ->execute([(int) $user['id'], password_hash($code, PASSWORD_DEFAULT), DataWipe::pickHash($picked), substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);

        $body = "Bonjour,\n\nVotre code pour confirmer l'effacement des données de test :\n\n    {$code}\n\n"
              . "Il est valable " . self::CODE_MINUTES . " minutes, une seule fois, et seulement pour cette sélection :\n\n{$what}\n\n"
              . "Si vous n'avez rien demandé, ne faites rien : sans ce code, rien n'est effacé.\n\nBureau LPC";
        if (!Mail::send(trim((string) env('WIPE_ADMIN_EMAIL')), 'Code de confirmation : ' . $code, $body)) {
            throw new DomainException("L'email n'est pas parti. Réessayez dans un instant.");
        }
    }

    /**
     * Check a code before the wipe starts; a wrong one costs a try (committed
     * at once). Returns the code's id, consumed later inside the wipe.
     * @throws DomainException with a message for the screen
     */
    public static function checkCode(PDO $db, array $user, array $picked, string $code): int
    {
        $st = $db->prepare("SELECT id, code_hash, picked_hash, attempts, expires_at < NOW() AS expired FROM data_wipe_codes
                             WHERE user_id = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1");
        $st->execute([(int) $user['id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['expired'] === 1) {
            throw new DomainException('Ce code a expiré ou a déjà servi. Demandez-en un nouveau.');
        }
        if ((int) $row['attempts'] >= self::CODE_TRIES) {
            throw new DomainException('Trop d\'essais pour ce code. Demandez-en un nouveau.');
        }
        if (!hash_equals((string) $row['picked_hash'], DataWipe::pickHash($picked))) {
            throw new DomainException('Ce code a été envoyé pour une autre sélection. Demandez-en un pour celle-ci.');
        }
        if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, (string) $row['code_hash'])) {
            $db->prepare("UPDATE data_wipe_codes SET attempts = attempts + 1 WHERE id = ?")->execute([(int) $row['id']]);
            $left = self::CODE_TRIES - (int) $row['attempts'] - 1;
            throw new DomainException('Code incorrect. ' . ($left > 0 ? "Encore {$left} essai(s)." : 'Demandez un nouveau code.'));
        }
        return (int) $row['id'];
    }

    /** Inside the wipe's transaction: the code is used up with it, or not at all. */
    public static function consumeCode(PDO $db, int $codeId): void
    {
        $st = $db->prepare("UPDATE data_wipe_codes SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL AND expires_at >= NOW()");
        $st->execute([$codeId]);
        if ($st->rowCount() !== 1) {
            throw new DomainException('Ce code a expiré ou a déjà servi. Demandez-en un nouveau.');
        }
    }

    // =========================================================================
    // The MD
    // =========================================================================

    /**
     * The active account of the MD (matricule LPC-MD-01) with a valid email, or
     * null: then nobody could be told, and no code is sent and no wipe runs.
     */
    public static function md(PDO $db): ?array
    {
        $st = $db->prepare("SELECT u.id, u.email, CONCAT(u.first_name, ' ', u.last_name) AS name
                              FROM users u LEFT JOIN employees e ON e.id = u.employee_id
                             WHERE (u.employee_code = ? OR e.employee_code = ?) AND u.status = 'active'
                             ORDER BY u.id LIMIT 1");
        $st->execute([self::MD_MATRICULE, self::MD_MATRICULE]);
        $md = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return $md && filter_var(trim((string) $md['email']), FILTER_VALIDATE_EMAIL) !== false ? $md : null;
    }

    /** Why a wipe can't run for want of someone to tell (for the screen). */
    public static function mdMissing(): string
    {
        return 'Aucun compte actif avec le matricule ' . self::MD_MATRICULE . ' et une adresse email valide : le DG ne pourrait pas être prévenu, '
             . 'donc aucun effacement n\'est possible. Corrigez sa fiche dans Données de Base.';
    }

    /** Tell the MD what was wiped. Records it on the wipe. Never throws: the wipe is done. */
    public static function tellMd(PDO $db, array $wipe, array $user, string $reason): array
    {
        $told = ['notified' => false, 'emailed' => false, 'name' => null];
        try {
            $md = self::md($db);
            if (!$md) {
                return $told;
            }
            $told['name'] = $md['name'];
            $lines = [];
            $counts = $wipe['counts'];
            arsort($counts);
            foreach ($counts as $t => $n) {
                $lines[] = '  · ' . DataWipeMap::label($t) . ' : ' . number_format((int) $n, 0, ',', ' ');
            }
            $when = date('d/m/Y à H:i');
            $records = ($wipe['mode'] ?? 'tables') === 'records';
            $full = ($wipe['mode'] ?? 'tables') === 'full';
            $title = $records ? 'Enregistrements effacés' : ($full ? 'Remise à zéro avant démarrage' : 'Données de test effacées');
            $short = number_format((int) $wipe['total'], 0, ',', ' ') . ($records ? ' lignes effacées' : ' lignes de test effacées') . ' le ' . $when . ' par ' . $user['name']
                   . ' (' . ($records ? 'un par un : ' . implode(', ', $wipe['records'])
                          : ($full ? DataWipe::pickedText(json_encode(['mode' => 'full', 'options' => $wipe['options'] ?? []]))
                                   : implode(', ', array_map([DataWipeMap::class, 'label'], $wipe['picked']))))
                   . '). Motif : ' . $reason;

            if (!function_exists('lpc_notify_user')) {
                require_once dirname(__DIR__) . '/functions/notify.php';
            }
            lpc_notify_user($db, (int) $md['id'], $title, mb_substr($short, 0, 1000), null, 'warning');
            $told['notified'] = true;

            if (!class_exists('Mail')) {
                require_once __DIR__ . '/Mail.php';
            }
            if (Mail::configured() && filter_var($md['email'], FILTER_VALIDATE_EMAIL)) {
                $body = $records
                    ? "Bonjour,\n\nDes enregistrements ont été effacés un par un de bureau.lpc.cm le {$when}, par {$user['name']} :\n"
                      . implode("\n", array_map(fn ($r) => '  · ' . $r, $wipe['records'])) . "\n\n"
                      . "Motif : {$reason}\n\nAvec tout ce qui en dépendait (" . number_format((int) $wipe['total'], 0, ',', ' ') . " lignes) :\n"
                      . implode("\n", $lines) . "\n\n"
                      . self::recordsDetail($wipe)
                      . "Les soldes de trésorerie, les avoirs clients et le stock ont été recalculés sur ce qui reste.\n\n"
                      . "Une copie de tout ce qui a été effacé est conservée " . DataWipe::KEEP_DAYS . " jours par JBS Praxis.\n\nBureau LPC"
                    : "Bonjour,\n\nDes données de test ont été effacées de bureau.lpc.cm le {$when}, par {$user['name']}.\n\n"
                      . "Motif : {$reason}\n\nCe qui a été effacé (" . number_format((int) $wipe['total'], 0, ',', ' ') . " lignes) :\n"
                      . implode("\n", $lines) . "\n\n"
                      . "Les soldes de trésorerie, les avoirs clients et le stock ont été recalculés sur ce qui reste, "
                      . "et la numérotation des documents vidés repart à 0001.\n\n"
                      . "Une copie de tout ce qui a été effacé est conservée " . DataWipe::KEEP_DAYS . " jours par JBS Praxis.\n\nBureau LPC";
                $told['emailed'] = Mail::send((string) $md['email'], $title, $body);
            }
            $db->prepare("UPDATE data_wipes SET md_user_id = ?, md_notified_at = NOW(), md_emailed = ? WHERE id = ?")
               ->execute([(int) $md['id'], $told['emailed'] ? 1 : 0, (int) $wipe['id']]);
        } catch (Throwable $e) {
            $told['error'] = $e->getMessage();   // shown on the wipe's screen, not in the error log admins read
        }
        return $told;
    }

    /** For the MD's email: hand-overs, mailboxes removed, numbering, after a one-by-one delete. */
    private static function recordsDetail(array $wipe): string
    {
        $out = '';
        $moved = [];
        foreach ($wipe['reassigned'] ?? [] as $r) {
            $moved[$r['to'] ?? '?'] = ($moved[$r['to'] ?? '?'] ?? 0) + (int) $r['rows'];
        }
        foreach ($moved as $to => $n) {
            $out .= "Repris par {$to} : " . number_format($n, 0, ',', ' ') . " enregistrement(s) du compte effacé.\n";
        }
        foreach ($wipe['mailboxes'] ?? [] as $box) {
            $out .= "Boîte mail personnelle retirée de bureau : {$box['email']} (le courrier reste sur le serveur mail).\n";
        }
        foreach ($wipe['numbering'] ?? [] as $note) {
            $out .= 'Numérotation : ' . $note['text'] . "\n";
        }
        return $out !== '' ? $out . "\n" : '';
    }
}
