<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../migrations.php';
require_once __DIR__ . '/../lib/large_importer.php';

const CHUNK_MIN_BYTES = 5 * 1024 * 1024;
const CHUNK_MAX_BYTES = 25 * 1024 * 1024;
const CHUNK_FILE_MAX_BYTES = 2 * 1024 * 1024 * 1024;

function chunk_upload_root(): string
{
    $dir = (string) config('chunk_upload_dir');
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the chunk-upload directory.');
    }
    return rtrim(str_replace('\\', '/', $dir), '/');
}

function chunk_upload_dir(string $id): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        fail('Invalid chunk-upload id.', 422);
    }
    return chunk_upload_root() . '/' . $id;
}

function chunk_upload_cleanup(): void
{
    $stale = db_all(
        'SELECT id FROM chunk_uploads
          WHERE status IN ("uploading", "failed")
            AND updated_at < (NOW() - INTERVAL 1 DAY)'
    );
    foreach ($stale as $row) {
        $dir = chunk_upload_dir((string) $row['id']);
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
        db_exec('DELETE FROM chunk_uploads WHERE id = ?', [$row['id']]);
    }
}

function chunk_upload_owned(string $id, int $userId): array
{
    $row = db_one('SELECT * FROM chunk_uploads WHERE id = ? AND user_id = ?', [$id, $userId]);
    if (!$row) {
        fail('That resumable upload does not exist.', 404);
    }
    return $row;
}

function chunk_received_indices(string $id): array
{
    $indices = [];
    foreach (glob(chunk_upload_dir($id) . '/*.part') ?: [] as $path) {
        $name = pathinfo($path, PATHINFO_FILENAME);
        if (ctype_digit($name)) {
            $indices[] = (int) $name;
        }
    }
    sort($indices, SORT_NUMERIC);
    return $indices;
}

function route_chunk_upload_start(): never
{
    $user = require_admin();
    require_csrf();
    ensure_management_schema();
    chunk_upload_cleanup();

    $name = basename((string) body_string('file_name', '', 255));
    $size = body_int('file_size', 0) ?? 0;
    $mtime = body_int('file_mtime', 0) ?? 0;
    $chunkSize = body_int('chunk_size', 10 * 1024 * 1024) ?? 0;
    $total = body_int('total_chunks', 0) ?? 0;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($name === '' || !in_array($ext, ['csv', 'xlsx'], true)) {
        fail('Choose a CSV or XLSX file.', 422);
    }
    if ($size <= 0 || $chunkSize < CHUNK_MIN_BYTES || $chunkSize > CHUNK_MAX_BYTES
        || $total !== (int) ceil($size / $chunkSize)) {
        fail('The chunk-upload metadata is invalid.', 422);
    }
    if ($size > CHUNK_FILE_MAX_BYTES) {
        fail('Large browser uploads are limited to 2 GB per file.', 413);
    }

    $free = @disk_free_space(chunk_upload_root());
    // XLSX is compressed XML and can expand several times while conversion
    // also maintains disk-backed shared strings and the final CSV.
    $needed = $size * ($ext === 'xlsx' ? 5.0 : 2.0) + 1024 * 1024 * 1024;
    if ($free !== false && $free < $needed) {
        fail('The server does not have enough free disk space for this upload and its working copy.', 507);
    }

    $existing = db_one(
        'SELECT * FROM chunk_uploads
          WHERE user_id = ? AND file_name = ? AND file_size = ? AND file_mtime = ? AND chunk_size = ?
            AND status IN ("uploading", "assembling", "ready")
          ORDER BY created_at DESC LIMIT 1',
        [(int) $user['id'], $name, $size, $mtime, $chunkSize]
    );

    if ($existing) {
        json_ok([
            'upload_id' => $existing['id'],
            'received' => $existing['status'] === 'uploading'
                ? chunk_received_indices((string) $existing['id']) : [],
            'status' => $existing['status'],
            'complete' => $existing['status'] !== 'uploading',
        ]);
    }

    $id = bin2hex(random_bytes(16));
    $dir = chunk_upload_dir($id);
    if (!@mkdir($dir, 0770, true) && !is_dir($dir)) {
        fail('Could not create resumable upload storage.', 500);
    }
    db_exec(
        'INSERT INTO chunk_uploads
           (id, user_id, file_name, file_size, file_mtime, chunk_size, total_chunks)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$id, (int) $user['id'], $name, $size, $mtime, $chunkSize, $total]
    );
    json_ok(['upload_id' => $id, 'received' => []]);
}

