<?php
namespace App\Core;

/**
 * Reads the first worksheet of an Excel .xlsx file, or a .csv file, into rows of cell values (strings).
 * No libraries needed: an .xlsx is a zip of XML files (needs PHP's zip extension, as the Excel export does).
 *
 *   $rows = SheetReader::read('/tmp/upload.xlsx', 'items.xlsx');   // [['Name', 'Price'], ['Adobo', '185'], ...]
 */
class SheetReader
{
    public static function read(string $path, string $originalName = ''): array
    {
        $ext = strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') return self::csv($path);
        if ($ext === 'xlsx') return self::xlsx($path);
        if ($ext === 'xls') throw HttpException::bad('Old .xls files are not supported. In Excel choose File → Save As → "Excel Workbook (.xlsx)" or CSV, then upload again.');
        throw HttpException::bad('Upload an Excel file (.xlsx) or a CSV file');
    }

    public static function csv(string $path): array
    {
        $text = (string) file_get_contents($path);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);                 // UTF-8 BOM from Excel
        if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        $first = strtok($text, "\n") ?: '';
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) $rows[] = array_map(fn ($v) => trim((string) $v), $r);
        fclose($fh);
        return self::trimEmpty($rows);
    }

    public static function xlsx(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) throw HttpException::bad('Reading .xlsx needs the PHP zip extension. Save the sheet as CSV and upload that instead.');
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw HttpException::bad('This file is not a valid .xlsx workbook');
        try {
            $shared = [];
            if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $sx = self::xml($xml);
                foreach ($sx->si as $si) $shared[] = self::text($si);
            }
            $sheetPath = self::firstSheet($zip);
            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) throw HttpException::bad('The workbook has no worksheet');
            $sheet = self::xml($xml);
            $rows = [];
            foreach ($sheet->sheetData->row as $row) {
                $r = (int) $row['r'] ?: count($rows) + 1;
                $cells = [];
                foreach ($row->c as $c) {
                    $col = self::colIndex(preg_replace('/\d+/', '', (string) $c['r']));
                    $t = (string) $c['t'];
                    if ($t === 's') $v = $shared[(int) $c->v] ?? '';
                    elseif ($t === 'inlineStr') $v = self::text($c->is);
                    elseif ($t === 'b') $v = ((string) $c->v) === '1' ? 'TRUE' : 'FALSE';
                    else $v = (string) $c->v;
                    // numbers: undo floating-point noise (0.1 is stored as 0.10000000000000001) and 1E+3 notation
                    if (($t === '' || $t === 'n') && is_numeric($v) && (strlen($v) > 12 || stripos($v, 'e') !== false)) {
                        $v = rtrim(rtrim(sprintf('%.10F', round((float) $v, 10)), '0'), '.');
                    }
                    $cells[$col] = trim($v);
                }
                if (!$cells) continue;
                $line = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $i => $v) $line[$i] = $v;
                $rows[$r] = $line;
            }
            ksort($rows);
            return self::trimEmpty(array_values($rows));
        } finally {
            $zip->close();
        }
    }

    private static function firstSheet(\ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $w = self::xml($wb);
            $first = $w->sheets->sheet[0] ?? null;
            if ($first) {
                $rid = (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach (self::xml($rels)->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');
                        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    private static function xml(string $xml): \SimpleXMLElement
    {
        $x = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        if ($x === false) throw HttpException::bad('The workbook could not be read');
        return $x;
    }

    /** Text of a shared / inline string (plain <t> or rich-text runs <r><t>). */
    private static function text(\SimpleXMLElement $si): string
    {
        if (isset($si->t)) return (string) $si->t;
        $s = '';
        foreach ($si->r as $run) $s .= (string) $run->t;
        return $s;
    }

    /** "A" -> 0, "Z" -> 25, "AA" -> 26 */
    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return max($n - 1, 0);
    }

    /** Drop fully empty rows and trailing empty cells. */
    private static function trimEmpty(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            while ($r && end($r) === '') array_pop($r);
            if ($r && implode('', $r) !== '') $out[] = $r;
        }
        return $out;
    }
}
