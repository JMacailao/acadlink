<?php
/**
 * SIS automated tests:  php tests/run.php
 * Builds a throwaway database (default "sis_test", override with SIS_TEST_DB) on the MySQL
 * server in api/config.php: the grading system's tables + the SIS tables. Never the live database.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/views/layout.php';
$cfg = sis_config();
$live = $cfg['db_name'];
$cfg['db_name'] = getenv('SIS_TEST_DB') ?: 'sis_test';
if ($cfg['db_name'] === $live) { fwrite(STDERR, "Refusing to run on the live database.\n"); exit(1); }
$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']), $cfg['db_user'], $cfg['db_pass']);
$server->exec("DROP DATABASE IF EXISTS `{$cfg['db_name']}`");
$server->exec("CREATE DATABASE `{$cfg['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
Db::connect($cfg);
$run = function (string $file) {
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) Db::pdo()->exec($stmt);
};
$run(dirname(SIS_ROOT) . '/api/install.sql');
$run(SIS_ROOT . '/database/schema.sql');
$run(SIS_ROOT . '/database/seed.sql');
$run(SIS_ROOT . '/database/seed.sql'); // safe to run twice
$run(SIS_ROOT . '/database/seed_accounting.sql');
$run(SIS_ROOT . '/database/seed_accounting.sql'); // safe to run twice
require __DIR__ . '/_legacy-payments.php'; // v56: these flows predate the payment-plan rule

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); $passed++; echo "  ✔ $name\n"; }
    catch (Throwable $e) { $failed++; echo "  ✘ $name\n      " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
    Auth::actAs(null);
}
function eq(mixed $exp, mixed $act, string $msg = ''): void { if ($exp !== $act) throw new RuntimeException("$msg expected " . var_export($exp, true) . ', got ' . var_export($act, true)); }
function ok(bool $c, string $msg = 'condition failed'): void { if (!$c) throw new RuntimeException($msg); }
function throws(callable $fn, string $class = AppError::class, string $contains = ''): void
{
    try { $fn(); } catch (Throwable $e) {
        if (!($e instanceof $class)) throw new RuntimeException("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        if ($contains !== '' && stripos($e->getMessage(), $contains) === false) throw new RuntimeException("message \"{$e->getMessage()}\" lacks \"$contains\"");
        return;
    }
    throw new RuntimeException("expected $class at line " . debug_backtrace()[0]["line"]);
}
$pw = password_hash('Password123', PASSWORD_DEFAULT);
foreach (['registrar' => 'REGISTRAR', 'admission' => 'ADMISSION', 'cashier' => 'CASHIER', 'cashier2' => 'CASHIER'] as $u => $r) {
    Db::insert('sis_users', ['username' => $u, 'full_name' => ucfirst($u), 'password_hash' => $pw, 'role' => $r]);
}
function as_user(string $u): int { $id = (int) Db::val('SELECT id FROM sis_users WHERE username = ?', [$u]); Auth::actAs($id); return $id; }
function doc(string $col, string $id): array { return GS::get($col, $id) ?? []; }
function idem(): string { return bin2hex(random_bytes(16)); }

echo "\nGrading-system records (same database)\n";
$S = [];
test('sections: validation, unique per school year, saved in the grading system format', function () use (&$S) {
    as_user('registrar');
    throws(fn() => GS::saveSection(null, '2026-2028', '1st Year', 'BSIT 1A'), AppError::class, 'consecutive');
    throws(fn() => GS::saveSection(null, '2026-2027', 'Grade 7', 'BSIT 1A'), AppError::class, 'year level');
    $S['1A'] = GS::saveSection(null, '2026-2027', '1st Year', 'bsit  1a');
    $d = doc('sections', $S['1A']);
    eq(['2026-2027', '1st Year', 'BSIT 1A'], [$d['schoolYear'], $d['yearLevel'], $d['sectionName']]);
    ok(isset(doc('sections', $S['1A'])['createdAt']['__ts']), 'grading timestamp format');
    throws(fn() => GS::saveSection(null, '2026-2027', '1st Year', 'BSIT 1A'), AppError::class, 'already exists');
    GS::saveSection(null, '2025-2026', '1st Year', 'BSIT 1A'); // same name, other school year: fine
    $S['2A'] = GS::saveSection(null, '2026-2027', '2nd Year', 'BSIT 2A');
    $S['old'] = GS::sections('2025-2026')[0]['id'];
    eq(20, strlen($S['1A']));
});
$SUB = [];
test('subjects, curriculum and teachers in the grading system', function () use (&$SUB) {
    as_user('registrar');
    throws(fn() => GS::saveSubject(null, 'IT101', 'Intro', '0'), AppError::class, 'Units');
    foreach ([['IT101', 'Intro to Computing', '3'], ['GE1', 'Understanding the Self', '3'], ['PE1', 'Fitness', '2'], ['IT201', 'Data Structures', '3']] as [$c, $n, $u]) $SUB[$c] = GS::saveSubject(null, $c, $n, $u);
    throws(fn() => GS::saveSubject(null, 'it101', 'Other', '3'), AppError::class, 'already used');
    eq(3, doc('subjects', $SUB['IT101'])['units'], 'units stored as a number');
    $cid = GS::saveCurriculum('bsit', '1st Year', '1st Semester', [$SUB['IT101'], $SUB['GE1'], $SUB['PE1'], 'not-a-subject']);
    eq('bsit-1st-year-1st-semester', $cid, 'same id rule as the grading system');
    eq([$SUB['IT101'], $SUB['GE1'], $SUB['PE1']], doc('curriculum', $cid)['subjectIds']);
    eq('BSIT', GS::programOf('BSIT 1A'));
    eq('BSCS', GS::programOf('BSCS-2B'));
    $t1 = GS::saveTeacher(null, '', 'Tomas Teacher');
    $t2 = GS::saveTeacher(null, '', 'Maria Santos');
    eq(['T001', 'T002'], [doc('teachers', $t1)['teacherId'], doc('teachers', $t2)['teacherId']]);
    throws(fn() => GS::saveTeacher($t2, 'T001', 'Maria Santos'), AppError::class, 'already used');
    eq('20260010', GS::nextNumber(['20260009', '20260002']));
    eq('S-0100', GS::nextNumber(['S-0099', 'S-0007']));
    eq('T010', GS::nextNumber(['T009', 'X']));
    eq('20260001', GS::nextNumber([], '2026-2027'));
});
test('renaming a section / teacher / subject updates the grading system\'s copies (students, classes, grades)', function () use (&$S, &$SUB) {
    as_user('registrar');
    $st = GS::saveStudent(null, ['studentName' => 'Lim, Dan', 'sectionId' => $S['1A']]);
    $tid = GS::teachers()[0]['id'];
    Db::q("INSERT INTO docs (col, id, data) VALUES ('gradingAssignments', 'asg1', ?), ('grades', 'g1', ?)", [
        json_encode(['sectionId' => $S['1A'], 'sectionName' => 'BSIT 1A', 'teacherDocId' => $tid, 'teacherName' => 'Tomas Teacher', 'subjectId' => $SUB['IT101'], 'subjectName' => 'Intro to Computing', 'studentIds' => [$st]]),
        json_encode(['sectionId' => $S['1A'], 'sectionName' => 'BSIT 1A', 'teacherDocId' => $tid, 'teacherName' => 'Tomas Teacher', 'subjectId' => $SUB['IT101'], 'subjectName' => 'Intro to Computing', 'studentId' => $st, 'studentName' => 'Lim, Dan', 'studentNumber' => doc('students', $st)['studentId']])]);
    GS::saveSection($S['1A'], '2026-2027', '1st Year', 'BSIT 1-ALPHA');
    eq('BSIT 1-ALPHA', doc('students', $st)['sectionName']);
    eq('BSIT 1-ALPHA', doc('gradingAssignments', 'asg1')['sectionName']);
    eq('BSIT 1-ALPHA', doc('grades', 'g1')['sectionName']);
    GS::saveSection($S['1A'], '2026-2027', '1st Year', 'BSIT 1A');
    GS::saveTeacher($tid, '', 'Tomas T. Teacher');
    eq('Tomas T. Teacher', doc('grades', 'g1')['teacherName']);
    GS::saveSubject($SUB['IT101'], 'IT101', 'Introduction to Computing', '3');
    eq('Introduction to Computing', doc('gradingAssignments', 'asg1')['subjectName']);
    GS::saveStudent($st, ['studentId' => doc('students', $st)['studentId'], 'studentName' => 'Lim, Daniel', 'sectionId' => $S['1A']]);
    eq('Lim, Daniel', doc('grades', 'g1')['studentName']);
    throws(fn() => GS::saveStudent(null, ['studentId' => doc('students', $st)['studentId'], 'studentName' => 'Other', 'sectionId' => $S['1A']]), AppError::class, 'already used');
    throws(fn() => GS::saveStudent(null, ['studentName' => 'X', 'email' => 'bad', 'sectionId' => $S['1A']]), AppError::class, 'email');
});

echo "\nAssessment: categories, labels, names\n";
$L = []; $N = [];
test('categories and labels', function () use (&$L) {
    as_user('registrar');
    eq(4, count(Assessment::categories()), 'seeded categories');
    throws(fn() => Assessment::saveCategory(null, ['name' => 'Tuition', 'is_active' => 1]), AppError::class, 'already used');
    $lab = Assessment::saveCategory(null, ['name' => 'Laboratory Fees 2', 'sort_order' => 35, 'is_active' => 1]);
    $cat = fn($n) => (int) Db::val('SELECT id FROM sis_fee_categories WHERE name = ?', [$n]);
    $L['tuition'] = Assessment::saveLabel(null, ['category_id' => $cat('Tuition'), 'name' => 'Tuition Fee', 'charge_type' => 'PER_UNIT', 'default_amount' => '500', 'is_active' => 1]);
    $L['reg'] = Assessment::saveLabel(null, ['category_id' => $cat('Miscellaneous'), 'name' => 'Registration Fee', 'charge_type' => 'FIXED', 'default_amount' => '500', 'is_active' => 1]);
    $L['lib'] = Assessment::saveLabel(null, ['category_id' => $cat('Miscellaneous'), 'name' => 'Library Fee', 'charge_type' => 'FIXED', 'default_amount' => '300', 'is_active' => 1]);
    $L['lab'] = Assessment::saveLabel(null, ['category_id' => $lab, 'name' => 'Lab per subject', 'charge_type' => 'PER_SUBJECT', 'default_amount' => '100', 'is_active' => 1]);
    throws(fn() => Assessment::saveLabel(null, ['category_id' => $cat('Tuition'), 'name' => 'Bad', 'charge_type' => 'FIXED', 'default_amount' => '-5', 'is_active' => 1]), AppError::class);
    throws(fn() => Assessment::saveLabel(null, ['category_id' => 999, 'name' => 'Bad', 'charge_type' => 'FIXED', 'is_active' => 1]), AppError::class, 'Category');
});
test('names: fee lines, computation per unit / per subject / fixed, best match, copy', function () use (&$L, &$N) {
    as_user('registrar');
    $N['bsit1'] = Assessment::saveName(null, ['name' => 'BSIT 1st Year 1st Sem', 'program' => 'bsit', 'year_level' => '1st Year', 'term' => '1st Semester', 'is_active' => 1]);
    $N['any'] = Assessment::saveName(null, ['name' => 'General', 'is_active' => 1]);
    throws(fn() => Assessment::saveName(null, ['name' => 'X', 'year_level' => 'Grade 1', 'is_active' => 1]), AppError::class, 'year level');
    foreach (['tuition' => 50000, 'reg' => 50000, 'lib' => 30000, 'lab' => 10000] as $k => $c) {
        Assessment::setItem($N['bsit1'], $L[$k], (string) Db::val('SELECT charge_type FROM sis_fee_labels WHERE id = ?', [$L[$k]]), $c);
    }
    Assessment::setItem($N['bsit1'], $L['lib'], 'FIXED', 35000); // update, not duplicate
    eq(4, count(Assessment::items($N['bsit1'])));
    $lines = Assessment::compute($N['bsit1'], 8.0, 3);
    $by = array_column($lines, 'amount_cents', 'label_name');
    ksort($by);
    eq(['Lab per subject' => 30000, 'Library Fee' => 35000, 'Registration Fee' => 50000, 'Tuition Fee' => 400000], $by);
    eq(Money::times(50000, '7.5'), Assessment::compute($N['bsit1'], 7.5, 3)[0]['amount_cents'] ?? null, 'half units');
    eq($N['bsit1'], (int) Assessment::match('BSIT', '1st Year', '1st Semester', '2026-2027')['id'], 'most specific');
    eq($N['any'], (int) Assessment::match('BSCS', '1st Year', '1st Semester', '2026-2027')['id'], 'fallback to the general one');
    $c = Assessment::copyName($N['bsit1'], 'BSIT copy');
    eq(4, count(Assessment::items($c)));
    throws(fn() => Assessment::copyName($N['bsit1'], 'BSIT copy'), AppError::class, 'already used');
    Db::q('UPDATE sis_assessment_names SET is_active = 0 WHERE id = ?', [$c]);
});

echo "\nTransaction: Admission → Registration → Cashier\n";
$A = 0; $R = 0;
test('admission: validation, no duplicate applicant, admit gives the next Student ID', function () use (&$A) {
    as_user('admission');
    throws(fn() => Admission::validate(['applicant_type' => 'TRANSFEREE', 'address' => '12 Rizal St., Brgy. Centro, Sample City', 'last_name' => 'A', 'first_name' => 'B', 'year_level' => '1st Year', 'school_year' => '2026-2027']), AppError::class, 'previous school');
    $d = Admission::validate(['applicant_type' => 'NEW', 'address' => '12 Rizal St., Brgy. Centro, Sample City', 'last_name' => 'Cruz', 'first_name' => 'Ben', 'middle_name' => 'Lopez', 'birth_date' => '2008-01-02',
        'program' => 'bsit', 'year_level' => '1st Year', 'school_year' => '2026-2027', 'email' => 'ben@example.com']);
    $A = Admission::create($d);
    throws(fn() => Admission::create($d), AppError::class, 'already recorded');
    throws(fn() => Admission::reject($A, ''), AppError::class, 'reason');
    $existing = Db::col("SELECT f_studentId FROM docs WHERE col = 'students'");
    $no = Admission::admit($A);
    ok(!in_array($no, $existing, true), 'new number');
    eq(GS::nextNumber($existing, '2026-2027'), $no, 'continues the grading system numbering');
    throws(fn() => Admission::admit($A), AppError::class);
    eq('Cruz, Ben L.', Admission::gradingName(Db::one('SELECT * FROM sis_admissions WHERE id = ?', [$A])));
});
test('registration of an admitted student: added to the grading system, subjects from the curriculum, fees computed', function () use (&$A, &$R, &$S, &$N) {
    as_user('registrar');
    [$R, $notes] = Registration::create($A, null, $S['1A'], '1st Semester', null);
    $r = Registration::get($R);
    $doc = GS::studentByNumber($r['student_number']);
    ok($doc !== null, 'student created in the grading system');
    eq(['Cruz, Ben L.', 'BSIT 1A', '2026-2027', '1st Year'], [$doc['studentName'], $doc['sectionName'], $doc['schoolYear'], $doc['yearLevel']]);
    eq($N['bsit1'], (int) $r['assessment_name_id'], 'matching assessment name chosen automatically');
    eq('8.0', (string) $r['total_units'], 'IT101 3 + GE1 3 + PE1 2');
    eq('5150.00', $r['total_amount'], '8 × 500 + 500 + 350 + 3 × 100');
    eq('UNPAID', Registration::payStatus($r));
    throws(fn() => Registration::create(null, $r['student_number'], $S['1A'], '1st Semester', null), AppError::class, 'already registered');
    eq(1, (int) Db::val("SELECT COUNT(*) FROM docs WHERE col = 'students' AND f_studentId = ?", [$r['student_number']]), 'still one grading record');
});
/** What the grading system's Final grades page saves when the registrar clicks Tag complete. */
function tag_complete(string $docId, string $sy, string $key): void
{
    Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?)', ['gradeCompletions', "{$docId}__{$sy}__$key",
        json_encode(['studentId' => $docId, 'schoolYear' => $sy, 'term' => '', 'termKey' => $key, 'subjects' => []])]);
}
test('registration needs the previous term\'s final grades tagged complete (classes in the grading system or SIS registrations)', function () use (&$S) {
    as_user('registrar');
    $id = GS::saveStudent(null, ['studentName' => 'Lim, Joy', 'sectionId' => $S['old']]);
    $num = doc('students', $id)['studentId'];
    eq(null, GS::previousTerm($id, $num, '2026-2027', '1st Semester'), 'nothing on record yet');
    // A class in the grading system in 2025-2026 2nd Semester
    Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?)', ['gradingAssignments', 'asgJoy', json_encode(['schoolYear' => '2025-2026', 'term' => '2nd Semester', 'studentIds' => [$id]])]);
    eq(['schoolYear' => '2025-2026', 'term' => '2nd Semester'], GS::previousTerm($id, $num, '2026-2027', '1st Semester'));
    throws(fn() => Registration::create(null, $num, $S['1A'], '1st Semester', null), AppError::class, 'not tagged complete');
    eq('2025-2026', doc('students', $id)['schoolYear'], 'refused before anything changed');
    tag_complete($id, '2025-2026', 'year'); // the whole school year counts too
    ok(GS::isComplete($id, '2025-2026', '2nd Semester'));
    [$rid] = Registration::create(null, $num, $S['1A'], '1st Semester', null);
    ok($rid > 0);
    eq('1st-semester', GS::termKey('1st Semester'));
    eq('noterm', GS::termKey(''));
});
test('continuing student: new school year moves the grading record (like Promote); same year keeps it', function () use (&$S) {
    as_user('registrar');
    $old = GS::saveStudent(null, ['studentName' => 'Reyes, Ana', 'sectionId' => $S['old']]);
    $num = doc('students', $old)['studentId'];
    [$rid, $notes] = Registration::create(null, $num, $S['2A'], '1st Semester', null);
    eq(['2026-2027', 'BSIT 2A', '2nd Year'], [doc('students', $old)['schoolYear'], doc('students', $old)['sectionName'], doc('students', $old)['yearLevel']]);
    ok(str_contains(implode(' ', $notes), 'moved'), json_encode($notes));
    ok(str_contains(implode(' ', $notes), 'No assessment name') === false, 'general name matched');
    throws(fn() => Registration::create(null, $num, $S['1A'], '2nd Semester', null), AppError::class, '2026-2027 1st Semester are not tagged complete');
    tag_complete($old, '2026-2027', '1st-semester');
    [$rid2, $notes2] = Registration::create(null, $num, $S['1A'], '2nd Semester', null);
    eq('BSIT 2A', doc('students', $old)['sectionName'], 'same school year: grading class not moved');
    ok(str_contains(implode(' ', $notes2), 'grading system still has'), json_encode($notes2));
    throws(fn() => Registration::create(null, 'NOPE', $S['1A'], '1st Semester', null), AppError::class, 'No student');
    throws(fn() => Registration::create(null, $num, $S['1A'], 'Quarter 1', null), AppError::class, 'term');
});
test('adjustments, subject changes and recomputation; total never negative', function () use (&$R, &$N) {
    as_user('registrar');
    Registration::addAdjustment($R, 'Sibling discount', -50000);
    eq('4650.00', Registration::get($R)['total_amount']);
    throws(fn() => Registration::addAdjustment($R, 'Huge discount', -1000000), AppError::class, 'negative');
    $subs = Db::col('SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ?', [$R]);
    $two = Db::col("SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ? AND subject_code IN ('IT101','GE1')", [$R]);
    Registration::update($R, $N['bsit1'], $two);
    eq('6.0', (string) Registration::get($R)['total_units']);
    eq('3550.00', Registration::get($R)['total_amount'], 'recomputed, the adjustment kept: 6×500 + 850 + 2×100 − 500');
    Registration::update($R, $N['bsit1'], $subs);
    eq('4650.00', Registration::get($R)['total_amount']);
});
test('fee labels chosen per student: add, change the amount, take off, charge again', function () use (&$R, &$L) {
    as_user('registrar');
    $misc = (int) Db::val("SELECT id FROM sis_fee_categories WHERE name = 'Miscellaneous'");
    $id = Assessment::saveLabel(null, ['category_id' => $misc, 'name' => 'ID Card', 'charge_type' => 'FIXED', 'default_amount' => '150', 'is_active' => 1]);
    $total = fn() => Registration::get($R)['total_amount'];
    Registration::chargeLabel($R, $id, null);
    eq('4800.00', $total(), 'ID card at its default ₱150');
    Registration::chargeLabel($R, $L['lib'], 20000);
    eq('4650.00', $total(), 'library fee ₱350 → ₱200 for this student only');
    eq(1, (int) Db::val("SELECT COUNT(*) FROM sis_registration_items WHERE registration_id = ? AND label_name = 'Library Fee'", [$R]), 'replaced, not added twice');
    Registration::removeLabel($R, $L['lab']);
    eq('4350.00', $total(), 'lab fee (3 × ₱100) taken off');
    eq('EXCLUDE', Registration::chosenLabels($R)[$L['lab']]['mode']);
    Registration::resetLabel($R, $L['lab']);
    Registration::resetLabel($R, $L['lib']);
    Registration::removeLabel($R, $id);
    eq('4650.00', $total(), 'back to the template plus the adjustment');
    eq([], Registration::chosenLabels($R));
    Registration::update($R, (int) Registration::get($R)['assessment_name_id'], Db::col('SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ?', [$R]));
    eq('4650.00', $total(), 'recompute keeps it');
    // fees taken off by hand come back when the assessment name is chosen again; added fees stay
    $subs = Db::col('SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ?', [$R]);
    $nm = (int) Registration::get($R)['assessment_name_id'];
    Registration::chargeLabel($R, $id, null);
    Registration::removeLabel($R, $L['lab']);
    Registration::removeLabel($R, $L['lib']);
    eq('4150.00', $total(), 'lab and library taken off, ID card added');
    Registration::update($R, $nm, $subs);
    eq('4150.00', $total(), 'saving subjects keeps the fees taken off');
    Registration::update($R, $nm, $subs, true);
    eq('4800.00', $total(), 'assessment name tagged again: all its fees are back, the added ID card stays');
    eq([$id], array_keys(Registration::chosenLabels($R)));
    Registration::removeLabel($R, $L['lab']);
    Registration::update($R, $nm, $subs, true);
    eq('4800.00', $total(), 'same name chosen again: the fee taken off is back');
    Registration::removeLabel($R, $id);
    eq('4650.00', $total());
    $multiA = Assessment::saveLabel(null, ['category_id' => $misc, 'name' => 'Multi fee A', 'charge_type' => 'FIXED', 'default_amount' => '100', 'is_active' => 1]);
    $multiB = Assessment::saveLabel(null, ['category_id' => $misc, 'name' => 'Multi fee B', 'charge_type' => 'FIXED', 'default_amount' => '250', 'is_active' => 1]);
    Registration::chargeLabels($R, [$multiA => null, $multiB => 30000]);
    eq('5050.00', $total(), 'both selected labels are included with their chosen rates');
    $multiChoices = Registration::chosenLabels($R);
    eq(['ADD', 'ADD'], [$multiChoices[$multiA]['mode'], $multiChoices[$multiB]['mode']]);
    eq(null, $multiChoices[$multiA]['unit_amount'], 'blank rate keeps the label default');
    eq('300.00', $multiChoices[$multiB]['unit_amount'], 'custom rate is saved for this student');
    throws(fn() => Registration::chargeLabels($R, [$multiA => null, 99999 => 100]), AppError::class, 'no longer active');
    eq('5050.00', $total(), 'unavailable label rolls back the entire multi-add');
    throws(fn() => Registration::chargeLabels($R, []), AppError::class, 'Select at least one');
    Registration::removeLabel($R, $multiA);
    Registration::removeLabel($R, $multiB);
    eq('4650.00', $total(), 'removing the multi-added labels restores the original assessment');
    throws(fn() => Registration::chargeLabel($R, 99999, null), AppError::class, 'Choose a fee label');
    throws(fn() => Registration::chargeLabel($R, $id, -100), AppError::class, 'negative');
    as_user('cashier');
    throws(fn() => Registration::chargeLabel($R, $id, null), Forbidden::class);
    throws(fn() => Registration::chargeLabels($R, [$multiA => null]), Forbidden::class);
});
test('remove a fee, void payment, restore charges, and void later payments without balance drift', function () use (&$S, &$L) {
    as_user('registrar');
    $sid = GS::saveStudent(null, ['studentName' => 'Repeat, Riley', 'sectionId' => $S['1A']]);
    [$rid] = Registration::create(null, doc('students', $sid)['studentId'], $S['1A'], '1st Semester', null);
    $total = fn() => Money::cents(Registration::get($rid)['total_amount']);
    $lab = (int) $L['lab'];
    $library = (int) $L['lib'];
    eq(515000, $total(), 'starting assessment');

    Registration::removeLabel($rid, $lab);
    eq(485000, $total(), 'template fee removed');
    as_user('cashier');
    $first = Cashier::pay($rid, 480000, 'CASH', null, idem(), 'before fee correction');
    as_user('registrar');
    throws(fn() => Registration::removeLabel($rid, $library), AppError::class, 'already paid');
    eq(485000, $total(), 'failed fee removal leaves saved assessment unchanged');

    as_user('cashier');
    Cashier::void((int) $first['id'], 'wrong assessment');
    eq(['0.00', 485000], [Registration::get($rid)['amount_paid'], Registration::balance(Registration::get($rid))], 'void restores the current balance');
    as_user('registrar');
    Registration::removeLabel($rid, $library);
    eq(450000, $total(), 'second fee removed after void');
    Registration::resetLabel($rid, $library);
    Registration::resetLabel($rid, $lab);
    eq(515000, $total(), 'both template fees charged again once');

    as_user('cashier');
    $second = Cashier::pay($rid, 120000, 'CASH', null, idem(), 'payment after fee restored');
    Cashier::void((int) $second['id'], 'wrong amount');
    $third = Cashier::pay($rid, 250000, 'CASH', null, idem(), 'replacement payment');
    Cashier::void((int) $third['id'], 'replacement also incorrect');
    $r = Registration::get($rid);
    eq(['0.00', 515000, 0], [$r['amount_paid'], Registration::balance($r), (int) Db::val("SELECT COUNT(*) FROM sis_payments WHERE registration_id = ? AND status = 'POSTED'", [$rid])], 'repeated voids do not drift the balance');
    eq(3, (int) Db::val("SELECT COUNT(*) FROM sis_payments WHERE registration_id = ? AND status = 'VOIDED'", [$rid]), 'all three voided receipts remain in the audit trail');
    as_user('cashier');
    throws(fn() => Cashier::void((int) $third['id'], 'again'), AppError::class, 'already voided');
    as_user('registrar');
    eq($total(), Registration::build($r)['total_cents'], 'saved fee lines still match the rebuilt assessment');
});
test('cashier: partial payment, overpayment refused, reference required, double submit recorded once', function () use (&$R) {
    as_user('cashier');
    throws(fn() => Cashier::pay($R, 100, 'GCASH', null, idem(), null), AppError::class, 'reference');
    throws(fn() => Cashier::pay($R, 465001, 'CASH', null, idem(), null), AppError::class, 'more than the balance');
    throws(fn() => Cashier::pay($R, 100, 'CASH', null, 'bad', null), AppError::class, 'expired');
    $k = idem();
    $p1 = Cashier::pay($R, 200000, 'CASH', null, $k, 'Down payment');
    $p2 = Cashier::pay($R, 200000, 'CASH', null, $k, 'Down payment');
    eq(true, $p2['duplicate']);
    eq($p1['receipt_no'], $p2['receipt_no']);
    eq(1, (int) Db::val('SELECT COUNT(*) FROM sis_payments WHERE idempotency_key = ?', [$k]));
    ok((bool) preg_match('/^OR-\d{7}$/', $p1['receipt_no']));
    $r = Registration::get($R);
    eq(['2000.00', 'PARTIAL', 265000], [$r['amount_paid'], Registration::payStatus($r), Registration::balance($r)]);
    as_user('registrar');
    throws(fn() => Registration::addAdjustment($R, 'Big discount', -300000), AppError::class, 'already paid');
    throws(fn() => Registration::cancel($R, 'x'), AppError::class, 'void');
    as_user('cashier');
    Cashier::pay($R, 265000, 'BANK', 'BDO-1', idem(), null);
    eq('PAID', Registration::payStatus(Registration::get($R)));
    throws(fn() => Cashier::pay($R, 1, 'CASH', null, idem(), null), AppError::class, 'balance');
});
test('void restores the balance and keeps the record; cancelled registration can be redone', function () use (&$R, &$S) {
    as_user('cashier');
    $pid = (int) Db::val("SELECT id FROM sis_payments WHERE registration_id = ? AND method = 'BANK'", [$R]);
    throws(fn() => Cashier::void($pid, ''), AppError::class, 'reason');
    Cashier::void($pid, 'Bank transfer bounced');
    throws(fn() => Cashier::void($pid, 'again'), AppError::class);
    eq('PARTIAL', Registration::payStatus(Registration::get($R)));
    eq('VOIDED', Db::val('SELECT status FROM sis_payments WHERE id = ?', [$pid]));
    as_user('registrar');
    $num = GS::saveStudent(null, ['studentName' => 'Go, Lea', 'sectionId' => $S['1A']]);
    [$x] = Registration::create(null, doc('students', $num)['studentId'], $S['1A'], 'Summer', null);
    Registration::cancel($x, 'Wrong term');
    [$y] = Registration::create(null, doc('students', $num)['studentId'], $S['1A'], 'Summer', null);
    ok($y > $x);
});

