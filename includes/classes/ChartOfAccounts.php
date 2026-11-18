<?php
/**
 * includes/classes/ChartOfAccounts.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · the chart's sub-accounts (the owner's decisions 12 to 15,
 * 18/11/2026).
 *
 * A row of chart_of_accounts is either the OHADA parent's own posting row
 * (code = the OHADA number, « 601 Achats de marchandises ») or a sub-account
 * under it (« 5211 CCA Bank », « 466001 SMART LOGISTICS… »). This class is
 * what every screen uses to:
 *   · know what still points at a row (links()), so a row is deleted only
 *     when nothing uses it: no journal line, no client, supplier, treasury
 *     account, product, category, fixed asset, loan counterparty or setting;
 *   · give a new sub-account the next free code under its parent
 *     (nextSubCode()) and create it (createSub()).
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class ChartOfAccounts
{
    /**
     * Every column that stores a chart_of_accounts.id, with what the screen
     * calls it. Probed against information_schema, so a table an install does
     * not have is skipped.
     */
    public const LINKS = [
        ['clients',               'account_id',               'client'],
        ['suppliers',             'account_id',               'fournisseur'],
        ['treasury_accounts',     'coa_account_id',           'compte de trésorerie'],
        ['products',              'revenue_account_id',       'produit (vente)'],
        ['products',              'stock_account_id',         'produit (stock)'],
        ['products',              'cogs_account_id',          'produit (coût)'],
        ['product_categories',    'revenue_account_id',       'catégorie de produit (vente)'],
        ['product_categories',    'stock_account_id',         'catégorie de produit (stock)'],
        ['product_categories',    'cogs_account_id',          'catégorie de produit (coût)'],
        ['expense_categories',    'coa_account_id',           'catégorie de dépense'],
        ['fixed_assets',          'asset_account_id',         'immobilisation'],
        ['fixed_assets',          'depr_account_id',          'immobilisation (amortissement)'],
        ['fixed_assets',          'expense_account_id',       'immobilisation (dotation)'],
        ['fixed_assets',          'disposal_cash_account_id', 'cession d\'immobilisation'],
        ['fixed_asset_disposals', 'cash_account_id',          'cession d\'immobilisation'],
        ['vehicle_expenses',      'expense_account_id',       'dépense de véhicule'],
        ['loan_counterparties',   'borrow_account_id',        'emprunt'],
        ['loan_counterparties',   'lend_account_id',          'prêt'],
    ];

    /**
     * What points at each row: [coa id => ['lines' => n, 'links' => ['client' => 1, ...]]].
     * One query per link column, so the whole chart costs a dozen small queries.
     *
     * @param int[]|null $ids  only these rows (null: the whole chart)
     * @return array<int,array{lines:int,links:array<string,int>}>
     */
    public static function usage(PDO $db, ?array $ids = null): array
    {
        $out = [];
        $filter = '';
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!$ids) return [];
            $filter = ' IN (' . implode(',', $ids) . ')';
        }

        $q = $db->query("SELECT account_id, COUNT(*) FROM journal_lines"
            . ($filter ? " WHERE account_id{$filter}" : '') . " GROUP BY account_id");
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$id, $n]) {
            $out[(int) $id]['lines'] = (int) $n;
        }

        foreach (self::LINKS as [$table, $col, $label]) {
            if (!self::hasColumn($db, $table, $col)) continue;
            $where = "`{$col}` IS NOT NULL" . ($filter ? " AND `{$col}`{$filter}" : '');
            // Soft-deleted rows still hold their account: they can come back.
            $q = $db->query("SELECT `{$col}`, COUNT(*) FROM `{$table}` WHERE {$where} GROUP BY `{$col}`");
            foreach ($q->fetchAll(PDO::FETCH_NUM) as [$id, $n]) {
                $out[(int) $id]['links'][$label] = ($out[(int) $id]['links'][$label] ?? 0) + (int) $n;
            }
        }

        // Settings that store an account id (accounting_*_account_id).
        $prefs = $db->query("SELECT pref_key, pref_value FROM app_preferences WHERE pref_key LIKE '%account_id'");
        foreach ($prefs ? $prefs->fetchAll(PDO::FETCH_NUM) : [] as [$key, $val]) {
            $id = (int) $val;
            if ($id <= 0 || ($ids !== null && !in_array($id, $ids, true))) continue;
            $out[$id]['links']['paramètre'] = ($out[$id]['links']['paramètre'] ?? 0) + 1;
        }

        foreach ($out as $id => $u) {
            $out[$id] += ['lines' => 0, 'links' => []];
        }
        return $out;
    }

    /** Why a row cannot be deleted, in French; '' when it can. */
    public static function whyNotDeletable(PDO $db, int $id): string
    {
        // The parent's own row (code = the OHADA number) is where the automatic
        // entries post (JournalPoster::coaByOhada): it stays.
        $own = $db->prepare("SELECT o.account_number FROM chart_of_accounts c
                               JOIN ohada_accounts o ON o.id = c.ohada_account_id
                              WHERE c.id = ? AND c.code = o.account_number");
        $own->execute([$id]);
        if (($n = $own->fetchColumn()) !== false) {
            return "C'est le compte propre du parent OHADA {$n} : les écritures automatiques y passent. Il ne se supprime pas.";
        }
        $u = self::usage($db, [$id])[$id] ?? ['lines' => 0, 'links' => []];
        $why = [];
        if ($u['lines'] > 0) $why[] = $u['lines'] . ' ligne(s) d\'écriture';
        foreach ($u['links'] as $label => $n) $why[] = "{$n} {$label}";
        return $why ? 'Ce compte est utilisé : ' . implode(', ', $why) . '. Désactivez-le plutôt.' : '';
    }

    /**
     * The next free code under an OHADA parent.
     *   $width 1: 5211, 5212… (treasury accounts, the owner's « 5211 CCA Bank »);
     *   $width 3: 466001, 466002… (clients, suppliers, loan counterparties).
     * The first free number, so a deleted code is not taken again while a
     * higher one exists only if nothing below is free.
     */
    public static function nextSubCode(PDO $db, string $parent, int $width = 3): string
    {
        $stmt = $db->prepare("SELECT code FROM chart_of_accounts WHERE code LIKE ?");
        $stmt->execute([$parent . '%']);
        $taken = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        for ($w = $width; $w <= 6; $w++) {
            $max = 10 ** $w - 1;
            for ($n = 1; $n <= $max; $n++) {
                $code = $parent . str_pad((string) $n, $w, '0', STR_PAD_LEFT);
                if (!isset($taken[$code])) return $code;
            }
        }
        throw new RuntimeException("Plus aucun code libre sous {$parent}.");
    }

    /**
     * Create a sub-account under the OHADA parent $parentNumber; returns its id.
     * The parent must exist (the migrations seed every parent the code uses).
     */
    public static function createSub(PDO $db, string $parentNumber, string $name, int $width = 3, ?string $code = null): int
    {
        $stmt = $db->prepare("SELECT id, type, tft_category FROM ohada_accounts WHERE account_number = ? LIMIT 1");
        $stmt->execute([$parentNumber]);
        $ohada = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ohada) {
            throw new RuntimeException("Le compte OHADA {$parentNumber} manque au plan comptable.");
        }
        $code = $code ?? self::nextSubCode($db, $parentNumber, $width);
        $type = in_array($ohada['type'], ['asset', 'liability', 'equity', 'revenue', 'expense'], true) ? $ohada['type'] : 'asset';
        $db->prepare("
            INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active, tft_category)
            VALUES (?, ?, ?, ?, 1, ?)
        ")->execute([(int) $ohada['id'], $code, mb_substr(trim($name), 0, 100), $type, $ohada['tft_category'] ?: 'operating']);
        return (int) $db->lastInsertId();
    }

    private static function hasColumn(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = "{$table}.{$column}";
        if (!array_key_exists($key, $cache)) {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
            ");
            $stmt->execute([$table, $column]);
            $cache[$key] = ((int) $stmt->fetchColumn() > 0);
        }
        return $cache[$key];
    }
}