function route_chunk_upload_part(string $stage, int $row): never
{
    $user = require_admin();
    require_csrf();
    $upload = chunk_upload_owned($stage, (int) $user['id']);
    if ($upload['status'] !== 'uploading' || $row < 0 || $row >= (int) $upload['total_chunks']) {
        fail('That chunk is not expected.', 409);
    }

    $expected = $row === (int) $upload['total_chunks'] - 1
        ? (int) $upload['file_size'] - ($row * (int) $upload['chunk_size'])
        : (int) $upload['chunk_size'];
    $dir = chunk_upload_dir($stage);
    $target = $dir . '/' . $row . '.part';
    if (is_file($target) && (int) filesize($target) === $expected) {
        json_ok(['received' => true, 'index' => $row]);
    }

    $tmp = $target . '.tmp';
    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    if (!$in || !$out) {
        fail('Could not open chunk storage.', 500);
    }
    $written = stream_copy_to_stream($in, $out, $expected + 1);
    fclose($in);
    fclose($out);
    if ($written !== $expected) {
        @unlink($tmp);
        fail('The uploaded chunk size did not match the expected size.', 422);
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        fail('Could not finalize the uploaded chunk.', 500);
    }

    $indices = chunk_received_indices($stage);
    $bytes = 0;
    foreach ($indices as $index) {
        $bytes += (int) filesize($dir . '/' . $index . '.part');
    }
    db_exec(
        'UPDATE chunk_uploads SET received_chunks = ?, received_bytes = ? WHERE id = ?',
        [count($indices), $bytes, $stage]
    );
    json_ok(['received' => true, 'index' => $row, 'received_bytes' => $bytes]);
}

function route_chunk_upload_complete(string $stage): never
{
    $user = require_admin();
    require_csrf();
    $upload = chunk_upload_owned($stage, (int) $user['id']);
    if ($upload['status'] === 'ready') {
        json_ok(['queued' => true]);
    }
    if ($upload['status'] !== 'uploading') {
        fail('That upload cannot be completed.', 409);
    }

    $indices = chunk_received_indices($stage);
    if (count($indices) !== (int) $upload['total_chunks']) {
        fail('Some upload chunks are still missing.', 409);
    }

    db_exec('UPDATE chunk_uploads SET status = "assembling" WHERE id = ?', [$stage]);
    json_ok(['queued' => true, 'assembling' => true]);
}

/** Assembles one completed browser upload from the CLI worker, never PHP-FPM. */
function chunk_upload_assemble_next(): ?array
{
    $upload = db_one('SELECT * FROM chunk_uploads WHERE status = "assembling" ORDER BY created_at LIMIT 1');
    if (!$upload) {
        return null;
    }

    $stage = (string) $upload['id'];
    $dir = chunk_upload_dir($stage);
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) $upload['file_name'])) ?: 'upload.csv';
    $target = large_import_root() . '/' . $stage . '-' . $safeName;
    $tmp = $target . '.assembling';
    $out = fopen($tmp, 'wb');
    if (!$out) {
        throw new RuntimeException('Could not create the assembled inbox file.');
    }

    $assembled = 0;
    try {
        for ($i = 0; $i < (int) $upload['total_chunks']; $i++) {
            $in = fopen($dir . '/' . $i . '.part', 'rb');
            if (!$in) {
                throw new RuntimeException('A chunk disappeared during assembly.');
            }
            $copied = stream_copy_to_stream($in, $out);
            fclose($in);
            if ($copied === false) {
                throw new RuntimeException('Could not assemble an uploaded chunk.');
            }
            $assembled += $copied;
        }
        fclose($out);
        if ($assembled !== (int) $upload['file_size'] || !@rename($tmp, $target)) {
            throw new RuntimeException('The completed file failed size verification.');
        }
        $jobId = large_import_register_file($target, (string) $upload['file_name'], (int) $upload['user_id']);
        db_exec('UPDATE chunk_uploads SET status = "ready" WHERE id = ?', [$stage]);
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
        return ['upload_id' => $stage, 'job_id' => $jobId, 'file_name' => $upload['file_name']];
    } catch (Throwable $e) {
        if (is_resource($out)) {
            fclose($out);
        }
        @unlink($tmp);
        db_exec(
            'UPDATE chunk_uploads SET status = "failed", error_message = ? WHERE id = ?',
            [mb_substr($e->getMessage(), 0, 1000), $stage]
        );
        throw $e;
    }
}
