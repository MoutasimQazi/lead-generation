<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/audit.php';

/**
 * Admin user management.
 *
 * There is deliberately no public signup route. The first admin is created by
 * setup.php (which locks itself once one exists); everyone else is created here
 * by an admin.
 */

const MIN_PASSWORD_LENGTH = 12;

function validate_password(string $password): void
{
    if (strlen($password) < MIN_PASSWORD_LENGTH) {
        fail('Passwords must be at least ' . MIN_PASSWORD_LENGTH . ' characters.', 422);
    }
}

function validate_role(string $role): string
{
    if (!in_array($role, ['admin', 'employee'], true)) {
        fail('Role must be either admin or employee.', 422);
    }

    return $role;
}

/** Guards against locking everyone out of administration. */
function assert_not_last_admin(int $userId, string $action): void
{
    $row = db_one('SELECT role, is_active FROM app_users WHERE id = ?', [$userId]);

    if (!$row || $row['role'] !== 'admin' || (int) $row['is_active'] !== 1) {
        return;
    }

    $others = (int) db_value(
        'SELECT COUNT(*) FROM app_users WHERE role = "admin" AND is_active = 1 AND id <> ?',
        [$userId],
        0
    );

    if ($others === 0) {
        fail("This is the only active administrator — $action would lock everyone out. "
           . 'Promote another user to admin first.', 409);
    }
}

function route_users_list(): never
{
    require_admin();

    $users = db_all(
        'SELECT id, email, full_name, role, is_active, created_at, last_login_at
           FROM app_users ORDER BY role ASC, full_name ASC'
    );

    $assigned = [];
    foreach (db_all(
        'SELECT a.user_id, d.id, d.display_name
           FROM dataset_assignments a
           JOIN datasets d ON d.id = a.dataset_id
          ORDER BY d.display_name ASC'
    ) as $a) {
        $assigned[(int) $a['user_id']][] = ['id' => (int) $a['id'], 'display_name' => $a['display_name']];
    }

    json_ok(['users' => array_map(static fn($u) => [
        'id'                 => (int) $u['id'],
        'email'              => $u['email'],
        'full_name'          => $u['full_name'],
        'role'               => $u['role'],
        'is_active'          => (int) $u['is_active'] === 1,
        'created_at'         => $u['created_at'],
        'last_login_at'      => $u['last_login_at'],
        'assigned_datasets'  => $assigned[(int) $u['id']] ?? [],
    ], $users)]);
}

