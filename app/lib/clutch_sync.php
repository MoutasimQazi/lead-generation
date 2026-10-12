<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/identifiers.php';

/**
 * Copies the Clutch pipeline's leads into this site's own `clutch` table.
 *
 * Source:      CLUTCH_DB_* (a second connection; read-only use) -> table CLUTCH_SOURCE_TABLE
 * Destination: table `clutch` in the main database, registered as a searchable
 *              dataset inside a "Clutch" folder, so search / assign / export
 *              work like any other dataset.
 *
 * Rows are matched on review_id, so re-running the sync only inserts new
 * reviews and refreshes changed ones.
 */

const CLUTCH_TABLE = 'clutch';
const CLUTCH_FOLDER_SLUG = 'clutch';

/** column => [MariaDB type, columns_json type]. Order matches the Clutch app's `leads` table. */
const CLUTCH_COLUMNS = [
    'review_id'         => ['VARCHAR(12) NOT NULL', 'TEXT'],
    'company'           => ['VARCHAR(255) NULL', 'TEXT'],
    'company_url'       => ['VARCHAR(500) NULL', 'TEXT'],
    'review_title'      => ['VARCHAR(500) NULL', 'TEXT'],
    'rating'            => ['DOUBLE NULL', 'DOUBLE'],
    'quality'           => ['DOUBLE NULL', 'DOUBLE'],
    'schedule'          => ['DOUBLE NULL', 'DOUBLE'],
    'cost'              => ['DOUBLE NULL', 'DOUBLE'],
    'willing_to_refer'  => ['DOUBLE NULL', 'DOUBLE'],
    'review_date'       => ['VARCHAR(40) NULL', 'TEXT'],
    'reviewer_name'     => ['VARCHAR(255) NULL', 'TEXT'],
    'reviewer_position' => ['VARCHAR(500) NULL', 'TEXT'],
    'industry'          => ['VARCHAR(255) NULL', 'TEXT'],
    'location'          => ['VARCHAR(255) NULL', 'TEXT'],
    'company_size'      => ['VARCHAR(100) NULL', 'TEXT'],
    'project_cost'      => ['VARCHAR(100) NULL', 'TEXT'],
    'project_dates'     => ['VARCHAR(100) NULL', 'TEXT'],
    'services'          => ['TEXT NULL', 'TEXT'],
    'quote'             => ['TEXT NULL', 'TEXT'],
    'project_summary'   => ['TEXT NULL', 'TEXT'],
    'feedback_summary'  => ['TEXT NULL', 'TEXT'],
    'full_review'       => ['MEDIUMTEXT NULL', 'MEDIUMTEXT'],
];

function clutch_is_configured(): bool
{
    return config('clutch_db_name') !== '' && config('clutch_db_user') !== '' && config('clutch_db_pass') !== '';
}

