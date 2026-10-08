<?php
namespace App\Services\Finance;

use App\Core\DB;

/** Aging and unpaid-total calculations shared by payables (ap_bills) and receivables (ar_invoices). */
class Aging
{
    public const BUCKETS = ['current' => 'Current', 'd1_30' => '1–30 days', 'd31_60' => '31–60 days', 'd61_90' => '61–90 days', 'over_90' => 'Over 90 days'];

    /**
     * Unpaid balance per party as of $asOf, split by days past the due date (or the document date when no due date).
     * Returns rows [party, current, d1_30, d31_60, d61_90, over_90, total], largest total first.
     */
    public static function compute(string $table, string $partyTable, string $partyField, string $dateField, string $asOf): array
    {
        $rows = DB::all(
            "SELECT x.amount, x.paid_amount, x.due_date, x.$dateField AS doc_date, p.name AS party
             FROM $table x JOIN $partyTable p ON p.id = x.$partyField
             WHERE x.status IN ('open','partial') AND x.$dateField <= ?",
            [$asOf]
        );
        $byParty = [];
        $asOfTs = strtotime($asOf);
        foreach ($rows as $r) {
            $balance = r2($r['amount'] - $r['paid_amount']);
            $days = (int) floor(($asOfTs - strtotime($r['due_date'] ?: $r['doc_date'])) / 86400);
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? 'd1_30' : ($days <= 60 ? 'd31_60' : ($days <= 90 ? 'd61_90' : 'over_90')));
            $byParty[$r['party']] ??= ['party' => $r['party']] + array_fill_keys(array_keys(self::BUCKETS), 0.0) + ['total' => 0.0];
            $byParty[$r['party']][$bucket] = r2($byParty[$r['party']][$bucket] + $balance);
            $byParty[$r['party']]['total'] = r2($byParty[$r['party']]['total'] + $balance);
        }
        $out = array_values($byParty);
        usort($out, fn ($a, $b) => $b['total'] <=> $a['total']);
        return $out;
    }

    /** Totals of unpaid documents: all / overdue (due before today) / due in the next 7 days, with counts. */
    public static function stats(string $table, string $dateField): array
    {
        $today = today();
        $in7 = add_days($today, 7);
        $s = ['total' => 0.0, 'n' => 0, 'overdue' => 0.0, 'n_overdue' => 0, 'soon' => 0.0, 'n_soon' => 0];
        foreach (DB::all("SELECT amount - paid_amount AS balance, COALESCE(due_date, $dateField) AS due FROM $table WHERE status IN ('open','partial')") as $r) {
            $s['total'] += $r['balance'];
            $s['n']++;
            if ($r['due'] < $today) { $s['overdue'] += $r['balance']; $s['n_overdue']++; }
            elseif ($r['due'] <= $in7) { $s['soon'] += $r['balance']; $s['n_soon']++; }
        }
        return $s;
    }
}
