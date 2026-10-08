<?php
namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Installer;

/** First-run web installer: asks for the MySQL details, creates tables and the admin account. */
class InstallController
{
    public function redirect(Request $req): \App\Core\Response
    {
        return redirect('/install');
    }

    public function form(Request $req): string
    {
        return view('install/form', ['db' => config('db'), 'zip' => class_exists(\ZipArchive::class)], 'blank');
    }

    public function install(Request $req): \App\Core\Response
    {
        $db = [
            'host' => trim($req->input('host', '127.0.0.1')),
            'port' => (int) $req->input('port', 3306),
            'database' => trim($req->input('database', 'mark5')),
            'username' => trim($req->input('username', 'root')),
            'password' => (string) $req->input('password', ''),
        ];
        $adminPassword = (string) $req->input('admin_password', '');
        if (strlen($adminPassword) < 6) throw HttpException::bad('Admin password must be at least 6 characters');
        if ($adminPassword !== $req->input('admin_password_confirm')) throw HttpException::bad('Admin passwords do not match');
        $pin = (string) $req->input('admin_pin', '1234');
        if (!preg_match('/^\d{4,8}$/', $pin)) throw HttpException::bad('Manager PIN must be 4-8 digits');

        DB::configure($db);
        try {
            Installer::createDatabase($db['database']);
            Installer::install($req->input('sample') === '1', $adminPassword, $pin);
        } catch (\PDOException $e) {
            throw HttpException::bad('Could not connect to MySQL: ' . $e->getMessage());
        }
        Installer::writeConfig($db);
        flash('success', 'Installation complete. Log in as admin with the password you chose.');
        return redirect('/login');
    }
}
