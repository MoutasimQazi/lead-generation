<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * MariaDB access via PDO.
 *
 * Every value that reaches SQL goes through a bound parameter. The only things
 * ever concatenated into a statement are identifiers, and those must come from
 * lib/identifiers.php — see the note there before writing any DDL.
 */

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        config('db_host'),
        config('db_port'),
        config('db_name')
    );

    try {
        $pdo = new PDO($dsn, config('db_user'), config('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real server-side prepares. Emulation would re-introduce string
            // interpolation of values, which is the thing we are avoiding.
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException(
            sprintf(
                'Database connection failed for %s at %s:%d: %s',
                config('db_name'),
                config('db_host'),
                config('db_port'),
                $e->getMessage()
            ),
            (int) $e->getCode(),
            $e
        );
    }

    return $pdo;
}

function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** First matching row, or null. */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** All matching rows. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** Single scalar from the first column of the first row. */
function db_value(string $sql, array $params = [], mixed $default = null): mixed
{
    $row = db_run($sql, $params)->fetch(PDO::FETCH_NUM);
    return $row === false ? $default : $row[0];
}

function db_exec(string $sql, array $params = []): int
{
    return db_run($sql, $params)->rowCount();
}

function db_insert_id(): int
{
    return (int) db()->lastInsertId();
}

/**
 * Runs $fn inside a transaction, rolling back on any throw.
 *
 * Note that MariaDB DDL (CREATE/ALTER/DROP TABLE) causes an implicit commit and
 * cannot be rolled back — so never wrap DDL in this and expect it to unwind.
 * The upload path compensates by dropping a half-built table explicitly.
 */
function db_transaction(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** True when a table exists in the configured database. */
function db_table_exists(string $table): bool
{
    // information_schema queries can make MariaDB materialize an Aria
    // temporary table in tmpdir. Some cPanel hosts mount /tmp read-only,
    // causing even this harmless existence check to fail with Errcode 30.
    // SHOW TABLES streams the catalog without that temporary-table path.
    $stmt = db()->query('SHOW TABLES');

    while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
        if (isset($row[0]) && (string) $row[0] === $table) {
            return true;
        }
    }

    return false;
}

/** Column names of an existing table, in ordinal order. */
function db_table_columns(string $table): array
{
    if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/i', $table)) {
        throw new InvalidArgumentException('Unsafe table identifier.');
    }

    // SHOW COLUMNS already returns physical ordinal order and avoids the
    // information_schema ORDER BY that can spill into MariaDB's tmpdir.
    $rows = db_all('SHOW COLUMNS FROM `' . $table . '`');

    return array_values(array_map(
        static fn(array $row): string => (string) ($row['Field'] ?? ''),
        $rows
    ));
}
