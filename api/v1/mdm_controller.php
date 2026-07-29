<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';   // Sprint 5
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('admin.master_data.view');
// api/v1/mdm_controller.php
header('Content-Type: application/json');

require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(401); echo json_encode(['status' => 'error', 'message' => 'Accès Refusé']); exit;
}

// =============================================================================
// PRODUCT MASTER DATA HELPERS  (Sprint 9 · migration 041)
// -----------------------------------------------------------------------------
// Everything below exists to remove three classes of silent data loss from
// product creation:
//   · the category ENUM failsafe that rewrote anything unrecognised to 'Eau'
//     (that is how "Jus 30L" ended up filed as water);
//   · empties saved with no bottle_size / has_cork, invisible to the empties
//     ledger even though cre_controller looks them up by exactly those columns;
//   · pack sizes with nowhere to live, forcing two controllers to parse the
//     SKU string instead.
// =============================================================================

/**
 * A message the user is meant to read.
 *
 * The catch-all at the bottom of this file deliberately replaces exception
 * messages with a generic string so internal errors can't leak schema details
 * — correct for a 500, wrong for "this code is already taken", which the user
 * has to see to act on. This subclass draws that line: MdmValidationException
 * surfaces verbatim with a 400, everything else stays opaque with a 500.
 */
class MdmValidationException extends Exception {}

/** Physical packaging vocabulary. Fixed on purpose — not admin-editable. */
const MDM_UOMS = [
    ['v' => 'unite',     'l' => 'Unité'],
    ['v' => 'bouteille', 'l' => 'Bouteille'],
    ['v' => 'bonbonne',  'l' => 'Bonbonne'],
    ['v' => 'sachet',    'l' => 'Sachet'],
    ['v' => 'carton',    'l' => 'Carton'],
    ['v' => 'pack',      'l' => 'Pack'],
    ['v' => 'palette',   'l' => 'Palette'],
];

