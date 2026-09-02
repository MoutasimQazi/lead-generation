<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../migrations.php';
require_once __DIR__ . '/identifiers.php';
require_once __DIR__ . '/inference.php';
require_once __DIR__ . '/importer.php';
require_once __DIR__ . '/reader.php';

/** SFTP inbox for files too large to send through PHP-FPM. */
function large_import_root(): string
{
    $dir = (string) config('large_import_dir');
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the large-import inbox.');
    }
    return rtrim(str_replace('\\', '/', $dir), '/');
}

function large_archive_root(): string
{
    $dir = (string) config('large_archive_dir');
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the completed-import archive.');
    }
    return rtrim(str_replace('\\', '/', $dir), '/');
}

/** Registers stable CSV files found in the inbox. Files still being copied are skipped. */
function large_import_scan(): int
{
    $root = large_import_root();
    $added = 0;

    foreach (glob($root . '/*') ?: [] as $path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!is_file($path) || !in_array($ext, ['csv', 'xlsx'], true)) {
            continue;
        }

        $name = basename($path);
        $size = (int) filesize($path);
        $mtime = (int) filemtime($path);

        // SFTP updates mtime while writing. Waiting 60 seconds prevents the
        // worker from opening a partially transferred multi-gigabyte file.
        if ($size <= 0 || $mtime > time() - 60) {
            continue;
        }

        $exists = db_one(
            'SELECT id FROM bulk_import_jobs
              WHERE file_path = ? OR (file_name = ? AND file_size = ? AND file_mtime = ?)',
            [str_replace('\\', '/', $path), $name, $size, $mtime]
        );
        if ($exists) {
            continue;
        }

        $inserted = db_exec(
            'INSERT IGNORE INTO bulk_import_jobs (file_name, file_path, file_size, file_mtime)
             VALUES (?, ?, ?, ?)',
            [$name, str_replace('\\', '/', $path), $size, $mtime]
        );
        $added += $inserted > 0 ? 1 : 0;
    }

    return $added;
}

function large_import_register_file(string $path, string $displayName, int $userId): int
{
    $path = large_import_safe_path($path);
    $ext = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx'], true)) {
        throw new RuntimeException('Large background imports support CSV and XLSX files only.');
    }

    $size = (int) filesize($path);
    $mtime = (int) filemtime($path);
    $existing = db_one('SELECT id FROM bulk_import_jobs WHERE file_path = ?', [$path]);
    if ($existing) {
        return (int) $existing['id'];
    }

    db_exec(
        'INSERT INTO bulk_import_jobs
           (file_name, file_path, file_size, file_mtime, queued_by, status)
         VALUES (?, ?, ?, ?, ?, "queued")',
        [mb_substr(basename($displayName), 0, 255), $path, $size, $mtime, $userId]
    );
    return db_insert_id();
}

function large_import_jobs(): array
{
    large_import_scan();
    large_import_recover_stale_failures();
    return db_all(
        'SELECT b.*, d.display_name AS dataset_name
           FROM bulk_import_jobs b
           LEFT JOIN datasets d ON d.id = b.dataset_id
          ORDER BY b.id DESC LIMIT 200'
    );
}

/**
 * Clears jobs stuck "failed" by the stale-$bulk archiving bug fixed above
 * (see large_import_work()): the row's error is left over from a failed
 * best-effort archive step, but the dataset it points to actually finished
 * importing and is "ready". Re-attempts the archive using the row's current
 * (correct) file_path, then always clears the stale failure either way —
 * the data already made it in, so this error no longer describes anything
 * wrong.
 */
function large_import_recover_stale_failures(): void
{
    $stuck = db_all(
        "SELECT b.id, b.file_path FROM bulk_import_jobs b
           JOIN datasets d ON d.id = b.dataset_id
          WHERE b.status = 'failed' AND d.status = 'ready'"
    );

    foreach ($stuck as $row) {
        try {
            $path = large_import_safe_path((string) $row['file_path']);
            $target = large_archive_root() . '/' . (int) $row['id'] . '-' . basename($path);
            @rename($path, $target);
        } catch (Throwable $e) {
            // Already archived, or otherwise gone — nothing left to move.
        }

        db_exec(
            'UPDATE bulk_import_jobs
                SET status = "done", error_message = NULL, finished_at = COALESCE(finished_at, NOW())
              WHERE id = ?',
            [(int) $row['id']]
        );
    }
}

