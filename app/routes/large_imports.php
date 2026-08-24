<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/large_importer.php';

function route_large_imports_list(): never
{
    require_admin();
    ensure_management_schema();
    $jobs = array_map(static fn(array $j): array => [
        'id' => (int) $j['id'],
        'file_name' => $j['file_name'],
        'file_size' => (int) $j['file_size'],
        'dataset_id' => $j['dataset_id'] === null ? null : (int) $j['dataset_id'],
        'dataset_name' => $j['dataset_name'],
        'status' => $j['status'],
        'rows_done' => (int) $j['rows_done'],
        'error' => $j['error_message'],
        'created_at' => $j['created_at'],
    ], large_import_jobs());

    json_ok(['jobs' => $jobs, 'inbox' => 'var/inbox']);
}

function route_large_import_queue(int $id): never
{
    $user = require_admin();
    require_csrf();
    ensure_management_schema();

    try {
        large_import_queue($id, (int) $user['id']);
    } catch (RuntimeException $e) {
        fail($e->getMessage(), 422);
    }

    json_ok(['queued' => true]);
}
