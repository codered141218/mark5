<?php
namespace App\Core;

/** The current HTTP request: form fields, query string and JSON bodies in one place. */
class Request
{
    public string $method;
    public string $path;
    private array $query;
    private array $body;
    private array $files;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim(config('app.base_path', ''), '/');
        if ($base !== '' && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));
        $this->path = '/' . trim($uri, '/');
        $this->query = $_GET;
        $this->files = $_FILES;
        $this->body = $_POST;
        $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($ctype, 'application/json')) {
            $json = json_decode(file_get_contents('php://input') ?: '[]', true);
            $this->body = is_array($json) ? $json : [];
        }
    }

    /** Value from the POST/JSON body, falling back to the query string. */
    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        return $f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK ? $f : null;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    public function wantsJson(): bool
    {
        return $this->isApi() || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}
