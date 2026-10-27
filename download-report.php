<?php
require_once __DIR__ . '/functions.php';
requireAdmin();

function reportXmlText($value)
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$value);
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function reportWorksheetXml($rows, $widths = [])
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="19"/>';

    if ($widths) {
        $xml .= '<cols>';
        foreach ($widths as $index => $width) {
            $column = $index + 1;
            $xml .= '<col min="' . $column . '" max="' . $column . '" width="' . (int)$width . '" customWidth="1"/>';
        }
        $xml .= '</cols>';
    }

    $xml .= '<sheetData>';
    foreach ($rows as $rowIndex => $row) {
        $rowNumber = $rowIndex + 1;
        $xml .= '<row r="' . $rowNumber . '">';
        foreach (array_values($row) as $columnIndex => $value) {
            $column = '';
            $number = $columnIndex + 1;
            while ($number > 0) {
                $remainder = ($number - 1) % 26;
                $column = chr(65 + $remainder) . $column;
                $number = (int)(($number - 1) / 26);
            }
            $xml .= '<c r="' . $column . $rowNumber . '"' . ($rowIndex === 0 ? ' s="1"' : '') . ' t="'
                . (is_int($value) || is_float($value) ? 'n' : 'inlineStr') . '">';
            if (is_int($value) || is_float($value)) {
                $xml .= '<v>' . $value . '</v>';
            } else {
                $xml .= '<is><t xml:space="preserve">'
                    . reportXmlText($value)
                    . '</t></is>';
            }
            $xml .= '</c>';
        }
        $xml .= '</row>';
    }
    return $xml . '</sheetData><autoFilter ref="A1:' . $column . count($rows) . '"/></worksheet>';
}

function reportZipArchive($files)
{
    $body = '';
    $directory = '';
    $offset = 0;
    $dosTime = 0;
    $dosDate = 33;

    foreach ($files as $name => $contents) {
        $name = str_replace('\\', '/', $name);
        $compressed = gzdeflate($contents, 6);
        if ($compressed === false) {
            throw new RuntimeException('The spreadsheet could not be compressed.');
        }

        $nameLength = strlen($name);
        $compressedLength = strlen($compressed);
        $contentsLength = strlen($contents);
        $checksum = (int)sprintf('%u', crc32($contents));
        $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, $dosTime, $dosDate, $checksum, $compressedLength, $contentsLength, $nameLength, 0)
            . $name . $compressed;
        $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, $dosTime, $dosDate, $checksum, $compressedLength, $contentsLength, $nameLength, 0, 0, 0, 0, 0, $offset)
            . $name;
        $offset = strlen($body);
    }

    $entryCount = count($files);
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $entryCount, $entryCount, strlen($directory), strlen($body), 0);
    return $body . $directory . $end;
}

function reportXlsx($stats, $items, $movements)
{
    $summaryRows = [
        ['NLT INVENTORY REPORT', ''],
        ['Generated', date('Y-m-d H:i:s')],
        ['Catalog items', (int)$stats['total_items']],
        ['Goods', (int)$stats['goods']],
        ['Consumables', (int)$stats['consumables']],
        ['Tracked assets', (int)$stats['assets']],
        ['Total units on hand', (int)$stats['total_units']],
        ['Items at or below reorder level', (int)$stats['low_stock']],
        ['Movement records included', count($movements)],
        [],
        ['Inventory is the current stock balance. Movement history contains the latest 200 records.']
    ];

    $inventoryRows = [[
        'Item code', 'Item name', 'Category', 'Quantity', 'Unit', 'Reorder level', 'Location',
        'Supplier', 'Asset tag', 'Assigned to', 'Department', 'Condition', 'Purchase date', 'Description'
    ]];
    foreach ($items as $item) {
        $inventoryRows[] = [
            $item['item_code'],
            $item['name'],
            ucfirst($item['category']),
            (int)$item['quantity'],
            $item['unit'],
            (int)$item['reorder_level'],
            $item['location'],
            $item['supplier'] ?: '',
            $item['asset_tag'] ?: '',
            $item['assigned_to'] ?: '',
            $item['department'] ?: '',
            $item['condition_status'] ?: '',
            $item['purchase_date'] ?: '',
            $item['description'] ?: ''
        ];
    }

    $movementRows = [['Date and time', 'Type', 'Item code', 'Item name', 'Quantity', 'Unit', 'From / to', 'Note']];
    foreach ($movements as $movement) {
        $movementRows[] = [
            $movement['created_at'],
            $movement['type'] === 'in' ? 'Stock in' : 'Stock out',
            $movement['item_code'],
            $movement['item_name'],
            (int)$movement['quantity'],
            $movement['unit'],
            $movement['issued_to_or_from'] ?: '',
            $movement['notes'] ?: ''
        ];
    }

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
    $rootRelationships = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Summary" sheetId="1" r:id="rId1"/>'
        . '<sheet name="Inventory" sheetId="2" r:id="rId2"/>'
        . '<sheet name="Movement History" sheetId="3" r:id="rId3"/></sheets></workbook>';
    $workbookRelationships = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
        . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="10"/><name val="Aptos"/></font><font><b/><color rgb="FFFFD77A"/><sz val="10"/><name val="Aptos"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF101C33"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    return reportZipArchive([
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRelationships,
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRelationships,
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => reportWorksheetXml($summaryRows, [39, 38]),
        'xl/worksheets/sheet2.xml' => reportWorksheetXml($inventoryRows, [18, 30, 16, 13, 12, 16, 24, 24, 18, 24, 20, 18, 22, 42]),
        'xl/worksheets/sheet3.xml' => reportWorksheetXml($movementRows, [22, 15, 18, 30, 13, 12, 24, 42])
    ]);
}

