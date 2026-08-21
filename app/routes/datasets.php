<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/identifiers.php';
require_once __DIR__ . '/../lib/inference.php';
require_once __DIR__ . '/../lib/importer.php';
require_once __DIR__ . '/../lib/audit.php';

/**
 * Dataset browsing and editing.
 *
 * Two invariants hold throughout:
 *   1. Any column name arriving in a request is checked for membership in the
 *      dataset's columns_json before it reaches SQL. Unknown name, rejected
 *      request. columns_json is the allowlist.
 *   2. is_protected datasets (the master leads table) are read-only here. They
 *      can be searched and exported, never altered or dropped.
 */

function load_dataset(int $id): array
{
    $row = db_one('SELECT * FROM datasets WHERE id = ?', [$id]);

    if (!$row) {
        fail('That dataset no longer exists.', 404);
    }

    $row['columns'] = json_decode((string) $row['columns_json'], true) ?: [];
    $row['files']   = json_decode((string) $row['source_files'], true) ?: [];

    ident_assert((string) $row['table_name']);

    return $row;
}

function assert_editable(array $dataset): void
{
    if ((int) $dataset['is_protected'] === 1) {
        fail(
            'The master leads table is protected. It can be searched and exported, '
            . 'but not edited or deleted from here.',
            403
        );
    }
}

/** Looks a column up in the dataset's allowlist. Throws if it is not there. */
function dataset_column(array $dataset, string $name): array
{
    foreach ($dataset['columns'] as $c) {
        if (($c['name'] ?? '') === $name) {
            return $c;
        }
    }

    fail('There is no column called "' . mb_substr($name, 0, 60) . '" in this dataset.', 422);
}

function save_columns(int $datasetId, array $columns): void
{
    db_exec(
        'UPDATE datasets SET columns_json = ? WHERE id = ?',
        [json_encode(array_values($columns), JSON_UNESCAPED_SLASHES), $datasetId]
    );
}

function dataset_summary(array $d): array
{
    return [
        'id'            => (int) $d['id'],
        'folder_id'     => $d['folder_id'] === null ? null : (int) $d['folder_id'],
        'table_name'    => $d['table_name'],
        'display_name'  => $d['display_name'],
        'row_count'     => (int) $d['row_count'],
        'column_count'  => count(json_decode((string) $d['columns_json'], true) ?: []),
        'is_searchable' => (int) $d['is_searchable'] === 1,
        'is_protected'  => (int) $d['is_protected'] === 1,
        'status'        => $d['status'],
        'error_message' => $d['error_message'],
        'created_at'    => $d['created_at'],
        'updated_at'    => $d['updated_at'],
    ];
}

/* ── list & read ───────────────────────────────────────────────────────── */

function route_datasets_list(): never
{
    require_auth();

    $rows = db_all(
        'SELECT d.*, f.name AS folder_name
           FROM datasets d
           LEFT JOIN folders f ON f.id = d.folder_id
       ORDER BY d.is_protected DESC, f.name ASC, d.display_name ASC'
    );

    $out = [];

    foreach ($rows as $r) {
        $summary                = dataset_summary($r);
        $summary['folder_name'] = $r['folder_name'];
        $out[]                  = $summary;
    }

    json_ok(['datasets' => $out]);
}

function route_datasets_get(int $id): never
{
    require_auth();

    $d = load_dataset($id);

    json_ok([
        'dataset'  => dataset_summary($d) + [
            'columns' => $d['columns'],
            'files'   => $d['files'],
        ],
        'progress' => $d['status'] === 'importing' ? import_progress($id) : null,
    ]);
}

/* ── metadata ──────────────────────────────────────────────────────────── */

