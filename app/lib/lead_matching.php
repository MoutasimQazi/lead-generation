<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/identifiers.php';

/**
 * Duplicate-lead flag propagation.
 *
 * The same lead often shows up more than once — re-scraped into a later
 * upload, sitting in more than one dataset, or merged from several source
 * files into one table. A flag like "contacted" describes the lead, not the
 * row, so setting it on one row also sets it on every other row (in this
 * dataset or any other "ready" one) that shares a normalized email or phone
 * number with it. A row that already carries its own flag is left alone —
 * this only fills in rows nobody has touched yet, it never overwrites a
 * deliberate choice made on a specific row.
 */

/**
 * Column names heuristically identified as email/phone — the same
 * name.includes('email') / name.includes('phone') rule dataset.js uses
 * client-side to decide which cells render as copy/tel-shaped, so "what
 * counts as a contact column" stays consistent between the two.
 */
function contact_identifier_columns(array $columns): array
{
    $email = [];
    $phone = [];

    foreach ($columns as $c) {
        $name = strtolower((string) ($c['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        if (str_contains($name, 'email')) {
            $email[] = (string) $c['name'];
        } elseif (str_contains($name, 'phone')) {
            $phone[] = (string) $c['name'];
        }
    }

    return ['email' => $email, 'phone' => $phone];
}

function normalize_lead_email(?string $v): ?string
{
    $v = strtolower(trim((string) $v));

    return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $v) ? $v : null;
}

/**
 * Digits only, last 10 kept (drops a leading country code like +1 so
 * "+1 415-555-0100" and "(415) 555-0100" match). Numbers under 7 digits are
 * too short to trust as a unique identifier and are dropped rather than
 * risking collisions between unrelated short/blank-ish values.
 */
function normalize_lead_phone(?string $v): ?string
{
    $digits = preg_replace('/\D+/', '', (string) $v) ?? '';

    return strlen($digits) >= 7 ? substr($digits, -10) : null;
}

/**
 * Copies $status onto every other row (in any "ready" dataset) that shares
 * an email or phone number with (dataset_id=$sourceDataset['id'], row_id=
 * $sourceRowId), skipping rows that already carry a flag of their own.
 *
 * @return array<int, int[]> dataset_id => newly-flagged row ids
 */
function propagate_lead_flag(array $sourceDataset, int $sourceRowId, string $status, array $user): array
{
    $sourceContacts = contact_identifier_columns($sourceDataset['columns']);
    if ($sourceContacts['email'] === [] && $sourceContacts['phone'] === []) {
        return [];
    }

    $sourceRow = db_one(
        'SELECT * FROM ' . qi((string) $sourceDataset['table_name']) . ' WHERE ' . qsys('_row_id') . ' = ?',
        [$sourceRowId]
    );
    if (!$sourceRow) {
        return [];
    }

    $emails = [];
    foreach ($sourceContacts['email'] as $col) {
        $n = normalize_lead_email($sourceRow[$col] ?? null);
        if ($n !== null) {
            $emails[$n] = true;
        }
    }

    $phones = [];
    foreach ($sourceContacts['phone'] as $col) {
        $n = normalize_lead_phone($sourceRow[$col] ?? null);
        if ($n !== null) {
            $phones[$n] = true;
        }
    }

    $emails = array_keys($emails);
    $phones = array_keys($phones);

    if ($emails === [] && $phones === []) {
        return [];
    }

    $datasets = db_all("SELECT id, table_name, columns_json FROM datasets WHERE status = 'ready'");
    $sourceId = (int) $sourceDataset['id'];
    $flagged  = [];

    foreach ($datasets as $ds) {
        $datasetId = (int) $ds['id'];
        $columns   = json_decode((string) $ds['columns_json'], true) ?: [];
        $contacts  = contact_identifier_columns($columns);

        if ($contacts['email'] === [] && $contacts['phone'] === []) {
            continue;
        }

        try {
            $table = qi((string) $ds['table_name']);
        } catch (InvalidArgumentException) {
            continue;
        }

        $conds  = [];
        $params = [];

        if ($emails !== []) {
            $marks = implode(',', array_fill(0, count($emails), '?'));
            foreach ($contacts['email'] as $col) {
                $conds[] = 'LOWER(TRIM(' . qi($col) . ')) IN (' . $marks . ')';
                $params  = array_merge($params, $emails);
            }
        }

        if ($phones !== []) {
            $marks = implode(',', array_fill(0, count($phones), '?'));
            foreach ($contacts['phone'] as $col) {
                $conds[] = "RIGHT(REGEXP_REPLACE(" . qi($col) . ", '[^0-9]', ''), 10) IN (" . $marks . ')';
                $params  = array_merge($params, $phones);
            }
        }

        if ($conds === []) {
            continue;
        }

        $sql = 'SELECT t.' . qsys('_row_id') . ' AS row_id
                  FROM ' . $table . ' t
                  LEFT JOIN lead_flags f ON f.dataset_id = ? AND f.row_id = t.' . qsys('_row_id') . '
                 WHERE f.dataset_id IS NULL
                   AND (' . implode(' OR ', $conds) . ')';
        $args = array_merge([$datasetId], $params);

        if ($datasetId === $sourceId) {
            $sql   .= ' AND t.' . qsys('_row_id') . ' <> ?';
            $args[] = $sourceRowId;
        }

        $sql .= ' LIMIT 1000';

        $rows = db_all($sql, $args);
        if ($rows === []) {
            continue;
        }

        $ids = array_map(static fn(array $r): int => (int) $r['row_id'], $rows);

        $valuesSql    = implode(',', array_fill(0, count($ids), '(?, ?, ?, ?)'));
        $insertParams = [];
        foreach ($ids as $rid) {
            array_push($insertParams, $datasetId, $rid, $status, (int) $user['id']);
        }

        db_exec(
            'INSERT IGNORE INTO lead_flags (dataset_id, row_id, status, set_by) VALUES ' . $valuesSql,
            $insertParams
        );

        $flagged[$datasetId] = $ids;
    }

    return $flagged;
}
