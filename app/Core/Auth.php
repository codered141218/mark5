<?php
namespace App\Core;

use App\Services\Audit;

/**
 * Login sessions and permission checks.
 *   Auth::user()                 current user row (with 'permissions' array) or null
 *   Auth::id()                   current user id
 *   Auth::can('pos.void_item')   true if the user's role has ANY of the given permissions ('*' = everything)
 *   Auth::require('finance.ap')  throws 403 if not allowed
 *   Auth::authorize('pos.void_receipt', $pin)   manager override: current user OR a PIN of someone allowed
 */
class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;
    private const MAX_FAILS = 10;
    private const LOCK_MINUTES = 15;

    public static function attempt(string $username, string $password, string $ip): array
    {
        $key = strtolower($username) . '|' . $ip;
        $fails = (int) DB::value(
            'SELECT COUNT(*) FROM login_attempts WHERE attempt_key = ? AND attempted_at > ?',
            [$key, date('Y-m-d H:i:s', time() - self::LOCK_MINUTES * 60)]
        );
        if ($fails >= self::MAX_FAILS) throw new HttpException(429, 'Too many failed attempts. Try again in 15 minutes.');

        $u = DB::one('SELECT * FROM users WHERE username = ?', [$username]);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            DB::insert('login_attempts', ['attempt_key' => $key, 'attempted_at' => now()]);
            throw new HttpException(401, 'Invalid username or password');
        }
        if (!$u['active']) throw new HttpException(401, 'This account is disabled');
        DB::run('DELETE FROM login_attempts WHERE attempt_key = ?', [$key]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $u['id'];
        DB::run('UPDATE users SET last_login = ? WHERE id = ?', [now(), $u['id']]);
        self::$loaded = false;
        Audit::log('login', 'user', (int) $u['id']);
        return self::user();
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
        self::$loaded = true;
    }

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            self::$user = isset($_SESSION['user_id']) ? self::load((int) $_SESSION['user_id']) : null;
            if (self::$user && !self::$user['active']) self::$user = null;
        }
        return self::$user;
    }

    /** Used by tests and scripts to act as a given user. */
    public static function actAs(?int $userId): void
    {
        self::$user = $userId ? self::load($userId) : null;
        self::$loaded = true;
    }

    public static function load(int $id): ?array
    {
        $u = DB::one(
            'SELECT u.id, u.username, u.full_name, u.role_id, u.employee_id, u.active, r.name AS role_name, r.permissions
             FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?',
            [$id]
        );
        if (!$u) return null;
        $u['permissions'] = json_decode($u['permissions'] ?? '[]', true) ?: [];
        $u['id'] = (int) $u['id'];
        $u['employee_id'] = $u['employee_id'] ? (int) $u['employee_id'] : null;
        return $u;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function can(string ...$perms): bool
    {
        $u = self::user();
        return $u ? self::userCan($u, ...$perms) : false;
    }

    public static function userCan(array $user, string ...$perms): bool
    {
        if (in_array('*', $user['permissions'], true)) return true;
        return (bool) array_intersect($perms, $user['permissions']);
    }

    public static function require(string ...$perms): void
    {
        if (!self::can(...$perms)) throw HttpException::forbidden();
    }

    /**
     * Manager override. Returns the id of the user who authorized the action:
     * the current user if allowed, otherwise any active user whose PIN matches and who has the permission.
     */
    public static function authorize(string $perm, ?string $pin): int
    {
        if (self::can($perm)) return self::id();
        if (!$pin) throw HttpException::forbidden('Manager authorization required. Enter a manager PIN.');
        foreach (DB::all('SELECT id, pin_hash FROM users WHERE active = 1 AND pin_hash IS NOT NULL') as $c) {
            if (password_verify($pin, $c['pin_hash'])) {
                $u = self::load((int) $c['id']);
                if ($u && self::userCan($u, $perm)) {
                    Audit::log('override', $perm, null, ['authorized_by' => $u['username']]);
                    return $u['id'];
                }
            }
        }
        throw HttpException::forbidden('Invalid manager PIN or the PIN owner is not allowed to do this.');
    }
}
