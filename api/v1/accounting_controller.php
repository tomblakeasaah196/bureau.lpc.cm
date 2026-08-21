<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/notify.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('accounting.journal.view');
/**
 * CONTROLLER: Comptabilité (Journals & Chart of Accounts)
 * DESCRIPTION: Handles Chart of Accounts (OHADA/LPC), Journal Entry saving
 *              (double-entry validation), draft queue read + confidence
 *              scoring, single + batch approvals.
 * METHOD: STRICT REST-like JSON API (GET / POST)
 *
 * STATUS VOCABULARY — post migration 039 the enum is
 *     ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft'.
 * Anything speaking 'pending' or 'approved' is stale code from before the
 * migration ran and MUST be treated as a bug. The queue on
 * modules/accounting/journal_entry.php was invisibly returning zero rows
 * because the SELECT still filtered on the retired values.
 *
 * CONFIDENCE — every draft row carries a 0..100 score (see
 * includes/classes/JournalConfidence.php + migration 077). Rules are
 * deterministic SQL over this database; no LLMs, no external calls.
 * The queue read lazily rescores stale rows so the tab is always current
 * without a background job.
 *
 * BATCH APPROVE — accepts a list of ids, requires every one of them to be
 * (a) still in draft, (b) balanced, and (c) at or above the auto-post
 * threshold. Posts them via the same stored proc as single approve, inside
 * one transaction — one row failing rolls back the whole batch. This is the
 * "one click for the obvious ones" workflow.
 */
require_once __DIR__ . '/../../includes/classes/JournalConfidence.php';

$user_id = $_SESSION['user_id'];

