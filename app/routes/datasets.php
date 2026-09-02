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

    $user = current_user();
    if ($user && $user['role'] !== 'admin') {
        $assignment = db_one(
            'SELECT search_enabled FROM dataset_assignments WHERE dataset_id = ? AND user_id = ?',
            [$id, (int) $user['id']]
        );

        if (!$assignment) {
            fail('This dataset has not been assigned to your account.', 403);
        }

        $row['user_search_enabled'] = (int) $assignment['search_enabled'] === 1;
    }

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
        'user_search_enabled' => array_key_exists('user_search_enabled', $d)
            ? (bool) $d['user_search_enabled']
            : true,
        'is_protected'  => (int) $d['is_protected'] === 1,
        'status'        => $d['status'],
        'error_message' => $d['error_message'],
        'created_at'    => $d['created_at'],
        'updated_at'    => $d['updated_at'],
    ];
}

function route_dataset_filter_options(int $id): never
{
    require_auth();

    $dataset = load_dataset($id);
    $table = qi((string) $dataset['table_name']);
    $options = [];

    foreach ($dataset['columns'] as $column) {
        $name = (string) $column['name'];
        $rows = db_all(
            'SELECT DISTINCT ' . qi($name) . ' AS value
               FROM ' . $table . '
              WHERE ' . qi($name) . ' IS NOT NULL
                AND ' . qi($name) . ' <> ""
              ORDER BY ' . qi($name) . '
              LIMIT 101'
        );

        $values = array_map(static fn(array $row): string => (string) $row['value'], $rows);
        $options[$name] = [
            'values'  => array_slice($values, 0, 100),
            'limited' => count($values) > 100,
        ];
    }

    json_ok(['options' => $options]);
}

/* ── list & read ───────────────────────────────────────────────────────── */

