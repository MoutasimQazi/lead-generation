<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../migrations.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/clutch_sync.php';

/** GET /api/clutch — dataset info, recent sync runs and the Clutch app log. Admin only. */
function route_clutch_status(): never
{
    require_admin();
    ensure_management_schema();

    $datasetId = clutch_ensure_schema();
    $history   = clutch_sync_history(10);
    $log       = clutch_log_tail(query_int('lines', 200, 10, 1000));

    json_ok([
        'configured' => clutch_is_configured(),
        'dataset_id' => $datasetId,
        'row_count'  => (int) db_value('SELECT COUNT(*) FROM ' . qi(CLUTCH_TABLE), [], 0),
        'syncs'      => array_map(static fn(array $r): array => [
            'id'          => (int) $r['id'],
            'started_at'  => $r['started_at'],
            'finished_at' => $r['finished_at'],
            'status'      => $r['status'],
            'rows_read'   => (int) $r['rows_read'],
            'total_after' => (int) $r['total_after'],
            'message'     => $r['message'],
            'triggered_by' => $r['triggered_by'],
        ], $history),
        'log' => $log,
    ]);
}

/** POST /api/clutch/sync — pull the latest leads from the Clutch database. Admin only. */
function route_clutch_sync(): never
{
    $user = require_admin();
    require_csrf();
    ensure_management_schema();

    if (!clutch_is_configured()) {
        fail('Clutch source database is not configured on the server (CLUTCH_DB_* in .env).', 503);
    }

    try {
        $res = clutch_sync((string) $user['email']);
    } catch (Throwable $e) {
        fail('Sync failed: ' . $e->getMessage(), 502);
    }

    audit('clutch.sync', $user, clutch_ensure_schema(), ['read' => $res['read'], 'total' => $res['total']]);
    json_ok($res);
}
