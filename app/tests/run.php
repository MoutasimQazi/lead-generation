<?php
declare(strict_types=1);

/**
 * Dependency-free test runner for the two libraries that carry the security
 * weight. No composer, no PHPUnit — run it on the server:
 *
 *     php app/tests/run.php
 *
 * Exits non-zero on failure so it can gate a deploy.
 */

require_once __DIR__ . '/../lib/identifiers.php';
require_once __DIR__ . '/../lib/inference.php';

$passed = 0;
$failed = [];

function check(string $name, callable $fn): void
{
    global $passed, $failed;

    try {
        $fn();
        $passed++;
    } catch (Throwable $e) {
        $failed[] = $name . ' — ' . $e->getMessage();
    }
}

function same(mixed $expected, mixed $actual, string $note = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%sexpected %s, got %s',
            $note !== '' ? $note . ': ' : '',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function throws(callable $fn, string $note = ''): void
{
    try {
        $fn();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException(($note !== '' ? $note . ': ' : '') . 'expected a throw, got none');
}

/* ── identifiers: the real headers from leads.csv ──────────────────────── */

check('leads.csv headers sanitize as expected', function () {
    $headers = [
        "\xEF\xBB\xBFBusiness Name", 'Primary Sector', 'Sectors', 'Size Band',
        'Number of Employees', 'Contact Person', 'Corporate Email', 'Generic Email',
        'Has Email', 'Phone', 'Phone Type', 'Street Address', 'City', 'State', 'Website',
    ];

    $cols = array_column(sanitize_headers($headers), 'name');

    same([
        'business_name', 'primary_sector', 'sectors', 'size_band',
        'number_of_employees', 'contact_person', 'corporate_email', 'generic_email',
        'has_email', 'phone', 'phone_type', 'street_address', 'city', 'state', 'website',
    ], $cols);
});

check('startup.gallery.csv headers pass through unchanged', function () {
    $headers = ['company_name', 'tagline', 'description', 'website_url', 'city', 'country', 'remote', 'company_type', 'industry'];
    same($headers, array_column(sanitize_headers($headers), 'name'));
});

/* ── identifiers: hostile input ────────────────────────────────────────── */

check('SQL injection in a header is neutralised', function () {
    $name = ident_sanitize('x`; DROP TABLE app_users; --');
    same('x_drop_table_app_users', $name);
    ident_assert($name);
});

check('quotes, backticks and semicolons never survive', function () {
    foreach (['a"b', "a'b", 'a`b', 'a;b', 'a b', "a\nb", 'a/*b*/'] as $raw) {
        $s = ident_sanitize($raw);
        ident_assert($s);
        foreach (['"', "'", '`', ';', ' ', "\n", '/', '*'] as $bad) {
            if (str_contains($s, $bad)) {
                throw new RuntimeException("'$bad' survived sanitizing of " . var_export($raw, true));
            }
        }
    }
});

check('ident_assert rejects anything off the allowlist', function () {
    foreach (['', ' ', 'A', 'Business Name', '1col', '_row_id', 'a`b', 'a;b', 'sel ect',
              str_repeat('x', 64)] as $bad) {
        throws(fn() => ident_assert($bad), var_export($bad, true));
    }
});

check('qi backticks only valid identifiers', function () {
    same('`business_name`', qi('business_name'));
    throws(fn() => qi('x`; DROP TABLE y; --'));
});

/* ── identifiers: edge cases that would corrupt a table ────────────────── */

check('leading underscore is trimmed, so no clash with system columns', function () {
    same('row_id', ident_sanitize('_row_id'));
    same('source_file', ident_sanitize('_source_file'));
});

check('a header colliding with a system column is renamed', function () {
    $cols = array_column(sanitize_headers(['_row_id', 'row_id']), 'name');
    same('row_id', $cols[0]);
    same('row_id_2', $cols[1], 'second must not collide with the first');
});

check('duplicate headers de-duplicate', function () {
    $cols = array_column(sanitize_headers(['Name', 'name', 'NAME', 'Name ']), 'name');
    same(['name', 'name_2', 'name_3', 'name_4'], $cols);
});

check('digit-leading and empty headers get a prefix', function () {
    same('c_1st_column', ident_sanitize('1st Column'));
    same('c_3', ident_sanitize('', 3));
    same('c_4', ident_sanitize('   ', 4));
});

check('reserved words are renamed, not merely quoted', function () {
    same('order_col', ident_sanitize('Order'));
    same('select_col', ident_sanitize('SELECT'));
    same('group_col', ident_sanitize('group'));
});

check('over-long headers truncate to a unique 63 chars', function () {
    $a = ident_sanitize(str_repeat('long header ', 20) . 'A');
    $b = ident_sanitize(str_repeat('long header ', 20) . 'B');

    ident_assert($a);
    ident_assert($b);
    same(true, strlen($a) <= 63);
    same(true, $a !== $b, 'shared prefixes must stay distinct');
});

check('table names are ds_-prefixed and cannot shadow core tables', function () {
    same('ds_yc_companies', ident_table_name('YC-Companies.xlsx'));
    same('ds_leads', ident_table_name('leads.csv'));
    same('ds_app_users', ident_table_name('app_users.csv'), 'ds_ prefix keeps core tables safe');
    same('ds_leads_2', ident_table_name('leads.csv', ['ds_leads']));
});

/* ── identifiers: type allowlist ───────────────────────────────────────── */

check('column types are allowlisted', function () {
    same('VARCHAR(255)', column_type_assert('varchar(255)'));
    same('BIGINT', column_type_assert(' bigint '));
    same('DECIMAL(18,4)', column_type_assert('DECIMAL(18,4)'));

    foreach (['VARCHAR(0)', 'VARCHAR(9999)', 'BLOB', 'INT; DROP TABLE x', 'DECIMAL(4,9)', ''] as $bad) {
        throws(fn() => column_type_assert($bad), var_export($bad, true));
    }
});

/* ── inference ─────────────────────────────────────────────────────────── */

check('plain integers infer BIGINT', function () {
    same('BIGINT', infer_type(['1', '42', '1000', '-7']));
});

check('zip codes keep their leading zero', function () {
    same('VARCHAR(255)', infer_type(['01234', '02115', '90210']));
});

check('phone numbers stay text', function () {
    same('VARCHAR(255)', infer_type(['(405) 408-7116', '(918) 555-0100']));
    same('VARCHAR(255)', infer_type(['+14054087116', '+19185550100']));
    same('VARCHAR(255)', infer_type(['14054087116000000000']), 'longer than BIGINT holds');
});

check('an all-blank column is TEXT, not an error', function () {
    same('TEXT', infer_type([]));
    same('TEXT', infer_type(['', '  ', '']));
});

check('ISO dates infer date types, ambiguous ones do not', function () {
    same('DATE', infer_type(['2026-08-21', '2024-01-02']));
    same('DATETIME', infer_type(['2026-08-21 14:30:00', '2024-01-02T09:15']));
    same('VARCHAR(255)', infer_type(['01/02/2024', '02/01/2024']), 'day/month order is unknowable');
});

check('text width is sized with headroom', function () {
    same('VARCHAR(255)', infer_type(['short']));
    same('VARCHAR(512)', infer_type([str_repeat('x', 200)]));
    same('VARCHAR(2000)', infer_type([str_repeat('x', 600)]));
    same('TEXT', infer_type([str_repeat('x', 1200)]));
});

check('mixed numeric and text falls back to text', function () {
    same('VARCHAR(255)', infer_type(['1', '2', 'N/A']));
});

/* ── casting ───────────────────────────────────────────────────────────── */

check('blanks cast to NULL rather than 0 or a zero date', function () {
    same(null, cast_for_type('', 'BIGINT'));
    same(null, cast_for_type('   ', 'DATE'));
    same(null, cast_for_type(null, 'TEXT'));
});

check('uncastable values become NULL instead of aborting the row', function () {
    same(null, cast_for_type('N/A', 'BIGINT'));
    same(null, cast_for_type('not a date', 'DATE'));
    same(42, cast_for_type('42', 'BIGINT'));
});

check('over-long text is truncated to the declared width', function () {
    same(10, strlen((string) cast_for_type(str_repeat('x', 50), 'VARCHAR(10)')));
});

check('infer_columns annotates descriptors positionally', function () {
    $cols = sanitize_headers(['Business Name', 'Employees']);
    $cols = infer_columns($cols, [['Acme', '10'], ['Globex', '25'], ['Initech', '']]);

    same('VARCHAR(255)', $cols[0]['type']);
    same('BIGINT', $cols[1]['type']);
    same(33, $cols[1]['blank_pct']);
    same(true, $cols[0]['include']);
});

/* ── report ────────────────────────────────────────────────────────────── */

echo "\n";
if ($failed === []) {
    echo "  \033[32mPASS\033[0m  $passed checks\n\n";
    exit(0);
}

echo "  \033[31mFAIL\033[0m  " . count($failed) . " of " . ($passed + count($failed)) . " checks\n\n";
foreach ($failed as $f) {
    echo "   • $f\n";
}
echo "\n";
exit(1);
