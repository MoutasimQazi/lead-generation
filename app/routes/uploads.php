<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/identifiers.php';
require_once __DIR__ . '/../lib/inference.php';
require_once __DIR__ . '/../lib/reader.php';
require_once __DIR__ . '/../lib/importer.php';
require_once __DIR__ . '/../lib/audit.php';

/**
 * Two-phase upload.
 *
 *   stage  — files land on disk, are normalized to CSV, and are sampled to
 *            propose a table name, column names and types. Nothing is created.
 *   commit — the admin's decisions come back; tables are created and import
 *            jobs queued. The rows themselves are ingested by /api/imports/tick.
 *
 * The split exists because the admin has real choices to make (searchable?
 * new table or append? which types?) and because a 48 MB file cannot be
 * ingested inside a single request on shared hosting.
 */

function upload_root(): string
{
    $dir = config('upload_dir');

    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the upload directory at ' . $dir);
    }

    // Defence in depth: if this folder ever ends up inside public_html, Apache
    // must still refuse to serve what is in it.
    $guard = $dir . '/.htaccess';

    if (!is_file($guard)) {
        @file_put_contents($guard, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }

    return $dir;
}

/** Human-readable reason for a PHP upload error code. */
function upload_error_message(int $code, string $name): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            "\"$name\" is larger than this server accepts. Raise upload_max_filesize "
            . 'and post_max_size in .user.ini (or MultiPHP INI Editor in cPanel).',
        UPLOAD_ERR_PARTIAL   => "\"$name\" only uploaded partially. Try again.",
        UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
            'The server could not write the upload to disk. Check the temp directory.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
        default              => "\"$name\" could not be uploaded (code $code).",
    };
}

/** Existing dataset tables, so a new table name cannot collide. */
function existing_table_names(): array
{
    return array_column(db_all('SELECT table_name FROM datasets'), 'table_name');
}

/**
 * Finds a dataset in the same folder whose columns exactly match $columnNames.
 * That match is what lets the confirm screen offer "append" instead of
 * silently making a second near-identical table.
 */
function find_appendable_dataset(?int $folderId, array $columnNames): ?array
{
    if ($folderId === null) {
        return null;
    }

    $candidates = db_all(
        'SELECT id, display_name, table_name, columns_json, row_count
           FROM datasets
          WHERE folder_id = ? AND status = "ready" AND is_protected = 0',
        [$folderId]
    );

    sort($columnNames);

    foreach ($candidates as $c) {
        $cols  = json_decode((string) $c['columns_json'], true) ?: [];
        $names = array_column($cols, 'name');
        sort($names);

        if ($names === $columnNames) {
            return [
                'id'           => (int) $c['id'],
                'display_name' => $c['display_name'],
                'table_name'   => $c['table_name'],
                'row_count'    => (int) $c['row_count'],
            ];
        }
    }

    return null;
}

/* ── phase A: stage ────────────────────────────────────────────────────── */

