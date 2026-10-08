<?php
namespace App\Services;

/** Every permission the system checks, grouped for the role editor. */
class Permissions
{
    public const GROUPS = [
        'POS / Cashier' => [
            'pos.access' => 'Access POS terminal & take orders',
            'pos.open_day' => 'Open business day (beginning cash)',
            'pos.close_day' => 'End of day / Z-reading & cash count',
            'pos.xreading' => 'Print X-reading',
            'pos.settle' => 'Accept payments / settle orders',
            'pos.discount' => 'Apply discounts (SC/PWD/promo) & price override',
            'pos.void_item' => 'Void items already sent to kitchen',
            'pos.void_receipt' => 'Void paid receipts',
            'pos.reprint' => 'Reprint receipts',
            'pos.split_move' => 'Split, merge & change table of orders',
            'pos.petty_cash' => 'Record petty cash / drawer payouts at POS',
        ],
        'Dashboard & Reports' => [
            'dashboard.view' => 'View dashboard',
            'reports.sales' => 'Sales reports',
            'reports.inventory' => 'Inventory reports',
            'reports.finance' => 'Financial statements & ledgers',
        ],
        'Inventory' => [
            'inventory.view' => 'View items & stock',
            'inventory.manage' => 'Add / edit / delete items, recipes, categories, units',
            'inventory.receive' => 'Delivery / stock in',
            'inventory.issue' => 'Stock issuance',
            'inventory.waste' => 'Spoilage & wastage',
            'inventory.count' => 'Inventory count sessions',
            'inventory.post' => 'Post inventory documents & counts',
        ],
        'Petty Cash' => [
            'pettycash.view' => 'View petty cash',
            'pettycash.manage' => 'Record / void petty cash, replenish fund',
        ],
        'Finance' => [
            'finance.view' => 'View chart of accounts & journals',
            'finance.accounts' => 'Manage chart of accounts',
            'finance.journal' => 'Create / void manual journal entries',
            'finance.banks' => 'Banks: money in / out / transfers',
            'finance.ap' => 'Accounts payable',
            'finance.ar' => 'Accounts receivable',
            'partners.manage' => 'Manage suppliers & customers',
        ],
        'Cash Advances & Employees' => [
            'ca.request' => 'File cash advance requests',
            'ca.approve' => 'Approve / reject / release cash advances',
            'ca.manage' => 'View all advances & record repayments',
            'employees.manage' => 'Manage employees',
        ],
        'Administration' => [
            'admin.users' => 'Manage users',
            'admin.roles' => 'Manage roles & permissions',
            'admin.settings' => 'Business, tax & receipt settings',
            'admin.backup' => 'Database backup & restore',
            'admin.audit' => 'View audit trail',
        ],
    ];

    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::GROUPS)));
    }

    /** Keep only known permission keys. */
    public static function clean($perms): array
    {
        if (!is_array($perms)) return [];
        if (in_array('*', $perms, true)) return ['*'];
        return array_values(array_intersect(self::all(), $perms));
    }

    public static function defaultRoles(): array
    {
        $all = self::all();
        return [
            ['name' => 'Administrator', 'description' => 'Full access to everything', 'permissions' => ['*'], 'is_system' => 1],
            ['name' => 'Manager', 'description' => 'Store manager: POS overrides, inventory, reports, approvals',
                'permissions' => array_values(array_diff($all, ['admin.roles', 'admin.backup', 'finance.accounts']))],
            ['name' => 'Cashier', 'description' => 'Front of house cashier',
                'permissions' => ['pos.access', 'pos.open_day', 'pos.close_day', 'pos.xreading', 'pos.settle', 'pos.reprint', 'pos.split_move', 'pos.petty_cash', 'ca.request']],
            ['name' => 'Waiter', 'description' => 'Takes orders only, cannot accept payment',
                'permissions' => ['pos.access', 'pos.split_move', 'ca.request']],
            ['name' => 'Inventory Clerk', 'description' => 'Stock room / commissary',
                'permissions' => ['inventory.view', 'inventory.receive', 'inventory.issue', 'inventory.waste', 'inventory.count', 'reports.inventory', 'ca.request']],
            ['name' => 'Accountant', 'description' => 'Bookkeeping & finance',
                'permissions' => ['dashboard.view', 'reports.sales', 'reports.inventory', 'reports.finance', 'pettycash.view', 'pettycash.manage',
                    'finance.view', 'finance.accounts', 'finance.journal', 'finance.banks', 'finance.ap', 'finance.ar', 'partners.manage',
                    'ca.request', 'ca.manage', 'inventory.view', 'inventory.post']],
        ];
    }
}