/** Connection to the Clutch pipeline's database. */
function clutch_source_pdo(): PDO
{
    if (!clutch_is_configured()) {
        throw new RuntimeException(
            'Clutch source database is not configured. Set CLUTCH_DB_NAME, CLUTCH_DB_USER and CLUTCH_DB_PASSWORD in .env.'
        );
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        config('clutch_db_host'),
        config('clutch_db_port'),
        config('clutch_db_name')
    );

    try {
        return new PDO($dsn, config('clutch_db_user'), config('clutch_db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
    } catch (PDOException $e) {
        // Never echo the DSN/password; the message from PDO does not contain the password.
        throw new RuntimeException('Cannot connect to the Clutch database: ' . $e->getMessage(), 0, $e);
    }
}

/** Creates the sync log table, the `clutch` data table, its folder and its dataset row. Idempotent. */
function clutch_ensure_schema(): int
{
    db()->exec("
        CREATE TABLE IF NOT EXISTS clutch_sync_log (
          id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
          started_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          finished_at TIMESTAMP NULL,
          status      ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
          rows_read   INT UNSIGNED NOT NULL DEFAULT 0,
          total_after INT UNSIGNED NOT NULL DEFAULT 0,
          message     TEXT NULL,
          triggered_by VARCHAR(190) NULL,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!db_table_exists(CLUTCH_TABLE)) {
        $defs = [
            qsys('_row_id') . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            qsys('_source_file') . " VARCHAR(255) NOT NULL DEFAULT 'clutch-sync'",
            qsys('_imported_at') . ' TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ];
        foreach (CLUTCH_COLUMNS as $name => [$sqlType]) {
            $defs[] = qi($name) . ' ' . $sqlType;
        }
        $defs[] = 'PRIMARY KEY (' . qsys('_row_id') . ')';
        $defs[] = 'UNIQUE KEY uq_clutch_review (' . qi('review_id') . ')';
        $defs[] = 'KEY idx_clutch_rating (' . qi('rating') . ')';

        db()->exec('CREATE TABLE ' . qi(CLUTCH_TABLE) . ' (' . implode(', ', $defs)
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    $folderId = db_value('SELECT id FROM folders WHERE slug = ?', [CLUTCH_FOLDER_SLUG]);
    if ($folderId === null) {
        db_exec('INSERT INTO folders (name, slug) VALUES (?, ?)', ['Clutch', CLUTCH_FOLDER_SLUG]);
        $folderId = db_insert_id();
    }

    $datasetId = db_value('SELECT id FROM datasets WHERE table_name = ?', [CLUTCH_TABLE]);
    if ($datasetId === null) {
        $cols = [];
        foreach (CLUTCH_COLUMNS as $name => [, $jsonType]) {
            $cols[] = ['name' => $name, 'label' => ucwords(str_replace('_', ' ', $name)), 'type' => $jsonType];
        }
        db_exec(
            'INSERT INTO datasets
               (folder_id, table_name, display_name, columns_json, source_files,
                row_count, is_searchable, is_protected, status)
             VALUES (?, ?, ?, ?, ?, 0, 1, 1, "ready")',
            [(int) $folderId, CLUTCH_TABLE, 'Clutch leads', json_encode($cols), json_encode([])]
        );
        $datasetId = db_insert_id();
    }

    return (int) $datasetId;
}

/**
 * Runs one sync. Returns ['read' => int, 'total' => int, 'log_id' => int].
 * Throws on failure after recording it in clutch_sync_log.
 */
function clutch_sync(string $triggeredBy = 'cli'): array
{
    $datasetId = clutch_ensure_schema();

    $locked = (int) db_value('SELECT GET_LOCK(?, 0)', ['movenetics_clutch_sync'], 0) === 1;
    if (!$locked) {
        throw new RuntimeException('A Clutch sync is already running.');
    }

    db_exec('INSERT INTO clutch_sync_log (status, triggered_by) VALUES ("running", ?)', [mb_substr($triggeredBy, 0, 190)]);
    $logId = db_insert_id();

    try {
        $src   = clutch_source_pdo();
        $table = (string) config('clutch_source_table');
        if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/i', $table)) {
            throw new RuntimeException('CLUTCH_SOURCE_TABLE is not a plain identifier.');
        }

        $cols     = array_keys(CLUTCH_COLUMNS);
        $colList  = implode(', ', array_map('qi', $cols));
        $update   = implode(', ', array_map(
            static fn(string $c): string => qi($c) . ' = VALUES(' . qi($c) . ')',
            array_slice($cols, 1)
        ));
        $one      = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $read     = 0;
        $lastId   = 0;
        $batchSz  = 500;

        $sel = $src->prepare(
            'SELECT id, ' . $colList . ' FROM `' . $table . '` WHERE id > ? ORDER BY id LIMIT ' . $batchSz
        );

        while (true) {
            $sel->execute([$lastId]);
            $rows = $sel->fetchAll();
            if (!$rows) {
                break;
            }

            $params = [];
            foreach ($rows as $r) {
                $lastId = (int) $r['id'];
                foreach ($cols as $c) {
                    $v = $r[$c] ?? null;
                    $params[] = ($v === '' && !in_array($c, ['review_id'], true)) ? null : $v;
                }
            }

            db_exec(
                'INSERT INTO ' . qi(CLUTCH_TABLE) . ' (' . $colList . ') VALUES '
                . implode(',', array_fill(0, count($rows), $one))
                . ' ON DUPLICATE KEY UPDATE ' . $update,
                $params
            );

            $read += count($rows);
        }

        $total = (int) db_value('SELECT COUNT(*) FROM ' . qi(CLUTCH_TABLE), [], 0);

        db_exec('UPDATE datasets SET row_count = ?, status = "ready", error_message = NULL WHERE id = ?', [$total, $datasetId]);
        db_exec(
            'UPDATE clutch_sync_log SET status = "ok", finished_at = NOW(), rows_read = ?, total_after = ?, message = ? WHERE id = ?',
            [$read, $total, "Read $read rows from {$table}; table now has $total.", $logId]
        );

        return ['read' => $read, 'total' => $total, 'log_id' => $logId];
    } catch (Throwable $e) {
        db_exec(
            'UPDATE clutch_sync_log SET status = "failed", finished_at = NOW(), message = ? WHERE id = ?',
            [mb_substr($e->getMessage(), 0, 1000), $logId]
        );
        error_log('[lead-site] clutch sync failed: ' . $e->getMessage());
        throw $e;
    } finally {
        db_value('SELECT RELEASE_LOCK(?)', ['movenetics_clutch_sync']);
    }
}

/** Recent sync runs, newest first. */
function clutch_sync_history(int $limit = 10): array
{
    clutch_ensure_schema();
    return db_all(
        'SELECT id, started_at, finished_at, status, rows_read, total_after, message, triggered_by
           FROM clutch_sync_log ORDER BY id DESC LIMIT ' . max(1, min(100, $limit))
    );
}

/**
 * Last lines of the Clutch app's own log (logs/app.log).
 * Read from CLUTCH_LOG_FILE when this server can see it, otherwise from the
 * Clutch app's GET /api/log using CLUTCH_APP_URL + CLUTCH_API_KEY.
 *
 * @return array{source: ?string, lines: string[], note: string}
 */
function clutch_log_tail(int $lines = 200): array
{
    $lines = max(10, min(1000, $lines));
    $file  = (string) config('clutch_log_file');

    if ($file !== '' && is_readable($file)) {
        $size = (int) filesize($file);
        $fh   = fopen($file, 'rb');
        if ($fh !== false) {
            if ($size > 262144) {
                fseek($fh, -262144, SEEK_END);
            }
            $text = (string) stream_get_contents($fh);
            fclose($fh);
            $all = preg_split('/\r?\n/', rtrim($text)) ?: [];
            return ['source' => 'file', 'lines' => array_slice($all, -$lines), 'note' => ''];
        }
    }

    $url = (string) config('clutch_app_url');
    $key = (string) config('clutch_api_key');

    if ($url !== '' && $key !== '') {
        $ch = curl_init($url . '/api/log?lines=' . $lines);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['x-api-key: ' . $key, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $code === 200) {
            $json = json_decode((string) $body, true);
            if (is_array($json) && isset($json['lines']) && is_array($json['lines'])) {
                return ['source' => 'http', 'lines' => array_map('strval', $json['lines']), 'note' => ''];
            }
            return ['source' => null, 'lines' => [], 'note' => 'Clutch app returned an unexpected response.'];
        }

        return ['source' => null, 'lines' => [], 'note' => 'Could not read the log from the Clutch app: '
            . ($err !== '' ? $err : 'HTTP ' . $code)];
    }

    return ['source' => null, 'lines' => [],
            'note' => 'Log source not configured. Set CLUTCH_LOG_FILE (same server) or CLUTCH_APP_URL + CLUTCH_API_KEY.'];
}