function route_datasets_list(): never
{
    $user = require_auth();

    if ($user['role'] === 'admin') {
        $rows = db_all(
            'SELECT d.*, f.name AS folder_name
               FROM datasets d
               LEFT JOIN folders f ON f.id = d.folder_id'
        );
    } else {
        $rows = db_all(
            'SELECT d.*, f.name AS folder_name,
                    a.search_enabled AS user_search_enabled
               FROM dataset_assignments a
               JOIN datasets d ON d.id = a.dataset_id
               LEFT JOIN folders f ON f.id = d.folder_id
              WHERE a.user_id = ?',
            [(int) $user['id']]
        );
    }

    // Sort this small metadata list in PHP. SQL ORDER BY on the join can spill
    // to MariaDB's Aria tmpdir, which is read-only on the current cPanel host.
    usort($rows, static function (array $a, array $b): int {
        $protected = (int) $b['is_protected'] <=> (int) $a['is_protected'];
        if ($protected !== 0) {
            return $protected;
        }

        $folder = strcasecmp((string) ($a['folder_name'] ?? ''), (string) ($b['folder_name'] ?? ''));
        return $folder !== 0
            ? $folder
            : strcasecmp((string) $a['display_name'], (string) $b['display_name']);
    });

    // Who each dataset is assigned to, for the admin list view only —
    // employees already know their own assignments from which rows they see.
    $assignedNames = [];
    if ($user['role'] === 'admin') {
        foreach (db_all(
            'SELECT a.dataset_id, u.full_name
               FROM dataset_assignments a
               JOIN app_users u ON u.id = a.user_id
              ORDER BY u.full_name ASC'
        ) as $a) {
            $assignedNames[(int) $a['dataset_id']][] = $a['full_name'];
        }
    }

    $out = [];

    foreach ($rows as $r) {
        $summary                  = dataset_summary($r);
        $summary['folder_name']   = $r['folder_name'];
        if ($user['role'] === 'admin') {
            $summary['assigned_names'] = $assignedNames[(int) $r['id']] ?? [];
        }
        $out[] = $summary;
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

    // Return authoritative state so the UI does not depend on a second GET.
    $updated = load_dataset($id);

    json_ok([
        'changes' => $changes,
        'dataset' => dataset_summary($updated) + [
            'columns' => $updated['columns'],
            'files'   => $updated['files'],
        ],
    ]);
}

/* ── employee assignment and personal search preference ───────────────── */

function route_dataset_assignments_get(int $id): never
{
    require_admin();
    load_dataset($id);

    $employees = db_all(
        'SELECT id, email, full_name, is_active
           FROM app_users
          WHERE role = "employee"'
    );
    $assigned = [];

    foreach (db_all(
        'SELECT user_id, search_enabled FROM dataset_assignments WHERE dataset_id = ?',
        [$id]
    ) as $row) {
        $assigned[(int) $row['user_id']] = (int) $row['search_enabled'] === 1;
    }

    usort($employees, static fn(array $a, array $b): int =>
        strcasecmp((string) $a['full_name'], (string) $b['full_name'])
    );

    json_ok(['employees' => array_map(static fn(array $employee): array => [
        'id'             => (int) $employee['id'],
        'email'          => $employee['email'],
        'full_name'      => $employee['full_name'],
        'is_active'      => (int) $employee['is_active'] === 1,
        'assigned'       => array_key_exists((int) $employee['id'], $assigned),
        'search_enabled' => $assigned[(int) $employee['id']] ?? false,
    ], $employees)]);
}

function route_dataset_assignments_update(int $id): never
{
    $admin = require_admin();
    require_csrf();
    load_dataset($id);

    $ids = json_body()['user_ids'] ?? [];
    if (!is_array($ids)) {
        fail('user_ids must be an array.', 422);
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, static fn(int $userId): bool => $userId > 0));

    if ($ids !== []) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $valid = array_map('intval', array_column(db_all(
            'SELECT id FROM app_users WHERE role = "employee" AND is_active = 1 AND id IN (' . $marks . ')',
            $ids
        ), 'id'));
        sort($ids);
        sort($valid);

        if ($ids !== $valid) {
            fail('One or more selected employees are unavailable.', 422);
        }
    }

    db_transaction(static function () use ($id, $ids, $admin): void {
        $existing = [];
        foreach (db_all(
            'SELECT user_id, search_enabled FROM dataset_assignments WHERE dataset_id = ?',
            [$id]
        ) as $row) {
            $existing[(int) $row['user_id']] = (int) $row['search_enabled'];
        }

        if ($ids === []) {
            db_exec('DELETE FROM dataset_assignments WHERE dataset_id = ?', [$id]);
            return;
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        db_exec(
            'DELETE FROM dataset_assignments WHERE dataset_id = ? AND user_id NOT IN (' . $marks . ')',
            array_merge([$id], $ids)
        );

        foreach ($ids as $userId) {
            if (array_key_exists($userId, $existing)) {
                continue;
            }

            db_exec(
                'INSERT INTO dataset_assignments
                   (dataset_id, user_id, search_enabled, assigned_by)
                 VALUES (?, ?, 1, ?)',
                [$id, $userId, (int) $admin['id']]
            );
        }
    });

    audit('dataset.assign', $admin, $id, ['employee_ids' => $ids]);
    json_ok(['user_ids' => $ids]);
}

