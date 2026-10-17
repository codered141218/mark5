<?php
/** Staff accounts for the POV footage: the owner's name on the admin account, a waiter user on his phone, and a cash advance for him. */
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/_dates.php';

use App\Core\Auth;
use App\Core\DB;
use App\Services\Admin\Users;
use App\Services\Finance\CashAdvances;

Auth::actAs(1);
DB::run("UPDATE users SET full_name = 'Nena Villanueva' WHERE username = 'admin'");
$waiterRole = (int) DB::value("SELECT id FROM roles WHERE name = 'Waiter'");
$ramon = (int) DB::value("SELECT id FROM employees WHERE full_name = 'Ramon Bautista'");
if (!DB::value("SELECT id FROM users WHERE username = 'ramon'")) {
    Users::create(['username' => 'ramon', 'full_name' => 'Ramon Bautista', 'password' => 'waiter123', 'role_id' => $waiterRole, 'employee_id' => $ramon]);
}
if (!DB::value('SELECT id FROM cash_advances WHERE employee_id = ?', [$ramon])) {
    $ca = CashAdvances::request(['employee_id' => $ramon, 'amount' => 2000, 'request_date' => shift('2026-10-02'), 'reason' => 'School supplies for the kids', 'repayment_terms' => '₱400 per payroll']);
    CashAdvances::approve($ca, ['release_method' => 'cash', 'release_date' => shift('2026-10-03')]);
}
echo "Staff ready: admin = Nena Villanueva (owner), ramon / waiter123 (Waiter, employee #$ramon)\n";
