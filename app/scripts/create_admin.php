<?php
declare(strict_types=1);

/**
 * Creates or promotes an administrator.
 *
 *     php app/scripts/create_admin.php
 *     php app/scripts/create_admin.php you@example.com "Your Name"
 *
 * Prompts for the password without echoing it where the terminal allows.
 * Also the way to recover access if you are locked out.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

const MIN_PASSWORD = 12;

function prompt(string $label, ?string $preset = null): string
{
    if ($preset !== null && $preset !== '') {
        return $preset;
    }

    echo $label;
    $line = fgets(STDIN);

    return trim((string) $line);
}

function prompt_hidden(string $label): string
{
    echo $label;

    // stty is not available on every host (and never on Windows), so fall back
    // to a visible prompt rather than failing outright.
    if (DIRECTORY_SEPARATOR === '/' && @shell_exec('which stty') !== null) {
        @shell_exec('stty -echo');
        $value = trim((string) fgets(STDIN));
        @shell_exec('stty echo');
        echo "\n";

        return $value;
    }

    echo '(typing will be visible) ';

    return trim((string) fgets(STDIN));
}

try {
    db();

    if (!db_table_exists('app_users')) {
        fwrite(STDERR, "The app_users table does not exist yet. Run: php app/scripts/migrate.php\n");
        exit(1);
    }

    $email = strtolower(prompt('Email: ', $argv[1] ?? null));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "That is not a valid email address.\n");
        exit(1);
    }

    $existing = db_one('SELECT id, full_name, role FROM app_users WHERE email = ?', [$email]);

    if ($existing) {
        echo "An account with that email exists ({$existing['role']}).\n";
        echo "Reset its password and promote it to admin? [y/N] ";

        if (strtolower(trim((string) fgets(STDIN))) !== 'y') {
            echo "Nothing changed.\n";
            exit(0);
        }
    }

    $name = $existing['full_name'] ?? prompt('Full name: ', $argv[2] ?? null);

    if ($name === '') {
        fwrite(STDERR, "A name is required.\n");
        exit(1);
    }

    $password = prompt_hidden('Password (min ' . MIN_PASSWORD . ' chars): ');

    if (strlen($password) < MIN_PASSWORD) {
        fwrite(STDERR, 'The password must be at least ' . MIN_PASSWORD . " characters.\n");
        exit(1);
    }

    if (prompt_hidden('Confirm password: ') !== $password) {
        fwrite(STDERR, "The passwords do not match.\n");
        exit(1);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($existing) {
        db_exec(
            'UPDATE app_users SET password_hash = ?, role = "admin", is_active = 1 WHERE id = ?',
            [$hash, (int) $existing['id']]
        );
        echo "\nUpdated $email — now an active administrator.\n";
    } else {
        db_exec(
            'INSERT INTO app_users (email, full_name, password_hash, role, is_active)
             VALUES (?, ?, ?, "admin", 1)',
            [$email, $name, $hash]
        );
        echo "\nCreated administrator $email.\n";
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nFailed: " . $e->getMessage() . "\n");
    exit(1);
}
