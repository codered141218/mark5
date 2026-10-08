<?php
/** Finance, cash and people: banks, AP, AR, cash advances, petty cash, chart of accounts, journals, employees. */
use App\Core\DB;
use App\Services\Finance\Accounts;
use App\Services\Finance\Banks;
use App\Services\Finance\CashAdvances;
use App\Services\Finance\Employees;
use App\Services\Finance\Journals;
use App\Services\Finance\Partners;
use App\Services\Finance\Payables;
use App\Services\Finance\PettyCash;
use App\Services\Finance\Receivables;
use App\Services\Ledger;

$fin = new stdClass(); // ids shared between the tests below

/** Create a user with a role (and optional employee link / PIN) for permission tests. */
function fin_user(string $username, string $role, ?int $employeeId = null, ?string $pin = null): int
{
    return DB::insert('users', [
        'username' => $username, 'full_name' => ucfirst($username), 'password_hash' => password_hash('secret1', PASSWORD_DEFAULT),
        'pin_hash' => $pin ? password_hash($pin, PASSWORD_DEFAULT) : null, 'role_id' => (int) DB::value('SELECT id FROM roles WHERE name = ?', [$role]),
        'employee_id' => $employeeId, 'active' => 1, 'created_at' => now(),
    ]);
}

function bank_balance(int $bankId): float
{
    return Ledger::balance((int) DB::value('SELECT gl_account_id FROM bank_accounts WHERE id = ?', [$bankId]));
}

test('bank with opening balance gets its own GL account and posts against opening equity', function () use ($fin) {
    as_user('admin');
    $fin->bank = Banks::create(['bank_name' => 'BDO', 'account_no' => '001234567890', 'account_type' => 'savings', 'opening_balance' => 50000, 'opening_date' => '2026-01-01']);
    $gl = DB::one('SELECT a.* FROM accounts a JOIN bank_accounts b ON b.gl_account_id = a.id WHERE b.id = ?', [$fin->bank]);
    eq('1031', $gl['code']);
    eq('Cash in Bank - BDO 7890', $gl['name']);
    eq(50000, bank_balance($fin->bank));
    eq(-50000, acct_balance('opening_equity'));
    $fin->bank2 = Banks::create(['bank_name' => 'GCash', 'account_no' => '09171234567']);
    eq('1032', DB::value('SELECT a.code FROM accounts a JOIN bank_accounts b ON b.gl_account_id = a.id WHERE b.id = ?', [$fin->bank2]));
    Banks::update($fin->bank2, ['bank_name' => 'Maya', 'account_no' => '09179999999', 'active' => 1]);
    eq('Cash in Bank - Maya 9999', DB::value('SELECT a.name FROM accounts a JOIN bank_accounts b ON b.gl_account_id = a.id WHERE b.id = ?', [$fin->bank2]));
    assert_books_balance();
});

