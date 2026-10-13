<?php
/**
 * Minimal, dependency-free Excel (.xlsx) writer and reader plus CSV helpers.
 *
 *   xlsx_download('students.xlsx', ['Name', 'Email'], [['Asha', 'a@x.com'], ...], 'Students');
 *   $rows = xlsx_read('/path/file.xlsx');           // first worksheet as list of arrays
 *   csv_download('students.csv', $headers, $rows);
 *   $rows = spreadsheet_read($path, $extension);    // csv or xlsx
 */

function xlsx_col_letter(int $index): string
{
    $s = '';
    $index++;
    while ($index > 0) {
        $m = ($index - 1) % 26;
        $s = chr(65 + $m) . $s;
        $index = intdiv($index - $m, 26);
    }
    return $s;
}

function xlsx_xml(string $s): string
{
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Build an xlsx file and return its binary contents. */
function xlsx_build(array $headers, array $rows, string $sheetName = 'Sheet1'): string
{
    $sheetName = mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', '', $sheetName) ?: 'Sheet1', 0, 31);
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
    foreach ($headers as $i => $h) {
        $width = max(10, min(50, mb_strlen((string) $h) + 4));
        foreach (array_slice($rows, 0, 200) as $r) {
            $width = max($width, min(50, mb_strlen((string) ($r[$i] ?? '')) + 2));
        }
        $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
    }
    $sheet .= '</cols><sheetData>';
    $all = array_merge([$headers], $rows);
    foreach ($all as $r => $row) {
        $sheet .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $value) {
            $ref = xlsx_col_letter($c) . ($r + 1);
            $style = $r === 0 ? ' s="1"' : '';
            if ($r > 0 && is_numeric($value) && !preg_match('/^0\d/', (string) $value) && strlen((string) $value) < 15) {
                $sheet .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
            } else {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . xlsx_xml((string) $value) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData><autoFilter ref="A1:' . xlsx_col_letter(max(0, count($headers) - 1)) . max(1, count($all)) . '"/></worksheet>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>GIMT SmartCampus</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . xlsx_xml($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0B2A5B"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $data = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $data;
}

function download_headers(string $filename, string $mime): void
{
    $safe = preg_replace('/[^A-Za-z0-9._\-]/', '_', $filename);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
}

function xlsx_download(string $filename, array $headers, array $rows, string $sheetName = 'Sheet1'): void
{
    $data = xlsx_build($headers, $rows, $sheetName);
    download_headers($filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

/** Neutralise spreadsheet formula injection for values starting with = + - @ */
function csv_safe($value): string
{
    $v = (string) ($value ?? '');
    return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($v) ? "'" . $v : $v;
}

function csv_download(string $filename, array $headers, array $rows): void
{
    download_headers($filename, 'text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_safe', array_values($row)));
    }
    fclose($out);
    exit;
}

/** Read the first worksheet of an xlsx file. Returns list of rows (arrays of strings). */
function xlsx_read(string $path, int $maxRows = 20000): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('The Excel file could not be opened.');
    }
    $shared = [];
    if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($ss, 'SimpleXMLElement', LIBXML_NONET);
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string) $si->t;
            } else {
                $t = '';
                foreach ($si->r as $r) {
                    $t .= (string) $r->t;
                }
                $shared[] = $t;
            }
        }
    }
    $sheetPath = 'xl/worksheets/sheet1.xml';
    if (($wb = $zip->getFromName('xl/workbook.xml')) !== false && ($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
        $wbx = simplexml_load_string($wb, 'SimpleXMLElement', LIBXML_NONET);
        $relx = simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET);
        $first = $wbx->sheets->sheet[0] ?? null;
        if ($first) {
            $rid = (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($relx->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $sheetPath = 'xl/' . ltrim(str_replace('/xl/', '', (string) $rel['Target']), '/');
                }
            }
        }
    }
    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('No worksheet found in the Excel file.');
    }
    $xml = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET);
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            $col = 0;
            if (preg_match('/^([A-Z]+)/', $ref, $m)) {
                foreach (str_split($m[1]) as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
                $col--;
            }
            $type = (string) $c['t'];
            if ($type === 's') {
                $v = $shared[(int) $c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $v = (string) $c->is->t;
            } else {
                $v = (string) $c->v;
            }
            $cells[$col] = $v;
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $rows[] = $line;
        }
        if (count($rows) >= $maxRows) {
            break;
        }
    }
    return $rows;
}

function csv_read(string $path, int $maxRows = 20000): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) {
        throw new RuntimeException('The CSV file could not be opened.');
    }
    $first = true;
    while (($line = fgetcsv($fh)) !== false) {
        if ($first) {
            $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line[0]);
            $first = false;
        }
        if ($line === [null]) {
            continue;
        }
        $rows[] = array_map(fn ($v) => trim((string) $v), $line);
        if (count($rows) >= $maxRows) {
            break;
        }
    }
    fclose($fh);
    return $rows;
}

function spreadsheet_read(string $path, string $extension): array
{
    return strtolower($extension) === 'xlsx' ? xlsx_read($path) : csv_read($path);
}

/** Convert an Excel serial date (e.g. 45210) or common date strings to Y-m-d. */
function spreadsheet_date($value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
        return gmdate('Y-m-d', (int) round(((float) $value - 25569) * 86400));
    }
    foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'd M Y', 'j M Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $value);
        if ($d && $d->format($fmt) === $value) {
            return $d->format('Y-m-d');
        }
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : null;
}
