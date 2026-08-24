<?php
declare(strict_types=1);

/**
 * Turning uploaded files into a normalized CSV.
 *
 * Everything is converted to UTF-8, comma-delimited CSV at stage time, so the
 * importer only ever deals with one format. That matters more than it sounds:
 * the chunked importer resumes with fseek() to a byte offset, which is only
 * meaningful for a plain text file. An .xlsx cannot be resumed by byte offset,
 * so it is flattened here once, up front.
 *
 * The .xlsx reader is written against ZipArchive + XMLReader rather than
 * PhpSpreadsheet — shared hosting cannot be relied on to have composer, and
 * streaming XML keeps memory flat on large sheets.
 */

const READER_SUPPORTED_EXT = ['csv', 'tsv', 'txt', 'xlsx'];

function reader_extension(string $filename): string
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/** Sniffs the delimiter from the header line. */
function detect_delimiter(string $path): string
{
    $fh = fopen($path, 'rb');

    if (!$fh) {
        throw new RuntimeException('Could not open the uploaded file.');
    }

    $line = fgets($fh, 65536) ?: '';
    fclose($fh);

    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;

    $counts = [
        ','  => substr_count($line, ','),
        "\t" => substr_count($line, "\t"),
        ';'  => substr_count($line, ';'),
        '|'  => substr_count($line, '|'),
    ];

    arsort($counts);
    $best = array_key_first($counts);

    return $counts[$best] > 0 ? (string) $best : ',';
}

/**
 * Strips a UTF-8 BOM and converts to UTF-8 when the bytes are not already
 * valid. Every CSV in this project starts with a BOM, and Windows exports are
 * frequently CP1252 — left alone, both corrupt the first column name.
 */
function to_utf8(string $s): string
{
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;

    if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
        return $s;
    }

    return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

/**
 * Rewrites any supported upload as UTF-8 comma CSV at $destPath.
 * Returns ['rows' => estimated data rows, 'header' => string[]].
 */
function normalize_to_csv(string $srcPath, string $originalName, string $destPath): array
{
    $ext = reader_extension($originalName);

    if (!in_array($ext, READER_SUPPORTED_EXT, true)) {
        throw new RuntimeException("Unsupported file type '.$ext'. Upload a .csv, .tsv or .xlsx file.");
    }

    return $ext === 'xlsx'
        ? xlsx_to_csv($srcPath, $destPath)
        : delimited_to_csv($srcPath, $destPath, detect_delimiter($srcPath));
}

function delimited_to_csv(string $srcPath, string $destPath, string $delimiter): array
{
    $in = fopen($srcPath, 'rb');
    $out = fopen($destPath, 'wb');

    if (!$in || !$out) {
        throw new RuntimeException('Could not stage the uploaded file for import.');
    }

    $header = null;
    $rows   = 0;

    while (($row = fgetcsv($in, 0, $delimiter)) !== false) {
        // fgetcsv yields [null] for a blank line.
        if ($row === [null]) {
            continue;
        }

        $row = array_map(static fn($v) => to_utf8((string) ($v ?? '')), $row);

        if ($header === null) {
            $header = $row;
        } else {
            $rows++;
        }

        fputcsv($out, $row);
    }

    fclose($in);
    fclose($out);

    if ($header === null) {
        throw new RuntimeException('That file appears to be empty.');
    }

    return ['rows' => $rows, 'header' => $header];
}

/* ── xlsx ──────────────────────────────────────────────────────────────── */

/** "A" -> 0, "Z" -> 25, "AA" -> 26 */
function column_ref_to_index(string $ref): int
{
    $letters = preg_replace('/\d+/', '', strtoupper($ref)) ?? '';
    $n = 0;

    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }

    return max(0, $n - 1);
}

/**
 * Excel stores dates as a day count. The epoch is 1899-12-30 rather than
 * 1900-01-01 because Excel deliberately preserves Lotus 1-2-3's bug of treating
 * 1900 as a leap year.
 */