function route_uploads_stage(): never
{
    $user = require_admin();
    require_csrf();

    $folderId = isset($_POST['folder_id']) && $_POST['folder_id'] !== ''
        ? (int) $_POST['folder_id']
        : null;

    if ($folderId !== null && !db_one('SELECT id FROM folders WHERE id = ?', [$folderId])) {
        fail('That folder no longer exists.', 404);
    }

    if (empty($_FILES['files'])) {
        // An empty $_FILES with a non-empty body means post_max_size silently
        // discarded everything — PHP reports no error for this, so say it plainly.
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($len > 0) {
            fail(
                'The upload exceeded this server\'s post_max_size (' . ini_get('post_max_size')
                . '), so PHP discarded it. Raise upload_max_filesize and post_max_size '
                . 'in .user.ini, then try again.',
                413
            );
        }

        fail('Choose at least one file to upload.', 422);
    }

    $files   = normalize_files_array($_FILES['files']);
    $maxBytes = config('max_upload_mb') * 1024 * 1024;

    if (count($files) > 20) {
        fail('Upload at most 20 files at a time.', 422);
    }

    $stageId  = bin2hex(random_bytes(16));
    $stageDir = upload_root() . '/' . $stageId;

    if (!@mkdir($stageDir, 0770, true) && !is_dir($stageDir)) {
        fail('Could not create a staging directory for this upload.', 500);
    }

    $taken    = existing_table_names();
    $manifest = ['folder_id' => $folderId, 'files' => []];

    foreach ($files as $i => $file) {
        $name = (string) $file['name'];

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            stage_cleanup($stageDir);
            fail(upload_error_message((int) $file['error'], $name), 413);
        }

        if ((int) $file['size'] > $maxBytes) {
            stage_cleanup($stageDir);
            fail("\"$name\" is larger than the " . config('max_upload_mb') . ' MB limit.', 413);
        }

        $ext = reader_extension($name);

        if (!in_array($ext, READER_SUPPORTED_EXT, true)) {
            stage_cleanup($stageDir);
            fail("\"$name\" is a .$ext file. Upload .csv, .tsv or .xlsx.", 422);
        }

        $rawPath = $stageDir . '/raw_' . $i;

        if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $rawPath)) {
            stage_cleanup($stageDir);
            fail("\"$name\" could not be saved to the server.", 500);
        }

        $csvPath = $stageDir . '/' . $i . '.csv';

        try {
            $info = normalize_to_csv($rawPath, $name, $csvPath);
        } catch (Throwable $e) {
            stage_cleanup($stageDir);
            fail($e->getMessage(), 422);
        }

        @unlink($rawPath);

        $sample  = csv_sample($csvPath, config('infer_sample'));
        $columns = infer_columns(sanitize_headers($sample['header']), $sample['rows']);

        $tableName = ident_table_name($name, $taken);
        $taken[]   = $tableName;

        $match = find_appendable_dataset($folderId, array_column($columns, 'name'));

        $manifest['files'][] = [
            'index'         => $i,
            'original_name' => $name,
            'csv_path'      => $csvPath,
            'size'          => (int) filesize($csvPath),
            'est_rows'      => $info['rows'],
            'table_name'    => $tableName,
            'display_name'  => pathinfo($name, PATHINFO_FILENAME),
            'columns'       => $columns,
            'sample'        => array_slice($sample['rows'], 0, 5),
            'append_to'     => $match,
        ];
    }

    db_exec(
        'INSERT INTO upload_stages (id, user_id, folder_id, manifest) VALUES (?, ?, ?, ?)',
        [$stageId, (int) $user['id'], $folderId, json_encode($manifest, JSON_UNESCAPED_SLASHES)]
    );

    stage_prune();

    // csv_path is internal — the browser has no business knowing server paths.
    $public = $manifest;
    foreach ($public['files'] as &$f) {
        unset($f['csv_path']);
    }

    json_ok(['stage_id' => $stageId, 'folder_id' => $folderId, 'files' => $public['files']]);
}

/** PHP gives multi-file uploads as parallel arrays; make it a list of records. */
function normalize_files_array(array $f): array
{
    if (!is_array($f['name'])) {
        return [$f];
    }

    $out = [];

    foreach (array_keys($f['name']) as $i) {
        $out[] = [
            'name'     => $f['name'][$i],
            'type'     => $f['type'][$i] ?? '',
            'tmp_name' => $f['tmp_name'][$i] ?? '',
            'error'    => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $f['size'][$i] ?? 0,
        ];
    }

    return $out;
}

function stage_cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }

    @rmdir($dir);
}

/** Drops staged uploads nobody committed, so the disk does not fill up. */
function stage_prune(): void
{
    $stale = db_all('SELECT id FROM upload_stages WHERE created_at < (NOW() - INTERVAL 1 DAY)');

    foreach ($stale as $s) {
        stage_cleanup(upload_root() . '/' . $s['id']);
        db_exec('DELETE FROM upload_stages WHERE id = ?', [$s['id']]);
    }
}

/* ── phase B: commit ───────────────────────────────────────────────────── */

