<?php
/** Administration module routes: users, roles, settings, backup & restore, audit trail. */
use App\Controllers\Admin\AuditController;
use App\Controllers\Admin\BackupController;
use App\Controllers\Admin\RoleController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\UserController;
use App\Core\Auth;
use App\Services\Backup;

$router->get('/admin/users', [UserController::class, 'index'], 'admin.users');
$router->post('/admin/users', [UserController::class, 'store'], 'admin.users');
$router->post('/admin/users/{id}', [UserController::class, 'update'], 'admin.users');
$router->post('/admin/users/{id}/disable', [UserController::class, 'disable'], 'admin.users');

$router->get('/admin/roles', [RoleController::class, 'index'], 'admin.roles');
$router->post('/admin/roles', [RoleController::class, 'save'], 'admin.roles');
$router->post('/admin/roles/{id}/delete', [RoleController::class, 'delete'], 'admin.roles');

$router->get('/admin/settings', [SettingsController::class, 'index'], 'admin.settings');
$router->post('/admin/settings', [SettingsController::class, 'save'], 'admin.settings');

$router->get('/admin/backup', [BackupController::class, 'index'], 'admin.backup');
$router->post('/admin/backup', [BackupController::class, 'create'], 'admin.backup');
$router->post('/admin/backup/upload', [BackupController::class, 'upload'], 'admin.backup');
$router->get('/admin/backup/{name}/download', [BackupController::class, 'download'], 'admin.backup');
$router->post('/admin/backup/{name}/restore', [BackupController::class, 'restore'], 'admin.backup');
$router->post('/admin/backup/{name}/delete', [BackupController::class, 'delete'], 'admin.backup');

$router->get('/admin/audit', [AuditController::class, 'index'], 'admin.audit');

/*
 * Daily automatic backup. The first page a signed-in user opens each day (any page, any user)
 * schedules it; it runs after the page has been sent, so nobody waits for it. The check costs
 * nothing extra: the settings are cached and the layout loads them anyway. Backup::autoBackup()
 * claims the day atomically, so simultaneous requests never make two backups.
 */
if (Auth::check() && Backup::autoBackupDue()) {
    register_shutdown_function(function () {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        ignore_user_abort(true);
        try {
            Backup::autoBackup();
        } catch (Throwable $e) {
            error_log('Automatic backup failed: ' . $e);
        }
    });
}
