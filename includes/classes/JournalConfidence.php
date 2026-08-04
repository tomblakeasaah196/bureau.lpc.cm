<?php
/**
 * includes/classes/JournalConfidence.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — rules-based confidence scoring for draft journal entries.
 *
 * WHY:
 *   The draft queue on modules/accounting/journal_entry.php lets an accountant
 *   batch-post entries. To do that safely, each entry needs a machine-computed
 *   confidence number and — critically — an audit trail of WHY it got that
 *   number. Auditors will not accept "the AI said so"; they will accept
 *   "this entry matches 87 prior entries with the same account pair and the
 *   amount is within 1σ of the historical median for this counterparty."
 *
 *   Every rule below is defensible in a review meeting. That's the point.
 *
 * WHAT COUNTS AS "CONFIDENCE":
 *   100 = "we've seen this exact pattern hundreds of times, nothing unusual".
 *     0 = "we've never seen anything like this, please look closely".
 *   Nothing about correctness — a balanced entry can score 20, an unbalanced
 *   one is rejected by post_journal_entry() long before it ever gets scored.
 *
 * HOW SCORES ARE STORED:
 *   Persisted on journal_entries.confidence_score (0..100 TINYINT) with a
 *   JSON breakdown on confidence_reasons — see migration 077. The controller
 *   calls ::scoreAndPersist() lazily when the queue is loaded; the score is
 *   cached on the row and only recomputed if confidence_scored_at is stale
 *   (older than 24 h) or missing.
 *
 * NO LLMs. Everything below is deterministic SQL over this database. A repeat
 * run on the same row + same history returns the same score.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class JournalConfidence
{
    /** Baseline score before any deductions. */
    private const BASELINE = 100;

    /** How far back "recent history" reaches for the vendor + amount rules. */
    private const LOOKBACK_MONTHS = 6;

    /** Sample size for "typical amount" comparisons. Below this we skip the rule. */
    private const MIN_SAMPLE = 5;

    /**
     * Fallback threshold used only when the Prefs table is unreachable OR when
     * migration 079 has not yet run. The live number is
     * `accounting_confidence_threshold` in `app_preferences`, read via
     * {@see JournalConfidence::autoPostThreshold()}. Kept as a class constant
     * (rather than deleted) so a Prefs outage never silently changes the
     * cutoff — 90 was the shipped default and stays the safety net.
     */
    public const THRESHOLD_AUTO_POST = 90;

    /**
     * Current auto-post threshold. Reads from Prefs on every call — Prefs
     * caches the whole table per request so this is a single array lookup,
     * cheap enough to invoke inside a loop. Clamped to [0..100] so a bad
     * value in the DB can't make the batch-approve endpoint refuse
     * everything or accept everything.
     */
    public static function autoPostThreshold(): int
    {
        // class_exists check keeps this file usable in unit tests where the
        // Prefs class + its Database dependency haven't been booted.
        if (!class_exists('Prefs')) return self::THRESHOLD_AUTO_POST;
        $v = Prefs::int('accounting_confidence_threshold', self::THRESHOLD_AUTO_POST);
        return max(0, min(100, $v));
    }

    /**
     * Compute the score for one entry WITHOUT persisting. Useful for previews.
     *
     * @return array{score:int, reasons:array<int,array{rule:string,message:string,delta:int}>}
     */
    public static function score(PDO $db, int $entry_id): array
    {
        $header = self::loadHeader($db, $entry_id);
        if (!$header) {
            return ['score' => 0, 'reasons' => [[
                'rule' => 'not_found',
                'message' => 'Écriture introuvable.',
                'delta' => -self::BASELINE,
            ]]];
        }

        $lines = self::loadLines($db, $entry_id);
        $reasons = [];
        $score = self::BASELINE;

        // Rule: balanced ledger. Unbalanced entries can't post anyway, but the
        // score should reflect that they're unfit for batch approval.
        [$dr, $cr] = self::totals($lines);
        if (abs($dr - $cr) > 0.01) {
            $reasons[] = self::reason('unbalanced', 'Débit ≠ Crédit — cette écriture ne peut pas être postée.', -60);
            $score -= 60;
        }
        if ($dr <= 0) {
            $reasons[] = self::reason('zero_amount', "Montant nul — l'écriture n'a pas d'effet comptable.", -30);
            $score -= 30;
        }

        // Rule: reference completeness. A blank or ultra-short reference means
        // no piece justificative — an OHADA audit red flag.
        $ref = trim((string) $header['reference']);
        if ($ref === '') {
            $reasons[] = self::reason('no_reference', 'Aucune référence de pièce.', -15);
            $score -= 15;
        } elseif (strlen($ref) < 4) {
            $reasons[] = self::reason('short_reference', 'Référence très courte (< 4 caractères).', -8);
            $score -= 8;
        }

        // Rule: description completeness. Cryptic descriptions are the
        // single biggest source of "what does this entry mean?" questions
        // at year-end. 8 chars is arbitrary but survives "OD divers".
        if (mb_strlen(trim((string) $header['description'])) < 8) {
            $reasons[] = self::reason('short_description', 'Libellé peu descriptif.', -10);
            $score -= 10;
        }

        // Rule: ERP-generated entries carry source_type/source_id (migration
        // 038). Those went through a controller that already validated the
        // upstream event (invoice issued, payment received, delivery COGS)
        // and used JournalPoster's hardcoded Dr/Cr templates — the account
        // pair CAN'T drift. Trust them more.
        if (!empty($header['source_type']) && !empty($header['source_id'])) {
            $reasons[] = self::reason('erp_source', 'Générée automatiquement par le module ' . $header['source_type'] . '.', +5);
            $score += 5;
        } else {
            $reasons[] = self::reason('manual_entry', 'Saisie manuelle (assistant).', -5);
            $score -= 5;
        }

        // Rule: account-pair familiarity. Every entry can be reduced to a set
        // of (debit account, credit account) pairs. Pairs that have never
        // co-occurred in prior posted entries are a warning sign — usually
        // a typo in the wizard.
        $pairs = self::pairsOf($lines);
        $unseen_pairs = [];
        foreach ($pairs as $p) {
            [$dr_id, $cr_id] = $p;
            if (self::pairSeenCount($db, $dr_id, $cr_id, $entry_id) === 0) {
                $unseen_pairs[] = $p;
            }
        }
        if (!empty($unseen_pairs)) {
            $n = count($unseen_pairs);
            $delta = -min(25, 8 * $n);
            $reasons[] = self::reason(
                'unseen_account_pair',
                $n === 1
                    ? 'Combinaison de comptes jamais vue dans le grand livre.'
                    : "$n combinaisons de comptes jamais vues dans le grand livre.",
                $delta
            );
            $score += $delta;
        }

        // Rule: amount deviation from historical median per account. For each
        // line, compare its debit-or-credit amount against the median of the
        // last N postings that touched the same account. 3× median is the
        // threshold — a router that usually processes 50 000 FCFA suddenly
        // seeing 500 000 FCFA is either a real spike or a data-entry slip.
        $deviating = [];
        foreach ($lines as $l) {
            $amount = max((float) $l['debit'], (float) $l['credit']);
            if ($amount <= 0) continue;
            $median = self::medianForAccount($db, (int) $l['account_id'], $entry_id);
            if ($median === null) continue;   // not enough history
            if ($median > 0 && $amount > 3 * $median) {
                $deviating[] = [
                    'code'   => $l['account_code'] ?? (string)$l['account_id'],
                    'amount' => $amount,
                    'median' => $median,
                ];
            }
        }
        if (!empty($deviating)) {
            $n = count($deviating);
            $delta = -min(25, 10 * $n);
            $sample = $deviating[0];
            $reasons[] = self::reason(
                'amount_outlier',
                sprintf(
                    '%s exceptionnel sur %s (%s F vs médiane %s F).',
                    $n === 1 ? 'Montant' : "$n montants",
                    $sample['code'],
                    number_format($sample['amount'], 0, ',', ' '),
                    number_format($sample['median'], 0, ',', ' ')
                ),
                $delta
            );
            $score += $delta;
        }

        // Rule: new source_type (an integration that just started firing).
        // If this is the very first entry from source_type=X in the ledger,
        // hold it for a human to sanity-check the mapping.
        if (!empty($header['source_type'])) {
            $prior = self::priorEntriesFromSource($db, $header['source_type'], $entry_id);
            if ($prior === 0) {
                $reasons[] = self::reason(
                    'first_from_source',
                    "Première écriture provenant de « {$header['source_type']} » — vérifiez le mapping.",
                    -15
                );
                $score -= 15;
            }
        }

        // Clamp and return.
        $score = max(0, min(100, $score));

        // Sort reasons by absolute impact so the UI tooltip leads with what
        // moved the needle most.
        usort($reasons, fn($a, $b) => abs($b['delta']) <=> abs($a['delta']));

        return ['score' => $score, 'reasons' => $reasons];
    }

    /**
     * Score AND persist to the row. Cheap enough to call on every queue read
     * for the drafts on screen (typically < 50 rows). Returns the score so
     * the caller can render without a second SELECT.
     */
    public static function scoreAndPersist(PDO $db, int $entry_id): array
    {
        $result = self::score($db, $entry_id);
        $db->prepare("
            UPDATE journal_entries
               SET confidence_score      = ?,
                   confidence_reasons    = ?,
                   confidence_scored_at  = NOW()
             WHERE id = ?
        ")->execute([
            $result['score'],
            json_encode($result['reasons'], JSON_UNESCAPED_UNICODE),
            $entry_id,
        ]);
        return $result;
    }

    /**
     * Rescore all drafts whose score is stale (older than $maxAgeHours) or
     * never computed. Returns the number rescored. Called lazily by the
     * controller on queue read.
     */
    public static function rescoreStale(PDO $db, int $maxAgeHours = 24): int
    {
        $stmt = $db->prepare("
            SELECT id
              FROM journal_entries
             WHERE status = 'draft'
               AND (confidence_scored_at IS NULL
                    OR confidence_scored_at < (NOW() - INTERVAL ? HOUR))
        ");
        $stmt->execute([$maxAgeHours]);
        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            self::scoreAndPersist($db, (int) $id);
            $count++;
        }
        return $count;
    }

    // ------------------------------------------------------------------ helpers

    private static function loadHeader(PDO $db, int $id): ?array
    {
        // source_type/source_id only exist after migration 038; guard the
        // SELECT so this class works on installs stuck on 037. Detected the
        // same way JournalPoster does it.
        $hasSource = self::hasSourceColumns($db);
        $cols = 'id, reference, description, status'
              . ($hasSource ? ', source_type, source_id' : '');
        $stmt = $db->prepare("SELECT $cols FROM journal_entries WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function loadLines(PDO $db, int $entry_id): array
    {
        $stmt = $db->prepare("
            SELECT jl.account_id, jl.debit, jl.credit, coa.code AS account_code
              FROM journal_lines jl
              JOIN chart_of_accounts coa ON coa.id = jl.account_id
             WHERE jl.journal_entry_id = ?
        ");
        $stmt->execute([$entry_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function totals(array $lines): array
    {
        $dr = 0.0; $cr = 0.0;
        foreach ($lines as $l) { $dr += (float) $l['debit']; $cr += (float) $l['credit']; }
        return [$dr, $cr];
    }

    /**
     * Enumerate (debit_account_id, credit_account_id) pairs for an entry.
     * Cross-product of debit lines × credit lines — good enough for the
     * typical 2-line or 3-line manual entry, and even 6-line entries only
     * produce 9 pairs to check.
     */
    private static function pairsOf(array $lines): array
    {
        $debits  = array_filter($lines, fn($l) => (float) $l['debit']  > 0);
        $credits = array_filter($lines, fn($l) => (float) $l['credit'] > 0);
        $out = [];
        foreach ($debits as $d) {
            foreach ($credits as $c) {
                $out[] = [(int) $d['account_id'], (int) $c['account_id']];
            }
        }
        return $out;
    }

    /**
     * Count how many PRIOR posted entries contained both the given debit
     * account and the given credit account. Excludes the entry being scored.
     */
    private static function pairSeenCount(PDO $db, int $dr_id, int $cr_id, int $exclude_id): int
    {
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT je.id)
              FROM journal_entries je
              JOIN journal_lines dl ON dl.journal_entry_id = je.id AND dl.account_id = ? AND dl.debit  > 0
              JOIN journal_lines cl ON cl.journal_entry_id = je.id AND cl.account_id = ? AND cl.credit > 0
             WHERE je.status = 'posted'
               AND je.id <> ?
               AND je.date >= (CURDATE() - INTERVAL ? MONTH)
        ");
        $stmt->execute([$dr_id, $cr_id, $exclude_id, self::LOOKBACK_MONTHS]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Median debit-or-credit amount for one account, from posted entries in
     * the lookback window. NULL if fewer than MIN_SAMPLE rows.
     *
     * We pick the "middle-ranked" value with LIMIT + OFFSET rather than a
     * PERCENTILE_CONT window function — MariaDB 10.5 doesn't have those.
     */
    private static function medianForAccount(PDO $db, int $account_id, int $exclude_id): ?float
    {
        $cnt = $db->prepare("
            SELECT COUNT(*)
              FROM journal_lines jl
              JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ?
               AND je.status = 'posted'
               AND je.id <> ?
               AND je.date >= (CURDATE() - INTERVAL ? MONTH)
        ");
        $cnt->execute([$account_id, $exclude_id, self::LOOKBACK_MONTHS]);
        $n = (int) $cnt->fetchColumn();
        if ($n < self::MIN_SAMPLE) return null;

        $offset = (int) floor($n / 2);
        $q = $db->prepare("
            SELECT GREATEST(jl.debit, jl.credit) AS amt
              FROM journal_lines jl
              JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ?
               AND je.status = 'posted'
               AND je.id <> ?
               AND je.date >= (CURDATE() - INTERVAL ? MONTH)
             ORDER BY amt ASC
             LIMIT 1 OFFSET $offset
        ");
        $q->execute([$account_id, $exclude_id, self::LOOKBACK_MONTHS]);
        $val = $q->fetchColumn();
        return $val === false ? null : (float) $val;
    }

    private static function priorEntriesFromSource(PDO $db, string $source_type, int $exclude_id): int
    {
        if (!self::hasSourceColumns($db)) return 1;   // pre-038: can't tell, don't penalise
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM journal_entries
             WHERE source_type = ? AND id <> ?
        ");
        $stmt->execute([$source_type, $exclude_id]);
        return (int) $stmt->fetchColumn();
    }

    /** True once migration 038 has added journal_entries.source_type. */
    private static function hasSourceColumns(PDO $db): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        $chk = $db->query("
            SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name   = 'journal_entries'
               AND column_name  = 'source_type'
        ")->fetchColumn();
        return $has = ((int) $chk > 0);
    }

    private static function reason(string $rule, string $message, int $delta): array
    {
        return ['rule' => $rule, 'message' => $message, 'delta' => $delta];
    }
}