function route_uploads_commit(string $stageId): never
{
    $user = require_admin();
    require_csrf();

    if (!preg_match('/^[a-f0-9]{32}$/', $stageId)) {
        fail('That upload session is not valid.', 422);
    }

    $stage = db_one('SELECT * FROM upload_stages WHERE id = ?', [$stageId]);

    if (!$stage) {
        fail('That upload session has expired. Upload the files again.', 404);
    }

    $manifest  = json_decode((string) $stage['manifest'], true) ?: [];
    $byIndex   = [];

    foreach ($manifest['files'] ?? [] as $f) {
        $byIndex[(int) $f['index']] = $f;
    }

    $decisions = body_array('files');

    if ($decisions === []) {
        fail('Nothing was selected to import.', 422);
    }

    $results = [];

    foreach ($decisions as $decision) {
        if (!is_array($decision)) {
            fail('Malformed import instruction.', 422);
        }

        $index = (int) ($decision['index'] ?? -1);

        if (!isset($byIndex[$index])) {
            fail('That upload session does not contain the file you selected.', 422);
        }

        $staged  = $byIndex[$index];
        $mode    = ($decision['mode'] ?? 'new') === 'append' ? 'append' : 'new';
        $results[] = $mode === 'append'
            ? commit_append($user, $staged, $decision)
            : commit_new($user, $staged, $decision, $manifest['folder_id'] ?? null);
    }

    db_exec('DELETE FROM upload_stages WHERE id = ?', [$stageId]);

    json_ok(['datasets' => $results]);
}

/**
 * Rebuilds the column list from the admin's choices.
 *
 * The browser echoes column names back to us. They are re-derived and
 * re-asserted here rather than trusted — a tampered payload must not be able
 * to introduce an identifier that never passed the sanitizer.
 */
function columns_from_decision(array $staged, array $decision): array
{
    $chosen = [];

    foreach ($decision['columns'] ?? [] as $c) {
        if (!is_array($c) || !isset($c['name'])) {
            continue;
        }
        $chosen[(string) $c['name']] = $c;
    }

    $columns = [];

    // $i is the column's position in the normalized CSV, which is what the
    // importer reads by. It must come from the staged manifest's own ordering,
    // never from anything the client sent.
    foreach (array_values($staged['columns']) as $i => $col) {
        $name     = ident_assert((string) $col['name']);
        $override = $chosen[$name] ?? null;

        if ($override !== null && array_key_exists('include', $override)
            && filter_var($override['include'], FILTER_VALIDATE_BOOLEAN) === false) {
            continue;
        }

        $columns[] = [
            'name'         => $name,
            'label'        => (string) ($col['label'] ?? $name),
            'type'         => column_type_assert((string) ($override['type'] ?? $col['type'])),
            'source_index' => $i,
        ];
    }

    if ($columns === []) {
        fail('At least one column has to be imported.', 422);
    }

    return $columns;
}

