<?php
/**
 * includes/classes/DataWipeBalances.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — after a data wipe, the figures bureau STORES match what is
 * left (owner's decision 6, 2 Nov 2026). What bureau computes on the fly
 * (stock quantities = the sum of inventory_movements, receivables, ledgers)
 * follows by itself once the movements and entries of wiped documents are
 * gone; these are kept up to date by the code that writes them, so a wipe
 * subtracts exactly what the wiped rows had added:
 *
 *   · treasury_accounts.balance      the signed sum of the wiped treasury
 *                                    movements (every balance change in bureau
 *                                    writes one: JournalPoster, treasury,
 *                                    expenses and procurement controllers);
 *   · client_wallets.balance         advances and overpayments the wiped
 *                                    payments credited, minus what wiped
 *                                    wallet payments used (invoices_controller);
 *   · invoices.status / sales_orders.payment_status
 *                                    rebuilt from the payments left
 *                                    (lpc_recompute_sales_order_payment_status);
 *   · client_empties_ledger          what wiped signed BLs and CREs counted out
 *                                    and in, and what open BLs held in flight
 *                                    (signature_side_effects, sales_controller);
 *   · products.cump                  back to 0 for a product none of whose
 *                                    receptions is left.
 *
 * Rows of wiped clients (wallets, ledger) go with the client instead. A
 * tournée overage credited to a wallet has no record of its own and stays.
 *
 * prepare() reads what the wiped rows held (before they go); apply() writes the
 * new figures and the UPDATEs that put the old ones back (restore.sql).
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class DataWipeBalances
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** What the wiped rows added to stored figures. Call before the rows are deleted. */
    public function prepare(array $plan): array
    {
        $del = $plan['delete'];
        $p = ['treasury' => [], 'wallets' => [], 'invoice_status' => [], 'empties_bl' => [], 'empties_cre' => [], 'cump' => [], 'stock' => []];

        if (!empty($del['treasury_transactions']) && $this->has('treasury_accounts')) {
            $live = $this->hasCol('treasury_transactions', 'deleted_at') ? ' AND deleted_at IS NULL' : '';
            foreach ($this->chunks($del['treasury_transactions']) as $in) {
                foreach ($this->db->query("SELECT account_id, SUM(CASE WHEN transaction_type LIKE 'in\\_%' OR transaction_type = 'transfer_in' THEN amount ELSE -amount END) AS d
                                             FROM treasury_transactions WHERE id IN ({$in}){$live} GROUP BY account_id") as $r) {
                    $p['treasury'][(int) $r['account_id']] = ($p['treasury'][(int) $r['account_id']] ?? 0) + (float) $r['d'];
                }
            }
        }

        if (!empty($del['payments'])) {
            $wiped = $del['payments'];
            $invoices = [];
            foreach ($this->chunks($wiped) as $in) {
                foreach ($this->db->query("SELECT client_id, invoice_id, amount, payment_method, status FROM payments WHERE id IN ({$in})") as $r) {
                    if ($r['status'] !== 'validated') {
                        continue;   // a payment waiting to be counted has moved no wallet yet
                    }
                    $c = (int) $r['client_id'];
                    if ($r['payment_method'] === 'wallet') {
                        $p['wallets'][$c] = ($p['wallets'][$c] ?? 0) - (float) $r['amount'];
                    } elseif ($r['invoice_id'] === null) {
                        $p['wallets'][$c] = ($p['wallets'][$c] ?? 0) + (float) $r['amount'];
                    }
                    if ($r['invoice_id'] !== null) {
                        $invoices[(int) $r['invoice_id']] = true;
                    }
                }
            }
            // Overpayment of an invoice went to the wallet: what it was, minus what is left after the wipe
            foreach ($this->chunks($invoices) as $in) {
                $paid = [];
                foreach ($this->db->query("SELECT id, invoice_id, amount + COALESCE(air_withheld_amount, 0) AS a FROM payments WHERE invoice_id IN ({$in}) AND status = 'validated'") as $r) {
                    $i = (int) $r['invoice_id'];
                    $paid[$i]['all'] = ($paid[$i]['all'] ?? 0) + (float) $r['a'];
                    if (!isset($wiped[(int) $r['id']])) {
                        $paid[$i]['left'] = ($paid[$i]['left'] ?? 0) + (float) $r['a'];
                    }
                }
                foreach ($this->db->query("SELECT id, client_id, total_amount, status FROM invoices WHERE id IN ({$in})") as $inv) {
                    $i = (int) $inv['id'];
                    $total = (float) $inv['total_amount'];
                    $gone = isset($del['invoices'][$i]);
                    $before = max(0, ($paid[$i]['all'] ?? 0) - $total);
                    $after = $gone ? 0 : max(0, ($paid[$i]['left'] ?? 0) - $total);
                    $c = (int) $inv['client_id'];
                    $p['wallets'][$c] = ($p['wallets'][$c] ?? 0) + ($before - $after);
                    if (!$gone) {
                        $left = $paid[$i]['left'] ?? 0;
                        $status = $left >= $total - 0.005 && $total > 0 ? 'paid' : ($left > 0 ? 'partial' : 'unpaid');
                        if ($status !== $inv['status']) {
                            $p['invoice_status'][$i] = $status;
                        }
                    }
                }
            }
            foreach ($p['wallets'] as $c => $d) {
                if (abs($d) < 0.005 || isset($del['client_wallets'][$c]) || isset($del['clients'][$c])) {
                    unset($p['wallets'][$c]);
                }
            }
            $p['touched_invoices'] = $invoices;
        }

        // Orders whose invoices or payments go: their payment status is rebuilt from what is left
        $touched = ($p['touched_invoices'] ?? []) + ($del['invoices'] ?? []);
        unset($p['touched_invoices']);
        $p['orders'] = [];
        if ($touched && $this->has('invoice_deliveries') && $this->hasCol('sales_orders', 'payment_status')) {
            foreach ($this->chunks($touched) as $in) {
                foreach ($this->db->query("SELECT DISTINCT d.sales_order_id FROM invoice_deliveries x JOIN deliveries d ON d.id = x.delivery_id
                                            WHERE x.invoice_id IN ({$in}) AND d.sales_order_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $so) {
                    if (!isset($del['sales_orders'][(int) $so])) {
                        $p['orders'][(int) $so] = true;
                    }
                }
            }
        }

        if ($this->has('client_empties_ledger')) {
            if (!empty($del['deliveries'])) {
                foreach ($this->chunks($del['deliveries']) as $in) {
                    foreach ($this->db->query("SELECT d.client_id, pr.linked_empty_id AS empty_id, d.status,
                                                      SUM(COALESCE(di.delivered_quantity, 0)) AS accepted, SUM(COALESCE(di.returned_empty_qty, 0)) AS back,
                                                      SUM(di.quantity) AS shipped
                                                 FROM delivery_items di JOIN deliveries d ON d.id = di.delivery_id JOIN products pr ON pr.id = di.product_id
                                                WHERE d.id IN ({$in}) AND pr.linked_empty_id IS NOT NULL
                                                GROUP BY d.client_id, pr.linked_empty_id, d.status") as $r) {
                        if (isset($del['clients'][(int) $r['client_id']])) {
                            continue;
                        }
                        $k = $r['client_id'] . ':' . $r['empty_id'];
                        $e = $p['empties_bl'][$k] ?? ['client' => (int) $r['client_id'], 'product' => (int) $r['empty_id'], 'out' => 0, 'in' => 0, 'flight' => 0];
                        if ($r['status'] === 'completed') {
                            $e['out'] += (int) $r['accepted'];
                            $e['in'] += (int) $r['back'];
                        } elseif (in_array($r['status'], ['dispatched', 'driver_confirmed'], true)) {
                            $e['flight'] += (int) $r['shipped'];
                        }
                        $p['empties_bl'][$k] = $e;
                    }
                }
            }
            if (!empty($del['cre_documents'])) {
                foreach ($this->chunks($del['cre_documents']) as $in) {
                    // The row each line reduced (cre_items.ledger_id, migration 150), else the CRE's own site and product.
                    foreach ($this->db->query("SELECT c.client_id, IF(l.id IS NULL, c.site_id, l.site_id) AS site_id, COALESCE(l.product_id, ci.product_id) AS product_id, SUM(ci.quantity) AS q
                                                 FROM cre_items ci JOIN cre_documents c ON c.id = ci.cre_document_id
                                                 LEFT JOIN client_empties_ledger l ON l.id = ci.ledger_id
                                                WHERE c.id IN ({$in}) AND c.status = 'signed' AND ci.quantity > 0
                                                GROUP BY c.client_id, IF(l.id IS NULL, c.site_id, l.site_id), COALESCE(l.product_id, ci.product_id)") as $r) {
                        if (!isset($del['clients'][(int) $r['client_id']])) {
                            $p['empties_cre'][] = ['client' => (int) $r['client_id'], 'site' => $r['site_id'] === null ? null : (int) $r['site_id'],
                                                   'product' => (int) $r['product_id'], 'in' => (int) $r['q']];
                        }
                    }
                }
            }
        }

        if (!empty($del['inventory_movements'])) {
            $wipedIn = [];
            foreach ($this->chunks($del['inventory_movements']) as $in) {
                foreach ($this->db->query("SELECT product_id, movement_type, SUM(quantity) AS q, COUNT(*) AS n FROM inventory_movements WHERE id IN ({$in}) GROUP BY product_id, movement_type") as $r) {
                    $pid = (int) $r['product_id'];
                    if (isset($del['products'][$pid])) {
                        continue;
                    }
                    $sign = str_starts_with((string) $r['movement_type'], 'in_') ? 1 : -1;
                    $p['stock'][$pid] = ($p['stock'][$pid] ?? 0) + $sign * (int) $r['q'];
                    if ($r['movement_type'] === 'in_supplier') {
                        $wipedIn[$pid] = ($wipedIn[$pid] ?? 0) + (int) $r['n'];
                    }
                }
            }
            if ($wipedIn) {
                $in = implode(',', array_keys($wipedIn));
                $all = $this->db->query("SELECT product_id, COUNT(*) FROM inventory_movements WHERE movement_type = 'in_supplier' AND product_id IN ({$in}) GROUP BY product_id")->fetchAll(PDO::FETCH_KEY_PAIR);
                foreach ($wipedIn as $pid => $n) {
                    if ((int) ($all[$pid] ?? 0) <= $n) {
                        $p['cump'][] = $pid;
                    }
                }
            }
        }

        return $p;
    }

    /** Write the new figures, and to $undo the UPDATEs that put the old ones back. Returns a summary for the log. */
    public function apply(array $p, $undo): array
    {
        $db = $this->db;
        $sum = ['treasury' => [], 'wallets' => [], 'invoices' => 0, 'orders' => 0, 'empties' => 0, 'cump' => []];

        foreach ($p['treasury'] as $acc => $d) {
            $row = $this->lockRow('treasury_accounts', 'id', $acc, ['balance', 'name']);
            if (!$row || abs($d) < 0.005) {
                continue;
            }
            fwrite($undo, $this->undo('treasury_accounts', 'id', $acc, ['balance' => $row['balance']]));
            $new = round((float) $row['balance'] - $d, 2);
            $db->prepare("UPDATE treasury_accounts SET balance = ? WHERE id = ?")->execute([$new, $acc]);
            $sum['treasury'][] = ['name' => $row['name'], 'before' => (float) $row['balance'], 'after' => $new];
        }

        foreach ($p['wallets'] as $client => $d) {
            $row = $this->lockRow('client_wallets', 'client_id', $client, ['balance']);
            if (!$row) {
                continue;
            }
            fwrite($undo, $this->undo('client_wallets', 'client_id', $client, ['balance' => $row['balance']]));
            $new = round((float) $row['balance'] - $d, 2);
            $db->prepare("UPDATE client_wallets SET balance = ? WHERE client_id = ?")->execute([$new, $client]);
            $sum['wallets'][] = ['client_id' => $client, 'before' => (float) $row['balance'], 'after' => $new];
        }

        foreach ($p['invoice_status'] as $inv => $status) {
            $row = $this->lockRow('invoices', 'id', $inv, ['status']);
            if (!$row) {
                continue;
            }
            fwrite($undo, $this->undo('invoices', 'id', $inv, ['status' => $row['status']]));
            $db->prepare("UPDATE invoices SET status = ? WHERE id = ?")->execute([$status, $inv]);
            $sum['invoices']++;
        }

        // Same rule as lpc_recompute_sales_order_payment_status(), by order: its invoices
        // and their validated payments that are left, against max(order total, invoiced)
        $sums = $db->prepare("SELECT COALESCE(SUM(i.total_amount), 0) AS invoiced,
                                     COALESCE(SUM((SELECT SUM(p.amount + COALESCE(p.air_withheld_amount, 0)) FROM payments p
                                                    WHERE p.invoice_id = i.id AND p.status = 'validated')), 0) AS paid
                                FROM invoices i JOIN invoice_deliveries x ON x.invoice_id = i.id JOIN deliveries d ON d.id = x.delivery_id
                               WHERE d.sales_order_id = ?");
        foreach (array_keys($p['orders']) as $so) {
            $row = $this->lockRow('sales_orders', 'id', $so, ['payment_status', 'total_amount']);
            if (!$row) {
                continue;
            }
            $sums->execute([$so]);
            $s = $sums->fetch(PDO::FETCH_ASSOC);
            $benchmark = max((float) $row['total_amount'], (float) $s['invoiced']);
            $status = $benchmark > 0 && (float) $s['paid'] + 0.01 >= $benchmark ? 'paid' : ((float) $s['paid'] > 0 ? 'partial' : 'unpaid');
            if ($status !== $row['payment_status']) {
                fwrite($undo, $this->undo('sales_orders', 'id', $so, ['payment_status' => $row['payment_status']]));
                $db->prepare("UPDATE sales_orders SET payment_status = ? WHERE id = ?")->execute([$status, $so]);
                $sum['orders']++;
            }
        }

        // Empties: a BL counted on every site row of the client and product, a CRE on its own site
        $cols = ['total_out', 'total_in', 'quantity_owed', 'quantity_in_flight'];
        foreach ($p['empties_bl'] as $e) {
            $st = $db->prepare("SELECT id, " . implode(', ', $cols) . " FROM client_empties_ledger WHERE client_id = ? AND product_id = ? FOR UPDATE");
            $st->execute([$e['client'], $e['product']]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $this->moveEmpties($row, $e['out'], $e['in'], $e['flight'], $undo);
                $sum['empties']++;
            }
        }
        foreach ($p['empties_cre'] as $e) {
            $st = $db->prepare("SELECT id, " . implode(', ', $cols) . " FROM client_empties_ledger WHERE client_id = ? AND product_id = ? AND site_id <=> ? FOR UPDATE");
            $st->execute([$e['client'], $e['product'], $e['site']]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $this->moveEmpties($row, 0, $e['in'], 0, $undo);
                $sum['empties']++;
            }
        }

        foreach ($p['cump'] as $pid) {
            $row = $this->lockRow('products', 'id', $pid, ['cump', 'name']);
            if ($row && (float) $row['cump'] != 0.0) {
                fwrite($undo, $this->undo('products', 'id', $pid, ['cump' => $row['cump']]));
                $db->prepare("UPDATE products SET cump = 0 WHERE id = ?")->execute([$pid]);
                $sum['cump'][] = $row['name'];
            }
        }

        return $sum;
    }

    /**
     * For the screen, without changing anything: treasury and wallet balances
     * before → after, stock before → after, and what else is recalculated.
     */
    public function preview(array $plan): array
    {
        $p = $this->prepare($plan);
        $out = ['treasury' => [], 'wallets' => [], 'stock' => [], 'invoices' => count($p['invoice_status']), 'orders' => count($p['orders']),
                'empties' => count($p['empties_bl']) + count($p['empties_cre']), 'cump' => count($p['cump'])];
        foreach ($p['treasury'] as $acc => $d) {
            $st = $this->db->prepare("SELECT name, balance FROM treasury_accounts WHERE id = ?");
            $st->execute([$acc]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $out['treasury'][] = ['name' => $r['name'], 'before' => (float) $r['balance'], 'after' => round((float) $r['balance'] - $d, 2)];
            }
        }
        foreach ($p['wallets'] as $client => $d) {
            $st = $this->db->prepare("SELECT c.name, w.balance FROM client_wallets w JOIN clients c ON c.id = w.client_id WHERE w.client_id = ?");
            $st->execute([$client]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $out['wallets'][] = ['name' => $r['name'], 'before' => (float) $r['balance'], 'after' => round((float) $r['balance'] - $d, 2)];
            }
        }
        if ($p['stock']) {
            $in = implode(',', array_map('intval', array_keys($p['stock'])));
            foreach ($this->db->query("SELECT pr.id, pr.name, COALESCE(SUM(CASE WHEN m.movement_type LIKE 'in\\_%' THEN m.quantity ELSE -m.quantity END), 0) AS qty
                                         FROM products pr LEFT JOIN inventory_movements m ON m.product_id = pr.id WHERE pr.id IN ({$in}) GROUP BY pr.id, pr.name") as $r) {
                $out['stock'][] = ['name' => $r['name'], 'before' => (int) $r['qty'], 'after' => (int) $r['qty'] - $p['stock'][(int) $r['id']]];
            }
        }
        return $out;
    }

    private function moveEmpties(array $row, int $out, int $in, int $flight, $undo): void
    {
        fwrite($undo, $this->undo('client_empties_ledger', 'id', (int) $row['id'], [
            'total_out' => $row['total_out'], 'total_in' => $row['total_in'], 'quantity_owed' => $row['quantity_owed'], 'quantity_in_flight' => $row['quantity_in_flight'],
        ]));
        $this->db->prepare("UPDATE client_empties_ledger
                               SET total_out = GREATEST(0, total_out - ?), total_in = GREATEST(0, total_in - ?),
                                   quantity_owed = GREATEST(0, quantity_owed - ? + ?), quantity_in_flight = GREATEST(0, quantity_in_flight - ?)
                             WHERE id = ?")->execute([$out, $in, $out, $in, $flight, (int) $row['id']]);
    }

    private function lockRow(string $table, string $key, int $id, array $cols): ?array
    {
        if (!$this->has($table)) {
            return null;
        }
        $st = $this->db->prepare("SELECT `" . implode('`, `', $cols) . "` FROM `{$table}` WHERE `{$key}` = ? FOR UPDATE");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * The UPDATE putting $values back on one row, with the columns MySQL stamps
     * on every update (ON UPDATE CURRENT_TIMESTAMP) as they are now, so a
     * restore doesn't date the row today. Read before the row is changed.
     */
    private function undo(string $table, string $key, int $id, array $values): string
    {
        $stamps = $this->stamps($table);
        if ($stamps) {
            $st = $this->db->prepare("SELECT `" . implode('`, `', $stamps) . "` FROM `{$table}` WHERE `{$key}` = ?");
            $st->execute([$id]);
            $values += $st->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $set = implode(', ', array_map(fn ($c, $v) => "`{$c}` = " . ($v === null ? 'NULL' : $this->db->quote((string) $v)), array_keys($values), $values));
        return "UPDATE `{$table}` SET {$set} WHERE `{$key}` = {$id};\n";
    }

    private function stamps(string $table): array
    {
        static $cache = [];
        return $cache[$table] ??= $this->db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = "
                                                   . $this->db->quote($table) . " AND extra LIKE '%on update%'")->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Comma lists of the integer keys of a [key => true] set, 500 at a time. */
    private function chunks(array $set): array
    {
        return array_map(fn ($c) => implode(',', array_map('intval', $c)), array_chunk(array_keys($set), 500));
    }

    private function has(string $table): bool
    {
        static $cache = [];
        return $cache[$table] ??= (bool) $this->db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $this->db->quote($table))->fetchColumn();
    }

    private function hasCol(string $table, string $col): bool
    {
        static $cache = [];
        return $cache["{$table}.{$col}"] ??= (bool) $this->db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = "
                                                                       . $this->db->quote($table) . " AND column_name = " . $this->db->quote($col))->fetchColumn();
    }
}
