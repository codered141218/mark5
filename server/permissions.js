'use strict';
// Every permission the system checks. Grouped for the role editor UI.
const PERMISSION_GROUPS = [
  {
    group: 'POS / Cashier',
    perms: [
      ['pos.access', 'Access POS terminal & take orders'],
      ['pos.open_day', 'Open business day (beginning cash)'],
      ['pos.close_day', 'End of day / Z-reading & cash count'],
      ['pos.xreading', 'Print X-reading'],
      ['pos.settle', 'Accept payments / settle tickets'],
      ['pos.discount', 'Apply discounts (SC/PWD/promo)'],
      ['pos.void_item', 'Void items on an open ticket'],
      ['pos.void_receipt', 'Void paid receipts'],
      ['pos.reprint', 'Reprint receipts'],
      ['pos.split_move', 'Split, merge & move tickets/tables'],
      ['pos.petty_cash', 'Record petty cash / drawer payouts at POS'],
    ],
  },
  {
    group: 'Dashboard & Reports',
    perms: [
      ['dashboard.view', 'View dashboard'],
      ['reports.sales', 'Sales reports'],
      ['reports.inventory', 'Inventory reports'],
      ['reports.finance', 'Financial statements & ledgers'],
    ],
  },
  {
    group: 'Inventory',
    perms: [
      ['inventory.view', 'View items & stock'],
      ['inventory.manage', 'Add / edit / delete items, recipes, categories, UOM'],
      ['inventory.receive', 'Delivery / stock in'],
      ['inventory.issue', 'Stock issuance'],
      ['inventory.waste', 'Spoilage & wastage'],
      ['inventory.count', 'Inventory count sessions'],
      ['inventory.post', 'Post inventory documents & counts'],
    ],
  },
  {
    group: 'Petty Cash',
    perms: [
      ['pettycash.view', 'View petty cash'],
      ['pettycash.manage', 'Record / void petty cash, replenish fund'],
    ],
  },
  {
    group: 'Finance',
    perms: [
      ['finance.view', 'View chart of accounts & journals'],
      ['finance.accounts', 'Manage chart of accounts'],
      ['finance.journal', 'Create / void manual journal entries'],
      ['finance.banks', 'Banks: money in / out / transfers'],
      ['finance.ap', 'Accounts payable'],
      ['finance.ar', 'Accounts receivable'],
      ['partners.manage', 'Manage suppliers & customers'],
    ],
  },
  {
    group: 'Cash Advances & Employees',
    perms: [
      ['ca.request', 'File cash advance requests'],
      ['ca.approve', 'Approve / reject / release cash advances'],
      ['ca.manage', 'View all advances & record repayments'],
      ['employees.manage', 'Manage employees'],
    ],
  },
  {
    group: 'Administration',
    perms: [
      ['admin.users', 'Manage users'],
      ['admin.roles', 'Manage roles & permissions'],
      ['admin.settings', 'Business settings, tables, tax'],
      ['admin.backup', 'Database backup & restore'],
      ['admin.audit', 'View audit trail'],
    ],
  },
];

const ALL_PERMISSIONS = PERMISSION_GROUPS.flatMap((g) => g.perms.map((p) => p[0]));

const DEFAULT_ROLES = [
  { name: 'Administrator', description: 'Full access to everything', permissions: ['*'], is_system: 1 },
  {
    name: 'Manager',
    description: 'Store manager: POS overrides, inventory, reports, approvals',
    permissions: ALL_PERMISSIONS.filter((p) => !['admin.roles', 'admin.backup', 'finance.accounts'].includes(p)),
  },
  {
    name: 'Cashier',
    description: 'Front of house cashier',
    permissions: ['pos.access', 'pos.open_day', 'pos.close_day', 'pos.xreading', 'pos.settle', 'pos.reprint', 'pos.split_move', 'pos.petty_cash', 'ca.request'],
  },
  {
    name: 'Waiter',
    description: 'Takes orders only, cannot settle',
    permissions: ['pos.access', 'pos.split_move', 'ca.request'],
  },
  {
    name: 'Inventory Clerk',
    description: 'Stock room / commissary',
    permissions: ['inventory.view', 'inventory.receive', 'inventory.issue', 'inventory.waste', 'inventory.count', 'reports.inventory', 'ca.request'],
  },
  {
    name: 'Accountant',
    description: 'Bookkeeping & finance',
    permissions: ['dashboard.view', 'reports.sales', 'reports.inventory', 'reports.finance', 'pettycash.view', 'pettycash.manage',
      'finance.view', 'finance.accounts', 'finance.journal', 'finance.banks', 'finance.ap', 'finance.ar', 'partners.manage',
      'ca.request', 'ca.manage', 'inventory.view', 'inventory.post'],
  },
];

function hasPerm(perms, p) {
  return perms.includes('*') || perms.includes(p);
}

module.exports = { PERMISSION_GROUPS, ALL_PERMISSIONS, DEFAULT_ROLES, hasPerm };
