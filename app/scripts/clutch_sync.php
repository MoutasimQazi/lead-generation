<?php
declare(strict_types=1);

/**
 * Copies Clutch leads into the `clutch` table. Safe to re-run; suitable for cron.
 *
 *     php app/scripts/clutch_sync.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../migrations.php';
require_once __DIR__ . '/../lib/clutch_sync.php';

try {
    db();
    // Make sure the core tables exist (folders/datasets) before registering the dataset.
    foreach (run_migrations() as $line) {
        echo '  ' . $line . "\n";
    }

    $res = clutch_sync('cron');
    echo "Synced: read {$res['read']} rows, clutch table now has {$res['total']}.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Clutch sync failed: ' . $e->getMessage() . "\n");
    exit(1);
}