function pdfText($value, $limit = 80)
{
    $value = (string)$value;
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    $value = preg_replace('/[^\x20-\x7E\x80-\xFF]/', '?', $value);
    if (strlen($value) > $limit) {
        $value = substr($value, 0, max(0, $limit - 3)) . '...';
    }
    return str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $value);
}

function pdfDrawText(&$content, $text, $x, $top, $size = 9, $bold = false, $color = '0.13 0.18 0.27', $limit = 80)
{
    $font = $bold ? 'F2' : 'F1';
    $baseline = 595 - $top - $size;
    $content .= "q {$color} rg BT /{$font} {$size} Tf 1 0 0 1 {$x} {$baseline} Tm (" . pdfText($text, $limit) . ") Tj ET Q\n";
}

function pdfDrawLine(&$content, $x1, $top, $x2, $color = '0.84 0.86 0.89')
{
    $y = 595 - $top;
    $content .= "q {$color} RG 0.55 w {$x1} {$y} m {$x2} {$y} l S Q\n";
}

function pdfDrawRect(&$content, $x, $top, $width, $height, $color)
{
    $y = 595 - $top - $height;
    $content .= "q {$color} rg {$x} {$y} {$width} {$height} re f Q\n";
}

function pdfHeader($title, $pageNumber)
{
    $content = '';
    pdfDrawText($content, 'NLT', 42, 30, 17, true, '0.84 0.67 0.33');
    pdfDrawText($content, 'INVENTORY  /  OPERATIONS', 95, 37, 8, true, '0.40 0.45 0.53');
    pdfDrawText($content, date('F j, Y'), 709, 38, 8, false, '0.40 0.45 0.53');
    pdfDrawLine($content, 42, 62, 800);
    pdfDrawText($content, $title, 42, 78, 20, true, '0.08 0.13 0.23');
    pdfDrawText($content, 'Generated ' . date('Y-m-d H:i') . '  ·  Page ' . $pageNumber, 42, 106, 8, false, '0.46 0.51 0.58');
    return $content;
}

function pdfTableRows(&$content, $headers, $rows, $widths, &$top, &$pageNumber, &$pages, $sectionTitle)
{
    $xStart = 42;
    $tableWidth = array_sum($widths);
    $drawHeaders = function () use (&$content, $headers, $widths, $xStart, $tableWidth, &$top) {
        pdfDrawRect($content, $xStart, $top, $tableWidth, 24, '0.08 0.13 0.23');
        $x = $xStart;
        foreach ($headers as $index => $header) {
            pdfDrawText($content, $header, $x + 6, $top + 8, 7, true, '0.94 0.84 0.61');
            $x += $widths[$index];
        }
        $top += 24;
    };

    $drawHeaders();
    foreach ($rows as $row) {
        if ($top > 555) {
            $pages[] = $content;
            $pageNumber++;
            $content = pdfHeader($sectionTitle, $pageNumber);
            $top = 132;
            $drawHeaders();
        }
        $x = $xStart;
        foreach (array_values($row) as $index => $value) {
            $limit = max(4, (int)floor(($widths[$index] - 12) / 4.1));
            pdfDrawText($content, $value, $x + 6, $top + 7, 7, false, '0.21 0.27 0.35', $limit);
            $x += $widths[$index];
        }
        $top += 22;
        pdfDrawLine($content, $xStart, $top, $xStart + $tableWidth, '0.89 0.90 0.92');
    }
}

