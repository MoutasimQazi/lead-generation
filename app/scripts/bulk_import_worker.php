<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../migrations.php';
require_once __DIR__ . '/../lib/large_importer.php';
require_once __DIR__ . '/../routes/chunk_uploads.php';

$budget = isset($argv[1]) ? max(10, min(300, (int) $argv[1])) : 50;

try {
    foreach (run_migrations() as $line) {
        if (str_starts_with($line, '+')) {
            echo $line . "\n";
        }
    }

    $lockName = 'movenetics_large_import_worker';
    if ((int) db_value('SELECT GET_LOCK(?, 1)', [$lockName], 0) !== 1) {
        echo "Another bulk-import worker is already running.\n";
        exit(0);
    }

    try {
        $assembled = chunk_upload_assemble_next();
        if ($assembled) {
            echo 'Assembled and queued: ' . $assembled['file_name'] . "\n";
        }
        $result = large_import_work($budget);
    } finally {
        db_value('SELECT RELEASE_LOCK(?)', [$lockName]);
    }
    if (!$result['worked']) {
        echo $result['message'] . "\n";
        exit(0);
    }

    $progress = $result['progress'];
    echo sprintf(
        "%s: %s%%, %s rows%s\n",
        $progress['name'] ?? 'Import',
        $progress['percent'] ?? 0,
        number_format((int) ($progress['rows'] ?? 0)),
        !empty($result['done']) ? ' (done)' : ''
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Bulk import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