function route_users_create(): never
{
    $actor = require_admin();
    require_csrf();

    $email    = strtolower(body_string('email', '', 190) ?? '');
    $name     = body_string('full_name', '', 120) ?? '';
    $role     = validate_role(body_string('role', 'employee', 20) ?? 'employee');
    $password = (string) (json_body()['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('That does not look like a valid email address.', 422);
    }

    if ($name === '') {
        fail('Enter the person\'s name.', 422);
    }

    validate_password($password);

    if (db_one('SELECT id FROM app_users WHERE email = ?', [$email])) {
        fail('An account with that email already exists.', 409);
    }

    db_exec(
        'INSERT INTO app_users (email, full_name, password_hash, role) VALUES (?, ?, ?, ?)',
        [$email, $name, password_hash($password, PASSWORD_DEFAULT), $role]
    );

    $id = db_insert_id();

    audit('user.create', $actor, null, ['user_id' => $id, 'email' => $email, 'role' => $role]);

    json_ok(['user' => ['id' => $id, 'email' => $email, 'full_name' => $name, 'role' => $role]]);
}

function route_users_update(int $id): never
{
    $actor = require_admin();
    require_csrf();

    $target = db_one('SELECT * FROM app_users WHERE id = ?', [$id]);

    if (!$target) {
        fail('That account no longer exists.', 404);
    }

    $body    = json_body();
    $changes = [];
    $sets    = [];
    $params  = [];

    if (array_key_exists('full_name', $body)) {
        $name = body_string('full_name', '', 120) ?? '';

        if ($name === '') {
            fail('Name cannot be blank.', 422);
        }

        $sets[]    = 'full_name = ?';
        $params[]  = $name;
        $changes[] = 'name';
    }

    if (array_key_exists('role', $body)) {
        $role = validate_role(body_string('role', 'employee', 20) ?? 'employee');

        if ($role !== 'admin') {
            assert_not_last_admin($id, 'demoting them');
        }

        $sets[]    = 'role = ?';
        $params[]  = $role;
        $changes[] = 'role=' . $role;
    }

    if (array_key_exists('is_active', $body)) {
        $active = body_bool('is_active', true);

        if (!$active) {
            if ($id === (int) $actor['id']) {
                fail('You cannot deactivate your own account.', 409);
            }
            assert_not_last_admin($id, 'deactivating them');
        }

        $sets[]    = 'is_active = ?';
        $params[]  = $active ? 1 : 0;
        $changes[] = $active ? 'reactivated' : 'deactivated';
    }

    if (!empty($body['password'])) {
        $password = (string) $body['password'];
        validate_password($password);

        $sets[]    = 'password_hash = ?';
        $params[]  = password_hash($password, PASSWORD_DEFAULT);
        $changes[] = 'password reset';
    }

    if ($sets === []) {
        fail('Nothing to update.', 422);
    }

    $params[] = $id;
    db_exec('UPDATE app_users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

    audit('user.update', $actor, null, [
        'user_id' => $id,
        'email'   => $target['email'],
        'changes' => $changes,
    ]);

    json_ok(['changes' => $changes]);
}

/**
 * Deactivates rather than deletes.
 *
 * audit_log and datasets reference users; a hard delete would blank the record
 * of who uploaded what. Deactivation ends their access immediately — see
 * current_user(), which re-reads is_active on every request.
 */
function route_users_delete(int $id): never
{
    $actor = require_admin();
    require_csrf();

    $target = db_one('SELECT * FROM app_users WHERE id = ?', [$id]);

    if (!$target) {
        fail('That account no longer exists.', 404);
    }

    if ($id === (int) $actor['id']) {
        fail('You cannot deactivate your own account.', 409);
    }

    assert_not_last_admin($id, 'deactivating them');

    db_exec('UPDATE app_users SET is_active = 0 WHERE id = ?', [$id]);

    audit('user.deactivate', $actor, null, ['user_id' => $id, 'email' => $target['email']]);

    json_ok();
}

/* ── this employee's dataset assignments ──────────────────────────────────
   The reverse of route_dataset_assignments_get()/_update() in datasets.php:
   those work dataset-first ("who can see this table"), these work
   employee-first ("what can this person see"). Both write the same
   dataset_assignments rows. */

function load_assignable_employee(int $userId): array
{
    $user = db_one('SELECT * FROM app_users WHERE id = ? AND role = "employee"', [$userId]);

    if (!$user) {
        fail('That employee account no longer exists.', 404);
    }

    return $user;
}

function route_user_assignments_get(int $userId): never
{
    require_admin();
    $employee = load_assignable_employee($userId);

    $datasets = db_all(
        'SELECT d.id, d.display_name, d.is_protected, d.status, f.name AS folder_name
           FROM datasets d
           LEFT JOIN folders f ON f.id = d.folder_id'
    );
    $assigned = array_map('intval', array_column(
        db_all('SELECT dataset_id FROM dataset_assignments WHERE user_id = ?', [$userId]),
        'dataset_id'
    ));

    usort($datasets, static function (array $a, array $b): int {
        $protected = (int) $b['is_protected'] <=> (int) $a['is_protected'];
        return $protected !== 0 ? $protected : strcasecmp((string) $a['display_name'], (string) $b['display_name']);
    });

    json_ok([
        'employee' => ['id' => (int) $employee['id'], 'full_name' => $employee['full_name']],
        'datasets' => array_map(static fn(array $d): array => [
            'id'            => (int) $d['id'],
            'display_name'  => $d['display_name'],
            'folder_name'   => $d['folder_name'],
            'is_protected'  => (int) $d['is_protected'] === 1,
            'status'        => $d['status'],
            'assigned'      => in_array((int) $d['id'], $assigned, true),
        ], $datasets),
    ]);
}

function route_user_assignments_update(int $userId): never
{
    $admin = require_admin();
    require_csrf();
    load_assignable_employee($userId);

    $ids = json_body()['dataset_ids'] ?? [];
    if (!is_array($ids)) {
        fail('dataset_ids must be an array.', 422);
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, static fn(int $datasetId): bool => $datasetId > 0));

    if ($ids !== []) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $valid = array_map('intval', array_column(
            db_all('SELECT id FROM datasets WHERE id IN (' . $marks . ')', $ids),
            'id'
        ));
        sort($ids);
        sort($valid);

        if ($ids !== $valid) {
            fail('One or more selected datasets are unavailable.', 422);
        }
    }

    db_transaction(static function () use ($userId, $ids, $admin): void {
        $existing = array_map('intval', array_column(
            db_all('SELECT dataset_id FROM dataset_assignments WHERE user_id = ?', [$userId]),
            'dataset_id'
        ));

        if ($ids === []) {
            db_exec('DELETE FROM dataset_assignments WHERE user_id = ?', [$userId]);
            return;
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        db_exec(
            'DELETE FROM dataset_assignments WHERE user_id = ? AND dataset_id NOT IN (' . $marks . ')',
            array_merge([$userId], $ids)
        );

        foreach ($ids as $datasetId) {
            if (in_array($datasetId, $existing, true)) {
                continue;
            }

            db_exec(
                'INSERT INTO dataset_assignments
                   (dataset_id, user_id, search_enabled, assigned_by)
                 VALUES (?, ?, 1, ?)',
                [$datasetId, $userId, (int) $admin['id']]
            );
        }
    });

    audit('user.assign_datasets', $admin, null, ['user_id' => $userId, 'dataset_ids' => $ids]);
    json_ok(['dataset_ids' => $ids]);
}