function route_dataset_search_preference(int $id): never
{
    $user = require_auth();
    require_csrf();

    if ($user['role'] === 'admin') {
        fail('Administrators control global search availability instead.', 422);
    }

    $dataset = load_dataset($id); // also proves this employee is assigned
    if ((int) $dataset['is_searchable'] !== 1) {
        fail('An administrator has not enabled AI search for this dataset.', 409);
    }

    $enabled = body_bool('enabled', false);
    db_exec(
        'UPDATE dataset_assignments SET search_enabled = ? WHERE dataset_id = ? AND user_id = ?',
        [$enabled ? 1 : 0, $id, (int) $user['id']]
    );

    audit('dataset.search_preference', $user, $id, ['enabled' => $enabled]);
    json_ok(['enabled' => $enabled]);
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

    // Dataset browsing is deliberately capped at 50 rows. Larger result sets
    // must be paged so the browser never downloads or renders the whole table.
    $per    = query_int('per', 50, 10, 50);
    $page   = query_int('page', 1, 1, 1000000);
    $afterRaw = query_string('after', '0', 20);
    $after = ctype_digit($afterRaw) ? (int) $afterRaw : 0;
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
    $countWhere = $where;
    $countParams = $params;
    // row_count is maintained as data is imported or edited. Reuse it for the
    // common unfiltered view instead of scanning the physical table each time.
    $total = $countWhere === ''
        ? (int) $d['row_count']
        : (int) db_value('SELECT COUNT(*) FROM ' . $table . $countWhere, $countParams, 0);

    $useCursor = $search === '' && $filters === [] && $sort === '';
    if ($useCursor && $after > 0) {
        $where .= $where === '' ? ' WHERE ' : ' AND ';
        $where .= qsys('_row_id') . ' > ?';
        $params[] = $after;
    }
    $offset = $useCursor ? 0 : ($page - 1) * $per;
    $fetchLimit = $per + 1;

    // $per and $offset are ints from query_int, so interpolating them is safe;
    // LIMIT does not accept bound parameters under real prepared statements.
    $rows = db_all(
        'SELECT ' . implode(', ', $select) . ' FROM ' . $table . $where
        . ' ORDER BY ' . $orderBy . ' LIMIT ' . $fetchLimit . ' OFFSET ' . $offset,
        $params
    );
    $hasMore = count($rows) > $per;
    if ($hasMore) {
        array_pop($rows);
    }
    $last = $rows === [] ? null : $rows[array_key_last($rows)];

    attach_row_flags($id, $rows);

    json_ok([
        'rows'    => $rows,
        'columns' => $columns,
        'total'   => $total,
        'page'    => $page,
        'per'     => $per,
        'pages'   => max(1, (int) ceil($total / $per)),
        'has_more' => $hasMore,
        'next_cursor' => $useCursor && $last ? (int) $last['_row_id'] : null,
        'cursor_mode' => $useCursor,
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

/** Every status a lead row can be flagged with, and what the UI calls it. */
const LEAD_FLAG_STATUSES = [
    'contacted'   => 'Contacted',
    'unreachable' => 'Unable to contact',
    'won'         => 'Won',
    'lost'        => 'Lost',
];

/** Merges each row's flag (if any) into it, in one query for the whole page. */
function attach_row_flags(int $datasetId, array &$rows): void
{
    if ($rows === []) {
        return;
    }

    $rowIds = array_map(static fn(array $r): int => (int) $r['_row_id'], $rows);
    $marks  = implode(',', array_fill(0, count($rowIds), '?'));

    $flags = [];
    foreach (db_all(
        'SELECT f.row_id, f.status, f.set_at, u.full_name AS set_by_name
           FROM lead_flags f
           LEFT JOIN app_users u ON u.id = f.set_by
          WHERE f.dataset_id = ? AND f.row_id IN (' . $marks . ')',
        array_merge([$datasetId], $rowIds)
    ) as $f) {
        $flags[(int) $f['row_id']] = [
            'status'   => $f['status'],
            'label'    => LEAD_FLAG_STATUSES[$f['status']] ?? $f['status'],
            'set_by'   => $f['set_by_name'],
            'set_at'   => $f['set_at'],
        ];
    }

    foreach ($rows as &$row) {
        $row['flag'] = $flags[(int) $row['_row_id']] ?? null;
    }
    unset($row);
}

/**
 * Sets or clears a lead's status. Open to any user with access to the
 * dataset (admin, or an assigned employee — same check load_dataset() already
 * makes) rather than admin-only: this tracks outreach work, not data edits,
 * so it deliberately skips assert_editable() and works on the protected
 * master table too.
 */
function route_row_flag_update(int $id, int $rowId): never
{
    $user = require_auth();
    require_csrf();

    $d = load_dataset($id);

    if (!row_exists($d['table_name'], $rowId)) {
        fail('That row no longer exists.', 404);
    }

    $status = json_body()['status'] ?? null;

    if ($status === null || $status === '') {
        db_exec('DELETE FROM lead_flags WHERE dataset_id = ? AND row_id = ?', [$id, $rowId]);
        audit('row.flag_clear', $user, $id, ['row_id' => $rowId]);
        json_ok(['flag' => null]);
    }

    if (!is_string($status) || !array_key_exists($status, LEAD_FLAG_STATUSES)) {
        fail('Status must be one of: ' . implode(', ', array_keys(LEAD_FLAG_STATUSES)) . '.', 422);
    }

    db_exec(
        'INSERT INTO lead_flags (dataset_id, row_id, status, set_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), set_by = VALUES(set_by), set_at = CURRENT_TIMESTAMP',
        [$id, $rowId, $status, (int) $user['id']]
    );

    audit('row.flag_set', $user, $id, ['row_id' => $rowId, 'status' => $status]);

    json_ok([
        'flag' => [
            'status' => $status,
            'label'  => LEAD_FLAG_STATUSES[$status],
            'set_by' => $user['full_name'],
            'set_at' => date('Y-m-d H:i:s'),
        ],
    ]);
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
    require_admin();

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
