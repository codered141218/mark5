import React, { useMemo, useState } from 'react';
import { api } from '../../api';
import { peso, today } from '../../format';
import {
  Badge, Button, Card, Checkbox, Empty, ErrorBox, Field, Input, Loading, Modal, NumberInput, PageHeader, Select, Textarea, useApi, useDialog, useToast,
} from '../../components/ui';
import DataTable from '../../components/DataTable';
import DateRange, { useDateRange, rangeLabel } from '../../components/DateRange';
import { AccountPicker, bankLabel, bankOptions, confirmVoid, useForm, voidRow } from './shared';

const PH_BANKS = ['BDO', 'BPI', 'Metrobank', 'Landbank', 'PNB', 'UnionBank', 'Security Bank', 'China Bank', 'RCBC', 'EastWest', 'PSBank', 'GCash', 'Maya', 'GoTyme', 'SeaBank'];
const ACCT_TYPES = [{ value: 'savings', label: 'Savings' }, { value: 'checking', label: 'Checking / current' }, { value: 'e-wallet', label: 'E-wallet' }, { value: 'time_deposit', label: 'Time deposit' }];
const TXN = {
  deposit: { title: 'Money in (deposit)', counter: 'Source of money', hint: 'Where did the money come from?' },
  withdrawal: { title: 'Money out (withdrawal / payment)', counter: 'Used for', hint: 'What was the money used for?' },
  transfer: { title: 'Transfer between banks' },
};
// system_keys or codes suggested first in the counter-account picker
const SUGGEST = {
  deposit: ['cash_on_hand', 'card_clearing', 'ewallet_clearing', 'ar', 'capital', '2500', 'other_income'],
  withdrawal: ['ap', 'salaries_payable', '6000', '6100', '6200', 'petty_cash', 'cash_on_hand', '3100', '2500', 'bank_charges'],
};
const TYPE_LABEL = { deposit: 'Money in', withdrawal: 'Money out', transfer: 'Transfer' };