test('AP bill: due date from supplier terms, partial then full payment, voids', function () use ($fin) {
    $sup = (int) DB::value("SELECT id FROM suppliers WHERE name = 'Metro Meat Supply'"); // 15-day terms
    $supplies = Ledger::account('supplies');
    $fin->bill = Payables::create(['supplier_id' => $sup, 'bill_date' => '2026-01-02', 'amount' => 11324, 'expense_account_id' => $supplies, 'ref_no' => 'SI-1001']);
    $b = Payables::find($fin->bill);
    eq('2026-01-17', $b['due_date']);
    eq('open', $b['status']);
    eq(-11324, acct_balance('ap'));
    eq(11324, acct_balance('supplies'));

    throws(fn () => Payables::pay($fin->bill, ['amount' => 20000, 'method' => 'cash']), 'balance of 11,324.00');
    throws(fn () => Payables::pay($fin->bill, ['amount' => 100, 'method' => 'bank']), 'select the bank');
    Payables::pay($fin->bill, ['amount' => 5000, 'method' => 'bank', 'bank_account_id' => $fin->bank, 'pay_date' => '2026-01-10']);
    eq('partial', Payables::find($fin->bill)['status']);
    eq(45000, bank_balance($fin->bank));
    throws(fn () => Payables::void($fin->bill, 'x'), 'Void the payments first');

    $pid = Payables::pay($fin->bill, ['amount' => 6324, 'method' => 'cash']);
    eq('paid', Payables::find($fin->bill)['status']);
    eq(0, acct_balance('ap'));
    Payables::voidPayment($pid, 'wrong amount');
    $b = Payables::find($fin->bill);
    eq('partial', $b['status']);
    eq(5000, $b['paid_amount']);
    throws(fn () => Payables::voidPayment($pid, 'again'), 'already void');
    eq(6324, Partners::list('suppliers')[array_search($sup, array_column(Partners::list('suppliers'), 'id'))]['balance']);

    $fromDelivery = Payables::create(['supplier_id' => $sup, 'bill_date' => today(), 'amount' => 100, 'expense_account_id' => $supplies]);
    DB::update('ap_bills', $fromDelivery, ['source_type' => 'inv_receive']);
    throws(fn () => Payables::void($fromDelivery, 'x'), 'came from a delivery');
    DB::update('ap_bills', $fromDelivery, ['source_type' => 'manual']);
    Payables::void($fromDelivery, 'test');
    eq('void', Payables::find($fromDelivery)['status']);
    throws(fn () => Payables::create(['supplier_id' => $sup, 'bill_date' => today(), 'amount' => 0, 'expense_account_id' => $supplies]), 'greater than zero');
    throws(fn () => Payables::create(['supplier_id' => $sup, 'bill_date' => today(), 'amount' => 10]), 'Expense account id is required');
    assert_books_balance();
});

test('AP aging buckets by days past due', function () use ($fin) {
    $aging = Payables::aging('2026-03-01'); // bill due 2026-01-17 -> 43 days past due
    eq(1, count($aging));
    eq(6324, $aging[0]['d31_60']);
    eq(6324, $aging[0]['total']);
    eq(6324, Payables::aging('2026-01-10')[0]['current']);
});

test('AR invoice (default Sales) and full collection to the bank', function () use ($fin) {
    $cust = (int) DB::value('SELECT id FROM customers ORDER BY id LIMIT 1'); // 30-day terms
    $fin->inv = Receivables::create(['customer_id' => $cust, 'inv_date' => '2026-01-05', 'amount' => 12000, 'description' => 'Catering']);
    $i = Receivables::find($fin->inv);
    eq('2026-02-04', $i['due_date']);
    eq(Ledger::account('sales'), (int) $i['income_account_id']);
    eq(12000, acct_balance('ar'));
    Receivables::collect($fin->inv, ['amount' => 12000, 'method' => 'bank', 'bank_account_id' => $fin->bank]);
    eq('paid', Receivables::find($fin->inv)['status']);
    eq(0, acct_balance('ar'));
    eq(57000, bank_balance($fin->bank));
    throws(fn () => Receivables::collect($fin->inv, ['amount' => 1, 'method' => 'cash']), 'not open');
    throws(fn () => Receivables::void($fin->inv, 'x'), 'Void the collections first');

    $pos = Receivables::create(['customer_id' => $cust, 'inv_date' => today(), 'amount' => 500]);
    DB::update('ar_invoices', $pos, ['source_type' => 'pos_sale']);
    throws(fn () => Receivables::void($pos, 'x'), 'POS charge');
    DB::update('ar_invoices', $pos, ['source_type' => 'manual']);
    $rid = Receivables::collect($pos, ['amount' => 200, 'method' => 'cash']);
    eq('partial', Receivables::find($pos)['status']);
    Receivables::voidReceipt($rid, 'bounced');
    eq('open', Receivables::find($pos)['status']);
    Receivables::void($pos, 'duplicate');
    eq(0, acct_balance('ar'));
    assert_books_balance();
});

