<?php
namespace App\Core;

/**
 * Reads old-style Excel 97-2003 .xls files (BIFF8 inside an OLE compound file) — e.g. the attendance report a
 * ZKTeco biometric device saves to a USB stick. No libraries: the compound file is walked through its FAT, then the
 * "Workbook" stream's records give the shared strings, the sheet list and every cell value.
 *
 *   $sheets = XlsReader::sheets('/tmp/1_report_1.xls');   // ['Att.log report' => [['Attendance Record Report'], ...], ...]
 *
 * Values come back as strings (numbers without a trailing ".0"); empty cells are ''. Formulas give their cached value.
 */
class XlsReader
{
    private string $data;
    private int $sectorSize;
    private int $miniSectorSize;
    private array $fat = [];
    private array $miniFat = [];
    private string $miniStream = '';
    private int $miniCutoff;

    /** [sheet name => rows] for every worksheet, in workbook order. */
    public static function sheets(string $path): array
    {
        $bin = (string) file_get_contents($path);
        if (substr($bin, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw HttpException::bad('This is not an Excel 97-2003 (.xls) file. Save it as .xls, .xlsx or CSV and upload it again.');
        }
        $r = new self($bin);
        $wb = $r->stream(['Workbook', 'Book']);
        if ($wb === null) throw HttpException::bad('The .xls file has no workbook inside');
        return (new BiffWorkbook($wb))->sheets();
    }

    /** Rows of the first worksheet (for SheetReader). */
    public static function firstSheet(string $path): array
    {
        $sheets = self::sheets($path);
        return $sheets ? reset($sheets) : [];
    }

    private function __construct(string $bin)
    {
        $this->data = $bin;
        $h = unpack('vminor/vmajor/vorder/vsecShift/vminiShift', substr($bin, 0x18, 10));
        $this->sectorSize = 1 << $h['secShift'];
        $this->miniSectorSize = 1 << $h['miniShift'];
        $f = unpack('VnumFat/VdirStart/Vtrans/Vcutoff/VminiFatStart/VnumMiniFat/VdifatStart/VnumDifat', substr($bin, 0x2C, 32));
        $this->miniCutoff = $f['cutoff'] ?: 4096;

        // FAT sector list: 109 in the header, the rest in DIFAT sectors
        $fatSectors = [];
        $head = unpack('V109', substr($bin, 0x4C, 436));
        foreach ($head as $s) if ($s < 0xFFFFFFFA) $fatSectors[] = $s;
        $difat = $f['difatStart'];
        $perSector = intdiv($this->sectorSize, 4);
        for ($i = 0; $i < $f['numDifat'] && $difat < 0xFFFFFFFA; $i++) {
            $vals = array_values(unpack('V' . $perSector, $this->sector($difat)));
            for ($k = 0; $k < $perSector - 1; $k++) if ($vals[$k] < 0xFFFFFFFA) $fatSectors[] = $vals[$k];
            $difat = $vals[$perSector - 1];
        }
        foreach ($fatSectors as $s) foreach (unpack('V' . $perSector, $this->sector($s)) as $v) $this->fat[] = $v;

        $this->dirStart = $f['dirStart'];
        if ($f['numMiniFat'] > 0) {
            $mf = $this->chain($f['miniFatStart']);
            foreach (str_split($mf, 4) as $chunk) if (strlen($chunk) === 4) $this->miniFat[] = unpack('V', $chunk)[1];
        }
    }

    private int $dirStart;

    private function sector(int $sid): string
    {
        return substr($this->data, ($sid + 1) * $this->sectorSize, $this->sectorSize);
    }

    /** All bytes of a FAT chain. */
    private function chain(int $start, ?int $size = null): string
    {
        $out = '';
        $seen = [];
        for ($s = $start; $s < 0xFFFFFFFA && isset($this->fat[$s]) && !isset($seen[$s]); $s = $this->fat[$s]) {
            $seen[$s] = true;
            $out .= $this->sector($s);
        }
        return $size === null ? $out : substr($out, 0, $size);
    }

    private function miniChain(int $start, int $size): string
    {
        $out = '';
        $seen = [];
        for ($s = $start; $s < 0xFFFFFFFA && isset($this->miniFat[$s]) && !isset($seen[$s]); $s = $this->miniFat[$s]) {
            $seen[$s] = true;
            $out .= substr($this->miniStream, $s * $this->miniSectorSize, $this->miniSectorSize);
        }
        return substr($out, 0, $size);
    }

    /** Contents of the first stream with one of these names (directory search). */
    private function stream(array $names): ?string
    {
        $dir = $this->chain($this->dirStart);
        $entries = [];
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $e = substr($dir, $o, 128);
            $len = unpack('v', substr($e, 0x40, 2))[1];
            $type = ord($e[0x42]);
            if ($type === 0 || $len < 2) continue;
            $name = mb_convert_encoding(substr($e, 0, $len - 2), 'UTF-8', 'UTF-16LE');
            $entries[] = ['name' => $name, 'type' => $type, 'start' => unpack('V', substr($e, 0x74, 4))[1], 'size' => unpack('V', substr($e, 0x78, 4))[1]];
        }
        foreach ($entries as $e) {
            if ($e['type'] === 5) $this->miniStream = $this->chain($e['start'], $e['size']);   // root entry holds the mini stream
        }
        foreach ($names as $want) {
            foreach ($entries as $e) {
                if ($e['type'] !== 2 || strcasecmp($e['name'], $want) !== 0) continue;
                return $e['size'] < $this->miniCutoff ? $this->miniChain($e['start'], $e['size']) : $this->chain($e['start'], $e['size']);
            }
        }
        return null;
    }
}