test('final grades tagged complete in the grading system show in the SIS, newest first; renaming updates them', function () use (&$S) {
    as_user('registrar');
    $docId = GS::saveStudent(null, ['studentName' => 'Tagged, Tess', 'sectionId' => (string) Db::val("SELECT id FROM docs WHERE col = 'sections' LIMIT 1")]);
    $put = fn(string $sy, string $term, string $key, float $gwa) => Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?)', ['gradeCompletions', "{$docId}__{$sy}__$key",
        json_encode(['studentId' => $docId, 'studentName' => 'Old', 'schoolYear' => $sy, 'term' => $term, 'termKey' => $key, 'gwa' => $gwa, 'remarks' => 'Passed',
            'subjects' => [['subjectCode' => 'IT101', 'units' => 3, 'finalGrade' => $gwa, 'remarks' => 'Passed']], 'completedByName' => 'Rita', 'completedAt' => ['__ts' => '2026-01-10T00:00:00.000Z']])]);
    $put('2025-2026', '1st Semester', '1st-semester', 1.5);
    $put('2025-2026', '2nd Semester', '2nd-semester', 1.75);
    $put('2024-2025', '', 'year', 2.0);
    $list = GS::completions($docId);
    eq(['2025-2026 · 2nd Semester', '2025-2026 · 1st Semester', '2024-2025 · Whole school year'], array_map([GS::class, 'completionPeriod'], $list));
    eq([], GS::completions('nobody'));
    $html = grades_html($list);
    ok(str_contains($html, '1.75') && str_contains($html, 'Complete') && str_contains($html, 'by Rita'));
    as_user('registrar');
    $s = GS::get('students', $docId);
    GS::saveStudent($docId, ['studentId' => $s['studentId'], 'studentName' => 'Renamed, Student', 'email' => '', 'sectionId' => $s['sectionId']]);
    eq('Renamed, Student', GS::completions($docId)[0]['studentName']);
});

