<?php
namespace App\Services\Pos;

use App\Services\Settings;

/**
 * Philippine POS bill computation. Menu prices are VAT-inclusive, or VAT-exclusive when the business chose
 * "VAT is added on top" (tickets.vat_inclusive = 0): then every price and fixed discount is first grossed up
 * by the VAT rate, the bill is computed the same way, and the amounts shown (subtotal, discounts) are converted
 * back to net of VAT so the receipt reads: Subtotal - Discounts + VAT + Service charge = Total.
 *
 * Discounts can be on single items (ticket_items.discount_*) and/or on the whole receipt (tickets.discount_*):
 *  - Senior Citizen / PWD (RA 9994 / RA 10754): the qualified amount is VAT-exempt and gets the SC rate (20%)
 *    on its net-of-VAT amount. Either tag the senior's own items, or apply it to the whole receipt, where the
 *    qualified share is sc_count / pax of the bill. (When applied to the whole receipt, item discounts are ignored:
 *    an SC/PWD discount cannot be combined with other promos.)
 *  - Percent / fixed amount discounts (employee, promo, complimentary ...) reduce the VAT-able amount.
 *    A receipt-level promo applies to what is left after item discounts and SC/PWD items.
 *  - Service charge is computed on net sales (excluding VAT); optionally dine-in only.
 *
 * Example (whole receipt): gross 528, 1 senior of 2 pax -> SC share 264 -> net of VAT 235.71 -> 20% = 47.14;
 * the other 264 is VAT-able: 235.71 + 28.29 VAT. Total due = 264 + 235.71 - 47.14 = 452.57.
 */
class Totals
{
    public static function compute(array $ticket, array $lines): array
    {
        $tax = Settings::tax();
        $div = 1 + $tax['vatRate'];
        $incl = !isset($ticket['vat_inclusive']) || (int) $ticket['vat_inclusive'] === 1 || !$tax['vatRegistered'];
        $mul = $incl ? 1.0 : $div;     // shown price -> VAT-inclusive amount
        $active = array_values(array_filter($lines, fn ($l) => $l['status'] === 'active'));
        $gross = r2(array_sum(array_map(fn ($l) => (float) $l['line_total'] * $mul, $active)));
        $orderType = $ticket['discount_type'] ?? 'none';
        $orderIsSc = in_array($orderType, ['sc', 'pwd'], true);

        // ---- item discounts
        $lineDisc = [];          // line id => discount amount shown on the receipt
        $scLines = [];           // line id => net-of-VAT amount of SC/PWD items
        $itemScIncl = 0.0;
        $itemPromo = 0.0;
        foreach ($active as $l) {
            $id = (int) $l['id'];
            $lineDisc[$id] = 0.0;
            $kind = $orderIsSc ? null : ($l['discount_kind'] ?? null);
            $lt = r2((float) $l['line_total'] * $mul);
            if ($kind === 'sc' || $kind === 'pwd') {
                $itemScIncl += $lt;
                $scLines[$id] = $lt;
            } elseif ($kind === 'percent') {
                $lineDisc[$id] = r2($lt * min((float) $l['discount_value'], 100) / 100);
            } elseif ($kind === 'amount') {
                $lineDisc[$id] = r2(min((float) $l['discount_value'] * $mul, $lt));
            }
            $itemPromo += $lineDisc[$id];
        }
        $itemPromo = r2($itemPromo);

        // ---- SC / PWD (VAT-exempt part)
        if ($orderIsSc) {
            $pax = max((int) $ticket['pax'], 1);
            $cnt = min(max((int) $ticket['sc_count'], 1), $pax);
            $eligible = r2($gross * $cnt / $pax);
        } else {
            $eligible = r2($itemScIncl);
        }
        $eligibleNet = r2($eligible / $div);
        $scDisc = r2($eligibleNet * $tax['scRate']);
        if ($scLines) {
            // Spread the SC discount over the tagged items (last one takes the rounding difference)
            $left = $scDisc;
            $ids = array_keys($scLines);
            foreach ($ids as $i => $id) {
                $share = $i === count($ids) - 1 ? $left : r2($scLines[$id] / $eligible * $scDisc);
                $lineDisc[$id] = $share;
                $left = r2($left - $share);
            }
        }

        // ---- receipt-level promo on what is left
        $promoBase = r2($gross - $eligible - $itemPromo);
        $orderPromo = 0.0;
        if ($orderType === 'percent') $orderPromo = r2($promoBase * min((float) $ticket['discount_rate'], 100) / 100);
        elseif ($orderType === 'amount') $orderPromo = r2(min((float) $ticket['discount_rate'] * $mul, max($promoBase, 0)));
        $promo = r2($itemPromo + $orderPromo);

        $vatableIncl = r2($gross - $eligible - $promo);
        $vatableSales = r2($vatableIncl / $div);
        $vat = r2($vatableIncl - $vatableSales);
        $svcApplies = $tax['svcRate'] > 0 && (!$tax['svcDineInOnly'] || ($ticket['order_type'] ?? '') === 'dine_in');
        $svc = $svcApplies ? r2($tax['svcRate'] * ($vatableSales + $eligibleNet - $scDisc)) : 0.0;
        $total = r2($vatableIncl + $eligibleNet - $scDisc + $svc);

        if (!$incl) {
            // Show net-of-VAT amounts; the promo shown is what makes Subtotal - Discounts + VAT + Service = Total.
            foreach ($lineDisc as $id => $d) if (!isset($scLines[$id])) $lineDisc[$id] = r2($d / $div);
            $gross = r2(array_sum(array_map(fn ($l) => (float) $l['line_total'], $active)));
            $promo = r2($gross - $scDisc + $vat + $svc - $total);
        }

        return [
            'subtotal' => $gross,
            'discount_amount' => r2($scDisc + $promo),
            'sc_discount' => $scDisc,
            'promo_discount' => $promo,
            'vatable_sales' => $vatableSales,
            'vat_amount' => $vat,
            'vat_exempt_sales' => $eligibleNet,
            'service_charge' => $svc,
            'total' => $total,
            'line_discounts' => $lineDisc,
        ];
    }

    /**
     * Discounts net of VAT, as booked to "Sales Discounts":
     * SC/PWD discounts are already computed on net-of-VAT amounts; promo discounts include VAT when prices are
     * VAT-inclusive (and are already net when VAT is added on top).
     */
    public static function discountNetOfVat(array $totals, bool $vatInclusive = true): float
    {
        return r2($totals['sc_discount'] + ($vatInclusive ? $totals['promo_discount'] / (1 + Settings::tax()['vatRate']) : $totals['promo_discount']));
    }
}
