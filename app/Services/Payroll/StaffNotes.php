<?php
namespace App\Services\Payroll;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Pos\CashSessions;

/**
 * Staff notes: the cashier records at the POS who was absent, on leave, left early (undertime) or came late, with the reason.
 * Payroll uses them as a reference per cut-off (attendance grid, payslip) and can apply absent / leave / day off to the
 * attendance of that day in one click.
 */
class StaffNotes
{
    public const KINDS = ['absent' => 'Absent', 'leave' => 'On leave', 'undertime' => 'Undertime (left early)', 'late' => 'Late',
        'dayoff' => 'Day off (swapped)', 'other' => 'Other'];
    /** What applying a note sets the day of the attendance to. */
    public const APPLIES = ['absent' => 'absent', 'leave' => 'leave', 'dayoff' => 'rest'];
    private const WITH_HOURS = ['undertime', 'late'];

    /** The date a note is for by default: the open business day, else today. */
    public static function defaultDate(): string
    {
        return CashSessions::current()['business_date'] ?? today();
    }

    public static function record(array $d): int
    {
        Auth::require('staffnotes.record', 'payroll.manage');
        $empId = (int) ($d['employee_id'] ?? 0);
        $emp = DB::one('SELECT id, full_name, active FROM employees WHERE id = ?', [$empId]);
        if (!$emp) throw HttpException::bad('Choose the employee');
        $kind = (string) ($d['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) throw HttpException::bad('Choose what happened (absent, leave, undertime …)');
        $date = (string) ($d['note_date'] ?? '') ?: self::defaultDate();
        if (!is_date($date)) throw HttpException::bad('Enter a valid date');
        if ($date > add_days(today(), 1)) throw HttpException::bad('The date cannot be in the future');
        if ($date < add_days(today(), -62) && !Auth::can('payroll.manage')) throw HttpException::bad('Notes older than 2 months can only be added in the back office');
        $hours = null;
        if (in_array($kind, self::WITH_HOURS, true)) {
            $hours = r2(num($d['hours'] ?? 0));
            if ($hours <= 0 || $hours > 24) throw HttpException::bad('Enter how many hours (e.g. 1.5)');
        }
        $note = trim((string) ($d['note'] ?? ''));
        if ($kind === 'other' && $note === '') throw HttpException::bad('Write the note');
        $id = DB::insert('staff_notes', ['employee_id' => $empId, 'note_date' => $date, 'kind' => $kind, 'hours' => $hours,
            'note' => mb_substr($note, 0, 255) ?: null, 'status' => 'open', 'created_by' => Auth::id(), 'created_at' => now()]);
        Audit::log('create', 'staff_note', $id, ['employee' => $emp['full_name'], 'date' => $date, 'kind' => $kind, 'hours' => $hours, 'note' => $note]);
        return $id;
    }

    /** Void a note: payroll staff any note; the one who recorded it, their own note on the same day. */
    public static function void(int $id): void
    {
        $n = self::find($id);
        if ($n['status'] === 'void') throw HttpException::bad('Already removed');
        $own = (int) $n['created_by'] === Auth::id() && substr((string) $n['created_at'], 0, 10) === date('Y-m-d');
        if (!Auth::can('payroll.manage') && !$own) throw HttpException::forbidden('Only payroll staff can remove this note (you can remove your own notes on the day you made them)');
        DB::update('staff_notes', $id, ['status' => 'void']);
        Audit::log('void', 'staff_note', $id);
    }

    /** Apply absent / leave / day off to the attendance of that day (times from the device are kept). */
    public static function apply(int $id): void
    {
        Auth::require('payroll.manage');
        $n = self::find($id);
        if ($n['status'] !== 'open') throw HttpException::bad('Only open notes can be applied');
        $type = self::APPLIES[$n['kind']] ?? null;
        if (!$type) throw HttpException::bad(self::KINDS[$n['kind']] . ' notes are a reference only — fix the time in / out on the attendance if needed');
        $row = DB::one('SELECT * FROM attendance WHERE employee_id = ? AND work_date = ?', [$n['employee_id'], $n['note_date']]);
        $note = trim(self::KINDS[$n['kind']] . ($n['note'] ? ': ' . $n['note'] : '') . ' (cashier note)');
        Attendance::setDay((int) $n['employee_id'], $n['note_date'], [
            'time_in' => $row['time_in'] ?? '', 'time_out' => $row['time_out'] ?? '', 'day_type' => $type, 'note' => $note]);
        DB::update('staff_notes', $id, ['status' => 'applied', 'applied_by' => Auth::id(), 'applied_at' => now()]);
    }

    public static function find(int $id): array
    {
        $n = DB::one('SELECT * FROM staff_notes WHERE id = ?', [$id]);
        if (!$n) throw HttpException::notFound('Note');
        return $n;
    }

    /** Notes of a period (newest first), with employee and recorder names. */
    public static function list(string $from, string $to, ?int $employeeId = null, bool $withVoid = false): array
    {
        $where = 'n.note_date BETWEEN ? AND ?' . ($employeeId ? ' AND n.employee_id = ?' : '') . ($withVoid ? '' : " AND n.status <> 'void'");
        return DB::all("SELECT n.*, e.full_name AS employee, e.emp_no, u.full_name AS recorded_by, a.full_name AS applied_by_name
                        FROM staff_notes n JOIN employees e ON e.id = n.employee_id LEFT JOIN users u ON u.id = n.created_by LEFT JOIN users a ON a.id = n.applied_by
                        WHERE $where ORDER BY n.note_date DESC, n.id DESC", $employeeId ? [$from, $to, $employeeId] : [$from, $to]);
    }

    /** [employee id => [date => [notes]]] of a period, for the attendance grid. */
    public static function byDay(string $from, string $to): array
    {
        $out = [];
        foreach (self::list($from, $to) as $n) $out[(int) $n['employee_id']][$n['note_date']][] = $n;
        return $out;
    }

    /** "Absent: sick" / "Undertime 2 h: family emergency" */
    public static function label(array $n): string
    {
        $k = self::KINDS[$n['kind']] ?? $n['kind'];
        if (in_array($n['kind'], self::WITH_HOURS, true)) $k = ($n['kind'] === 'late' ? 'Late' : 'Undertime') . ' ' . rtrim(rtrim(number_format((float) $n['hours'], 2), '0'), '.') . ' h';
        return $k . ($n['note'] ? ': ' . $n['note'] : '');
    }

    /** Totals per employee of a period: [employee id => ['absent' => n, 'leave' => n, 'undertime' => hours, ...]]. */
    public static function summary(string $from, string $to): array
    {
        $out = [];
        foreach (self::list($from, $to) as $n) {
            $s = &$out[(int) $n['employee_id']];
            $s['employee'] = $n['employee'];
            $s[$n['kind']] = ($s[$n['kind']] ?? 0) + (in_array($n['kind'], self::WITH_HOURS, true) ? (float) $n['hours'] : 1);
            unset($s);
        }
        return $out;
    }
}
