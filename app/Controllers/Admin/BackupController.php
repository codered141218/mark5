<?php
namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Audit;
use App\Services\Backup;
use App\Services\Settings;

/** /admin/backup — create, download, delete and restore database backups (see App\Services\Backup). */
class BackupController
{
    public function index(Request $req)
    {
        $rows = Backup::list();
        $columns = [
            ['key' => 'name', 'label' => 'File', 'html' => fn ($b) => '<span class="mono">' . e($b['name']) . '</span>'],
            ['key' => 'kind', 'label' => 'Type', 'value' => fn ($b) => (Backup::KINDS[$b['kind']] ?? [$b['kind']])[0],
                'html' => fn ($b) => badge(...(Backup::KINDS[$b['kind']] ?? [$b['kind'], 'gray']))],
            ['key' => 'created_at', 'label' => 'Created', 'type' => 'datetime'],
            ['key' => 'size', 'label' => 'Size', 'type' => 'int', 'html' => fn ($b) => e(self::size($b['size']))],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($b) => view('admin/backup/_actions', ['b' => $b], null)],
        ];
        if ($x = Table::export($req, 'backups', 'Database backups', '', $columns, $rows)) return $x;
        return view('admin/backup/index', [
            'title' => 'Backup & Restore', 'columns' => $columns, 'rows' => $rows,
            'totalSize' => self::size(array_sum(array_column($rows, 'size'))),
            'autoOn' => Settings::get('auto_backup', '1') === '1', 'retention' => (int) Settings::get('backup_retention', 30),
            'uploadLimit' => ini_get('upload_max_filesize'),
        ]);
    }

    public function create(Request $req): Response
    {
        $b = Backup::create('manual');
        Audit::log('backup', 'database', null, $b['name']);
        flash('success', "Backup created: {$b['name']}");
        return redirect('/admin/backup');
    }

    public function download(Request $req, string $name): Response
    {
        return new FileResponse(Backup::path($name), $name, 'application/sql');
    }

    public function delete(Request $req, string $name): Response
    {
        Backup::delete($name);
        Audit::log('delete_backup', 'database', null, $name);
        flash('success', "Backup $name deleted.");
        return redirect('/admin/backup');
    }

    public function restore(Request $req, string $name): Response
    {
        return $this->signOut(Backup::restore(Backup::path($name), $name), $name);
    }

    public function upload(Request $req): Response
    {
        $file = $req->file('backup');
        return $this->signOut(Backup::restoreUpload($file), $file['name'] ?? 'upload');
    }

    /** After a restore the user table may be different, so everyone signs in again. */
    private function signOut(array $result, string $from): Response
    {
        Auth::logout();
        flash('success', "Data restored from $from. A safety copy of the previous data was saved as {$result['safety_backup']}. Please sign in again.");
        return redirect('/login');
    }

    private static function size($n): string
    {
        $n = (int) $n;
        if ($n < 1024) return "$n B";
        if ($n < 1048576) return number_format($n / 1024, 1) . ' KB';
        return number_format($n / 1048576, 1) . ' MB';
    }
}
