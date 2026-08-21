<?php
declare(strict_types=1);

/**
 * Configuration: parses .env from the app root and validates it.
 *
 * Deliberately dependency-free — shared hosting cannot be relied on to have
 * composer available, and the whole config layer is about 100 lines.
 */

// The codebase uses `never` return types and readonly properties, both PHP 8.1.
// Checked here rather than letting it surface as a parse error, which on cPanel
// shows up as a blank white page with nothing useful in it.
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit(
        'This application needs PHP 8.1 or newer. This server is running ' . PHP_VERSION . '. '
        . 'Change it in cPanel under MultiPHP Manager.'
    );
}

/** Absolute path to the app root (the folder containing .env). */
function app_root(): string
{
    return dirname(__DIR__);
}

/**
 * Parses a .env file.
 *
 * Supports  KEY=value  /  KEY='value'  /  KEY="value".
 *
 * Single-quoted values are taken literally, which is what makes passwords
 * containing $, [, ^ or # survive intact — the DB password for this app needs
 * exactly that, so do not "simplify" this by stripping quotes blindly.
 */
function parse_env_file(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }

    $out   = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));

        if ($key === '') {
            continue;
        }

        if (strlen($val) >= 2 && $val[0] === "'" && str_ends_with($val, "'")) {
            // Literal: no escape processing, no trailing-comment stripping.
            $val = substr($val, 1, -1);
        } elseif (strlen($val) >= 2 && $val[0] === '"' && str_ends_with($val, '"')) {
            $val = substr($val, 1, -1);
            $val = str_replace(['\\n', '\\r', '\\"', '\\\\'], ["\n", "\r", '"', '\\'], $val);
        } else {
            // Unquoted: an unescaped # starts a trailing comment.
            $hash = strpos($val, ' #');
            if ($hash !== false) {
                $val = rtrim(substr($val, 0, $hash));
            }
        }

        $out[$key] = $val;
    }

    return $out;
}

/**
 * Returns the whole config array, or one key from it.
 * Throws on first call if anything required is missing or nonsensical.
 */
function config(?string $key = null, mixed $default = null): mixed
{
    static $cfg = null;

    if ($cfg === null) {
        $cfg = build_config();
    }

    if ($key === null) {
        return $cfg;
    }

    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

/**
 * Built-in settings.
 *
 * These are the live values, so the app runs with no .env file at all — which
 * is what makes deployment on cPanel a straight file copy.
 *
 * A .env file, or a real environment variable, still overrides anything here
 * (see $get below), so a different install does not need this file edited.
 *
 * ── Two consequences worth knowing ──────────────────────────────────────────
 *  1. The database password is in source control. This repo has a GitHub
 *     remote, so the repository must be PRIVATE. If it is public, or ever was,
 *     treat this password as compromised and rotate it.
 *  2. app/ must stay unreachable from the web. It ships with a deny-all
 *     .htaccess, and the site root .htaccess blocks app/ as well. PHP files are
 *     executed rather than shown, so a working server does not reveal this —
 *     but a server with PHP misconfigured would serve it as plain text.
 */
const BUILTIN_CONFIG = [
    'APP_ENV'  => 'production',
    'APP_URL'  => 'https://lead.moveneticsdigital.com',

    'DB_HOST'     => 'localhost',
    'DB_PORT'     => '3306',
    'DB_NAME'     => 'movenetics_lead',
    'DB_USER'     => 'movenetics_moutasim',
    'DB_PASSWORD' => '@Iv+rUv_^$EeSm[Q',

    // Name of the pre-existing leads table. Blank if it does not exist yet.
    'LEADS_TABLE' => 'leads',

    // Signs the session cookie. Changing it signs everyone out.
    'SESSION_SECRET' => '9d12bc3c676fde3543b46e389a6a1c58ee8a868d8401fdc76281e76ce6d707194ad28b84aa68691df6ccbd10ee1de681',
    'SESSION_HOURS'  => '12',

    'N8N_WEBHOOK_URL'     => 'https://n8n.moveneticsdigital.com/webhook/lead-search-v2',
    'N8N_API_KEY'         => '',
    'N8N_TIMEOUT_SECONDS' => '120',

    'UPLOAD_DIR'              => './var/uploads',
    'MAX_UPLOAD_MB'           => '250',
    'IMPORT_BATCH_SIZE'       => '500',
    'IMPORT_ROWS_PER_REQUEST' => '20000',
    'INFER_SAMPLE_ROWS'       => '500',
];

function build_config(): array
{
    $env = parse_env_file(app_root() . '/.env');

    // Precedence: real environment variable, then .env, then the built-in value.
    // Nothing is "required" any more — the built-ins always answer — so a
    // missing .env is not an error, just the default install.
    $get = static function (string $k, ?string $fallback = null) use ($env): ?string {
        $v = getenv($k);
        if ($v !== false && $v !== '' && !config_placeholder($v)) {
            return $v;
        }
        if (isset($env[$k]) && $env[$k] !== '' && !config_placeholder($env[$k])) {
            return $env[$k];
        }
        if (isset(BUILTIN_CONFIG[$k]) && BUILTIN_CONFIG[$k] !== '') {
            return BUILTIN_CONFIG[$k];
        }
        return $fallback;
    };

    $secret = (string) $get('SESSION_SECRET');

    if (strlen($secret) < 32) {
        throw new RuntimeException(
            'SESSION_SECRET must be at least 32 characters. Generate one with: ' .
            'php -r "echo bin2hex(random_bytes(48));"'
        );
    }

    $uploadDir = (string) $get('UPLOAD_DIR', './var/uploads');
    if (!str_starts_with($uploadDir, '/') && !preg_match('/^[A-Za-z]:/', $uploadDir)) {
        $uploadDir = app_root() . '/' . ltrim($uploadDir, './');
    }

    return [
        'app_env'  => $get('APP_ENV', 'production'),
        'app_url'  => rtrim((string) $get('APP_URL', ''), '/'),
        'is_dev'   => $get('APP_ENV', 'production') === 'development',

        'db_host'  => (string) $get('DB_HOST'),
        'db_port'  => (int) $get('DB_PORT', '3306'),
        'db_name'  => (string) $get('DB_NAME'),
        'db_user'  => (string) $get('DB_USER'),
        'db_pass'  => (string) $get('DB_PASSWORD'),

        'leads_table'    => trim((string) $get('LEADS_TABLE', '')),
        'session_secret' => $secret,
        'session_hours'  => max(1, (int) $get('SESSION_HOURS', '12')),

        'n8n_url'     => (string) $get('N8N_WEBHOOK_URL', ''),
        'n8n_key'     => (string) $get('N8N_API_KEY', ''),
        'n8n_timeout' => max(5, (int) $get('N8N_TIMEOUT_SECONDS', '120')),

        'upload_dir'        => $uploadDir,
        'max_upload_mb'     => max(1, (int) $get('MAX_UPLOAD_MB', '250')),
        'import_batch'      => max(50, (int) $get('IMPORT_BATCH_SIZE', '500')),
        'import_per_request' => max(500, (int) $get('IMPORT_ROWS_PER_REQUEST', '20000')),
        'infer_sample'      => max(20, (int) $get('INFER_SAMPLE_ROWS', '500')),
    ];
}

/** A copied .env.example must not replace live built-in settings. */
function config_placeholder(string $value): bool
{
    return in_array(trim($value), ['replace-me', 'replace-me-with-96-hex-chars'], true);
}
