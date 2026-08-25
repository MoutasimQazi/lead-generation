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

    $uploads = array_map(static fn(array $u): array => [
        'id' => $u['id'],
        'file_name' => $u['file_name'],
        'file_size' => (int) $u['file_size'],
        'received_bytes' => (int) $u['received_bytes'],
        'status' => $u['status'],
        'error' => $u['error_message'],
    ], db_all(
        'SELECT id, file_name, file_size, received_bytes, status, error_message
           FROM chunk_uploads
          WHERE status <> "ready"
          ORDER BY created_at DESC LIMIT 50'
    ));

    json_ok(['jobs' => $jobs, 'uploads' => $uploads, 'inbox' => 'var/inbox']);
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
