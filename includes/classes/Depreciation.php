<?php
/**
 * includes/classes/Depreciation.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 4 (parallel) · OHADA depreciation math.
 *
 * Two entry points:
 *
 *   Depreciation::monthly($asset, $month)
 *       Returns [proration_days, amount, is_full_month, calculation]
 *       for the given fixed_assets row and target month. Prorates the first
 *       and last months by day-of-service. Amount excludes salvage_value.
 *
 *   Depreciation::disposalGain($asset, $sale_price, $disposal_date)
 *       Returns { book_value, plus_value, moins_value, accumulated_at_disposal,
 *       journal_lines } for a cession. The journal_lines[] can be fed straight
 *       into a balanced JE.
 *
 * The class is pure math — no DB access. The caller loads the asset row,
 * hands it in as an array, and posts the resulting numbers through
 * post_journal_entry.
 *
 * FCFA rounding: nearest integer, half-up. Every returned amount is an int
 * as a float (no fractional part).
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class Depreciation
{
    /**
     * Compute this month's depreciation for a single asset.
     *
     * @param array $asset  fixed_assets row (must have acquisition_cost,
     *                      salvage_value, lifespan_months, service_start_date,
     *                      depreciation_method).
     * @param DateTimeImmutable $month  Any date in the target month.
     * @return array [
     *   'proration_days'  => int,   // 30 for a full month, less if pro-rated
     *   'amount'          => float, // FCFA int, salvage-adjusted, may be 0
     *   'is_full_month'   => bool,
     *   'calculation'     => array  // audit trail — safe to json_encode
     * ]
     */
    public static function monthly(array $asset, DateTimeImmutable $month): array
    {
        $cost    = (float) ($asset['acquisition_cost'] ?? 0);
        $salvage = (float) ($asset['salvage_value']    ?? 0);
        $life    = (int)   ($asset['lifespan_months']  ?? 0);
        $method  = (string)($asset['depreciation_method'] ?? 'linear');
        $service_start = $asset['service_start_date']
            ?? $asset['acquisition_date']
            ?? null;

        if ($life <= 0 || $cost <= 0) {
            return self::empty('lifespan or cost is zero');
        }
        if ($service_start === null) {
            return self::empty('missing service_start_date');
        }

        $service_start = new DateTimeImmutable(is_string($service_start) ? $service_start : $service_start->format('Y-m-d'));
        $month_first   = new DateTimeImmutable($month->format('Y-m-01'));
        $month_last    = new DateTimeImmutable($month->format('Y-m-t'));

        // Not-yet-in-service: skip.
        if ($service_start > $month_last) {
            return self::empty('asset not yet in service');
        }

        // End-of-life: skip.
        $end_of_life = $service_start->modify('+' . $life . ' months');
        if ($month_first >= $end_of_life) {
            return self::empty('asset past end of useful life');
        }

        // Depreciable base = cost - salvage.
        $depreciable_base = max(0.0, $cost - $salvage);
        $already_for_dbg  = (float) ($asset['accumulated'] ?? 0);
        $full_monthly     = ($method === 'linear')
            ? ($depreciable_base / $life)
            : self::decliningMonthly($depreciable_base, $life, $already_for_dbg);

        // Proration logic: months use a 30-day convention (standard OHADA).
        // - If service_start is in this month: prorate from service_start's DoM.
        // - If end_of_life falls in this month: prorate down to end_of_life's DoM - 1.
        // - Otherwise: full 30 days.
        $days_in_month  = 30;
        $proration_days = 30;
        $is_full_month  = true;
        $reason         = 'full month';

        if (
            $service_start->format('Y-m') === $month_first->format('Y-m')
        ) {
            $start_dom = (int) $service_start->format('j');
            $proration_days = max(0, 30 - $start_dom + 1);
            $is_full_month  = ($proration_days === 30);
            $reason = "first month, service_start day-of-month=$start_dom";
        }

        if (
            $end_of_life > $month_first
            && $end_of_life <= $month_last->modify('+1 day')
            && $end_of_life->format('Y-m') === $month_first->format('Y-m')
        ) {
            $end_dom = (int) $end_of_life->format('j');
            $proration_days = min($proration_days, max(0, $end_dom - 1));
            $is_full_month  = ($proration_days === 30);
            $reason .= "; last month, end_of_life day-of-month=$end_dom";
        }

        $amount = ($full_monthly * $proration_days) / $days_in_month;

        // Cap so accumulated never exceeds depreciable_base.
        $already = (float) ($asset['accumulated'] ?? 0);
        $remaining = max(0.0, $depreciable_base - $already);
        if ($amount > $remaining) {
            $amount = $remaining;
            $reason .= '; capped to remaining depreciable base';
        }

        $amount_int = self::roundFcfa($amount);

        return [
            'proration_days' => $proration_days,
            'amount'         => $amount_int,
            'is_full_month'  => $is_full_month,
            'calculation'    => [
                'cost'              => $cost,
                'salvage'           => $salvage,
                'depreciable_base'  => $depreciable_base,
                'lifespan_months'   => $life,
                'method'            => $method,
                'service_start'     => $service_start->format('Y-m-d'),
                'month'             => $month_first->format('Y-m'),
                'full_monthly'      => round($full_monthly, 4),
                'proration_days'    => $proration_days,
                'raw_amount'        => round($amount, 4),
                'rounded_amount'    => $amount_int,
                'accumulated_prior' => $already,
                'remaining_before'  => $remaining,
                'reason'            => $reason,
            ],
        ];
    }

    /**
     * Compute plus/moins-value on disposal.
     * Returns a balanced set of journal lines (debit + credit) ready to feed
     * into a journal entry via post_journal_entry.
     *
     * @param array $asset  fixed_assets row with cost, salvage, life, service_start,
     *                      cost_account_id (or asset_account_id), accumulated_depr_account_id
     *                      (or depr_account_id) — legacy chart_of_accounts refs
     *                      are also honored via {asset,depr}_account_id.
     * @param float $sale_price  Sale price (0 for scrap).
     * @param DateTimeImmutable $disposal_date
     * @param int $cash_account_id  chart_of_accounts.id where the cash lands.
     * @param float|null $accumulated_at_disposal  Optional override — if the
     *        caller already knows the running total, saves a compute. If null
     *        the method computes it by summing monthly() over the asset's life
     *        up to $disposal_date.
     */
    public static function disposalGain(
        array $asset,
        float $sale_price,
        DateTimeImmutable $disposal_date,
        int $cash_account_id,
        ?float $accumulated_at_disposal = null,
        array $opts = []
    ): array {
        $cost = (float) ($asset['acquisition_cost'] ?? 0);
        if ($accumulated_at_disposal === null) {
            $accumulated_at_disposal = self::accumulatedThrough($asset, $disposal_date);
        }
        $accumulated_at_disposal = min($accumulated_at_disposal, $cost);

        $book_value = self::roundFcfa($cost - $accumulated_at_disposal);
        // TVA on cession, when the sale price is TTC. Zero for scrap or
        // non-VAT-eligible assets. Passed by the caller; we don't guess.
        $vat_amount = self::roundFcfa((float) ($opts['vat_amount'] ?? 0));
        $sale_price = self::roundFcfa($sale_price);
        $sale_ht    = self::roundFcfa($sale_price - $vat_amount);

        $plus_value  = 0.0;
        $moins_value = 0.0;

        // Gain/loss is computed on the HT sale, not the TTC. TVA collectée is
        // owed to the tax office and is never part of a plus/moins-value.
        if ($sale_ht >= $book_value) {
            $plus_value  = self::roundFcfa($sale_ht - $book_value);
        } else {
            $moins_value = self::roundFcfa($book_value - $sale_ht);
        }

        // Journal lines (SYSCOHADA revised, "net" convention):
        //   Debit cash (5xx) sale_price TTC
        //   Debit accumulated_depr (28x) accumulated
        //   Debit moins-value (81x) if any
        //   Credit cost (2xx) cost
        //   Credit plus-value (82x) if any
        //   Credit TVA facturée (4431) if TTC
        $cost_account_id      = (int) ($asset['cost_account_id']             ?? $asset['asset_account_id'] ?? 0);
        $depr_account_id      = (int) ($asset['accumulated_depr_account_id'] ?? $asset['depr_account_id']  ?? 0);
        // Per-asset override wins; otherwise use the company-default preference
        // ID the caller resolved from app_preferences. Zero-fallback stays zero
        // so the controller can surface a clean "configure 81/82" error.
        $moins_account_id     = (int) ($asset['moins_value_account_id']      ?? 0)
                             ?: (int) ($opts['default_moins_account_id']     ?? 0);
        $plus_account_id      = (int) ($asset['plus_value_account_id']       ?? 0)
                             ?: (int) ($opts['default_plus_account_id']      ?? 0);
        $vat_account_id       = (int) ($opts['vat_account_id']               ?? 0);

        $lines = [];

        if ($sale_price > 0) {
            $lines[] = [
                'account_id'  => $cash_account_id,
                'debit'       => $sale_price,
                'credit'      => 0.0,
                'description' => 'Encaissement cession',
            ];
        }
        if ($accumulated_at_disposal > 0) {
            $lines[] = [
                'account_id'  => $depr_account_id,
                'debit'       => self::roundFcfa($accumulated_at_disposal),
                'credit'      => 0.0,
                'description' => 'Solde amortissements cumulés',
            ];
        }
        if ($moins_value > 0) {
            $lines[] = [
                'account_id'  => $moins_account_id ?: null,
                'debit'       => $moins_value,
                'credit'      => 0.0,
                'description' => 'Moins-value de cession (81x)',
            ];
        }
        if ($cost > 0) {
            $lines[] = [
                'account_id'  => $cost_account_id,
                'debit'       => 0.0,
                'credit'      => self::roundFcfa($cost),
                'description' => 'Sortie coût d\'acquisition',
            ];
        }
        if ($plus_value > 0) {
            $lines[] = [
                'account_id'  => $plus_account_id ?: null,
                'debit'       => 0.0,
                'credit'      => $plus_value,
                'description' => 'Plus-value de cession (82x)',
            ];
        }
        if ($vat_amount > 0) {
            $lines[] = [
                'account_id'  => $vat_account_id ?: null,
                'debit'       => 0.0,
                'credit'      => $vat_amount,
                'description' => 'TVA facturée sur cession (4431)',
            ];
        }

        // Sanity check — the caller's post_journal_entry stored proc will
        // reject if this doesn't balance; keep an internal assert too.
        $total_debit  = array_sum(array_column($lines, 'debit'));
        $total_credit = array_sum(array_column($lines, 'credit'));
        $balanced = (round($total_debit, 2) === round($total_credit, 2));

        return [
            'book_value'              => $book_value,
            'sale_price'              => $sale_price,
            'sale_ht'                 => $sale_ht,
            'vat_amount'              => $vat_amount,
            'plus_value'              => $plus_value,
            'moins_value'             => $moins_value,
            'accumulated_at_disposal' => self::roundFcfa($accumulated_at_disposal),
            'journal_lines'           => $lines,
            'is_balanced'             => $balanced,
            'total_debit'             => self::roundFcfa($total_debit),
            'total_credit'            => self::roundFcfa($total_credit),
        ];
    }

    // ------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------

    /** Sum monthly() over [service_start, disposal_date]. */
    private static function accumulatedThrough(array $asset, DateTimeImmutable $disposal_date): float
    {
        $service_start = $asset['service_start_date']
            ?? $asset['acquisition_date']
            ?? null;
        if ($service_start === null) return 0.0;

        $start = new DateTimeImmutable(is_string($service_start) ? $service_start : $service_start->format('Y-m-d'));
        $cursor = new DateTimeImmutable($start->format('Y-m-01'));
        $end    = new DateTimeImmutable($disposal_date->format('Y-m-01'));

        $running_asset = $asset;
        $running_asset['accumulated'] = 0.0;
        $total = 0.0;

        // Safety cap — an asset with an insane lifespan_months shouldn't loop forever.
        for ($i = 0; $i < 1200 && $cursor <= $end; $i++) {
            $running_asset['accumulated'] = $total;
            $r = self::monthly($running_asset, $cursor);
            $total += (float) $r['amount'];
            $cursor = $cursor->modify('+1 month');
        }
        return $total;
    }

    /**
     * Amortissement dégressif (SYSCOHADA / CGI Cameroun art. 7 ter).
     *
     * The rule the CGI applies:
     *   · straight-line rate = 12 / lifespan_months (as a fraction per month)
     *   · coefficient depending on useful life in YEARS:
     *       — 1.5 for 3–4 years
     *       — 2.0 for 5–6 years
     *       — 2.5 for 7+ years
     *   · monthly dépreciation = residual_base * (straight_rate * coefficient) / 12
     *   · when residual_base * annual_rate < remaining straight-line dotation,
     *     switch back to straight-line for the rest of the life.
     *
     * $accumulated is the running total already booked. The caller must
     * pass the salvage-adjusted depreciable base.
     */
    private static function decliningMonthly(float $base, int $life, float $accumulated): float
    {
        if ($life <= 0 || $base <= 0) return 0.0;

        $life_years = max(1, (int) round($life / 12));
        if ($life_years <= 2)      $coef = 1.0;   // pas de dégressif possible < 3 ans
        elseif ($life_years <= 4)  $coef = 1.5;
        elseif ($life_years <= 6)  $coef = 2.0;
        else                       $coef = 2.5;

        $residual = max(0.0, $base - $accumulated);
        if ($residual <= 0) return 0.0;

        $straight_annual   = $base / ($life / 12.0);       // FCFA / year (constant)
        $declining_annual  = $residual * ($coef / $life_years);

        // Switch-back rule: once the flat straight-line would beat the
        // declining formula on residual value, use straight-line for the rest.
        $annual = max($straight_annual, $declining_annual);
        // Never book more than the residual base in a single year.
        if ($annual > $residual) $annual = $residual;

        return $annual / 12.0;
    }

    private static function empty(string $reason): array
    {
        return [
            'proration_days' => 0,
            'amount'         => 0.0,
            'is_full_month'  => false,
            'calculation'    => ['skipped' => true, 'reason' => $reason],
        ];
    }

    private static function roundFcfa(float $amount): float
    {
        return (float) round($amount, 0, PHP_ROUND_HALF_UP);
    }
}