test('mark as done: view only (no changes), payments still allowed; only the administrator reopens; void with a reason', function () use (&$S) {
    as_user('registrar');
    $sid = GS::saveStudent(null, ['studentName' => 'Done, Dina', 'sectionId' => $S['1A']]);
    [$x] = Registration::create(null, doc('students', $sid)['studentId'], $S['1A'], '1st Semester', null);
    eq('OPEN', Registration::state(Registration::get($x)));
    Registration::finish($x);
    $r = Registration::get($x);
    eq('DONE', Registration::state($r));
    $subs = Db::col('SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ?', [$x]);
    throws(fn() => Registration::update($x, null, $subs), AppError::class, 'can only be viewed');
    throws(fn() => Registration::addAdjustment($x, 'Late fee', 10000), AppError::class, 'can only be viewed');
    throws(fn() => Registration::chargeLabel($x, (int) Db::val('SELECT id FROM sis_fee_labels LIMIT 1'), null), AppError::class, 'can only be viewed');
    throws(fn() => Registration::finish($x), AppError::class, 'can only be viewed');
    throws(fn() => Registration::reopen($x, 'fix fees'), Forbidden::class, '', 'registrar cannot reopen');
    as_user('cashier');
    Cashier::pay($x, 10000, 'CASH', null, idem(), null);
    eq('100.00', Registration::get($x)['amount_paid'], 'payment on a done registration');
    as_user('registrar');
    throws(fn() => Registration::void($x, 'wrong'), AppError::class, 'void them first');
    $admin = (int) Db::val("SELECT id FROM sis_users WHERE username = 'admin'");
    Auth::actAs($admin);
    throws(fn() => Registration::reopen($x, ' '), AppError::class, 'reason');
    Registration::reopen($x, 'Wrong subjects');
    eq('OPEN', Registration::state(Registration::get($x)));
    ok((bool) Db::val("SELECT 1 FROM sis_audit_logs WHERE action = 'registration_reopened' AND entity_id = ?", [$x]));
    // void: needs a reason; then view only and the term is free again
    as_user('cashier');
    Cashier::void((int) Db::val('SELECT id FROM sis_payments WHERE registration_id = ?', [$x]), 'test');
    as_user('registrar');
    Registration::finish($x);
    throws(fn() => Registration::void($x, ''), AppError::class, 'reason');
    Registration::void($x, 'Student withdrew');
    eq(['VOIDED', 'VOIDED'], [Registration::state(Registration::get($x)), Registration::payStatus(Registration::get($x))]);
    throws(fn() => Registration::void($x, 'again'), AppError::class, 'already voided');
    throws(fn() => Registration::update($x, null, $subs), AppError::class, 'voided');
    [$y] = Registration::create(null, doc('students', $sid)['studentId'], $S['1A'], '1st Semester', null);
    ok($y > $x, 'registered again for the same term');
});

