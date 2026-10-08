<?php
namespace App\Core;

/**
 * Throw from anywhere to stop the request with a message.
 *   throw HttpException::bad('Quantity is required');   // 400 - shown to the user
 *   throw HttpException::forbidden();                     // 403
 *   throw HttpException::notFound('Item');                // 404
 * For normal form posts the message is flashed and the user is sent back to the form;
 * for /api requests a JSON {"error": "..."} is returned.
 */
class HttpException extends \RuntimeException
{
    public int $status;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public static function bad(string $message): self
    {
        return new self(400, $message);
    }

    public static function forbidden(string $message = 'You do not have permission for this function'): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $what = 'Record'): self
    {
        return new self(404, "$what not found");
    }
}
