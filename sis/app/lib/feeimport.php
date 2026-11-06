<?php
/**
 * Excel import and templates for the fee setup: Categories, Labels, Assessment Names (with their
 * fee lines) and Scholarships & Discounts. Rows are matched by name: an existing record is updated,
 * a new one is added; nothing is deleted. All or nothing: if any row has an error, nothing is saved.
 */
declare(strict_types=1);

final class ImportRollback extends RuntimeException {}

final class FeeImport
{
    public const KINDS = [
        'categories' => 'Categories',
        'labels' => 'Labels',
        'names' => 'Assessment Names',
        'discounts' => 'Scholarships & Discounts',
    ];

    /** Column headings of each sheet; the first ones marked * are required. */
    private const COLS = [
        'categories' => ['Category*', 'Description', 'Order', 'Active'],
        'labels' => ['Category*', 'Label*', 'Charged*', 'Default amount', 'Description', 'Active'],
        'names' => ['Assessment name*', 'Program', 'Year level', 'Term', 'School year', 'Notes', 'Active'],
        'lines' => ['Assessment name*', 'Label*', 'Charged', 'Amount*'],
        'discounts' => ['Name*', 'Type*', 'Computation*', 'Value*', 'Maximum amount', 'Applies to categories', 'Description', 'Active'],
    ];