export default function Banks() {
  const toast = useToast();
  const dialog = useDialog();
  const [range, setRange] = useDateRange();
  const [bankId, setBankId] = useState('');
  const [editBank, setEditBank] = useState(null);
  const [txnType, setTxnType] = useState(null);
  const banks = useApi(() => api.get('/finance/banks'), []);
  const txns = useApi(() => api.get('/finance/bank-txns', { ...range, bank_account_id: bankId }), [range.from, range.to, bankId]);

  const rows = useMemo(() => (txns.data || []).map((t) => {
    const incoming = t.txn_type === 'deposit' || (t.txn_type === 'transfer' && bankId && String(t.transfer_bank_id) === String(bankId));
    return {
      ...t,
      bank: t.txn_type === 'transfer' ? `${t.bank_name} → ${t.transfer_bank_name}` : t.bank_name,
      detail: [t.txn_type === 'transfer' ? 'Bank transfer' : t.counter_account_name, t.description].filter(Boolean).join(' — '),
      money_in: incoming ? t.amount : null,
      money_out: incoming ? null : t.amount,
    };
  }), [txns.data, bankId]);

  const reloadAll = () => { banks.reload(); txns.reload(); };
  const voidTxn = (t) => confirmVoid(dialog, toast, {
    title: `Void ${t.doc_no}`, message: `Void this ${TYPE_LABEL[t.txn_type].toLowerCase()} of ${peso(t.amount)}? The journal entry will be reversed.`,
    run: (reason) => api.post(`/finance/bank-txns/${t.id}/void`, { reason }),
  }).then((ok) => ok && reloadAll());

  const posted = (k) => (rs) => rs.reduce((s, r) => s + (r.status === 'void' ? 0 : Number(r[k]) || 0), 0);
  const columns = [
    { key: 'txn_date', label: 'Date', type: 'date' },
    { key: 'doc_no', label: 'Doc no', render: (r) => <span className="bold nowrap">{r.doc_no}</span> },
    { key: 'bank', label: 'Bank' },
    { key: 'txn_type', label: 'Type', render: (r) => <Badge color={r.txn_type === 'deposit' ? 'green' : r.txn_type === 'withdrawal' ? 'amber' : 'blue'}>{TYPE_LABEL[r.txn_type]}</Badge>, exportValue: (r) => TYPE_LABEL[r.txn_type] },
    { key: 'detail', label: 'Description / counter account' },
    { key: 'reference', label: 'Reference' },
    { key: 'money_in', label: 'Money in', type: 'money', total: posted('money_in'), render: (r) => (r.money_in ? <span className="text-green">{peso(r.money_in)}</span> : '') },
    { key: 'money_out', label: 'Money out', type: 'money', total: posted('money_out'), render: (r) => (r.money_out ? <span className="text-red">{peso(r.money_out)}</span> : '') },
    { key: 'status', label: 'Status', render: (r) => <Badge>{r.status}</Badge> },
    { key: '_act', label: '', noExport: true, sortable: false, render: (r) => r.status === 'posted' && <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); voidTxn(r); }}>Void</Button> },
  ];

  const list = banks.data || [];
  const total = list.filter((b) => b.active).reduce((s, b) => s + b.balance, 0);
  const selected = list.find((b) => String(b.id) === String(bankId));

  return (
    <div className="stack">
      <PageHeader
        title="Banks & E-wallets"
        subtitle="Bank and e-wallet accounts, deposits, withdrawals and transfers. Each transaction is posted to the general ledger automatically."
        actions={<>
          <Button onClick={() => setEditBank({})}>+ Add bank</Button>
          {list.length > 0 && <>
            <Button variant="success" onClick={() => setTxnType('deposit')}>↓ Money in</Button>
            <Button variant="danger" onClick={() => setTxnType('withdrawal')}>↑ Money out</Button>
            <Button onClick={() => setTxnType('transfer')} disabled={list.filter((b) => b.active).length < 2}>⇄ Transfer</Button>
          </>}
        </>}
      />
      <ErrorBox error={banks.error} />
      {banks.loading && !banks.data ? <Loading /> : !list.length ? (
        <Card><Empty>No bank accounts yet. Click “Add bank” to set up your BDO, BPI, GCash, Maya… accounts with their opening balances.</Empty></Card>
      ) : (
        <div className="stats" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))' }}>
          {list.map((b) => (
            <div key={b.id} className="stat" role="button" tabIndex={0}
              style={{ cursor: 'pointer', opacity: b.active ? 1 : 0.6, outline: String(b.id) === String(bankId) ? '2px solid var(--brand)' : undefined }}
              onClick={() => setBankId(String(b.id) === String(bankId) ? '' : String(b.id))}>
              <div className="row between">
                <span className="bold">{b.bank_name}</span>
                <span className="row gap-sm">
                  {!b.active && <Badge color="gray">inactive</Badge>}
                  {b.account_type && <Badge color="blue">{b.account_type.replace('_', ' ')}</Badge>}
                </span>
              </div>
              <div className="stat-sub">{[b.account_name, b.account_no].filter(Boolean).join(' · ') || '—'}</div>
              <div className="stat-value" style={{ color: b.balance < 0 ? 'var(--red)' : undefined }}>{peso(b.balance)}</div>
              <div className="row between mt">
                <span className="muted small">GL {b.gl_code}</span>
                <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setEditBank(b); }}>Edit</Button>
              </div>
            </div>
          ))}
          <div className="stat stat-brand">
            <div className="stat-label">Total in banks & e-wallets</div>
            <div className="stat-value">{peso(total)}</div>
            <div className="stat-sub">{list.filter((b) => b.active).length} active account(s)</div>
          </div>
        </div>
      )}

      <Card title="Bank transactions" pad={false}>
        <div className="row wrap gap pad">
          <DateRange value={range} onChange={setRange} />
          <div style={{ width: 260 }}>
            <Select value={bankId} onChange={setBankId} placeholder="All banks" options={list.map((b) => ({ value: String(b.id), label: bankLabel(b) }))} />
          </div>
        </div>
        <ErrorBox error={txns.error} />
        <DataTable columns={columns} rows={rows} loading={txns.loading} rowClass={voidRow}
          exportName="bank-transactions" exportTitle="Bank Transactions"
          exportSubtitle={`${rangeLabel(range)}${selected ? ' · ' + bankLabel(selected) : ''}`}
          emptyText="No bank transactions in this period." />
      </Card>

      {editBank && <BankModal bank={editBank} onClose={() => setEditBank(null)} onSaved={() => { setEditBank(null); reloadAll(); }} />}
      {txnType && <TxnModal type={txnType} setType={setTxnType} banks={list} defaultBank={bankId}
        onClose={() => setTxnType(null)} onSaved={() => { setTxnType(null); reloadAll(); }} />}
    </div>
  );
}

