<?php
/**
 * Full applicant-to-graduate lifecycle test.
 * Run with a disposable database configuration:
 *   E2E_DB=sis_e2e php sis/tests/e2e_lifecycle.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/views/layout.php';

$cfg = sis_config();
$live = $cfg['db_name'];
$cfg['db_name'] = getenv('E2E_DB') ?: 'sis_e2e';
if ($cfg['db_name'] === $live) exit("Refusing to run on the configured database.\n");
$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']), $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $cfg['db_name']) . '`');
$server->exec('CREATE DATABASE `' . str_replace('`', '``', $cfg['db_name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
Db::connect($cfg);

$runSql = function (string $file): void {
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) Db::pdo()->exec($stmt);
};
$runSql(dirname(SIS_ROOT) . '/api/install.sql');
$runSql(SIS_ROOT . '/database/schema.sql');
$runSql(SIS_ROOT . '/database/seed.sql');
$runSql(SIS_ROOT . '/database/seed_accounting.sql');
require __DIR__ . '/_legacy-payments.php'; // v56: these flows predate the payment-plan rule

function e2e_ok(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function e2e_user(string $username, string $role): int {
    $id = (int) Db::val('SELECT id FROM sis_users WHERE username = ?', [$username]);
    if (!$id) $id = Db::insert('sis_users', ['username' => $username, 'full_name' => ucfirst(strtolower($username)), 'password_hash' => password_hash('Password123', PASSWORD_DEFAULT), 'role' => $role]);
    Auth::actAs($id);
    return $id;
}
function e2e_doc(string $id): array { return GS::get('students', $id) ?? []; }
function e2e_tag(string $docId, string $schoolYear, string $term): void {
    $key = GS::termKey($term);
    Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)', ['gradeCompletions', "{$docId}__{$schoolYear}__{$key}", json_encode([
        'studentId' => $docId, 'schoolYear' => $schoolYear, 'term' => $term, 'termKey' => $key, 'subjects' => [],
    ])]);
}
function e2e_second_semester_record(string $docId, string $studentNumber, string $schoolYear): void {
    Db::q('INSERT INTO docs (col, id, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)', ['gradingAssignments', "e2e-{$docId}-{$schoolYear}", json_encode([
        'schoolYear' => $schoolYear, 'term' => '2nd Semester', 'studentIds' => [$docId], 'sectionId' => '', 'sectionName' => '',
        'studentNumber' => $studentNumber,
    ])]);
}
function e2e_next_year(string $schoolYear): string {
    $a = (int) substr($schoolYear, 0, 4) + 1;
    return $a . '-' . ($a + 1);
}

try {
    // Setup the four academic year sections used by the actual Registration handoff.
    e2e_user('admin', 'ADMIN');
    $sections = [];
    foreach ([['2026-2027', '1st Year', 'E2E BSIT 1A'], ['2027-2028', '2nd Year', 'E2E BSIT 2A'], ['2028-2029', '3rd Year', 'E2E BSIT 3A'], ['2029-2030', '4th Year', 'E2E BSIT 4A']] as [$sy, $level, $name]) {
        $sections[$sy] = GS::saveSection(null, $sy, $level, $name);
    }
    e2e_user('registrar', 'REGISTRAR');
    $cat = Assessment::saveCategory(null, ['name' => 'E2E Tuition', 'is_active' => 1]);
    $label = Assessment::saveLabel(null, ['category_id' => $cat, 'name' => 'E2E Tuition Fee', 'charge_type' => 'FIXED', 'default_amount' => '1000', 'is_active' => 1]);
    $assessment = Assessment::saveName(null, ['name' => 'E2E General Assessment', 'is_active' => 1]);
    Assessment::setItem($assessment, $label, 'FIXED', 100000);

    // 1. Applicant → Admission → Student ID.
    e2e_user('admission', 'ADMISSION');
    $applicant = Admission::validate(['applicant_type' => 'NEW', 'address' => '12 Rizal St., Brgy. Centro, Sample City', 'last_name' => 'Lifecycle', 'first_name' => 'Student', 'middle_name' => 'E2E', 'birth_date' => '2008-01-02', 'program' => 'BSIT', 'year_level' => '1st Year', 'school_year' => '2026-2027', 'email' => 'e2e@example.com']);
    $admissionId = Admission::create($applicant);
    $studentNumber = Admission::admit($admissionId);
    e2e_ok($studentNumber !== '', 'Admission did not issue a Student ID.');

    // 2. First registration through the admitted applicant and full Cashier payment.
    e2e_user('registrar', 'REGISTRAR');
    [$reg1] = Registration::create($admissionId, null, $sections['2026-2027'], '1st Semester', $assessment);
    $r1 = Registration::get($reg1);
    $doc = GS::studentByNumber($studentNumber);
    e2e_ok($doc && $doc['yearLevel'] === '1st Year' && $doc['schoolYear'] === '2026-2027', 'Initial registration did not create the 1st-year grading record.');
    e2e_ok(Money::cents($r1['total_amount']) > 0, 'Initial assessment was not computed.');
    e2e_user('cashier', 'CASHIER');
    Cashier::pay($reg1, Money::cents($r1['total_amount']), 'CASH', null, bin2hex(random_bytes(16)), 'Full first-semester payment');
    e2e_ok(Registration::payStatus(Registration::get($reg1)) === 'PAID', 'Cashier payment did not settle the first registration.');

    // 3. Final grades → exact 2nd-semester completion tag.
    $docId = $doc['id'];
    e2e_second_semester_record($docId, $studentNumber, '2026-2027');
    e2e_user('registrar', 'REGISTRAR');
    e2e_ok(!GS::isTermComplete($docId, '2026-2027', '2nd Semester'), 'Student was complete before the registrar tagged the term.');
    $blocked = false;
    try { Registration::create(null, $studentNumber, $sections['2027-2028'], '1st Semester', $assessment); } catch (AppError $e) { $blocked = str_contains($e->getMessage(), 'not tagged complete'); }
    e2e_ok($blocked, 'Promotion/registration was not blocked before 2nd-semester completion.');
    e2e_tag($docId, '2026-2027', '2nd Semester');
    e2e_ok(GS::isTermComplete($docId, '2026-2027', '2nd Semester'), 'Exact 2nd-semester completion tag was not detected.');

    // 4. Registration handoff promotes the grading record to 2nd Year.
    [$reg2] = Registration::create(null, $studentNumber, $sections['2027-2028'], '1st Semester', $assessment);
    $doc = GS::studentByNumber($studentNumber);
    e2e_ok($doc['schoolYear'] === '2027-2028' && $doc['yearLevel'] === '2nd Year', 'Registration did not move the student to 2nd Year.');
    e2e_ok(($doc['promotionState'] ?? '') !== 'PROMOTED_PENDING_REGISTRATION', 'Promotion handoff marker was not cleared after registration.');

    // 5. Complete 2nd Year, then continue through 3rd Year and 4th Year.
    foreach ([['2027-2028', '2028-2029', '3rd Year'], ['2028-2029', '2029-2030', '4th Year']] as [$from, $to, $expectedLevel]) {
        $doc = GS::studentByNumber($studentNumber);
        e2e_second_semester_record($doc['id'], $studentNumber, $from);
        e2e_tag($doc['id'], $from, '2nd Semester');
        [$rid] = Registration::create(null, $studentNumber, $sections[$to], '1st Semester', $assessment);
        $doc = GS::studentByNumber($studentNumber);
        e2e_ok($doc['schoolYear'] === $to && $doc['yearLevel'] === $expectedLevel, "Registration did not promote the student from $from to $expectedLevel.");
        e2e_ok($rid > 0, "No continuing registration was created for $to.");
    }

    // 6. Final-year completion and graduation state used by Promote.
    $doc = GS::studentByNumber($studentNumber);
    e2e_second_semester_record($doc['id'], $studentNumber, '2029-2030');
    e2e_tag($doc['id'], '2029-2030', '2nd Semester');
    Db::q("UPDATE docs SET data = JSON_SET(data, '$.status', 'graduated', '$.statusNote', ?, '$.updatedAt', JSON_OBJECT('__ts', ?)) WHERE col = 'students' AND id = ?", ['Graduated, school year 2029-2030', gmdate('c'), $doc['id']]);
    $graduate = GS::get('students', $doc['id']);
    e2e_ok(($graduate['status'] ?? '') === 'graduated', 'Graduation status was not recorded.');
    e2e_ok(GS::isTermComplete($doc['id'], '2029-2030', '2nd Semester'), 'Final-year 2nd-semester completion was not retained.');

    $regs = (int) Db::val("SELECT COUNT(*) FROM sis_registrations WHERE student_number = ? AND status <> 'CANCELLED'", [$studentNumber]);
    $receipts = (int) Db::val("SELECT COUNT(*) FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id WHERE r.student_number = ? AND p.status = 'POSTED'", [$studentNumber]);
    echo "PASS: applicant → admission (Student ID $studentNumber) → 1st Year registration → Cashier payment → exact 2nd-semester completion → 2nd Year → 3rd Year → 4th Year → graduated.\n";
    echo "Registrations: $regs; posted receipts: $receipts; final status: {$graduate['status']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . " @ " . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
}
