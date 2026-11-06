<?php
declare(strict_types=1);

/**
 * v56 Data Migration → Import All: subjects, subject prices (Units × Price per Unit), fee templates,
 * teachers and curriculum from one Excel sheet.
 *
 *   1. Download Template (built from FIELDS and the current setup, with drop-down lists).
 *   2. Upload: every row is checked and stored as a preview batch. Nothing live changes yet.
 *   3. Preview: tick the rows to import and choose Add / Skip / Update / Replace for each (or for all ticked).
 *   4. Confirm: totals are shown; Replace needs the word REPLACE typed. Rows are checked again against the live
 *      records before anything is written.
 *   5. Results: added / updated / replaced / skipped / failed, a downloadable report and the import history.
 *
 * Matching uses the Subject Code (never the name). Update changes only the values given in the file. Replace
 * sets the subject, its price for that fee, its ticked fee templates and its curriculum teacher to the file's
 * values. Grades, registrations, payments and enrollment history are never changed: subjects keep their id,
 * confirmed assessments keep their amounts, classes keep their students and grades.
 */
final class ImportAll
{
    /** key => [column heading, required for a new subject, help]. New supported fields are added here (template and reader follow). */
    public const FIELDS = [
        'code' => ['Subject Code', true, 'Unique identifier of the subject, e.g. IT101. Existing subjects are matched by this code.'],
        'name' => ['Subject Name', true, 'Required for a new subject.'],
        'units' => ['Subject Units', true, '0.5 to 30, at most one decimal (e.g. 3 or 1.5). Required for a new subject.'],
        'type' => ['Subject Type', false, 'Lecture, Laboratory, Lecture and Laboratory, or Other.'],
        'price' => ['Price per Unit', false, 'Pesos per unit, e.g. 450.00. Blank = ₱0.00 for a new subject; unchanged on Update.'],
        'fee' => ['Fee Label', false, 'The fee the price belongs to (Assessment → Labels). Blank = the default subject fee.'],
        'templates' => ['Fee Templates', false, 'Fee templates that charge this price, separated by commas. Blank = none ticked (Update keeps the current ones).'],
        'teacher' => ['Professor / Teacher', false, 'Teacher ID or exact teacher name. Blank = Professor X (needs teacher).'],
        'curriculum' => ['Curriculum', false, 'An existing curriculum, written as in the list: BSIT · 1st Year · 1st Semester. Or use the next three columns.'],
        'program' => ['Course / Program', false, 'Program as in section names (BSIT 1A → BSIT). Use with Year Level and Semester.'],
        'level' => ['Year Level', false, '1st Year to 5th Year.'],
        'term' => ['Semester', false, '1st Semester, 2nd Semester, 3rd Semester or Summer.'],
        'lec' => ['Lecture Units', false, 'Used when Settings → Separate lecture and laboratory units is on. Lecture + Laboratory must equal Subject Units (or fill only these two).'],
        'lab' => ['Laboratory Units', false, 'Laboratory units of the subject (see Lecture Units).'],
    ];
    public const SUBJECT_TYPES = ['Lecture', 'Laboratory', 'Lecture and Laboratory', 'Other'];
    public const ACTIONS = ['ADD' => 'Add', 'SKIP' => 'Skip', 'UPDATE' => 'Update', 'REPLACE' => 'Replace'];
    public const MAX_ROWS = 1000;

