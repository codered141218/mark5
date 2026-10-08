<?php
namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Admin\Roles;
use App\Services\Permissions;

/**
 * /admin/roles — role list on the left, permission matrix editor on the right.
 *   ?id=5  edit role 5     ?new=1  blank new role     ?duplicate=5  new role pre-filled from role 5
 */
class RoleController
{
    public function index(Request $req)
    {
        $roles = Roles::list();
        $all = Permissions::all();
        $columns = [
            ['key' => 'name', 'label' => 'Role'],
            ['key' => 'description', 'label' => 'Description'],
            ['key' => 'user_count', 'label' => 'Users', 'type' => 'int'],
            ['key' => 'permissions', 'label' => 'Permissions', 'value' => fn ($r) => Roles::isFull($r) ? 'Full access'
                : implode(', ', array_map(fn ($p) => self::label($p), $r['permissions']))],
        ];
        if ($x = Table::export($req, 'roles', 'Roles & permissions', '', $columns, $roles)) return $x;

        if ($req->query('new')) {
            $sel = ['id' => null, 'name' => '', 'description' => '', 'permissions' => [], 'is_system' => 0, 'user_count' => 0];
        } elseif ($src = Roles::find((int) $req->query('duplicate'))) {
            $sel = ['id' => null, 'name' => $src['name'] . ' (copy)', 'description' => $src['description'],
                'permissions' => Roles::isFull($src) ? $all : $src['permissions'], 'is_system' => 0, 'user_count' => 0];
        } else {
            $sel = Roles::find((int) $req->query('id'));
            if (!$sel) foreach ($roles as $r) if (!$r['is_system']) { $sel = $r; break; }
        }
        return view('admin/roles/index', [
            'title' => 'Roles & Permissions', 'roles' => $roles, 'sel' => $sel ?? ($roles[0] ?? null),
            'groups' => Permissions::GROUPS, 'total' => count($all),
        ]);
    }

    public function save(Request $req): Response
    {
        $isNew = !$req->input('id');
        $id = Roles::save($req->all());
        flash('success', $isNew ? 'Role created.' : 'Role saved.');
        return redirect('/admin/roles?id=' . $id);
    }

    public function delete(Request $req, string $id): Response
    {
        Roles::delete((int) $id);
        flash('success', 'Role deleted.');
        return redirect('/admin/roles');
    }

    private static function label(string $perm): string
    {
        foreach (Permissions::GROUPS as $perms) if (isset($perms[$perm])) return $perms[$perm];
        return $perm;
    }
}
