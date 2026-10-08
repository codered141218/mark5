import React, { useState } from 'react';
import { NavLink, Link, useLocation } from 'react-router-dom';
import { useAuth } from '../auth';

// Sidebar menu. Each entry is shown only if the user has ANY of its permissions.
export const NAV = [
  { group: 'Overview', items: [
    { to: '/', label: 'Dashboard', ico: '◎', perms: ['dashboard.view'], end: true },
  ] },
  { group: 'Reports', items: [
    { to: '/reports/sales', label: 'Sales Reports', ico: '▤', perms: ['reports.sales'] },
    { to: '/reports/inventory', label: 'Inventory Reports', ico: '▦', perms: ['reports.inventory'] },
    { to: '/reports/finance', label: 'Financial Reports', ico: '▥', perms: ['reports.finance'] },
  ] },
  { group: 'Inventory', items: [
    { to: '/inventory/items', label: 'Items & Recipes', ico: '◫', perms: ['inventory.view', 'inventory.manage'] },
    { to: '/inventory/receiving', label: 'Delivery / Stock In', ico: '⇩', perms: ['inventory.receive'] },
    { to: '/inventory/issuance', label: 'Stock Issuance', ico: '⇧', perms: ['inventory.issue'] },
    { to: '/inventory/wastage', label: 'Spoilage & Wastage', ico: '✕', perms: ['inventory.waste'] },
    { to: '/inventory/counts', label: 'Inventory Count', ico: '#', perms: ['inventory.count'] },
    { to: '/inventory/categories', label: 'Categories', ico: '❖', perms: ['inventory.manage'] },
    { to: '/inventory/uom', label: 'Units & Conversions', ico: '⚖', perms: ['inventory.manage'] },
  ] },
  { group: 'Cash & Finance', items: [
    { to: '/petty-cash', label: 'Petty Cash', ico: '₱', perms: ['pettycash.view', 'pettycash.manage'] },
    { to: '/finance/banks', label: 'Banks', ico: '🏦', perms: ['finance.banks'] },
    { to: '/finance/payables', label: 'Accounts Payable', ico: '↗', perms: ['finance.ap'] },
    { to: '/finance/receivables', label: 'Accounts Receivable', ico: '↙', perms: ['finance.ar'] },
    { to: '/finance/journals', label: 'Journal Entries', ico: '✎', perms: ['finance.view', 'finance.journal'] },
    { to: '/finance/accounts', label: 'Chart of Accounts', ico: '☰', perms: ['finance.view', 'finance.accounts'] },
    { to: '/finance/partners', label: 'Suppliers & Customers', ico: '☺', perms: ['partners.manage', 'finance.ap', 'finance.ar'] },
  ] },
  { group: 'People', items: [
    { to: '/cash-advances', label: 'Cash Advances', ico: '⚑', perms: ['ca.request', 'ca.approve', 'ca.manage'] },
    { to: '/employees', label: 'Employees', ico: '☻', perms: ['employees.manage'] },
  ] },
  { group: 'Administration', items: [
    { to: '/admin/users', label: 'Users', ico: '👤', perms: ['admin.users'] },
    { to: '/admin/roles', label: 'Roles & Permissions', ico: '🔒', perms: ['admin.roles'] },
    { to: '/admin/settings', label: 'Settings & Tables', ico: '⚙', perms: ['admin.settings'] },
    { to: '/admin/backup', label: 'Backup & Restore', ico: '⛁', perms: ['admin.backup'] },
    { to: '/admin/audit', label: 'Audit Trail', ico: '⌕', perms: ['admin.audit'] },
  ] },
];

export default function Layout({ children }) {
  const { user, settings, logout, can } = useAuth();
  const [open, setOpen] = useState(false);
  const loc = useLocation();
  React.useEffect(() => setOpen(false), [loc.pathname]);

  return (
    <div className="app">
      <div className="topbar">
        <button className="icon-btn" onClick={() => setOpen(true)} aria-label="Menu">☰</button>
        <b>{settings.business_name || 'Mark5'}</b>
      </div>
      <aside className={`sidebar ${open ? 'open' : ''}`}>
        <div className="brand">
          <div className="brand-logo">M5</div>
          <div>
            <div className="brand-name">{settings.business_name || 'Mark5 Restaurant'}</div>
            <div className="brand-sub">Restaurant Suite</div>
          </div>
        </div>
        {can('pos.access') && <div className="pos-link"><Link to="/pos">▶ Open POS / Cashier</Link></div>}
        <nav className="nav">
          {NAV.map((g) => {
            const items = g.items.filter((i) => can(...i.perms));
            if (!items.length) return null;
            return (
              <div className="nav-group" key={g.group}>
                <div className="nav-group-title">{g.group}</div>
                {items.map((i) => (
                  <NavLink key={i.to} to={i.to} end={i.end}><span className="ico">{i.ico}</span>{i.label}</NavLink>
                ))}
              </div>
            );
          })}
        </nav>
        <div className="side-user">
          <b>{user.full_name}</b>
          <span>{user.role_name}</span>
          <div className="row gap-sm mt">
            <Link to="/account" style={{ color: '#d1d5db' }}>My account</Link>
            <span>·</span>
            <a href="#" style={{ color: '#d1d5db' }} onClick={(e) => { e.preventDefault(); logout(); }}>Log out</a>
          </div>
        </div>
      </aside>
      {open && <div className="modal-backdrop" style={{ zIndex: 40 }} onClick={() => setOpen(false)} />}
      <main className="main"><div className="content">{children}</div></main>
    </div>
  );
}
