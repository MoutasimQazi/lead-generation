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
    require_auth();

    $folders = db_all(
        'SELECT f.id, f.name, f.slug, f.created_at,
                COUNT(d.id)                    AS dataset_count,
                COALESCE(SUM(d.row_count), 0)  AS total_rows
           FROM folders f
           LEFT JOIN datasets d ON d.folder_id = f.id
       GROUP BY f.id, f.name, f.slug, f.created_at
       ORDER BY f.name ASC'
    );

    json_ok(['folders' => array_map(static fn($f) => [
        'id'            => (int) $f['id'],
        'name'          => $f['name'],
        'slug'          => $f['slug'],
        'dataset_count' => (int) $f['dataset_count'],
        'total_rows'    => (int) $f['total_rows'],
        'created_at'    => $f['created_at'],
    ], $folders)]);
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
