<?php
namespace App\Controllers\Finance;

use App\Core\Request;
use App\Core\Response;
use App\Services\Finance\Receivables;

/** Accounts receivable pages: customer invoices, collections and aging. */
class ReceivableController extends PartyDocController
{
    protected string $service = Receivables::class;

    protected function cfg(): array
    {
        return [
            'title' => 'Accounts Receivable', 'base' => '/finance/receivables', 'export' => 'receivables',
            'subtitle' => 'Charge accounts and invoices to customers, and their collections. POS sales settled with “Charge to account” automatically create receivables here.',
            'doc' => 'Invoice', 'docLower' => 'invoice', 'docs' => 'invoices', 'no' => 'invoice_no', 'date' => 'inv_date',
            'party' => 'Customer', 'partyTable' => 'customers', 'partyId' => 'customer_id', 'partyName' => 'customer_name',
            'paidLabel' => 'Collected', 'payVerb' => 'Collect', 'payAction' => 'collect', 'payDate' => 'rcpt_date', 'payments' => 'receipts',
            'paymentPath' => 'receipts', 'paymentWord' => 'collection', 'methods' => Receivables::METHODS, 'methodLabel' => 'Received into',
            'referenceLabel' => 'OR / reference no',
            'account' => 'income_account_id', 'accountLabel' => 'Income account', 'accountTypes' => ['income'],
            'refLabel' => 'Reference / PO no', 'autoSource' => 'pos_sale', 'autoLabel' => 'From POS charge',
            'autoNote' => 'This receivable came from a POS charge sale. To void it, void the POS receipt instead.',
        ];
    }

    public function store(Request $req): Response
    {
        $id = Receivables::create($req->all());
        flash('success', 'Invoice recorded.');
        return redirect('/finance/receivables/' . $id);
    }

    public function collect(Request $req, string $id): Response
    {
        Receivables::collect((int) $id, $req->all());
        flash('success', 'Collection recorded.');
        return redirect('/finance/receivables/' . $id);
    }

    public function void(Request $req, string $id): Response
    {
        Receivables::void((int) $id, $req->input('reason'));
        flash('success', 'Invoice voided.');
        return redirect('/finance/receivables/' . $id);
    }

    public function voidReceipt(Request $req, string $id): Response
    {
        Receivables::voidReceipt((int) $id, $req->input('reason'));
        flash('success', 'Collection voided.');
        return back();
    }
}
