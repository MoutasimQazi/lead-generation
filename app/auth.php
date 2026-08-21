<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';

/**
 * Sessions, roles and CSRF.
 *
 * Two rules this file exists to enforce:
 *   1. Role is checked on the server for every request. The frontend hides
 *      admin nav items, but hiding is decoration — this is the access control.
 *   2. The account is re-read from the database on each request, so
 *      deactivating a user takes effect on their next call rather than
 *      whenever their session happens to expire.
 */

const SESSION_COOKIE = 'movenetics_sid';
const MAX_LOGIN_ATTEMPTS = 10;
const LOGIN_WINDOW_MINUTES = 15;

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    session_name(SESSION_COOKIE);

    session_set_cookie_params([
        'lifetime' => config('session_hours') * 3600,
        'path'     => '/',
        'domain'   => '',
        // Secure is conditional so the app still works over plain HTTP while
        // you are setting it up; in production APP_URL is https and this is on.
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Refuses to adopt a session id the server never issued.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) (config('session_hours') * 3600));

    session_start();
}

/** The logged-in user as a row from app_users, or null. */
function current_user(): ?array
{
    static $user = null;
    static $looked = false;

    if ($looked) {
        return $user;
    }

    $looked = true;
    session_boot();

    $id = $_SESSION['uid'] ?? null;

    if (!$id) {
        return $user = null;
    }

    $row = db_one(
        'SELECT id, email, full_name, role, is_active FROM app_users WHERE id = ?',
        [(int) $id]
    );

    // Deleted or deactivated mid-session: drop the session immediately.
    if (!$row || (int) $row['is_active'] !== 1) {
        auth_logout();
        return $user = null;
    }

    return $user = $row;
}

function require_auth(): array
{
    $user = current_user();

    if (!$user) {
        fail('You are not signed in.', 401);
    }

    return $user;
}

function require_admin(): array
{
    $user = require_auth();

    if ($user['role'] !== 'admin') {
        fail('That action is restricted to administrators.', 403);
    }

    return $user;
}

/* ── login ─────────────────────────────────────────────────────────────── */

function login_attempts_recent(string $ip): int
{
    // The window is interpolated, not bound: MariaDB is inconsistent about
    // accepting a placeholder as an INTERVAL operand under native prepared
    // statements, and a throw here would 500 every single login attempt.
    // LOGIN_WINDOW_MINUTES is a code constant, so there is no injection surface.
    $window = (int) LOGIN_WINDOW_MINUTES;

    return (int) db_value(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip = ? AND attempted_at > (NOW() - INTERVAL ' . $window . ' MINUTE)',
        [$ip],
        0
    );
}

function login_attempt_record(string $ip, string $email, bool $ok): void
{
    db_exec(
        'INSERT INTO login_attempts (ip, email, succeeded) VALUES (?, ?, ?)',
        [$ip, mb_substr($email, 0, 190), $ok ? 1 : 0]
    );

    // Opportunistic cleanup so the table cannot grow without bound on a host
    // where no cron is set up.
    if (random_int(1, 50) === 1) {
        db_exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    }
}

/**
 * Verifies credentials and starts a session.
 * Returns the user row, or throws an ApiError the caller can surface as-is.
 */
function auth_login(string $email, string $password): array
{
    $ip = client_ip();

    if (login_attempts_recent($ip) >= MAX_LOGIN_ATTEMPTS) {
        fail(
            'Too many sign-in attempts. Wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.',
            429
        );
    }

    $email = strtolower(trim($email));
    $row   = db_one('SELECT * FROM app_users WHERE email = ?', [$email]);

    $verified = false;

    if ($row) {
        $verified = password_verify($password, $row['password_hash']);
    } else {
        // Hash anyway so a missing account and a wrong password take a similar
        // amount of time, and the response cannot be used to enumerate users.
        password_verify($password, '$2y$12$usesomesillystringforsalt0000000000000000000000000000000');
    }

    if (!$row || !$verified || (int) $row['is_active'] !== 1) {
        login_attempt_record($ip, $email, false);
        // One message for every failure mode — wrong password, no such account,
        // deactivated. Distinguishing them tells an attacker which emails exist.
        fail('That email and password did not match.', 401);
    }

    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE app_users SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), (int) $row['id']]);
    }

    login_attempt_record($ip, $email, true);

    session_boot();
    // New id on privilege change, so a fixed pre-login cookie is worthless.
    session_regenerate_id(true);

    $_SESSION['uid']  = (int) $row['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    db_exec('UPDATE app_users SET last_login_at = NOW() WHERE id = ?', [(int) $row['id']]);

    return [
        'id'        => (int) $row['id'],
        'email'     => $row['email'],
        'full_name' => $row['full_name'],
        'role'      => $row['role'],
    ];
}

function auth_logout(): void
{
    session_boot();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/* ── CSRF ──────────────────────────────────────────────────────────────── */

function csrf_token(): string
{
    session_boot();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

/**
 * Guards every state-changing request.
 *
 * Session cookies ride along automatically on cross-site requests, so without
 * this a page on another domain could make an admin's browser POST here.
 * SameSite=Lax already blocks most of it; this covers the rest.
 */
function require_csrf(): void
{
    session_boot();

    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $held = $_SESSION['csrf'] ?? '';

    if (!is_string($sent) || $held === '' || !hash_equals($held, $sent)) {
        fail('Your session has expired. Reload the page and try again.', 419);
    }

    // Belt and braces: reject an obviously foreign Origin when one is present.
    // Compares scheme + host only, allowing for common variations like trailing
    // slashes or non-standard ports that proxies might introduce.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $appUrl = config('app_url');

    if ($origin !== '' && $appUrl !== '') {
        $originParts = parse_url($origin);
        $appParts = parse_url($appUrl);

        $originHost = ($originParts['scheme'] ?? '') . '://' . ($originParts['host'] ?? '');
        $appHost = ($appParts['scheme'] ?? '') . '://' . ($appParts['host'] ?? '');

        if ($originHost !== $appHost) {
            fail('Request origin not allowed.', 403);
        }
    }
}