    public static function install(): void
    {
        Db::q("CREATE TABLE IF NOT EXISTS sis_import_all_batches (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            file_name VARCHAR(200) NOT NULL,
            status ENUM('PREVIEW','APPLIED','DISCARDED') NOT NULL DEFAULT 'PREVIEW',
            rows_json MEDIUMTEXT NOT NULL,
            result_json MEDIUMTEXT NULL,
            summary_json TEXT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            applied_by INT UNSIGNED NULL,
            applied_at DATETIME NULL,
            KEY k_import_all_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function can(): bool { return Auth::can('curriculum.edit') && Auth::can('assessment.edit'); }
    public static function requireAccess(): void
    {
        if (!self::can()) throw new Forbidden('Import All changes subjects, curriculum and fees: it needs both Curriculum and Assessment editing rights.');
    }

    // ------------------------------------------------------------ template

    /** Sheets and drop-down lists of the template. $withSubjects: one row per current subject (to update them). */
    public static function template(bool $withSubjects = false): array
    {
        self::requireAccess();
        $keys = array_keys(self::FIELDS);
        $head = array_map(fn($k) => self::FIELDS[$k][0] . (self::FIELDS[$k][1] ? ' *' : ''), $keys);
        $records = [$head];
        if ($withSubjects) {
            $prices = [];
            foreach (SubjectFees::catalog() as $f) $prices[$f['subject_doc_id']] ??= $f;
            foreach (GS::subjects() as $s) {
                $f = $prices[$s['id']] ?? null;
                $row = array_fill_keys($keys, '');
                $row['code'] = (string) ($s['subjectCode'] ?? ''); $row['name'] = (string) ($s['subjectName'] ?? ''); $row['units'] = (string) ($s['units'] ?? '');
                $row['type'] = (string) ($s['subjectType'] ?? '');
                if (isset($s['lecUnits'], $s['labUnits'])) { [$sl, $sb] = LecLab::ofSubject($s); $row['lec'] = LecLab::text($sl); $row['lab'] = LecLab::text($sb); }
                $row['price'] = $f ? Money::fmt(Money::cents($f['rate'])) : '0.00'; $row['fee'] = $f ? (string) $f['label_name'] : '';
                $row['templates'] = $f ? implode(', ', array_column($f['templates'], 'name')) : '';
                $records[] = array_values($row);
            }
        }
        $col = array_flip($keys);
        $labels = array_column(Assessment::labels(true), 'name');
        $teachers = array_map(fn($t) => (string) $t['teacherName'], array_filter(GS::teachers(), fn($t) => ($t['id'] ?? '') !== CurriculumAssignments::PLACEHOLDER));
        $curricula = array_values(array_filter(array_map(fn($c) => (string) ($c['term'] ?? '') === '' ? '' : self::curriculumLabel($c), GS::curricula())));
        $dropdowns = [$col['type'] => self::SUBJECT_TYPES, $col['fee'] => $labels, $col['teacher'] => array_merge(['Professor X'], $teachers),
            $col['curriculum'] => $curricula, $col['program'] => GS::programs(), $col['level'] => YEAR_LEVELS, $col['term'] => TERMS];
        $help = [['Column', 'Required', 'How to fill it']];
        foreach (self::FIELDS as [$h, $req, $text]) $help[] = [$h, $req ? 'Yes (new subjects)' : 'No', $text];
        $help[] = ['', '', ''];
        $help[] = ['Steps', '', '1. Fill the Records sheet (one row per subject; repeat a subject on another row to add it to another curriculum). 2. Upload it in Data Migration → Import All. 3. Tick the rows to import, choose Add / Skip / Update / Replace, review the totals and confirm.'];
        $help[] = ['Totals', '', 'Each subject fee is Subject Units × Price per Unit. A subject without a price stays at ₱0.00 and is still shown on assessments.'];
        $help[] = ['Safety', '', 'Existing subjects are found by Subject Code. Grades, registrations, payments and enrollment history are never changed. Confirmed assessments keep their amounts.'];
        $example = [$head, ['IT101', 'Introduction to Computing', '3', 'Lecture and Laboratory', '450.00', $labels[0] ?? 'Tuition fee', '', $teachers[0] ?? 'Professor X', '', 'BSIT', '1st Year', '1st Semester']];
        return ['sheets' => ['Records' => $records, 'Instructions' => $help, 'Example' => $example], 'dropdowns' => $dropdowns];
    }

    public static function curriculumLabel(array $c): string
    {
        return trim((string) ($c['program'] ?? '')) . ' · ' . (string) ($c['yearLevel'] ?? '') . ' · ' . ((string) ($c['term'] ?? '') ?: 'No term');
    }

    // ------------------------------------------------------------ reading and checking

    /** Rows of the uploaded file: [['row' => Excel row, 'input' => [field key => text]]]. */
    public static function read(string $path, string $fileName): array
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $rows = $ext === 'xlsx' && in_array('records', array_map('strtolower', Xlsx::sheetNames($path)), true) ? Xlsx::read($path, $fileName, 'Records') : Xlsx::read($path, $fileName);
        $lines = Xlsx::$sourceRows;
        if (!$rows) throw new AppError('The file has no rows. Fill the Records sheet of the template.');
        $norm = fn(string $h) => strtolower(trim(preg_replace('/[\s*]+/', ' ', str_replace('*', '', $h))));
        $byHeading = [];
        foreach (self::FIELDS as $k => [$h]) $byHeading[$norm($h)] = $k;
        $byHeading['professor'] = 'teacher'; $byHeading['teacher'] = 'teacher'; $byHeading['course'] = 'program'; $byHeading['program'] = 'program'; $byHeading['units'] = 'units'; $byHeading['term'] = 'term';
        $map = [];
        foreach ($rows[0] as $i => $h) if (isset($byHeading[$norm((string) $h)])) $map[$i] = $byHeading[$norm((string) $h)];
        if (!in_array('code', $map, true)) throw new AppError('The first row must have the column headings of the template (Subject Code is missing). Download the template and copy your data into it.');
        $out = [];
        foreach (array_slice($rows, 1, null, true) as $n => $r) {
            $in = array_fill_keys(array_keys(self::FIELDS), '');
            foreach ($map as $i => $k) $in[$k] = trim((string) ($r[$i] ?? ''));
            if (implode('', $in) === '') continue;
            $out[] = ['row' => $lines[$n] ?? ($n + 1), 'input' => $in];
        }
        if (!$out) throw new AppError('The Records sheet has headings but no records.');
        if (count($out) > self::MAX_ROWS) throw new AppError('Upload at most ' . self::MAX_ROWS . ' rows at a time. Split the file and import the parts one after another.');
        return $out;
    }

    private static function term(string $v): ?string
    {
        $v = strtolower(trim(preg_replace('/\s+/', ' ', $v)));
        if ($v === '') return '';
        foreach (['1st Semester' => ['1st semester', 'first semester', '1st sem', '1st', '1'], '2nd Semester' => ['2nd semester', 'second semester', '2nd sem', '2nd', '2'],
                  '3rd Semester' => ['3rd semester', 'third semester', '3rd sem', '3rd', '3'], 'Summer' => ['summer', 'summer term', 'midyear', 'mid-year']] as $t => $alts)
            if (in_array($v, $alts, true)) return $t;
        return null;
    }

    private static function level(string $v): ?string
    {
        $v = strtolower(trim($v));
        if ($v === '') return '';
        foreach (YEAR_LEVELS as $i => $l) if ($v === strtolower($l) || $v === (string) ($i + 1) || $v === ['first', 'second', 'third', 'fourth', 'fifth'][$i] . ' year') return $l;
        return null;
    }

    /**
     * Checks rows against the live records. Each row gets: 'clean' values, 'errors', 'warnings', 'status'
     * (NEW, EXISTING or ERROR), 'subject_id', 'target' (curriculum [program, level, term] or null), 'actions'
     * (allowed), 'action' (suggested) and 'links' (records already using an existing subject).
     */
    public static function check(array $rows): array
    {
        $subjects = [];
        foreach (GS::subjects() as $s) if (($s['subjectCode'] ?? '') !== '') $subjects[strtoupper((string) $s['subjectCode'])] = $s;
        $labels = [];
        foreach (Assessment::labels(true) as $l) $labels[mb_strtolower($l['name'])] ??= (int) $l['id'];
        $templates = [];
        foreach (Assessment::names() as $t) $templates[mb_strtolower($t['name'])] = (int) $t['id'];
        $teacherById = []; $teacherByName = [];
        foreach (GS::teachers() as $t) {
            if (($t['teacherId'] ?? '') !== '') $teacherById[strtoupper((string) $t['teacherId'])] = $t['id'];
            $teacherByName[mb_strtolower(trim((string) ($t['teacherName'] ?? '')))][] = $t['id'];
        }
        $curricula = [];
        foreach (GS::curricula() as $c) $curricula[mb_strtolower(self::curriculumLabel($c))] = [(string) $c['program'], (string) $c['yearLevel'], (string) ($c['term'] ?? '')];
        $defaultLabel = SubjectFees::defaultLabel();
        $out = [];
        foreach ($rows as $r) {
            $in = $r['input']; $e = []; $w = []; $c = [];
            $c['code'] = strtoupper(trim($in['code']));
            if ($c['code'] === '') $e[] = 'Subject Code is missing.';
            elseif (!preg_match('/^[A-Z0-9][A-Z0-9 .\-\/]{0,39}$/', $c['code'])) $e[] = 'Subject Code: use letters, numbers, spaces, ., -, / (up to 40 characters).';
            $existing = $subjects[$c['code']] ?? null;
            $c['name'] = trim($in['name']);
            if (mb_strlen($c['name']) > 150) $e[] = 'Subject Name is longer than 150 characters.';
            if (!$existing && $c['name'] === '') $e[] = 'Subject Name is missing (required for a new subject).';
            $c['units'] = trim($in['units']);
            if ($c['units'] !== '' && (!preg_match('/^\d{1,2}(?:\.\d)?$/', $c['units']) || (float) $c['units'] <= 0 || (float) $c['units'] > 30)) $e[] = 'Subject Units must be a number from 0.5 to 30 with at most one decimal.';
            if (!$existing && $c['units'] === '') $e[] = 'Subject Units is missing (required for a new subject).';
            // v56.2: lecture / laboratory units; Subject Units may be left blank and is then their sum
            $c['split'] = null;
            if (trim($in['lec'] ?? '') !== '' || trim($in['lab'] ?? '') !== '') {
                try { $c['split'] = LecLab::parse((string) ($in['lec'] ?? ''), (string) ($in['lab'] ?? '')); } catch (AppError $x) { $e[] = $x->getMessage(); }
                if ($c['split']) {
                    $sum = LecLab::text($c['split'][0] + $c['split'][1]);
                    if ($c['units'] === '') { $c['units'] = $sum; $e = array_values(array_filter($e, fn($m) => !str_starts_with($m, 'Subject Units is missing'))); }
                    elseif (abs((float) $c['units'] - (float) $sum) > 0.001) $e[] = "Lecture units + Laboratory units ($sum) must equal Subject Units ({$c['units']}).";
                }
            }
            $c['type'] = '';
            if (trim($in['type']) !== '') {
                $match = array_values(array_filter(self::SUBJECT_TYPES, fn($t) => strcasecmp($t, trim($in['type'])) === 0));
                if ($match) $c['type'] = $match[0]; else $e[] = 'Subject Type must be ' . implode(', ', self::SUBJECT_TYPES) . '.';
            }
            $priceText = str_replace(['₱', ',', ' ', 'PHP', 'php'], '', trim($in['price']));
            $c['price'] = null;
            if ($priceText !== '') {
                if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $priceText)) $e[] = 'Price per Unit must be ₱0.00 or more with at most two decimals (e.g. 450.00).';
                else $c['price'] = Money::cents($priceText);
            }
            $c['fee'] = null;
            if (trim($in['fee']) !== '') { $c['fee'] = $labels[mb_strtolower(trim($in['fee']))] ?? null; if (!$c['fee']) $e[] = 'Fee Label "' . $in['fee'] . '" is not an active fee (Assessment → Labels).'; }
            elseif ($existing && ($own = Db::col('SELECT label_id FROM sis_subject_fees WHERE subject_doc_id = ? AND assessment_name_id IS NULL', [$existing['id']])) && count($own) === 1) $c['fee'] = (int) $own[0]; // its one saved price
            elseif ($c['price'] !== null || !$existing) $c['fee'] = $defaultLabel;
            if (($c['price'] !== null || trim($in['templates']) !== '') && !$c['fee']) $e[] = 'No fee to put the price on: add a fee label in Assessment → Labels.';
            $c['templates'] = null;
            if (trim($in['templates']) !== '') {
                $c['templates'] = [];
                foreach (array_filter(array_map('trim', preg_split('/[,;]/', $in['templates']))) as $tn) {
                    if (isset($templates[mb_strtolower($tn)])) $c['templates'][] = $templates[mb_strtolower($tn)]; else $e[] = 'Fee template "' . $tn . '" does not exist.';
                }
            }
            $c['teacher'] = null;
            $tt = trim($in['teacher']);
            if ($tt !== '' && strcasecmp($tt, 'Professor X') !== 0 && strcasecmp($tt, 'PROF-X') !== 0) {
                if (isset($teacherById[strtoupper($tt)])) $c['teacher'] = $teacherById[strtoupper($tt)];
                elseif (count($teacherByName[mb_strtolower($tt)] ?? []) === 1) $c['teacher'] = $teacherByName[mb_strtolower($tt)][0];
                elseif (count($teacherByName[mb_strtolower($tt)] ?? []) > 1) $e[] = 'Two or more teachers are named "' . $tt . '": use the Teacher ID.';
                else $e[] = 'Teacher "' . $tt . '" was not found (Records → Teachers). Use the Teacher ID or the exact name.';
            } elseif ($tt !== '') $c['teacher'] = CurriculumAssignments::PLACEHOLDER;
            // Curriculum: an existing one, or Course / Program + Year Level + Semester
            $target = null;
            $level = self::level($in['level']); $term = self::term($in['term']); $program = strtoupper(trim(preg_replace('/\s+/', ' ', $in['program'])));
            if ($level === null) $e[] = 'Year Level must be one of ' . implode(', ', YEAR_LEVELS) . '.';
            if ($term === null) $e[] = 'Semester must be 1st Semester, 2nd Semester, 3rd Semester or Summer.';
            if (trim($in['curriculum']) !== '') {
                $key = mb_strtolower(preg_replace('/\s*[·|]\s*/u', ' · ', trim($in['curriculum'])));
                $target = $curricula[$key] ?? null;
                if (!$target) $e[] = 'Curriculum "' . $in['curriculum'] . '" was not found. Write it as in Curriculum (e.g. BSIT · 1st Year · 1st Semester) or fill Course / Program, Year Level and Semester.';
                elseif ($target[2] === '') { $e[] = 'Curriculum "' . $in['curriculum'] . '" has no semester. Choose a semester for it in Curriculum first.'; $target = null; }
                elseif (($program !== '' && $program !== $target[0]) || ($level && $level !== $target[1]) || ($term && $term !== $target[2])) $e[] = 'Curriculum and Course / Program, Year Level, Semester disagree. Fill only one of them.';
            } elseif ($program !== '' || $level || $term) {
                if ($program === '' || !$level || !$term) $e[] = 'To add the subject to a curriculum, fill Course / Program, Year Level and Semester (all three), or the Curriculum column.';
                elseif (mb_strlen($program) > 40) $e[] = 'Course / Program is longer than 40 characters.';
                else $target = [$program, $level, $term];
            }
            if ($c['teacher'] !== null && !$target) $w[] = 'Teacher is used only with a curriculum: it was ignored.';
            $links = ['classes' => 0, 'registrations' => 0];
            if ($existing) {
                $links['classes'] = (int) Db::val("SELECT COUNT(*) FROM docs WHERE col = 'gradingAssignments' AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.subjectId')) = ?", [$existing['id']]);
                $links['registrations'] = (int) Db::val('SELECT COUNT(DISTINCT registration_id) FROM sis_registration_subjects WHERE subject_doc_id = ?', [$existing['id']]);
                if ($c['name'] !== '' && $c['name'] !== (string) $existing['subjectName']) $w[] = 'Name differs from the saved subject (' . $existing['subjectName'] . ').';
                if ($c['units'] !== '' && (float) $c['units'] !== (float) $existing['units']) $w[] = 'Units differ from the saved subject (' . (float) $existing['units'] . '). Confirmed assessments keep their units.';
            }
            $status = $e ? 'ERROR' : ($existing ? 'EXISTING' : 'NEW');
            $out[] = $r + ['clean' => $c, 'errors' => $e, 'warnings' => $w, 'status' => $status, 'subject_id' => $existing['id'] ?? null, 'target' => $target, 'links' => $links,
                'actions' => match ($status) { 'NEW' => ['ADD', 'SKIP'], 'EXISTING' => ['UPDATE', 'REPLACE', 'SKIP'], default => [] },
                'action' => match ($status) { 'NEW' => 'ADD', 'EXISTING' => 'SKIP', default => 'SKIP' }];
        }
        // Duplicate Subject Codes inside the file: same subject details everywhere, and one row per curriculum
        $groups = [];
        foreach ($out as $i => $r) if ($r['clean']['code'] !== '') $groups[$r['clean']['code']][] = $i;
        foreach ($groups as $code => $idx) {
            if (count($idx) < 2) continue;
            $rowsText = implode(', ', array_map(fn($i) => (string) $out[$i]['row'], $idx));
            foreach (['name' => 'Subject Name', 'units' => 'Subject Units', 'type' => 'Subject Type', 'price' => 'Price per Unit', 'fee' => 'Fee Label'] as $k => $label) {
                $vals = array_unique(array_filter(array_map(fn($i) => $out[$i]['clean'][$k] === null ? '' : (string) $out[$i]['clean'][$k], $idx), fn($v) => $v !== ''));
                if (count($vals) > 1) foreach ($idx as $i) { $out[$i]['errors'][] = "Duplicate Subject Code $code (rows $rowsText) with different $label."; $out[$i]['status'] = 'ERROR'; $out[$i]['actions'] = []; }
            }
            $seen = [];
            foreach ($idx as $i) {
                $t = $out[$i]['target'] ? implode('|', $out[$i]['target']) : '-';
                if (isset($seen[$t])) { $out[$i]['errors'][] = "Duplicate row: Subject Code $code is already on row {$out[$seen[$t]]['row']}" . ($t === '-' ? '.' : ' for the same curriculum.'); $out[$i]['status'] = 'ERROR'; $out[$i]['actions'] = []; }
                else $seen[$t] = $i;
            }
        }
        return $out;
    }

    public static function counts(array $rows): array
    {
        return ['total' => count($rows), 'new' => count(array_filter($rows, fn($r) => $r['status'] === 'NEW')), 'existing' => count(array_filter($rows, fn($r) => $r['status'] === 'EXISTING')),
            'errors' => count(array_filter($rows, fn($r) => $r['status'] === 'ERROR')), 'warnings' => count(array_filter($rows, fn($r) => $r['warnings']))];
    }

    // ------------------------------------------------------------ batches

    public static function upload(string $path, string $fileName): int
    {
        self::requireAccess();
        $rows = self::check(self::read($path, $fileName));
        $id = (int) Db::insert('sis_import_all_batches', ['file_name' => mb_substr($fileName, 0, 200), 'rows_json' => json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'summary_json' => json_encode(self::counts($rows), JSON_THROW_ON_ERROR), 'created_by' => Auth::id()]);
        Audit::log('import_all_uploaded', 'import_all', $id, ['file' => $fileName] + self::counts($rows));
        return $id;
    }

    public static function batch(int $id): array
    {
        self::requireAccess();
        $b = Db::one('SELECT b.*, u.full_name AS created_by_name, a.full_name AS applied_by_name FROM sis_import_all_batches b LEFT JOIN sis_users u ON u.id = b.created_by LEFT JOIN sis_users a ON a.id = b.applied_by WHERE b.id = ?', [$id]);
        if (!$b) throw new AppError('Import batch not found.');
        $b['rows'] = json_decode((string) $b['rows_json'], true) ?: [];
        $b['result'] = $b['result_json'] ? json_decode((string) $b['result_json'], true) : null;
        $b['summary'] = $b['summary_json'] ? json_decode((string) $b['summary_json'], true) : [];
        return $b;
    }

    public static function history(int $limit = 50): array
    {
        self::requireAccess();
        return Db::all('SELECT b.id, b.file_name, b.status, b.summary_json, b.result_json, b.created_at, b.applied_at, u.full_name AS created_by_name, a.full_name AS applied_by_name
                          FROM sis_import_all_batches b LEFT JOIN sis_users u ON u.id = b.created_by LEFT JOIN sis_users a ON a.id = b.applied_by ORDER BY b.id DESC LIMIT ' . max(1, min(200, $limit)));
    }

    public static function discard(int $id): void
    {
        self::requireAccess();
        Db::tx(function () use ($id) {
            $b = Db::one('SELECT status FROM sis_import_all_batches WHERE id = ? FOR UPDATE', [$id]);
            if (!$b || $b['status'] !== 'PREVIEW') throw new AppError('Only a preview that was not imported can be discarded.');
            Db::q("UPDATE sis_import_all_batches SET status = 'DISCARDED' WHERE id = ?", [$id]);
            Audit::log('import_all_discarded', 'import_all', $id);
        });
    }

    /** What the chosen rows would do: ['selected', 'add', 'skip', 'update', 'replace'] (unticked rows count as skip). */
    public static function plan(array $rows, array $choice): array
    {
        $n = ['total' => count($rows), 'selected' => 0, 'add' => 0, 'skip' => 0, 'update' => 0, 'replace' => 0];
        foreach ($rows as $i => $r) {
            $a = $choice[$i] ?? null;
            if ($a === null || !in_array($a, $r['actions'], true) || $a === 'SKIP') { $n['skip']++; if ($a === 'SKIP') $n['selected']++; continue; }
            $n['selected']++; $n[strtolower($a)]++;
        }
        return $n;
    }

    /**
     * Applies the ticked rows: $choice = [row index => ADD|SKIP|UPDATE|REPLACE] (rows not in $choice are skipped).
     * Every row is checked again against the live records first. Subjects and prices are saved row by row (a failed
     * row does not stop the others); then each curriculum is saved once with its new subjects and teachers.
     */
    public static function apply(int $id, array $choice, bool $reviewed, string $replaceWord = ''): array
    {
        self::requireAccess();
        if (!$reviewed) throw new AppError('Tick the confirmation that you reviewed the preview and totals.');
        return CurriculumAssignments::locked(Db::pdo(), function () use ($id, $choice, $replaceWord) {
            $b = Db::one('SELECT * FROM sis_import_all_batches WHERE id = ? FOR UPDATE', [$id]);
            if (!$b) throw new AppError('Import batch not found.');
            if ($b['status'] !== 'PREVIEW') throw new AppError('This batch was already ' . ($b['status'] === 'APPLIED' ? 'imported' : 'discarded') . '. Upload the file again to import more.');
            $stored = json_decode((string) $b['rows_json'], true) ?: [];
            $choice = array_filter(array_map(fn($a) => strtoupper((string) $a), $choice), fn($a) => isset(self::ACTIONS[$a]));
            if (in_array('REPLACE', $choice, true) && strtoupper(trim($replaceWord)) !== 'REPLACE') throw new AppError('Replace changes saved subjects: type REPLACE to confirm.');
            // Mark the batch first, so a repeated click cannot import it twice
            Db::q("UPDATE sis_import_all_batches SET status = 'APPLIED', applied_by = ?, applied_at = NOW() WHERE id = ? AND status = 'PREVIEW'", [Auth::id(), $id]);
            $fresh = self::check(array_map(fn($r) => ['row' => $r['row'], 'input' => $r['input']], $stored));
            $results = []; $byCurriculum = [];
            foreach ($fresh as $i => $r) {
                $a = $choice[$i] ?? null;
                $res = ['row' => $r['row'], 'code' => $r['clean']['code'], 'action' => $a ?? 'SKIP', 'result' => 'SKIPPED', 'message' => $a === null ? 'Not ticked.' : ''];
                if ($a === null || $a === 'SKIP') { if ($a === 'SKIP') $res['message'] = 'Skipped as chosen.'; $results[$i] = $res; continue; }
                if (!in_array($a, $r['actions'], true)) {
                    $res['result'] = 'FAILED';
                    $res['message'] = $r['errors'] ? implode(' ', $r['errors']) : ($r['status'] === 'EXISTING' && $a === 'ADD' ? 'This subject code now exists (added after the preview). Upload again and choose Update or Replace.' : 'This row changed since the preview. Upload the file again.');
                    $results[$i] = $res; continue;
                }
                try {
                    $subjectId = Db::tx(fn() => self::applySubject($r, $a));
                    $res['result'] = ['ADD' => 'ADDED', 'UPDATE' => 'UPDATED', 'REPLACE' => 'REPLACED'][$a];
                    $res['message'] = $r['warnings'] ? implode(' ', $r['warnings']) : '';
                    if ($r['target']) $byCurriculum[implode('|', $r['target'])][] = [$i, $subjectId, $r, $a];
                } catch (AppError | Forbidden $e) { $res['result'] = 'FAILED'; $res['message'] = $e->getMessage(); }
                $results[$i] = $res;
            }
            foreach ($byCurriculum as $key => $items) {
                [$program, $level, $term] = explode('|', $key);
                try {
                    $cur = GS::curriculumFor($program, $level, $term);
                    $ids = array_values((array) ($cur['subjectIds'] ?? []));
                    $teachers = (array) ($cur['subjectTeachers'] ?? []);
                    foreach ($items as [$i, $sid, $r, $a]) {
                        if (!in_array($sid, $ids, true)) $ids[] = $sid;
                        $t = $r['clean']['teacher'];
                        if ($a === 'REPLACE') $teachers[$sid] = $t ?? CurriculumAssignments::PLACEHOLDER;
                        elseif ($t !== null) $teachers[$sid] = $t;
                        else $teachers[$sid] ??= CurriculumAssignments::PLACEHOLDER;
                    }
                    GS::saveCurriculum($program, $level, $term, $ids, $teachers);
                    $sync = GS::$lastCurriculumSync;
                    foreach ($items as [$i]) $results[$i]['message'] = trim($results[$i]['message'] . " Curriculum $program · $level · $term saved" . ($sync['created'] ? ' (' . $sync['created'] . ' class(es) created)' : '') . '.');
                } catch (AppError | Forbidden | RuntimeException $e) {
                    foreach ($items as [$i]) $results[$i]['message'] = trim($results[$i]['message'] . ' Subject saved, but the curriculum was not: ' . $e->getMessage());
                    foreach ($items as [$i]) $results[$i]['result'] = 'FAILED';
                }
            }
            ksort($results);
            $count = ['added' => 0, 'updated' => 0, 'replaced' => 0, 'skipped' => 0, 'failed' => 0];
            foreach ($results as $res) $count[match ($res['result']) { 'ADDED' => 'added', 'UPDATED' => 'updated', 'REPLACED' => 'replaced', 'FAILED' => 'failed', default => 'skipped' }]++;
            $count['successful'] = $count['added'] + $count['updated'] + $count['replaced'];
            Db::q('UPDATE sis_import_all_batches SET result_json = ? WHERE id = ?', [json_encode(['counts' => $count, 'rows' => array_values($results)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id]);
            Audit::log('import_all_applied', 'import_all', $id, $count + ['file' => $b['file_name']]);
            return $count;
        });
    }

    /** One row: the subject (and its price and fee templates). Returns the subject id. */
    private static function applySubject(array $r, string $action): string
    {
        $c = $r['clean'];
        $before = $r['subject_id'] ? GS::get('subjects', (string) $r['subject_id']) : null;
        if ($action === 'ADD') {
            $sid = GS::saveSubject(null, $c['code'], $c['name'], $c['units']);
        } else {
            if (!$before) throw new AppError('The subject no longer exists.');
            $name = $c['name'] !== '' ? $c['name'] : (string) $before['subjectName'];
            $units = $c['units'] !== '' ? $c['units'] : (string) (float) $before['units'];
            $sid = GS::saveSubject((string) $before['id'], $c['code'], $name, $units);
        }
        if ($action === 'REPLACE' || $c['type'] !== '') GS::setSubjectType($sid, $c['type']);
        if ($c['split'] !== null || $action === 'REPLACE') GS::setSubjectSplit($sid, $c['split']);
        // Price (Units × Price per Unit): a new subject gets its price row only when a price, fee or template is given
        $wantPrice = $action === 'REPLACE' || $c['price'] !== null || $c['templates'] !== null || ($action === 'ADD' && trim($r['input']['fee']) !== '');
        if ($wantPrice && $c['fee']) {
            $row = Db::one('SELECT * FROM sis_subject_fees WHERE subject_doc_id = ? AND label_id = ? AND assessment_name_id IS NULL', [$sid, $c['fee']]);
            $rate = $c['price'] !== null ? Money::dec($c['price']) : ($row ? (string) $row['rate'] : '0.00');
            $in = ['subject_doc_id' => $sid, 'label_id' => (string) $c['fee'], 'rate' => $action === 'REPLACE' && $c['price'] === null ? '0.00' : $rate] + ($row ? ['id' => (string) $row['id']] : []);
            if ($c['templates'] !== null || $action === 'REPLACE') {
                $ticks = $c['templates'] ?? [];
                if ($action === 'UPDATE' && $row) $ticks = array_values(array_unique(array_merge(array_map('intval', Db::col('SELECT assessment_name_id FROM sis_assessment_name_subject_fees WHERE subject_fee_id = ?', [$row['id']])), $ticks)));
                $in += ['templates_sent' => '1', 'templates' => array_map('strval', $ticks)];
            }
            SubjectFees::save($in);
        }
        return $sid;
    }

    /** Rows for the downloadable report: preview checks, or results after import. */
    public static function report(array $b): array
    {
        $head = array_merge(['Excel Row'], array_map(fn($f) => $f[0], self::FIELDS), ['Check', 'Errors', 'Warnings', 'Action', 'Result', 'Result details']);
        $res = [];
        foreach (($b['result']['rows'] ?? []) as $x) $res[$x['row']] = $x;
        $rows = [$head];
        foreach ($b['rows'] as $r) {
            $x = $res[$r['row']] ?? null;
            $rows[] = array_merge([(string) $r['row']], array_values($r['input']), [$r['status'], implode(' ', $r['errors']), implode(' ', $r['warnings']), $x['action'] ?? '', $x['result'] ?? '', $x['message'] ?? '']);
        }
        return $rows;
    }
}
