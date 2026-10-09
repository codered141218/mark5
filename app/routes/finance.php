<?php
/** Finance, cash and people routes. Third argument = required permission(s) (any of them). */
use App\Controllers\Finance\AccountController;
use App\Controllers\Finance\BankController;
use App\Controllers\Finance\CashAdvanceController;
use App\Controllers\Finance\EmployeeController;
use App\Controllers\Finance\JournalController;
use App\Controllers\Finance\PartnerController;
use App\Controllers\Finance\PayableController;
use App\Controllers\Finance\PettyCashController;
use App\Controllers\Finance\ReceivableController;

// Petty cash (cashiers with pos.petty_cash see and void their own drawer payouts)
$router->get('/petty-cash', [PettyCashController::class, 'index'], ['pettycash.view', 'pettycash.manage', 'pos.petty_cash']);
$router->post('/petty-cash', [PettyCashController::class, 'store'], ['pettycash.manage', 'pos.petty_cash']);
$router->post('/petty-cash/{id}/void', [PettyCashController::class, 'void'], ['pettycash.manage', 'pos.petty_cash']);

// Chart of accounts
$router->get('/finance/accounts', [AccountController::class, 'index'], ['finance.view', 'finance.accounts']);
$router->post('/finance/accounts', [AccountController::class, 'save'], 'finance.accounts');
$router->post('/finance/accounts/{id}/delete', [AccountController::class, 'delete'], 'finance.accounts');

// Journal entries
$router->get('/finance/journals', [JournalController::class, 'index'], ['finance.view', 'finance.journal']);
$router->get('/finance/journals/new', [JournalController::class, 'create'], 'finance.journal');
$router->post('/finance/journals', [JournalController::class, 'store'], 'finance.journal');
$router->get('/finance/journals/{id}', [JournalController::class, 'show'], ['finance.view', 'finance.journal']);
$router->post('/finance/journals/{id}/void', [JournalController::class, 'void'], 'finance.journal');

// Banks
$router->get('/finance/banks', [BankController::class, 'index'], ['finance.banks', 'finance.view']);
$router->post('/finance/banks', [BankController::class, 'save'], 'finance.banks');
$router->post('/finance/banks/txns', [BankController::class, 'storeTxn'], 'finance.banks');
$router->post('/finance/banks/txns/{id}/void', [BankController::class, 'voidTxn'], 'finance.banks');

// Accounts payable
$router->get('/finance/payables', [PayableController::class, 'index'], 'finance.ap');
$router->post('/finance/payables', [PayableController::class, 'store'], 'finance.ap');
$router->get('/finance/payables/{id}', [PayableController::class, 'show'], 'finance.ap');
$router->post('/finance/payables/{id}/pay', [PayableController::class, 'pay'], 'finance.ap');
$router->post('/finance/payables/{id}/void', [PayableController::class, 'void'], 'finance.ap');
$router->post('/finance/payables/payments/{id}/void', [PayableController::class, 'voidPayment'], 'finance.ap');

// Accounts receivable
$router->get('/finance/receivables', [ReceivableController::class, 'index'], 'finance.ar');
$router->post('/finance/receivables', [ReceivableController::class, 'store'], 'finance.ar');
$router->get('/finance/receivables/{id}', [ReceivableController::class, 'show'], 'finance.ar');
$router->post('/finance/receivables/{id}/collect', [ReceivableController::class, 'collect'], 'finance.ar');
$router->post('/finance/receivables/{id}/void', [ReceivableController::class, 'void'], 'finance.ar');
$router->post('/finance/receivables/receipts/{id}/void', [ReceivableController::class, 'voidReceipt'], 'finance.ar');

// Suppliers & customers ({kind} = suppliers | customers; adding is open to more roles than editing)
foreach (['suppliers' => 'finance.ap', 'customers' => 'finance.ar'] as $kind => $perm) {
    $router->get("/finance/$kind", [PartnerController::class, $kind], ['partners.manage', $perm]);
    $router->post("/finance/$kind", [PartnerController::class, 'save'], ['partners.manage', $perm, 'inventory.receive']);
    $router->post("/finance/$kind/{id}/active", [PartnerController::class, 'setActive'], 'partners.manage');
}

// Cash advances & employees
$router->get('/cash-advances', [CashAdvanceController::class, 'index'], ['ca.request', 'ca.approve', 'ca.manage']);
$router->post('/cash-advances', [CashAdvanceController::class, 'store'], 'ca.request');
$router->get('/cash-advances/{id}', [CashAdvanceController::class, 'show'], ['ca.request', 'ca.approve', 'ca.manage']);
$router->post('/cash-advances/{id}/approve', [CashAdvanceController::class, 'approve'], 'ca.approve');
$router->post('/cash-advances/{id}/reject', [CashAdvanceController::class, 'reject'], 'ca.approve');
$router->post('/cash-advances/{id}/cancel', [CashAdvanceController::class, 'cancel'], 'ca.request');
$router->post('/cash-advances/{id}/repay', [CashAdvanceController::class, 'repay'], 'ca.manage');
$router->post('/cash-advances/repayments/{id}/void', [CashAdvanceController::class, 'voidRepayment'], 'ca.manage');

$router->get('/employees', [EmployeeController::class, 'index'], 'employees.manage');
$router->post('/employees', [EmployeeController::class, 'save'], 'employees.manage');

// GL account determination (which account every automatic posting uses)
$router->get('/finance/gl-setup', [App\Controllers\Finance\GlSetupController::class, 'index'], ['finance.accounts']);
$router->post('/finance/gl-setup', [App\Controllers\Finance\GlSetupController::class, 'save'], 'finance.accounts');