function large_import_queue(int $id, int $userId): void
{
    $job = db_one('SELECT * FROM bulk_import_jobs WHERE id = ?', [$id]);
    if (!$job) {
        throw new RuntimeException('That inbox file no longer exists.');
    }
    if ($job['status'] !== 'discovered' && !($job['status'] === 'failed' && empty($job['dataset_id']))) {
        throw new RuntimeException('That file is already queued or imported.');
    }

    $path = large_import_safe_path((string) $job['file_path']);
    if (!is_file($path)) {
        throw new RuntimeException('The inbox file is missing. Upload it again by SFTP.');
    }

    db_exec(
        'UPDATE bulk_import_jobs
            SET status = "queued", queued_by = ?, error_message = NULL,
                started_at = NULL, finished_at = NULL
          WHERE id = ?',
        [$userId, $id]
    );
}

/** Ensures a DB path can never escape the configured inbox. */
function large_import_safe_path(string $path): string
{
    $root = realpath(large_import_root());
    $real = realpath($path);
    if ($root === false || $real === false) {
        throw new RuntimeException('Could not locate the inbox file.');
    }

    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $real = str_replace('\\', '/', $real);
    if (!str_starts_with($real, $root) || dirname($real) . '/' !== $root) {
        throw new RuntimeException('The queued path is outside the import inbox.');
    }
    return $real;
}

