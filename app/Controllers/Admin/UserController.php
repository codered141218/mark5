<?php
namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Admin\Roles;
use App\Services\Admin\Users;

/** /admin/users — who can sign in, their role, manager PIN and linked employee. */
class UserController
{
    public function index(Request $req)
    {
        $me = Auth::id();
        $rows = array_map(fn ($r) => $r + ['_class' => $r['active'] ? '' : 'muted-row'], Users::list());
        $columns = [
            ['key' => 'username', 'label' => 'Username', 'html' => fn ($r) => '<b>' . e($r['username']) . '</b>' . ((int) $r['id'] === $me ? ' <span class="muted small">(you)</span>' : '')],
            ['key' => 'full_name', 'label' => 'Full name'],
            ['key' => 'role_name', 'label' => 'Role', 'html' => fn ($r) => $r['role_name'] ? badge($r['role_name'], 'blue') : '<span class="muted">—</span>'],
            ['key' => 'employee_name', 'label' => 'Linked employee', 'html' => fn ($r) => $r['employee_name'] ? e($r['employee_name']) : '<span class="muted">—</span>'],
            ['key' => 'has_pin', 'label' => 'Manager PIN', 'align' => 'center', 'value' => fn ($r) => $r['has_pin'] ? 'Yes' : 'No',
                'html' => fn ($r) => $r['has_pin'] ? '✓' : '<span class="muted">—</span>'],
            ['key' => 'active', 'label' => 'Status', 'value' => fn ($r) => $r['active'] ? 'Active' : 'Disabled',
                'html' => fn ($r) => $r['active'] ? badge('active') : badge('disabled', 'red')],
            ['key' => 'last_login', 'label' => 'Last login', 'type' => 'datetime'],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => view('admin/users/_actions', ['r' => $r, 'self' => (int) $r['id'] === $me], null)],
        ];
        if ($x = Table::export($req, 'users', 'System users', '', $columns, $rows)) return $x;
        $roles = [];
        foreach (Roles::list() as $role) $roles[$role['id']] = $role['name'];
        return view('admin/users/index', [
            'title' => 'Users', 'columns' => $columns, 'rows' => $rows, 'roles' => $roles, 'employees' => Users::employeeOptions(),
        ]);
    }

    public function store(Request $req): Response
    {
        Users::create($req->all());
        flash('success', 'User created.');
        return redirect('/admin/users');
    }

    public function update(Request $req, string $id): Response
    {
        Users::update((int) $id, $req->all());
        flash('success', 'User updated.');
        return redirect('/admin/users');
    }

    public function disable(Request $req, string $id): Response
    {
        Users::disable((int) $id);
        flash('success', 'User disabled. They can no longer sign in.');
        return redirect('/admin/users');
    }
}