/** BIFF8 workbook stream → sheets of cell values (used by XlsReader). */
class BiffWorkbook
{
    private string $s;
    private array $sst = [];

    public function __construct(string $stream)
    {
        $this->s = $stream;
    }

    public function sheets(): array
    {
        $sheets = [];      // [name, offset]
        $sstChunks = null;
        $len = strlen($this->s);
        $pos = 0;
        // globals: up to the first EOF
        while ($pos + 4 <= $len) {
            [$type, $size] = array_values(unpack('vt/vs', substr($this->s, $pos, 4)));
            $body = substr($this->s, $pos + 4, $size);
            $pos += 4 + $size;
            if ($type === 0x0085) {                                  // BOUNDSHEET
                $off = unpack('V', substr($body, 0, 4))[1];
                $dt = ord($body[5]);
                $cch = ord($body[6]);
                $name = self::chars($body, 8, $cch, (ord($body[7]) & 1) === 1);
                if ($dt === 0) $sheets[] = [$name, $off];            // worksheets only
            } elseif ($type === 0x00FC) {                            // SST + its CONTINUE records
                $sstChunks = [$body];
                while ($pos + 4 <= $len && unpack('v', substr($this->s, $pos, 2))[1] === 0x003C) {
                    $csize = unpack('v', substr($this->s, $pos + 2, 2))[1];
                    $sstChunks[] = substr($this->s, $pos + 4, $csize);
                    $pos += 4 + $csize;
                }
            } elseif ($type === 0x000A) {                            // EOF of the globals
                break;
            }
        }
        if ($sstChunks) $this->readSst($sstChunks);
        $out = [];
        foreach ($sheets as [$name, $off]) $out[$name] = $this->readSheet($off);
        return $out;
    }

    /** Characters of an XLUnicodeString body (compressed = 1 byte per char, else UTF-16LE). */
    private static function chars(string $b, int $at, int $cch, bool $wide): string
    {
        return $wide ? mb_convert_encoding(substr($b, $at, $cch * 2), 'UTF-8', 'UTF-16LE') : mb_convert_encoding(substr($b, $at, $cch), 'UTF-8', 'ISO-8859-1');
    }

    /** Shared strings: a string may continue into the next CONTINUE record, which then starts with a new flags byte. */
    private function readSst(array $chunks): void
    {
        $ci = 0;
        $p = 8;                                                      // skip cstTotal, cstUnique
        $bytes = function (int $n) use (&$chunks, &$ci, &$p): string {
            $out = '';
            while ($n > 0 && $ci < count($chunks)) {
                $avail = strlen($chunks[$ci]) - $p;
                if ($avail <= 0) { $ci++; $p = 0; continue; }
                $take = min($n, $avail);
                $out .= substr($chunks[$ci], $p, $take);
                $p += $take;
                $n -= $take;
            }
            return $out;
        };
        $total = unpack('V', substr($chunks[0], 4, 4))[1];
        for ($i = 0; $i < $total; $i++) {
            if ($ci >= count($chunks)) break;
            if ($p >= strlen($chunks[$ci])) { $ci++; $p = 0; if ($ci >= count($chunks)) break; }
            $hdr = $bytes(3);
            if (strlen($hdr) < 3) break;
            $cch = unpack('v', substr($hdr, 0, 2))[1];
            $flags = ord($hdr[2]);
            $runs = ($flags & 0x08) ? unpack('v', $bytes(2))[1] : 0;
            $ext = ($flags & 0x04) ? unpack('V', $bytes(4))[1] : 0;
            $wide = ($flags & 0x01) === 1;
            $str = '';
            $left = $cch;
            while ($left > 0 && $ci < count($chunks)) {
                $avail = strlen($chunks[$ci]) - $p;
                if ($avail <= 0) {                                   // continues in the next record: new flags byte
                    $ci++; $p = 0;
                    if ($ci >= count($chunks)) break;
                    $wide = (ord($chunks[$ci][0]) & 0x01) === 1;
                    $p = 1;
                    continue;
                }
                $per = $wide ? 2 : 1;
                $take = min($left, intdiv($avail, $per));
                if ($take === 0) { $p = strlen($chunks[$ci]); continue; }
                $str .= self::chars($chunks[$ci], $p, $take, $wide);
                $p += $take * $per;
                $left -= $take;
            }
            if ($runs) $bytes($runs * 4);
            if ($ext) $bytes($ext);
            $this->sst[] = $str;
        }
    }