function large_import_prepare(array $bulk): array
{
    if (!empty($bulk['dataset_id'])) {
        $job = import_next_job((int) $bulk['dataset_id']);
        if (!$job) {
            throw new RuntimeException('The resumable import job is missing.');
        }
        return $job;
    }

    $path = large_import_safe_path((string) $bulk['file_path']);
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx') {
        large_import_convert_xlsx($bulk, $path);
        $path = large_import_safe_path((string) $bulk['file_path']);
    }
    $fh = fopen($path, 'rb');
    if (!$fh) {
        throw new RuntimeException('Could not open the inbox CSV.');
    }
    $header = fgetcsv($fh, 0, ',');
    fclose($fh);
    if ($header === false || $header === [null]) {
        throw new RuntimeException('The inbox CSV is empty or has no header row.');
    }

    $headers = sanitize_headers(array_map(static fn($v) => (string) $v, $header));
    $existing = array_map(
        static fn(array $r): string => (string) $r['table_name'],
        db_all('SELECT table_name FROM datasets')
    );
    $table = ident_table_name((string) $bulk['file_name'], $existing);
    $display = mb_substr(pathinfo((string) $bulk['file_name'], PATHINFO_FILENAME), 0, 190);

    $columns = [];
    $defs = [
        qsys('_row_id') . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        qsys('_source_file') . ' VARCHAR(255) NOT NULL',
        qsys('_imported_at') . ' TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ];
    foreach ($headers as $i => $descriptor) {
        $name = ident_assert((string) $descriptor['name']);
        $columns[] = ['name' => $name, 'label' => (string) ($header[$i] ?: $name), 'type' => 'TEXT'];
        $defs[] = qi($name) . ' TEXT NULL';
    }
    $defs[] = 'PRIMARY KEY (' . qsys('_row_id') . ')';

    db()->exec(
        'CREATE TABLE ' . qi($table) . ' (' . implode(', ', $defs)
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    try {
        db_exec(
            'INSERT INTO datasets
               (table_name, display_name, source_files, columns_json, status, created_by)
             VALUES (?, ?, ?, ?, "importing", ?)',
            [
                $table,
                $display,
                json_encode([['filename' => $bulk['file_name'], 'uploaded_at' => date('c'), 'uploaded_by' => 'SFTP inbox']]),
                json_encode($columns, JSON_UNESCAPED_SLASHES),
                $bulk['queued_by'] ?: null,
            ]
        );
        $datasetId = db_insert_id();
        $mapping = array_map(static fn(array $c, int $i): array => [
            'index' => $i, 'name' => $c['name'], 'type' => 'TEXT',
        ], $columns, array_keys($columns));
        import_create_job($datasetId, $path, (string) $bulk['file_name'], $mapping);
        db_exec(
            'UPDATE bulk_import_jobs
                SET dataset_id = ?, status = "running", started_at = NOW()
              WHERE id = ?',
            [$datasetId, (int) $bulk['id']]
        );
    } catch (Throwable $e) {
        db()->exec('DROP TABLE IF EXISTS ' . qi($table));
        throw $e;
    }

    return import_next_job($datasetId)
        ?? throw new RuntimeException('Could not create the resumable import job.');
}

/** Converts a queued workbook in the CLI worker before the resumable CSV import. */
function large_import_convert_xlsx(array &$bulk, string $path): void
{
    $csvPath = preg_replace('/\.xlsx$/i', '.csv', $path) ?: ($path . '.csv');
    $tmp = $csvPath . '.converting';
    $parked = $path . '.converted';

    @unlink($tmp);
    xlsx_to_csv($path, $tmp);

    if (!@rename($path, $parked)) {
        @unlink($tmp);
        throw new RuntimeException('XLSX conversion finished, but the original workbook could not be parked safely.');
    }
    if (!@rename($tmp, $csvPath)) {
        @rename($parked, $path);
        @unlink($tmp);
        throw new RuntimeException('The converted CSV could not be finalized.');
    }

    $archive = large_archive_root() . '/' . (int) $bulk['id'] . '-' . basename($path);
    @rename($parked, $archive); // A parked .converted file is ignored if archiving fails.

    $csvName = preg_replace('/\.xlsx$/i', '.csv', (string) $bulk['file_name']) ?: ((string) $bulk['file_name'] . '.csv');
    $bulk['file_path'] = str_replace('\\', '/', $csvPath);
    $bulk['file_name'] = $csvName;
    $bulk['file_size'] = (int) filesize($csvPath);
    $bulk['file_mtime'] = (int) filemtime($csvPath);

    db_exec(
        'UPDATE bulk_import_jobs
            SET file_path = ?, file_name = ?, file_size = ?, file_mtime = ?
          WHERE id = ?',
        [$bulk['file_path'], $bulk['file_name'], $bulk['file_size'], $bulk['file_mtime'], (int) $bulk['id']]
    );
}

/** Runs queued work for a bounded duration; safe to call every minute from cron. */
function large_import_work(int $budgetSeconds = 50): array
{
    large_import_scan();
    $started = microtime(true);
    $bulk = db_one(
        'SELECT * FROM bulk_import_jobs
          WHERE status IN ("running", "queued")
          ORDER BY FIELD(status, "running", "queued"), id LIMIT 1'
    );
    if (!$bulk) {
        return ['worked' => false, 'message' => 'No queued large imports.'];
    }

    try {
        do {
            $job = large_import_prepare($bulk);

            // large_import_prepare() takes $bulk by value, so an XLSX
            // conversion or dataset creation inside it (which does persist
            // to the row) never reaches this local copy. Without this
            // refresh, a job that finishes in the very same slice that
            // converted its file still carries the pre-conversion path when
            // the "done" branch below archives the source — realpath() on
            // that stale, already-renamed path fails with "Could not locate
            // the inbox file.", and dataset_id still reads empty here too,
            // so a genuine failure on that same slice would also miss
            // marking the dataset as failed.
            $bulk = db_one('SELECT * FROM bulk_import_jobs WHERE id = ?', [(int) $bulk['id']]) ?: $bulk;

            $progress = import_run_slice($job, false, false);
            db_exec(
                'UPDATE bulk_import_jobs SET status = "running", rows_done = ? WHERE id = ?',
                [(int) $progress['rows'], (int) $bulk['id']]
            );
            if ($progress['done']) {
                if ($progress['status'] === 'failed') {
                    throw new RuntimeException((string) ($progress['error'] ?? 'Import failed.'));
                }
                $source = large_import_safe_path((string) $bulk['file_path']);
                $target = large_archive_root() . '/' . (int) $bulk['id'] . '-' . basename($source);
                $archiveWarning = null;
                if (!@rename($source, $target)) {
                    $archiveWarning = 'Import finished, but the source file could not be moved to var/imported.';
                }
                db_exec(
                    'UPDATE bulk_import_jobs
                        SET status = "done", rows_done = ?, error_message = ?, finished_at = NOW()
                      WHERE id = ?',
                    [(int) $progress['rows'], $archiveWarning, (int) $bulk['id']]
                );
                return ['worked' => true, 'done' => true, 'progress' => $progress];
            }
            $bulk = db_one('SELECT * FROM bulk_import_jobs WHERE id = ?', [(int) $bulk['id']]) ?: $bulk;
        } while (microtime(true) - $started < max(5, $budgetSeconds));

        return ['worked' => true, 'done' => false, 'progress' => $progress];
    } catch (Throwable $e) {
        db_exec(
            'UPDATE bulk_import_jobs
                SET status = "failed", error_message = ?, finished_at = NOW()
              WHERE id = ?',
            [mb_substr($e->getMessage(), 0, 1000), (int) $bulk['id']]
        );
        if (!empty($bulk['dataset_id'])) {
            db_exec(
                'UPDATE datasets SET status = "failed", error_message = ? WHERE id = ?',
                [mb_substr($e->getMessage(), 0, 1000), (int) $bulk['dataset_id']]
            );
        }
        throw $e;
    }
}
