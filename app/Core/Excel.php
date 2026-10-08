<?php
namespace App\Core;

/**
 * Minimal Excel (.xlsx) writer — no external libraries.
 * Uses PHP's ZipArchive (ext-zip). If ext-zip is not enabled (e.g. some XAMPP installs),
 * it falls back to the Excel 2003 XML format (.xls), which Excel also opens.
 */
class Excel
{
    // Style ids defined in styles.xml below
    private const S_TEXT = 0, S_BOLD = 1, S_MONEY = 2, S_MONEY_BOLD = 3, S_HEADER = 4, S_QTY = 5, S_PCT = 6, S_INT = 7, S_TITLE = 8;

    /**
     * @param array $columns [['label'=>..., 'type'=>...], ...]
     * @param array $rows    [['cells' => [v1, v2, ...], 'bold' => bool], ...]
     */
    public static function download(string $filename, string $title, string $subtitle, array $columns, array $rows): Response
    {
        $business = \App\Services\Settings::get('business_name', '');
        if (class_exists(\ZipArchive::class)) {
            return Response::download(self::xlsx($business, $title, $subtitle, $columns, $rows), $filename . '.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        return Response::download(self::xml2003($business, $title, $subtitle, $columns, $rows), $filename . '.xls', 'application/vnd.ms-excel');
    }

    public static function xlsx(string $business, string $title, string $subtitle, array $columns, array $rows): string
    {
        $ncol = max(count($columns), 1);
        $sheet = [];
        $r = 1;
        $merges = [];
        foreach ([$business, $title, $subtitle] as $line) {
            if ($line === '') continue;
            $sheet[] = self::row($r, [self::cell('A' . $r, $line, self::S_TITLE)]);
            if ($ncol > 1) $merges[] = 'A' . $r . ':' . self::col($ncol - 1) . $r;
            $r++;
        }
        $r++;
        $headerRow = $r;
        $cells = [];
        foreach ($columns as $i => $c) $cells[] = self::cell(self::col($i) . $r, $c['label'], self::S_HEADER);
        $sheet[] = self::row($r, $cells);
        foreach ($rows as $row) {
            $r++;
            $cells = [];
            foreach ($columns as $i => $c) {
                $v = $row['cells'][$i] ?? null;
                $cells[] = self::cell(self::col($i) . $r, $v, self::styleFor($c['type'] ?? 'text', !empty($row['bold']), $v));
            }
            $sheet[] = self::row($r, $cells);
        }
        $widths = '';
        foreach ($columns as $i => $c) {
            $w = mb_strlen((string) $c['label']);
            foreach (array_slice($rows, 0, 300) as $row) $w = max($w, mb_strlen((string) ($row['cells'][$i] ?? '')));
            $widths .= sprintf('<col min="%d" max="%d" width="%d" customWidth="1"/>', $i + 1, $i + 1, min(max($w + 2, 8), 60));
        }
        $mergeXml = $merges ? '<mergeCells count="' . count($merges) . '">' . implode('', array_map(fn ($m) => "<mergeCell ref=\"$m\"/>", $merges)) . '</mergeCells>' : '';
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $widths . '</cols><sheetData>' . implode('', $sheet) . '</sheetData>' . $mergeXml . '</worksheet>';

        $sheetName = self::x(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '-', $title ?: 'Report'), 0, 31));
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => self::styles(),
            'xl/worksheets/sheet1.xml' => $sheetXml,
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $xml) $zip->addFromString($name, $xml);
        $zip->close();
        $content = file_get_contents($tmp);
        @unlink($tmp);
        return $content;
    }

    private static function styleFor(string $type, bool $bold, $v): int
    {
        if (!is_int($v) && !is_float($v)) return $bold ? self::S_BOLD : self::S_TEXT;
        return match ($type) {
            'money' => $bold ? self::S_MONEY_BOLD : self::S_MONEY,
            'qty' => self::S_QTY,
            'percent' => self::S_PCT,
            'int' => self::S_INT,
            default => $bold ? self::S_MONEY_BOLD : self::S_MONEY,
        };
    }

    private static function row(int $r, array $cells): string
    {
        return '<row r="' . $r . '">' . implode('', $cells) . '</row>';
    }

    private static function cell(string $ref, $v, int $style): string
    {
        if ($v === null || $v === '') return '<c r="' . $ref . '" s="' . $style . '"/>';
        if (is_int($v) || is_float($v)) return '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>';
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::x((string) $v) . '</t></is></c>';
    }

    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private static function x(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0.00;[Red]-#,##0.00"/><numFmt numFmtId="165" formatCode="#,##0.####"/><numFmt numFmtId="166" formatCode="0.00&quot;%&quot;"/></numFmts>'
            . '<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="13"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F2937"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="9">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    /** Fallback: Excel 2003 XML spreadsheet (opens in Excel without ext-zip). */
    public static function xml2003(string $business, string $title, string $subtitle, array $columns, array $rows): string
    {
        $x = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0"?><?mso-application progid="Excel.Sheet"?>'
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            . '<Styles><Style ss:ID="b"><Font ss:Bold="1"/></Style><Style ss:ID="m"><NumberFormat ss:Format="#,##0.00"/></Style></Styles>'
            . '<Worksheet ss:Name="Report"><Table>';
        foreach ([$business, $title, $subtitle, ''] as $line) $out .= '<Row><Cell ss:StyleID="b"><Data ss:Type="String">' . $x($line) . '</Data></Cell></Row>';
        $out .= '<Row>' . implode('', array_map(fn ($c) => '<Cell ss:StyleID="b"><Data ss:Type="String">' . $x($c['label']) . '</Data></Cell>', $columns)) . '</Row>';
        foreach ($rows as $row) {
            $out .= '<Row>';
            foreach ($columns as $i => $c) {
                $v = $row['cells'][$i] ?? '';
                $num = is_int($v) || is_float($v);
                $out .= '<Cell' . (!empty($row['bold']) ? ' ss:StyleID="b"' : ($num ? ' ss:StyleID="m"' : '')) . '><Data ss:Type="' . ($num ? 'Number' : 'String') . '">' . $x($v) . '</Data></Cell>';
            }
            $out .= '</Row>';
        }
        return $out . '</Table></Worksheet></Workbook>';
    }
}
