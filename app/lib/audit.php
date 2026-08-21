<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../http.php';

/**
 * Append-only record of who changed what.
 *
 * Every mutation of a dataset goes through here. Auditing must never be the
 * reason a request fails, so a write failure is logged and swallowed rather
 * than propagated.
 */
function audit(string $action, ?array $user = null, ?int $datasetId = null, array $detail = []): void
{
    try {
        db_exec(
            'INSERT INTO audit_log (user_id, user_email, action, dataset_id, detail, ip)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $user['id'] ?? null,
                mb_substr((string) ($user['email'] ?? ''), 0, 190),
                mb_substr($action, 0, 60),
                $datasetId,
                $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_SLASHES),
                mb_substr(client_ip(), 0, 45),
            ]
        );
    } catch (Throwable $e) {
        error_log('[lead-site] audit write failed: ' . $e->getMessage());
    }
}

/** Recent audit entries, newest first. */
function audit_recent(int $limit = 50, ?int $datasetId = null): array
{
    $limit = max(1, min(500, $limit));

    if ($datasetId !== null) {
        return db_all(
            'SELECT * FROM audit_log WHERE dataset_id = ? ORDER BY id DESC LIMIT ' . $limit,
            [$datasetId]
        );
    }

    return db_all('SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . $limit);
}