function reportPdf($stats, $items, $movements)
{
    $pages = [];
    $pageNumber = 1;
    $content = pdfHeader('Inventory report', $pageNumber);

    pdfDrawText($content, 'A practical snapshot of your stock, assets and recent activity.', 42, 132, 9, false, '0.40 0.45 0.53');
    $cards = [
        ['CATALOG ITEMS', number_format((int)$stats['total_items'])],
        ['UNITS ON HAND', number_format((int)$stats['total_units'])],
        ['RESTOCK NEEDED', number_format((int)$stats['low_stock'])],
        ['MOVEMENTS', number_format(count($movements))]
    ];
    $cardWidth = 177;
    foreach ($cards as $index => $card) {
        $x = 42 + $index * ($cardWidth + 15);
        pdfDrawRect($content, $x, 162, $cardWidth, 66, '0.94 0.95 0.95');
        pdfDrawText($content, $card[0], $x + 12, 174, 7, true, '0.43 0.49 0.55');
        pdfDrawText($content, $card[1], $x + 12, 192, 19, true, '0.08 0.13 0.23');
    }
    pdfDrawText($content, 'INVENTORY REGISTER  (' . count($items) . ' ITEMS)', 42, 250, 10, true, '0.08 0.13 0.23');
    $inventoryRows = [];
    foreach ($items as $item) {
        $inventoryRows[] = [
            $item['item_code'],
            $item['name'],
            ucfirst($item['category']),
            $item['quantity'] . ' ' . $item['unit'],
            $item['reorder_level'],
            $item['location'],
            $item['asset_tag'] ?: '—',
            $item['assigned_to'] ?: '—'
        ];
    }
    $inventoryTop = 272;
    pdfTableRows($content, ['CODE', 'ITEM', 'CATEGORY', 'ON HAND', 'REORDER', 'LOCATION', 'ASSET TAG', 'ASSIGNED TO'], $inventoryRows, [66, 146, 78, 70, 68, 128, 90, 112], $inventoryTop, $pageNumber, $pages, 'Inventory register');

    $pages[] = $content;
    $pageNumber++;
    $content = pdfHeader('Movement history', $pageNumber);
    pdfDrawText($content, 'The latest ' . count($movements) . ' recorded stock movements.', 42, 132, 9, false, '0.40 0.45 0.53');
    $movementRows = [];
    foreach ($movements as $movement) {
        $movementRows[] = [
            date('Y-m-d H:i', strtotime($movement['created_at'])),
            $movement['type'] === 'in' ? 'Stock in' : 'Stock out',
            $movement['item_code'],
            $movement['item_name'],
            ($movement['type'] === 'in' ? '+' : '-') . $movement['quantity'] . ' ' . $movement['unit'],
            $movement['issued_to_or_from'] ?: '—',
            $movement['notes'] ?: '—'
        ];
    }
    $movementTop = 158;
    pdfTableRows($content, ['DATE / TIME', 'TYPE', 'CODE', 'ITEM', 'QUANTITY', 'FROM / TO', 'NOTE'], $movementRows, [100, 72, 82, 146, 94, 116, 148], $movementTop, $pageNumber, $pages, 'Movement history');
    $pages[] = $content;

    $objects = [];
    $pageIds = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    foreach ($pages as $index => $pageContent) {
        $pageId = 5 + $index * 2;
        $streamId = $pageId + 1;
        $pageIds[] = $pageId . ' 0 R';
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $streamId . ' 0 R >>';
        $objects[$streamId] = '<< /Length ' . strlen($pageContent) . " >>\nstream\n" . $pageContent . "\nendstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageIds) . '] /Count ' . count($pageIds) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $size = max(array_keys($objects)) + 1;
    $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
    for ($id = 1; $id < $size; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", isset($offsets[$id]) ? $offsets[$id] : 0);
    }
    return $pdf . "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
}

$format = isset($_GET['format']) && is_string($_GET['format']) ? $_GET['format'] : '';
if (!in_array($format, ['pdf', 'xlsx'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Choose a supported report format: pdf or xlsx.';
    exit;
}

try {
    if ($format === 'xlsx' && !function_exists('gzdeflate')) {
        throw new RuntimeException('Excel workbook generation requires the PHP zlib extension.');
    }
    $stats = getDashboardSummary();
    $items = getAllItems();
    $movements = getAllMovements();
    if ($format === 'xlsx') {
        $file = reportXlsx($stats, $items, $movements);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="nlt-inventory-report.xlsx"');
    } else {
        $file = reportPdf($stats, $items, $movements);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="nlt-inventory-report.pdf"');
    }
    header('Content-Length: ' . strlen($file));
    header('Cache-Control: private, no-store, max-age=0');
    echo $file;
} catch (Exception $exception) {
    error_log('Unable to generate ' . $format . ' inventory report: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $exception instanceof RuntimeException
        ? $exception->getMessage()
        : 'The report could not be generated. Check the database connection and try again.';
}