// Database Connection (PDO)
try {
    require_once '../../includes/classes/Database.php';
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

function sendResponse($status, $message, $data = null) {
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// ==========================================
// [GET] READ OPERATIONS (Data Fetching)
// ==========================================
if ($method === 'GET') {
    $tab = $_GET['tab'] ?? null;
    $response_data = [];

    try {
        switch ($tab) {
            case 'chart':
            case 'wizard':
                // 1. Fetch OHADA Master Accounts
                $stmtM = $pdo->query("SELECT id, account_number, name, type FROM ohada_accounts ORDER BY account_number ASC");
                $response_data['ohada_masters'] = $stmtM->fetchAll();

                // 2. Fetch LPC Auxiliary Accounts
                $stmtA = $pdo->query("SELECT id, ohada_account_id, code, name, type FROM chart_of_accounts WHERE is_active = 1 ORDER BY code ASC");
                $response_data['lpc_accounts'] = $stmtA->fetchAll();
                break;

            case 'chart_admin':
                // Full chart for the admin editor: every CoA row (active
                // and inactive) with parent info and a usage count that
                // decides whether Deactivate/Delete are safe.
                Rbac::requirePermission('accounting.chart.view');

                $ohada = $pdo->query("
                    SELECT id, account_number, name, type
                      FROM ohada_accounts
                     ORDER BY account_number ASC
                ")->fetchAll();

                $coa = $pdo->query("
                    SELECT c.id, c.ohada_account_id, c.code, c.name, c.type,
                           c.is_active,
                           o.account_number AS ohada_number,
                           o.name           AS ohada_name,
                           (SELECT COUNT(*) FROM journal_lines jl
                             WHERE jl.account_id = c.id)              AS lines_count,
                           (SELECT COUNT(*) FROM expense_categories ec
                             WHERE ec.coa_account_id = c.id)          AS categories_count
                      FROM chart_of_accounts c
                      JOIN ohada_accounts    o ON o.id = c.ohada_account_id
                     ORDER BY c.code ASC
                ")->fetchAll();

                $response_data['ohada_masters'] = $ohada;
                $response_data['coa']           = $coa;
                $response_data['can_edit']      =
                    Rbac::hasPermission('accounting.chart.edit');
                $response_data['can_create']    =
                    Rbac::hasPermission('accounting.chart.create');
                break;

            case 'queue':
                // Lazy rescore first. Cheap: only touches rows with a stale
                // (or never-computed) score. The typical queue read after this
                // is a warm-cache read of pre-scored rows.
                try {
                    JournalConfidence::rescoreStale($pdo, 24);
                } catch (Throwable $e) {
                    // Scoring is best-effort. A rules-engine failure must not
                    // hide the queue from the accountant. Log and carry on
                    // with whatever scores are already on the rows.
                    error_log('JournalConfidence rescore failed: ' . $e->getMessage());
                }

                // Fetch drafts (post-039 vocabulary). Ordered so the highest-
                // confidence entries surface first — matches the UX of
                // "check the top rows, batch-post them, then focus on the
                // suspicious tail."
                $stmtQ = $pdo->query("
                    SELECT id, reference, date, description, status, journal_code,
                           confidence_score, confidence_reasons, confidence_scored_at
                      FROM journal_entries
                     WHERE status = 'draft'
                     ORDER BY (confidence_score IS NULL) ASC,
                              confidence_score DESC,
                              created_at DESC
                ");
                $entries = $stmtQ->fetchAll();

                // Fetch lines for each entry.
                $stmtL = $pdo->prepare("
                    SELECT jl.debit, jl.credit, ca.code AS account_code, ca.name AS account_name
                      FROM journal_lines jl
                      JOIN chart_of_accounts ca ON jl.account_id = ca.id
                     WHERE jl.journal_entry_id = ?
                ");

                $queue = [];
                foreach ($entries as $e) {
                    $stmtL->execute([$e['id']]);
                    $e['lines']              = $stmtL->fetchAll();
                    $e['confidence_reasons'] = $e['confidence_reasons']
                        ? json_decode($e['confidence_reasons'], true)
                        : [];
                    $e['confidence_score'] = $e['confidence_score'] !== null
                        ? (int) $e['confidence_score']
                        : null;
                    $queue[] = $e;
                }

                $response_data['queue']                = $queue;
                $response_data['auto_post_threshold']  = JournalConfidence::autoPostThreshold();
                break;

            default:
                sendResponse('error', 'Onglet inconnu.');
        }

        sendResponse('success', 'Données chargées', $response_data);

    } catch (PDOException $e) {
        sendResponse('error', 'Erreur base de données: ' . $e->getMessage());
    }
}

// ==========================================
// [POST] WRITE OPERATIONS (Data Mutation)
// ==========================================
else if ($method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!$payload || !isset($payload['action'])) sendResponse('error', 'Payload invalide.');

    $action = $payload['action'];

    try {
        $pdo->beginTransaction();

        // 1. CREATE AUXILIARY ACCOUNT (Plan Comptable LPC)
        if ($action === 'create_lpc_account') {
            Rbac::requirePermission('accounting.chart.create');
            $ohada_id = (int)$payload['ohada_account_id'];
            $code = trim($payload['code']);
            $name = trim($payload['name']);

            if (strlen($code) !== 6 || !is_numeric($code)) {
                throw new Exception("Le code compte doit être strictement composé de 6 chiffres.");
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM chart_of_accounts WHERE code = ?");
            $stmt->execute([$code]);
            if ($stmt->fetchColumn() > 0) throw new Exception("Ce code de compte ($code) existe déjà.");

            $stmtP = $pdo->prepare("SELECT type FROM ohada_accounts WHERE id = ?");
            $stmtP->execute([$ohada_id]);
            $type = $stmtP->fetchColumn();
            if (!$type) throw new Exception("Compte OHADA parent introuvable.");

            $stmtI = $pdo->prepare("INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmtI->execute([$ohada_id, $code, $name, $type]);

            $pdo->commit();
            sendResponse('success', 'Compte LPC créé avec succès.',
                ['id' => (int)$pdo->lastInsertId()]);
        }

        // -----------------------------------------------------------------
        // CoA admin — add/rename/deactivate rows in ohada_accounts and
        // chart_of_accounts without a migration.
        //
        // Rename touches display text only; historical journal lines
        // reference chart_of_accounts.id and are unaffected. Deactivating a
        // CoA row flips is_active off — the row still exists so old journal
        // lines stay resolvable, but it disappears from the pickers.
        //
        // Codes (ohada_accounts.account_number and chart_of_accounts.code)
        // are never mutated by the editor. Historical postings and the
        // budget-bucket mappings in migration 091 both look accounts up by
        // code, so a mutation would silently reroute posted history. If a
        // code was wrong, fix it in a migration (like 117 for 6252) and let
        // the reviewer trace it — never through a UI.
        // -----------------------------------------------------------------
        if ($action === 'create_ohada_account') {
            Rbac::requirePermission('accounting.chart.create');
            $number = trim((string)($payload['account_number'] ?? ''));
            $name   = trim((string)($payload['name'] ?? ''));
            $type   = trim((string)($payload['type'] ?? ''));

            if (!preg_match('/^[0-9]{2,6}$/', $number)) {
                throw new Exception("Le numéro OHADA doit être 2 à 6 chiffres.");
            }
            if ($name === '') throw new Exception("Nom obligatoire.");
            $allowed = ['asset','liability','equity','revenue','expense'];
            if (!in_array($type, $allowed, true)) {
                throw new Exception("Type invalide.");
            }

            $chk = $pdo->prepare("SELECT id FROM ohada_accounts WHERE account_number = ?");
            $chk->execute([$number]);
            if ($chk->fetchColumn()) {
                throw new Exception("Le compte OHADA $number existe déjà.");
            }

            $ins = $pdo->prepare("INSERT INTO ohada_accounts (account_number, name, type) VALUES (?, ?, ?)");
            $ins->execute([$number, $name, $type]);
            $ohada_id = (int)$pdo->lastInsertId();

            // Auto-mirror a chart_of_accounts front — the same pattern
            // migration 053 uses. Without it the new OHADA parent cannot be
            // referenced by an expense category or journal line.
            $insCoa = $pdo->prepare("
                INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");
            $insCoa->execute([$ohada_id, $number, $name, $type]);
            $coa_id = (int)$pdo->lastInsertId();

            $pdo->commit();
            sendResponse('success', 'Compte OHADA créé.',
                ['ohada_id' => $ohada_id, 'coa_id' => $coa_id]);
        }

        if ($action === 'update_ohada_account') {
            Rbac::requirePermission('accounting.chart.edit');
            $id   = (int)($payload['id'] ?? 0);
            $name = trim((string)($payload['name'] ?? ''));
            if (!$id || $name === '') throw new Exception("Paramètres manquants.");

            $upd = $pdo->prepare("UPDATE ohada_accounts SET name = ? WHERE id = ?");
            $upd->execute([$name, $id]);

            // Keep the front CoA row in sync IF its name still matches what
            // the OHADA row was called before. A CoA row an operator has
            // customised is left alone.
            $syncCoa = $pdo->prepare("
                UPDATE chart_of_accounts c
                  JOIN ohada_accounts    o ON o.id = c.ohada_account_id
                   SET c.name = ?
                 WHERE c.ohada_account_id = ?
                   AND (c.name = o.name OR c.name = c.code)
            ");
            $syncCoa->execute([$name, $id]);

            $pdo->commit();
            sendResponse('success', 'Compte OHADA renommé.');
        }

        if ($action === 'update_lpc_account') {
            Rbac::requirePermission('accounting.chart.edit');
            $id   = (int)($payload['id'] ?? 0);
            $name = trim((string)($payload['name'] ?? ''));
            if (!$id || $name === '') throw new Exception("Paramètres manquants.");

            $upd = $pdo->prepare("UPDATE chart_of_accounts SET name = ? WHERE id = ?");
            $upd->execute([$name, $id]);

            $pdo->commit();
            sendResponse('success', 'Compte renommé.');
        }

        if ($action === 'set_lpc_account_active') {
            Rbac::requirePermission('accounting.chart.edit');
            $id     = (int)($payload['id'] ?? 0);
            $active = !empty($payload['is_active']) ? 1 : 0;
            if (!$id) throw new Exception("Paramètres manquants.");

            // Refuse to deactivate a row that an expense category still
            // points at — the category would silently start rejecting saves
            // (postExpense throws when coaForCategory returns null). The
            // operator must remap the category first.
            if (!$active) {
                $used = $pdo->prepare("
                    SELECT COUNT(*) FROM expense_categories
                     WHERE coa_account_id = ?
                ");
                $used->execute([$id]);
                $n = (int)$used->fetchColumn();
                if ($n > 0) {
                    throw new Exception(
                        "Ce compte est utilisé par $n catégorie(s) de dépense. Remappez-les avant de désactiver."
                    );
                }
            }

            $upd = $pdo->prepare("UPDATE chart_of_accounts SET is_active = ? WHERE id = ?");
            $upd->execute([$active, $id]);

            $pdo->commit();
            sendResponse('success', $active ? 'Compte réactivé.' : 'Compte désactivé.');
        }

        if ($action === 'remap_expense_category') {
            Rbac::requirePermission('accounting.chart.edit');
            $cat_id = (int)($payload['category_id'] ?? 0);
            $coa_id = (int)($payload['coa_account_id'] ?? 0);
            if (!$cat_id || !$coa_id) throw new Exception("Paramètres manquants.");

            $chk = $pdo->prepare("SELECT is_active FROM chart_of_accounts WHERE id = ?");
            $chk->execute([$coa_id]);
            $act = $chk->fetchColumn();
            if ($act === false) throw new Exception("Compte cible introuvable.");
            if ((int)$act !== 1) throw new Exception("Compte cible désactivé.");

            $upd = $pdo->prepare("UPDATE expense_categories SET coa_account_id = ? WHERE id = ?");
            $upd->execute([$coa_id, $cat_id]);

            $pdo->commit();
            sendResponse('success', 'Catégorie remappée.');
        }

        // 2. SAVE JOURNAL ENTRY (Draft or Post directly)
        if ($action === 'save_journal_entry') {
            // Vocabulary maps to migration 039 enum: draft = brouillon,
            // posted = validé au grand livre.
            $wants_post   = ($payload['status'] ?? '') === 'post';
            $journal_code = $payload['journal_code'];
            $date         = $payload['date'];
            $ref          = trim($payload['reference']);
            $desc         = trim($payload['description']);
            $lines        = $payload['lines'];

            if (!$journal_code || !$date || !$ref || !$desc || !is_array($lines) || count($lines) < 2) {
                throw new Exception("Champs obligatoires manquants ou moins de 2 lignes.");
            }

            $total_debit = 0.0; $total_credit = 0.0;
            foreach ($lines as $line) {
                $total_debit  += (float)$line['debit'];
                $total_credit += (float)$line['credit'];
            }

            if ($wants_post) {
                Rbac::requirePermission('accounting.journal.approve');
                if (abs($total_debit - $total_credit) > 0.01) {
                    throw new Exception("Écriture déséquilibrée ! Impossible de valider au Grand Livre.");
                }
                if ($total_debit <= 0) {
                    throw new Exception("L'écriture doit avoir un montant supérieur à 0.");
                }
            } else {
                Rbac::requirePermission('accounting.journal.create');
            }

            // Always insert as 'draft' first — that's what the post_journal_entry
            // stored proc from migration 004 requires. If the user asked to
            // post, we transition after the lines are inserted.
            $stmtE = $pdo->prepare("
                INSERT INTO journal_entries
                    (reference, journal_code, date, description, status, created_by)
                VALUES (?, ?, ?, ?, 'draft', ?)
            ");
            $stmtE->execute([$ref, $journal_code, $date, $desc, $user_id]);
            $entry_id = (int) $pdo->lastInsertId();

            $stmtL = $pdo->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
            foreach ($lines as $line) {
                if (empty($line['account_id'])) throw new Exception("Un compte est manquant sur une ligne.");
                $stmtL->execute([$entry_id, (int)$line['account_id'], (float)$line['debit'], (float)$line['credit']]);
            }

            // Score the fresh entry so the queue widget can decide badge colour
            // immediately, whether it stays as a draft or not.
            JournalConfidence::scoreAndPersist($pdo, $entry_id);

            if ($wants_post) {
                // Stored proc raises SQLSTATE 45000 if the ledger drifted.
                $pdo->prepare("CALL post_journal_entry(?, ?)")->execute([$entry_id, $user_id]);
            }

            $pdo->commit();

            // Notify whoever can approve that a fresh draft is waiting on
            // them — the journal_entry.php queue tab is already the default
            // view on load, so a bare link to the page lands right on it.
            // Only for drafts: a direct post means the actor already held
            // accounting.journal.approve, so nobody else needs to act.
            if (!$wants_post) {
                lpc_notify_permission(
                    $pdo,
                    'accounting.journal.approve',
                    'Écriture en attente d\'approbation',
                    "Nouvelle écriture ($ref, {$journal_code}) déposée par " . ($_SESSION['user_name'] ?? 'un opérateur')
                        . " — " . number_format($total_debit, 0, ',', ' ') . " FCFA. En attente de validation.",
                    '/modules/accounting/journal_entry.php',
                    'warning',
                    [$user_id]
                );
            }

            $msg = $wants_post
                ? 'Écriture équilibrée et validée au Grand Livre.'
                : 'Brouillard sauvegardé dans la file d\'attente.';
            sendResponse('success', $msg, ['entry_id' => $entry_id]);
        }

        // 3. APPROVE ONE ENTRY (From the Queue)
        if ($action === 'approve_entry') {
            Rbac::requirePermission('accounting.journal.approve');
            $entry_id = (int)$payload['id'];

            // Sanity check: the row is actually a draft. Users open two tabs.
            $st = $pdo->prepare("SELECT status, reference, created_by FROM journal_entries WHERE id = ?");
            $st->execute([$entry_id]);
            $entryRow = $st->fetch(PDO::FETCH_ASSOC);
            if ($entryRow === false) throw new Exception("Écriture introuvable.");
            if ($entryRow['status'] !== 'draft') throw new Exception("Cette écriture n'est plus en brouillon (statut actuel : {$entryRow['status']}).");

            // post_journal_entry validates balance + stamps posted_at/posted_by
            // + flips status to 'posted'. SIGNAL SQLSTATE '45000' on drift.
            $pdo->prepare("CALL post_journal_entry(?, ?)")->execute([$entry_id, $user_id]);

            $pdo->commit();

            // Let the person who submitted it know it cleared — skip if they
            // approved their own draft.
            if (!empty($entryRow['created_by']) && (int) $entryRow['created_by'] !== $user_id) {
                lpc_notify_user(
                    $pdo,
                    (int) $entryRow['created_by'],
                    'Écriture approuvée',
                    "Votre écriture {$entryRow['reference']} a été validée et postée au Grand Livre par " . ($_SESSION['user_name'] ?? 'un approbateur') . ".",
                    '/modules/accounting/ledger.php',
                    'info'
                );
            }

            sendResponse('success', 'Brouillard validé et transféré au Grand Livre.');
        }

        // 4. BATCH APPROVE — the "one click for the ≥ 90 %" flow.
        //    Payload: { action: 'batch_approve', ids: [1,2,3,...] }
        //    Every id must be draft, balanced, and confidence >= threshold.
        //    All-or-nothing: any failure rolls back the whole batch, and the
        //    UI re-reads the queue so the user sees why.
        if ($action === 'batch_approve') {
            Rbac::requirePermission('accounting.journal.approve');
            $ids = $payload['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) throw new Exception("Aucune écriture sélectionnée.");
            $ids = array_values(array_unique(array_map('intval', $ids)));

            $threshold = JournalConfidence::autoPostThreshold();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $st = $pdo->prepare("
                SELECT id, status, confidence_score, created_by
                  FROM journal_entries
                 WHERE id IN ($placeholders)
            ");
            $st->execute($ids);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($ids)) throw new Exception("Certaines écritures n'existent plus.");

            foreach ($rows as $r) {
                if ($r['status'] !== 'draft') {
                    throw new Exception("L'écriture #{$r['id']} n'est plus en brouillon.");
                }
                if ($r['confidence_score'] === null || (int)$r['confidence_score'] < $threshold) {
                    throw new Exception("L'écriture #{$r['id']} est en dessous du seuil de confiance ({$threshold} %).");
                }
            }

            // Post each. Stored proc rejects unbalanced rows — one bad row
            // rolls the whole transaction back and the user sees the message.
            $call = $pdo->prepare("CALL post_journal_entry(?, ?)");
            $count = 0;
            foreach ($ids as $id) {
                $call->execute([$id, $user_id]);
                $count++;
            }

            $pdo->commit();

            // Group by preparer so someone with 5 entries in the batch gets
            // one message, not five.
            $byPreparer = [];
            foreach ($rows as $r) {
                $cb = (int) ($r['created_by'] ?? 0);
                if ($cb > 0 && $cb !== $user_id) $byPreparer[$cb] = ($byPreparer[$cb] ?? 0) + 1;
            }
            foreach ($byPreparer as $preparerId => $n) {
                lpc_notify_user(
                    $pdo,
                    $preparerId,
                    'Écritures approuvées',
                    "$n de vos écritures ont été validées en lot et postées au Grand Livre.",
                    '/modules/accounting/ledger.php',
                    'info'
                );
            }

            sendResponse('success', "$count écriture(s) postée(s) au Grand Livre.", ['posted' => $count]);
        }

        // Unknown action falls through — roll back and complain.
        throw new Exception("Action inconnue : " . htmlspecialchars((string)$action));

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendResponse('error', $e->getMessage());
    }
} else {
    sendResponse('error', 'Méthode HTTP non autorisée.');
}
