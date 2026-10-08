<?php
namespace App\Core;

/** What a controller returns. Use the helpers view(), redirect(), json() instead of `new Response`. */
class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = []
    ) {
    }

    public static function json($data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $to): self
    {
        return new self('', 302, ['Location' => url($to)]);
    }

    public static function download(string $content, string $filename, string $mime): self
    {
        return new self($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^\w.\-]+/', '_', $filename) . '"',
            'Content-Length' => (string) strlen($content),
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) header("$k: $v");
        echo $this->body;
    }
}
