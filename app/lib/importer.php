<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/identifiers.php';
require_once __DIR__ . '/inference.php';

/**
 * Chunked, resumable CSV import.
 *
 * Shared hosting gives no way to run a background worker, and a 48 MB / 248k
 * row file will not import inside one request's max_execution_time. So the
 * import is sliced: the browser calls /api/imports/tick repeatedly, each call
 * ingests as many rows as it safely can, records the byte offset it reached,
 * and returns progress. A killed request costs at most one slice — the next
 * call resumes from the stored offset.
 *
 * This is why reader.php normalizes everything to CSV first: fseek() to a byte
 * offset is only meaningful on a flat text file.
 */

/** Seconds to spend in one slice before yielding, derived from the host limit. */
function import_slice_budget(): float
{
    $limit = (int) ini_get('max_execution_time');

    // 0 means unlimited (CLI, or a permissive host). Cap anyway so the browser
    // gets progress back at a sensible cadence.
    if ($limit <= 0) {
        return 20.0;
    }

    // Leave headroom: finishing the slice and committing must fit too.
    return max(3.0, $limit * 0.6);
}

function import_create_job(int $datasetId, string $filePath, string $sourceFile, array $mapping): int
{
    db_exec(
        'INSERT INTO import_jobs (dataset_id, file_path, source_file, mapping_json, file_size, status)
         VALUES (?, ?, ?, ?, ?, "pending")',
        [
            $datasetId,
            $filePath,
            mb_substr($sourceFile, 0, 255),
            json_encode($mapping, JSON_UNESCAPED_SLASHES),
            is_file($filePath) ? (int) filesize($filePath) : 0,
        ]
    );

    return db_insert_id();
}

/** The next unfinished job for a dataset, or null when everything is done. */
function import_next_job(int $datasetId): ?array
{
    return db_one(
        'SELECT * FROM import_jobs
          WHERE dataset_id = ? AND status IN ("pending","running")
       ORDER BY id ASC LIMIT 1',
        [$datasetId]
    );
}

/**
 * Ingests one slice of one job.
 *
 * @return array progress for the caller: rows_done, done, percent, message
 */
function import_run_slice(array $job): array
{
    $datasetId = (int) $job['dataset_id'];
    $dataset   = db_one('SELECT * FROM datasets WHERE id = ?', [$datasetId]);

    if (!$dataset) {
        throw new RuntimeException('The dataset this import belongs to no longer exists.');
    }

    $table   = ident_assert($dataset['table_name']);
    $mapping = json_decode((string) $job['mapping_json'], true) ?: [];

    if ($mapping === []) {
        import_fail($job, 'The column mapping for this file is empty.');
        return import_progress($datasetId);
    }

    $path = (string) $job['file_path'];

    if (!is_file($path)) {
        import_fail($job, 'The staged file is no longer on disk. Re-upload it.');
        return import_progress($datasetId);
    }

    $fh = fopen($path, 'rb');

    if (!$fh) {
        import_fail($job, 'The staged file could not be opened.');
        return import_progress($datasetId);
    }

    $offset = (int) $job['byte_offset'];

    if ($offset > 0) {
        fseek($fh, $offset);
    } else {
        // First slice: step past the header row.
        fgetcsv($fh, 0, ',');
    }

    db_exec('UPDATE import_jobs SET status = "running" WHERE id = ?', [(int) $job['id']]);

    // Column list is fixed for the whole job, so build the SQL once.
    $colNames = array_map(static fn($m) => qi((string) $m['name']), $mapping);
    $sqlCols  = array_merge([qsys('_source_file')], $colNames);
    $perRow   = count($sqlCols);

    $insertHead = 'INSERT INTO ' . qi($table) . ' (' . implode(', ', $sqlCols) . ') VALUES ';
    $rowPlace   = '(' . implode(',', array_fill(0, $perRow, '?')) . ')';

    $batchSize = (int) config('import_batch');
    $maxRows   = (int) config('import_per_request');
    $deadline  = microtime(true) + import_slice_budget();

    $buffer    = [];
    $rowsDone  = (int) $job['rows_done'];
    $skipped   = (int) $job['rows_skipped'];
    $truncated = (int) $job['truncated_cells'];
    $sliceRows = 0;
    $eof       = false;

    $flush = static function (array &$buffer) use ($insertHead, $rowPlace): void {
        if ($buffer === []) {
            return;
        }

        $sql    = $insertHead . implode(',', array_fill(0, count($buffer), $rowPlace));
        $params = array_merge(...$buffer);

        db_run($sql, $params);
        $buffer = [];
    };

    try {
        while (true) {
            if ($sliceRows >= $maxRows || microtime(true) >= $deadline) {
                break;
            }

            $row = fgetcsv($fh, 0, ',');

            if ($row === false) {
                $eof = true;
                break;
            }

            if ($row === [null]) {
                continue;
            }

            $values = [$job['source_file']];

            foreach ($mapping as $m) {
                $raw = $row[$m['index']] ?? null;
                $cast = cast_for_type($raw, (string) $m['type']);

                if (is_string($raw) && is_string($cast) && strlen(trim($raw)) > strlen($cast)) {
                    $truncated++;
                }

                $values[] = $cast;
            }

            $buffer[] = $values;
            $rowsDone++;
            $sliceRows++;

            if (count($buffer) >= $batchSize) {
                $flush($buffer);
                // Record the offset only after the batch is safely written, so
                // a crash re-reads those rows rather than losing them.
                $offset = ftell($fh) ?: $offset;

                db_exec(
                    'UPDATE import_jobs
                        SET byte_offset = ?, rows_done = ?, truncated_cells = ?
                      WHERE id = ?',
                    [$offset, $rowsDone, $truncated, (int) $job['id']]
                );
            }
        }

        $flush($buffer);
        $offset = ftell($fh) ?: $offset;
        fclose($fh);
    } catch (Throwable $e) {
        fclose($fh);
        import_fail($job, import_friendly_error($e));
        return import_progress($datasetId);
    }

    db_exec(
        'UPDATE import_jobs
            SET byte_offset = ?, rows_done = ?, rows_skipped = ?, truncated_cells = ?,
                status = ?
          WHERE id = ?',
        [$offset, $rowsDone, $skipped, $truncated, $eof ? 'done' : 'running', (int) $job['id']]
    );

    if ($eof) {
        @unlink($path);
    }

    import_sync_dataset($datasetId);

    return import_progress($datasetId);
}

