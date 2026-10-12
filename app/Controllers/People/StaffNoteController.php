<?php
namespace App\Controllers\People;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Payroll\Payroll;
use App\Services\Payroll\StaffNotes;

/** Staff notes recorded at the POS (absent, leave, undertime …), per cut-off, for payroll. */
class StaffNoteController
{
    public function index(Request $req)
    {
        $cutoffs = Payroll::recentCutoffs(10);
        $from = is_date($req->query('from')) ? $req->query('from') : $cutoffs[0]['from'];
        $to = is_date($req->query('to')) ? $req->query('to') : $cutoffs[0]['to'];
        if ($to < $from) [$from, $to] = [$to, $from];
        $empId = (int) $req->query('employee_id') ?: null;
        $rows = StaffNotes::list($from, $to, $empId, true);
        foreach ($rows as &$r) {
            $r['what'] = StaffNotes::KINDS[$r['kind']] ?? $r['kind'];
            $r['detail'] = StaffNotes::label($r);
            if ($r['status'] === 'void') $r['_class'] = 'muted-row';
        }
        unset($r);
        $manage = can('payroll.manage');
        $columns = [
            ['key' => 'note_date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'employee', 'label' => 'Employee', 'html' => fn ($r) => '<b>' . e($r['employee']) . '</b>'],
            ['key' => 'what', 'label' => 'What', 'html' => fn ($r) => badge($r['what'], ['absent' => 'red', 'leave' => 'blue', 'dayoff' => 'blue', 'undertime' => 'amber', 'late' => 'amber'][$r['kind']] ?? 'gray')],
            ['key' => 'hours', 'label' => 'Hours', 'type' => 'qty'],
            ['key' => 'note', 'label' => 'Reason / note'],
            ['key' => 'recorded_by', 'label' => 'Recorded by', 'html' => fn ($r) => e($r['recorded_by'] ?? '—') . '<div class="muted small">' . e(fmt_datetime($r['created_at'])) . '</div>'],
            ['key' => 'status', 'label' => 'Status', 'value' => fn ($r) => $r['status'] === 'applied' ? 'applied to attendance' : $r['status'],
                'html' => fn ($r) => $r['status'] === 'applied' ? badge('applied', 'green') . '<div class="muted small">' . e($r['applied_by_name'] ?? '') . '</div>' : badge($r['status'], $r['status'] === 'void' ? 'gray' : 'amber')],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => $manage && $r['status'] === 'open' ? '<div class="actions">'
                . (isset(StaffNotes::APPLIES[$r['kind']]) ? '<form method="post" action="' . url('/payroll/notes/' . $r['id'] . '/apply') . '" class="inline" data-confirm="Mark ' . e($r['note_date']) . ' of ' . e($r['employee']) . ' as ' . e(strtolower(\App\Services\Payroll\Attendance::DAY_TYPES[StaffNotes::APPLIES[$r['kind']]])) . ' in the attendance?">' . csrf_field() . '<button class="btn btn-sm" type="submit">Apply to attendance</button></form>' : '')
                . '<form method="post" action="' . url('/payroll/notes/' . $r['id'] . '/void') . '" class="inline" data-confirm="Remove this note?" data-danger>' . csrf_field() . '<button class="btn btn-sm btn-ghost text-red" type="submit">Remove</button></form></div>' : ''],
        ];
        if ($x = Table::export($req, 'staff-notes', 'Staff notes', range_label($from, $to), $columns, $rows)) return $x;
        return view('payroll/notes', ['title' => 'Staff notes', 'from' => $from, 'to' => $to, 'empId' => $empId, 'cutoffs' => $cutoffs,
            'rows' => $rows, 'columns' => $columns, 'summary' => StaffNotes::summary($from, $to),
            'employees' => DB::all('SELECT id, full_name FROM employees WHERE active = 1 ORDER BY full_name')]);
    }

    public function store(Request $req): Response
    {
        StaffNotes::record($req->all());
        flash('success', 'Note saved.');
        return back();
    }

    public function apply(Request $req, string $id): Response
    {
        StaffNotes::apply((int) $id);
        flash('success', 'Applied to the attendance. Recompute a draft payroll of this cut-off to use it.');
        return back();
    }

    public function void(Request $req, string $id): Response
    {
        StaffNotes::void((int) $id);
        flash('success', 'Note removed.');
        return back();
    }
}
