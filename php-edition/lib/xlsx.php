<?php
declare(strict_types=1);

/**
 * Minimal XLSX writer.
 *
 * An .xlsx file is a ZIP of XML parts. PhpSpreadsheet would do this for us but
 * needs Composer, which the target cPanel hosting does not have, so the ZIP
 * container is assembled here by hand. Only zlib (gzdeflate) is required -
 * notably NOT ext-zip, which shared hosts sometimes omit.
 *
 * Cells are written as inline strings rather than shared strings. That costs a
 * little file size and buys two things: no second pass to build a string table
 * (so a large export streams straight out), and immunity to CSV-style formula
 * injection - Excel only evaluates a formula from an <f> element, so a value of
 * "=cmd|..." in an inlineStr cell stays inert text.
 */

function xlsx_col_letter(int $index): string
{
    $letters = '';
    $n = $index;
    while ($n >= 0) {
        $letters = chr(65 + ($n % 26)) . $letters;
        $n = intdiv($n, 26) - 1;
    }
    return $letters;
}

function xlsx_escape(string $value): string
{
    // Strip the control characters Excel refuses to open a file over, then
    // XML-escape what is left.
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
    return htmlspecialchars($clean === null ? '' : $clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Excel stores dates as days since 1899-12-30. Returning null tells the caller
 * to write the original text instead, which is more honest than an obviously
 * wrong 1900 date when a value failed to parse.
 */
function xlsx_date_serial(?string $value): ?float
{
    if (!$value) {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return 25569 + ($ts / 86400);
}

function xlsx_cell(int $rowNum, int $colIndex, $value, string $type): string
{
    $ref = xlsx_col_letter($colIndex) . $rowNum;
    if ($value === null || $value === '') {
        return '';
    }
    if ($type === 'number') {
        if (!is_numeric($value)) {
            return '<c r="' . $ref . '" t="inlineStr"><is><t>' . xlsx_escape((string) $value) . '</t></is></c>';
        }
        return '<c r="' . $ref . '"><v>' . (0 + $value) . '</v></c>';
    }
    if ($type === 'date' || $type === 'datetime') {
        $serial = xlsx_date_serial((string) $value);
        if ($serial !== null) {
            $style = $type === 'date' ? 2 : 3;
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . round($serial, 6) . '</v></c>';
        }
    }
    return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_escape((string) $value) . '</t></is></c>';
}

/**
 * @param array    $columns [['key'=>'code','label'=>'Lead ID','type'=>'string'], ...]
 * @param iterable $rows    associative rows keyed by column key
 */
function xlsx_sheet_xml(array $columns, iterable $rows, string $sheetName): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

    $cols = '';
    foreach ($columns as $i => $col) {
        $width = $col['width'] ?? max(12, min(40, strlen((string) $col['label']) + 6));
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
    }
    if ($cols !== '') {
        $xml .= '<cols>' . $cols . '</cols>';
    }

    $xml .= '<sheetData><row r="1">';
    foreach ($columns as $i => $col) {
        $xml .= '<c r="' . xlsx_col_letter($i) . '1" t="inlineStr" s="1"><is><t>'
            . xlsx_escape((string) $col['label']) . '</t></is></c>';
    }
    $xml .= '</row>';

    $rowNum = 1;
    foreach ($rows as $row) {
        $rowNum++;
        $xml .= '<row r="' . $rowNum . '">';
        foreach ($columns as $i => $col) {
            $xml .= xlsx_cell($rowNum, $i, $row[$col['key']] ?? null, $col['type'] ?? 'string');
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';
    // Every real export gets sorted and filtered the moment it is opened.
    $xml .= '<autoFilter ref="A1:' . xlsx_col_letter(count($columns) - 1) . $rowNum . '"/>';
    $xml .= '</worksheet>';
    return $xml;
}

// ---------------------------------------------------------------------------
// ZIP container (deflate, without ext-zip)
// ---------------------------------------------------------------------------
function xlsx_zip(array $files): string
{
    $local = '';
    $central = '';
    $offset = 0;
    // A fixed timestamp keeps repeated exports of the same data byte-identical,
    // which is what makes the export test assertable.
    $dosTime = 0;
    $dosDate = ((2024 - 1980) << 9) | (1 << 5) | 1;

    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $uncompressed = strlen($content);
        $deflated = gzdeflate($content, 6);
        if ($deflated === false || strlen($deflated) >= $uncompressed) {
            $deflated = $content;
            $method = 0;
        } else {
            $method = 8;
        }
        $compressed = strlen($deflated);

        $header = pack('vvvvvVVVvv', 20, 0, $method, $dosTime, $dosDate, $crc, $compressed, $uncompressed, strlen($name), 0);
        $local .= "PK\x03\x04" . $header . $name . $deflated;

        $central .= "PK\x01\x02" . pack('v', 20) . $header . pack('vvvVV', 0, 0, 0, 32, $offset) . $name;
        $offset += 4 + strlen($header) + strlen($name) + $compressed;
    }

    $count = count($files);
    return $local . $central . "PK\x05\x06"
        . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), $offset, 0);
}

function xlsx_build(array $columns, iterable $rows, string $sheetName = 'Sheet1'): string
{
    $stripped = preg_replace('#[\\\\/?*\[\]:]#', '', $sheetName);
    $safeName = substr((string) ($stripped === null || $stripped === '' ? 'Sheet1' : $stripped), 0, 31);

    $files = [
        '[Content_Types].xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',

        '_rels/.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',

        'xl/workbook.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . xlsx_escape($safeName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',

        'xl/_rels/workbook.xml.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',

        // Style 0 = default, 1 = bold header, 2 = date, 3 = date + time.
        'xl/styles.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd-mmm-yyyy"/>'
            . '<numFmt numFmtId="165" formatCode="dd-mmm-yyyy hh:mm"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs></styleSheet>',

        'xl/worksheets/sheet1.xml' => xlsx_sheet_xml($columns, $rows, $safeName),
    ];

    return xlsx_zip($files);
}

/** Sends an XLSX download with the headers browsers need in order to save it. */
function xlsx_download(string $filename, array $columns, iterable $rows, string $sheetName = 'Export'): void
{
    $binary = xlsx_build($columns, $rows, $sheetName);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: no-store');
    echo $binary;
}
