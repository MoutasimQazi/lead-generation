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

    $pdo = new PDO($dsn, config('db_user'), config('db_pass'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Real server-side prepares. Emulation would re-introduce string
        // interpolation of values, which is the thing we are avoiding.
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);

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
    $n = db_value(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        [config('db_name'), $table],
        0
    );

    return (int) $n > 0;
}

/** Column names of an existing table, in ordinal order. */
function db_table_columns(string $table): array
{
    $rows = db_all(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
       ORDER BY ORDINAL_POSITION',
        [config('db_name'), $table]
    );

    return array_column($rows, 'COLUMN_NAME');
}
