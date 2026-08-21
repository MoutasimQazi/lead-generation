<?php
declare(strict_types=1);

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  SECURITY-CRITICAL. Read before changing.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * SQL cannot bind identifiers — table and column names are concatenated into
 * the statement. Since ours are derived from uploaded filenames and CSV headers
 * (attacker-controllable, in effect), they are ALLOWLISTED, not escaped.
 *
 * The contract:  nothing reaches a query except via qi(), and qi() throws
 * unless the string already matches ^[a-z][a-z0-9_]{0,62}$.
 *
 * That regex admits no quote, backtick, space, semicolon or comment marker, so
 * a header of `x`; DROP TABLE app_users; -- cannot survive it. Backticking is
 * belt-and-braces on top of the allowlist, never a substitute for it.
 */

/** System columns present on every uploaded table. */
const SYSTEM_COLUMNS = ['_row_id', '_source_file', '_imported_at'];

/** Prefix that namespaces uploaded tables away from core tables. */
const DATASET_TABLE_PREFIX = 'ds_';

/** Core tables an upload must never be able to shadow. */
const CORE_TABLES = ['app_users', 'folders', 'datasets', 'audit_log', 'upload_stages', 'sessions'];

/**
 * MariaDB reserved words.
 *
 * Quoting would make these safe for us, but the n8n NL-to-SQL step generates
 * its own SQL and will not reliably quote. A column literally named `order`
 * would break those queries, so we rename rather than rely on quoting.
 */
const RESERVED_WORDS = [
    'accessible','add','all','alter','analyze','and','as','asc','asensitive','before','between',
    'bigint','binary','blob','both','by','call','cascade','case','change','char','character',
    'check','collate','column','condition','constraint','continue','convert','create','cross',
    'current_date','current_role','current_time','current_timestamp','current_user','cursor',
    'database','databases','day_hour','day_microsecond','day_minute','day_second','dec','decimal',
    'declare','default','delayed','delete','desc','describe','deterministic','distinct',
    'distinctrow','div','double','drop','dual','each','else','elseif','enclosed','escaped','exists',
    'exit','explain','false','fetch','float','float4','float8','for','force','foreign','from',
    'fulltext','general','grant','group','having','high_priority','hour_microsecond','hour_minute',
    'hour_second','if','ignore','in','index','infile','inner','inout','insensitive','insert','int',
    'int1','int2','int3','int4','int8','integer','interval','into','is','iterate','join','key',
    'keys','kill','leading','leave','left','like','limit','linear','lines','load','localtime',
    'localtimestamp','lock','long','longblob','longtext','loop','low_priority','master_ssl_verify_server_cert',
    'match','maxvalue','mediumblob','mediumint','mediumtext','middleint','minute_microsecond',
    'minute_second','mod','modifies','natural','not','no_write_to_binlog','null','numeric','on',
    'optimize','option','optionally','or','order','out','outer','outfile','over','partition',
    'precision','primary','procedure','purge','range','read','reads','read_write','real',
    'recursive','references','regexp','release','rename','repeat','replace','require','resignal',
    'restrict','return','revoke','right','rlike','rows','schema','schemas','second_microsecond',
    'select','sensitive','separator','set','show','signal','smallint','spatial','specific','sql',
    'sqlexception','sqlstate','sqlwarning','sql_big_result','sql_calc_found_rows','sql_small_result',
    'ssl','starting','stats_auto_recalc','stats_persistent','stats_sample_pages','straight_join',
    'table','terminated','then','tinyblob','tinyint','tinytext','to','trailing','trigger','true',
    'undo','union','unique','unlock','unsigned','update','usage','use','using','utc_date','utc_time',
    'utc_timestamp','values','varbinary','varchar','varcharacter','varying','when','where','while',
    'window','with','write','xor','year_month','zerofill',
];

/**
 * Turns arbitrary text into a candidate SQL identifier.
 *
 * "Business Name"        -> business_name
 * "Number of Employees"  -> number_of_employees
 * "1st Column"           -> c_1st_column
 * "_row_id"              -> row_id      (leading _ trimmed, so no clash with the system column)
 * "order"                -> order_col   (reserved)
 * ""                     -> c_1         (caller supplies the ordinal)
 */
function ident_sanitize(string $raw, int $ordinal = 1): string
{
    $s = trim($raw);

    // Strip a UTF-8 BOM — the first header of every CSV in this project has one.
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;

    $s = strtolower($s);

    // Transliterate accented characters where the platform can, so that
    // "Société" becomes "societe" instead of collapsing to "soci_t".
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($t !== false) {
            $s = $t;
        }
    }

    $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
    $s = preg_replace('/_+/', '_', $s) ?? '';
    $s = trim($s, '_');

    if ($s === '') {
        $s = 'c_' . $ordinal;
    }

    if (ctype_digit($s[0])) {
        $s = 'c_' . $s;
    }

    if (in_array($s, RESERVED_WORDS, true)) {
        $s .= '_col';
    }

    if (strlen($s) > 63) {
        // Keep a hash tail so two long headers sharing a prefix stay distinct.
        $s = substr($s, 0, 55) . '_' . substr(md5($raw), 0, 7);
    }

    return $s;
}

