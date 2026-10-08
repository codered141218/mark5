import React, { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../../api';
import { useAuth } from '../../auth';
import { peso, qty, pct, int, ITEM_TYPES, ITEM_TYPE_SHORT } from '../../format';
import DataTable from '../../components/DataTable';
import { Badge, Button, Card, Checkbox, ErrorBox, PageHeader, Select, Stat, useApi } from '../../components/ui';
import { cost4 } from './shared';

const STOCKED = ['raw', 'retail'];
const typeOptions = Object.entries(ITEM_TYPES).map(([value, label]) => ({ value, label }));

function foodCostClass(p) {
  if (p === null || p === undefined) return '';
  return p > 45 ? 'text-red bold' : p > 35 ? 'text-amber' : 'text-green';
}

export default function Items() {
  const navigate = useNavigate();
  const { can } = useAuth();
  const [type, setType] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [showInactive, setShowInactive] = useState(false);
  const [lowOnly, setLowOnly] = useState(false);

  const cats = useApi(() => api.get('/inventory/categories'), []);
  const { data, loading, error } = useApi(
    () => api.get('/inventory/items', { type, category_id: categoryId, active: showInactive ? 'all' : undefined }),
    [type, categoryId, showInactive]
  );

  const rows = useMemo(() => (data || []).map((i) => {
    const stocked = STOCKED.includes(i.item_type);
    const status = !i.active ? 'inactive' : stocked && i.stock_qty < 0 ? 'NEGATIVE' : i.low_stock ? 'REORDER' : stocked ? 'OK' : '';
    const showFc = i.sellable && ['composite', 'retail'].includes(i.item_type) && i.price > 0;
    return {
      ...i,
      type_short: ITEM_TYPE_SHORT[i.item_type] || i.item_type,
      on_hand: stocked ? i.stock_qty : null,
      reorder: stocked ? i.reorder_point : null,
      fc: showFc ? i.food_cost_pct : null,
      status,
    };
  }), [data]);
  const shown = lowOnly ? rows.filter((r) => r.status === 'REORDER' || r.status === 'NEGATIVE') : rows;

  const stats = useMemo(() => {
    const stocked = rows.filter((r) => STOCKED.includes(r.item_type) && r.active);
    return {
      total: rows.length,
      stocked: stocked.length,
      low: stocked.filter((r) => r.status === 'REORDER' || r.status === 'NEGATIVE').length,
      value: stocked.reduce((s, r) => s + (Number(r.stock_value) || 0), 0),
    };
  }, [rows]);

  const columns = [
    { key: 'sku', label: 'SKU', render: (r) => <span className="nowrap muted">{r.sku}</span> },
    { key: 'name', label: 'Name', render: (r) => <span className={r.active ? 'bold' : 'muted'}>{r.name}</span> },
    { key: 'category_name', label: 'Category' },
    { key: 'type_short', label: 'Type' },
    { key: 'uom', label: 'Unit' },
    { key: 'price', label: 'Price', type: 'money', render: (r) => (r.price ? peso(r.price) : <span className="muted">—</span>) },
    { key: 'unit_cost', label: 'Unit cost', type: 'money', render: (r) => cost4(r.unit_cost) },
    { key: 'fc', label: 'Food cost %', type: 'percent', render: (r) => (r.fc === null ? '' : <span className={foodCostClass(r.fc)}>{pct(r.fc)}</span>) },
    { key: 'on_hand', label: 'On hand', type: 'qty', render: (r) => (r.on_hand === null ? '' : <span className={r.on_hand < 0 ? 'text-red bold' : ''}>{qty(r.on_hand)}</span>) },
    { key: 'reorder', label: 'Reorder pt', type: 'qty' },
    { key: 'stock_value', label: 'Stock value', type: 'money', total: true, render: (r) => (STOCKED.includes(r.item_type) ? peso(r.stock_value) : '') },
    { key: 'status', label: 'Status', render: (r) => (r.status ? <Badge>{r.status}</Badge> : null) },
  ];

  return (
    <div className="stack">
      <PageHeader title="Items & Recipes" subtitle="Ingredients, menu items with recipes, retail goods and services."
        actions={can('inventory.manage') && <Button variant="primary" onClick={() => navigate('/inventory/items/new')}>+ New item</Button>} />

      <div className="stats">
        <Stat label="Items" value={int(stats.total)} sub={showInactive ? 'including inactive' : 'active'} />
        <Stat label="Stocked items" value={int(stats.stocked)} sub="raw materials & retail" />
        <Stat label="Low / negative stock" value={int(stats.low)} tone={stats.low ? 'amber' : undefined} sub="at or below reorder point" />
        <Stat label="Total stock value" value={peso(stats.value)} tone="green" sub="at moving average cost" />
      </div>

      <ErrorBox error={error} />
      <Card pad={false}>
        <DataTable columns={columns} rows={shown} loading={loading} onRowClick={(r) => navigate(`/inventory/items/${r.id}`)}
          exportName="items" exportTitle="Item Master List"
          rowClass={(r) => (r.active ? '' : 'muted')}
          toolbar={<>
            <Select options={typeOptions} value={type} onChange={setType} placeholder="All types" style={{ width: 210 }} />
            <Select options={(cats.data || []).map((c) => ({ value: c.id, label: c.name }))} value={categoryId} onChange={setCategoryId}
              placeholder="All categories" style={{ width: 190 }} />
            <Checkbox checked={lowOnly} onChange={setLowOnly} label="Low stock only" />
            <Checkbox checked={showInactive} onChange={setShowInactive} label="Show inactive" />
          </>}
          emptyText="No items match the filters." />
      </Card>
    </div>
  );
}
