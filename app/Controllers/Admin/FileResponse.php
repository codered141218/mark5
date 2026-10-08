<?php
namespace App\Controllers\Admin;

use App\Core\Response;

/** Sends a file from disk as a download without loading it into memory (backups can be large). */
class FileResponse extends Response
{
    public function __construct(private string $file, string $filename, string $mime = 'application/octet-stream')
    {
        parent::__construct('', 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^\w.\-]+/', '_', $filename) . '"',
            'Content-Length' => (string) filesize($file),
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) header("$k: $v");
        while (ob_get_level()) ob_end_clean();
        readfile($this->file);
    }
}