test('find a student by name or Student ID (any word order)', function () {
    $num = (string) Db::val("SELECT f_studentId FROM docs WHERE col = 'students' AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.studentName')) = 'Done, Dina'");
    eq(['Done, Dina'], array_column(GS::findStudents('dina done'), 'studentName'));
    eq(['Done, Dina'], array_column(GS::findStudents('DONE, dina'), 'studentName'));
    eq(['Done, Dina'], array_column(GS::findStudents($num), 'studentName'));
    eq([], GS::findStudents('nobody here'));
    eq([], GS::findStudents('   '));
    ok(count(GS::findStudents('a')) > 1, 'several matches');
});

test('scholarships & discounts: percent of a category, fixed, maximum, never below zero, recomputed, done locks them', function () use (&$S) {
    as_user('registrar');
    $tuition = (int) Db::val("SELECT id FROM sis_fee_categories WHERE name = 'Tuition'");
    $acad = Discounts::save(null, ['name' => 'Academic', 'kind' => 'SCHOLARSHIP', 'method' => 'PERCENT', 'value' => '50', 'categories' => [(string) $tuition], 'is_active' => 1]);
    $sib = Discounts::save(null, ['name' => 'Sibling', 'kind' => 'DISCOUNT', 'method' => 'PERCENT', 'value' => '10', 'max_amount' => '300', 'is_active' => 1]);
    $big = Discounts::save(null, ['name' => 'Full grant', 'kind' => 'SCHOLARSHIP', 'method' => 'FIXED', 'value' => '999999', 'is_active' => 1]);
    throws(fn() => Discounts::save(null, ['name' => 'Bad', 'kind' => 'DISCOUNT', 'method' => 'PERCENT', 'value' => '120', 'is_active' => 1]), AppError::class, 'at most 100');
    throws(fn() => Discounts::save(null, ['name' => 'Academic', 'kind' => 'DISCOUNT', 'method' => 'FIXED', 'value' => '5', 'is_active' => 1]), AppError::class, 'already used');
    eq('50% of Tuition', Discounts::describe(Discounts::all()[array_search('Academic', array_column(Discounts::all(), 'name'))]));
    $sid = GS::saveStudent(null, ['studentName' => 'Grant, Gina', 'sectionId' => $S['1A']]);
    [$x] = Registration::create(null, doc('students', $sid)['studentId'], $S['1A'], '1st Semester', null);
    $before = Money::cents(Registration::get($x)['total_amount']);         // 8 units: 4000 tuition + fees
    $tuitionCents = Money::cents((string) Db::val("SELECT SUM(amount) FROM sis_registration_items WHERE registration_id = ? AND category_name = 'Tuition'", [$x]));
    Discounts::give($x, $acad, 'GWA 1.25');
    eq($before - intdiv($tuitionCents, 2), Money::cents(Registration::get($x)['total_amount']), '50% of tuition off');
    throws(fn() => Discounts::give($x, $acad, null), AppError::class, 'already given');
    Discounts::give($x, $sib, null);
    eq($before - intdiv($tuitionCents, 2) - 30000, Money::cents(Registration::get($x)['total_amount']), '10% capped at ₱300');
    $two = Db::col("SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ? LIMIT 1", [$x]);
    Registration::update($x, (int) Registration::get($x)['assessment_name_id'], $two);
    $t = Money::cents((string) Db::val("SELECT SUM(amount) FROM sis_registration_items WHERE registration_id = ? AND category_name = 'Tuition'", [$x]));
    eq(-intdiv($t, 2), Money::cents((string) Db::val("SELECT amount FROM sis_registration_items WHERE registration_id = ? AND label_name LIKE 'Academic (%'", [$x])), 'recomputed with the new tuition');
    Discounts::give($x, $big, null);
    eq('0.00', Registration::get($x)['total_amount'], 'never below zero');
    eq(3, count(Discounts::given($x)));
    $g = array_values(array_filter(Discounts::grantees('2026-2027', '1st Semester'), fn($r) => (int) $r['registration_id'] === $x));
    eq(3, count($g));
    Discounts::remove($x, $big);
    ok(Money::cents(Registration::get($x)['total_amount']) > 0, 'removed: back above zero');
    Registration::finish($x);
    throws(fn() => Discounts::give($x, $big, null), AppError::class, 'can only be viewed');
    throws(fn() => Discounts::remove($x, $sib), AppError::class, 'can only be viewed');
    eq(2, count(Discounts::given($x)), 'nothing changed on a done registration');
    as_user('cashier');
    throws(fn() => Discounts::save(null, ['name' => 'X', 'kind' => 'DISCOUNT', 'method' => 'FIXED', 'value' => '5', 'is_active' => 1]), Forbidden::class);
});

