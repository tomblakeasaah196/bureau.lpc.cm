<?php
/**
 * scripts/tests/admin_settings.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Administration (18 Nov 2026, the admin training videos' QA,
 * docs/VIDEO_QA_FINDINGS.md §15). What the admin screens must keep doing:
 *
 *   · Paramètres reads are GETs without a token (« Provisionner un compte »
 *     answered 419 and said « Aucun employé disponible »); every write is a
 *     POST with a CSRF token;
 *   · a closed session, a locked account or a changed password ends the
 *     session at its next request (« Kill Session » and the lock used to
 *     leave it working until its idle limit);
 *   · « Connectés maintenant » counts live sessions only;
 *   · the Journal d'audit sends what changed and the screen names it; the
 *     users trigger logs real changes only; a permission save records what
 *     was added and removed;
 *   · the help article list sends the page's token (its publish toggle and
 *     delete got 419 and did nothing);
 *   · a role is created from the name people read;
 *   · account preferences are offered by number and name;
 *   · the QA crawler never changes a select that saves on change.
 *
 * Each was run by hand against the filming copy when this was written. CI has
 * no database for this app: these are wiring checks.
 *
 * Run:  php scripts/tests/admin_settings.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$read = static fn(string $p): string => (string) file_get_contents($root . '/' . $p);

$ctl   = $read('api/v1/settings_controller.php');
$rbac  = $read('api/v1/rbac_controller.php');
$js    = $read('assets/js/modules/settings-index.js');
$boot  = $read('includes/bootstrap.php');
$login = $read('index.php');
$help  = $read('assets/js/modules/help-admin.js');
$prefs = $read('includes/classes/Prefs.php');
$qa    = $read('scripts/video/qa.js');
$mig   = $read('migrations/140_admin_settings_audit_help.sql');

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}

echo "Paramètres: reads and writes\n";
preg_match("/\\\$LPC_READ_ACTIONS = \[([^\]]*)\]/", $ctl, $m);
$reads = $m ? array_map(fn($s) => trim($s, " '\""), explode(',', $m[1])) : [];
check('the read actions are read and list_unlinked_employees', $reads == ['read', 'list_unlinked_employees']);
$gate = strpos($ctl, 'if (!in_array($action, $LPC_READ_ACTIONS, true))');
$gateBlock = $gate !== false ? substr($ctl, $gate, 300) : '';
check('every other action needs a POST', str_contains($gateBlock, "!== 'POST'"));
check('every other action needs a CSRF token', str_contains($gateBlock, 'Csrf::requireValid()'));
check('the employee list answers where apiGet reads it (data.employees)',
      str_contains($ctl, "'data' => ['employees' => \$rows]"));
check('a failed employee list says so, not « Aucun employé disponible »',
      str_contains($js, "La liste des employés n'a pas pu être chargée."));

echo "Sessions end when an administrator says so\n";
check('bootstrap reads the session row and the account status',
      str_contains($boot, 'SELECT us.logout_time, u.status') && str_contains($boot, 'session_token_hash = ?'));
check('a closed or locked session goes to the sign-in page', str_contains($boot, "error=session_revoked"));
check('an API call gets 401 session_revoked', str_contains($boot, "'code' => 'session_revoked'"));
check('the sign-in page explains it', str_contains($login, "\$error === 'session_revoked'"));
$lock = substr($ctl, (int) strpos($ctl, "\$action === 'toggle_user'"), 1600);
check('a lock accepts only active or inactive', str_contains($lock, "['active', 'inactive']"));
check('nobody locks their own account', str_contains($lock, 'votre propre compte'));
check('a lock closes the open sessions', str_contains($lock, 'UPDATE user_sessions SET logout_time = NOW() WHERE user_id = ?'));
$kill = substr($ctl, (int) strpos($ctl, "\$action === 'kill_session'"), 1200);
check('closing a session refuses one already closed', str_contains($kill, 'logout_time IS NULL') && str_contains($kill, 'déjà fermée'));
check('« Connectés maintenant » counts accounts active within the idle limit',
      str_contains($ctl, 'COUNT(DISTINCT user_id)') && str_contains($ctl, 'last_activity >= NOW() - INTERVAL'));
check('the button says Déconnecter, not Kill Session', str_contains($js, 'Déconnecter</button>') && !str_contains($js, 'Kill Session'));

echo "Journal d'audit\n";
check('the read sends the change itself', str_contains($ctl, 'AS new_json') && str_contains($ctl, 'AS old_json'));
check('the read sends field labels and role names', str_contains($ctl, "'field_labels'") && str_contains($ctl, "'role_names'"));
check('the screen has a Détail window', str_contains($js, 'function openAuditDetail') && str_contains($js, 'openAuditDetail: openAuditDetail'));
check('actions are named in French', str_contains($js, "UPDATE: 'Modification'"));
check('a permission save records what was added and removed',
      str_contains($rbac, "'perm_added'") && str_contains($rbac, "'perm_removed'"));
check('the users trigger logs a change of role, access, email or password only',
      str_contains($mig, 'IF NOT (OLD.role_id <=> NEW.role_id)') && str_contains($mig, 'NOT (OLD.password_hash <=> NEW.password_hash)'));
check('the trigger never stores the password hash',
      !preg_match("/JSON_OBJECT\([^)]*password_hash/", $mig));

echo "Rôles\n";
check('a role is created from the name people read', str_contains($rbac, "\$body['label']") && str_contains($rbac, 'INSERT INTO roles (name, description)'));
check('the screen sends that name', str_contains($js, "fd.append('label', label.trim())"));
check('the list returns it', str_contains($rbac, 'r.description AS label'));
check('no Supprimer on roles the server never deletes', str_contains($js, '!BASE_ROLES.includes(data.role.name)'));
check('permission groups have French names', str_contains($js, "accounting: 'Comptabilité & finance'"));

echo "Centre d'Aide (articles)\n";
check('the list sends the page token when it has no form', str_contains($help, 'window.LPC.rbac.csrf'));
check('a refused change is said, not swallowed', substr_count($help, 'fail(res)') >= 2);

echo "Préférences\n";
check('account preferences are offered by number and name',
      str_contains($prefs, 'function accountOptions') && str_contains($prefs, "_account_id'"));
check('the two groups shown in English have French names',
      str_contains($prefs, "'accounting' => ['fr' => 'Comptes par défaut'") && str_contains($prefs, "'tax'        => ['fr' =>"));
check('settings no screen reads are hidden', str_contains($mig, 'UPDATE app_preferences SET is_active = 0'));
check('no help line speaks of code any more', !str_contains($mig, "codé en dur"));

echo "QA crawler\n";
check('qa.js never tries a select that saves on change', str_contains($qa, '[data-autosave]') && str_contains($qa, 'onchange'));
check('the roles home-page list is marked', str_contains($js, 'data-autosave onchange="LPCSettings.setRoleLanding'));

echo $fail ? "\n{$fail} check(s) failed.\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