function BankModal({ bank, onClose, onSaved }) {
  const toast = useToast();
  const isNew = !bank.id;
  const [f, set] = useForm({
    bank_name: bank.bank_name || '', account_name: bank.account_name || '', account_no: bank.account_no || '',
    account_type: bank.account_type || 'savings', notes: bank.notes || '', active: bank.active === undefined ? true : !!bank.active,
    opening_balance: '', opening_date: today(),
  });
  const [busy, setBusy] = useState(false);
  const save = async () => {
    if (!f.bank_name.trim()) return toast('Bank name is required', 'error');
    setBusy(true);
    try {
      if (isNew) await api.post('/finance/banks', f);
      else await api.put(`/finance/banks/${bank.id}`, { bank_name: f.bank_name, account_name: f.account_name, account_no: f.account_no, account_type: f.account_type, notes: f.notes, active: f.active });
      toast(isNew ? 'Bank account added' : 'Bank account saved');
      onSaved();
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };
  return (
    <Modal title={isNew ? 'Add bank / e-wallet account' : `Edit ${bank.bank_name}`} onClose={onClose} width={600}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={save}>Save</Button></>}>
      <div className="form-grid">
        <Field label="Bank / wallet" hint="e.g. BDO, BPI, Metrobank, GCash, Maya">
          <Input value={f.bank_name} onChange={set('bank_name')} list="ph-banks" autoFocus={isNew} />
          <datalist id="ph-banks">{PH_BANKS.map((b) => <option key={b} value={b} />)}</datalist>
        </Field>
        <Field label="Account type"><Select value={f.account_type} onChange={set('account_type')} options={ACCT_TYPES} /></Field>
        <Field label="Account name"><Input value={f.account_name} onChange={set('account_name')} placeholder="e.g. Juan's Eatery Inc." /></Field>
        <Field label="Account / mobile no"><Input value={f.account_no} onChange={set('account_no')} /></Field>
        {isNew && <>
          <Field label="Opening balance (₱)" hint="Current balance per bank statement / app"><NumberInput value={f.opening_balance} onChange={set('opening_balance')} /></Field>
          <Field label="Balance as of"><Input type="date" value={f.opening_date} onChange={set('opening_date')} /></Field>
        </>}
        {!isNew && <Field label="Status"><Checkbox checked={f.active} onChange={set('active')} label="Active" /></Field>}
        <Field label="Notes" span={2}><Textarea value={f.notes} onChange={set('notes')} rows={2} placeholder="Branch, signatories, etc." /></Field>
      </div>
      {isNew && <p className="muted small">A “Cash in Bank” GL account is created automatically. The opening balance is posted against Opening Balance Equity.</p>}
    </Modal>
  );
}

