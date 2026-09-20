<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Request/response helpers for the JSON API.
 *
 * Every API failure leaves here as JSON, because the frontend's fetch wrapper
 * parses the body to show an error. An HTML error page from PHP would surface
 * to the user as "the webhook did not return JSON", which is misleading.
 */

final class ApiError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly array $extra = []
    ) {
        parent::__construct($message);
    }
}

function json_out(mixed $data, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function json_ok(array $data = []): never
{
    json_out(['success' => true] + $data);
}

function fail(string $message, int $status = 400, array $extra = []): never
{
    throw new ApiError($message, $status, $extra);
}

/** Decodes the JSON request body, or throws if it is not an object. */
function json_body(): array
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return $cached = [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        fail('Request body must be a JSON object.', 400);
    }

    return $cached = $decoded;
}

function body_string(string $key, ?string $default = null, int $maxLen = 4000): ?string
{
    $body = json_body();

    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return $default;
    }

    if (!is_scalar($body[$key])) {
        fail("Field '$key' must be text.", 422);
    }

    $v = trim((string) $body[$key]);

    if (mb_strlen($v) > $maxLen) {
        fail("Field '$key' is too long (max $maxLen characters).", 422);
    }

    return $v;
}

function body_int(string $key, ?int $default = null): ?int
{
    $body = json_body();

    if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
        return $default;
    }

    if (!is_numeric($body[$key])) {
        fail("Field '$key' must be a number.", 422);
    }

    return (int) $body[$key];
}

function body_bool(string $key, bool $default = false): bool
{
    $body = json_body();

    if (!array_key_exists($key, $body)) {
        return $default;
    }

    return filter_var($body[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

function body_array(string $key): array
{
    $body = json_body();

    if (!isset($body[$key]) || !is_array($body[$key])) {
        fail("Field '$key' must be a list.", 422);
    }

    return $body[$key];
}

function query_int(string $key, int $default, int $min, int $max): int
{
    $v = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);

    if ($v === false || $v === null) {
        return $default;
    }

    return max($min, min($max, $v));
}

function query_string(string $key, string $default = '', int $maxLen = 400): string
{
    $v = $_GET[$key] ?? $default;
    $v = is_scalar($v) ? trim((string) $v) : $default;

    return mb_substr($v, 0, $maxLen);
}

function client_ip(): string
{
    // cPanel sits behind Apache on the same host, so REMOTE_ADDR is the real
    // client. Only consult forwarding headers if a proxy is genuinely in front,
    // otherwise a client can spoof its own IP and defeat rate limiting.
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Installs handlers so that any uncaught throw or fatal becomes a JSON error
 * rather than a blank 500 or, worse, a stack trace on a production page.
 */
function install_error_handlers(): void
{
    set_exception_handler(static function (Throwable $e): void {
        if ($e instanceof ApiError) {
            json_out(['success' => false, 'error' => $e->getMessage()] + $e->extra, $e->status);
        }

        $errorId = substr(hash('sha256', microtime(true) . ':' . getmypid() . ':' . $e->getMessage()), 0, 12);

        error_log(
            '[lead-site][' . $errorId . '] ' . $e::class . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine()
        );

        $payload = [
            'success'  => false,
            'error'    => 'Something went wrong on the server. Reference: ' . $errorId,
            'error_id' => $errorId,
        ];

        // config() itself throws when .env is missing or invalid. Consulting it
        // unguarded here would throw from inside the exception handler, which
        // PHP turns into an unreadable fatal — exactly when a clear message
        // matters most. So the misconfiguration case is reported directly.
        try {
            $user = function_exists('current_user') ? current_user() : null;
            $admin = is_array($user) && ($user['role'] ?? '') === 'admin';

            if (config('is_dev') || $admin) {
                // The API is same-origin and current_user() revalidates the
                // session against app_users. Giving an authenticated admin the
                // DB/PHP exception makes production faults diagnosable without
                // exposing internals to employees or anonymous visitors.
                $payload['error']  = 'Server error: ' . $e->getMessage();
                $payload['detail'] = $e->getMessage();
                $payload['where']  = $e->getFile() . ':' . $e->getLine();
            }
        } catch (Throwable) {
            $payload['error'] = 'The server is not configured yet: ' . $e->getMessage();
        }

        json_out($payload, 500);
    });

    register_shutdown_function(static function (): void {
        $err = error_get_last();

        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log('[lead-site] fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);

            if (!headers_sent()) {
                $isStaging = str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/uploads/stage');
                $message = $isStaging
                    ? 'The server ran out of resources while reading the upload. '
                        . 'For a large spreadsheet, save it as CSV and upload it again; '
                        . 'also try uploading one file at a time.'
                    : 'The server ran out of resources while processing that request. '
                        . 'Try again, or ask an administrator to check the PHP error log.';

                json_out([
                    'success' => false,
                    'error'   => $message,
                ], 500);
            }
        }
    });
}
