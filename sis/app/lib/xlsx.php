<?php
/**
 * Small Excel (.xlsx) reader and writer for the import templates, with no extra PHP extension:
 * .xlsx is a zip of XML files, read and written here with zlib (always on) and SimpleXML.
 * CSV files are read too.
 */
declare(strict_types=1);

final class Xlsx
{
    public static array $sourceRows=[];
    /**
     * Rows of one sheet of an uploaded file, as arrays of trimmed strings. Empty rows are skipped.
     * $sheet = sheet name (case-insensitive) or null for the first sheet.
     */
    public static function read(string $path, string $originalName, ?string $sheet = null): array
    {
        self::$sourceRows=[];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'csv') return self::readCsv($path);
        if ($ext !== 'xlsx') throw new AppError('Choose an Excel file (.xlsx) or a CSV file. Older .xls files: open them in Excel and save as .xlsx.');
        $files = self::unzip((string) file_get_contents($path));
        $strings = [];
        if (isset($files['xl/sharedStrings.xml'])) {
            $sx = self::xml($files['xl/sharedStrings.xml']);
            foreach ($sx->si as $si) $strings[] = self::text($si);
        }
        $target = self::sheetPath($files, $sheet);
        if ($target === null) {
            if ($sheet !== null) return [];
            throw new AppError('The Excel file has no sheet.');
        }
        $rows = [];
        foreach (self::xml($files[$target])->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = self::colIndex((string) $c['r']);
                $t = (string) $c['t'];
                $v = match ($t) {
                    's' => $strings[(int) $c->v] ?? '',
                    'inlineStr' => self::text($c->is),
                    'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                    default => self::number((string) $c->v),
                };
                $cells[$col] = trim($v);
            }
            if (!$cells) continue;
            $line = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $i => $v) $line[$i] = $v;
            if (implode('', $line) !== '') { $rows[] = $line; self::$sourceRows[]=(int)$row['r']; }
        }
        return $rows;
    }

    /** Names of the sheets in an .xlsx file. */
    public static function sheetNames(string $path): array
    {
        $files = self::unzip((string) file_get_contents($path));
        if (!isset($files['xl/workbook.xml'])) return [];
        $out = [];
        foreach (self::xml($files['xl/workbook.xml'])->sheets->sheet as $s) $out[] = (string) $s['name'];
        return $out;
    }

    /** An .xlsx file (binary string) with these sheets: ['Sheet name' => [[header…], [row…], …]]. The first row of each sheet is bold. */
    public static function write(array $sheets,array $dropdowns=[]): string
    {
        $definedNames='';$validationXml='';
        if($dropdowns){
            $lists=[];$index=0;
            foreach($dropdowns as $column=>$choices){$choices=array_values(array_unique(array_map('strval',$choices)));if(!$choices)continue;
                foreach($choices as $n=>$choice)$lists[$n][$index]=$choice;
                $letter=self::colName($index);$name='migration_list_'.$index;
                $definedNames.='<definedName name="'.$name.'">Lists!$'.$letter.'$1:$'.$letter.'$'.count($choices).'</definedName>';
                $validationXml.='<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorStyle="warning" sqref="'.self::colName((int)$column).'2:'.self::colName((int)$column).'1001"><formula1>'.$name.'</formula1></dataValidation>';$index++;
            }
            if($lists){$width=max(array_map(fn($row)=>max(array_keys($row)),$lists))+1;foreach($lists as &$list){$filled=array_fill(0,$width,'');foreach($list as $key=>$value)$filled[$key]=$value;$list=$filled;}unset($list);$sheets['Lists']=$lists;}
        }
        $esc = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $files = [];
        $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wbSheets = $wbRels = '';
        $n = 0;
        foreach ($sheets as $name => $rows) {
            $n++;
            $files['[Content_Types].xml'] .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . $esc(mb_substr((string) $name, 0, 31)) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $widths = [];
            foreach ($rows as $row) foreach (array_values($row) as $i => $v) $widths[$i] = max($widths[$i] ?? 8, min(60, mb_strlen((string) $v) + 2));
            $cols = '';
            foreach ($widths as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
            $data = '';
            foreach (array_values($rows) as $r => $row) {
                $data .= '<row r="' . ($r + 1) . '">';
                foreach (array_values($row) as $i => $v) {
                    $ref = self::colName($i) . ($r + 1);
                    $style = $r === 0 ? ' s="1"' : '';
                    $data .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $esc((string) $v) . '</t></is></c>';
                }
                $data .= '</row>';
            }
            $files['xl/worksheets/sheet' . $n . '.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
                . ($cols ? '<cols>' . $cols . '</cols>' : '') . '<sheetData>' . $data . '</sheetData>'.($n===1&&$validationXml!==''?'<dataValidations>'.$validationXml.'</dataValidations>':'').'</worksheet>';
        }
        $files['[Content_Types].xml'] .= '</Types>';
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets>'.($definedNames?'<definedNames>'.$definedNames.'</definedNames>':'').'</workbook>';
        $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels
            . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8EEF8"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        return self::zip($files);
    }

    /** Send an .xlsx file to the browser as a download. */
    public static function download(string $filename, array $sheets,array $dropdowns=[]): never
    {
        $bin = self::write($sheets,$dropdowns);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }

    // ------------------------------------------------------------ helpers
    private static function readCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
        if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        $first = strtok($raw, "\n") ?: '';
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $rows = [];
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw); rewind($fh);
        $sourceRow=0;
        while (($line = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $sourceRow++;
            $line = array_map(fn($v) => trim((string) $v), $line);
            if (implode('', $line) !== '') { $rows[] = $line; self::$sourceRows[]=$sourceRow; }
        }
        fclose($fh);
        return $rows;
    }

    private static function sheetPath(array $files, ?string $sheet): ?string
    {
        if (!isset($files['xl/workbook.xml'], $files['xl/_rels/workbook.xml.rels'])) return null;
        $wb = self::xml($files['xl/workbook.xml']);
        $rels = [];
        foreach (self::xml($files['xl/_rels/workbook.xml.rels'])->Relationship as $r) $rels[(string) $r['Id']] = (string) $r['Target'];
        foreach ($wb->sheets->sheet as $s) {
            if ($sheet !== null && strcasecmp(trim((string) $s['name']), $sheet) !== 0) continue;
            $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $t = $rels[$rid] ?? '';
            $t = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
            return isset($files[$t]) ? $t : null;
        }
        return null;
    }

    private static function xml(string $s): SimpleXMLElement
    {
        if(!function_exists('simplexml_load_string'))throw new AppError('Excel upload requires the PHP SimpleXML extension. Enable it in php.ini and restart Apache; CSV upload is also available.');
        $x = simplexml_load_string($s, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($x === false) throw new AppError('The Excel file could not be read. Save it again in Excel as .xlsx and try once more.');
        return $x;
    }

    private static function text(SimpleXMLElement $node): string
    {
        if (isset($node->t)) return (string) $node->t;
        $s = '';
        foreach ($node->r as $r) $s .= (string) $r->t;  // rich text runs
        return $s;
    }

    /** "1500" stays "1500"; "0.30000000000000004" becomes "0.3" (Excel's float noise). */
    private static function number(string $v): string
    {
        if ($v === '' || !is_numeric($v)) return $v;
        return rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.') ?: '0';
    }

    private static function colIndex(string $ref): int
    {
        $n = 0;
        foreach (str_split(preg_replace('/[^A-Z]/', '', strtoupper($ref))) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return max(0, $n - 1);
    }

    private static function colName(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    /** Files inside a zip archive (stored or deflated), by name. */
    private static function unzip(string $zip): array
    {
        $eocd = strrpos($zip, "PK\x05\x06");
        if ($eocd === false) throw new AppError('This is not an Excel .xlsx file. Save it in Excel as "Excel Workbook (.xlsx)".');
        $e = unpack('vdisk/vcd_disk/ventries_disk/ventries/Vcd_size/Vcd_offset', substr($zip, $eocd + 4, 16));
        $p = $e['cd_offset'];
        $out = [];
        for ($i = 0; $i < $e['entries']; $i++) {
            if (substr($zip, $p, 4) !== "PK\x01\x02") break;
            $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voffset', substr($zip, $p + 4, 42));
            $name = substr($zip, $p + 46, $h['nlen']);
            $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
            $lh = unpack('vnlen/velen', substr($zip, $h['offset'] + 26, 4));
            $data = substr($zip, $h['offset'] + 30 + $lh['nlen'] + $lh['elen'], $h['csize']);
            if ($h['size'] > 20 * 1024 * 1024) throw new AppError('The Excel file is too large.');
            if ($h['method'] === 8) $data = @gzinflate($data);
            elseif ($h['method'] !== 0) continue;
            if ($data === false) throw new AppError('The Excel file is damaged. Save it again in Excel and try once more.');
            $out[$name] = $data;
        }
        return $out;
    }

    /** A zip archive (deflated) of these files. */
    private static function zip(array $files): string
    {
        $body = $cd = '';
        foreach ($files as $name => $data) {
            $crc = crc32($data);
            $comp = gzdeflate($data, 6);
            $head = pack('vvvvvVVVvv', 20, 0, 8, 0, 0x21, $crc, strlen($comp), strlen($data), strlen($name), 0);
            $offset = strlen($body);
            $body .= "PK\x03\x04" . $head . $name . $comp;
            $cd .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 8, 0, 0x21, $crc, strlen($comp), strlen($data), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }
        return $body . $cd . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($cd), strlen($body), 0);
    }
}