test('settings: defaults, validation, amount in words', function () use (&$R) {
    eq('Official Receipt', Settings::get('or_title'));
    as_user('registrar');
    throws(fn() => Settings::save(['or_title' => 'X']), Forbidden::class);
    Auth::actAs((int) Db::val("SELECT id FROM sis_users WHERE username = 'admin'"));
    $base = array_map(fn($v) => $v, Settings::DEFAULTS);
    throws(fn() => Settings::save(['asm_paper' => 'A3'] + $base), AppError::class, 'paper');
    throws(fn() => Settings::save(['asm_template' => '3'] + $base), AppError::class, 'template');
    eq('1', Settings::get('asm_template'));
    Settings::save(['or_title' => 'Acknowledgement Receipt', 'asm_show_subjects' => ''] + $base);
    eq(['Acknowledgement Receipt', '0'], [Settings::get('or_title'), Settings::get('asm_show_subjects')]);
    eq('One thousand two hundred fifty pesos and 50/100', Settings::pesosInWords(125050));
    Settings::save(Settings::DEFAULTS);
    eq('Official Receipt', Settings::get('or_title'));
});

test('O.R. series: numbering, each number used once, edit after posting, void with a negative number', function () use (&$S) {
    $admin = fn() => Auth::actAs((int) Db::val("SELECT id FROM sis_users WHERE username = 'admin'"));
    $series = fn(array $d) => $d + ['code' => 'X', 'description' => 'Test', 'prefix' => '', 'digits' => '5', 'next_number' => '1', 'template' => 'STANDARD', 'is_active' => '1', 'auto_number' => '1', 'allow_edit' => '1'];
    as_user('cashier');
    throws(fn() => OrSeries::save(null, $series([])), Forbidden::class);
    $admin();
    $def = (int) Db::val('SELECT id FROM sis_or_series WHERE is_default = 1');
    eq('OR1', OrSeries::get($def)['code'], 'the first series is the default');
    throws(fn() => OrSeries::save(null, $series(['code' => 'bad code'])), AppError::class, 'Code');
    throws(fn() => OrSeries::save(null, $series(['code' => 'OR1'])), AppError::class, 'already used');
    throws(fn() => OrSeries::save(null, $series(['prefix' => '-X'])), AppError::class, 'Prefix');
    throws(fn() => OrSeries::save(null, $series(['is_default' => '1', 'is_active' => ''])), AppError::class, 'must be active');
    throws(fn() => OrSeries::save($def, ['is_default' => ''] + OrSeries::get($def)), AppError::class, 'one series as the default');
    $lgc = OrSeries::save(null, $series(['code' => 'lgc', 'description' => 'Loose leaf', 'prefix' => 'LGC-', 'template' => 'OFFICIAL']));
    $man = OrSeries::save(null, $series(['code' => 'MAN', 'description' => 'Pre-printed', 'auto_number' => '', 'allow_edit' => '']));
    eq('LGC', OrSeries::get($lgc)['code']);
    eq(['LGC-00001', ''], [OrSeries::suggest(OrSeries::get($lgc)), OrSeries::suggest(OrSeries::get($man))]);

    as_user('registrar');
    $sid = GS::saveStudent(null, ['studentName' => 'Receipt, Rio', 'sectionId' => $S['1A']]);
    [$rid] = Registration::create(null, doc('students', $sid)['studentId'], $S['1A'], '1st Semester', null);
    as_user('cashier');
    $pay = fn(?int $series, ?string $no = null) => Cashier::pay($rid, 100, 'CASH', null, idem(), null, $series, $no);
    $p1 = $pay($lgc);
    $p2 = $pay($lgc);
    eq(['LGC-00001', 'LGC-00002'], [$p1['receipt_no'], $p2['receipt_no']], 'auto-numbered');
    ok((bool) preg_match('/^OR-\d{7}$/', $pay(null)['receipt_no']), 'no series = the default series');
    throws(fn() => $pay($man), AppError::class, 'Enter the O.R. number');
    eq('778899', $pay($man, '778899')['receipt_no'], 'typed number');
    throws(fn() => $pay($man, '778899'), AppError::class, 'used only once');
    throws(fn() => $pay($lgc, 'LGC-00002'), AppError::class, 'used only once');
    throws(fn() => $pay($man, '-5'), AppError::class, 'not starting with -');
    eq('LGC-00005', $pay($lgc, 'LGC-00005')['receipt_no'], 'typed number in an auto series');
    eq('LGC-00006', OrSeries::suggest(OrSeries::get($lgc)), 'the series continues after a typed number');

    // edit after posting
    throws(fn() => Cashier::editNumber((int) $p2['id'], 'LGC-00010', ''), AppError::class, 'reason');
    throws(fn() => Cashier::editNumber((int) $p2['id'], '778899', 'typo'), AppError::class, 'used only once');
    Cashier::editNumber((int) $p2['id'], 'LGC-00010', 'printed on form 10');
    eq('LGC-00010', Db::val('SELECT receipt_no FROM sis_payments WHERE id = ?', [$p2['id']]));
    eq('LGC-00011', OrSeries::suggest(OrSeries::get($lgc)), 'the series continues after the corrected number');
    $manPay = (int) Db::val("SELECT id FROM sis_payments WHERE receipt_no = '778899'");
    throws(fn() => Cashier::editNumber($manPay, '778900', 'x'), AppError::class, "can't be edited");
    as_user('registrar');
    throws(fn() => Cashier::editNumber((int) $p2['id'], 'LGC-00011', 'x'), Forbidden::class);

    // void: keep the number, or make it negative so it can be used again
    as_user('cashier');
    Cashier::void((int) $p1['id'], 'wrong student', true);
    eq(['VOIDED', '-LGC-00001'], array_values(Db::one('SELECT status, receipt_no FROM sis_payments WHERE id = ?', [$p1['id']])));
    $again = $pay($lgc, 'LGC-00001');
    eq('LGC-00001', $again['receipt_no'], 'the freed number is used again');
    Cashier::void((int) $again['id'], 'wrong again', true);
    eq('-LGC-00001-2', Db::val('SELECT receipt_no FROM sis_payments WHERE id = ?', [$again['id']]), 'a second negative copy stays unique');
    Cashier::void((int) $p2['id'], 'kept', false);
    eq('LGC-00010', Db::val('SELECT receipt_no FROM sis_payments WHERE id = ?', [$p2['id']]), 'voided without making it negative: the number stays taken');
    throws(fn() => $pay($lgc, 'LGC-00010'), AppError::class, 'voided receipt');
    throws(fn() => Cashier::editNumber((int) $p2['id'], 'LGC-00020', 'x'), AppError::class, 'Only a posted receipt');
    $r = Registration::get($rid);
    eq(Money::cents($r['amount_paid']), (int) Db::val("SELECT COALESCE(SUM(amount), 0) * 100 FROM sis_payments WHERE registration_id = ? AND status = 'POSTED'", [$rid]), 'balance follows the posted receipts');

    // series housekeeping
    $admin();
    $spare = OrSeries::save(null, $series(['code' => 'SPARE']));
    throws(fn() => OrSeries::delete($lgc), AppError::class, "can't be deleted");
    throws(fn() => OrSeries::delete($def), AppError::class, 'default');
    OrSeries::delete($spare);
    eq(null, OrSeries::get($spare));
    OrSeries::save($man, ['is_active' => ''] + OrSeries::get($man));
    as_user('cashier');
    throws(fn() => $pay($man, '1'), AppError::class, 'not active');
});