test('bank deposit with charges, withdrawal with fee, transfer and void', function () use ($fin) {
    $coh = Ledger::account('cash_on_hand');
    $cohBefore = acct_balance('cash_on_hand');
    $dep = Banks::postTxn(['bank_account_id' => $fin->bank, 'txn_type' => 'deposit', 'amount' => 1000, 'bank_charges' => 25, 'counter_account_id' => $coh, 'txn_date' => '2026-01-06']);
    eq(57975, bank_balance($fin->bank));
    eq(25, acct_balance('bank_charges'));
    eq($cohBefore - 1000, acct_balance('cash_on_hand'));
    $t = Banks::txns('2026-01-06', '2026-01-06', $fin->bank)[0];
    eq([975.0, null], Banks::moneyInOut($t, $fin->bank));

    throws(fn () => Banks::postTxn(['bank_account_id' => $fin->bank, 'txn_type' => 'deposit', 'amount' => 100, 'txn_date' => today()]), 'where the money came from');
    throws(fn () => Banks::postTxn(['bank_account_id' => $fin->bank, 'txn_type' => 'transfer', 'amount' => 100, 'transfer_bank_id' => $fin->bank, 'txn_date' => today()]), 'must differ');
    throws(fn () => Banks::postTxn(['bank_account_id' => $fin->bank, 'txn_type' => 'withdrawal', 'amount' => -5, 'counter_account_id' => $coh, 'txn_date' => today()]), 'greater than zero');

    $rent = (int) DB::value("SELECT id FROM accounts WHERE code = '6100'");
    $wd = Banks::postTxn(['bank_account_id' => $fin->bank, 'txn_type' => 'withdrawal', 'amount' => 2000, 'bank_charges' => 15, 'counter_account_id' => $rent, 'txn_date' => today()]);
    eq(57975 - 2015, bank_balance($fin->bank));
    $tr = Banks::postTxn(['bank_account_id' => $fin->bank, 'txn_type' => 'transfer', 'amount' => 500, 'transfer_bank_id' => $fin->bank2, 'txn_date' => today()]);
    eq(500, bank_balance($fin->bank2));
    Banks::voidTxn($wd, 'wrong bank');
    Banks::voidTxn($tr, 'test');
    throws(fn () => Banks::voidTxn($tr, 'again'), 'Already void');
    eq(57975, bank_balance($fin->bank));
    eq(0, bank_balance($fin->bank2));
    eq(25, acct_balance('bank_charges'));
    ok($dep > 0);
    assert_books_balance();
});

test('cash advance: request, approve from bank (Dr Advances to Employees), repay via payroll', function () use ($fin) {
    $fin->emp = (int) DB::value("SELECT id FROM employees WHERE full_name = 'Juan Dela Cruz'");
    $fin->ca = CashAdvances::request(['employee_id' => $fin->emp, 'amount' => 3000, 'reason' => 'Tuition']);
    $c = CashAdvances::find($fin->ca);
    eq('pending', $c['status']);
    ok(str_starts_with($c['doc_no'], 'CA-'));
    throws(fn () => CashAdvances::approve($fin->ca, ['release_method' => 'bank']), 'select the bank');
    CashAdvances::approve($fin->ca, ['release_method' => 'bank', 'bank_account_id' => $fin->bank]);
    $c = CashAdvances::find($fin->ca);
    eq('approved', $c['status']);
    eq(3000, $c['balance']);
    ok($c['journal_entry_id'], 'approval posts a journal entry');
    eq(3000, acct_balance('emp_advances'));
    throws(fn () => CashAdvances::approve($fin->ca, []), 'Only pending');

    throws(fn () => CashAdvances::repay($fin->ca, ['amount' => 5000, 'method' => 'payroll']), 'balance of 3,000.00');
    $rid = CashAdvances::repay($fin->ca, ['amount' => 1000, 'method' => 'payroll', 'reference' => 'Payroll Jan 1-15']);
    eq(2000, CashAdvances::find($fin->ca)['balance']);
    eq(2000, acct_balance('emp_advances'));
    eq(1000, acct_balance('salaries_payable'), 'payroll deduction debits Salaries Payable');
    eq(50000 - 5000 + 12000 + 975 - 3000, bank_balance($fin->bank));
    eq(2000, Employees::list()[array_search($fin->emp, array_column(Employees::list(), 'id'))]['ca_balance']);

    $rid2 = CashAdvances::repay($fin->ca, ['amount' => 2000, 'method' => 'cash']);
    eq('settled', CashAdvances::find($fin->ca)['status']);
    CashAdvances::voidRepayment($rid2, 'wrong');
    $c = CashAdvances::find($fin->ca);
    eq('approved', $c['status']);
    eq(2000, $c['balance']);
    ok($rid > 0);
    assert_books_balance();
});

