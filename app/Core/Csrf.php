<?php
namespace App\Core;

/**
 * Protects every POST against cross-site request forgery.
 * Forms include <?= csrf_field() ?>; JavaScript sends the X-CSRF-Token header (see assets/js/app.js).
 */
class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }

    public static function verify(Request $req): void
    {
        $sent = $req->input('_token') ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($sent) || !hash_equals(self::token(), $sent)) {
            throw new HttpException(419, 'Your form expired. Please reload the page and try again.');
        }
    }
}