function TxnModal({ type, setType, banks, defaultBank, onClose, onSaved }) {
  const toast = useToast();
  const accounts = useApi(() => api.get('/finance/accounts'), []);
  const active = banks.filter((b) => b.active);
  const first = defaultBank && active.some((b) => String(b.id) === String(defaultBank)) ? String(defaultBank) : String(active[0]?.id || '');
  const [f, set, setF] = useForm({
    bank_account_id: first, txn_date: today(), amount: '', counter_account_id: '', transfer_bank_id: '', reference: '', description: '', bank_charges: '',
  });
  const [busy, setBusy] = useState(false);
  const bank = banks.find((b) => String(b.id) === String(f.bank_account_id));
  const amount = Number(f.amount) || 0;
  const charges = Number(f.bank_charges) || 0;
  const isTransfer = type === 'transfer';

  const save = async () => {
    if (!f.bank_account_id) return toast('Select the bank', 'error');
    if (!(amount > 0)) return toast('Enter the amount', 'error');
    if (isTransfer && !f.transfer_bank_id) return toast('Select the destination bank', 'error');
    if (!isTransfer && !f.counter_account_id) return toast(type === 'deposit' ? 'Select the source of money' : 'Select what the money was used for', 'error');
    setBusy(true);
    try {
      await api.post('/finance/bank-txns', {
        ...f, txn_type: type, bank_charges: isTransfer ? 0 : charges,
        counter_account_id: isTransfer ? null : Number(f.counter_account_id), transfer_bank_id: isTransfer ? Number(f.transfer_bank_id) : null,
      });
      toast('Bank transaction posted');
      onSaved();
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };

  return (
    <Modal title={TXN[type].title} onClose={onClose} width={640}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={save}>Post</Button></>}>
      <div className="row gap-sm mb wrap">
        {Object.keys(TXN).map((t) => (
          <Button key={t} size="sm" variant={t === type ? 'dark' : 'default'} onClick={() => { setType(t); setF((s) => ({ ...s, counter_account_id: '' })); }}>
            {TYPE_LABEL[t]}
          </Button>
        ))}
      </div>
      <div className="form-grid">
        <Field label={isTransfer ? 'From bank' : 'Bank account'}>
          <Select value={f.bank_account_id} onChange={set('bank_account_id')} placeholder="Select bank…" options={bankOptions(banks)} />
        </Field>
        {isTransfer && (
          <Field label="To bank">
            <Select value={f.transfer_bank_id} onChange={set('transfer_bank_id')} placeholder="Select bank…"
              options={bankOptions(banks).filter((o) => String(o.value) !== String(f.bank_account_id))} />
          </Field>
        )}
        <Field label="Date"><Input type="date" value={f.txn_date} onChange={set('txn_date')} /></Field>
        <Field label="Amount (₱)"><NumberInput value={f.amount} onChange={set('amount')} min="0" /></Field>
        {!isTransfer && (
          <Field label={TXN[type].counter} hint={TXN[type].hint} span={2}>
            <AccountPicker accounts={accounts.data} value={f.counter_account_id} onChange={set('counter_account_id')}
              suggest={SUGGEST[type]} exclude={bank ? [bank.gl_account_id] : []} />
          </Field>
        )}
        <Field label="Reference" hint="Deposit slip, check no, transaction ID"><Input value={f.reference} onChange={set('reference')} /></Field>
        {!isTransfer && (
          <Field label="Bank / card charges (₱)" hint={type === 'deposit' ? 'Optional — e.g. card MDR deducted from settlement' : 'Optional — e.g. transfer fee'}>
            <NumberInput value={f.bank_charges} onChange={set('bank_charges')} min="0" />
          </Field>
        )}
        <Field label="Description" span={2}><Input value={f.description} onChange={set('description')} placeholder={type === 'deposit' ? 'e.g. Sales deposit Oct 7' : type === 'withdrawal' ? 'e.g. Rent for October' : ''} /></Field>
      </div>
      {!isTransfer && charges > 0 && amount > 0 && (
        <div className="alert alert-info mt">
          {type === 'deposit'
            ? <>Bank receives <b>{peso(amount - charges)}</b> ({peso(amount)} less {peso(charges)} charges).</>
            : <>Bank is debited <b>{peso(amount + charges)}</b> ({peso(amount)} plus {peso(charges)} charges).</>}
          {' '}Charges are posted to Bank & Card Charges.
        </div>
      )}
    </Modal>
  );
}