test('double-entry accounting: assessment and payments booked, reversed with voids and reopening, ledger balances', function () use (&$S, &$N) {
    $admin = fn() => Auth::actAs((int) Db::val("SELECT id FROM sis_users WHERE username = 'admin'"));
    $code = fn(string $c) => (int) Db::val('SELECT id FROM sis_accounts WHERE code = ?', [$c]);
    $bal = fn(string $c) => (int) (Accounting::balances()[$code($c)] ?? 0);
    $trial = fn() => array_sum(Accounting::balances());
    $arOf = fn(int $rid) => Money::cents((string) Db::val('SELECT COALESCE(SUM(l.debit) - SUM(l.credit), 0) FROM sis_journal_lines l JOIN sis_journal j ON j.id = l.journal_id WHERE j.registration_id = ? AND l.account_id = ?', [$rid, $code('1100')]));
    ok(count(Accounting::accounts()) >= 12, 'default chart of accounts');
    ok(isset(Accounting::map()['ar'], Accounting::map()['method:CASH']), 'default mappings');
    eq(0, $trial(), 'books balanced before');

    as_user('registrar');
    $sid = GS::saveStudent(null, ['studentName' => 'Ledger, Lia', 'sectionId' => $S['1A']]);
    [$rid] = Registration::create(null, doc('students', $sid)['studentId'], $S['1A'], '1st Semester', $N['bsit1']);
    $total = Money::cents(Registration::get($rid)['total_amount']);
    ok($total > 0, 'has fees');
    eq(null, Accounting::activeEntry('ASSESSMENT', $rid), 'nothing booked while the enrollment is open');
    as_user('cashier');
    $p1 = Cashier::pay($rid, 30000, 'CASH', null, idem(), 'down payment');
    eq(-30000, $arOf($rid), 'a down payment before confirming is credited to receivables');
    as_user('registrar');
    Registration::finish($rid);
    $e = Accounting::activeEntry('ASSESSMENT', $rid);
    ok($e !== null, 'confirming books the assessment');
    eq($total - 30000, $arOf($rid), 'receivable = the balance');
    eq(0, $trial(), 'books balanced after confirming');
    $lines = Db::all('SELECT l.*, a.code FROM sis_journal_lines l JOIN sis_accounts a ON a.id = l.account_id WHERE l.journal_id = ?', [$e['id']]);
    ok(in_array('4010', array_column($lines, 'code'), true), 'tuition credited to Tuition Fees');

    as_user('cashier');
    $p2 = Cashier::pay($rid, 20000, 'GCASH', 'GC-777', idem(), null);
    ok($bal('1030') >= 20000, 'GCash booked to e-wallet collections');
    $cashBefore = $bal('1010');
    Cashier::void((int) $p1['id'], 'wrong amount', false);
    eq($cashBefore - 30000, $bal('1010'), 'voiding the receipt reverses the cash');
    $pe = Db::one("SELECT * FROM sis_journal WHERE source = 'PAYMENT' AND source_id = ? AND reverses_id IS NULL", [$p1['id']]);
    ok($pe['reversed_by_id'] !== null, 'the payment entry is marked reversed, and kept');
    eq(Registration::balance(Registration::get($rid)), $arOf($rid), 'receivable follows the void');

    $admin();
    Registration::reopen($rid, 'add a fee');
    eq(null, Accounting::activeEntry('ASSESSMENT', $rid), 'reopening takes the assessment out of the books');
    eq(-20000, $arOf($rid), 'only the remaining payment is left');
    as_user('registrar');
    Registration::addAdjustment($rid, 'Late enrollment fee', 20000);
    Registration::finish($rid);
    eq(Registration::balance(Registration::get($rid)), $arOf($rid), 'booked again with the new total');
    ok(in_array('4090', Db::col('SELECT a.code FROM sis_journal_lines l JOIN sis_accounts a ON a.id = l.account_id WHERE l.journal_id = ?', [Accounting::activeEntry('ASSESSMENT', $rid)['id']]), true), 'other charge credited');
    eq(0, $trial(), 'books still balanced');

    // hand-made entries
    as_user('cashier');
    throws(fn() => Accounting::manualEntry(date('Y-m-d'), 'x', []), Forbidden::class);
    $admin();
    throws(fn() => Accounting::manualEntry(date('Y-m-d'), 'Bank charges', [['account_id' => $code('5010'), 'debit' => '50'], ['account_id' => $code('1020'), 'credit' => '40']]), AppError::class, 'must be equal');
    throws(fn() => Accounting::manualEntry(date('Y-m-d'), '', [['account_id' => $code('5010'), 'debit' => '50'], ['account_id' => $code('1020'), 'credit' => '50']]), AppError::class, 'what the entry is for');
    $m = Accounting::manualEntry(date('Y-m-d'), 'Bank charges', [['account_id' => $code('5010'), 'debit' => '50'], ['account_id' => $code('1020'), 'credit' => '50']]);
    throws(fn() => Accounting::reverseManual((int) $e['id'], 'x'), AppError::class, 'made by the system');
    Accounting::reverseManual($m, 'entered twice');
    throws(fn() => Accounting::reverseManual($m, 'again'), AppError::class, 'already reversed');
    eq(0, $trial(), 'balanced after hand-made entries');

    // cash and bank reconciliation
    $v = Accounting::reconcileView($code('1030'), date('Y-m-d'));
    ok(count($v['lines']) >= 1, 'uncleared e-wallet lines');
    $ids = array_column($v['lines'], 'id');
    $sum = array_sum(array_map(fn($l) => Money::cents($l['debit']) - Money::cents($l['credit']), $v['lines']));
    throws(fn() => Accounting::reconcile($code('1030'), date('Y-m-d'), $sum + 100, $ids, null), AppError::class, 'Not balanced');
    Accounting::reconcile($code('1030'), date('Y-m-d'), $sum, $ids, 'September statement');
    eq(0, count(Accounting::reconcileView($code('1030'), date('Y-m-d'))['lines']), 'all cleared');
    throws(fn() => Accounting::reconcileView($code('1100'), date('Y-m-d')), AppError::class, 'cash or bank');

    // accounts
    throws(fn() => Accounting::saveAccount($code('1100'), ['code' => '1100', 'name' => 'AR', 'type' => 'REVENUE', 'is_active' => '1']), AppError::class, 'type can\'t change');
    throws(fn() => Accounting::saveAccount($code('1100'), ['code' => '1100', 'name' => 'AR', 'type' => 'ASSET']), AppError::class, 'mappings');
    $new = Accounting::saveAccount(null, ['code' => '1025', 'name' => 'Cash in Bank - Savings', 'type' => 'ASSET', 'is_cash' => '1', 'is_active' => '1']);
    throws(fn() => Accounting::saveAccount(null, ['code' => '1025', 'name' => 'X', 'type' => 'ASSET', 'is_active' => '1']), AppError::class, 'already used');
    Accounting::saveMap(['method:BANK' => (string) $new]);
    eq($new, Accounting::map()['method:BANK']);
    throws(fn() => Accounting::saveMap(['ar' => '']), AppError::class, 'every required mapping');
});

test('enrollment rules: readmission for returnees, previous-term grades, enrollment period, administrator override, teachers', function () use (&$S, &$N) {
    $admin = fn() => Auth::actAs((int) Db::val("SELECT id FROM sis_users WHERE username = 'admin'"));
    as_user('registrar');
    $sy = (string) doc('sections', $S['1A'])['schoolYear'];
    // A returning student: last enrolled two school years before, grades of that term tagged complete
    $sid = GS::saveStudent(null, ['studentName' => 'Return, Rex', 'sectionId' => $S['1A']]);
    $num = doc('students', $sid)['studentId'];
    $old = ((int) substr($sy, 0, 4) - 2) . '-' . ((int) substr($sy, 0, 4) - 1);
    Db::insert('sis_registrations', ['reg_no' => 'REG-OLD-1', 'student_number' => $num, 'student_doc_id' => $sid, 'student_name' => 'Return, Rex', 'school_year' => $old, 'term' => '1st Semester',
        'year_level' => '1st Year', 'section_doc_id' => $S['1A'], 'section_name' => 'BSIT 1A', 'created_by' => 1, 'locked_at' => date('Y-m-d H:i:s')]);
    eq('RETURNING', Registration::studentType($sid, $num, $sy, '1st Semester')['key']);
    Db::q("INSERT INTO docs (col, id, data) VALUES ('gradeCompletions', ?, ?)", ["{$sid}__{$old}__1st-semester", json_encode(['studentId' => $sid, 'schoolYear' => $old, 'term' => '1st Semester'])]);
    throws(fn() => Registration::create(null, $num, $S['1A'], '1st Semester', $N['bsit1']), AppError::class, 'readmission request');
    // Readmission request: keeps the Student ID, no new one
    as_user('admission');
    throws(fn() => Admission::validate(['applicant_type' => 'RETURNEE', 'address' => '12 Rizal St., Brgy. Centro, Sample City', 'last_name' => 'Return', 'first_name' => 'Rex', 'year_level' => '1st Year', 'school_year' => $sy]), AppError::class, 'Student ID they already have');
    $adm = Admission::create(Admission::validate(['applicant_type' => 'RETURNEE', 'address' => '12 Rizal St., Brgy. Centro, Sample City', 'last_name' => 'Return', 'first_name' => 'Rex', 'year_level' => '1st Year', 'school_year' => $sy, 'program' => 'BSIT', 'student_number' => $num]));
    eq($num, Admission::admit($adm), 'approved readmission keeps the Student ID');
    as_user('registrar');
    [$rid] = Registration::create($adm, null, $S['1A'], '1st Semester', $N['bsit1']);
    eq($num, Registration::get($rid)['student_number']);

    // Continuing student whose previous-term grades are not tagged complete
    $sid2 = GS::saveStudent(null, ['studentName' => 'Pending, Pia', 'sectionId' => $S['1A']]);
    $num2 = doc('students', $sid2)['studentId'];
    [$first] = Registration::create(null, $num2, $S['1A'], '1st Semester', $N['bsit1']);
    throws(fn() => Registration::create(null, $num2, $S['1A'], '2nd Semester', $N['bsit1']), AppError::class, 'not tagged complete');
    throws(fn() => Registration::create(null, $num2, $S['1A'], '2nd Semester', $N['bsit1'], null, [], 'teacher is late'), Forbidden::class);
    $admin();
    [$ov] = Registration::create(null, $num2, $S['1A'], '2nd Semester', $N['bsit1'], null, [], 'Teacher is late; registrar checked the grades');
    eq('Teacher is late; registrar checked the grades', Registration::get($ov)['override_reason'], 'the reason is kept');
    ok((bool) Db::val("SELECT 1 FROM sis_audit_logs WHERE action = 'registration_override' AND entity_id = ?", [$ov]), 'logged');

    // Enrollment period
    TermCalendar::save(null, ['school_year' => $sy, 'term' => 'Summer', 'enrollment_start' => date('Y-m-d', strtotime('-30 days')), 'enrollment_end' => date('Y-m-d', strtotime('-1 day'))]);
    throws(fn() => TermCalendar::save(null, ['school_year' => $sy, 'term' => 'Summer', 'enrollment_start' => date('Y-m-d'), 'enrollment_end' => date('Y-m-d')]), AppError::class, 'already set');
    throws(fn() => TermCalendar::save(null, ['school_year' => $sy, 'term' => '3rd Semester', 'enrollment_start' => date('Y-m-d'), 'enrollment_end' => date('Y-m-d', strtotime('-1 day'))]), AppError::class, 'close after');
    as_user('registrar');
    $sid3 = GS::saveStudent(null, ['studentName' => 'Late, Leo', 'sectionId' => $S['1A']]);
    throws(fn() => Registration::create(null, doc('students', $sid3)['studentId'], $S['1A'], 'Summer', null), AppError::class, 'Enrollment for');
    as_user('cashier');
    throws(fn() => TermCalendar::save(null, ['school_year' => $sy, 'term' => '1st Semester', 'enrollment_start' => date('Y-m-d'), 'enrollment_end' => date('Y-m-d')]), Forbidden::class);
    $admin();
    TermCalendar::delete((int) TermCalendar::get($sy, 'Summer')['id']);

    // Teachers: assign now from the registration; a confirmed enrollment joins the classes that have a teacher
    as_user('registrar');
    $subject = Db::val('SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ? LIMIT 1', [$rid]);
    $teacher = GS::teachers()[0]['id'];
    $before = GS::sectionAssignments($S['1A'], $sy, '1st Semester');
    if (!isset($before[$subject])) {
        GS::assignTeacher($S['1A'], '1st Semester', $subject, $teacher, [$sid]);
        throws(fn() => GS::assignTeacher($S['1A'], '1st Semester', $subject, $teacher, []), AppError::class, 'already has a teacher');
    }
    as_user('admission');
    throws(fn() => GS::assignTeacher($S['1A'], 'Summer', $subject, $teacher, []), Forbidden::class);
    as_user('registrar');
    Registration::finish($rid);
    $class = GS::sectionAssignments($S['1A'], $sy, '1st Semester')[$subject];
    ok(in_array($sid, $class['studentIds'], true), 'the student is in the class of the subject');
    eq(1, count(array_keys($class['studentIds'], $sid, true)), 'only once');
});

