<?php
namespace App\Services\Finance;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\Sequence;

/**
 * Employee cash advances (vale): request -> approve & release (or reject / cancel) -> repayments until settled.
 * Release:   Dr Advances to Employees / Cr cash on hand, petty cash fund or bank
 * Repayment: Dr Salaries Payable (payroll deduction), cash on hand or bank / Cr Advances to Employees
 */
class CashAdvances
{
    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved (outstanding)', 'settled' => 'Settled', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
    public const RELEASE_METHODS = ['cash' => 'Cash on hand', 'petty_cash' => 'Petty cash fund', 'bank' => 'Bank account'];
    public const REPAY_METHODS = ['payroll' => 'Salary deduction (payroll)', 'cash' => 'Cash on hand', 'bank' => 'Bank account'];

    /** Approvers and managers see every advance; other users only those they filed or that are theirs. */
    public static function seesAll(): bool
    {
        return Auth::can('ca.approve', 'ca.manage');
    }

    /** $f: from, to, status, employee_id */
    public static function list(array $f): array
    {
        $where = ['c.request_date BETWEEN ? AND ?'];
        $params = [$f['from'] ?? '1000-01-01', $f['to'] ?? '9999-12-31'];
        if (!empty($f['status'])) { $where[] = 'c.status = ?'; $params[] = $f['status']; }
        if (!empty($f['employee_id'])) { $where[] = 'c.employee_id = ?'; $params[] = (int) $f['employee_id']; }
        if (!self::seesAll()) {
            $where[] = '(c.requested_by = ? OR c.employee_id = ?)';
            array_push($params, Auth::id(), Auth::user()['employee_id'] ?? -1);
        }
        return DB::all(
            'SELECT c.*, e.full_name AS employee_name, e.emp_no, ru.full_name AS requested_by_name, au.full_name AS approved_by_name
             FROM cash_advances c JOIN employees e ON e.id = c.employee_id
             LEFT JOIN users ru ON ru.id = c.requested_by LEFT JOIN users au ON au.id = c.approved_by
             WHERE ' . implode(' AND ', $where) . ' ORDER BY c.request_date DESC, c.id DESC',
            $params
        );
    }

    public static function find(int $id): array
    {
        $c = DB::one(
            'SELECT c.*, e.full_name AS employee_name, e.emp_no, ru.full_name AS requested_by_name, au.full_name AS approved_by_name, b.bank_name
             FROM cash_advances c JOIN employees e ON e.id = c.employee_id
             LEFT JOIN users ru ON ru.id = c.requested_by LEFT JOIN users au ON au.id = c.approved_by
             LEFT JOIN bank_accounts b ON b.id = c.bank_account_id WHERE c.id = ?',
            [$id]
        );
        if (!$c) throw HttpException::notFound('Cash advance');
        if (!self::seesAll() && (int) $c['requested_by'] !== Auth::id() && (int) $c['employee_id'] !== (Auth::user()['employee_id'] ?? -1)) {
            throw HttpException::forbidden();
        }
        $c['repayments'] = DB::all(
            'SELECT r.*, b.bank_name, u.full_name AS created_by_name FROM ca_repayments r
             LEFT JOIN bank_accounts b ON b.id = r.bank_account_id LEFT JOIN users u ON u.id = r.created_by
             WHERE r.advance_id = ? ORDER BY r.pay_date, r.id',
            [$id]
        );
        return $c;
    }

    /** File a request. Users without approval / HR rights may only request for their own linked employee record. */
    public static function request(array $d): int
    {
        Auth::require('ca.request');
        required($d, 'employee_id', 'amount');
        $amount = r2($d['amount']);
        if (!($amount > 0)) throw HttpException::bad('Amount must be greater than zero');
        $empId = (int) $d['employee_id'];
        if (!Auth::can('ca.approve', 'ca.manage', 'employees.manage') && $empId !== (Auth::user()['employee_id'] ?? null)) {
            throw HttpException::bad('You can only request a cash advance for yourself. Ask the admin to link your user to your employee record.');
        }
        if (!DB::value('SELECT id FROM employees WHERE id = ?', [$empId])) throw HttpException::notFound('Employee');
        $id = DB::insert('cash_advances', [
            'doc_no' => Sequence::next('CA', 'CA'), 'employee_id' => $empId,
            'request_date' => is_date($d['request_date'] ?? null) ? $d['request_date'] : today(), 'amount' => $amount,
            'reason' => trim((string) ($d['reason'] ?? '')) ?: null, 'repayment_terms' => trim((string) ($d['repayment_terms'] ?? '')) ?: null,
            'status' => 'pending', 'requested_by' => Auth::id(), 'balance' => 0, 'created_at' => now(),
        ]);
        Audit::log('request', 'cash_advance', $id, ['amount' => $amount]);
        return $id;
    }