    // ------------------------------------------------------------ templates
    /** The template workbook: the sheet(s) to fill (with the current records, or examples) and a Help sheet. */
    public static function template(string $kind): array
    {
        Auth::require('assessment');
        $yn = fn($v) => (int) $v ? 'Yes' : 'No';
        $h = fn(string $k) => array_map(fn($c) => rtrim($c, '*'), self::COLS[$k]);
        switch ($kind) {
            case 'categories':
                $rows = array_map(fn($c) => [$c['name'], (string) $c['description'], (string) $c['sort_order'], $yn($c['is_active'])], Assessment::categories());
                $rows = $rows ?: [['Tuition', 'Tuition per unit', '10', 'Yes'], ['Miscellaneous', 'Registration, library, ID', '20', 'Yes']];
                $sheets = ['Categories' => [$h('categories'), ...$rows]];
                $help = [['Category', 'Required. A fee group, e.g. Tuition, Miscellaneous, Laboratory. Same name = updated.'],
                    ['Description', 'Optional.'], ['Order', 'Optional. 1 to 999: order on the assessment (smaller first). Empty = 100.'], ['Active', 'Yes or No. Empty = Yes.']];
                break;
            case 'labels':
                $rows = array_map(fn($l) => [$l['category_name'], $l['name'], CHARGE_TYPES[$l['charge_type']], Money::dec(Money::cents($l['default_amount'])), (string) $l['description'], $yn($l['is_active'])], Assessment::labels());
                $rows = $rows ?: [['Tuition', 'Tuition Fee', 'Per unit', '500.00', '', 'Yes'], ['Miscellaneous', 'Library Fee', 'Fixed amount', '300.00', '', 'Yes'], ['Laboratory', 'Computer Lab', 'Per subject', '100.00', '', 'Yes']];
                $sheets = ['Labels' => [$h('labels'), ...$rows]];
                $help = [['Category', 'Required. An existing category, or a new one (it is added).'], ['Label', 'Required. The fee line, e.g. Tuition Fee. Same name = updated.'],
                    ['Charged', 'Required. Fixed amount, Per unit or Per subject.'], ['Default amount', 'Optional. e.g. 500 or 1,500.00 (pesos).'],
                    ['Description', 'Optional.'], ['Active', 'Yes or No. Empty = Yes.'], [], ['Categories now', implode(', ', array_column(Assessment::categories(), 'name')) ?: '(none yet)']];
                break;
            case 'names':
                $names = Assessment::names();
                $rows = array_map(fn($n) => [$n['name'], (string) $n['program'], (string) $n['year_level'], (string) $n['term'], (string) $n['school_year'], (string) $n['notes'], $yn($n['is_active'])], $names);
                $lines = [];
                foreach ($names as $n) foreach (Assessment::items((int) $n['id']) as $i) $lines[] = [$n['name'], $i['label_name'], CHARGE_TYPES[$i['charge_type']], Money::dec(Money::cents($i['amount']))];
                if (!$rows) {
                    $rows = [['BSIT 1st Year 1st Semester', 'BSIT', '1st Year', '1st Semester', '', '', 'Yes'], ['General', '', '', '', '', 'For everyone else', 'Yes']];
                    $lines = [['BSIT 1st Year 1st Semester', 'Tuition Fee', 'Per unit', '500.00'], ['BSIT 1st Year 1st Semester', 'Library Fee', 'Fixed amount', '300.00'], ['General', 'Tuition Fee', 'Per unit', '450.00']];
                }
                $sheets = ['Names' => [$h('names'), ...$rows], 'Fee lines' => [$h('lines'), ...$lines]];
                $help = [['Sheet "Names"', 'One row per assessment name (fee template).'], ['Assessment name', 'Required. Same name = updated.'],
                    ['Program / Year level / Term / School year', 'Optional: who it is for. Empty = any. Year level: ' . implode(', ', YEAR_LEVELS) . '. Term: ' . implode(', ', TERMS) . '. School year like 2026-2027.'],
                    ['Active', 'Yes or No. Empty = Yes.'], [], ['Sheet "Fee lines"', 'The fees of each name: one row per fee.'],
                    ['Assessment name', 'Required: a name from the Names sheet (or one that already exists).'], ['Label', 'Required: an existing label (see Labels).'],
                    ['Charged', 'Optional: Fixed amount, Per unit or Per subject. Empty = as the label.'], ['Amount', 'Required, e.g. 500 (per unit), 1,500.00 (fixed).'],
                    ['Note', 'Fee lines are added or updated; lines not in the file are kept. Remove a line on the Names tab.'], [], ['Labels now', implode(', ', array_column(Assessment::labels(), 'name')) ?: '(none yet)']];
                break;
            case 'discounts':
                $rows = array_map(fn($d) => [$d['name'], Discounts::KINDS[$d['kind']], $d['method'] === 'PERCENT' ? 'Percentage' : 'Fixed amount',
                    rtrim(rtrim((string) $d['value'], '0'), '.'), $d['max_amount'] !== null ? Money::dec(Money::cents($d['max_amount'])) : '', implode(', ', $d['categories']), (string) $d['description'], $yn($d['is_active'])], Discounts::all());
                $rows = $rows ?: [['Academic Scholarship', 'Scholarship', 'Percentage', '50', '', 'Tuition', 'Dean\'s list', 'Yes'], ['Sibling Discount', 'Discount', 'Percentage', '10', '1,000.00', '', '', 'Yes'], ['Athlete Grant', 'Scholarship', 'Fixed amount', '5,000', '', '', '', 'Yes']];
                $sheets = ['Scholarships & Discounts' => [$h('discounts'), ...$rows]];
                $help = [['Name', 'Required. Same name = updated.'], ['Type', 'Required. Scholarship or Discount.'], ['Computation', 'Required. Percentage or Fixed amount.'],
                    ['Value', 'Required. Percentage: 1 to 100 (e.g. 50). Fixed amount: pesos (e.g. 5,000).'], ['Maximum amount', 'Optional. The most it can take off, in pesos. Empty = no limit.'],
                    ['Applies to categories', 'Optional. Category names separated by commas, e.g. Tuition, Laboratory. Empty = the whole assessment.'],
                    ['Description', 'Optional.'], ['Active', 'Yes or No. Empty = Yes.'], [], ['Categories now', implode(', ', array_column(Assessment::categories(), 'name')) ?: '(none yet)']];
                break;
            default:
                throw new AppError('Unknown template.');
        }
        return $sheets + ['Help' => [['Column', 'What to put'], ...$help, [], ['How it works', 'Rows are matched by name: existing ones are updated, new ones added. Nothing is deleted. If a row has an error, nothing is saved: fix it and import again.']]];
    }