/** True once migration 041 is applied. Cached per request. */
function mdm_has_product_master_v2(PDO $db): bool
{
    static $has = null;
    if ($has !== null) return $has;

    $cols = (int) $db->query("
        SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'products'
           AND column_name IN ('category_id','unit_of_measure','units_per_pack','sold_by',
                               'revenue_account_id','stock_account_id','cogs_account_id')
    ")->fetchColumn();

    $tbl = (int) $db->query("
        SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'product_categories'
    ")->fetchColumn();

    return $has = ($cols === 7 && $tbl === 1);
}

/**
 * Normalise a size token for use inside a SKU: "1.5 L" -> "1.5L", "20l" -> "20L".
 * Returns '' when there is nothing usable, in which case the SKU has no size
 * segment at all rather than an empty one.
 */
function mdm_size_token(?string $raw): string
{
    $s = strtoupper(trim((string) $raw));
    $s = preg_replace('/\s+/', '', $s);
    $s = preg_replace('/[^A-Z0-9.]/', '', $s);
    return substr((string) $s, 0, 8);
}

/**
 * Build the next free SKU for a category: WAT-20L-004 / JUS-1L-001 / EQP-002.
 *
 * The sequence is derived from the codes already in the table rather than a
 * counter column, so it survives manual inserts and imported SKUs — the price
 * being that it is a MAX() scan, which is free at this table size.
 *
 * Always returns something unique: if the computed code is somehow taken
 * (legacy row with an odd suffix), it walks forward until it isn't.
 */
function mdm_next_product_code(PDO $db, string $prefix, ?string $size): string
{
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'PRD');
    $size   = mdm_size_token($size);
    $stem   = $size !== '' ? "{$prefix}-{$size}-" : "{$prefix}-";

    $stmt = $db->prepare("
        SELECT code FROM products
         WHERE code LIKE CONCAT(?, '%')
         ORDER BY LENGTH(code) DESC, code DESC
    ");
    $stmt->execute([$stem]);

    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
        $tail = substr((string) $code, strlen($stem));
        if (preg_match('/^(\d+)$/', $tail, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }

    $exists = $db->prepare("SELECT 1 FROM products WHERE code = ? LIMIT 1");
    for ($n = $max + 1; $n < $max + 500; $n++) {
        $candidate = $stem . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        $exists->execute([$candidate]);
        if (!$exists->fetchColumn()) return $candidate;
    }
    // Pathological fallback — never collides, never pretty.
    return $stem . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Find (or mint) a chart_of_accounts row for an OHADA-style account number.
 *
 * Mirrors what supplier creation already does for 401xxx: an account the
 * business needs should not require a trip to the accountant to exist. Both
 * the ohada_accounts parent and the chart_of_accounts row are created, because
 * JournalPoster::coaByOhada joins the two and a COA row with no parent is
 * unusable.
 *
 * @return int chart_of_accounts.id
 */
function mdm_ensure_account(PDO $db, string $number, string $name, string $type): int
{
    $stmt = $db->prepare("SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1");
    $stmt->execute([$number]);
    if ($id = $stmt->fetchColumn()) return (int) $id;

    $stmt = $db->prepare("SELECT id FROM ohada_accounts WHERE account_number = ? LIMIT 1");
    $stmt->execute([$number]);
    $ohada_id = $stmt->fetchColumn();

    if (!$ohada_id) {
        $db->prepare("INSERT INTO ohada_accounts (account_number, name, type) VALUES (?, ?, ?)")
           ->execute([$number, $name, $type]);
        $ohada_id = $db->lastInsertId();
    }

    $db->prepare("INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active) VALUES (?, ?, ?, ?, 1)")
       ->execute([$ohada_id, $number, $name, $type]);

    return (int) $db->lastInsertId();
}

/**
 * Next free sub-account under a parent class: nextSub('701') -> '7013' when
 * 7011 and 7012 are taken. Used when a category is created from the UI.
 */
function mdm_next_sub_account(PDO $db, string $parent): string
{
    $stmt = $db->prepare("
        SELECT code FROM chart_of_accounts
         WHERE code REGEXP CONCAT('^', ?, '[0-9]+$')
    ");
    $stmt->execute([$parent]);

    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
        $tail = substr((string) $code, strlen($parent));
        if (ctype_digit($tail)) $max = max($max, (int) $tail);
    }
    return $parent . ($max + 1);
}

/** Strip accents so "Équipement" yields the prefix EQU, not a mangled byte. */
function mdm_ascii_upper(string $s): string
{
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $t !== false ? $t : $s));
}

/** A unique 3-4 char SKU prefix derived from a category name. */
function mdm_derive_prefix(PDO $db, string $name): string
{
    $base = substr(mdm_ascii_upper($name), 0, 3);
    if ($base === '') $base = 'CAT';

    $stmt = $db->prepare("SELECT 1 FROM product_categories WHERE sku_prefix = ? LIMIT 1");
    $stmt->execute([$base]);
    if (!$stmt->fetchColumn()) return $base;

    for ($i = 2; $i <= 9; $i++) {
        $try = $base . $i;
        $stmt->execute([$try]);
        if (!$stmt->fetchColumn()) return $try;
    }
    return $base . strtoupper(bin2hex(random_bytes(1)));
}

try {
    $db = Database::getInstance()->getConnection();
    $action = $_REQUEST['action'] ?? '';
    $module = $_REQUEST['module'] ?? '';

    // =========================================================================
    // ACTION: READ (Fetch Data + Meta dropdowns)
    // =========================================================================
    if ($action === 'read') {
        $response = ['status' => 'success', 'data' => [], 'meta' => []];
        // Sprint 5: server-side pagination + search.
        $lpc_q = trim((string) ($_REQUEST['q'] ?? ''));

        if ($module === 'products') {
            $has_v2 = mdm_has_product_master_v2($db);

            // Sprint 9 · migration 041. The v1 branch is kept so the page still
            // renders against an un-migrated database — the form degrades to
            // the old field set rather than 500-ing on a missing column.
            if ($has_v2) {
                $body = "
                    FROM products p
                    LEFT JOIN products           pe ON p.linked_empty_id = pe.id
                    LEFT JOIN product_categories pc ON pc.id = p.category_id
                    LEFT JOIN chart_of_accounts  ra ON ra.id = COALESCE(p.revenue_account_id, pc.revenue_account_id)
                ";
                $searchable = ['p.code', 'p.name', 'p.category', 'p.format', 'pe.name', 'pc.name'];
                $select = "p.*, pe.name AS linked_empty_name,
                           pc.code AS category_code, pc.name AS category_label,
                           pc.sku_prefix, pc.is_empty_container, pc.requires_emballage, pc.allows_packs,
                           ra.code AS revenue_account_code, ra.name AS revenue_account_name,
                           (p.revenue_account_id IS NOT NULL) AS revenue_is_override";
            } else {
                $body = "
                    FROM products p
                    LEFT JOIN products pe ON p.linked_empty_id = pe.id
                ";
                $searchable = ['p.name', 'p.category', 'p.format', 'pe.name'];
                $select = "p.*, pe.name AS linked_empty_name";
            }

            $params = [];
            if ($lpc_q !== '') {
                [$body, $params] = Paginator::addWhere($body, $params, $lpc_q, $searchable);
            }
            // Order empties so cork variants of the same size sit next to each
            // other — the list groups them into one family row.
            $body .= $has_v2
                ? " ORDER BY p.is_empty ASC, p.bottle_size DESC, p.has_cork ASC, p.id DESC"
                : " ORDER BY p.id DESC";

            $page = Paginator::paginate($db, $body, $params, $select,
                null, null, "mdm.read.products");
            $response['data'] = $page['data'];
            $response['pagination'] = [
                'page'        => $page['page'],
                'per_page'    => $page['per_page'],
                'total'       => $page['total'],
                'total_pages' => $page['total_pages'],
                'has_prev'    => $page['has_prev'],
                'has_next'    => $page['has_next'],
            ];

            // Emballage dropdown. Carries bottle_size / has_cork so the form can
            // label them "10L · avec bouchon" instead of relying on the name.
            $response['meta']['empties'] = $db->query(
                $has_v2
                ? "SELECT p.id, p.name, p.bottle_size, p.has_cork, p.base_price
                     FROM products p
                     LEFT JOIN product_categories pc ON pc.id = p.category_id
                    WHERE (pc.is_empty_container = 1 OR p.is_empty = 1 OR p.category = 'Emballage')
                      AND p.is_active = 1
                    ORDER BY p.bottle_size DESC, p.has_cork ASC LIMIT 500"
                : "SELECT id, name FROM products WHERE category = 'Emballage' AND is_active = 1
                    ORDER BY name ASC LIMIT 500"
            )->fetchAll(PDO::FETCH_ASSOC);

            if ($has_v2) {
                $response['meta']['categories'] = $db->query("
                    SELECT pc.id, pc.code, pc.name, pc.sku_prefix,
                           pc.is_empty_container, pc.requires_emballage, pc.allows_packs,
                           r.code AS revenue_code, r.name AS revenue_name
                      FROM product_categories pc
                      LEFT JOIN chart_of_accounts r ON r.id = pc.revenue_account_id
                     WHERE pc.is_active = 1
                     ORDER BY pc.sort_order ASC, pc.name ASC
                ")->fetchAll(PDO::FETCH_ASSOC);

                // Units of measure are a fixed vocabulary, not a table — they
                // describe physical packaging, not something an admin invents.
                $response['meta']['uoms'] = MDM_UOMS;
            }
            $response['meta']['schema_v2'] = $has_v2;
        }
        elseif ($module === 'employees') {
            $body = "
                FROM users u
                JOIN roles r ON u.role_id = r.id
                LEFT JOIN employee_profiles ep ON u.id = ep.user_id
            ";
            $params = [];
            if ($lpc_q !== '') {
                [$body, $params] = Paginator::addWhere(
                    $body, $params, $lpc_q,
                    ['u.first_name', 'u.last_name', 'u.employee_code', 'u.email', 'r.name', 'ep.job_title']
                );
            }
            $body .= " ORDER BY u.id DESC";
            $page = Paginator::paginate($db, $body, $params,
                "u.id, u.employee_code, u.first_name, u.last_name,
                 CONCAT(u.first_name, ' ', u.last_name) as full_name, u.email, u.status,
                 r.name as role_name, r.id as role_id,
                 ep.job_title, ep.phone, ep.base_salary, ep.avatar",
                null, null, "mdm.read.employees");
            $response['data'] = $page['data'];
            $response['pagination'] = [
                'page'        => $page['page'],
                'per_page'    => $page['per_page'],
                'total'       => $page['total'],
                'total_pages' => $page['total_pages'],
                'has_prev'    => $page['has_prev'],
                'has_next'    => $page['has_next'],
            ];
            $response['meta']['roles'] = $db->query("SELECT id, name FROM roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode($response); exit;
    }

    // =========================================================================
    // ACTION: NEXT_CODE — live SKU suggestion for the product form.
    // -------------------------------------------------------------------------
    // Called on every category / format change so the code field fills itself
    // as you type. Read-only and cheap; the authoritative generation still
    // happens server-side on save, because two people can have the form open.
    // =========================================================================
    if ($action === 'next_code' && $module === 'products') {
        if (!mdm_has_product_master_v2($db)) {
            echo json_encode(['status' => 'success', 'code' => '']); exit;
        }
        $stmt = $db->prepare("SELECT sku_prefix FROM product_categories WHERE id = ?");
        $stmt->execute([(int) ($_REQUEST['category_id'] ?? 0)]);
        $prefix = $stmt->fetchColumn();
        if (!$prefix) { echo json_encode(['status' => 'success', 'code' => '']); exit; }

        $size = $_REQUEST['bottle_size'] ?? ($_REQUEST['format'] ?? '');
        echo json_encode([
            'status' => 'success',
            'code'   => mdm_next_product_code($db, (string) $prefix, (string) $size),
        ]);
        exit;
    }

    // =========================================================================
    // ACTION: SAVE_CATEGORY — create a product category from inside the
    // product modal.
    // -------------------------------------------------------------------------
    // Adding a category used to mean a migration plus edits in three files.
    // Creating one here also mints its revenue and stock accounts, the same
    // way saving a supplier mints its 401xxx — a category with no revenue
    // account would just fall back to the blanket 701 and quietly recreate the
    // problem this sprint exists to fix.
    // =========================================================================
    if ($action === 'save_category') {
        if (!mdm_has_product_master_v2($db)) {
            throw new MdmValidationException("Migration 041 requise avant d'ajouter des catégories.");
        }

        $cat_name = trim($_POST['name'] ?? '');
        if ($cat_name === '')             throw new MdmValidationException("Le nom de la catégorie est obligatoire.");
        if (mb_strlen($cat_name) > 64)    throw new MdmValidationException("Nom de catégorie trop long (64 caractères max).");

        $chk = $db->prepare("SELECT id, is_active FROM product_categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $chk->execute([$cat_name]);
        if ($existing = $chk->fetch(PDO::FETCH_ASSOC)) {
            // Re-activate rather than refuse: a category someone archived last
            // year should come back, not collide.
            if (!$existing['is_active']) {
                $db->prepare("UPDATE product_categories SET is_active = 1 WHERE id = ?")->execute([$existing['id']]);
                echo json_encode(['status' => 'success', 'id' => (int) $existing['id'], 'reactivated' => true]); exit;
            }
            throw new MdmValidationException("La catégorie « {$cat_name} » existe déjà.");
        }

        $is_container       = (int) !empty($_POST['is_empty_container']);
        $requires_emballage = (int) !empty($_POST['requires_emballage']);
        $allows_packs       = (int) !empty($_POST['allows_packs']);

        $prefix = strtoupper(trim($_POST['sku_prefix'] ?? ''));
        $prefix = substr((string) preg_replace('/[^A-Z0-9]/', '', $prefix), 0, 8);  // column is VARCHAR(8)
        if ($prefix === '') {
            $prefix = mdm_derive_prefix($db, $cat_name);
        } else {
            $p = $db->prepare("SELECT 1 FROM product_categories WHERE sku_prefix = ? LIMIT 1");
            $p->execute([$prefix]);
            if ($p->fetchColumn()) throw new MdmValidationException("Le préfixe « {$prefix} » est déjà utilisé.");
        }

        $db->beginTransaction();
        try {
            // Revenue sits under 701 (Ventes), stock under 31 (Stocks). COGS
            // reuses 6031 — OHADA expects one variation account per stock
            // class, and splitting it per category buys nothing the stock
            // breakdown doesn't already give.
            $revenue_code = mdm_next_sub_account($db, '701');
            $stock_code   = mdm_next_sub_account($db, '31');

            $revenue_id = mdm_ensure_account($db, $revenue_code, "Ventes - {$cat_name}",  'revenue');
            $stock_id   = mdm_ensure_account($db, $stock_code,   "Stocks - {$cat_name}",  'asset');
            $cogs_id    = mdm_ensure_account($db, '6031', 'Variations des stocks de marchandises', 'expense');

            $next_sort = (int) $db->query("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM product_categories")->fetchColumn();

            // Machine key: ASCII, lowercase, capped at the column width (32) so
            // a long display name can't overflow it, and de-duplicated because
            // "Jus d'ananas" and "Jus ananas" collapse to the same slug.
            $cat_code = substr(strtolower(mdm_ascii_upper($cat_name)), 0, 28) ?: strtolower($prefix);
            $codeChk  = $db->prepare("SELECT 1 FROM product_categories WHERE code = ? LIMIT 1");
            $codeChk->execute([$cat_code]);
            if ($codeChk->fetchColumn()) {
                for ($i = 2; $i <= 99; $i++) {
                    $codeChk->execute([$cat_code . $i]);
                    if (!$codeChk->fetchColumn()) { $cat_code .= $i; break; }
                }
            }

            $db->prepare("
                INSERT INTO product_categories
                    (code, name, sku_prefix, revenue_ohada, stock_ohada, cogs_ohada,
                     revenue_account_id, stock_account_id, cogs_account_id,
                     is_empty_container, requires_emballage, allows_packs, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, '6031', ?, ?, ?, ?, ?, ?, ?, 1)
            ")->execute([
                $cat_code,
                $cat_name, $prefix, $revenue_code, $stock_code,
                $revenue_id, $stock_id, $cogs_id,
                $is_container, $requires_emballage, $allows_packs, $next_sort,
            ]);
            $new_id = (int) $db->lastInsertId();

            $db->commit();
            echo json_encode([
                'status'       => 'success',
                'id'           => $new_id,
                'name'         => $cat_name,
                'sku_prefix'   => $prefix,
                'revenue_code' => $revenue_code,
                'stock_code'   => $stock_code,
            ]);
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // ACTION: TOGGLE STATUS (Soft Deletes)
    // -------------------------------------------------------------------------
    // SECURITY: table/column names cannot come from user input directly. We
    // map $_POST['module'] through a hard-coded whitelist so a hostile client
    // supplying module=users can't inject arbitrary SQL. See AUDIT_REPORT §2.3.
    // =========================================================================
    if ($action === 'toggle_status' && $module !== 'pricing') {
        // (table, column, on-value, off-value) per allowed module.
        static $TOGGLE_MAP = [
            'fleet'     => ['table' => 'vehicles',  'col' => 'status',    'on' => 'active', 'off' => 'inactive'],
            'employees' => ['table' => 'users',     'col' => 'status',    'on' => 'active', 'off' => 'inactive'],
            'products'  => ['table' => 'products',  'col' => 'is_active', 'on' => 1,        'off' => 0],
            'suppliers' => ['table' => 'suppliers', 'col' => 'is_active', 'on' => 1,        'off' => 0],
            'clients'   => ['table' => 'clients',   'col' => 'is_active', 'on' => 1,        'off' => 0],
        ];

        if (!isset($TOGGLE_MAP[$module])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Module non autorisé.']);
            exit;
        }
        $map = $TOGGLE_MAP[$module];

        $id           = (int) ($_POST['id'] ?? 0);
        $current_flag = (int) ($_POST['is_active'] ?? 0);   // treats truthy "on"
        $newFlag      = $current_flag === 1 ? 0 : 1;
        $val          = $newFlag ? $map['on'] : $map['off'];

        // Table and column are HARD-CODED strings from the whitelist above —
        // never sourced from user input, so the format-string usage is safe.
        $sql = sprintf("UPDATE `%s` SET `%s` = ? WHERE id = ?", $map['table'], $map['col']);
        $db->prepare($sql)->execute([$val, $id]);
        echo json_encode(['status' => 'success']); exit;
    }

    // =========================================================================
    // ACTION: SAVE (Insert/Update including File Uploads)
    // =========================================================================
    if ($action === 'save') {
        $id = !empty($_POST['id']) ? $_POST['id'] : null;

        if ($module === 'products') {
            // -----------------------------------------------------------------
            // Sprint 9. The old branch silently coerced any unknown category to
            // 'Eau' and dropped the empties metadata on the floor. Categories
            // are now validated against the table, and a mismatch is an error
            // the user sees rather than a rewrite they don't.
            // -----------------------------------------------------------------
            $has_v2 = mdm_has_product_master_v2($db);

            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new MdmValidationException("La désignation est obligatoire.");

            $base_price = (float) ($_POST['base_price'] ?? 0);
            if ($base_price < 0) throw new MdmValidationException("Le prix de base ne peut pas être négatif.");

            $format = trim($_POST['format'] ?? '') ?: null;

            // Emballage is genuinely optional — plenty of products ship without
            // one. The UI used to mark it required by accident (every
            // dynamic_select got a `required` attribute), which made those
            // products unsaveable.
            $linked_empty = !empty($_POST['linked_empty_id']) ? (int) $_POST['linked_empty_id'] : null;

            if (!$has_v2) {
                // Pre-migration fallback: old columns only.
                $allowed_categories = ['Eau', 'Emballage', 'Equipement'];
                $safe_category = ucfirst(strtolower(trim($_POST['category'] ?? '')));
                if (!in_array($safe_category, $allowed_categories, true)) {
                    throw new MdmValidationException("Catégorie inconnue. Appliquez la migration 041 pour ajouter des catégories.");
                }
                $code = trim($_POST['code'] ?? '');
                if ($code === '') throw new MdmValidationException("Le code produit est obligatoire.");

                if ($id) {
                    $db->prepare("UPDATE products SET code=?, name=?, format=?, category=?, base_price=?, linked_empty_id=? WHERE id=?")
                       ->execute([$code, $name, $format, $safe_category, $base_price, $linked_empty, $id]);
                } else {
                    $db->prepare("INSERT INTO products (code, name, format, category, base_price, is_active, linked_empty_id) VALUES (?, ?, ?, ?, ?, 1, ?)")
                       ->execute([$code, $name, $format, $safe_category, $base_price, $linked_empty]);
                }
                echo json_encode(['status' => 'success']); exit;
            }

            // ---- v2 path --------------------------------------------------
            $category_id = (int) ($_POST['category_id'] ?? 0);
            $stmtCat = $db->prepare("SELECT * FROM product_categories WHERE id = ? AND is_active = 1");
            $stmtCat->execute([$category_id]);
            $cat = $stmtCat->fetch(PDO::FETCH_ASSOC);
            if (!$cat) throw new MdmValidationException("Catégorie invalide ou désactivée.");

            // What the row looks like today — needed so an edit can never
            // silently destroy empties metadata it wasn't shown.
            $prev = ['is_empty' => 0, 'bottle_size' => null, 'has_cork' => 0];
            if ($id) {
                $stmtPrev = $db->prepare("SELECT is_empty, bottle_size, has_cork FROM products WHERE id = ?");
                $stmtPrev->execute([$id]);
                $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC) ?: $prev;
            }

            // Empties metadata. Forced on for container categories so a new
            // Emballage can never again be created invisible to the empties
            // ledger — cre_controller filters on is_empty = 1 AND bottle_size.
            $is_container = (int) !empty($cat['is_empty_container']);
            $is_empty     = $is_container;
            $bottle_size  = mdm_size_token($_POST['bottle_size'] ?? '') ?: null;
            $has_cork     = (int) !empty($_POST['has_cork']);

            if ($is_container) {
                if ($bottle_size === null) {
                    throw new MdmValidationException("Un emballage doit indiquer son format (20L, 10L, 1.5L…) — le registre des consignes s'en sert pour le retrouver.");
                }
            } else {
                // Not a container: the form doesn't render bottle_size/has_cork,
                // so we must NOT read "absent" as "clear it".
                //
                // Derive the size from the Format field when it parses as one
                // ("20L", "1.5L", "50CL"). That is a straight upgrade on the
                // status quo, where budget_controller and
                // sales_dashboard_controller both gave up on products.format
                // and fell back to pattern-matching the SKU string.
                $derived = mdm_size_token($_POST['format'] ?? '');
                $bottle_size = preg_match('/^\d+(\.\d+)?(L|CL|ML)$/', $derived)
                    ? $derived
                    : ($prev['bottle_size'] ?? null);

                // Moving a container out of a container category clears the
                // flag — but keep bottle_size / has_cork so the move is
                // reversible by putting the category back.
                $has_cork = (int) ($prev['has_cork'] ?? 0);
                if ((int) ($prev['is_empty'] ?? 0) === 1) {
                    error_log("mdm: product #{$id} left a container category; is_empty cleared, "
                            . "bottle_size/has_cork preserved.");
                }
            }

            // Packs. units_per_pack = 1 means sold loose, which is the default
            // and true for every bonbonne.
            $uom_values     = array_column(MDM_UOMS, 'v');
            $unit_of_measure = trim($_POST['unit_of_measure'] ?? 'unite');
            if (!in_array($unit_of_measure, $uom_values, true)) $unit_of_measure = 'unite';

            $units_per_pack = max(1, (int) ($_POST['units_per_pack'] ?? 1));
            $sold_by        = ($_POST['sold_by'] ?? 'unit') === 'pack' ? 'pack' : 'unit';
            if ($units_per_pack === 1) $sold_by = 'unit';   // "pack of 1" is not a pack

            // Account overrides. Blank means inherit from the category, which is
            // what we expect for virtually every product.
            $revenue_account_id = !empty($_POST['revenue_account_id']) ? (int) $_POST['revenue_account_id'] : null;
            $stock_account_id   = !empty($_POST['stock_account_id'])   ? (int) $_POST['stock_account_id']   : null;
            $cogs_account_id    = !empty($_POST['cogs_account_id'])    ? (int) $_POST['cogs_account_id']    : null;

            // Code: generated from category prefix + size unless the user typed
            // one. Uniqueness is checked here so a collision reads as a message
            // instead of the UNIQUE KEY surfacing as a 500.
            $code = strtoupper(trim($_POST['code'] ?? ''));
            if ($code === '') {
                $code = mdm_next_product_code($db, $cat['sku_prefix'], $bottle_size ?: $format);
            }
            // products.code is VARCHAR(20). Say so, rather than letting MySQL
            // truncate it and create a code that silently isn't the one shown.
            if (strlen($code) > 20) {
                throw new MdmValidationException("Le code produit ne peut pas dépasser 20 caractères (« {$code} »).");
            }
            $dupe = $db->prepare("SELECT id FROM products WHERE code = ? AND id <> ? LIMIT 1");
            $dupe->execute([$code, (int) $id]);
            if ($dupe->fetchColumn()) {
                throw new MdmValidationException("Le code « {$code} » est déjà utilisé par un autre produit.");
            }

            if ($id) {
                $db->prepare("
                    UPDATE products
                       SET code=?, name=?, format=?, category=?, category_id=?,
                           unit_of_measure=?, units_per_pack=?, sold_by=?,
                           base_price=?, linked_empty_id=?,
                           is_empty=?, bottle_size=?, has_cork=?,
                           revenue_account_id=?, stock_account_id=?, cogs_account_id=?
                     WHERE id=?
                ")->execute([
                    $code, $name, $format, $cat['name'], $cat['id'],
                    $unit_of_measure, $units_per_pack, $sold_by,
                    $base_price, $linked_empty,
                    $is_empty, $bottle_size, $has_cork,
                    $revenue_account_id, $stock_account_id, $cogs_account_id,
                    $id,
                ]);
            } else {
                $db->prepare("
                    INSERT INTO products
                        (code, name, format, category, category_id,
                         unit_of_measure, units_per_pack, sold_by,
                         base_price, is_active, linked_empty_id,
                         is_empty, bottle_size, has_cork,
                         revenue_account_id, stock_account_id, cogs_account_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $code, $name, $format, $cat['name'], $cat['id'],
                    $unit_of_measure, $units_per_pack, $sold_by,
                    $base_price, $linked_empty,
                    $is_empty, $bottle_size, $has_cork,
                    $revenue_account_id, $stock_account_id, $cogs_account_id,
                ]);
                $id = $db->lastInsertId();
            }

            // Echo back the resolved code + account so the UI can confirm what
            // was actually booked rather than what was typed.
            $confirm = $db->prepare("
                SELECT p.id, p.code, p.name,
                       COALESCE(ra.code, '701') AS revenue_code,
                       COALESCE(ra.name, 'Ventes') AS revenue_name
                  FROM products p
                  LEFT JOIN product_categories pc ON pc.id = p.category_id
                  LEFT JOIN chart_of_accounts  ra ON ra.id = COALESCE(p.revenue_account_id, pc.revenue_account_id)
                 WHERE p.id = ?
            ");
            $confirm->execute([$id]);

            echo json_encode([
                'status'  => 'success',
                'product' => $confirm->fetch(PDO::FETCH_ASSOC) ?: null,
            ]);
            exit;
        }
        elseif ($module === 'pricing') {
            // Pricing uses ON DUPLICATE KEY UPDATE because it's a pivot table
            $stmt = $db->prepare("INSERT INTO client_prices (client_id, product_id, custom_price) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE custom_price=?");
            $stmt->execute([$_POST['client_id'], $_POST['product_id'], $_POST['custom_price'], $_POST['custom_price']]);
        }
        elseif ($module === 'suppliers') {
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? null);
            $email = trim($_POST['email'] ?? null);
            $address = trim($_POST['address'] ?? null);

            if ($id) {
                // UPDATE (Code and Account ID don't change on update)
                $stmt = $db->prepare("UPDATE suppliers SET name=?, phone=?, email=?, address=? WHERE id=?");
                $stmt->execute([$name, $phone, $email, $address, $id]);
            } else {
                // CREATE NEW SUPPLIER + AUTO-GENERATE OHADA 401
                $db->beginTransaction();
                try {
                    // 1. Find the last 401 account and increment
                    $stmtAcc = $db->query("SELECT MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) as max_seq FROM chart_of_accounts WHERE code LIKE '401%'");
                    $max_seq = $stmtAcc->fetchColumn();
                    $next_seq = ($max_seq > 0) ? $max_seq + 1 : 1;
                    $new_account_code = '401' . str_pad($next_seq, 3, '0', STR_PAD_LEFT);
                    
                    // 2. Insert into Chart of Accounts (OHADA ID 1 = 401 Fournisseurs)
                    $insAcc = $db->prepare("INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active) VALUES (1, ?, ?, 'liability', 1)");
                    $insAcc->execute([$new_account_code, "Fournisseur - " . $name]);
                    $account_id = $db->lastInsertId();

                    // 3. AUTO-GENERATE LPC CODE: FRS-001, FRS-002...
                    $stmt_code = $db->query("SELECT lpc_code FROM suppliers WHERE lpc_code LIKE 'FRS-%' ORDER BY id DESC LIMIT 1");
                    $last_code = $stmt_code->fetchColumn();
                    if ($last_code) {
                        $num = (int)str_replace('FRS-', '', $last_code);
                        $lpc_code = 'FRS-' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
                    } else {
                        $lpc_code = 'FRS-001';
                    }

                    // 4. Save Supplier
                    $stmt = $db->prepare("INSERT INTO suppliers (lpc_code, account_id, name, phone, email, address, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$lpc_code, $account_id, $name, $phone, $email, $address]);
                    
                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
            }
        }
        elseif ($module === 'fleet') {
            if ($id) {
                $stmt = $db->prepare("UPDATE vehicles SET plate_number=?, type=?, make_model=?, status=? WHERE id=?");
                $stmt->execute([$_POST['plate_number'], $_POST['type'], $_POST['make_model'], $_POST['status'], $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO vehicles (plate_number, type, make_model, status, is_active) VALUES (?, ?, ?, ?, 1)");
                $stmt->execute([$_POST['plate_number'], $_POST['type'], $_POST['make_model'], $_POST['status']]);
            }
        }
        elseif ($module === 'employees') {
            if (empty($_POST['role_id'])) throw new MdmValidationException("Le Rôle Système est obligatoire.");
            if (empty($_POST['first_name']) || empty($_POST['last_name'])) throw new MdmValidationException("Le Nom et Prénom sont obligatoires.");
            if (empty($_POST['email'])) throw new MdmValidationException("L'adresse email est obligatoire.");
            // 1. Handle avatar upload — hardened via Uploads::saveUploaded.
            //    Writes under /uploads/avatars/YYYY/MM/ (protected by uploads/.htaccess).
            require_once __DIR__ . '/../../includes/classes/Uploads.php';
            $avatar_path = null;
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                try {
                    $up = Uploads::saveUploaded('avatar', 'avatars', [
                        'allowed_mime' => ['image/jpeg','image/png','image/webp'],
                        'max_bytes'    => 2 * 1024 * 1024,   // 2 MiB
                        'sanitize_img' => true,              // GD re-encode strips EXIF & payloads
                    ]);
                    $avatar_path = $up['path'];
                } catch (Throwable $e) {
                    throw new Exception('Avatar refusé : ' . $e->getMessage());
                }
            }

            $db->beginTransaction();
            try {
                if ($id) {
                    // Update IAM
                    $stmt = $db->prepare("UPDATE users SET first_name=?, last_name=?, email=?, role_id=? WHERE id=?");
                    $stmt->execute([$_POST['first_name'], $_POST['last_name'], $_POST['email'], $_POST['role_id'], $id]);
                    // Update HR Profile
                    if ($avatar_path) {
                        $stmt = $db->prepare("UPDATE employee_profiles SET job_title=?, phone=?, base_salary=?, avatar=? WHERE user_id=?");
                        $stmt->execute([$_POST['job_title'], $_POST['phone'], $_POST['base_salary'], $avatar_path, $id]);
                        if ($id == $_SESSION['user_id']) { $_SESSION['avatar'] = $avatar_path; }
                    } else {
                        $stmt = $db->prepare("UPDATE employee_profiles SET job_title=?, phone=?, base_salary=? WHERE user_id=?");
                        $stmt->execute([$_POST['job_title'], $_POST['phone'], $_POST['base_salary'], $id]);
                    }
                } else {
                    // New Employee
                    $temp_pwd = password_hash('LPC2026', PASSWORD_BCRYPT);
                    
                    // AUTO-GENERATE CODE: EMP-001, EMP-002...
                    $stmt_code = $db->query("SELECT employee_code FROM users WHERE employee_code LIKE 'EMP-%' ORDER BY id DESC LIMIT 1");
                    $last_code = $stmt_code->fetchColumn();
                    if ($last_code) {
                        $num = (int)str_replace('EMP-', '', $last_code);
                        $emp_code = 'EMP-' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
                    } else {
                        $emp_code = 'EMP-001';
                    }
                    $stmt = $db->prepare("INSERT INTO users (role_id, employee_code, first_name, last_name, email, password_hash, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                    $emp_code = 'LPC-' . time();
                    $stmt->execute([$_POST['role_id'], $emp_code, $_POST['first_name'], $_POST['last_name'], $_POST['email'], $temp_pwd]);
                    $new_user_id = $db->lastInsertId();
                    
                    $stmt = $db->prepare("INSERT INTO employee_profiles (user_id, job_title, phone, base_salary, hire_date, avatar) VALUES (?, ?, ?, ?, CURDATE(), ?)");
                    $stmt->execute([$new_user_id, $_POST['job_title'], $_POST['phone'], $_POST['base_salary'], $avatar_path]);
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack(); throw $e;
            }
        }

        echo json_encode(['status' => 'success']); exit;
    }

} catch (MdmValidationException $e) {
    // Actionable, user-facing, and safe to echo — it was written for them.
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    http_response_code(500); error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}