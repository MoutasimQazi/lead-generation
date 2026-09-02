<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

/**
 * Admin overview: how each employee's lead work is going, and how active the
 * team has been. Everything here is derived from lead_flags (who flagged what,
 * and when) and audit_log's "search" entries (who asked the AI what) — no new
 * tracking, just aggregating what the app already records.
 */

const DASHBOARD_STATUSES = ['contacted', 'unreachable', 'won', 'lost'];

function route_dashboard(): never
{
    require_admin();

    $employees = db_all(
        "SELECT id, full_name, email FROM app_users WHERE role = 'employee' AND is_active = 1 ORDER BY full_name ASC"
    );

    $flagsByUser = [];
    foreach (db_all(
        'SELECT set_by AS user_id, status, COUNT(*) AS n
           FROM lead_flags
          WHERE set_by IS NOT NULL
          GROUP BY set_by, status'
    ) as $r) {
        $flagsByUser[(int) $r['user_id']][$r['status']] = (int) $r['n'];
    }

    $searchesByUser = [];
    foreach (db_all(
        "SELECT user_id, COUNT(*) AS n, MAX(created_at) AS last_at
           FROM audit_log
          WHERE action = 'search' AND user_id IS NOT NULL AND created_at >= NOW() - INTERVAL 30 DAY
          GROUP BY user_id"
    ) as $r) {
        $searchesByUser[(int) $r['user_id']] = ['count' => (int) $r['n'], 'last_at' => $r['last_at']];
    }

    // Team-wide flagging activity for the last 30 days, one point per day —
    // missing days are filled with zero so the line doesn't skip gaps.
    $dailyCounts = [];
    foreach (db_all(
        'SELECT DATE(set_at) AS d, COUNT(*) AS n
           FROM lead_flags
          WHERE set_at >= CURDATE() - INTERVAL 29 DAY
          GROUP BY DATE(set_at)'
    ) as $r) {
        $dailyCounts[(string) $r['d']] = (int) $r['n'];
    }
    $daily = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $daily[] = ['date' => $date, 'count' => $dailyCounts[$date] ?? 0];
    }

    $totals = array_fill_keys(DASHBOARD_STATUSES, 0);
    $totalSearches = 0;
    $people = [];

    foreach ($employees as $e) {
        $id     = (int) $e['id'];
        $counts = $flagsByUser[$id] ?? [];
        $row    = ['id' => $id, 'full_name' => $e['full_name'], 'email' => $e['email']];
        $total  = 0;

        foreach (DASHBOARD_STATUSES as $status) {
            $n = $counts[$status] ?? 0;
            $row[$status] = $n;
            $total += $n;
            $totals[$status] += $n;
        }

        $row['total_flagged']  = $total;
        $row['win_rate']       = ($row['won'] + $row['lost']) > 0
            ? round($row['won'] / ($row['won'] + $row['lost']) * 100, 1)
            : null;
        $row['searches_30d']   = $searchesByUser[$id]['count'] ?? 0;
        $row['last_search_at'] = $searchesByUser[$id]['last_at'] ?? null;

        $totalSearches += $row['searches_30d'];
        $people[]       = $row;
    }

    // Busiest employees lead both the chart and the table — the order a
    // reader actually wants when scanning "who's doing the most."
    usort($people, static fn(array $a, array $b): int => $b['total_flagged'] <=> $a['total_flagged']);

    $wonLost = $totals['won'] + $totals['lost'];

    json_ok([
        'employees' => $people,
        'totals'    => $totals + [
            'total_flagged'    => array_sum($totals),
            'win_rate'         => $wonLost > 0 ? round($totals['won'] / $wonLost * 100, 1) : null,
            'searches_30d'     => $totalSearches,
            'active_employees' => count($employees),
        ],
        'daily' => $daily,
    ]);
}
