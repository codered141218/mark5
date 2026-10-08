<?php
namespace App\Services\Pos;

use App\Services\Settings;

/**
 * Philippine POS bill computation. Menu prices are VAT-inclusive.
 *
 *  - Senior Citizen / PWD (RA 9994 / RA 10754): the qualified share of the bill (sc_count / pax) is VAT-exempt
 *    and gets a 20% discount on its net-of-VAT amount.
 *  - Promo % / fixed amount discounts reduce the VAT-able amount.
 *  - Service charge is computed on net sales (excluding VAT); optionally dine-in only.
 *
 * Example: gross 528, 1 senior of 2 pax -> SC share 264 -> net of VAT 235.71 -> 20% = 47.14 discount;
 * the other 264 is VAT-able: 235.71 + 28.29 VAT. Total due = 264 + 235.71 - 47.14 = 452.57.
 */
class Totals
{
    public static function compute(array $ticket, array $lines): array
    {
        $tax = Settings::tax();
        $div = 1 + $tax['vatRate'];
        $gross = r2(array_sum(array_map(fn ($l) => $l['status'] === 'active' ? (float) $l['line_total'] : 0, $lines)));

        $eligible = $eligibleNet = $scDisc = $regDisc = 0.0;
        $type = $ticket['discount_type'] ?? 'none';
        if ($type === 'sc' || $type === 'pwd') {
            $pax = max((int) $ticket['pax'], 1);
            $cnt = min(max((int) $ticket['sc_count'], 1), $pax);
            $eligible = r2($gross * $cnt / $pax);
            $eligibleNet = r2($eligible / $div);
            $scDisc = r2($eligibleNet * $tax['scRate']);
        } elseif ($type === 'percent') {
            $regDisc = r2($gross * min((float) $ticket['discount_rate'], 100) / 100);
        } elseif ($type === 'amount') {
            $regDisc = r2(min((float) $ticket['discount_rate'], $gross));
        }

        $vatableIncl = r2($gross - $eligible - $regDisc);
        $vatableSales = r2($vatableIncl / $div);
        $vat = r2($vatableIncl - $vatableSales);
        $svcApplies = $tax['svcRate'] > 0 && (!$tax['svcDineInOnly'] || ($ticket['order_type'] ?? '') === 'dine_in');
        $svc = $svcApplies ? r2($tax['svcRate'] * ($vatableSales + $eligibleNet - $scDisc)) : 0.0;
        $total = r2($vatableIncl + $eligibleNet - $scDisc + $svc);

        return [
            'subtotal' => $gross,
            'discount_amount' => r2($scDisc + $regDisc),
            'vatable_sales' => $vatableSales,
            'vat_amount' => $vat,
            'vat_exempt_sales' => $eligibleNet,
            'service_charge' => $svc,
            'total' => $total,
        ];
    }

    /**
     * Discount amount net of VAT, as booked to "Sales Discounts".
     * SC/PWD discounts are already computed on net-of-VAT amounts; promo discounts include VAT.
     */
    public static function discountNetOfVat(array $ticket, float $discountAmount): float
    {
        if (in_array($ticket['discount_type'], ['sc', 'pwd'], true)) return r2($discountAmount);
        return r2($discountAmount / (1 + Settings::tax()['vatRate']));
    }
}