    // ------------------------------------------------------------ import
    /**
     * Import an uploaded file. $check = only check, save nothing.
     * Returns ['added' => n, 'updated' => n, 'rows' => n, 'errors' => ["Row 3: …"], 'saved' => bool].
     */
    public static function run(string $kind, string $path, string $fileName, bool $check): array
    {
        Auth::require('assessment.edit');
        if (!isset(self::KINDS[$kind])) throw new AppError('Unknown import.');
        $res = ['added' => 0, 'updated' => 0, 'rows' => 0, 'errors' => [], 'saved' => false];
        $isXlsx = strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'xlsx';
        $jobs = match ($kind) {
            'categories' => [['categories', $isXlsx ? 'Categories' : null]],
            'labels' => [['labels', $isXlsx ? 'Labels' : null]],
            'discounts' => [['discounts', $isXlsx ? 'Scholarships & Discounts' : null]],
            'names' => $isXlsx ? [['names', 'Names'], ['lines', 'Fee lines']] : [[null, null]], // CSV: one sheet, names or fee lines (by its headings)
        };
        try {
            Db::tx(function () use ($kind, $jobs, $path, $fileName, $isXlsx, $check, &$res) {
                $any = false;
                foreach ($jobs as [$sheetKind, $sheetName]) {
                    $rows = Xlsx::read($path, $fileName, $sheetName);
                    if ($isXlsx && !$rows && $sheetName !== null) {
                        // A file saved with another first-sheet name: use the first sheet for a one-sheet import
                        if (count($jobs) === 1) $rows = Xlsx::read($path, $fileName, null);
                        if (!$rows) continue;
                    }
                    if (!$rows) continue;
                    $map = self::headings(array_shift($rows));
                    $sheetKind ??= isset($map['label'], $map['amount']) ? 'lines' : 'names';
                    self::requireColumns($sheetKind, $map, $sheetName);
                    $any = true;
                    foreach ($rows as $i => $row) {
                        $rowNo = $i + 2;
                        $v = fn(string $col) => trim((string) ($row[$map[$col] ?? -1] ?? ''));
                        $res['rows']++;
                        try {
                            $method = 'row' . ucfirst($sheetKind);
                            $what = self::$method($v);
                            $res[$what]++;
                        } catch (AppError | Forbidden $e) {
                            $res['errors'][] = ($sheetName ? "$sheetName, row $rowNo" : "Row $rowNo") . ': ' . $e->getMessage();
                        }
                    }
                }
                if (!$any) throw new AppError('No rows to import. Use the template: the first row must be the column headings.');
                if ($res['errors'] || $check) throw new ImportRollback();
                Audit::log('fees_imported', 'import', $kind, ['what' => self::KINDS[$kind], 'file' => $fileName, 'added' => $res['added'], 'updated' => $res['updated']]);
                $res['saved'] = true;
            });
        } catch (ImportRollback) {
            // nothing saved: errors to fix, or only checking
        }
        return $res;
    }

    /** The uploaded file of the import form ($_FILES['file']): run it and put the result in a flash message. */
    public static function handleUpload(string $kind): void
    {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new AppError('Choose the Excel file to import.');
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new AppError('The file could not be uploaded. Try again.');
        if ($f['size'] > 5 * 1024 * 1024) throw new AppError('The file is too large (5 MB at most).');
        $check = !empty($_POST['check_only']);
        $r = self::run($kind, $f['tmp_name'], (string) $f['name'], $check);
        $what = self::KINDS[$kind];
        if ($r['errors']) {
            $list = array_slice($r['errors'], 0, 20);
            flash('danger', "Nothing was imported: fix these rows in the file and import again.\n• " . implode("\n• ", $list) . (count($r['errors']) > 20 ? "\n… and " . (count($r['errors']) - 20) . ' more.' : ''));
        } elseif ($check) {
            flash('info', "File checked ($what): no errors. {$r['added']} would be added, {$r['updated']} updated. Nothing is saved yet: untick \"Only check\" and import again.");
        } else {
            flash('success', "Imported $what: {$r['added']} added, {$r['updated']} updated.");
        }
    }