function commit_new(array $user, array $staged, array $decision, ?int $folderId): array
{
    $existing  = existing_table_names();

    // Re-derive from the original filename; never accept a table name from the
    // client, which would hand it a say in what gets created.
    $tableName = ident_table_name(
        (string) ($decision['table_name'] ?? $staged['original_name']),
        $existing
    );

    $display = trim((string) ($decision['display_name'] ?? $staged['display_name']));
    $display = $display !== '' ? mb_substr($display, 0, 190) : $tableName;

    $searchable = filter_var($decision['is_searchable'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    $columns    = columns_from_decision($staged, $decision);

    $defs = [
        qsys('_row_id') . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        qsys('_source_file') . ' VARCHAR(255) NOT NULL',
        qsys('_imported_at') . ' TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ];

    foreach ($columns as $c) {
        $defs[] = qi($c['name']) . ' ' . column_type_assert($c['type']) . ' NULL';
    }

    $defs[] = 'PRIMARY KEY (' . qsys('_row_id') . ')';
    $defs[] = 'KEY idx_source_file (' . qsys('_source_file') . ')';

    $ddl = 'CREATE TABLE ' . qi($tableName) . ' (' . implode(', ', $defs)
         . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    db()->exec($ddl);

    try {
        db_exec(
            'INSERT INTO datasets
               (folder_id, table_name, display_name, source_files, columns_json,
                is_searchable, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, "importing", ?)',
            [
                $folderId,
                $tableName,
                $display,
                json_encode([[
                    'filename'    => $staged['original_name'],
                    'uploaded_at' => date('c'),
                    'uploaded_by' => $user['email'],
                ]], JSON_UNESCAPED_SLASHES),
                json_encode(array_map(static fn($c) => [
                    'name'  => $c['name'],
                    'label' => $c['label'],
                    'type'  => $c['type'],
                ], $columns), JSON_UNESCAPED_SLASHES),
                $searchable,
                (int) $user['id'],
            ]
        );
    } catch (Throwable $e) {
        // DDL cannot be rolled back in MariaDB, so undo the table by hand
        // rather than leaving an orphan nothing points at.
        db()->exec('DROP TABLE IF EXISTS ' . qi($tableName));
        throw $e;
    }

    $datasetId = db_insert_id();

    import_create_job(
        $datasetId,
        (string) $staged['csv_path'],
        (string) $staged['original_name'],
        array_map(static fn($c) => [
            'index' => $c['source_index'],
            'name'  => $c['name'],
            'type'  => $c['type'],
        ], $columns)
    );

    audit('upload.create', $user, $datasetId, [
        'table'   => $tableName,
        'file'    => $staged['original_name'],
        'columns' => count($columns),
    ]);

    return [
        'id'           => $datasetId,
        'display_name' => $display,
        'table_name'   => $tableName,
        'mode'         => 'new',
    ];
}

function commit_append(array $user, array $staged, array $decision): array
{
    $datasetId = (int) ($decision['dataset_id'] ?? 0);
    $dataset   = db_one('SELECT * FROM datasets WHERE id = ?', [$datasetId]);

    if (!$dataset) {
        fail('The dataset you chose to append to no longer exists.', 404);
    }

    if ((int) $dataset['is_protected'] === 1) {
        fail('The master leads table is protected and cannot be appended to from here.', 403);
    }

    $target = json_decode((string) $dataset['columns_json'], true) ?: [];
    $byName = [];

    foreach ($target as $c) {
        $byName[(string) $c['name']] = $c;
    }

    $mapping = [];
    $missing = [];

    foreach ($staged['columns'] as $i => $col) {
        $name = (string) $col['name'];

        if (!isset($byName[$name])) {
            $missing[] = $col['label'] ?? $name;
            continue;
        }

        $mapping[] = [
            'index' => $i,
            'name'  => ident_assert($name),
            // The target table's type wins — the incoming file does not get to
            // redefine a column that already holds data.
            'type'  => column_type_assert((string) $byName[$name]['type']),
        ];
    }

    if ($missing !== []) {
        fail(
            'That file cannot be appended to "' . $dataset['display_name'] . '" because it has '
            . 'columns the table does not: ' . implode(', ', array_slice($missing, 0, 6))
            . '. Import it as a new table instead.',
            422,
            ['unmatched_columns' => $missing]
        );
    }

    if ($mapping === []) {
        fail('None of that file\'s columns match the dataset you chose.', 422);
    }

    $files   = json_decode((string) $dataset['source_files'], true) ?: [];
    $files[] = [
        'filename'    => $staged['original_name'],
        'uploaded_at' => date('c'),
        'uploaded_by' => $user['email'],
    ];

    db_exec(
        'UPDATE datasets SET source_files = ?, status = "importing", error_message = NULL WHERE id = ?',
        [json_encode($files, JSON_UNESCAPED_SLASHES), $datasetId]
    );

    import_create_job(
        $datasetId,
        (string) $staged['csv_path'],
        (string) $staged['original_name'],
        $mapping
    );

    audit('upload.append', $user, $datasetId, [
        'table' => $dataset['table_name'],
        'file'  => $staged['original_name'],
    ]);

    return [
        'id'           => $datasetId,
        'display_name' => $dataset['display_name'],
        'table_name'   => $dataset['table_name'],
        'mode'         => 'append',
    ];
}

/* ── the import pump ───────────────────────────────────────────────────── */

/**
 * Ingests one slice. The browser calls this in a loop until done is true.
 * Each call is short enough to finish inside max_execution_time.
 */
function route_imports_tick(): never
{
    require_admin();
    require_csrf();

    $datasetId = body_int('dataset_id');

    if (!$datasetId) {
        fail('Which dataset should be imported?', 422);
    }

    $job = import_next_job($datasetId);

    if (!$job) {
        import_sync_dataset($datasetId);
        json_ok(['progress' => import_progress($datasetId)]);
    }

    json_ok(['progress' => import_run_slice($job)]);
}
