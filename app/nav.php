<?php
/**
 * Sidebar menu. An entry is shown only if the user has ANY of its permissions.
 * [url, label, icon, [permissions]]
 */
return [
    'Overview' => [
        ['/', 'Dashboard', '◎', ['dashboard.view']],
    ],
    'Reports' => [
        ['/reports/sales', 'Sales Reports', '▤', ['reports.sales']],
        ['/reports/inventory', 'Inventory Reports', '▦', ['reports.inventory']],
        ['/reports/finance', 'Financial Reports', '▥', ['reports.finance', 'pettycash.view']],
    ],
    'Inventory' => [
        ['/inventory/items', 'Items & Recipes', '◫', ['inventory.view', 'inventory.manage']],
        ['/inventory/receiving', 'Delivery / Stock In', '⇩', ['inventory.receive']],
        ['/inventory/issuance', 'Stock Issuance', '⇧', ['inventory.issue']],
        ['/inventory/wastage', 'Spoilage & Wastage', '✕', ['inventory.waste']],
        ['/inventory/counts', 'Inventory Count', '#', ['inventory.count']],
        ['/inventory/categories', 'Categories', '❖', ['inventory.manage']],
        ['/inventory/uom', 'Units & Conversions', '⚖', ['inventory.manage']],
    ],
    'Cash & Finance' => [
        ['/petty-cash', 'Petty Cash', '₱', ['pettycash.view', 'pettycash.manage']],
        ['/finance/banks', 'Banks', '▣', ['finance.banks']],
        ['/finance/payables', 'Accounts Payable', '↗', ['finance.ap']],
        ['/finance/receivables', 'Accounts Receivable', '↙', ['finance.ar']],
        ['/finance/journals', 'Journal Entries', '✎', ['finance.view', 'finance.journal']],
        ['/finance/accounts', 'Chart of Accounts', '☰', ['finance.view', 'finance.accounts']],
        ['/finance/suppliers', 'Suppliers', '⛟', ['partners.manage', 'finance.ap']],
        ['/finance/customers', 'Customers', '☺', ['partners.manage', 'finance.ar']],
    ],
    'People' => [
        ['/cash-advances', 'Cash Advances', '⚑', ['ca.request', 'ca.approve', 'ca.manage']],
        ['/employees', 'Employees', '☻', ['employees.manage']],
    ],
    'Administration' => [
        ['/admin/users', 'Users', '⚇', ['admin.users']],
        ['/admin/roles', 'Roles & Permissions', '⚿', ['admin.roles']],
        ['/admin/settings', 'Settings', '⚙', ['admin.settings']],
        ['/printer', 'Printer Setup', '⎙', ['pos.access', 'admin.settings']],
        ['/admin/backup', 'Backup & Restore', '⛁', ['admin.backup']],
        ['/admin/audit', 'Audit Trail', '⌕', ['admin.audit']],
    ],
];