    private static function rk(int $rk): float
    {
        if ($rk & 0x02) {
            $v = $rk >> 2;
            if ($v & 0x20000000) $v -= 0x40000000;                  // sign of the 30-bit integer
            $v = (float) $v;
        } else {
            $v = unpack('e', "\0\0\0\0" . pack('V', $rk & 0xFFFFFFFC))[1];
        }
        return ($rk & 0x01) ? $v / 100 : $v;
    }

    private static function num(float $v): string
    {
        if (floor($v) == $v && abs($v) < 1e15) return (string) (int) $v;
        return rtrim(rtrim(sprintf('%.10F', round($v, 10)), '0'), '.');
    }

    private function readSheet(int $pos): array
    {
        $cells = [];
        $len = strlen($this->s);
        $pendingFormula = null;
        while ($pos + 4 <= $len) {
            [$type, $size] = array_values(unpack('vt/vs', substr($this->s, $pos, 4)));
            $b = substr($this->s, $pos + 4, $size);
            $pos += 4 + $size;
            switch ($type) {
                case 0x000A:                                         // EOF
                    break 2;
                case 0x00FD:                                         // LABELSST
                    $v = unpack('vr/vc/vx/Vi', $b);
                    $cells[$v['r']][$v['c']] = $this->sst[$v['i']] ?? '';
                    break;
                case 0x0204:                                         // LABEL
                    $v = unpack('vr/vc/vx/vn', $b);
                    $cells[$v['r']][$v['c']] = self::chars($b, 9, $v['n'], (ord($b[8]) & 1) === 1);
                    break;
                case 0x00D6:                                         // RSTRING
                    $v = unpack('vr/vc/vx/vn', $b);
                    $cells[$v['r']][$v['c']] = self::chars($b, 9, $v['n'], (ord($b[8]) & 1) === 1);
                    break;
                case 0x0203:                                         // NUMBER
                    $v = unpack('vr/vc/vx', $b);
                    $cells[$v['r']][$v['c']] = self::num(unpack('e', substr($b, 6, 8))[1]);
                    break;
                case 0x027E:                                         // RK
                    $v = unpack('vr/vc/vx/Vk', $b);
                    $cells[$v['r']][$v['c']] = self::num(self::rk($v['k']));
                    break;
                case 0x00BD:                                         // MULRK
                    $r = unpack('v', substr($b, 0, 2))[1];
                    $c = unpack('v', substr($b, 2, 2))[1];
                    for ($o = 4; $o + 6 <= strlen($b) - 2; $o += 6, $c++) {
                        $cells[$r][$c] = self::num(self::rk(unpack('V', substr($b, $o + 2, 4))[1]));
                    }
                    break;
                case 0x0205:                                         // BOOLERR
                    $v = unpack('vr/vc/vx/Cval/Cerr', $b);
                    $cells[$v['r']][$v['c']] = $v['err'] ? '' : ($v['val'] ? 'TRUE' : 'FALSE');
                    break;
                case 0x0006:                                         // FORMULA: cached result
                    $v = unpack('vr/vc/vx', $b);
                    $res = substr($b, 6, 8);
                    if (substr($res, 6, 2) === "\xFF\xFF") {
                        $kind = ord($res[0]);
                        if ($kind === 0) { $pendingFormula = [$v['r'], $v['c']]; break; }      // the value is in the next STRING record
                        $cells[$v['r']][$v['c']] = $kind === 1 ? (ord($res[2]) ? 'TRUE' : 'FALSE') : '';
                    } else {
                        $cells[$v['r']][$v['c']] = self::num(unpack('e', $res)[1]);
                    }
                    break;
                case 0x0207:                                         // STRING (result of the formula before it)
                    if ($pendingFormula) {
                        $n = unpack('v', substr($b, 0, 2))[1];
                        $cells[$pendingFormula[0]][$pendingFormula[1]] = self::chars($b, 3, $n, (ord($b[2]) & 1) === 1);
                        $pendingFormula = null;
                    }
                    break;
            }
        }
        if (!$cells) return [];
        ksort($cells);
        $rows = [];
        $maxRow = max(array_keys($cells));
        for ($r = 0; $r <= $maxRow; $r++) {
            if (!isset($cells[$r])) { $rows[] = []; continue; }
            $line = array_fill(0, max(array_keys($cells[$r])) + 1, '');
            foreach ($cells[$r] as $c => $v) $line[$c] = trim((string) $v);
            $rows[] = $line;
        }
        return $rows;
    }
}
