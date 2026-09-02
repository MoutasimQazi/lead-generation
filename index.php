<?php
declare(strict_types=1);

/**
 * Front controller for /api/*.
 *
 * Static pages (index.html, login.html, …) are served directly by Apache; only
 * API paths are rewritten here by .htaccess.
 */

// Single-folder layout: app/ sits inside the document root, protected by
// .htaccess rather than by being above it. See DEPLOY.md.
$appRoot = __DIR__ . '/app';

require_once $appRoot . '/config.php';
require_once $appRoot . '/http.php';

install_error_handlers();

require_once $appRoot . '/auth.php';
require_once $appRoot . '/migrations.php';
require_once $appRoot . '/routes/auth_routes.php';
require_once $appRoot . '/routes/search.php';
require_once $appRoot . '/routes/folders.php';
require_once $appRoot . '/routes/uploads.php';
require_once $appRoot . '/routes/datasets.php';
require_once $appRoot . '/routes/users.php';
require_once $appRoot . '/routes/large_imports.php';
require_once $appRoot . '/routes/chunk_uploads.php';
require_once $appRoot . '/lib/audit.php';

/**
 * Path of the current request, relative to wherever the app is installed.
 * Supports deployment into a subdirectory as well as a document root.
 */
function request_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');

    if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }

    return '/' . trim($path, '/');
}

function route_audit_list(): never
{
    require_admin();

    $datasetId = filter_input(INPUT_GET, 'dataset_id', FILTER_VALIDATE_INT) ?: null;

    json_ok(['entries' => array_map(static fn($e) => [
        'id'         => (int) $e['id'],
        'user_email' => $e['user_email'],
        'action'     => $e['action'],
        'dataset_id' => $e['dataset_id'] === null ? null : (int) $e['dataset_id'],
        'detail'     => json_decode((string) $e['detail'], true),
        'created_at' => $e['created_at'],
    ], audit_recent(query_int('limit', 100, 1, 500), $datasetId))]);
}

/**
 * Route table. More specific patterns must come first — the DELETE on
 * /rows (remove one file's rows) has to be matched before /rows/{rowId}.
 *
 * {id} and {row} capture integers; {name} captures a bare identifier;
 * {stage} captures the 32-hex staging id.
 */
function routes(): array
{
    return [
        ['POST',   '/api/auth/login',   'route_auth_login'],
        ['POST',   '/api/auth/logout',  'route_auth_logout'],
        ['GET',    '/api/auth/me',      'route_auth_me'],

        ['POST',   '/api/search',       'route_search'],

        ['GET',    '/api/folders',      'route_folders_list'],
        ['POST',   '/api/folders',      'route_folders_create'],
        ['PATCH',  '/api/folders/{id}', 'route_folders_update'],
        ['DELETE', '/api/folders/{id}', 'route_folders_delete'],

        ['POST',   '/api/uploads/stage',           'route_uploads_stage'],
        ['POST',   '/api/uploads/{stage}/commit',  'route_uploads_commit'],
        ['POST',   '/api/imports/tick',            'route_imports_tick'],
        ['GET',    '/api/large-imports',            'route_large_imports_list'],
        ['POST',   '/api/large-imports/{id}/queue', 'route_large_import_queue'],
        ['POST',   '/api/chunk-uploads/start',              'route_chunk_upload_start'],
        ['POST',   '/api/chunk-uploads/{stage}/chunks/{row}', 'route_chunk_upload_part'],
        ['POST',   '/api/chunk-uploads/{stage}/complete',   'route_chunk_upload_complete'],

        ['GET',    '/api/datasets',                  'route_datasets_list'],
        ['GET',    '/api/datasets/{id}',             'route_datasets_get'],
        ['GET',    '/api/datasets/{id}/filter-options', 'route_dataset_filter_options'],
        ['PATCH',  '/api/datasets/{id}',             'route_datasets_update'],
        ['GET',    '/api/datasets/{id}/assignments', 'route_dataset_assignments_get'],
        ['PATCH',  '/api/datasets/{id}/assignments', 'route_dataset_assignments_update'],
        ['PATCH',  '/api/datasets/{id}/search-preference', 'route_dataset_search_preference'],
        ['DELETE', '/api/datasets/{id}',             'route_datasets_delete'],
        ['GET',    '/api/datasets/{id}/export',      'route_dataset_export'],

        ['GET',    '/api/datasets/{id}/rows',        'route_rows_list'],
        ['POST',   '/api/datasets/{id}/rows',        'route_rows_create'],
        ['DELETE', '/api/datasets/{id}/rows',        'route_rows_delete_by_file'],
        ['PATCH',  '/api/datasets/{id}/rows/{row}',  'route_rows_update'],
        ['DELETE', '/api/datasets/{id}/rows/{row}',  'route_rows_delete'],

        ['POST',   '/api/datasets/{id}/columns',          'route_columns_create'],
        ['PATCH',  '/api/datasets/{id}/columns/{name}',   'route_columns_update'],
        ['DELETE', '/api/datasets/{id}/columns/{name}',   'route_columns_delete'],

        ['GET',    '/api/users',                  'route_users_list'],
        ['POST',   '/api/users',                  'route_users_create'],
        ['PATCH',  '/api/users/{id}',              'route_users_update'],
        ['DELETE', '/api/users/{id}',              'route_users_delete'],
        ['GET',    '/api/users/{id}/assignments', 'route_user_assignments_get'],
        ['PATCH',  '/api/users/{id}/assignments', 'route_user_assignments_update'],

        ['GET',    '/api/audit',      'route_audit_list'],
    ];
}

function pattern_to_regex(string $pattern): string
{
    $regex = preg_quote($pattern, '#');

    $regex = str_replace(
        ['\{id\}', '\{row\}', '\{name\}', '\{stage\}'],
        ['(?P<id>\d{1,10})', '(?P<row>\d{1,19})', '(?P<name>[a-z][a-z0-9_]{0,62})', '(?P<stage>[a-f0-9]{32})'],
        $regex
    );

    return '#^' . $regex . '$#';
}

function dispatch(): never
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path   = request_path();

    if ($method === 'OPTIONS') {
        // Same-origin only: there is no CORS policy here on purpose. The page
        // and the API are served from one host, so nothing needs preflighting.
        http_response_code(204);
        exit;
    }

    $pathMatched = false;

    foreach (routes() as [$routeMethod, $pattern, $handler]) {
        if (!preg_match(pattern_to_regex($pattern), $path, $m)) {
            continue;
        }

        $pathMatched = true;

        if ($routeMethod !== $method) {
            continue;
        }

        // setup.php locks after the first admin is created. Older deployments
        // can therefore have working authentication but none of the management
        // tables added later. Self-heal on the first authenticated request.
        if (preg_match('#^/api/(?:datasets|folders|uploads|imports|search)(?:/|$)#', $path)) {
            require_auth();
            ensure_management_schema();
        }

        $args = [];

        // Handler arguments must follow their order in the route pattern.
        // A fixed key order reversed {stage} and {row} for chunk uploads,
        // passing the numeric chunk index where the hexadecimal upload id was
        // expected.
        preg_match_all('/\{(id|row|name|stage)\}/', $pattern, $placeholders);
        foreach ($placeholders[1] as $key) {
            if (isset($m[$key]) && $m[$key] !== '') {
                $args[] = in_array($key, ['id', 'row'], true) ? (int) $m[$key] : $m[$key];
            }
        }

        $handler(...$args);
    }

    if ($pathMatched) {
        fail('That endpoint does not accept ' . $method . ' requests.', 405);
    }

    fail('No such endpoint: ' . $path, 404);
}

dispatch();
