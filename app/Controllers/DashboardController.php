<?php
namespace App\Controllers;

use App\Core\Request;
use App\Services\Reports\Dashboard;

/** Back-office dashboard (GET /, shown by AuthController::home to users with dashboard.view). */
class DashboardController
{
    public function index(Request $req): string
    {
        [$from, $to] = date_range($req);
        $d = Dashboard::data($from, $to);
        return view('dashboard/index', [
            'title' => 'Dashboard', 'd' => $d, 'from' => $from, 'to' => $to,
            'singleDay' => count($d['daily']) <= 1,
            'dailySeries' => self::dailySeries($d['daily'], $from, $to),
            'hourlySales' => self::hourlySeries($d['hourly'], 'net', fn ($h) => $h['receipts'] . ' receipts'),
            'hourlyReceipts' => self::hourlySeries($d['hourly'], 'receipts', null),
        ]);
    }

    /** One bar per day of the range (days without sales show as empty), up to ~3 months; longer ranges show sales days only. */
    private static function dailySeries(array $daily, string $from, string $to): array
    {
        $byDate = array_column($daily, null, 'date');
        $dates = (strtotime($to) - strtotime($from)) / 86400 <= 92 ? [] : array_keys($byDate);
        if (!$dates) for ($day = $from; $day <= $to; $day = add_days($day, 1)) $dates[] = $day;
        return array_map(fn ($day) => [
            'label' => date('j', strtotime($day)),
            'tip' => fmt_date($day) . ' · ' . (int) ($byDate[$day]['receipts'] ?? 0) . ' receipts',
            'value' => (float) ($byDate[$day]['net'] ?? 0),
        ], $dates);
    }

    /** One bar per hour from the first to the last hour with sales. */
    private static function hourlySeries(array $hourly, string $field, ?callable $tip): array
    {
        $hourly = array_filter($hourly, fn ($r) => $r['hour'] !== null);
        if (!$hourly) return [];
        $byHour = array_column($hourly, null, 'hour');
        $out = [];
        for ($h = (int) min(array_keys($byHour)); $h <= (int) max(array_keys($byHour)); $h++) {
            $row = $byHour[$h] ?? ['receipts' => 0, 'net' => 0];
            $out[] = [
                'label' => (string) $h,
                'tip' => sprintf('%02d:00 – %02d:59', $h, $h) . ($tip ? ' · ' . $tip($row) : ''),
                'value' => (float) $row[$field],
            ];
        }
        return $out;
    }
}
