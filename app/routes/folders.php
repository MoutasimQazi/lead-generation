<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/identifiers.php';
require_once __DIR__ . '/../lib/audit.php';

/**
 * Folders group datasets. They are organisational only — a folder holds no
 * data itself, and deleting one never deletes the datasets inside it.
 */

function folder_slug(string $name, ?int $ignoreId = null): string
{
    $base = ident_sanitize($name);

    $taken = array_column(
        $ignoreId === null
            ? db_all('SELECT slug FROM folders')
            : db_all('SELECT slug FROM folders WHERE id <> ?', [$ignoreId]),
        'slug'
    );

    return ident_unique($base, $taken);
}

function route_folders_list(): never
{
    $user = require_auth();

    // Do the tiny metadata aggregation in PHP. GROUP BY/ORDER BY makes MariaDB
    // create an Aria #sql-temptable on some cPanel builds, and this host has a
    // read-only tmpdir. Dataset rows themselves are not loaded here.
    $folders = db_all('SELECT id, name, slug, created_at FROM folders');
    $counts  = [];

    $datasetRows = $user['role'] === 'admin'
        ? db_all('SELECT folder_id, row_count FROM datasets WHERE folder_id IS NOT NULL')
        : db_all(
            'SELECT d.folder_id, d.row_count
               FROM dataset_assignments a
               JOIN datasets d ON d.id = a.dataset_id
              WHERE a.user_id = ? AND d.folder_id IS NOT NULL',
            [(int) $user['id']]
        );

    foreach ($datasetRows as $dataset) {
        $folderId = (int) $dataset['folder_id'];

        if (!isset($counts[$folderId])) {
            $counts[$folderId] = ['dataset_count' => 0, 'total_rows' => 0];
        }

        $counts[$folderId]['dataset_count']++;
        $counts[$folderId]['total_rows'] += (int) $dataset['row_count'];
    }

    if ($user['role'] !== 'admin') {
        $folders = array_values(array_filter(
            $folders,
            static fn(array $folder): bool => isset($counts[(int) $folder['id']])
        ));
    }

    usort($folders, static fn(array $a, array $b): int =>
        strcasecmp((string) $a['name'], (string) $b['name'])
    );

    json_ok(['folders' => array_map(static function ($f) use ($counts): array {
        $id = (int) $f['id'];

        return [
            'id'            => $id,
            'name'          => $f['name'],
            'slug'          => $f['slug'],
            'dataset_count' => $counts[$id]['dataset_count'] ?? 0,
            'total_rows'    => $counts[$id]['total_rows'] ?? 0,
            'created_at'    => $f['created_at'],
        ];
    }, $folders)]);
}

function route_folders_create(): never
{
    $user = require_admin();
    require_csrf();

    $name = body_string('name', '', 120) ?? '';

    if ($name === '') {
        fail('Give the folder a name.', 422);
    }

    if (db_one('SELECT id FROM folders WHERE name = ?', [$name])) {
        fail('A folder with that name already exists.', 409);
    }

    db_exec(
        'INSERT INTO folders (name, slug, created_by) VALUES (?, ?, ?)',
        [$name, folder_slug($name), (int) $user['id']]
    );

    $id = db_insert_id();

    audit('folder.create', $user, null, ['folder_id' => $id, 'name' => $name]);

    json_ok(['folder' => ['id' => $id, 'name' => $name]]);
}

function route_folders_update(int $id): never
{
    $user = require_admin();
    require_csrf();

    $folder = db_one('SELECT * FROM folders WHERE id = ?', [$id]);

    if (!$folder) {
        fail('That folder no longer exists.', 404);
    }

    $name = body_string('name', '', 120) ?? '';

    if ($name === '') {
        fail('Give the folder a name.', 422);
    }

    db_exec(
        'UPDATE folders SET name = ?, slug = ? WHERE id = ?',
        [$name, folder_slug($name, $id), $id]
    );

    audit('folder.rename', $user, null, ['folder_id' => $id, 'from' => $folder['name'], 'to' => $name]);

    json_ok();
}

function route_folders_delete(int $id): never
{
    $user = require_admin();
    require_csrf();

    $folder = db_one('SELECT * FROM folders WHERE id = ?', [$id]);

    if (!$folder) {
        fail('That folder no longer exists.', 404);
    }

    // Datasets survive; the FK is ON DELETE SET NULL, so they simply become
    // unfiled. Deleting a folder must never be a way to lose data by accident.
    $orphaned = (int) db_value('SELECT COUNT(*) FROM datasets WHERE folder_id = ?', [$id], 0);

    db_exec('DELETE FROM folders WHERE id = ?', [$id]);

    audit('folder.delete', $user, null, [
        'folder_id' => $id,
        'name'      => $folder['name'],
        'unfiled'   => $orphaned,
    ]);

    json_ok(['unfiled_datasets' => $orphaned]);
}