function route_datasets_update(int $id): never
{
    $user = require_admin();
    require_csrf();

    $d    = load_dataset($id);
    $body = json_body();

    $sets    = [];
    $params  = [];
    $changes = [];

    if (array_key_exists('display_name', $body)) {
        assert_editable($d);
        $name = body_string('display_name', '', 190) ?? '';

        if ($name === '') {
            fail('The dataset needs a name.', 422);
        }

        $sets[]    = 'display_name = ?';
        $params[]  = $name;
        $changes[] = 'renamed to "' . $name . '"';
    }

    if (array_key_exists('folder_id', $body)) {
        assert_editable($d);
        $folderId = body_int('folder_id');

        if ($folderId !== null && !db_one('SELECT id FROM folders WHERE id = ?', [$folderId])) {
            fail('That folder no longer exists.', 404);
        }

        $sets[]    = 'folder_id = ?';
        $params[]  = $folderId;
        $changes[] = 'moved folder';
    }

    // Deliberately allowed on protected datasets too: whether the master leads
    // table is searchable is a legitimate admin choice.
    if (array_key_exists('is_searchable', $body)) {
        $on        = body_bool('is_searchable', false);
        $sets[]    = 'is_searchable = ?';
        $params[]  = $on ? 1 : 0;
        $changes[] = $on ? 'made searchable' : 'removed from search';
    }

    if ($sets === []) {
        fail('Nothing to update.', 422);
    }

    $params[] = $id;
    db_exec('UPDATE datasets SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

    audit('dataset.update', $user, $id, ['changes' => $changes]);

    json_ok(['changes' => $changes]);
}

function route_datasets_delete(int $id): never
{
    $user = require_admin();
    require_csrf();

    $d = load_dataset($id);
    assert_editable($d);

    // The UI asks the admin to type the display name; requiring it here too
    // means a stray DELETE cannot drop a table on its own.
    $confirm = body_string('confirm', '', 190) ?? '';

    if ($confirm !== $d['display_name']) {
        fail('Type the dataset name exactly to confirm deletion.', 422);
    }

    db()->exec('DROP TABLE IF EXISTS ' . qi($d['table_name']));
    db_exec('DELETE FROM datasets WHERE id = ?', [$id]);

    audit('dataset.delete', $user, null, [
        'table' => $d['table_name'],
        'name'  => $d['display_name'],
        'rows'  => (int) $d['row_count'],
    ]);

    json_ok();
}

/* ── rows ──────────────────────────────────────────────────────────────── */

function route_rows_list(int $id): never
{
    require_auth();

    $d       = load_dataset($id);
    $columns = $d['columns'];

    if ($columns === []) {
        json_ok(['rows' => [], 'columns' => [], 'total' => 0, 'page' => 1, 'pages' => 1]);
    }

    $per    = query_int('per', 50, 10, 200);
    $page   = query_int('page', 1, 1, 1000000);
    $search = query_string('q');
    $sort   = query_string('sort', '', 64);
    $dir    = strtolower(query_string('dir', 'asc', 4)) === 'desc' ? 'DESC' : 'ASC';
    $filters = [];
    $filterJson = query_string('filters', '', 4000);

    if ($filterJson !== '') {
        $decoded = json_decode($filterJson, true);

        if (!is_array($decoded)) {
            fail('Invalid column filters.', 422);
        }

        foreach ($decoded as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $column = dataset_column($d, $name);
            $filters[$column['name']] = $value;
        }
    }

    $select = [qsys('_row_id') . ' AS _row_id', qsys('_source_file') . ' AS _source_file'];

    foreach ($columns as $c) {
        $select[] = qi((string) $c['name']);
    }

    $where  = '';
    $params = [];

    if ($search !== '') {
        $clauses = [];

        // Capped: a LIKE across 60 columns on a large table is a good way to
        // hang the database.
        foreach (array_slice($columns, 0, 20) as $c) {
            $clauses[] = qi((string) $c['name']) . ' LIKE ?';
            $params[]  = '%' . $search . '%';
        }

        if ($clauses !== []) {
            $where = ' WHERE (' . implode(' OR ', $clauses) . ')';
        }
    }

    foreach ($filters as $name => $value) {
        $where .= $where === '' ? ' WHERE ' : ' AND ';
        $where .= qi((string) $name) . ' LIKE ?';
        $params[] = '%' . $value . '%';
    }

    $orderBy = qsys('_row_id') . ' ASC';

    if ($sort !== '') {
        dataset_column($d, $sort);            // membership check, throws if unknown
        $orderBy = qi($sort) . ' ' . $dir;
    }

    $table = qi((string) $d['table_name']);
    $total = (int) db_value('SELECT COUNT(*) FROM ' . $table . $where, $params, 0);

    $offset = ($page - 1) * $per;

    // $per and $offset are ints from query_int, so interpolating them is safe;
    // LIMIT does not accept bound parameters under real prepared statements.
    $rows = db_all(
        'SELECT ' . implode(', ', $select) . ' FROM ' . $table . $where
        . ' ORDER BY ' . $orderBy . ' LIMIT ' . $per . ' OFFSET ' . $offset,
        $params
    );

    json_ok([
        'rows'    => $rows,
        'columns' => $columns,
        'total'   => $total,
        'page'    => $page,
        'per'     => $per,
        'pages'   => max(1, (int) ceil($total / $per)),
    ]);
}

function route_rows_create(int $id): never
{
    $user = require_admin();
    require_csrf();

    $d = load_dataset($id);
    assert_editable($d);

    $values = json_body()['values'] ?? [];

    if (!is_array($values) || $values === []) {
        fail('Provide at least one value for the new row.', 422);
    }

    $cols   = [qsys('_source_file')];
    $marks  = ['?'];
    $params = ['(added manually)'];

    foreach ($values as $name => $raw) {
        $col      = dataset_column($d, (string) $name);
        $cols[]   = qi((string) $col['name']);
        $marks[]  = '?';
        $params[] = cast_for_type($raw, (string) $col['type']);
    }

    db_exec(
        'INSERT INTO ' . qi($d['table_name']) . ' (' . implode(', ', $cols) . ')'
        . ' VALUES (' . implode(', ', $marks) . ')',
        $params
    );

    $rowId = db_insert_id();

    db_exec('UPDATE datasets SET row_count = row_count + 1 WHERE id = ?', [$id]);
    audit('row.create', $user, $id, ['row_id' => $rowId]);

    json_ok(['row_id' => $rowId]);
}

function route_rows_update(int $id, int $rowId): never
{
    $user = require_admin();
    require_csrf();

    $d = load_dataset($id);
    assert_editable($d);

    $values = json_body()['values'] ?? [];

    if (!is_array($values) || $values === []) {
        fail('Nothing to change.', 422);
    }

    $sets   = [];
    $params = [];
    $fields = [];

    foreach ($values as $name => $raw) {
        $col      = dataset_column($d, (string) $name);
        $sets[]   = qi((string) $col['name']) . ' = ?';
        $params[] = cast_for_type($raw, (string) $col['type']);
        $fields[] = $col['name'];
    }

    $params[] = $rowId;

    $changed = db_exec(
        'UPDATE ' . qi($d['table_name']) . ' SET ' . implode(', ', $sets)
        . ' WHERE ' . qsys('_row_id') . ' = ?',
        $params
    );

    if ($changed === 0 && !row_exists($d['table_name'], $rowId)) {
        fail('That row no longer exists.', 404);
    }

    audit('row.update', $user, $id, ['row_id' => $rowId, 'fields' => $fields]);

    json_ok();
}

function row_exists(string $table, int $rowId): bool
{
    return db_one(
        'SELECT ' . qsys('_row_id') . ' FROM ' . qi($table) . ' WHERE ' . qsys('_row_id') . ' = ?',
        [$rowId]
    ) !== null;
}

function route_rows_delete(int $id, int $rowId): never
{
    $user = require_admin();
    require_csrf();

    $d = load_dataset($id);
    assert_editable($d);

    $deleted = db_exec(
        'DELETE FROM ' . qi($d['table_name']) . ' WHERE ' . qsys('_row_id') . ' = ?',
        [$rowId]
    );

    if ($deleted === 0) {
        fail('That row no longer exists.', 404);
    }

    db_exec('UPDATE datasets SET row_count = GREATEST(row_count - 1, 0) WHERE id = ?', [$id]);
    audit('row.delete', $user, $id, ['row_id' => $rowId]);

    json_ok();
}

/**
 * Removes every row that came from one uploaded file.
 * This is what makes a merged multi-file table reversible — append the wrong
 * file into a folder's table and you can take exactly it back out.
 */
function route_rows_delete_by_file(int $id): never
{
    $user = require_admin();
    require_csrf();

    $d = load_dataset($id);
    assert_editable($d);

    $file = body_string('source_file', '', 255) ?? '';

    if ($file === '') {
        fail('Name the source file whose rows should be removed.', 422);
    }

    $deleted = db_exec(
        'DELETE FROM ' . qi($d['table_name']) . ' WHERE ' . qsys('_source_file') . ' = ?',
        [$file]
    );

    $files = array_values(array_filter(
        $d['files'],
        static fn($f) => ($f['filename'] ?? '') !== $file
    ));

    $count = (int) db_value('SELECT COUNT(*) FROM ' . qi($d['table_name']), [], 0);

    db_exec(
        'UPDATE datasets SET source_files = ?, row_count = ? WHERE id = ?',
        [json_encode($files, JSON_UNESCAPED_SLASHES), $count, $id]
    );

    audit('row.delete_by_file', $user, $id, ['file' => $file, 'rows' => $deleted]);

    json_ok(['deleted' => $deleted, 'remaining' => $count]);
}

/* ── schema ────────────────────────────────────────────────────────────── */

function route_columns_create(int $id): never
{
    $user = require_admin();
    require_csrf();

    $d = load_dataset($id);
    assert_editable($d);

    $label = body_string('label', '', 190) ?? '';
    $type  = column_type_assert(body_string('type', 'VARCHAR(255)', 40) ?? 'VARCHAR(255)');

    if ($label === '') {
        fail('Give the new column a name.', 422);
    }

    $taken = array_merge(array_column($d['columns'], 'name'), SYSTEM_COLUMNS);
    $name  = ident_unique(ident_sanitize($label), $taken);

    db()->exec(
        'ALTER TABLE ' . qi($d['table_name']) . ' ADD COLUMN ' . qi($name) . ' ' . $type . ' NULL'
    );

    $columns   = $d['columns'];
    $columns[] = ['name' => $name, 'label' => $label, 'type' => $type];
    save_columns($id, $columns);

    audit('column.create', $user, $id, ['column' => $name, 'type' => $type]);

    json_ok(['column' => ['name' => $name, 'label' => $label, 'type' => $type]]);
}

/**
 * Counts values that would not survive a cast to $type.
 *
 * Retyping in MariaDB is silently destructive — text that will not parse as a
 * number becomes 0, not an error — so this runs first and the change is refused
 * if anything would be lost.
 */
function count_uncastable(string $table, string $column, string $type): int
{
    $col = qi($column);
    $t   = strtoupper($type);

    $notNull = $col . ' IS NOT NULL AND ' . $col . " <> ''";

    if ($t === 'BIGINT' || $t === 'INT') {
        $sql = "SELECT COUNT(*) FROM " . qi($table) . " WHERE $notNull AND $col NOT REGEXP '^-?[0-9]+$'";
    } elseif (str_starts_with($t, 'DECIMAL') || $t === 'DOUBLE') {
        $sql = "SELECT COUNT(*) FROM " . qi($table) . " WHERE $notNull AND $col NOT REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'";
    } elseif ($t === 'DATE') {
        $sql = "SELECT COUNT(*) FROM " . qi($table) . " WHERE $notNull AND $col NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'";
    } elseif ($t === 'DATETIME') {
        $sql = "SELECT COUNT(*) FROM " . qi($table) . " WHERE $notNull AND $col NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}'";
    } elseif (preg_match('/^VARCHAR\((\d+)\)$/', $t, $m)) {
        $sql = "SELECT COUNT(*) FROM " . qi($table) . " WHERE $notNull AND CHAR_LENGTH($col) > " . (int) $m[1];
    } else {
        return 0; // widening to TEXT never loses anything
    }

    return (int) db_value($sql, [], 0);
}

function route_columns_update(int $id, string $columnName): never
{
    $user = require_admin();
    require_csrf();

    $d = load_dataset($id);
    assert_editable($d);

    if (is_system_column($columnName)) {
        fail('System columns cannot be changed.', 403);
    }

    $col   = dataset_column($d, $columnName);
    $body  = json_body();
    $table = (string) $d['table_name'];

    $newName  = $col['name'];
    $newLabel = $col['label'] ?? $col['name'];
    $newType  = $col['type'];
    $changes  = [];

    if (array_key_exists('label', $body)) {
        $label = body_string('label', '', 190) ?? '';

        if ($label === '') {
            fail('The column needs a name.', 422);
        }

        if ($label !== $newLabel) {
            $newLabel = $label;

            $taken   = array_diff(array_column($d['columns'], 'name'), [$col['name']]);
            $newName = ident_unique(ident_sanitize($label), array_merge($taken, SYSTEM_COLUMNS));
            $changes[] = 'renamed';
        }
    }

    if (array_key_exists('type', $body)) {
        $type = column_type_assert(body_string('type', '', 40) ?? '');

        if ($type !== $newType) {
            $bad = count_uncastable($table, $col['name'], $type);

            if ($bad > 0 && !body_bool('force', false)) {
                fail(
                    number_format($bad) . ' value' . ($bad === 1 ? '' : 's')
                    . ' in this column cannot be converted to ' . $type
                    . ' and would be lost. Widen the type, clean the data, or re-send with force.',
                    409,
                    ['uncastable' => $bad]
                );
            }

            $newType   = $type;
            $changes[] = 'retyped to ' . $type;
        }
    }

    if ($changes === []) {
        fail('Nothing to change.', 422);
    }

    // CHANGE handles rename and retype in one statement; both names are
    // asserted by qi() before they reach the string.
    db()->exec(
        'ALTER TABLE ' . qi($table) . ' CHANGE ' . qi($col['name']) . ' ' . qi($newName)
        . ' ' . column_type_assert($newType) . ' NULL'
    );

    $columns = $d['columns'];

    foreach ($columns as $i => $c) {
        if (($c['name'] ?? '') === $col['name']) {
            $columns[$i] = ['name' => $newName, 'label' => $newLabel, 'type' => $newType];
            break;
        }
    }

    save_columns($id, $columns);

    audit('column.update', $user, $id, [
        'from'    => $col['name'],
        'to'      => $newName,
        'changes' => $changes,
    ]);

    json_ok(['column' => ['name' => $newName, 'label' => $newLabel, 'type' => $newType]]);
}

function route_columns_delete(int $id, string $columnName): never
{
    $user = require_admin();
    require_csrf();

    $d = load_dataset($id);
    assert_editable($d);

    if (is_system_column($columnName)) {
        fail('System columns cannot be dropped.', 403);
    }

    $col = dataset_column($d, $columnName);

    if (count($d['columns']) <= 1) {
        fail('A dataset needs at least one column. Delete the whole dataset instead.', 409);
    }

    db()->exec('ALTER TABLE ' . qi($d['table_name']) . ' DROP COLUMN ' . qi($col['name']));

    save_columns($id, array_values(array_filter(
        $d['columns'],
        static fn($c) => ($c['name'] ?? '') !== $col['name']
    )));

    audit('column.delete', $user, $id, ['column' => $col['name']]);

    json_ok();
}

/* ── export ────────────────────────────────────────────────────────────── */

/**
 * Streams the whole dataset as CSV.
 *
 * Deliberately unbuffered: the master table is 248k rows, and building that in
 * memory before sending would exhaust memory_limit on a shared host.
 */
function route_dataset_export(int $id): never
{
    require_auth();

    $d       = load_dataset($id);
    $columns = $d['columns'];

    if ($columns === []) {
        fail('This dataset has no columns to export.', 422);
    }

    $select = [];
    foreach ($columns as $c) {
        $select[] = qi((string) $c['name']);
    }

    $filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $d['display_name']) ?: 'dataset';
    $filename .= '-' . date('Y-m-d') . '.csv';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'wb');

    // Excel needs the BOM to read UTF-8 correctly, matching what the existing
    // client-side CSV download already does.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map(static fn($c) => (string) ($c['label'] ?? $c['name']), $columns));

    $pdo = db();
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

    try {
        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', $select) . ' FROM ' . qi($d['table_name'])
            . ' ORDER BY ' . qsys('_row_id') . ' ASC'
        );
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            fputcsv($out, array_map(static fn($v) => $v ?? '', $row));
        }

        $stmt->closeCursor();
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }

    fclose($out);
    exit;
}
