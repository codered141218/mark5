<?php
namespace App\Controllers\Finance;

use App\Core\Request;
use App\Core\Response;
use App\Services\Finance\Payables;

/** Accounts payable pages: supplier bills, payments and aging. */
class PayableController extends PartyDocController
{
    protected string $service = Payables::class;

    protected function cfg(): array
    {
        return [
            'title' => 'Accounts Payable', 'base' => '/finance/payables', 'export' => 'payables',
            'subtitle' => 'Bills from suppliers and their payments. Deliveries received on credit automatically create bills here. Every bill and payment is posted to the general ledger.',
            'doc' => 'Bill', 'docLower' => 'bill', 'docs' => 'bills', 'no' => 'bill_no', 'date' => 'bill_date',
            'party' => 'Supplier', 'partyTable' => 'suppliers', 'partyId' => 'supplier_id', 'partyName' => 'supplier_name',
            'paidLabel' => 'Paid', 'payVerb' => 'Pay', 'payAction' => 'pay', 'payDate' => 'pay_date', 'payments' => 'payments',
            'paymentPath' => 'payments', 'paymentWord' => 'payment', 'methods' => Payables::METHODS, 'methodLabel' => 'Paid from',
            'referenceLabel' => 'Reference / check no',
            'account' => 'expense_account_id', 'accountLabel' => 'Expense / asset account', 'accountTypes' => ['expense', 'asset'], 'defaultAccount' => null,
            'refLabel' => 'Supplier invoice / ref no', 'autoSource' => 'inv_receive', 'autoLabel' => 'From delivery',
            'autoNote' => 'This bill came from a delivery receipt. To void it, void the delivery instead.',
        ];
    }

    public function store(Request $req): Response
    {
        $id = Payables::create($req->all());
        flash('success', 'Bill recorded.');
        return redirect('/finance/payables/' . $id);
    }

    public function pay(Request $req, string $id): Response
    {
        Payables::pay((int) $id, $req->all());
        flash('success', 'Payment recorded.');
        return redirect('/finance/payables/' . $id);
    }

    public function void(Request $req, string $id): Response
    {
        Payables::void((int) $id, $req->input('reason'));
        flash('success', 'Bill voided.');
        return redirect('/finance/payables/' . $id);
    }

    public function voidPayment(Request $req, string $id): Response
    {
        Payables::voidPayment((int) $id, $req->input('reason'));
        flash('success', 'Payment voided.');
        return back();
    }
}
