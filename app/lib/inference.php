<?php
declare(strict_types=1);

require_once __DIR__ . '/identifiers.php';

/**
 * Column type inference from a sample of rows.
 *
 * The guiding rule is: when in doubt, choose text. A column typed too wide
 * costs a little storage; a column typed too narrow loses data or aborts an
 * import 90,000 rows in. The admin can narrow any column on the confirm screen,
 * so the default only has to be safe, not clever.
 */

/**
 * Values that look numeric but must stay text, because casting them destroys
 * meaning: leading zeros (zip 01234 -> 1234), an explicit +, or digits longer
 * than BIGINT can hold (unformatted phone numbers).
 */
function looks_like_lossy_number(string $v): bool
{
    if ($v === '') {
        return false;
    }

    if ($v[0] === '+') {
        return true;
    }

    $digits = ltrim($v, '-');

    if (strlen($digits) > 1 && $digits[0] === '0' && !str_starts_with($digits, '0.')) {
        return true;
    }

    return strlen(preg_replace('/\D/', '', $digits) ?? '') > 18;
}

/** Infers one column's type from its non-blank sample values. */
function infer_type(array $values): string
{
    $values = array_values(array_filter(
        array_map(static fn($v) => trim((string) $v), $values),
        static fn($v) => $v !== ''
    ));

    // Nothing to go on. leads.csv has genuinely empty columns
    // ("Number of Employees" is blank for many rows), so this is a real case.
    if ($values === []) {
        return 'TEXT';
    }

    $maxLen     = 0;
    $allInt     = true;
    $allDecimal = true;
    $allDate    = true;
    $allDateTime = true;

    foreach ($values as $v) {
        $maxLen = max($maxLen, strlen($v));

        if ($allInt || $allDecimal) {
            if (looks_like_lossy_number($v)) {
                $allInt = $allDecimal = false;
            } else {
                if ($allInt && !preg_match('/^-?\d{1,18}$/', $v)) {
                    $allInt = false;
                }
                if ($allDecimal && !preg_match('/^-?\d{1,14}(\.\d{1,4})?$/', $v)) {
                    $allDecimal = false;
                }
            }
        }

        // Only unambiguous ISO forms. 01/02/2024 is either 1 Feb or 2 Jan
        // depending on where you live, so it stays text.
        if ($allDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $allDate = false;
        }
        if ($allDateTime && !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $v)) {
            $allDateTime = false;
        }
    }

    if ($allInt)      return 'BIGINT';
    if ($allDecimal)  return 'DECIMAL(18,4)';
    if ($allDate)     return 'DATE';
    if ($allDateTime) return 'DATETIME';

    // Generous headroom: the sample is 500 rows out of a possible 250,000, so
    // the longest value in the file is very likely longer than anything seen here.
    // VARCHAR is variable-length in InnoDB, so over-sizing costs essentially nothing.
    if ($maxLen <= 100) return 'VARCHAR(255)';
    if ($maxLen <= 250) return 'VARCHAR(512)';
    if ($maxLen <= 800) return 'VARCHAR(2000)';

    return 'TEXT';
}

/**
 * Annotates sanitized column descriptors with an inferred type plus the stats
 * the confirm screen shows the admin.
 *
 * @param array $columns   from sanitize_headers()
 * @param array $rows      sample rows, each a positional array aligned to $columns
 */
function infer_columns(array $columns, array $rows): array
{
    foreach ($columns as $i => $col) {
        $values = [];
        $blank  = 0;

        foreach ($rows as $row) {
            $v = $row[$i] ?? '';
            $v = is_string($v) ? trim($v) : (string) $v;

            if ($v === '') {
                $blank++;
            } else {
                $values[] = $v;
            }
        }

        $samples = array_slice(array_unique($values), 0, 3);

        $columns[$i]['type']       = infer_type($values);
        $columns[$i]['blank_pct']  = $rows === [] ? 0 : (int) round($blank * 100 / count($rows));
        $columns[$i]['max_len']    = $values === [] ? 0 : max(array_map('strlen', $values));
        $columns[$i]['samples']    = array_values($samples);
        $columns[$i]['include']    = true;
    }

    return $columns;
}

/**
 * Casts an incoming string to what the column type expects.
 * Returns null for blanks so empty CSV cells become NULL rather than 0 or
 * '0000-00-00', both of which silently misrepresent "we don't know".
 */
function cast_for_type(mixed $value, string $type): mixed
{
    if ($value === null) {
        return null;
    }

    $v = is_string($value) ? trim($value) : $value;

    if ($v === '' || $v === null) {
        return null;
    }

    $t = strtoupper($type);

    if ($t === 'BIGINT' || $t === 'INT' || $t === 'TINYINT(1)') {
        return is_numeric($v) ? (int) $v : null;
    }

    if (str_starts_with($t, 'DECIMAL') || $t === 'DOUBLE') {
        return is_numeric($v) ? (string) $v : null;
    }

    if ($t === 'DATE') {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : null;
    }

    if ($t === 'DATETIME') {
        $s = str_replace('T', ' ', (string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $s) ? $s : null;
    }

    // Text: hard-truncate to the declared width so one long row cannot abort
    // an import that is otherwise fine. Truncations are counted and reported.
    if (preg_match('/^VARCHAR\((\d+)\)$/', $t, $m)) {
        $limit = (int) $m[1];
        $s     = (string) $v;
        return mb_strlen($s) > $limit ? mb_substr($s, 0, $limit) : $s;
    }

    return (string) $v;
}
