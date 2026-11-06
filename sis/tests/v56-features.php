<?php
/** php tests/v56-features.php — v56 checklist features on a disposable sis_v56_test database only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/views/layout.php';
$cfg = sis_config(); $live = $cfg['db_name'];
$name = getenv('SIS_V56_TEST_DB') ?: 'sis_v56_test';
if ($name === $live || !preg_match('/^sis_[a-z0-9_]*test$/', $name)) exit("Refusing an unsafe test database name.\n");
$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']), $cfg['db_user'], $cfg['db_pass']);
$server->exec("DROP DATABASE IF EXISTS `$name`"); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$cfg['db_name'] = $name; Db::connect($cfg);
foreach ([dirname(SIS_ROOT) . '/api/install.sql', SIS_ROOT . '/database/schema.sql', SIS_ROOT . '/database/seed.sql', SIS_ROOT . '/database/seed_accounting.sql'] as $file) {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) Db::q($statement);
}
$failures = 0; $passed = 0;
function expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $contains): void { try { $fn(); } catch (AppError|Forbidden $e) { expect(stripos($e->getMessage(), $contains) !== false, 'Unexpected rejection: ' . $e->getMessage()); return; } throw new RuntimeException('Expected rejection: ' . $contains); }
function scenario(string $label, callable $fn): void { global $failures, $passed; try { $fn(); $passed++; echo "PASS $label\n"; } catch (Throwable $e) { $failures++; echo "FAIL $label: " . get_class($e) . ' ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; } }
function admin(): void { Auth::actAs((int) Db::val("SELECT id FROM sis_users WHERE username = 'admin'")); }
function policy(array $settings): void { admin(); Settings::save(array_replace(Settings::all(), $settings)); }
function cents(string $v): int { return Money::cents($v); }
admin();

// ---------------------------------------------------------------- legacy setup BEFORE the v56 upgrade
// Simulate an installation upgraded from v55: a template and an all-templates subject price exist.
Db::q('DROP TABLE IF EXISTS sis_assessment_name_subject_fees');
Db::q('CREATE TABLE IF NOT EXISTS sis_subject_fees (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, subject_doc_id VARCHAR(191) NOT NULL, assessment_name_id INT UNSIGNED NULL, label_id INT UNSIGNED NOT NULL, rate DECIMAL(12,2) NOT NULL, created_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY u_subject_fee (subject_doc_id, label_id, assessment_name_id), CONSTRAINT fk_subject_fee_label FOREIGN KEY (label_id) REFERENCES sis_fee_labels (id), CONSTRAINT fk_subject_fee_name FOREIGN KEY (assessment_name_id) REFERENCES sis_assessment_names (id) ON DELETE CASCADE) ENGINE=InnoDB');
$section = GS::saveSection(null, '2026-2027', '1st Year', 'FEE 1A');
$lab = GS::saveSubject(null, 'LAB101', 'Laboratory Science', '3');
$lec = GS::saveSubject(null, 'LEC101', 'Lecture Only', '2');
$category = Assessment::saveCategory(null, ['name' => 'V56 Tuition', 'is_active' => 1, 'sort_order' => 1]);
$tuition = Assessment::saveLabel(null, ['name' => 'V56 Tuition fee', 'category_id' => $category, 'charge_type' => 'PER_UNIT', 'default_amount' => '0', 'is_active' => 1]);
$labFee = Assessment::saveLabel(null, ['name' => 'V56 Laboratory fee', 'category_id' => $category, 'charge_type' => 'PER_UNIT', 'default_amount' => '0', 'is_active' => 1]);
$misc = Assessment::saveLabel(null, ['name' => 'V56 Registration', 'category_id' => $category, 'charge_type' => 'FIXED', 'default_amount' => '100', 'is_active' => 1]);
$oldTemplate = Assessment::saveName(null, ['name' => 'Old template', 'is_active' => 1]); Assessment::setItem($oldTemplate, $misc, 'FIXED', 10000);
Db::insert('sis_subject_fees', ['subject_doc_id' => $lab, 'assessment_name_id' => null, 'label_id' => $labFee, 'rate' => '200.00']);
sis_upgrade(); sis_upgrade();
$labPrice = (int) Db::val('SELECT id FROM sis_subject_fees WHERE subject_doc_id = ? AND label_id = ?', [$lab, $labFee]);
function student(string $nameText): array { global $section; admin(); $id = GS::saveStudent(null, ['studentName' => $nameText, 'sectionId' => $section]); return GS::get('students', $id); }
function register(array $s, ?int $template, array $subjects, string $term = '1st Semester'): int { global $section; admin(); [$rid] = Registration::create(null, $s['studentId'], $section, $term, $template, $subjects); return (int) $rid; }

// ---------------------------------------------------------------- B. Subject and fee management
scenario('upgrade keeps existing totals: old templates are ticked for old prices', function () use ($oldTemplate, $labPrice, $lab) {
    expect(SubjectFees::ticks($oldTemplate) === [$labPrice], 'Existing price not ticked for existing template');
    $rid = register(student('Upgrade, Totals'), $oldTemplate, [$lab]);
    expect(Registration::get($rid)['total_amount'] === '700.00', 'Expected 100 + 3 × 200, got ' . Registration::get($rid)['total_amount']);
});
scenario('every subject appears in Fee Management at ₱0.00 until priced', function () use ($lec, $lab) {
    $rows = array_values(array_filter(SubjectFees::management(), fn($r) => $r['subject_doc_id'] === $lec));
    expect(count($rows) === 1 && $rows[0]['fee'] === null && $rows[0]['rate_cents'] === 0 && $rows[0]['total_cents'] === 0, 'New subject not shown at ₱0.00');
    $labRow = array_values(array_filter(SubjectFees::management(), fn($r) => $r['subject_doc_id'] === $lab))[0];
    expect($labRow['total_cents'] === 60000, 'Units × Price per Unit wrong: ' . $labRow['total_cents']);
});
scenario('a subject can be created without any fee', function () {
    $id = GS::saveSubject(null, 'NOFEE1', 'No Fee Subject', '1.5');
    expect((bool) GS::get('subjects', $id), 'Subject not created');
    expect(!Db::val('SELECT 1 FROM sis_subject_fees WHERE subject_doc_id = ?', [$id]), 'A fee was created automatically');
});
scenario('price ₱0.00 is allowed, blank means ₱0.00, negative refused', function () use ($lec, $tuition) {
    $r = SubjectFees::save(['subject_doc_id' => $lec, 'label_id' => (string) $tuition, 'rate' => '']);
    expect(Db::val('SELECT rate FROM sis_subject_fees WHERE id = ?', [$r['id']]) === '0.00', 'Blank price not ₱0.00');
    rejects(fn() => SubjectFees::save(['subject_doc_id' => $lec, 'label_id' => (string) $tuition, 'rate' => '-5']), 'Price per unit');
});
scenario('Edit changes the existing price without a duplicate and recomputes open assessments', function () use ($lab, $labPrice, $oldTemplate) {
    $rid = register(student('Edit, Price'), $oldTemplate, [$lab]);
    $before = (int) Db::val('SELECT COUNT(*) FROM sis_subject_fees');
    $r = SubjectFees::save(['id' => (string) $labPrice, 'rate' => '250.00', 'templates_sent' => '1', 'templates' => [(string) $oldTemplate]]);
    expect($r['id'] === $labPrice && (int) Db::val('SELECT COUNT(*) FROM sis_subject_fees') === $before, 'Edit created a duplicate');
    expect(Registration::get($rid)['total_amount'] === '850.00', 'Open assessment not recomputed: ' . Registration::get($rid)['total_amount']);
    expect($r['recomputed'] >= 1, 'Recompute count missing');
});
scenario('Edit cannot turn a price into a second copy of another fee of the subject', function () use ($lab, $labPrice, $tuition, $labFee) {
    $other = SubjectFees::save(['subject_doc_id' => $lab, 'label_id' => (string) $tuition, 'rate' => '10']);
    rejects(fn() => SubjectFees::save(['id' => (string) $other['id'], 'label_id' => (string) $labFee, 'rate' => '10']), 'already has that fee');
    SubjectFees::delete($other['id']);
});
scenario('new templates charge only the subject fees ticked by hand', function () use ($lab, $misc, $labPrice) {
    $t = Assessment::saveName(null, ['name' => 'Manual template', 'is_active' => 1]); Assessment::setItem($t, $misc, 'FIXED', 10000);
    expect(SubjectFees::ticks($t) === [], 'New template auto-ticked fees');
    $rid = register(student('Manual, Ticks'), $t, [$lab]);
    expect(Registration::get($rid)['total_amount'] === '100.00', 'Unticked subject fee was charged');
    $res = SubjectFees::setTicks($t, [$labPrice]);
    expect($res['recomputed'] === 1 && Registration::get($rid)['total_amount'] === '850.00', 'Ticked fee not charged after recompute: ' . Registration::get($rid)['total_amount']);
    SubjectFees::setTicks($t, []);
    expect(Registration::get($rid)['total_amount'] === '100.00', 'Unticking did not remove fee');
});
scenario('a template copy keeps its ticked subject fees', function () use ($oldTemplate, $labPrice) {
    $copy = Assessment::copyName($oldTemplate, 'Old template copy');
    expect(SubjectFees::ticks($copy) === [$labPrice], 'Copy lost ticks');
});
scenario('every subject is visible on the statement even at ₱0.00', function () use ($lab, $lec, $oldTemplate) {
    $rid = register(student('Visible, Zero'), $oldTemplate, [$lab, $lec]);
    $subs = Db::all('SELECT * FROM sis_registration_subjects WHERE registration_id = ?', [$rid]);
    $items = Db::all('SELECT * FROM sis_registration_items WHERE registration_id = ?', [$rid]);
    $per = SubjectFees::perSubject($subs, $items);
    expect(count($per) === 2 && $per[$lec]['amount_cents'] === 0 && $per[$lab]['amount_cents'] === 75000 && $per[$lab]['rate_cents'] === 25000, 'Per-subject view wrong: ' . json_encode($per));
    expect(!Db::val('SELECT 1 FROM sis_registration_items WHERE registration_id = ? AND amount = 0', [$rid]), 'A ₱0.00 line was booked');
});
scenario('confirmed enrollments keep their assessment when a price changes', function () use ($lab, $labPrice, $oldTemplate) {
    $rid = register(student('Confirmed, Keeps'), $oldTemplate, [$lab]);
    Registration::finish($rid, true);
    $total = Registration::get($rid)['total_amount'];
    SubjectFees::save(['id' => (string) $labPrice, 'rate' => '300.00']);
    expect(Registration::get($rid)['total_amount'] === $total, 'Confirmed assessment changed');
    SubjectFees::save(['id' => (string) $labPrice, 'rate' => '250.00']);
});

// ---------------------------------------------------------------- C. Payment plan, Cashier and SOA
$plainTemplate = Assessment::saveName(null, ['name' => 'Cashier template', 'is_active' => 1]); Assessment::setItem($plainTemplate, $misc, 'FIXED', 100000);
$fullPlan = PaymentPlans::save(['name' => 'Full payment 5%', 'kind' => 'FULL', 'deadline' => date('Y-m-d', strtotime('+10 days')), 'discount' => '5', 'discount_mode' => 'PERCENT', 'stack' => '1', 'is_active' => '1']);
$instPlan = PaymentPlans::save(['name' => 'Two payments', 'kind' => 'INSTALLMENT', 'is_active' => '1', 'steps' => [['label' => 'Down', 'percent' => '50', 'due' => date('Y-m-d', strtotime('+1 day'))], ['label' => 'Final', 'percent' => '50', 'due' => date('Y-m-d', strtotime('+30 days'))]]]);
function pay(int $rid, int $cents): array { admin(); return Cashier::pay($rid, $cents, 'CASH', null, bin2hex(random_bytes(16)), null); }
scenario('Cashier cannot take the first payment without a payment plan', function () use ($plainTemplate, $lec) {
    $rid = register(student('Plan, Missing'), $plainTemplate, [$lec]);
    rejects(fn() => pay($rid, 10000), 'No payment plan is selected');
    expect(!Db::val('SELECT 1 FROM sis_payments WHERE registration_id = ?', [$rid]), 'A payment was posted without a plan');
});
scenario('after the plan is chosen the payment posts once and the SOA figures follow', function () use ($plainTemplate, $lec, $instPlan) {
    $rid = register(student('Plan, Chosen'), $plainTemplate, [$lec]);
    PaymentPlans::assign($rid, $instPlan);
    $p = pay($rid, 40000);
    expect(!$p['duplicate'] && (int) Db::val('SELECT COUNT(*) FROM sis_payments WHERE registration_id = ?', [$rid]) === 1, 'Payment not recorded once');
    $r = Registration::get($rid); $cls = 'soa';
    ob_start(); require SIS_ROOT . '/app/views/soa_plan_box.php'; $html = ob_get_clean();
    expect(str_contains($html, 'Two payments') && str_contains($html, '₱1,000.00') && str_contains($html, '₱400.00') && str_contains($html, '₱600.00'), 'SOA plan box wrong: ' . strip_tags($html));
});
scenario('a confirmed enrollment without payments can still get its plan', function () use ($plainTemplate, $lec, $fullPlan) {
    $rid = register(student('Plan, Confirmed'), $plainTemplate, [$lec]);
    Registration::finish($rid, true);
    PaymentPlans::assign($rid, $fullPlan);
    expect((bool) PaymentPlans::saved($rid), 'Plan not saved on confirmed enrollment');
    $p = pay($rid, 95000);
    $r = Registration::get($rid);
    expect(Registration::balance($r) === 0 && $r['total_amount'] === '950.00', 'Full-payment discount not reflected: total ' . $r['total_amount'] . ' balance ' . Registration::balance($r));
    rejects(fn() => PaymentPlans::assign($rid, $fullPlan), 'before the first payment');
});
scenario('registrations made before the rule and the switch-off are not blocked', function () use ($plainTemplate, $lec) {
    $rid = register(student('Plan, Older'), $plainTemplate, [$lec]);
    Db::q("UPDATE sis_settings SET svalue = ? WHERE skey = 'cashier_plan_rule_since'", [date('Y-m-d H:i:s', time() + 3600)]);
    expect(PaymentPlans::paymentBlock(Registration::get($rid)) === null, 'Older registration blocked');
    Db::q("UPDATE sis_settings SET svalue = ? WHERE skey = 'cashier_plan_rule_since'", ['2000-01-01 00:00:00']);
    expect(PaymentPlans::paymentBlock(Registration::get($rid)) !== null, 'Rule not applied');
    policy(['cashier_require_plan' => '0']);
    expect(PaymentPlans::paymentBlock(Registration::get($rid)) === null, 'Switched-off rule still blocks');
    policy(['cashier_require_plan' => '1']);
});
scenario('Prepared by on the SOA is the signed-in staff member', function () {
    admin();
    expect(SoaPreparer::name() === (string) Db::val("SELECT full_name FROM sis_users WHERE username = 'admin'") || SoaPreparer::name() !== '', 'No preparer name');
    Auth::actAs(null);
    expect(SoaPreparer::name() === '', 'Name shown without a signed-in user');
    admin();
});

// ---------------------------------------------------------------- A/D. Semesters, curriculum and grading assignments
$tA = GS::saveTeacher(null, 'V56A', 'Teacher Alpha'); $tB = GS::saveTeacher(null, 'V56B', 'Teacher Beta'); $tC = GS::saveTeacher(null, 'V56C', 'Teacher Gamma');
$cs = GS::saveSection(null, '2026-2027', '1st Year', 'SYNC 1A');
$s1 = GS::saveSubject(null, 'SYN101', 'Sync One', '3'); $s2 = GS::saveSubject(null, 'SYN102', 'Sync Two', '3'); $s3 = GS::saveSubject(null, 'SYN103', 'Sync Three', '3');
function classOf(string $section, string $subject, string $term = '1st Semester'): ?array { foreach (GS::all('gradingAssignments') as $a) if (($a['sectionId'] ?? '') === $section && ($a['subjectId'] ?? '') === $subject && ($a['term'] ?? '') === $term) return $a; return null; }
scenario('a new curriculum needs a semester (no whole school year)', function () use ($s1) {
    rejects(fn() => GS::saveCurriculum('SYNC', '2nd Year', '', [$s1]), 'Choose the semester');
});
scenario('a new class needs a semester', function () use ($cs, $s1, $tA) {
    rejects(fn() => GS::assignTeacher($cs, '', $s1, $tA, []), 'Choose the semester');
});
scenario('curriculum classes are created with the curriculum teacher', function () use ($cs, $s1, $s2, $s3, $tA) {
    GS::saveCurriculum('SYNC', '1st Year', '1st Semester', [$s1, $s2, $s3], [$s1 => $tA, $s2 => $tA, $s3 => $tA]);
    foreach ([$s1, $s2, $s3] as $sub) expect((classOf($cs, $sub)['teacherDocId'] ?? '') === $tA, 'Class not created with curriculum teacher');
});
scenario('changing the curriculum teacher updates the active class; manual choices and submitted grades stay', function () use ($cs, $s1, $s2, $s3, $tA, $tB, $tC) {
    // s2: teacher changed by hand in Grading → must stay; s3: has a submitted grade → must stay
    $a2 = classOf($cs, $s2); $a2['teacherDocId'] = $tC; $a2['teacherName'] = 'Teacher Gamma'; $id2 = $a2['id']; unset($a2['id']);
    Db::q('UPDATE docs SET data = ? WHERE col = ? AND id = ?', [json_encode($a2), 'gradingAssignments', $id2]);
    $a3 = classOf($cs, $s3);
    Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?)', ['grades', 'g-sync-3', json_encode(['assignmentId' => $a3['id'], 'teacherDocId' => $tA, 'studentId' => 'x', 'finalGrade' => '90', 'draft' => false])]);
    $d1 = 'g-sync-1'; $a1 = classOf($cs, $s1);
    Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?)', ['grades', $d1, json_encode(['assignmentId' => $a1['id'], 'teacherDocId' => $tA, 'studentId' => 'y', 'finalGrade' => '88', 'draft' => true])]);
    GS::saveCurriculum('SYNC', '1st Year', '1st Semester', [$s1, $s2, $s3], [$s1 => $tB, $s2 => $tB, $s3 => $tB]);
    expect(classOf($cs, $s1)['teacherDocId'] === $tB, 'Active class kept the old teacher');
    expect(classOf($cs, $s2)['teacherDocId'] === $tC, 'Teacher chosen in Grading was overwritten');
    expect(classOf($cs, $s3)['teacherDocId'] === $tA, 'Class with submitted grades changed teacher');
    $g = GS::get('grades', $d1);
    expect($g['teacherDocId'] === $tB && $g['finalGrade'] === '88', 'Draft grade not moved or its value changed');
    expect(GS::get('grades', 'g-sync-3')['finalGrade'] === '90', 'Submitted grade changed');
    expect(GS::$lastCurriculumSync['updated'] === 1 && GS::$lastCurriculumSync['kept'] === 1, 'Sync counts wrong: ' . json_encode(GS::$lastCurriculumSync));
    expect(count(array_filter(GS::all('gradingAssignments'), fn($a) => ($a['sectionId'] ?? '') === $cs)) === 3, 'Classes were duplicated or removed');
});
scenario('older school years are history: their classes keep the teacher', function () use ($s1, $tB, $tC) {
    $old = GS::saveSection(null, '2025-2026', '1st Year', 'SYNC 1B');
    $id = 'hist-sync-1';
    Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?)', ['gradingAssignments', $id, json_encode(['teacherDocId' => $tB, 'teacherName' => 'Teacher Beta', 'subjectId' => $s1, 'schoolYear' => '2024-2025', 'term' => '1st Semester', 'sectionId' => $old, 'sectionName' => 'SYNC 1B', 'studentIds' => []])]);
    GS::saveCurriculum('SYNC', '1st Year', '1st Semester', [$s1], [$s1 => $tC]);
    expect(GS::get('gradingAssignments', $id)['teacherDocId'] === $tB, 'History class changed teacher');
});

// ---------------------------------------------------------------- E. Enrollment classification and documents
function applicant(array $over = []): array { return array_replace(['applicant_type' => 'NEW', 'last_name' => 'Docs', 'first_name' => 'Dana', 'address' => '5 Mabini St., Brgy. Uno, Sample City', 'year_level' => '1st Year', 'school_year' => '2026-2027', 'birth_date' => '2008-02-02'], $over); }
scenario('first name, last name and complete address are required', function () {
    rejects(fn() => Admission::validate(applicant(['first_name' => ''])), 'First name');
    rejects(fn() => Admission::validate(applicant(['last_name' => ''])), 'Last name');
    rejects(fn() => Admission::validate(applicant(['address' => ''])), 'address');
    rejects(fn() => Admission::validate(applicant(['address' => 'abc'])), 'Complete address');
});
scenario('classification is required and offers New, Continuing, Transferee and Unit Earner', function () {
    rejects(fn() => Admission::validate(applicant(['applicant_type' => ''])), 'classification');
    foreach (['NEW', 'CONTINUING', 'TRANSFEREE', 'UNIT_EARNER'] as $t) expect(isset(Admission::TYPES[$t]), "Missing $t");
    $id = Admission::create(Admission::validate(applicant(['applicant_type' => 'UNIT_EARNER', 'first_name' => 'Unit', 'last_name' => 'Earner'])));
    expect(Db::val('SELECT applicant_type FROM sis_admissions WHERE id = ?', [$id]) === 'UNIT_EARNER', 'Unit Earner not stored');
    rejects(fn() => Admission::validate(applicant(['applicant_type' => 'CONTINUING', 'student_number' => 'NOPE'])), 'Student ID');
});
scenario('default document requirements are installed once with classifications', function () {
    $names = array_column(EnrollmentDocs::requirements(), 'name');
    foreach (['Form 137 / Permanent Student Record', 'Form 138 / Report Card', 'Certificate of Good Moral Character', 'PSA Birth Certificate', 'Transcript of Records (TOR)', 'Transfer Credentials / Honorable Dismissal', 'Recent ID Pictures', 'Other Documents'] as $n) expect(in_array($n, $names, true), "Missing default $n");
    sis_upgrade();
    expect(count(EnrollmentDocs::requirements()) === count($names), 'Upgrade duplicated requirements');
    $tr = array_column(EnrollmentDocs::forType('TRANSFEREE'), 'name');
    expect(in_array('Transfer Credentials / Honorable Dismissal', $tr, true) && !in_array('Form 138 / Report Card', $tr, true), 'Transferee checklist wrong');
});
scenario('admin can add, edit and remove requirements; used ones are turned off, not deleted', function () {
    $id = EnrollmentDocs::saveRequirement(null, ['name' => 'Medical Certificate', 'applies_to' => ['NEW', 'TRANSFEREE'], 'is_required' => '1', 'is_active' => '1']);
    expect(in_array('Medical Certificate', array_column(EnrollmentDocs::forType('NEW'), 'name'), true), 'New requirement not on checklist');
    rejects(fn() => EnrollmentDocs::saveRequirement(null, ['name' => 'Medical Certificate', 'applies_to' => ['NEW']]), 'already exists');
    rejects(fn() => EnrollmentDocs::saveRequirement($id, ['name' => 'Medical Certificate', 'applies_to' => []]), 'Tick at least one');
    EnrollmentDocs::saveRequirement($id, ['name' => 'Medical Certificate', 'applies_to' => ['TRANSFEREE'], 'is_required' => '1', 'is_active' => '1']);
    expect(!in_array('Medical Certificate', array_column(EnrollmentDocs::forType('NEW'), 'name'), true), 'Edit of classifications ignored');
    $adm = Admission::create(Admission::validate(applicant(['applicant_type' => 'TRANSFEREE', 'previous_school' => 'Old College', 'first_name' => 'Med', 'last_name' => 'Cert'])));
    EnrollmentDocs::saveChecklist($adm, [$id]);
    expect(EnrollmentDocs::removeRequirement($id) === 'off' && (int) Db::val('SELECT is_active FROM sis_document_requirements WHERE id = ?', [$id]) === 0, 'Used requirement deleted');
    $free = EnrollmentDocs::saveRequirement(null, ['name' => 'Unused Doc', 'applies_to' => ['NEW']]);
    expect(EnrollmentDocs::removeRequirement($free) === 'removed', 'Unused requirement not removed');
});
scenario('checklist tracks submitted and missing documents of the applicant', function () {
    $adm = Admission::create(Admission::validate(applicant(['first_name' => 'Check', 'last_name' => 'List'])));
    $a = Db::one('SELECT * FROM sis_admissions WHERE id = ?', [$adm]);
    $req = array_values(array_filter(EnrollmentDocs::forType('NEW'), fn($r) => (int) $r['is_required']));
    expect(count(EnrollmentDocs::missing($a)) === count($req), 'All required should be missing');
    $tr = EnrollmentDocs::forType('TRANSFEREE'); $notNew = array_values(array_filter($tr, fn($r) => !in_array('NEW', $r['types'], true)));
    EnrollmentDocs::saveChecklist($adm, [$req[0]['id'], $req[1]['id'], $notNew[0]['id']]);
    $p = EnrollmentDocs::progress($a);
    expect($p['done'] === 2 && count($p['missing']) === count($req) - 2, 'Progress wrong: ' . json_encode($p));
    expect(!isset(EnrollmentDocs::submitted($adm)[(int) $notNew[0]['id']]), 'Document of another classification was ticked');
    EnrollmentDocs::saveChecklist($adm, [$req[0]['id']]);
    expect(EnrollmentDocs::progress($a)['done'] === 1, 'Unticking failed');
    $no = Admission::admit($adm);
    expect($no !== '' && Db::val('SELECT status FROM sis_admissions WHERE id = ?', [$adm]) === 'ADMITTED', 'Missing documents blocked admission');
});
scenario('continuing students keep their Student ID when admitted', function () use ($section) {
    $sd = GS::saveStudent(null, ['studentName' => 'Keep, Id', 'sectionId' => $section]); $st = GS::get('students', $sd);
    $adm = Admission::create(Admission::validate(applicant(['applicant_type' => 'CONTINUING', 'student_number' => $st['studentId'], 'first_name' => 'Keep', 'last_name' => 'Id'])));
    expect(Admission::admit($adm) === $st['studentId'], 'Continuing student got a new Student ID');
});
scenario('only confirmed enrollments are on the official enrollment list', function () use ($plainTemplate, $lec) {
    $pending = student('List, Pending'); $done = student('List, Confirmed');
    register($pending, $plainTemplate, [$lec], '2nd Semester');
    $rid = register($done, $plainTemplate, [$lec], '2nd Semester'); Registration::finish($rid, true);
    $rep = Reports::run('class_list', Reports::params(['sy' => '2026-2027', 'term' => '2nd Semester']));
    $names = []; foreach ($rep['sections'] as $sec) foreach ($sec['rows'] as $row) $names[] = $row[2];
    expect(in_array('LIST, CONFIRMED', $names, true) && !in_array('LIST, PENDING', $names, true), 'Official list wrong: ' . json_encode($names));
});

// ---------------------------------------------------------------- F. Data Migration → Import All
function workbook(array $rows): string { $f = tempnam(sys_get_temp_dir(), 'ia') . '.xlsx'; file_put_contents($f, Xlsx::write(['Records' => $rows])); return $f; }
$H = array_map(fn($f) => $f[0] . ($f[1] ? ' *' : ''), ImportAll::FIELDS);
$tImp = GS::saveTeacher(null, 'IMP01', 'Import Teacher');
scenario('template follows the system fields and has drop-down lists', function () {
    $t = ImportAll::template();
    expect($t['sheets']['Records'][0][0] === 'Subject Code *' && in_array('Price per Unit', $t['sheets']['Records'][0], true) && in_array('Professor / Teacher', $t['sheets']['Records'][0], true), 'Template headings wrong');
    expect(count($t['dropdowns']) >= 6 && in_array('Summer', $t['dropdowns'][array_search('term', array_keys(ImportAll::FIELDS))], true), 'Drop-downs missing');
    $bin = Xlsx::write($t['sheets'], $t['dropdowns']);
    expect(strlen($bin) > 1000, 'Template not written');
    expect(count(ImportAll::template(true)['sheets']['Records']) === count(GS::subjects()) + 1, 'Current-subjects template incomplete');
});
scenario('upload checks every row: required fields, units, price, references and duplicate codes', function () use ($H) {
    $f = workbook([$H,
        ['IMP101', 'Imported One', '3', 'Lecture', '450', 'V56 Tuition fee', 'V56 template x', 'Import Teacher', '', 'IMPX', '1st Year', '1st Semester'],
        ['IMP102', '', '3', '', '', '', '', '', '', '', '', ''],
        ['IMP103', 'Bad Units', '45', '', '-1', 'No Such Fee', '', 'Nobody', 'Nope · 1st Year · Summer', '', '', ''],
        ['IMP104', 'Dup A', '2', '', '100', '', '', '', '', '', '', ''],
        ['IMP104', 'Dup B', '2', '', '100', '', '', '', '', '', '', ''],
        ['LAB101', 'Laboratory Science', '3', 'Laboratory', '', '', '', '', '', '', '', ''],
        ['IMP105', 'Half Curriculum', '3', '', '', '', '', '', '', 'IMPX', '', ''],
    ]);
    $rows = ImportAll::check(ImportAll::read($f, 'test.xlsx'));
    expect($rows[0]['status'] === 'ERROR' && str_contains(implode(' ', $rows[0]['errors']), 'Fee template'), 'Unknown fee template not reported');
    expect($rows[1]['status'] === 'ERROR' && str_contains(implode(' ', $rows[1]['errors']), 'Subject Name is missing'), 'Missing name not reported');
    $e2 = implode(' ', $rows[2]['errors']);
    foreach (['Subject Units', 'Price per Unit', 'Fee Label', 'Teacher', 'Curriculum'] as $w) expect(str_contains($e2, $w), "Invalid $w not reported: $e2");
    expect($rows[3]['status'] === 'ERROR' && $rows[4]['status'] === 'ERROR' && str_contains(implode(' ', $rows[3]['errors']), 'Duplicate Subject Code'), 'Duplicate codes not detected');
    expect($rows[5]['status'] === 'EXISTING' && $rows[5]['actions'] === ['UPDATE', 'REPLACE', 'SKIP'] && $rows[5]['action'] === 'SKIP', 'Existing subject not matched by code');
    expect($rows[6]['status'] === 'ERROR' && str_contains(implode(' ', $rows[6]['errors']), 'all three'), 'Incomplete curriculum not reported');
    expect(!GS::get('subjects', 'x') && !array_filter(GS::subjects(), fn($s) => ($s['subjectCode'] ?? '') === 'IMP101'), 'Checking imported something');
});
$iaBatch = 0;
scenario('only ticked rows import; Add creates subject, price (Units × Price), ticks and curriculum with teacher', function () use ($H, $tImp, $oldTemplate, &$iaBatch) {
    $f = workbook([$H,
        ['IMP201', 'Imported Two', '3', 'Lecture', '450', 'V56 Tuition fee', 'Old template', 'Import Teacher', '', 'IMPX', '1st Year', '1st Semester'],
        ['IMP202', 'Imported Three', '2', '', '', '', '', '', '', 'IMPX', '1st Year', '1st Semester'],
        ['IMP203', 'Not Ticked', '1', '', '', '', '', '', '', '', '', ''],
    ]);
    $iaBatch = ImportAll::upload($f, 'add.xlsx');
    rejects(fn() => ImportAll::apply($iaBatch, [0 => 'ADD'], false), 'reviewed');
    $n = ImportAll::apply($iaBatch, [0 => 'ADD', 1 => 'ADD'], true);
    expect($n['added'] === 2 && $n['skipped'] === 1 && $n['failed'] === 0, 'Counts wrong: ' . json_encode($n));
    $by = array_column(GS::subjects(), null, 'subjectCode');
    expect(isset($by['IMP201'], $by['IMP202']) && !isset($by['IMP203']), 'Unticked row imported or ticked row missing');
    expect(($by['IMP201']['subjectType'] ?? '') === 'Lecture', 'Subject type not saved');
    $m = array_values(array_filter(SubjectFees::management(), fn($r) => $r['subject_doc_id'] === $by['IMP201']['id']))[0];
    expect($m['rate_cents'] === 45000 && $m['total_cents'] === 135000 && array_column($m['fee']['templates'], 'id') === [$oldTemplate], 'Price or template tick wrong');
    $m2 = array_values(array_filter(SubjectFees::management(), fn($r) => $r['subject_doc_id'] === $by['IMP202']['id']))[0];
    expect($m2['fee'] === null && $m2['total_cents'] === 0, 'Subject without price should stay at ₱0.00');
    $cur = GS::curriculumFor('IMPX', '1st Year', '1st Semester');
    expect($cur && $cur['subjectTeachers'][$by['IMP201']['id']] === $tImp && $cur['subjectTeachers'][$by['IMP202']['id']] === CurriculumAssignments::PLACEHOLDER, 'Curriculum teachers wrong');
    rejects(fn() => ImportAll::apply($iaBatch, [0 => 'ADD'], true), 'already');
    $b = ImportAll::batch($iaBatch);
    expect($b['status'] === 'APPLIED' && $b['applied_by'] && count(ImportAll::report($b)) === 4, 'History / report missing');
});
scenario('Update changes only given values; Replace sets file values and needs REPLACE; Skip leaves it', function () use ($H, $tImp, $tB) {
    $by = array_column(GS::subjects(), null, 'subjectCode');
    $f = workbook([$H,
        ['IMP201', '', '', '', '500', '', '', '', '', '', '', ''],
        ['IMP202', 'Imported Three Renamed', '2', '', '', '', '', 'Teacher Beta', '', 'IMPX', '1st Year', '1st Semester'],
        ['LAB101', 'Should Not Change', '9', '', '', '', '', '', '', '', '', ''],
    ]);
    $id = ImportAll::upload($f, 'update.xlsx');
    rejects(fn() => ImportAll::apply($id, [0 => 'UPDATE', 1 => 'REPLACE', 2 => 'SKIP'], true, ''), 'type REPLACE');
    $n = ImportAll::apply($id, [0 => 'UPDATE', 1 => 'REPLACE', 2 => 'SKIP'], true, 'replace');
    expect($n['updated'] === 1 && $n['replaced'] === 1 && $n['skipped'] === 1, 'Counts wrong: ' . json_encode($n));
    $now = array_column(GS::subjects(), null, 'subjectCode');
    expect($now['IMP201']['subjectName'] === 'Imported Two' && ($now['IMP201']['subjectType'] ?? '') === 'Lecture', 'Update changed values not in the file');
    $m = array_values(array_filter(SubjectFees::management(), fn($r) => $r['subject_doc_id'] === $now['IMP201']['id']))[0];
    expect($m['rate_cents'] === 50000 && count($m['fee']['templates']) === 1, 'Update price or ticks wrong');
    expect($now['IMP202']['subjectName'] === 'Imported Three Renamed', 'Replace did not set the name');
    expect(GS::curriculumFor('IMPX', '1st Year', '1st Semester')['subjectTeachers'][$now['IMP202']['id']] === $tB, 'Replace did not set the curriculum teacher');
    expect($now['LAB101']['subjectName'] === 'Laboratory Science' && (float) $now['LAB101']['units'] === 3.0, 'Skipped row changed');
});
scenario('a code added after the preview fails instead of creating a duplicate', function () use ($H) {
    $id = ImportAll::upload(workbook([$H, ['IMP301', 'Late Add', '3', '', '', '', '', '', '', '', '', '']]), 'late.xlsx');
    GS::saveSubject(null, 'IMP301', 'Created Meanwhile', '3');
    $n = ImportAll::apply($id, [0 => 'ADD'], true);
    expect($n['failed'] === 1 && count(array_filter(GS::subjects(), fn($s) => ($s['subjectCode'] ?? '') === 'IMP301')) === 1, 'Duplicate subject created');
});
scenario('grades and confirmed assessments are untouched by Replace', function () use ($H, $plainTemplate) {
    $by = array_column(GS::subjects(), null, 'subjectCode');
    $rid = register(student('Import, Safe'), $plainTemplate, [$by['IMP201']['id']], 'Summer'); Registration::finish($rid, true);
    $before = Db::one('SELECT total_amount, total_units FROM sis_registrations WHERE id = ?', [$rid]);
    $id = ImportAll::upload(workbook([$H, ['IMP201', 'Imported Two', '4', '', '900', '', '', '', '', '', '', '']]), 'safe.xlsx');
    ImportAll::apply($id, [0 => 'REPLACE'], true, 'REPLACE');
    expect(Db::one('SELECT total_amount, total_units FROM sis_registrations WHERE id = ?', [$rid]) == $before, 'Confirmed assessment changed');
    expect((float) Db::val('SELECT units FROM sis_registration_subjects WHERE registration_id = ?', [$rid]) === 3.0, 'Registration subject snapshot changed');
});
scenario('discarded previews import nothing and cannot be applied', function () use ($H) {
    $id = ImportAll::upload(workbook([$H, ['IMP401', 'Discard Me', '3', '', '', '', '', '', '', '', '', '']]), 'discard.xlsx');
    ImportAll::discard($id);
    rejects(fn() => ImportAll::apply($id, [0 => 'ADD'], true), 'discarded');
    expect(!array_filter(GS::subjects(), fn($s) => ($s['subjectCode'] ?? '') === 'IMP401'), 'Discarded row imported');
});

// ---------------------------------------------------------------- v56.1 Manage fees: one list of every fee
scenario('Manage fees lists every general and subject fee; ticking charges it with its price', function () use ($misc, $lab, $lec, $labPrice) {
    $t = Assessment::saveName(null, ['name' => 'One list template', 'is_active' => 1]);
    $rows = TemplateFees::rows($t);
    expect(in_array($misc, array_column($rows['general'], 'label_id'), true) && !array_filter($rows['general'], fn($r) => $r['on']), 'General fees not listed or pre-ticked');
    expect(in_array('c' . $labPrice, array_column($rows['subjects'], 'key'), true) && !array_filter($rows['subjects'], fn($r) => $r['on']), 'Subject fees not listed or pre-ticked');
    $rid = register(student('One, List'), $t, [$lab, $lec]);
    expect(Registration::get($rid)['total_amount'] === '0.00', 'Empty template charged something');
    $r = TemplateFees::save($t, ['gen' => [$misc => ['on' => '1', 'amount' => '', 'charge' => 'FIXED']], 'sub' => ['c' . $labPrice => ['on' => '1', 'price' => '']]]);
    expect($r['general'] === 1 && $r['subjects'] === 1 && $r['recomputed'] === 1, 'Counts wrong: ' . json_encode($r));
    expect(Registration::get($rid)['total_amount'] === '850.00', 'Expected 100 + 3 × 250, got ' . Registration::get($rid)['total_amount']);
    TemplateFees::save($t, ['gen' => [$misc => ['on' => '1', 'amount' => '150', 'charge' => 'FIXED']], 'sub' => ['c' . $labPrice => ['on' => '1', 'price' => '300']]]);
    expect(Registration::get($rid)['total_amount'] === '900.00', 'Subject fee must keep the Subject Fees price (150 + 750), got ' . Registration::get($rid)['total_amount']);
    expect(!Db::val('SELECT 1 FROM sis_subject_fees WHERE assessment_name_id = ?', [$t]), 'A template-only subject price was created');
    TemplateFees::save($t, ['sub' => ['c' . $labPrice => ['on' => '1']]]);
    expect(Registration::get($rid)['total_amount'] === '750.00', 'Unticking the general fee failed');
    rejects(fn() => TemplateFees::save($t, ['gen' => [$misc => ['on' => '1', 'amount' => '-1']]]), 'enter ₱0.00 or more');
    TemplateFees::save($t, []);
    expect(Registration::get($rid)['total_amount'] === '0.00' && SubjectFees::ticks($t) === [], 'Unticking everything failed');
});

// ---------------------------------------------------------------- v56.2 Separate lecture and laboratory units
scenario('lecture / laboratory units: subject split, lab fee per lab unit, statement shows Lec / Lab', function () use ($H, $labFee, $tuition, $misc) {
    policy(['asm_split_lec_lab' => '1']);
    $sid = GS::saveSubject(null, 'LL101', 'Lec Lab Subject', '3');
    rejects(fn() => GS::setSubjectSplit($sid, [2, 2]), 'must equal');
    GS::setSubjectSplit($sid, LecLab::parse('2', '1'));
    expect(LecLab::ofSubject(GS::get('subjects', $sid)) === [2.0, 1.0, 3.0], 'Split not saved');
    $t = Assessment::saveName(null, ['name' => 'Lec lab template', 'is_active' => 1]);
    $lf = SubjectFees::save(['subject_doc_id' => $sid, 'label_id' => (string) $labFee, 'rate' => '500', 'unit_basis' => 'LAB', 'templates_sent' => '1', 'templates' => [(string) $t]]);
    SubjectFees::save(['subject_doc_id' => $sid, 'label_id' => (string) $tuition, 'rate' => '100', 'unit_basis' => 'LEC', 'templates_sent' => '1', 'templates' => [(string) $t]]);
    $row = array_values(array_filter(SubjectFees::management(), fn($r) => $r['fee'] && (int) $r['fee']['id'] === $lf['id']))[0];
    expect($row['basis_units'] === 1.0 && $row['total_cents'] === 50000, 'Lab-unit total wrong: ' . json_encode([$row['basis_units'], $row['total_cents']]));
    $rid = register(student('Lec, Lab'), $t, [$sid]);
    expect(Registration::get($rid)['total_amount'] === '700.00', 'Expected 1 lab × 500 + 2 lec × 100, got ' . Registration::get($rid)['total_amount']);
    expect(Db::val('SELECT CONCAT(lec_units, "/", lab_units) FROM sis_registration_subjects WHERE registration_id = ?', [$rid]) === '2.0/1.0', 'Registration did not keep the split');
    $r = Registration::get($rid); $subs = Db::all('SELECT * FROM sis_registration_subjects WHERE registration_id = ?', [$rid]); $items = Db::all('SELECT * FROM sis_registration_items WHERE registration_id = ?', [$rid]);
    $pays = []; $state = 'OPEN'; $id = $rid;
    foreach (['1', '2'] as $tpl) {
        policy(['asm_split_lec_lab' => '1', 'asm_template' => $tpl]);
        ob_start(); require SIS_ROOT . '/app/views/assessment_sheet.php'; $html = ob_get_clean();
        expect(str_contains($html, '>Lec</th>') && str_contains($html, '>Lab</th>') && str_contains($html, '700.00'), "Template $tpl does not show Lec / Lab");
    }
    policy(['asm_template' => '1']);
    $id2 = ImportAll::upload(workbook([$H, ['LL201', 'Imported LecLab', '', '', '', '', '', '', '', '', '', '', '2', '1'], ['LL202', 'Bad Split', '3', '', '', '', '', '', '', '', '', '', '2', '2']]), 'leclab.xlsx');
    $b = ImportAll::batch($id2);
    expect($b['rows'][0]['status'] === 'NEW' && $b['rows'][0]['clean']['units'] === '3' && $b['rows'][1]['status'] === 'ERROR', 'Import All lec/lab check wrong: ' . json_encode([$b['rows'][0]['errors'], $b['rows'][1]['errors']]));
    ImportAll::apply($id2, [0 => 'ADD'], true);
    $imp = array_values(array_filter(GS::subjects(), fn($x) => ($x['subjectCode'] ?? '') === 'LL201'))[0];
    expect(LecLab::ofSubject($imp) === [2.0, 1.0, 3.0], 'Import All did not save the split');
    policy(['asm_split_lec_lab' => '0']);
    expect(Registration::get($rid)['total_amount'] === '700.00', 'Turning the setting off changed a saved assessment');
});

scenario('an open assessment made before the split follows it; a confirmed one does not change', function () use ($labFee) {
    policy(['asm_split_lec_lab' => '1']);
    $sid = GS::saveSubject(null, 'LL301', 'Split Later', '3');
    $t = Assessment::saveName(null, ['name' => 'Split later template', 'is_active' => 1]);
    SubjectFees::save(['subject_doc_id' => $sid, 'label_id' => (string) $labFee, 'rate' => '400', 'unit_basis' => 'LAB', 'templates_sent' => '1', 'templates' => [(string) $t]]);
    $open = register(student('Split, Open'), $t, [$sid]);
    $done = register(student('Split, Done'), $t, [$sid], '2nd Semester'); Registration::finish($done, true);
    expect(Registration::get($open)['total_amount'] === '0.00', 'Lab fee charged before the subject had lab units');
    GS::setSubjectSplit($sid, [2.0, 1.0]);
    expect(Registration::get($open)['total_amount'] === '400.00', 'Open assessment did not follow the new split: ' . Registration::get($open)['total_amount']);
    expect(Registration::get($done)['total_amount'] === '0.00', 'Confirmed assessment changed');
    policy(['asm_split_lec_lab' => '0']);
});

scenario('payment plan preview shows on the assessment before saving and matches the saved plan', function () use ($plainTemplate, $lec, $instPlan, $fullPlan) {
    $rid = register(student('Plan, Preview'), $plainTemplate, [$lec]);
    $r = Registration::get($rid);
    $pv = PaymentPlans::preview($r, $instPlan);
    expect(!PaymentPlans::saved($rid) && count($pv['rows']) === 2, 'Preview saved the plan or has no schedule');
    $planPreview = $pv; $cls = 'soa'; ob_start(); require SIS_ROOT . '/app/views/soa_plan_box.php'; $html = ob_get_clean(); unset($planPreview);
    expect(str_contains($html, 'Preview') && str_contains($html, 'Two payments') && str_contains($html, 'Down'), 'Preview box wrong');
    $full = PaymentPlans::preview($r, $fullPlan);
    expect($full['offer'] === 5000, 'Full-payment offer not previewed: ' . $full['offer']);
    PaymentPlans::assign($rid, $instPlan);
    $saved = PaymentPlans::schedule(Registration::get($rid));
    expect(array_column($saved['rows'], 'amount') === array_column($pv['rows'], 'amount'), 'Saved schedule differs from the preview');
});

scenario('full payment 80% "deduct as soon as chosen" lowers the balance at once; switching or removing the plan restores it', function () use ($plainTemplate, $lec, $instPlan) {
    $p80 = PaymentPlans::save(['name' => 'Full payment 80% off', 'kind' => 'FULL', 'deadline' => date('Y-m-d', strtotime('+10 days')), 'discount' => '80', 'discount_mode' => 'PERCENT', 'stack' => '1', 'apply_now' => '1', 'is_active' => '1']);
    $rid = register(student('Eighty, Percent'), $plainTemplate, [$lec]);
    expect(Registration::get($rid)['total_amount'] === '1000.00', 'Setup total wrong');
    $r = Registration::get($rid); $planPreview = PaymentPlans::preview($r, $p80); $cls = 'soa';
    ob_start(); require SIS_ROOT . '/app/views/soa_plan_box.php'; $html = ob_get_clean(); unset($planPreview);
    expect(str_contains($html, '−₱800.00') && str_contains($html, '₱200.00'), 'Preview does not show the deducted balance: ' . strip_tags($html));
    PaymentPlans::assign($rid, $p80);
    $r = Registration::get($rid);
    expect($r['total_amount'] === '200.00' && Registration::balance($r) === 20000, 'Discount not deducted at once: ' . $r['total_amount']);
    PaymentPlans::assign($rid, $instPlan);
    expect(Registration::get($rid)['total_amount'] === '1000.00', 'Switching plans did not restore the balance: ' . Registration::get($rid)['total_amount']);
    PaymentPlans::assign($rid, $p80);
    PaymentPlans::assign($rid, 0);
    expect(Registration::get($rid)['total_amount'] === '1000.00' && !PaymentPlans::saved($rid), 'Removing the plan did not restore the balance');
    PaymentPlans::assign($rid, $p80);
    Registration::finish($rid, true);
    pay($rid, 20000);
    $r = Registration::get($rid);
    expect(Registration::balance($r) === 0 && $r['total_amount'] === '200.00', 'Paying the discounted balance did not settle it');
    $j = Db::val("SELECT COUNT(*) FROM sis_journal WHERE registration_id = ? AND source = 'ASSESSMENT'", [$rid]);
    expect((int) $j === 1, 'Assessment not booked');
    $legacy = PaymentPlans::save(['name' => 'Old style full', 'kind' => 'FULL', 'deadline' => date('Y-m-d', strtotime('+10 days')), 'discount' => '10', 'discount_mode' => 'PERCENT', 'stack' => '1', 'is_active' => '1']);
    $rid2 = register(student('Old, Style'), $plainTemplate, [$lec], 'Summer');
    PaymentPlans::assign($rid2, $legacy);
    expect(Registration::get($rid2)['total_amount'] === '1000.00', 'A plan without the option deducted at once');
});

echo "\n$passed passed, $failures failed\n";
exit($failures ? 1 : 0);