    /** Approve and release. $d: amount (blank = requested), release_date, release_method, bank_account_id, remarks. */
    public static function approve(int $id, array $d): void
    {
        Auth::require('ca.approve');
        $c = self::find($id);
        if ($c['status'] !== 'pending') throw HttpException::bad('Only pending requests can be approved');
        $method = $d['release_method'] ?? 'cash';
        if (!isset(self::RELEASE_METHODS[$method])) throw HttpException::bad('Choose how the advance is released');
        $amount = isset($d['amount']) && $d['amount'] !== '' ? r2($d['amount']) : r2($c['amount']);
        if (!($amount > 0)) throw HttpException::bad('Approved amount must be greater than zero');
        $date = is_date($d['release_date'] ?? null) ? $d['release_date'] : today();
        $bankId = $method === 'bank' ? (int) ($d['bank_account_id'] ?? 0) : null;

        DB::transaction(function () use ($c, $d, $amount, $method, $date, $bankId) {
            $je = Ledger::post($date, "Cash advance {$c['doc_no']} - {$c['employee_name']}", [
                ['key' => 'emp_advances', 'debit' => $amount, 'party_type' => 'employee', 'party_id' => (int) $c['employee_id']],
                ['account_id' => Ledger::sourceAccount($method, $bankId), 'credit' => $amount, 'bank_account_id' => $bankId],
            ], 'cash_advance', (int) $c['id'], $c['doc_no']);
            DB::update('cash_advances', (int) $c['id'], [
                'status' => 'approved', 'amount' => $amount, 'balance' => $amount, 'approved_by' => Auth::id(), 'approved_at' => now(),
                'release_method' => $method, 'bank_account_id' => $bankId, 'release_date' => $date,
                'remarks' => trim((string) ($d['remarks'] ?? '')) ?: $c['remarks'], 'journal_entry_id' => $je,
            ]);
        });
        Audit::log('approve', 'cash_advance', $id, ['amount' => $amount, 'method' => $method]);
    }

    public static function reject(int $id, ?string $remarks): void
    {
        Auth::require('ca.approve');
        $c = self::find($id);
        if ($c['status'] !== 'pending') throw HttpException::bad('Only pending requests can be rejected');
        DB::update('cash_advances', $id, ['status' => 'rejected', 'approved_by' => Auth::id(), 'approved_at' => now(), 'remarks' => trim((string) $remarks) ?: null]);
        Audit::log('reject', 'cash_advance', $id, $remarks);
    }

    /** The requester (or an approver) may cancel a pending request. */
    public static function cancel(int $id): void
    {
        $c = self::find($id);
        if ($c['status'] !== 'pending') throw HttpException::bad('Only pending requests can be cancelled');
        if ((int) $c['requested_by'] !== Auth::id() && !Auth::can('ca.approve')) throw HttpException::bad('You can only cancel your own request');
        DB::update('cash_advances', $id, ['status' => 'cancelled']);
        Audit::log('cancel', 'cash_advance', $id);
    }

    /** Record a repayment. $d: amount, method (payroll|cash|bank), bank_account_id, reference, pay_date. Returns the repayment id. */
    public static function repay(int $id, array $d): int
    {
        Auth::require('ca.manage');
        $c = self::find($id);
        if ($c['status'] !== 'approved') throw HttpException::bad('Advance is not outstanding');
        $amount = r2($d['amount'] ?? 0);
        $balance = r2($c['balance']);
        if (!($amount > 0) || $amount > $balance + 0.001) throw HttpException::bad('Amount must be between 0 and the balance of ' . number_format($balance, 2));
        $method = $d['method'] ?? 'payroll';
        if (!isset(self::REPAY_METHODS[$method])) throw HttpException::bad('Choose how the advance was repaid');
        $date = is_date($d['pay_date'] ?? null) ? $d['pay_date'] : today();
        $bankId = $method === 'bank' ? (int) ($d['bank_account_id'] ?? 0) : null;

        $rid = DB::transaction(function () use ($c, $d, $amount, $balance, $method, $date, $bankId) {
            $target = Ledger::sourceAccount($method, $bankId);
            $rid = DB::insert('ca_repayments', [
                'doc_no' => Sequence::next('CAR', 'CAR'), 'advance_id' => $c['id'], 'pay_date' => $date, 'amount' => $amount, 'method' => $method,
                'bank_account_id' => $bankId, 'reference' => trim((string) ($d['reference'] ?? '')) ?: null,
                'status' => 'posted', 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            $how = $method === 'payroll' ? 'salary deduction' : $method;
            $je = Ledger::post($date, "CA repayment {$c['doc_no']} - {$c['employee_name']} ($how)", [
                ['account_id' => $target, 'debit' => $amount, 'bank_account_id' => $bankId],
                ['key' => 'emp_advances', 'credit' => $amount, 'party_type' => 'employee', 'party_id' => (int) $c['employee_id']],
            ], 'ca_repayment', $rid, $c['doc_no']);
            DB::update('ca_repayments', $rid, ['journal_entry_id' => $je]);
            $left = r2($balance - $amount);
            DB::update('cash_advances', (int) $c['id'], ['balance' => $left, 'status' => $left <= 0.001 ? 'settled' : 'approved']);
            return $rid;
        });
        Audit::log('repay', 'cash_advance', $id, ['amount' => $amount, 'method' => $method]);
        return $rid;
    }

    /** Void a repayment: reverses its entry and puts the amount back on the advance balance. */
    public static function voidRepayment(int $repaymentId, ?string $reason): void
    {
        Auth::require('ca.manage');
        $r = DB::one('SELECT * FROM ca_repayments WHERE id = ?', [$repaymentId]);
        if (!$r || $r['status'] !== 'posted') throw HttpException::bad('Repayment not found or already void');
        DB::transaction(function () use ($r) {
            Ledger::reverse($r['journal_entry_id'] ? (int) $r['journal_entry_id'] : null, today());
            DB::update('ca_repayments', (int) $r['id'], ['status' => 'void']);
            DB::run("UPDATE cash_advances SET balance = balance + ?, status = 'approved' WHERE id = ?", [(float) $r['amount'], (int) $r['advance_id']]);
        });
        Audit::log('void', 'ca_repayment', $repaymentId, $reason);
    }
}
