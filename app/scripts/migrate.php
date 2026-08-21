<?php
declare(strict_types=1);

/**
 * Creates or updates the core schema. Safe to re-run.
 *
 *     php app/scripts/migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script runs from the command line only. Use setup.php in a browser instead.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../migrations.php';

try {
    echo "Connecting to " . config('db_name') . " at " . config('db_host') . " …\n";
    db();

    foreach (run_migrations() as $line) {
        echo '  ' . $line . "\n";
    }

    echo "\nDone.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nMigration failed: " . $e->getMessage() . "\n");
    exit(1);
}