echo "\nPermissions and audit\n";
test('assessment fee workflow: review before confirming, fees from the current setup, student type, setup checks', function () use (&$S, &$N, &$L) {
    as_user('registrar');
    // A. The preview (build) and the saved assessment always agree for open registrations
    foreach (Db::col("SELECT id FROM sis_registrations WHERE status <> 'CANCELLED' AND locked_at IS NULL") as $rid) {
        eq(Money::cents(Registration::get((int) $rid)['total_amount']), Registration::build(Registration::get((int) $rid))['total_cents'], "build = saved for #$rid");
    }
    // B. Student type, from the student's earlier terms
    $sid = GS::saveStudent(null, ['studentName' => 'Flow, Fay', 'sectionId' => $S['1A']]);
    $num = doc('students', $sid)['studentId'];
    eq('NEW', Registration::studentType($sid, $num, '2026-2027', '1st Semester')['key']);
    [$x] = Registration::create(null, $num, $S['1A'], '1st Semester', null);
    eq('NEW', Registration::studentType($sid, $num, '2026-2027', '1st Semester')['key'], 'its own term does not count');
    eq('CONTINUING', Registration::studentType($sid, $num, '2026-2027', '2nd Semester')['key']);
    eq('CONTINUING', Registration::studentType($sid, $num, '2027-2028', '1st Semester')['key'], 'next school year');
    eq('RETURNING', Registration::studentType($sid, $num, '2028-2029', '1st Semester')['key'], 'a whole school year away');
    // C. "Assessment correct?": nothing to fix → confirm without ticking anything, even with nothing paid yet
    $rv = Registration::review($x);
    eq([0, 0], [$rv['errors'], $rv['warnings']], json_encode($rv['checks']));
    ok($rv['balance_cents'] > 0 && str_contains(implode(' ', array_column($rv['checks'], 'text')), 'is not settled yet. You can still confirm'), 'unpaid balance shown as information only');
    eq(Money::cents(Registration::get($x)['total_amount']), $rv['build']['total_cents']);
    // D. The fee setup changes after registering: the review shows the new total; confirming saves it
    $before = Money::cents(Registration::get($x)['total_amount']);
    Assessment::setItem($N['bsit1'], $L['lib'], 'FIXED', 40000);                      // Library 350 → 400
    eq($before, Money::cents(Registration::get($x)['total_amount']), 'saved assessment unchanged until recomputed');
    $rv = Registration::review($x);
    eq($before + 5000, $rv['build']['total_cents']);
    ok(str_contains(implode(' ', array_column($rv['checks'], 'text')), 'fee setup changed'), 'told about the change');
    Registration::finish($x);
    eq([$before + 5000, 'DONE'], [Money::cents(Registration::get($x)['total_amount']), Registration::state(Registration::get($x))], 'confirmed with the current fees');
    eq(['0.00', 'UNPAID'], [Registration::get($x)['amount_paid'], Registration::payStatus(Registration::get($x))], 'confirmed although nothing is paid');
    as_user('cashier');
    Cashier::pay($x, 100000, 'CASH', null, idem(), 'down payment');
    eq('PARTIAL', Registration::payStatus(Registration::get($x)), 'payments still accepted after confirming');
    as_user('registrar');
    Assessment::setItem($N['bsit1'], $L['lib'], 'FIXED', 35000);                      // back to 350
    eq($before + 5000, Money::cents(Registration::get($x)['total_amount']), 'a confirmed assessment no longer follows the setup');
    // E. Errors block; warnings must be confirmed
    $sid2 = GS::saveStudent(null, ['studentName' => 'Flow, Finn', 'sectionId' => $S['1A']]);
    [$y] = Registration::create(null, doc('students', $sid2)['studentId'], $S['1A'], '1st Semester', null);
    $subs = Db::col('SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ?', [$y]);
    Registration::update($y, $N['bsit1'], []);
    throws(fn() => Registration::finish($y, true), AppError::class, 'No subjects');
    Registration::update($y, null, $subs);                                              // no assessment name, no fees
    eq('0.00', Registration::get($y)['total_amount']);
    throws(fn() => Registration::finish($y), AppError::class, 'No fees');
    $other = Assessment::saveName(null, ['name' => 'BSCS 1st Year only', 'program' => 'BSCS', 'year_level' => '1st Year', 'is_active' => 1]);
    Assessment::setItem($other, $L['reg'], 'FIXED', 60000);
    Registration::update($y, $other, $subs);                                            // a template for another program
    throws(fn() => Registration::finish($y), AppError::class, 'is for program BSCS');
    eq('OPEN', Registration::state(Registration::get($y)), 'nothing confirmed while warnings are unchecked');
    Registration::finish($y, true);
    eq(['DONE', '600.00'], [Registration::state(Registration::get($y)), Registration::get($y)['total_amount']]);
    ok((bool) Db::val("SELECT 1 FROM sis_audit_logs WHERE action = 'registration_done' AND entity_id = ? AND details LIKE '%\"warnings_confirmed\":1%'", [$y]), 'confirmed warnings are logged');
    // F. Fees lowered after a full payment: the review shows an error and confirming is refused
    $sid3 = GS::saveStudent(null, ['studentName' => 'Flow, Flor', 'sectionId' => $S['1A']]);
    [$z] = Registration::create(null, doc('students', $sid3)['studentId'], $S['1A'], '1st Semester', null);
    as_user('cashier');
    Cashier::pay($z, Money::cents(Registration::get($z)['total_amount']), 'CASH', null, idem(), null);
    as_user('registrar');
    $tuitionItem = Db::one('SELECT charge_type, amount FROM sis_assessment_name_items WHERE assessment_name_id = ? AND label_id = ?', [$N['bsit1'], $L['tuition']]);
    Assessment::setItem($N['bsit1'], $L['tuition'], $tuitionItem['charge_type'], 10000);
    $rv = Registration::review($z);
    eq(1, $rv['errors'], json_encode($rv['checks']));
    throws(fn() => Registration::finish($z, true), AppError::class, 'already paid');
    Assessment::setItem($N['bsit1'], $L['tuition'], $tuitionItem['charge_type'], Money::cents($tuitionItem['amount']));
    eq([0, 'PAID'], [Registration::review($z)['errors'], Registration::payStatus(Registration::get($z))]);
    // G. Discounts: rounding half up, never more than what is left, total never negative
    eq(12500, Money::percentOf(100001, Money::cents('12.5')));
    eq(1, Money::percentOf(3, Money::cents('33.33')));
    $b = Registration::build(Registration::get($z));
    ok($b['total_cents'] >= 0 && array_sum(array_column($b['discounts'], 'cents')) <= $b['fees_cents'] + max(0, $b['adjust_cents']));
    // H. Fee setup checks: two names that fit the same students, and a name with no fee lines
    $t1 = Assessment::saveName(null, ['name' => 'Tie A', 'program' => 'BSXX', 'year_level' => '1st Year', 'is_active' => 1]);
    $t2 = Assessment::saveName(null, ['name' => 'Tie B', 'program' => 'BSXX', 'year_level' => '1st Year', 'is_active' => 1]);
    $mi = Assessment::matchInfo('BSXX', '1st Year', '1st Semester', '2026-2027');
    eq(['Tie A', ['Tie B'], true], [$mi['best']['name'], $mi['ties'], $mi['empty']]);
    $pb = implode("\n", Assessment::setupProblems());
    ok(str_contains($pb, '"Tie A", "Tie B"') && str_contains($pb, '"Tie A" has no fee lines'), $pb);
    Db::q('UPDATE sis_assessment_names SET is_active = 0 WHERE id IN (?, ?, ?)', [$t1, $t2, $other]);
    ok(!str_contains(implode("\n", Assessment::setupProblems()), 'Tie A'), 'inactive names are not checked');
});