function excel_serial_to_date(float $serial, bool $withTime): string
{
    $days    = (int) floor($serial);
    $seconds = (int) round(($serial - $days) * 86400);

    $dt = (new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC')))
        ->modify("+$days days")
        ->modify("+$seconds seconds");

    return $dt->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
}

/** Opens one XML entry inside an XLSX archive without expanding it in memory. */
function xlsx_xml_reader(string $archivePath, string $entry): XMLReader
{
    $path = realpath($archivePath);

    if ($path === false) {
        throw new RuntimeException('Could not locate the uploaded spreadsheet.');
    }

    $reader = new XMLReader();
    $uri = 'zip://' . str_replace('\\', '/', $path) . '#' . ltrim($entry, '/');

    if (!$reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT)) {
        throw new RuntimeException('Could not stream data from the uploaded spreadsheet.');
    }

    return $reader;
}

/** Reads sharedStrings.xml into an indexed array while streaming its XML. */
function xlsx_shared_strings(string $archivePath, bool $hasSharedStrings): array
{
    if (!$hasSharedStrings) {
        return [];
    }

    $strings = [];
    $reader  = xlsx_xml_reader($archivePath, 'xl/sharedStrings.xml');

    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
            $node = $reader->readOuterXML();
            // <si> may hold one <t>, or several inside <r> runs for rich text.
            preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $node, $m);
            $strings[] = html_entity_decode(implode('', $m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $reader->next();
        }
    }

    $reader->close();

    return $strings;
}

/**
 * Maps each cell-style index to whether it is a date format.
 * Without this, date columns arrive as opaque serial numbers like 45521.
 */