test('cash advance permissions: cashier requests only for self, cannot approve; reject and cancel', function () use ($fin) {
    $maria = (int) DB::value("SELECT id FROM employees WHERE full_name = 'Maria Santos'");
    fin_user('cashier1', 'Cashier');
    as_user('cashier1');
    throws(fn () => CashAdvances::request(['employee_id' => $maria, 'amount' => 500]), 'only request a cash advance for yourself');
    DB::run("UPDATE users SET employee_id = ? WHERE username = 'cashier1'", [$maria]);
    as_user('cashier1');
    eq([$maria], array_map('intval', array_column(Employees::forRequests(), 'id')));
    $own = CashAdvances::request(['employee_id' => $maria, 'amount' => 500]);
    throws(fn () => CashAdvances::approve($own, ['release_method' => 'cash']), 'permission');
    throws(fn () => CashAdvances::repay($fin->ca, ['amount' => 1]), 'permission');
    throws(fn () => CashAdvances::find($fin->ca), 'permission'); // someone else's advance
    eq(1, count(CashAdvances::list([])));
    CashAdvances::cancel($own);
    eq('cancelled', CashAdvances::find($own)['status']);

    as_user('admin');
    $other = CashAdvances::request(['employee_id' => $maria, 'amount' => 800]);
    as_user('cashier1');
    throws(fn () => CashAdvances::cancel($other), 'only cancel your own');
    as_user('admin');
    CashAdvances::reject($other, 'Over the limit');
    eq('rejected', CashAdvances::find($other)['status']);
    eq(2000, acct_balance('emp_advances'));
    assert_books_balance();
});

test('petty cash: fund expense, replenish from cash and bank, void reverses', function () use ($fin) {
    as_user('admin');
    $lpg = (int) DB::value("SELECT id FROM accounts WHERE code = '6210'");
    $fundBefore = PettyCash::fundBalance();
    $exp = PettyCash::record(['txn_type' => 'expense', 'source' => 'fund', 'amount' => 150, 'account_id' => $lpg, 'description' => 'LPG refill', 'payee' => 'Petron']);
    eq($fundBefore - 150, PettyCash::fundBalance());
    eq(150, Ledger::balance($lpg));
    $cohBefore = acct_balance('cash_on_hand');
    PettyCash::record(['txn_type' => 'replenish', 'amount' => 1000, 'from_method' => 'cash']);
    eq($fundBefore + 850, PettyCash::fundBalance());
    eq($cohBefore - 1000, acct_balance('cash_on_hand'));
    PettyCash::record(['txn_type' => 'replenish', 'amount' => 500, 'from_method' => 'bank', 'bank_account_id' => $fin->bank]);
    eq($fundBefore + 1350, PettyCash::fundBalance());
    PettyCash::void($exp, 'duplicate');
    eq($fundBefore + 1500, PettyCash::fundBalance());
    eq(0, Ledger::balance($lpg));
    throws(fn () => PettyCash::void($exp, 'again'), 'already void');
    throws(fn () => PettyCash::record(['txn_type' => 'expense', 'amount' => 10]), 'Account id is required');
    throws(fn () => PettyCash::record(['txn_type' => 'expense', 'amount' => 0, 'account_id' => $lpg]), 'greater than zero');
    throws(fn () => PettyCash::record(['txn_type' => 'expense', 'source' => 'drawer', 'amount' => 10, 'account_id' => $lpg]), 'Open the business day');
    eq(3, count(PettyCash::list('2000-01-01', '2100-01-01')));
    assert_books_balance();
});

