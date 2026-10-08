<?php
namespace App\Services\Admin;

use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Permissions;

/**
 * Roles = named sets of permissions (keys from Permissions::GROUPS) assigned to users.
 * The system role (Administrator) always keeps full access ["*"] and cannot be renamed or deleted.
 */
class Roles
{
    /** All roles with their decoded permissions and number of users. */
    public static function list(): array
    {
        $rows = DB::all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count FROM roles r ORDER BY r.name');
        return array_map([self::class, 'decode'], $rows);
    }

    public static function find(int $id): ?array
    {
        $r = DB::one('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count FROM roles r WHERE r.id = ?', [$id]);
        return $r ? self::decode($r) : null;
    }

    public static function isFull(array $role): bool
    {
        return !empty($role['is_system']) || in_array('*', $role['permissions'], true);
    }

    /** Create ($d['id'] empty) or update a role. Unknown permission keys are dropped. Returns the id. */
    public static function save(array $d): int
    {
        $id = (int) ($d['id'] ?? 0);
        $name = trim((string) ($d['name'] ?? ''));
        $row = ['description' => trim((string) ($d['description'] ?? '')) ?: null, 'permissions' => Permissions::clean($d['permissions'] ?? [])];
        if ($id) {
            $role = self::find($id);
            if (!$role) throw HttpException::notFound('Role');
            if ($role['is_system']) {
                $name = $role['name'];
                $row['permissions'] = ['*'];
            }
        }
        if ($name === '') throw HttpException::bad('Role name is required');
        if (DB::value('SELECT id FROM roles WHERE name = ? AND id <> ?', [$name, $id])) throw HttpException::bad("A role named \"$name\" already exists");
        $row['name'] = $name;
        $row['permissions'] = json_encode($row['permissions']);
        if ($id) {
            DB::update('roles', $id, $row);
            Audit::log('update', 'role', $id, $row);
        } else {
            $id = DB::insert('roles', $row);
            Audit::log('create', 'role', $id, $row);
        }
        return $id;
    }

    public static function delete(int $id): void
    {
        $role = self::find($id);
        if (!$role) throw HttpException::notFound('Role');
        if ($role['is_system']) throw HttpException::bad("The {$role['name']} role cannot be deleted");
        if ($role['user_count'] > 0) throw HttpException::bad('This role is still assigned to ' . $role['user_count'] . ' user(s). Give them another role first.');
        DB::run('DELETE FROM roles WHERE id = ?', [$id]);
        Audit::log('delete', 'role', $id, $role['name']);
    }

    private static function decode(array $r): array
    {
        $r['permissions'] = json_decode($r['permissions'] ?: '[]', true) ?: [];
        $r['is_system'] = (int) $r['is_system'];
        $r['user_count'] = (int) $r['user_count'];
        return $r;
    }
}
