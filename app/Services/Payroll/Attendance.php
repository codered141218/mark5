<?php
namespace App\Services\Payroll;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\SheetReader;
use App\Core\XlsReader;
use App\Services\Audit;
use App\Services\Settings;

/**
 * Biometric attendance: import the file from the ZKTeco device and keep one row per employee per day (first and last
 * punch), which payroll uses. Accepted files:
 *  - the device's USB "report" (1_report_1.xls): sheet "Att.log report", a row of punches per employee under "ID: n";
 *  - the raw punch log (1_attlog.dat / .txt): "user id <tab> 2026-09-26 09:56:22 ..." per line;
 *  - any CSV / Excel list with a user ID column and date + time (one punch per row).
 * Device user IDs are matched to employees by employees.biometric_id (set on the first import).
 */
class Attendance
{
    public const DAY_TYPES = ['auto' => 'From the device', 'present' => 'Present', 'absent' => 'Absent', 'rest' => 'Day off', 'leave' => 'Paid leave'];

    // ------------------------------------------------------------------ reading files
    /**
     * Read an attendance file. Returns ['format', 'from', 'to', 'people' => [device id => ['name', 'days' => [date => ['09:56', '22:32']]]]].
     */
    public static function parse(string $path, string $originalName = ''): array
    {
        $ext = strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));
        if ($ext === 'xls' || $ext === 'xlsx') {
            $sheets = $ext === 'xls' ? XlsReader::sheets($path) : ['Sheet1' => SheetReader::xlsx($path)];
            foreach ($sheets as $rows) {
                if ($r = self::fromReport($rows)) return self::finish($r);
            }
            foreach ($sheets as $rows) {
                if ($r = self::fromPunchList($rows)) return self::finish($r);
            }
            throw HttpException::bad('No attendance found in this file. Upload the report (.xls) or the attlog (.dat) saved by the biometric device.');
        }
        if (in_array($ext, ['dat', 'txt', 'csv', 'tsv', ''], true)) {
            $rows = $ext === 'csv' ? SheetReader::csv($path) : self::textRows((string) file_get_contents($path));
            if ($r = self::fromPunchList($rows)) return self::finish($r);
            throw HttpException::bad('No punches found in this file. Each line needs the user ID and the date and time of the punch.');
        }
        throw HttpException::bad('Upload the attendance file from the biometric device (.xls, .xlsx, .dat, .txt or .csv)');
    }

    /** Lines of a text attlog split on tabs / commas / runs of spaces (the date and time stay together). */
    private static function textRows(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $rows = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $cells = str_contains($line, "\t") ? explode("\t", $line) : (str_contains($line, ',') ? str_getcsv($line) : preg_split('/\s{2,}/', $line));
            $rows[] = array_map('trim', $cells);
        }
        return $rows;
    }

    /** ZKTeco "Attendance Record Report": period on the "Att. Time" row, day numbers below it, then ID/Name rows each followed by a row of punches. */
    private static function fromReport(array $rows): ?array
    {
        $from = $to = null;
        $dayRow = null;
        foreach ($rows as $i => $r) {
            $joined = implode(' ', $r);
            if (!$from && preg_match('/(\d{4}-\d{2}-\d{2})\s*~\s*(\d{4}-\d{2}-\d{2})/', $joined, $m) && stripos($joined, 'Att') !== false) {
                [$from, $to] = [$m[1], $m[2]];
                $dayRow = $i + 1;
                break;
            }
        }
        if (!$from || !isset($rows[$dayRow])) return null;
        // column -> date: walk from the first day of the period, one day per day-number column
        $cols = [];
        $d = $from;
        foreach ($rows[$dayRow] as $c => $v) {
            if (!is_numeric($v)) continue;
            while ($d <= $to && (int) date('j', strtotime($d)) !== (int) $v) $d = add_days($d, 1);
            if ($d > $to) break;
            $cols[$c] = $d;
            $d = add_days($d, 1);
        }
        if (!$cols) return null;
        $people = [];
        for ($i = $dayRow + 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            if (($r[0] ?? '') !== 'ID:') continue;
            $id = '';
            $name = '';
            foreach ($r as $c => $v) {
                if ($v === 'ID:') { for ($k = $c + 1; $k < count($r); $k++) if ($r[$k] !== '') { $id = $r[$k]; break; } }
                if ($v === 'Name:') { for ($k = $c + 1; $k < count($r); $k++) if ($r[$k] !== '') { $name = $r[$k]; break; } }
            }
            $id = ltrim(trim($id), '0') ?: ($id === '' ? '' : '0');
            if ($id === '') continue;
            $days = [];
            $next = $rows[$i + 1] ?? [];
            if (($next[0] ?? '') !== 'ID:') {
                foreach ($cols as $c => $date) {
                    preg_match_all('/\d{1,2}:\d{2}/', (string) ($next[$c] ?? ''), $m);
                    if ($m[0]) $days[$date] = array_map(fn ($t) => str_pad($t, 5, '0', STR_PAD_LEFT), $m[0]);
                }
            }
            $people[$id] = ['name' => $name ?: "User $id", 'days' => $days];
        }
        return $people ? ['format' => 'ZKTeco attendance report', 'from' => $from, 'to' => $to, 'people' => $people] : null;
    }

    /** One punch per row: a user ID cell and a date-time (or a date cell and a time cell); an optional name cell. */
    private static function fromPunchList(array $rows): ?array
    {
        $people = [];
        foreach ($rows as $r) {
            $id = null; $name = null; $date = null; $time = null;
            foreach ($r as $v) {
                $v = trim((string) $v);
                if ($v === '') continue;
                if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})[ T](\d{1,2}):(\d{2})/', $v, $m)) {
                    $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]); $time = sprintf('%02d:%02d', $m[4], $m[5]);
                } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})[ T](\d{1,2}):(\d{2})/', $v, $m)) {   // 9/26/2026 09:56
                    $date = sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]); $time = sprintf('%02d:%02d', $m[4], $m[5]);
                } elseif (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $v, $m)) {
                    $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                } elseif (preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', $v, $m)) {
                    $time = sprintf('%02d:%02d', $m[1], $m[2]);
                } elseif ($id === null && preg_match('/^\d{1,9}$/', $v)) {
                    $id = ltrim($v, '0') ?: '0';
                } elseif ($name === null && preg_match('/[A-Za-z]/', $v) && !preg_match('/^(no|id|ac-no|user|name|time|date|check|status)\b/i', $v)) {
                    $name = $v;
                }
            }
            if ($id === null || !$date || !$time) continue;
            $people[$id]['name'] = $people[$id]['name'] ?? ($name ?: "User $id");
            $people[$id]['days'][$date][] = $time;
        }
        if (!$people) return null;
        $dates = [];
        foreach ($people as $p) $dates = array_merge($dates, array_keys($p['days']));
        return ['format' => 'punch log', 'from' => min($dates), 'to' => max($dates), 'people' => $people];
    }

    /** Sort punches; a punch before "day starts" (e.g. 00:40 after closing) belongs to the previous day's shift. */
    private static function finish(array $r): array
    {
        $dayStarts = Settings::get('payroll.day_starts', '05:00');
        foreach ($r['people'] as &$p) {
            $days = $p['days'] ?? [];
            ksort($days);
            foreach ($days as &$t) sort($t);
            unset($t);
            foreach (array_keys($days) as $date) {
                $prev = add_days($date, -1);
                while (!empty($days[$date]) && $days[$date][0] < $dayStarts && isset($days[$prev]) && count($days[$prev]) % 2 === 1) {
                    $days[$prev][] = array_shift($days[$date]);
                }
                if (isset($days[$date]) && !$days[$date]) unset($days[$date]);
            }
            $p['days'] = $days;
        }
        unset($p);
        return $r;
    }

    // ------------------------------------------------------------------ matching & saving
    /**
     * Each device user with the employee it matches: the employee linked to that device ID, else a name suggestion
     * (only for users with punches, and never an employee already linked or suggested for another device user).
     */
    public static function preview(array $parsed): array
    {
        $emps = DB::all('SELECT id, emp_no, full_name, biometric_id, active FROM employees ORDER BY active DESC, full_name');
        $byDevice = [];
        foreach ($emps as $e) if ($e['biometric_id'] !== null && $e['biometric_id'] !== '') $byDevice[(string) $e['biometric_id']] = (int) $e['id'];
        $taken = [];
        $out = [];
        foreach ($parsed['people'] as $id => $p) {
            $match = $byDevice[(string) $id] ?? null;
            if ($match) $taken[$match] = true;
            $out[(string) $id] = ['device_id' => (string) $id, 'name' => $p['name'], 'days' => count($p['days']), 'employee_id' => $match, 'matched_by' => $match ? 'device ID' : ''];
        }
        // name suggestions: exact full / first name first, then a first name that starts the same (3+ letters)
        foreach ([1, 2] as $pass) {
            foreach ($out as &$x) {
                if ($x['employee_id'] || !$x['days']) continue;
                $n = mb_strtolower(trim($x['name']));
                if (mb_strlen($n) < 2 || preg_match('/^user \d+$/', $n)) continue;
                foreach ($emps as $e) {
                    if (!$e['active'] || isset($taken[(int) $e['id']]) || ($e['biometric_id'] !== null && $e['biometric_id'] !== '')) continue;
                    $full = mb_strtolower(trim($e['full_name']));
                    $first = explode(' ', $full)[0];
                    $hit = $pass === 1 ? ($full === $n || $first === $n)
                        : (mb_strlen($n) >= 3 && mb_strlen($first) >= 3 && (str_starts_with($first, $n) || str_starts_with($n, $first)));
                    if ($hit) { $x['employee_id'] = (int) $e['id']; $x['matched_by'] = 'name'; $taken[(int) $e['id']] = true; break; }
                }
            }
            unset($x);
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => (int) $a['device_id'] <=> (int) $b['device_id']);
        return $out;
    }

    /**
     * Save the punches. $map = [device id => employee id | 'new' | 'skip']. 'new' creates the employee with the device name.
     * One device user per employee. Days set by hand (source manual) are kept; a device user without any punch in the file
     * is only linked (their days are not touched). Returns ['employees' => n, 'days' => n, 'import_id' => id, 'linked' => [...]].
     */
    public static function save(array $parsed, array $map, string $fileName = ''): array
    {
        Auth::require('payroll.manage');
        $chosen = [];
        foreach ($parsed['people'] as $devId => $p) {
            $choice = (string) ($map[(string) $devId] ?? 'skip');
            if ($choice === 'skip' || $choice === '' || $choice === 'new') continue;
            $chosen[(int) $choice][] = '#' . $devId . ' ' . $p['name'];
        }
        foreach ($chosen as $empId => $devs) {
            if (count($devs) > 1) {
                $name = DB::value('SELECT full_name FROM employees WHERE id = ?', [$empId]) ?: 'One employee';
                throw HttpException::bad("$name is chosen for more than one device user (" . implode(', ', $devs) . '). '
                    . 'Choose the device user who punches for this employee and set the other one to Skip.');
            }
        }
        return DB::transaction(function () use ($parsed, $map, $fileName) {
            $importId = DB::insert('attendance_imports', ['file_name' => mb_substr($fileName, 0, 190) ?: null, 'period_from' => $parsed['from'], 'period_to' => $parsed['to'],
                'created_by' => Auth::id(), 'created_at' => now()]);
            $emps = 0;
            $days = 0;
            $linked = [];
            foreach ($parsed['people'] as $devId => $p) {
                $devId = (string) $devId;
                $choice = (string) ($map[$devId] ?? 'skip');
                if ($choice === 'skip' || $choice === '') continue;
                if ($choice === 'new') {
                    $empId = \App\Services\Finance\Employees::save(['full_name' => $p['name'], 'active' => 1]);
                } else {
                    $empId = (int) $choice;
                    if (!DB::value('SELECT id FROM employees WHERE id = ?', [$empId])) throw HttpException::notFound('Employee');
                }
                DB::run('UPDATE employees SET biometric_id = NULL WHERE biometric_id = ? AND id <> ?', [$devId, $empId]);
                DB::run('UPDATE employees SET biometric_id = ? WHERE id = ?', [$devId, $empId]);
                $emps++;
                $n = 0;
                // every day of the file's period: punches, or none (absent unless set by hand)
                if ($p['days']) {
                    for ($d = $parsed['from']; $d <= $parsed['to']; $d = add_days($d, 1)) {
                        $punches = $p['days'][$d] ?? [];
                        $row = DB::one('SELECT * FROM attendance WHERE employee_id = ? AND work_date = ?', [$empId, $d]);
                        if ($row && $row['source'] === 'manual') continue;
                        $data = [
                            'time_in' => $punches ? $punches[0] . ':00' : null,
                            'time_out' => count($punches) > 1 ? end($punches) . ':00' : null,
                            'punches' => $punches ? implode(' ', $punches) : null,
                            'source' => 'device', 'import_id' => $importId, 'updated_by' => Auth::id(), 'updated_at' => now(),
                        ];
                        if ($row) DB::update('attendance', (int) $row['id'], $data);
                        elseif ($punches) DB::insert('attendance', $data + ['employee_id' => $empId, 'work_date' => $d, 'day_type' => 'auto']);
                        if ($punches) $n++;
                    }
                }
                $days += $n;
                $linked[] = ['employee_id' => $empId, 'device_id' => $devId, 'days' => $n];
            }
            DB::update('attendance_imports', $importId, ['employees' => $emps, 'days' => $days]);
            Audit::log('import', 'attendance', $importId, ['file' => $fileName, 'from' => $parsed['from'], 'to' => $parsed['to'], 'employees' => $emps,
                'map' => array_map(fn ($l) => "#{$l['device_id']}→{$l['employee_id']}", $linked)]);
            return ['employees' => $emps, 'days' => $days, 'import_id' => $importId, 'linked' => $linked];
        });
    }

    /** Change one day by hand (fix a missing punch, mark a day off / leave). Empty times clear them. */
    public static function setDay(int $employeeId, string $date, array $d): void
    {
        Auth::require('payroll.manage');
        if (!is_date($date)) throw HttpException::bad('Invalid date');
        if (!DB::value('SELECT id FROM employees WHERE id = ?', [$employeeId])) throw HttpException::notFound('Employee');
        $time = function ($v) {
            $v = trim((string) $v);
            if ($v === '') return null;
            if (!preg_match('/^(\d{1,2}):(\d{2})/', $v, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) throw HttpException::bad('Enter times like 10:00 or 22:30');
            return sprintf('%02d:%02d:00', $m[1], $m[2]);
        };
        $type = isset(self::DAY_TYPES[$d['day_type'] ?? '']) ? $d['day_type'] : 'auto';
        $data = ['time_in' => $time($d['time_in'] ?? ''), 'time_out' => $time($d['time_out'] ?? ''), 'day_type' => $type,
            'note' => mb_substr(trim((string) ($d['note'] ?? '')), 0, 255) ?: null, 'source' => 'manual', 'updated_by' => Auth::id(), 'updated_at' => now()];
        $row = DB::one('SELECT id FROM attendance WHERE employee_id = ? AND work_date = ?', [$employeeId, $date]);
        if ($row) DB::update('attendance', (int) $row['id'], $data);
        else DB::insert('attendance', $data + ['employee_id' => $employeeId, 'work_date' => $date]);
        Audit::log('update', 'attendance', $employeeId, ['date' => $date] + $data);
    }

    /** Attendance rows of a period: [employee id => [date => row]]. */
    public static function rows(string $from, string $to, ?int $employeeId = null): array
    {
        $out = [];
        $sql = 'SELECT * FROM attendance WHERE work_date BETWEEN ? AND ?' . ($employeeId ? ' AND employee_id = ?' : '');
        foreach (DB::all($sql, $employeeId ? [$from, $to, $employeeId] : [$from, $to]) as $r) $out[(int) $r['employee_id']][$r['work_date']] = $r;
        return $out;
    }
}
