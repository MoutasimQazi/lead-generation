<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../migrations.php';
require_once __DIR__ . '/../lib/lead_matching.php';

$budget = isset($argv[1]) ? max(5, min(300, (int) $argv[1])) : 50;

try {
    foreach (run_migrations() as $line) {
        if (str_starts_with($line, '+')) {
            echo $line . "\n";
        }
    }

    $lockName = 'movenetics_flag_propagation_worker';
    if ((int) db_value('SELECT GET_LOCK(?, 1)', [$lockName], 0) !== 1) {
        echo "Another flag-propagation worker is already running.\n";
        exit(0);
    }

    try {
        // A previous run that was killed mid-job (deploy, OOM, host reboot)
        // leaves its job stuck in 'running' forever otherwise.
        db_exec(
            "UPDATE lead_flag_jobs SET job_state = 'pending'
              WHERE job_state = 'running' AND updated_at < NOW() - INTERVAL 10 MINUTE"
        );

        // Keeps the table from growing without bound; nothing reads a
        // finished job after this long.
        db_exec(
            "DELETE FROM lead_flag_jobs
              WHERE job_state IN ('done', 'failed') AND updated_at < NOW() - INTERVAL 7 DAY
              LIMIT 1000"
        );

        $deadline  = microtime(true) + $budget;
        $processed = 0;
        $failed    = 0;

        while (microtime(true) < $deadline) {
            $job = db_one("SELECT * FROM lead_flag_jobs WHERE job_state = 'pending' ORDER BY id ASC LIMIT 1");

            if (!$job) {
                break;
            }

            db_exec("UPDATE lead_flag_jobs SET job_state = 'running' WHERE id = ?", [(int) $job['id']]);

            try {
                $dataset = db_one('SELECT * FROM datasets WHERE id = ?', [(int) $job['dataset_id']]);

                if (!$dataset) {
                    throw new RuntimeException('Dataset no longer exists.');
                }

                $dataset['columns'] = json_decode((string) $dataset['columns_json'], true) ?: [];

                propagate_lead_flag(
                    $dataset,
                    (int) $job['row_id'],
                    (string) $job['flag_status'],
                    ['id' => (int) $job['set_by']]
                );

                db_exec("UPDATE lead_flag_jobs SET job_state = 'done' WHERE id = ?", [(int) $job['id']]);
                $processed++;
            } catch (Throwable $e) {
                db_exec(
                    "UPDATE lead_flag_jobs SET job_state = 'failed', error_message = ? WHERE id = ?",
                    [mb_substr($e->getMessage(), 0, 2000), (int) $job['id']]
                );
                error_log('[lead-site] flag propagation job ' . $job['id'] . ' failed: ' . $e->getMessage());
                $failed++;
            }
        }
    } finally {
        db_value('SELECT RELEASE_LOCK(?)', [$lockName]);
    }

    if ($processed === 0 && $failed === 0) {
        echo "Nothing queued.\n";
        exit(0);
    }

    echo "Processed $processed" . ($failed > 0 ? ", $failed failed" : '') . ".\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Flag propagation worker failed: ' . $e->getMessage() . "\n");
    exit(1);
}