/**
 * Makes $base unique against $taken by appending _2, _3, …
 * $taken is compared case-insensitively.
 */
function ident_unique(string $base, array $taken): string
{
    $lower = array_map('strtolower', $taken);

    if (!in_array(strtolower($base), $lower, true)) {
        return $base;
    }

    for ($n = 2; $n < 1000; $n++) {
        $suffix    = '_' . $n;
        $candidate = strlen($base) + strlen($suffix) > 63
            ? substr($base, 0, 63 - strlen($suffix)) . $suffix
            : $base . $suffix;

        if (!in_array(strtolower($candidate), $lower, true)) {
            return $candidate;
        }
    }

    throw new RuntimeException('Could not derive a unique identifier from "' . $base . '".');
}

/**
 * The gate. Throws unless $ident is a plain lowercase identifier.
 * Call this immediately before an identifier enters SQL — never trust that it
 * was sanitized somewhere upstream.
 */
function ident_assert(string $ident): string
{
    if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $ident)) {
        throw new InvalidArgumentException('Rejected unsafe SQL identifier: ' . var_export($ident, true));
    }

    return $ident;
}

/** Asserts, then backticks. The only sanctioned way to put an identifier in SQL. */
function qi(string $ident): string
{
    return '`' . ident_assert($ident) . '`';
}

/**
 * Quotes a system column (`_row_id` etc.), which ident_assert deliberately
 * rejects because user input must never produce a leading underscore.
 */
function qsys(string $ident): string
{
    if (!in_array($ident, SYSTEM_COLUMNS, true)) {
        throw new InvalidArgumentException('Not a system column: ' . var_export($ident, true));
    }

    return '`' . $ident . '`';
}

/** True for a column the user is not allowed to rename, retype or drop. */
function is_system_column(string $name): bool
{
    return str_starts_with($name, '_');
}

/**
 * Builds a dataset table name from a filename or label.
 * Always ds_-prefixed, always unique against $existing.
 */
function ident_table_name(string $raw, array $existing = []): string
{
    $stem = pathinfo($raw, PATHINFO_FILENAME);
    $base = ident_sanitize($stem !== '' ? $stem : $raw);

    // ident_sanitize may already have produced a ds_-prefixed string if the file
    // was named "ds_foo"; do not double it.
    if (!str_starts_with($base, DATASET_TABLE_PREFIX)) {
        $base = DATASET_TABLE_PREFIX . $base;
    }

    if (strlen($base) > 60) {
        $base = substr($base, 0, 60);
    }

    $taken = array_merge($existing, CORE_TABLES);

    return ident_assert(ident_unique($base, $taken));
}

/**
 * Sanitizes a header row into column descriptors, resolving duplicates.
 * Returns [['name' => 'business_name', 'label' => 'Business Name'], …]
 */
function sanitize_headers(array $headers): array
{
    $out   = [];
    $taken = SYSTEM_COLUMNS;

    foreach (array_values($headers) as $i => $header) {
        $label = trim((string) $header);
        $name  = ident_unique(ident_sanitize($label, $i + 1), $taken);

        ident_assert($name);
        $taken[] = $name;

        $out[] = [
            'name'  => $name,
            'label' => $label !== '' ? $label : $name,
        ];
    }

    return $out;
}

/**
 * Allowlist for column types. Returns the normalized type or throws.
 * Anything not listed here cannot end up in a CREATE TABLE or ALTER.
 */
function column_type_assert(string $type): string
{
    $t = strtoupper(trim($type));

    $simple = ['TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'BIGINT', 'INT', 'DOUBLE', 'DATE', 'DATETIME', 'TINYINT(1)'];
    if (in_array($t, $simple, true)) {
        return $t;
    }

    if (preg_match('/^VARCHAR\((\d{1,4})\)$/', $t, $m)) {
        $n = (int) $m[1];
        if ($n >= 1 && $n <= 2000) {
            return 'VARCHAR(' . $n . ')';
        }
    }

    if (preg_match('/^DECIMAL\((\d{1,2}),(\d{1,2})\)$/', $t, $m)) {
        [$p, $s] = [(int) $m[1], (int) $m[2]];
        if ($p >= 1 && $p <= 38 && $s >= 0 && $s < $p) {
            return "DECIMAL($p,$s)";
        }
    }

    throw new InvalidArgumentException('Unsupported column type: ' . var_export($type, true));
}