    // ------------------------------------------------------------ one row of each sheet
    private static function rowCategories(callable $v): string
    {
        $name = $v('category');
        if ($name === '') throw new AppError('Category is empty.');
        $id = self::idByName('sis_fee_categories', $name);
        $order = $v('order');
        Assessment::saveCategory($id, ['name' => $name, 'description' => $v('description'), 'sort_order' => $order === '' ? '' : $order, 'is_active' => self::yes($v('active'))]);
        return $id ? 'updated' : 'added';
    }

    private static function rowLabels(callable $v): string
    {
        $cat = $v('category');
        $name = $v('label');
        if ($cat === '' || $name === '') throw new AppError('Category and Label are required.');
        $catId = self::idByName('sis_fee_categories', $cat) ?? Assessment::saveCategory(null, ['name' => $cat, 'is_active' => 1]);
        $id = self::idByName('sis_fee_labels', $name);
        Assessment::saveLabel($id, ['category_id' => (string) $catId, 'name' => $name, 'charge_type' => self::charged($v('charged'), true),
            'default_amount' => self::amount($v('default amount')), 'description' => $v('description'), 'is_active' => self::yes($v('active'))]);
        return $id ? 'updated' : 'added';
    }

    private static function rowNames(callable $v): string
    {
        $name = $v('assessment name');
        if ($name === '') throw new AppError('Assessment name is empty.');
        $id = self::idByName('sis_assessment_names', $name);
        Assessment::saveName($id, ['name' => $name, 'program' => $v('program'), 'year_level' => self::oneOf($v('year level'), YEAR_LEVELS, 'Year level'),
            'term' => self::oneOf($v('term'), TERMS, 'Term'), 'school_year' => $v('school year'), 'notes' => $v('notes'), 'is_active' => self::yes($v('active'))]);
        return $id ? 'updated' : 'added';
    }

    private static function rowLines(callable $v): string
    {
        $nameId = self::idByName('sis_assessment_names', $v('assessment name'));
        if (!$nameId) throw new AppError('Assessment name "' . $v('assessment name') . '" not found (add it on the Names sheet).');
        $label = Db::one('SELECT id, charge_type FROM sis_fee_labels WHERE LOWER(name) = LOWER(?)', [$v('label')]);
        if (!$label) throw new AppError('Label "' . $v('label') . '" not found (add it on the Labels tab first).');
        $amount = self::amount($v('amount'));
        if ($amount === '') throw new AppError('Amount is empty.');
        $cents = Money::cents($amount);
        $type = $v('charged') === '' ? $label['charge_type'] : self::charged($v('charged'), true);
        $exists = (bool) Db::val('SELECT 1 FROM sis_assessment_name_items WHERE assessment_name_id = ? AND label_id = ?', [$nameId, $label['id']]);
        Assessment::setItem($nameId, (int) $label['id'], $type, $cents);
        return $exists ? 'updated' : 'added';
    }