test('petty cash drawer payouts: cashier rules, manager PIN, closed day', function () {
    $lpg = (int) DB::value("SELECT id FROM accounts WHERE code = '6210'");
    $session = DB::insert('cash_sessions', ['business_date' => '2026-01-07', 'status' => 'open', 'opened_at' => now(), 'opening_cash' => 2000]);
    as_user('cashier1');
    throws(fn () => PettyCash::record(['txn_type' => 'replenish', 'amount' => 100, 'from_method' => 'cash']), 'permission');
    $cohBefore = acct_balance('cash_on_hand');
    $own = PettyCash::record(['txn_type' => 'expense', 'source' => 'drawer', 'amount' => 150, 'account_id' => $lpg, 'description' => 'Ice']);
    $row = DB::one('SELECT * FROM petty_cash_txns WHERE id = ?', [$own]);
    eq('2026-01-07', $row['txn_date'], 'drawer payout uses the business date');
    eq($session, (int) $row['cash_session_id']);
    eq($cohBefore - 150, acct_balance('cash_on_hand'));
    eq(1, count(PettyCash::list('2000-01-01', '2100-01-01')), 'cashier sees only own entries');
    eq(1, count(PettyCash::listForSession($session)));
    PettyCash::void($own, 'mistake'); // own payout, open day: no PIN needed
    eq($cohBefore, acct_balance('cash_on_hand'));
    eq('2026-01-07', DB::value('SELECT entry_date FROM journal_entries WHERE id = (SELECT reversed_by FROM journal_entries WHERE id = ?)', [$row['journal_entry_id']]));

    as_user('admin');
    $mgr = PettyCash::record(['txn_type' => 'expense', 'source' => 'drawer', 'amount' => 80, 'account_id' => $lpg]);
    $mgr2 = PettyCash::record(['txn_type' => 'expense', 'source' => 'drawer', 'amount' => 90, 'account_id' => $lpg]);
    as_user('cashier1');
    throws(fn () => PettyCash::void($mgr, 'x'), 'Manager authorization required');
    throws(fn () => PettyCash::void($mgr, 'x', '9999'), 'Invalid manager PIN');
    PettyCash::void($mgr, 'approved by manager', '1234');
    eq('void', DB::value('SELECT status FROM petty_cash_txns WHERE id = ?', [$mgr]));

    DB::update('cash_sessions', $session, ['status' => 'closed', 'closed_at' => now()]);
    as_user('admin');
    throws(fn () => PettyCash::void($mgr2, 'late'), 'closed business day');
    assert_books_balance();
});

test('chart of accounts: create, edit, type lock, delete rules, natural balances', function () use ($fin) {
    as_user('admin');
    $id = Accounts::create(['code' => '6150', 'name' => 'Gas & Fuel', 'type' => 'expense', 'subtype' => 'opex']);
    throws(fn () => Accounts::create(['code' => '6150', 'name' => 'Dup', 'type' => 'expense']), 'already exists');
    throws(fn () => Accounts::create(['code' => '7000', 'name' => 'Bad', 'type' => 'other']), 'Invalid account type');
    Accounts::update($id, ['code' => '6150', 'name' => 'Gas & Fuel Expense', 'type' => 'asset', 'active' => 1]);
    eq('asset', DB::value('SELECT type FROM accounts WHERE id = ?', [$id]));
    $cash = Ledger::account('cash_on_hand');
    throws(fn () => Accounts::update($cash, ['code' => '1000', 'name' => 'Cash', 'type' => 'expense', 'active' => 1]), 'Cannot change the type');
    Accounts::update($cash, ['code' => '1000', 'name' => 'Cash on Hand', 'active' => 1]); // locked type field not submitted
    throws(fn () => Accounts::delete($cash), 'System accounts');
    throws(fn () => Accounts::delete((int) DB::value("SELECT id FROM accounts WHERE code = '6100'")), 'has transactions');
    $bank3 = Banks::create(['bank_name' => 'BPI']);
    throws(fn () => Accounts::delete((int) DB::value('SELECT gl_account_id FROM bank_accounts WHERE id = ?', [$bank3])), 'linked to a bank');
    Accounts::delete($id);
    eq(null, DB::value('SELECT id FROM accounts WHERE id = ?', [$id]));
    $ap = array_values(array_filter(Accounts::all(), fn ($a) => $a['system_key'] === 'opening_equity'))[0];
    eq(50000, $ap['natural_balance'], 'equity shown positive');
});

