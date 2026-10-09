<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Permissions;

class AuthController
{
    public function loginForm(Request $req)
    {
        if (Auth::check()) return redirect('/');
        return view('auth/login', ['title' => 'Sign in'], 'blank');
    }

    public function login(Request $req): Response
    {
        Auth::attempt(trim((string) $req->input('username')), (string) $req->input('password'), $req->ip());
        $to = $_SESSION['intended'] ?? '/';
        unset($_SESSION['intended']);
        return redirect($to);
    }

    public function logout(Request $req): Response
    {
        Auth::logout();
        return redirect('/login');
    }

    /** Start page: dashboard, or the first page the user may open. */
    public function home(Request $req)
    {
        // First use: administrators go through the setup wizard first
        if (Auth::can('admin.settings') && \App\Services\Admin\SetupWizard::pending()) return redirect('/setup');
        if (Auth::can('dashboard.view')) return (new DashboardController())->index($req);
        foreach (require BASE_PATH . '/app/nav.php' as $items) {
            foreach ($items as [$href, , , $perms]) if ($href !== '/' && Auth::can(...$perms)) return redirect($href);
        }
        if (Auth::can('pos.access')) return redirect('/pos');
        return redirect('/account');
    }

    public function account(Request $req): string
    {
        return view('auth/account', ['title' => 'My account', 'groups' => Permissions::GROUPS]);
    }

    public function changePassword(Request $req): Response
    {
        $u = DB::one('SELECT * FROM users WHERE id = ?', [Auth::id()]);
        if (!password_verify((string) $req->input('current_password'), $u['password_hash'])) throw HttpException::bad('Current password is incorrect');
        $new = (string) $req->input('new_password');
        $pin = (string) $req->input('new_pin');
        if ($new === '' && $pin === '') throw HttpException::bad('Enter a new password or a new PIN');
        if ($new !== '') {
            if (strlen($new) < 6) throw HttpException::bad('Password must be at least 6 characters');
            if ($new !== $req->input('confirm_password')) throw HttpException::bad('New passwords do not match');
            DB::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        }
        if ($pin !== '') {
            if (!preg_match('/^\d{4,8}$/', $pin)) throw HttpException::bad('PIN must be 4-8 digits');
            DB::run('UPDATE users SET pin_hash = ? WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $u['id']]);
        }
        Audit::log('change_password', 'user', (int) $u['id']);
        flash('success', 'Your account was updated.');
        return redirect('/account');
    }
}