    private static function rowDiscounts(callable $v): string
    {
        $name = $v('name');
        if ($name === '') throw new AppError('Name is empty.');
        $kind = match (strtolower($v('type'))) { 'scholarship' => 'SCHOLARSHIP', 'discount' => 'DISCOUNT', default => throw new AppError('Type must be Scholarship or Discount.') };
        $method = match (true) {
            in_array(strtolower($v('computation')), ['percentage', 'percent', '%'], true) => 'PERCENT',
            in_array(strtolower($v('computation')), ['fixed amount', 'fixed', 'amount'], true) => 'FIXED',
            default => throw new AppError('Computation must be Percentage or Fixed amount.'),
        };
        $cats = [];
        foreach (array_filter(array_map('trim', explode(',', $v('applies to categories'))), 'strlen') as $c) {
            $cid = self::idByName('sis_fee_categories', $c);
            if (!$cid) throw new AppError("Category \"$c\" not found.");
            $cats[] = (string) $cid;
        }
        $id = self::idByName('sis_discounts', $name);
        Discounts::save($id, ['name' => $name, 'kind' => $kind, 'method' => $method, 'value' => self::amount(rtrim($v('value'), '% ')),
            'max_amount' => self::amount($v('maximum amount')), 'categories' => $cats, 'description' => $v('description'), 'is_active' => self::yes($v('active'))]);
        return $id ? 'updated' : 'added';
    }

    // ------------------------------------------------------------ helpers
    /** Column heading → index, by lower-case heading without "*" and extra spaces. */
    private static function headings(array $row): array
    {
        $map = [];
        foreach ($row as $i => $h) {
            $k = strtolower(trim(preg_replace('/\s+/', ' ', str_replace('*', '', (string) $h))));
            if ($k !== '' && !isset($map[$k])) $map[$k] = $i;
        }
        return $map;
    }

    private static function requireColumns(string $kind, array $map, ?string $sheet): void
    {
        $missing = [];
        foreach (self::COLS[$kind] as $c) if (str_ends_with($c, '*') && !isset($map[strtolower(rtrim($c, '*'))])) $missing[] = rtrim($c, '*');
        if ($missing) throw new AppError(($sheet ? "Sheet \"$sheet\": " : '') . 'missing column(s): ' . implode(', ', $missing) . '. Download the template and keep its first row.');
    }

    private static function idByName(string $table, string $name): ?int
    {
        if ($name === '') return null;
        $id = Db::val("SELECT id FROM $table WHERE LOWER(name) = LOWER(?) ORDER BY id LIMIT 1", [$name]);
        return $id ? (int) $id : null;
    }

    private static function yes(string $v): string
    {
        $v = strtolower($v);
        if ($v === '' || in_array($v, ['yes', 'y', '1', 'true', 'active', 'on'], true)) return '1';
        if (in_array($v, ['no', 'n', '0', 'false', 'inactive', 'off'], true)) return '';
        throw new AppError('Active must be Yes or No.');
    }

    private static function charged(string $v, bool $required): string
    {
        $k = strtolower(preg_replace('/[\s_]+/', ' ', $v));
        return match ($k) {
            'fixed', 'fixed amount' => 'FIXED',
            'per unit', 'unit', 'units' => 'PER_UNIT',
            'per subject', 'subject', 'subjects' => 'PER_SUBJECT',
            '' => $required ? throw new AppError('Charged is empty: Fixed amount, Per unit or Per subject.') : '',
            default => throw new AppError("Charged \"$v\": use Fixed amount, Per unit or Per subject."),
        };
    }

    /** "₱1,500.00" → "1500.00"; empty stays empty. */
    private static function amount(string $v): string
    {
        $v = trim(str_replace(['₱', 'PHP', 'Php', ',', ' '], '', $v));
        if ($v === '') return '';
        if (!preg_match('/^\d+(\.\d+)?$/', $v)) throw new AppError("Amount \"$v\" is not a number.");
        return number_format(round((float) $v, 2), 2, '.', '');
    }

    /** A value from a fixed list, matched without caring about case; empty stays empty. */
    private static function oneOf(string $v, array $allowed, string $label): string
    {
        if ($v === '') return '';
        foreach ($allowed as $a) if (strcasecmp($a, $v) === 0) return $a;
        throw new AppError("$label \"$v\": use " . implode(', ', $allowed) . '.');
    }
}
