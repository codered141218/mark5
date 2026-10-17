<?php
/**
 * Payroll demo data on top of seed.php: daily rates and ZKTeco user IDs for the employees, a posted payroll for
 * Sep 16–30 built from generated punches, and a ZKTeco attlog.dat for Oct 1–15 (written next to this script as
 * zkteco-1_attlog.dat) ready to be imported on screen.
 */
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/_dates.php';

use App\Core\Auth;
use App\Core\DB;
use App\Services\Finance\Employees;
use App\Services\Finance\Payroll;

mt_srand(11);
Auth::actAs(1);

$staff = [
    ['Juan Dela Cruz', 610, '7'], ['Maria Santos', 570, '12'], ['Ramon Bautista', 540, '15'], ['Grace Lim', 520, '21'],
];
foreach ($staff as [$name, $rate, $bio]) {
    $e = DB::one('SELECT * FROM employees WHERE full_name = ?', [$name]);
    if (!$e) continue;
    Employees::save(['id' => $e['id'], 'full_name' => $e['full_name'], 'position' => $e['position'], 'department' => $e['department'], 'phone' => $e['phone'],
        'date_hired' => $e['date_hired'], 'daily_rate' => $rate, 'biometric_id' => $bio, 'notes' => $e['notes'], 'active' => 1]);
}

/** Generate attlog.dat lines (userid<TAB>time<TAB>verify<TAB>status<TAB>workcode) for a date range, 6-day weeks. */
function punches(string $from, string $to, array $staff): string
{
    $out = [];
    for ($d = new DateTime($from); $d <= new DateTime($to); $d->modify('+1 day')) {
        if ((int) $d->format('N') === 1) continue;                       // closed on Mondays
        foreach ($staff as $i => [, , $bio]) {
            if (mt_rand(1, 14) === 1) continue;                          // an absence now and then
            $in = [7, 8, 8, 9, 9, 10][($i + (int) $d->format('j')) % 6];   // shift start
            $inTs = sprintf('%s %02d:%02d:%02d', $d->format('Y-m-d'), $in, mt_rand(0, 14), mt_rand(0, 59));
            $len = 9 + (mt_rand(1, 4) === 1 ? mt_rand(1, 3) : 0);        // 9 h on site incl. break, sometimes overtime
            $outTs = date('Y-m-d H:i:s', strtotime($inTs) + $len * 3600 + mt_rand(-10, 25) * 60);
            $out[] = "$bio\t$inTs\t1\t0\t0";
            $out[] = "$bio\t$outTs\t1\t1\t0";
        }
    }
    return implode("\n", $out) . "\n";
}

// Previous cutoff: imported and posted so the payroll list has history
Payroll::importAttendance(punches(shift('2026-09-16'), shift('2026-09-30'), $staff), 'ZKTeco K40 · kitchen door');
$run = Payroll::createRun(['period_from' => shift('2026-09-16'), 'period_to' => shift('2026-09-30'), 'pay_date' => shift('2026-09-30'), 'method' => 'cash', 'notes' => 'Previous cutoff']);
$lines = [];
foreach (Payroll::find($run)['lines'] as $l) $lines[$l['id']] = ['other_deductions' => [150, 120, 110, 100][array_search($l['employee_name'], array_column($staff, 0)) ?: 0]];
Payroll::saveLines($run, $lines);
Payroll::post($run);
DB::run('UPDATE payroll_runs SET created_at = ?, posted_at = ? WHERE id = ?', [shift('2026-09-30 17:10:00'), shift('2026-09-30 17:25:00'), $run]);

// Current cutoff: the file the video imports
file_put_contents(__DIR__ . '/zkteco-1_attlog.dat', punches(shift('2026-10-01'), min(shift('2026-10-15'), today()), $staff));
echo "Payroll demo ready: posted run $run, attlog written to " . __DIR__ . "/zkteco-1_attlog.dat\n";
