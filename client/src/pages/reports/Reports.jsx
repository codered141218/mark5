import React, { useMemo, useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { api } from '../../api';
import { useAuth } from '../../auth';
import { fmtDate, money, peso, ITEM_TYPE_SHORT } from '../../format';
import { PageHeader, Card, Badge, Select, useApi, ErrorBox, Loading, Modal, Button, Stat } from '../../components/ui';
import DataTable from '../../components/DataTable';
import DateRange, { useDateRange, rangeLabel } from '../../components/DateRange';
import { IncomeStatement, BalanceSheet } from './Statements';
import { ReadingReport, PrintJob } from '../pos/Print';

// Column helpers
const M = (key, label, total = true) => ({ key, label, type: 'money', total });
const Q = (key, label, total = false) => ({ key, label, type: 'qty', total });
const N = (key, label, total = true) => ({ key, label, type: 'number', total });
const T = (key, label) => ({ key, label });
const D = (key = 'date', label = 'Date') => ({ key, label, type: 'date' });
const S = (key = 'status', label = 'Status') => ({ key, label, render: (r) => r[key] ? <Badge>{r[key]}</Badge> : '' });
const P = (key, label) => ({ key, label, type: 'percent' });

const SOURCE = {
  manual: 'Manual', pos_sale: 'POS sale', pos_eod: 'POS EOD', inv_receive: 'Stock in', inv_issue: 'Issuance', inv_waste: 'Wastage', inv_count: 'Count',
  petty_cash: 'Petty cash', bank: 'Bank', bank_opening: 'Bank opening', ap_bill: 'AP bill', ap_payment: 'AP payment', ar_invoice: 'AR invoice',
  ar_receipt: 'AR collection', cash_advance: 'Cash advance', ca_repayment: 'CA repayment',
};
const SRC = { key: 'source', label: 'Source', render: (r) => SOURCE[r.source] || r.source || '' };
const MOVE = { RECEIVE: 'Stock in', SALE: 'Sale', ISSUE: 'Issuance', WASTE: 'Wastage', COUNT: 'Count adj.', BEGINNING: 'Beginning' };

/**
 * Report catalog. params: 'range' (default) | 'asof' | 'none'; extra: item | account | bank | category
 */
const REPORTS = {
  sales: {
    title: 'Sales Reports',
    perms: ['reports.sales'],
    list: [
      { key: 'daily', label: 'Sales summary by date', url: '/reports/sales/daily', columns: [D(), N('receipts', 'Receipts'), N('pax', 'Guests'), M('gross', 'Gross sales'), M('discounts', 'Discounts'), M('vatable_sales', 'VATable'), M('vat_exempt', 'VAT-exempt'), M('vat', 'VAT'), M('service_charge', 'Svc charge'), M('net_sales', 'Net sales'), M('cogs', 'COGS'), M('gross_profit', 'Gross profit')] },
      { key: 'items', label: 'Sales by item', url: '/reports/sales/items', columns: [T('sku', 'SKU'), T('item', 'Item'), T('category', 'Category'), Q('qty', 'Qty sold', true), M('gross', 'Sales'), M('avg_price', 'Avg price', false), M('unit_cost', 'Unit cost', false), M('total_cost', 'Est. cost'), P('share_pct', '% of sales')] },
      { key: 'categories', label: 'Sales by category', url: '/reports/sales/categories', columns: [T('category', 'Category'), N('receipts', 'Receipts', false), Q('qty', 'Qty sold', true), M('gross', 'Sales'), P('share_pct', '% of sales')] },
      { key: 'receipts', label: 'Sales by receipt', url: '/reports/sales/receipts', extra: ['status'], columns: [D(), T('receipt_no', 'Receipt'), S(), T('order_type', 'Type'), T('table_name', 'Table'), T('customer_name', 'Customer'), N('pax', 'Pax'), M('gross', 'Gross'), T('discount_type', 'Disc. type'), M('discount', 'Discount'), M('vat', 'VAT'), M('service_charge', 'Svc'), M('total', 'Total'), T('payment', 'Payment'), T('cashier', 'Cashier'), T('void_reason', 'Void reason')] },
      { key: 'payments', label: 'Sales by payment method', url: '/reports/sales/payments', columns: [T('method', 'Method'), N('transactions', 'Transactions'), M('amount', 'Amount')] },
      { key: 'hourly', label: 'Sales by hour', url: '/reports/sales/hourly', columns: [T('hour', 'Hour'), N('receipts', 'Receipts'), N('pax', 'Guests'), M('net_sales', 'Net sales'), M('avg_ticket', 'Avg ticket', false)] },
      { key: 'cashiers', label: 'Sales by cashier', url: '/reports/sales/cashiers', columns: [T('cashier', 'Cashier'), N('receipts', 'Receipts'), M('net_sales', 'Net sales'), M('discounts', 'Discounts'), N('voids_authorized', 'Voids authorized')] },
      { key: 'eod', label: 'End of day / Z-readings', url: '/reports/sales/eod', drill: 'eod', columns: [D('business_date', 'Business date'), S(), T('opened_by', 'Opened by'), M('opening_cash', 'Beginning cash'), N('receipts', 'Receipts'), M('net_sales', 'Net sales'), M('expected_cash', 'Expected cash'), M('counted_cash', 'Counted'), { ...M('variance', 'Over/(Short)'), render: (r) => r.variance == null ? '' : <span className={r.variance < 0 ? 'text-red bold' : r.variance > 0 ? 'text-amber bold' : ''}>{money(r.variance)}</span> }, T('closed_by', 'Closed by'), { key: 'closed_at', label: 'Closed at', type: 'datetime' }] },
      { key: 'voids', label: 'Voids report', url: '/reports/sales/voids', columns: [D(), T('kind', 'Kind'), T('ref', 'Receipt/ticket'), T('item', 'Item'), Q('qty', 'Qty'), M('amount', 'Amount'), T('reason', 'Reason'), { key: 'voided_at', label: 'Voided at', type: 'datetime' }, T('authorized_by', 'Authorized by')] },
      { key: 'discounts', label: 'Discounts / SC & PWD sales book', url: '/reports/sales/discounts', columns: [D(), T('receipt_no', 'Receipt'), T('discount_type', 'Type'), T('names', 'Name(s)'), T('id_numbers', 'OSCA/PWD ID'), N('sc_count', 'Qualified', false), N('pax', 'Pax', false), M('gross', 'Gross'), M('vat_exempt_sales', 'VAT-exempt sales'), M('discount', 'Discount'), M('net', 'Net')] },
    ],
  },
  inventory: {
    title: 'Inventory Reports',
    perms: ['reports.inventory'],
    list: [
      { key: 'onhand', label: 'Stock on hand & valuation', url: '/reports/inventory/onhand', params: 'none', extra: ['category'], columns: [T('sku', 'SKU'), T('item', 'Item'), T('category', 'Category'), { key: 'type', label: 'Type', render: (r) => ITEM_TYPE_SHORT[r.type] }, T('uom', 'Unit'), Q('on_hand', 'On hand'), M('avg_cost', 'Avg cost', false), M('last_cost', 'Last cost', false), M('value', 'Value'), Q('reorder_point', 'Reorder pt.'), S()] },
      { key: 'reorder', label: 'Reorder / purchase suggestion', url: '/reports/inventory/reorder', params: 'none', columns: [T('sku', 'SKU'), T('item', 'Item'), T('category', 'Category'), T('uom', 'Unit'), Q('on_hand', 'On hand'), Q('reorder_point', 'Reorder pt.'), Q('reorder_qty', 'Reorder qty'), Q('suggested_order', 'Suggested order'), M('last_cost', 'Last cost', false), M('est_cost', 'Est. cost')] },
      { key: 'movement', label: 'Inventory movement summary', url: '/reports/inventory/movement', columns: [T('sku', 'SKU'), T('item', 'Item'), T('uom', 'Unit'), Q('beginning', 'Beginning'), Q('received', 'Received'), Q('sold', 'Used in sales'), Q('issued', 'Issued'), Q('wasted', 'Wasted'), Q('count_adj', 'Count adj.'), Q('ending', 'Ending'), M('avg_cost', 'Avg cost', false), M('ending_value', 'Ending value')] },
      { key: 'stockcard', label: 'Stock card (per item)', url: '/reports/inventory/stockcard', extra: ['item'], columns: [D(), { key: 'type', label: 'Type', render: (r) => MOVE[String(r.type).replace('_VOID', '')] ? `${MOVE[String(r.type).replace('_VOID', '')]}${String(r.type).endsWith('_VOID') ? ' (void)' : ''}` : r.type }, T('ref_no', 'Reference'), T('notes', 'Notes'), Q('qty_in', 'In', true), Q('qty_out', 'Out', true), Q('balance', 'Balance'), M('unit_cost', 'Unit cost', false), M('total_cost', 'Value', false), T('user', 'User')] },
      { key: 'receiving', label: 'Receiving / stock-in report', url: '/reports/inventory/receiving', columns: [D(), T('doc_no', 'Doc no.'), T('supplier', 'Supplier'), T('invoice_no', 'Invoice/DR'), T('payment_mode', 'Payment'), T('item', 'Item'), Q('qty', 'Qty'), T('uom', 'Unit'), Q('base_qty', 'Base qty'), T('base_uom', 'Base unit'), M('unit_cost', 'Unit cost', false), M('amount', 'Amount'), T('prepared_by', 'Prepared by')] },
      { key: 'issuance', label: 'Stock issuance report', url: '/reports/inventory/issuance', columns: [D(), T('doc_no', 'Doc no.'), T('issued_to', 'Issued to'), T('expense_account', 'Charged to'), T('reason', 'Reason'), T('item', 'Item'), Q('qty', 'Qty'), T('uom', 'Unit'), M('unit_cost', 'Unit cost', false), M('amount', 'Cost')] },
      { key: 'wastage', label: 'Spoilage & wastage report', url: '/reports/inventory/wastage', columns: [D(), T('doc_no', 'Doc no.'), T('reason', 'Reason'), T('item', 'Item'), Q('qty', 'Qty'), T('uom', 'Unit'), M('unit_cost', 'Unit cost', false), M('amount', 'Cost')] },
      { key: 'counts', label: 'Count variance report', url: '/reports/inventory/counts', columns: [D(), T('doc_no', 'Count no.'), T('sku', 'SKU'), T('item', 'Item'), T('uom', 'Unit'), Q('system_qty', 'System'), Q('counted_qty', 'Actual'), Q('variance', 'Variance'), M('unit_cost', 'Unit cost', false), M('variance_value', 'Variance value')] },
      { key: 'usage', label: 'Ingredient usage (theoretical vs. shortage)', url: '/reports/inventory/usage', columns: [T('sku', 'SKU'), T('item', 'Item'), T('uom', 'Unit'), Q('sold_usage', 'Used per recipes'), M('sold_cost', 'Cost of sales'), Q('wasted', 'Wasted'), Q('count_shortage', 'Count shortage'), M('shortage_cost', 'Shortage cost')] },
      { key: 'recipe', label: 'Menu / recipe costing (food cost %)', url: '/reports/inventory/recipe-costing', params: 'none', columns: [T('sku', 'SKU'), T('item', 'Item'), T('category', 'Category'), { key: 'type', label: 'Type', render: (r) => ITEM_TYPE_SHORT[r.type] }, M('price', 'Selling price', false), M('price_net_of_vat', 'Net of VAT', false), M('cost', 'Recipe cost', false), M('margin', 'Margin', false), { key: 'food_cost_pct', label: 'Food cost %', type: 'percent', render: (r) => r.food_cost_pct == null ? '' : <span className={r.food_cost_pct > 40 ? 'text-red bold' : r.food_cost_pct > 35 ? 'text-amber' : 'text-green'}>{Number(r.food_cost_pct).toFixed(1)}%</span> }] },
    ],
  },
  finance: {
    title: 'Financial Reports',
    perms: ['reports.finance'],
    list: [
      { key: 'is', label: 'Income statement (P&L)', url: '/reports/finance/income-statement', custom: 'is' },
      { key: 'bs', label: 'Balance sheet', url: '/reports/finance/balance-sheet', params: 'asof', custom: 'bs' },
      { key: 'tb', label: 'Trial balance', url: '/reports/finance/trial-balance', columns: [T('code', 'Code'), T('account', 'Account'), T('type', 'Type'), M('opening', 'Opening', false), M('period_debit', 'Debit'), M('period_credit', 'Credit'), M('ending_debit', 'Ending Dr'), M('ending_credit', 'Ending Cr')] },
      { key: 'gl', label: 'General ledger (per account)', url: '/reports/finance/general-ledger', extra: ['account'], columns: [D(), T('entry_no', 'Entry'), SRC, T('ref_no', 'Ref'), T('description', 'Description'), M('debit', 'Debit'), M('credit', 'Credit'), M('balance', 'Balance', false)] },
      { key: 'journal', label: 'General journal', url: '/reports/finance/journal', columns: [D(), T('entry_no', 'Entry'), SRC, T('ref_no', 'Ref'), T('memo', 'Memo'), T('code', 'Code'), T('account', 'Account'), M('debit', 'Debit'), M('credit', 'Credit'), T('line_memo', 'Line memo')] },
      { key: 'bank', label: 'Bank register', url: '/reports/finance/bank-register', extra: ['bank'], columns: [D(), T('entry_no', 'Entry'), SRC, T('ref_no', 'Ref'), T('description', 'Description'), M('money_in', 'Money in'), M('money_out', 'Money out'), M('balance', 'Balance', false)] },
      { key: 'petty', label: 'Petty cash report', url: '/reports/petty-cash', custom: 'petty' },
      { key: 'ap', label: 'Accounts payable aging', url: '/reports/finance/ap-aging', params: 'asof', columns: [T('party', 'Supplier'), M('current', 'Current'), M('d1_30', '1–30 days'), M('d31_60', '31–60'), M('d61_90', '61–90'), M('over_90', 'Over 90'), M('total', 'Total')] },
      { key: 'ar', label: 'Accounts receivable aging', url: '/reports/finance/ar-aging', params: 'asof', columns: [T('party', 'Customer'), M('current', 'Current'), M('d1_30', '1–30 days'), M('d31_60', '31–60'), M('d61_90', '61–90'), M('over_90', 'Over 90'), M('total', 'Total')] },
      { key: 'ca', label: 'Cash advances report', url: '/reports/finance/cash-advances', columns: [T('doc_no', 'CA no.'), D('request_date', 'Requested'), T('emp_no', 'Emp no.'), T('employee', 'Employee'), M('amount', 'Amount'), S(), T('release_method', 'Released via'), D('release_date', 'Released'), T('approved_by', 'Approved by'), M('repaid', 'Repaid'), M('balance', 'Balance'), T('reason', 'Reason')] },
    ],
  },
};

const PETTY_COLS = [D(), T('doc_no', 'PCV no.'), T('type', 'Type'), T('source', 'Source'), T('payee', 'Payee'), T('description', 'Description'), T('account', 'Account'), T('or_no', 'OR no.'),
  { ...M('amount', 'Amount', false), render: (r) => <span className={r.status === 'void' ? 'muted' : ''}>{r.type === 'replenish' ? '+' : '−'}{money(r.amount)}</span> }, S(), T('recorded_by', 'Recorded by')];

export default function Reports() {
  const { group } = useParams();
  const [sp, setSp] = useSearchParams();
  const { can, settings } = useAuth();
  const cfg = REPORTS[group];
  const visible = cfg ? cfg.list.filter((r) => r.key !== 'petty' || can('reports.finance', 'pettycash.view')) : [];
  const repKey = sp.get('report') || (visible[0] && visible[0].key);
  const rep = visible.find((r) => r.key === repKey) || visible[0];
  const [range, setRange] = useDateRange('month');
  const [extra, setExtra] = useState({ item_id: sp.get('item_id') || '', account_id: sp.get('account_id') || '', bank_account_id: sp.get('bank_account_id') || '', category_id: '', status: '' });

  if (!cfg) return <div className="empty">Unknown report group.</div>;
  if (!can(...cfg.perms)) return <div className="alert alert-warn">You do not have permission to view these reports.</div>;

  const choose = (k) => setSp({ report: k });
  const mode = rep.params || 'range';
  const params = { ...(mode === 'range' ? range : mode === 'asof' ? { to: range.to } : {}) };
  for (const e of rep.extra || []) {
    const k = { item: 'item_id', account: 'account_id', bank: 'bank_account_id', category: 'category_id', status: 'status' }[e];
    if (extra[k]) params[k] = extra[k];
  }
  const needsPick = (rep.extra || []).some((e) => ['item', 'account', 'bank'].includes(e) && !params[{ item: 'item_id', account: 'account_id', bank: 'bank_account_id' }[e]]);
  const subtitle = mode === 'range' ? rangeLabel(range) : mode === 'asof' ? `As of ${fmtDate(range.to)}` : `As of today`;

  return (
    <div>
      <PageHeader title={cfg.title} subtitle={rep.label}
        actions={mode !== 'none' && <DateRange value={range} onChange={setRange} single={mode === 'asof'} />} />
      <div className="row gap wrap mb" style={{ alignItems: 'flex-end' }}>
        <div style={{ minWidth: 280 }}>
          <Select value={rep.key} onChange={choose} options={visible.map((r) => ({ value: r.key, label: r.label }))} />
        </div>
        <ExtraFilters rep={rep} extra={extra} setExtra={setExtra} />
      </div>
      {needsPick ? <Card><div className="empty">Select the {(rep.extra || []).find((e) => ['item', 'account', 'bank'].includes(e))} to view this report.</div></Card>
        : <ReportBody key={rep.key} rep={rep} params={params} subtitle={subtitle} settings={settings} />}
    </div>
  );
}

function ExtraFilters({ rep, extra, setExtra }) {
  const ex = rep.extra || [];
  const items = useApi(() => (ex.includes('item') ? api.get('/inventory/items', { stocked: '1', active: 'all' }) : Promise.resolve(null)), [ex.includes('item')]);
  const accounts = useApi(() => (ex.includes('account') ? api.get('/finance/accounts') : Promise.resolve(null)), [ex.includes('account')]);
  const banks = useApi(() => (ex.includes('bank') ? api.get('/finance/banks') : Promise.resolve(null)), [ex.includes('bank')]);
  const cats = useApi(() => (ex.includes('category') ? api.get('/inventory/categories') : Promise.resolve(null)), [ex.includes('category')]);
  const set = (k) => (v) => setExtra({ ...extra, [k]: v });
  return (
    <>
      {ex.includes('item') && <div style={{ minWidth: 260 }}><Select value={extra.item_id} onChange={set('item_id')} placeholder="Select item…" options={(items.data || []).map((i) => ({ value: i.id, label: `${i.name} (${i.uom})` }))} /></div>}
      {ex.includes('account') && <div style={{ minWidth: 300 }}><Select value={extra.account_id} onChange={set('account_id')} placeholder="Select account…" options={(accounts.data || []).map((a) => ({ value: a.id, label: `${a.code} · ${a.name}` }))} /></div>}
      {ex.includes('bank') && <div style={{ minWidth: 260 }}><Select value={extra.bank_account_id} onChange={set('bank_account_id')} placeholder="Select bank…" options={(banks.data || []).map((b) => ({ value: b.id, label: `${b.bank_name} ${b.account_no || ''}` }))} /></div>}
      {ex.includes('category') && <div style={{ minWidth: 200 }}><Select value={extra.category_id} onChange={set('category_id')} placeholder="All categories" options={(cats.data || []).filter((c) => c.kind !== 'menu').map((c) => ({ value: c.id, label: c.name }))} /></div>}
      {ex.includes('status') && <div style={{ minWidth: 160 }}><Select value={extra.status} onChange={set('status')} placeholder="Paid & void" options={[{ value: 'paid', label: 'Paid only' }, { value: 'void', label: 'Void only' }]} /></div>}
    </>
  );
}

function ReportBody({ rep, params, subtitle, settings }) {
  const key = JSON.stringify(params);
  const { data, loading, error } = useApi(() => api.get(rep.url, params), [rep.url, key]);
  const [drill, setDrill] = useState(null);
  const business = settings.business_name || '';
  const fname = `${rep.key}_${params.from || ''}_${params.to || ''}`.replace(/_+$/, '');

  if (error) return <ErrorBox error={error} />;
  if (loading && !data) return <Card><Loading /></Card>;
  if (!data) return null;
  if (rep.custom === 'is') return <Card pad={false}><IncomeStatement data={data} business={business} /></Card>;
  if (rep.custom === 'bs') return <Card pad={false}><BalanceSheet data={data} business={business} /></Card>;
  if (rep.custom === 'petty') return <PettyReport data={data} subtitle={subtitle} fname={fname} />;

  return (
    <>
      <Card pad={false}>
        <DataTable columns={rep.columns} rows={data} loading={loading} exportName={fname} exportTitle={rep.label} exportSubtitle={subtitle}
          onRowClick={rep.drill === 'eod' ? (r) => setDrill(r) : undefined} />
      </Card>
      {drill && <EodModal session={drill} settings={settings} onClose={() => setDrill(null)} />}
    </>
  );
}

function PettyReport({ data, subtitle, fname }) {
  const posted = data.rows.filter((r) => r.status === 'posted');
  const spent = posted.filter((r) => r.type === 'expense').reduce((s, r) => s + r.amount, 0);
  const added = posted.filter((r) => r.type === 'replenish').reduce((s, r) => s + r.amount, 0);
  return (
    <div className="stack">
      <div className="stats">
        <Stat label="Fund beginning balance" value={peso(data.beginning)} />
        <Stat label="Replenishments" value={peso(added)} />
        <Stat label="Expenses (all sources)" value={peso(spent)} />
        <Stat tone="brand" label="Fund ending balance" value={peso(data.ending)} sub="Drawer payouts do not affect the fund" />
      </div>
      <div className="grid-2" style={{ gridTemplateColumns: '2fr 1fr' }}>
        <Card pad={false}><DataTable columns={PETTY_COLS} rows={data.rows} exportName={fname} exportTitle="Petty cash report" exportSubtitle={subtitle} /></Card>
        <Card pad={false} title="Expenses by account">
          <DataTable searchable={false} columns={[T('account', 'Account'), N('cnt', 'Count'), M('amount', 'Amount')]} rows={data.by_account} exportName={`${fname}_by-account`} exportTitle="Petty cash by account" exportSubtitle={subtitle} />
        </Card>
      </div>
    </div>
  );
}

function EodModal({ session, settings, onClose }) {
  const rep = useApi(() => api.get(`/pos/sessions/${session.id}`), [session.id]);
  const [printing, setPrinting] = useState(false);
  const business = useMemo(() => ({ name: settings.business_name, address: settings.business_address, tin: settings.business_tin, phone: settings.business_phone }), [settings]);
  const tax = { vatRegistered: settings.vat_registered === '1', vatRate: Number(settings.vat_rate || 12) / 100 };
  const z = session.status === 'closed';
  return (
    <Modal title={`${z ? 'Z' : 'X'}-Reading · ${fmtDate(session.business_date)}`} onClose={onClose} width={420}
      footer={<><Button onClick={onClose}>Close</Button><Button variant="primary" disabled={!rep.data} onClick={() => setPrinting(true)}>Print</Button></>}>
      {rep.loading && <Loading />}
      <ErrorBox error={rep.error} />
      {rep.data && <ReadingReport report={rep.data} business={business} tax={tax} z={z} />}
      {printing && rep.data && <PrintJob onDone={() => setPrinting(false)}><ReadingReport report={rep.data} business={business} tax={tax} z={z} /></PrintJob>}
    </Modal>
  );
}