test('excel import of the fee setup: templates, check only, add / update by name, all-or-nothing, CSV', function () use (&$S) {
    as_user('registrar');
    $tmp = function (array $sheets, string $ext = 'xlsx'): string { $f = tempnam(sys_get_temp_dir(), 'imp') . ".$ext"; file_put_contents($f, Xlsx::write($sheets)); return $f; };
    // Round trip: what we write, we read back (text, numbers, empty cells, several sheets)
    $f = $tmp(['One' => [['A', 'B', 'C'], ['x', '', '1,500.00'], ['ñ & "q"', '2', '']], 'Two' => [['Z'], ['z1']]]);
    eq([['A', 'B', 'C'], ['x', '', '1,500.00'], ['ñ & "q"', '2', '']], Xlsx::read($f, 'x.xlsx'));
    eq([['Z'], ['z1']], Xlsx::read($f, 'x.xlsx', 'two'));
    eq(['One', 'Two'], Xlsx::sheetNames($f));
    throws(fn() => Xlsx::read($f, 'old.xls'), AppError::class, '.xlsx');
    // Every template opens and has its sheets + Help
    foreach (array_keys(FeeImport::KINDS) as $k) {
        $t = FeeImport::template($k);
        ok(isset($t['Help']) && count($t) >= 2, "template $k");
        $g = $tmp($t);
        ok(count(Xlsx::read($g, 'g.xlsx')) >= 1, "template $k readable");
    }
    // Labels (with a new category made on the fly): check only saves nothing
    $lab = $tmp(['Labels' => [['Category*', 'Label*', 'Charged*', 'Default amount', 'Description', 'Active'],
        ['Thesis Fees', 'Thesis Adviser Fee', 'Fixed amount', '₱2,500.00', 'Final year', 'Yes'],
        ['Tuition', 'Tuition Fee', 'Per unit', '520', '', '']]]);
    $before = (int) Db::val('SELECT COUNT(*) FROM sis_fee_labels');
    $r = FeeImport::run('labels', $lab, 'labels.xlsx', true);
    eq([1, 1, [], false], [$r['added'], $r['updated'], $r['errors'], $r['saved']], json_encode($r));
    eq($before, (int) Db::val('SELECT COUNT(*) FROM sis_fee_labels'), 'check only: nothing saved');
    ok(!Db::val("SELECT 1 FROM sis_fee_categories WHERE name = 'Thesis Fees'"), 'check only: no category either');
    $r = FeeImport::run('labels', $lab, 'labels.xlsx', false);
    eq([1, 1, true], [$r['added'], $r['updated'], $r['saved']]);
    eq(['2500.00', 'FIXED'], array_values(Db::one("SELECT default_amount, charge_type FROM sis_fee_labels WHERE name = 'Thesis Adviser Fee'")));
    eq('520.00', Db::val("SELECT default_amount FROM sis_fee_labels WHERE name = 'Tuition Fee'"), 'existing label updated, not duplicated');
    ok((bool) Db::val("SELECT 1 FROM sis_audit_logs WHERE action = 'fees_imported'"), 'import logged');
    // All or nothing: one bad row → nothing saved, the row is named
    $bad = $tmp(['Labels' => [['Category', 'Label', 'Charged', 'Default amount'], ['Tuition', 'Good Label', 'Fixed', '100'], ['Tuition', 'Bad Label', 'Per week', '100']]]);
    $r = FeeImport::run('labels', $bad, 'bad.xlsx', false);
    ok(!$r['saved'] && count($r['errors']) === 1 && str_contains($r['errors'][0], 'row 3') && str_contains($r['errors'][0], 'Per week'), json_encode($r['errors']));
    ok(!Db::val("SELECT 1 FROM sis_fee_labels WHERE name = 'Good Label'"), 'the good row was not saved either');
    throws(fn() => FeeImport::run('labels', $tmp(['Labels' => [['Category', 'Charged'], ['x', 'Fixed']]]), 'x.xlsx', false), AppError::class, 'missing column(s): Label');
    // Names + Fee lines (two sheets); then used by a real registration
    $nm = $tmp(['Names' => [['Assessment name', 'Program', 'Year level', 'Term', 'School year', 'Notes', 'Active'], ['BSIT 4th Year Thesis', 'bsit', '4th year', '1st semester', '', '', 'yes']],
        'Fee lines' => [['Assessment name', 'Label', 'Charged', 'Amount'], ['BSIT 4th Year Thesis', 'Thesis Adviser Fee', '', '3000'], ['BSIT 4th Year Thesis', 'Tuition Fee', 'Per unit', '510']]]);
    $r = FeeImport::run('names', $nm, 'names.xlsx', false);
    eq([3, 0, true], [$r['added'], $r['updated'], $r['saved']], json_encode($r));
    $nid = (int) Db::val("SELECT id FROM sis_assessment_names WHERE name = 'BSIT 4th Year Thesis'");
    eq(['BSIT', '4th Year', '1st Semester'], array_values(Db::one('SELECT program, year_level, term FROM sis_assessment_names WHERE id = ?', [$nid])), 'normalised');
    $by = array_column(Assessment::compute($nid, 6.0, 2), 'amount_cents', 'label_name');
    ksort($by);
    eq(['Thesis Adviser Fee' => 300000, 'Tuition Fee' => 306000], $by, 'imported fees compute: 3,000 fixed + 6 × 510');
    $r = FeeImport::run('names', $nm, 'names.xlsx', false);
    eq([0, 3], [$r['added'], $r['updated']], 'importing again updates');
    $r = FeeImport::run('names', $tmp(['Names' => [['Assessment name', 'Year level'], ['X', 'Grade 9']], 'Fee lines' => [['Assessment name', 'Label', 'Amount'], ['Nope', 'Tuition Fee', '1']]]), 'n.xlsx', false);
    eq(2, count($r['errors']), json_encode($r['errors']));
    ok(str_contains(implode(' ', $r['errors']), 'Year level "Grade 9"') && str_contains(implode(' ', $r['errors']), 'Assessment name "Nope" not found'), json_encode($r['errors']));
    // Scholarships & discounts
    $dc = $tmp(['Scholarships & Discounts' => [['Name', 'Type', 'Computation', 'Value', 'Maximum amount', 'Applies to categories', 'Description', 'Active'],
        ['Thesis Grant', 'Scholarship', 'Percentage', '25%', '1,000', 'Thesis Fees, Tuition', '', 'Yes'], ['Early Bird', 'discount', 'fixed amount', '500', '', '', '', 'No']]]);
    $r = FeeImport::run('discounts', $dc, 'd.xlsx', false);
    eq([2, true], [$r['added'], $r['saved']], json_encode($r));
    $g = array_values(array_filter(Discounts::all(), fn($d) => $d['name'] === 'Thesis Grant'))[0];
    eq(['SCHOLARSHIP', 'PERCENT', '25.00', '1000.00', ['Tuition', 'Thesis Fees']], [$g['kind'], $g['method'], $g['value'], $g['max_amount'], array_values($g['categories'])]);
    eq('0', (string) Db::val("SELECT is_active FROM sis_discounts WHERE name = 'Early Bird'"));
    $r = FeeImport::run('discounts', $tmp(['S' => [['Name', 'Type', 'Computation', 'Value'], ['Bad', 'Gift', 'Percentage', '10'], ['Bad2', 'Discount', 'Percentage', '150']]]), 'd.xlsx', false);
    eq(2, count($r['errors']), json_encode($r['errors']));
    // CSV (Excel "Save as CSV"), categories
    $csv = tempnam(sys_get_temp_dir(), 'csv') . '.csv';
    file_put_contents($csv, "\xEF\xBB\xBFCategory,Description,Order,Active\nThesis Fees,For graduating students,45,Yes\nOther Fees,,,No\n");
    $r = FeeImport::run('categories', $csv, 'cats.csv', false);
    eq([0, 2, true], [$r['added'], $r['updated'], $r['saved']], json_encode($r)); // Thesis Fees was added by the labels import
    eq(['45', 'For graduating students'], array_map('strval', array_values(Db::one("SELECT sort_order, description FROM sis_fee_categories WHERE name = 'Thesis Fees'"))));
    // Only people who edit the fee setup can import
    as_user('cashier');
    throws(fn() => FeeImport::run('labels', $lab, 'labels.xlsx', false), Forbidden::class);
    as_user('registrar');
    Db::q("UPDATE sis_fee_categories SET is_active = 1 WHERE name = 'Other Fees'");
    Db::q("UPDATE sis_fee_labels SET default_amount = 500 WHERE name = 'Tuition Fee'");
});

test('roles are enforced in the services', function () use (&$R, &$N, &$S) {
    as_user('cashier');
    throws(fn() => Assessment::saveCategory(null, ['name' => 'X', 'is_active' => 1]), Forbidden::class);
    throws(fn() => Registration::addAdjustment($R, 'x', 100), Forbidden::class);
    throws(fn() => GS::saveSection(null, '2026-2027', '1st Year', 'Z 1A'), Forbidden::class);
    as_user('admission');
    throws(fn() => Cashier::pay($R, 100, 'CASH', null, idem(), null), Forbidden::class);
    throws(fn() => Assessment::setItem($N['bsit1'], 1, 'FIXED', 1), Forbidden::class);
    throws(fn() => GS::saveTeacher(null, '', 'Nope'), Forbidden::class);
    as_user('registrar');
    throws(fn() => Cashier::void(1, 'x'), Forbidden::class);
    eq(false, Auth::can('users.manage'));
    $admin = (int) Db::val("SELECT id FROM sis_users WHERE username = 'admin'");
    Auth::actAs($admin);
    ok(Auth::can('users.manage', 'payments.void', 'assessment.edit') && Auth::can('payments.void') && !Auth::can('self'));
});
test('login lockout and audit trail', function () {
    for ($i = 0; $i < 5; $i++) { try { Auth::login('cashier2', 'wrong'); } catch (AppError $e) {} }
    throws(fn() => Auth::login('cashier2', 'Password123'), AppError::class, 'Too many');
    foreach (['payment_recorded', 'payment_voided', 'registration_created', 'admission_admitted', 'section_updated', 'assessment_item_set'] as $a) {
        ok((bool) Db::val('SELECT 1 FROM sis_audit_logs WHERE action = ?', [$a]), "missing audit $a");
    }
});

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