/**
 * Turns a driver exception into something an admin can act on.
 * The raw PDO message names internal columns and is not much help on screen.
 */
function import_friendly_error(Throwable $e): string
{
    $msg = $e->getMessage();

    if (str_contains($msg, 'Data too long')) {
        return 'A value was longer than its column allows. Widen that column '
             . '(or set it to TEXT) on the dataset page, then re-import.';
    }

    if (str_contains($msg, 'Incorrect') && str_contains($msg, 'value')) {
        return 'A value did not fit the column type chosen for it. Set that column '
             . 'to text on the dataset page, then re-import.';
    }

    if (str_contains($msg, 'max_allowed_packet')) {
        return 'The batch sent to MariaDB was too large. Lower IMPORT_BATCH_SIZE in .env.';
    }

    return 'Import failed: ' . mb_substr($msg, 0, 300);
}

function import_fail(array $job, string $message): void
{
    db_exec(
        'UPDATE import_jobs SET status = "failed", error_message = ? WHERE id = ?',
        [$message, (int) $job['id']]
    );

    db_exec(
        'UPDATE datasets SET status = "failed", error_message = ? WHERE id = ?',
        [$message, (int) $job['dataset_id']]
    );
}

/** Recomputes dataset row_count and status from its jobs. */
function import_sync_dataset(int $datasetId): void
{
    $dataset = db_one('SELECT table_name FROM datasets WHERE id = ?', [$datasetId]);

    if (!$dataset) {
        return;
    }

    $stats = db_one(
        'SELECT COUNT(*) AS jobs,
                SUM(status = "done")   AS done,
                SUM(status = "failed") AS failed,
                SUM(rows_done)         AS rows_done,
                SUM(truncated_cells)   AS truncated
           FROM import_jobs WHERE dataset_id = ?',
        [$datasetId]
    ) ?: [];

    $jobs   = (int) ($stats['jobs'] ?? 0);
    $done   = (int) ($stats['done'] ?? 0);
    $failed = (int) ($stats['failed'] ?? 0);

    if ($failed > 0) {
        return; // import_fail already recorded the message.
    }

    // No jobs at all means there is nothing left to ingest. Without this the
    // dataset would sit at "importing" forever and the polling frontend would
    // spin, because every tick would find no work and change nothing.
    $allDone = $jobs === 0 || $done === $jobs;

    if ($allDone) {
        // Authoritative count straight from the table, rather than trusting the
        // running tally.
        $count = (int) db_value('SELECT COUNT(*) FROM ' . qi($dataset['table_name']), [], 0);

        db_exec(
            'UPDATE datasets SET status = "ready", row_count = ?, error_message = NULL WHERE id = ?',
            [$count, $datasetId]
        );
    } else {
        db_exec(
            'UPDATE datasets SET status = "importing", row_count = ? WHERE id = ?',
            [(int) ($stats['rows_done'] ?? 0), $datasetId]
        );
    }
}

/** Progress summary for the polling frontend. */
function import_progress(int $datasetId): array
{
    $dataset = db_one(
        'SELECT id, display_name, status, row_count, error_message FROM datasets WHERE id = ?',
        [$datasetId]
    );

    if (!$dataset) {
        return ['done' => true, 'percent' => 100, 'rows' => 0, 'status' => 'failed'];
    }

    $jobs = db_all(
        'SELECT source_file, status, rows_done, byte_offset, file_size, truncated_cells, error_message
           FROM import_jobs WHERE dataset_id = ? ORDER BY id',
        [$datasetId]
    );

    $totalBytes = 0;
    $doneBytes  = 0;
    $truncated  = 0;

    foreach ($jobs as $j) {
        $totalBytes += (int) $j['file_size'];
        $doneBytes  += $j['status'] === 'done' ? (int) $j['file_size'] : (int) $j['byte_offset'];
        $truncated  += (int) $j['truncated_cells'];
    }

    $percent = $totalBytes > 0 ? (int) min(100, round($doneBytes * 100 / $totalBytes)) : 100;
    $status  = (string) $dataset['status'];

    return [
        'dataset_id' => (int) $dataset['id'],
        'name'       => $dataset['display_name'],
        'status'     => $status,
        'done'       => in_array($status, ['ready', 'failed'], true),
        'percent'    => $status === 'ready' ? 100 : $percent,
        'rows'       => (int) $dataset['row_count'],
        'truncated'  => $truncated,
        'error'      => $dataset['error_message'],
        'files'      => array_map(static fn($j) => [
            'file'   => $j['source_file'],
            'status' => $j['status'],
            'rows'   => (int) $j['rows_done'],
        ], $jobs),
    ];
}
