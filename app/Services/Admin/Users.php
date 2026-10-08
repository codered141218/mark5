<?php
namespace App\Services\Admin;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;

/**
 * System users: who can sign in, with which role, optional manager PIN and linked employee.
 * Users are never deleted (their name stays on past transactions); they are disabled instead.
 */
class Users
{
    public static function list(): array
    {
        return DB::all(
            'SELECT u.id, u.username, u.full_name, u.role_id, u.employee_id, u.active, u.last_login, u.created_at,
                    r.name AS role_name, e.full_name AS employee_name, (u.pin_hash IS NOT NULL) AS has_pin
             FROM users u LEFT JOIN roles r ON r.id = u.role_id LEFT JOIN employees e ON e.id = u.employee_id
             ORDER BY u.full_name'
        );
    }

    /** Employees a user can be linked to: [id => "Name (EMP-001)"]. Inactive ones are marked. */
    public static function employeeOptions(): array
    {
        $opts = [];
        foreach (DB::all('SELECT id, emp_no, full_name, active FROM employees ORDER BY full_name') as $e) {
            $opts[$e['id']] = $e['full_name'] . ($e['emp_no'] ? " ({$e['emp_no']})" : '') . ($e['active'] ? '' : ' — inactive');
        }
        return $opts;
    }

    /** $d: username, full_name, password, pin (optional), role_id, employee_id (optional). Returns the new id. */
    public static function create(array $d): int
    {
        required($d, 'username', 'full_name', 'password', 'role_id');
        $username = trim($d['username']);
        if (DB::value('SELECT id FROM users WHERE username = ?', [$username])) throw HttpException::bad('Username already exists');
        $row = self::common($d) + [
            'username' => $username,
            'password_hash' => self::passwordHash($d['password']),
            'pin_hash' => self::pinHash($d['pin'] ?? ''),
            'active' => 1,
            'created_at' => now(),
        ];
        $id = DB::insert('users', $row);
        Audit::log('create', 'user', $id, ['username' => $username, 'role_id' => $row['role_id']]);
        return $id;
    }

    /** Blank password / PIN = keep the current one. $d['active'] false disables the account. */
    public static function update(int $id, array $d): void
    {
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$user) throw HttpException::notFound('User');
        required($d, 'full_name', 'role_id');
        $active = !empty($d['active']);
        if (!$active && $id === Auth::id()) throw HttpException::bad('You cannot disable your own account');
        $row = self::common($d) + ['active' => $active ? 1 : 0];
        if (($d['password'] ?? '') !== '') $row['password_hash'] = self::passwordHash($d['password']);
        if (($d['pin'] ?? '') !== '') $row['pin_hash'] = self::pinHash($d['pin']);
        DB::update('users', $id, $row);
        Audit::log('update', 'user', $id, [
            'full_name' => $row['full_name'], 'role_id' => $row['role_id'], 'active' => $row['active'],
            'password_changed' => isset($row['password_hash']), 'pin_changed' => isset($row['pin_hash']),
        ]);
    }

    /** Soft delete: the user can no longer sign in; past records keep their name. */
    public static function disable(int $id): void
    {
        if ($id === Auth::id()) throw HttpException::bad('You cannot disable your own account');
        if (!DB::value('SELECT id FROM users WHERE id = ?', [$id])) throw HttpException::notFound('User');
        DB::run('UPDATE users SET active = 0 WHERE id = ?', [$id]);
        Audit::log('disable', 'user', $id);
    }

    /** Fields shared by create and update, validated. */
    private static function common(array $d): array
    {
        $name = trim($d['full_name']);
        if ($name === '') throw HttpException::bad('Full name is required');
        $roleId = (int) $d['role_id'];
        if (!DB::value('SELECT id FROM roles WHERE id = ?', [$roleId])) throw HttpException::bad('Choose a role');
        $empId = (int) ($d['employee_id'] ?? 0) ?: null;
        if ($empId && !DB::value('SELECT id FROM employees WHERE id = ?', [$empId])) throw HttpException::bad('Linked employee not found');
        return ['full_name' => $name, 'role_id' => $roleId, 'employee_id' => $empId];
    }

    private static function passwordHash(string $password): string
    {
        if (strlen($password) < 6) throw HttpException::bad('Password must be at least 6 characters');
        return password_hash($password, PASSWORD_DEFAULT);
    }

    private static function pinHash(string $pin): ?string
    {
        if ($pin === '') return null;
        if (!preg_match('/^\d{4,8}$/', $pin)) throw HttpException::bad('PIN must be 4-8 digits');
        return password_hash($pin, PASSWORD_DEFAULT);
    }
}