test('manual journal entries: balance check, post, void only manual entries', function () {
    $cash = Ledger::account('cash_on_hand');
    $cap = Ledger::account('capital');
    throws(fn () => Journals::postManual(['entry_date' => today(), 'lines' => [['account_id' => $cash, 'debit' => 100], ['account_id' => $cap, 'credit' => 90]]]), 'not balanced');
    throws(fn () => Journals::postManual(['entry_date' => today(), 'lines' => [['account_id' => '', 'debit' => 100], ['account_id' => $cap, 'credit' => 100]]]), 'Select an account');
    throws(fn () => Journals::postManual(['entry_date' => today(), 'lines' => [['account_id' => $cash, 'debit' => '']]]), 'no amounts');
    $before = acct_balance('cash_on_hand');
    $id = Journals::postManual(['entry_date' => today(), 'memo' => 'Owner investment', 'ref_no' => 'DS-1', 'lines' => [
        ['account_id' => $cash, 'debit' => '10000', 'credit' => ''], ['account_id' => $cap, 'debit' => '', 'credit' => '10000', 'memo' => 'Capital'],
    ]]);
    $je = Journals::find($id);
    eq(2, count($je['lines']));
    eq('manual', $je['source_type']);
    eq($before + 10000, acct_balance('cash_on_hand'));
    $rev = Journals::void($id, 'wrong date');
    eq('void', Journals::find($id)['status']);
    ok(str_contains(Journals::find($rev)['memo'], 'wrong date'));
    throws(fn () => Journals::void($rev, 'x'), 'itself a reversal');
    throws(fn () => Journals::void($id, 'x'), 'already reversed');
    $bankJe = (int) DB::value("SELECT id FROM journal_entries WHERE source_type = 'bank' LIMIT 1");
    throws(fn () => Journals::void($bankJe, 'x'), 'source document');
    eq($before, acct_balance('cash_on_hand'));
    ok(count(Journals::list('2000-01-01', '2100-01-01', 'manual')) >= 2);
    assert_books_balance();
});

test('employees and partners: auto employee no, deactivate', function () {
    $id = Employees::save(['full_name' => 'Pedro Penduko', 'position' => 'Server']);
    eq('EMP-003', DB::value('SELECT emp_no FROM employees WHERE id = ?', [$id]));
    throws(fn () => Employees::save(['full_name' => 'Dup', 'emp_no' => 'EMP-003']), 'already exists');
    Employees::save(['id' => $id, 'full_name' => 'Pedro Penduko', 'emp_no' => 'EMP-003', 'active' => 0]);
    ok(!in_array($id, array_column(Employees::list(), 'id')));
    ok(in_array($id, array_column(Employees::list(true), 'id')));
    throws(fn () => Employees::save(['full_name' => '']), 'Full name is required');

    $s = Partners::save('suppliers', ['name' => 'Ice Plant', 'terms_days' => '7']);
    Partners::setActive('suppliers', $s, false);
    ok(!isset(Partners::options('suppliers')[$s]));
    Partners::setActive('suppliers', $s, true);
    ok(isset(Partners::options('suppliers')[$s]));
    $c = Partners::save('customers', ['name' => 'XYZ Corp', 'credit_limit' => '10000']);
    eq(10000, DB::value('SELECT credit_limit FROM customers WHERE id = ?', [$c]));
});