function xlsx_date_styles(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/styles.xml');

    if ($xml === false) {
        return [];
    }

    // Built-in date/time formats, plus any custom numFmt whose code contains
    // date or time placeholders.
    $dateFmtIds = array_fill_keys([14,15,16,17,18,19,20,21,22,45,46,47], true);

    if (preg_match_all('/<numFmt[^>]*numFmtId="(\d+)"[^>]*formatCode="([^"]*)"/i', $xml, $m, PREG_SET_ORDER)) {
        foreach ($m as $fmt) {
            $code = html_entity_decode($fmt[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $code = preg_replace('/\[[^\]]*\]|"[^"]*"/', '', $code) ?? $code;

            if (preg_match('/[ymdhs]/i', $code)) {
                $dateFmtIds[(int) $fmt[1]] = true;
            }
        }
    }

    // cellXfs lists the styles cells reference by index, in document order.
    if (!preg_match('/<cellXfs[^>]*>(.*?)<\/cellXfs>/s', $xml, $block)) {
        return [];
    }

    preg_match_all('/<xf[^>]*numFmtId="(\d+)"[^>]*\/?>/i', $block[1], $xfs);

    $styles = [];
    foreach ($xfs[1] as $i => $fmtId) {
        $id = (int) $fmtId;
        $styles[$i] = [
            'is_date' => isset($dateFmtIds[$id]),
            'has_time' => in_array($id, [18,19,20,21,22,45,46,47], true),
        ];
    }

    return $styles;
}

/** Locates the first worksheet's XML path inside the archive. */
function xlsx_first_sheet_path(ZipArchive $zip): string
{
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels     = $zip->getFromName('xl/_rels/workbook.xml.rels');

    if ($workbook !== false && $rels !== false
        && preg_match('/<sheet[^>]*r:id="([^"]+)"/i', $workbook, $m)) {
        $rid = $m[1];

        if (preg_match('/<Relationship[^>]*Id="' . preg_quote($rid, '/') . '"[^>]*Target="([^"]+)"/i', $rels, $r)) {
            $target = ltrim($r[1], '/');
            $path   = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;

            if ($zip->locateName($path) !== false) {
                return $path;
            }
        }
    }

    if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
        return 'xl/worksheets/sheet1.xml';
    }

    throw new RuntimeException('Could not find a worksheet inside that .xlsx file.');
}

/** Streams the first worksheet into a UTF-8 CSV. */
function xlsx_to_csv(string $srcPath, string $destPath): array
{
    if (!class_exists('ZipArchive') || !class_exists('XMLReader')) {
        throw new RuntimeException(
            'This server lacks the zip or xml PHP extensions, so .xlsx cannot be read. '
            . 'Save the file as .csv and upload that instead.'
        );
    }

    $zip = new ZipArchive();

    if ($zip->open($srcPath) !== true) {
        throw new RuntimeException('That .xlsx file could not be opened — it may be corrupt.');
    }

    $hasSharedStrings = $zip->locateName('xl/sharedStrings.xml') !== false;
    $styles    = xlsx_date_styles($zip);
    $sheetPath = xlsx_first_sheet_path($zip);
    $zip->close();

    $shared = xlsx_shared_strings($srcPath, $hasSharedStrings);
    $reader = xlsx_xml_reader($srcPath, $sheetPath);
    $out = fopen($destPath, 'wb');

    if (!$out) {
        $reader->close();
        throw new RuntimeException('Could not stage the uploaded file for import.');
    }

    $header = null;
    $rows   = 0;
    $width  = 0;

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
            continue;
        }

        $rowXml = $reader->readOuterXML();
        $cells  = [];

        if (preg_match_all('/<c\b([^>]*)(?:\/>|>(.*?)<\/c>)/s', $rowXml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $c) {
                $attrs = $c[1];
                $inner = $c[2] ?? '';

                $index = preg_match('/r="([A-Z]+\d+)"/i', $attrs, $r)
                    ? column_ref_to_index($r[1])
                    : count($cells);

                $type  = preg_match('/t="([^"]+)"/', $attrs, $t) ? $t[1] : 'n';
                $style = preg_match('/s="(\d+)"/', $attrs, $s) ? (int) $s[1] : -1;

                $value = '';

                if ($type === 'inlineStr') {
                    preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $inner, $im);
                    $value = implode('', $im[1]);
                } elseif (preg_match('/<v[^>]*>(.*?)<\/v>/s', $inner, $vm)) {
                    $value = $vm[1];

                    if ($type === 's') {
                        $value = $shared[(int) $value] ?? '';
                    } elseif ($type === 'b') {
                        $value = $value === '1' ? 'TRUE' : 'FALSE';
                    } elseif ($style >= 0 && ($styles[$style]['is_date'] ?? false) && is_numeric($value)) {
                        $value = excel_serial_to_date((float) $value, $styles[$style]['has_time']);
                    }
                }

                $cells[$index] = html_entity_decode((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        if ($cells === []) {
            continue;
        }

        // Fill gaps left by empty cells so every row lines up with the header.
        $max  = max(array_keys($cells));
        $flat = [];
        for ($i = 0; $i <= $max; $i++) {
            $flat[] = to_utf8((string) ($cells[$i] ?? ''));
        }

        if ($header === null) {
            $header = $flat;
            $width  = count($flat);
        } else {
            $flat = array_slice(array_pad($flat, $width, ''), 0, $width);
            $rows++;
        }

        fputcsv($out, $flat);
    }

    $reader->close();
    fclose($out);

    if ($header === null) {
        throw new RuntimeException('That spreadsheet appears to be empty.');
    }

    return ['rows' => $rows, 'header' => $header];
}

/**
 * Reads the header plus up to $limit data rows from a normalized CSV.
 * Used to build the confirm screen without touching the whole file.
 */
function csv_sample(string $path, int $limit): array
{
    $fh = fopen($path, 'rb');

    if (!$fh) {
        throw new RuntimeException('Could not read the staged file.');
    }

    $header = fgetcsv($fh, 0, ',');

    if ($header === false || $header === [null]) {
        fclose($fh);
        throw new RuntimeException('That file appears to be empty.');
    }

    $rows = [];

    while (count($rows) < $limit && ($row = fgetcsv($fh, 0, ',')) !== false) {
        if ($row === [null]) {
            continue;
        }
        $rows[] = $row;
    }

    fclose($fh);

    return ['header' => array_map('strval', $header), 'rows' => $rows];
}
