<?php
declare(strict_types=1);

/**
 * Application exceptions carry an HTTP status and a machine-readable code, so
 * the single catch block in api/index.php can turn any of them into the same
 * JSON envelope the Node backend used — the frontend's error handling
 * (public/js/lib/api.js) never has to know the backend changed.
 */
class AppException extends Exception
{
    public int $status;
    // Named errorCode, not code: Exception already declares an untyped $code
    // property, and PHP 8.3+ fatals on a child class redeclaring it with a type.
    public string $errorCode;
    public $details;

    public function __construct(int $status, string $errorCode, string $message, $details = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }
}

function gv_fail(int $status, string $code, string $message, $details = null): void
{
    throw new AppException($status, $code, $message, $details);
}

function bad_request(string $message = 'Bad request', $details = null): AppException
{
    return new AppException(400, 'BAD_REQUEST', $message, $details);
}
function validation_error(array $details, string $message = 'Validation failed'): AppException
{
    return new AppException(422, 'VALIDATION_ERROR', $message, $details);
}
function unauthenticated(string $message = 'Authentication required'): AppException
{
    return new AppException(401, 'UNAUTHENTICATED', $message);
}
function forbidden(string $message = 'You do not have permission to perform this action'): AppException
{
    return new AppException(403, 'FORBIDDEN', $message);
}
function not_found(string $message = 'Resource not found'): AppException
{
    return new AppException(404, 'NOT_FOUND', $message);
}
function conflict(string $message = 'Conflict', $details = null): AppException
{
    return new AppException(409, 'CONFLICT', $message, $details);
}
function too_many(string $message = 'Too many requests'): AppException
{
    return new AppException(429, 'RATE_LIMITED', $message);
}
