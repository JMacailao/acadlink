<?php
/**
 * Demo data for testing (writes grading-system records too — never run on a live school database).
 * SIS accounts (password Password123): registrar, admission, cashier; student 20260001.
 */
declare(strict_types=1);

function sis_sample_data(): void
{
    if ((int) Db::val("SELECT COUNT(*) FROM docs WHERE col IN ('sections','students','subjects')") > 0) {
        throw new AppError('Sample data needs an empty grading database (it adds sections, subjects and students there). Use it only on a test database.');
    }
    $pw = password_hash('Password123', PASSWORD_DEFAULT);
    foreach ([['registrar', 'Rita Registrar', 'REGISTRAR'], ['admission', 'Ada Admission', 'ADMISSION'], ['cashier', 'Carlo Cashier', 'CASHIER']] as [$u, $n, $r]) {
        Db::q('INSERT IGNORE INTO sis_users (username, full_name, password_hash, role) VALUES (?, ?, ?, ?)', [$u, $n, $pw, $r]);
    }
    Auth::actAs((int) Db::val("SELECT id FROM sis_users WHERE username = 'admin'"));
    $sy = '2026-2027';
    // Grading-system records
    $sec = [];
    foreach ([['BSIT 1A', '1st Year'], ['BSIT 1B', '1st Year'], ['BSIT 2A', '2nd Year'], ['BSCS 1A', '1st Year']] as [$n, $y]) $sec[$n] = GS::saveSection(null, $sy, $y, $n);
    GS::saveSection(null, '2025-2026', '1st Year', 'BSIT 1A');
    $subj = [];
    foreach ([['IT101', 'Introduction to Computing', '3'], ['IT102', 'Computer Programming 1', '3'], ['GE1', 'Understanding the Self', '3'],
              ['GE2', 'Readings in Philippine History', '3'], ['MATH1', 'Mathematics in the Modern World', '3'], ['PE1', 'Physical Fitness', '2'],
              ['NSTP1', 'National Service Training Program 1', '3'], ['IT201', 'Data Structures', '3'], ['IT202', 'Discrete Mathematics', '3']] as [$c, $n, $u]) {
        $subj[$c] = GS::saveSubject(null, $c, $n, $u);
    }
    GS::saveCurriculum('BSIT', '1st Year', '1st Semester', [$subj['IT101'], $subj['GE1'], $subj['GE2'], $subj['MATH1'], $subj['PE1'], $subj['NSTP1']]);
    GS::saveCurriculum('BSIT', '2nd Year', '1st Semester', [$subj['IT201'], $subj['IT202'], $subj['GE2']]);
    GS::saveTeacher(null, '', 'Tomas Teacher');
    GS::saveTeacher(null, '', 'Maria Santos');
    $ana = GS::saveStudent(null, ['studentId' => '20250001', 'studentName' => 'Reyes, Ana M.', 'email' => 'ana@example.com', 'sectionId' => GS::sections('2025-2026')[0]['id']]);
    // Assessment: categories (seeded) → labels → names
    $cat = fn(string $n) => (int) Db::val('SELECT id FROM sis_fee_categories WHERE name = ?', [$n]);
    $lab = [];
    foreach ([['Tuition', 'Tuition Fee', 'PER_UNIT', '500.00'], ['Miscellaneous', 'Registration Fee', 'FIXED', '500.00'], ['Miscellaneous', 'Library Fee', 'FIXED', '300.00'],
              ['Miscellaneous', 'ID Card', 'FIXED', '150.00'], ['Miscellaneous', 'Medical / Dental', 'FIXED', '250.00'], ['Laboratory', 'Computer Laboratory', 'FIXED', '1500.00'],
              ['Other Fees', 'Insurance', 'FIXED', '100.00']] as [$c, $n, $t, $a]) {
        $lab[$n] = Assessment::saveLabel(null, ['category_id' => $cat($c), 'name' => $n, 'charge_type' => $t, 'default_amount' => $a, 'is_active' => 1]);
    }
    $n1 = Assessment::saveName(null, ['name' => "BSIT 1st Year 1st Semester $sy", 'program' => 'BSIT', 'year_level' => '1st Year', 'term' => '1st Semester', 'school_year' => $sy, 'is_active' => 1]);
    foreach ($lab as $label => $lid) {
        $l = Db::one('SELECT * FROM sis_fee_labels WHERE id = ?', [$lid]);
        Assessment::setItem($n1, $lid, $l['charge_type'], Money::cents($l['default_amount']));
    }
    $n2 = Assessment::copyName($n1, "BSIT 2nd Year 1st Semester $sy");
    Db::q("UPDATE sis_assessment_names SET year_level = '2nd Year' WHERE id = ?", [$n2]);
    Assessment::setItem($n2, $lab['Tuition Fee'], 'PER_UNIT', 55000);
    // Transaction: admission → registration → cashier
    $adm = Admission::create(Admission::validate(['applicant_type' => 'NEW', 'address' => '12 Rizal St., Brgy. Centro, Sample City', 'last_name' => 'Cruz', 'first_name' => 'Ben', 'middle_name' => 'Lopez', 'birth_date' => '2008-03-14',
        'sex' => 'M', 'email' => 'ben@example.com', 'phone' => '09171234567', 'program' => 'BSIT', 'year_level' => '1st Year', 'school_year' => $sy]));
    Admission::admit($adm);
    [$r1] = Registration::create($adm, null, $sec['BSIT 1A'], '1st Semester', null);
    [$r2] = Registration::create(null, '20250001', $sec['BSIT 2A'], '1st Semester', null);
    // Scholarships & discounts
    $acad = Discounts::save(null, ['name' => 'Academic Scholarship', 'kind' => 'SCHOLARSHIP', 'method' => 'PERCENT', 'value' => '50', 'categories' => [(string) $cat('Tuition')],
        'description' => 'GWA 1.75 or better in the previous term', 'is_active' => 1]);
    Discounts::save(null, ['name' => 'Sibling Discount', 'kind' => 'DISCOUNT', 'method' => 'PERCENT', 'value' => '10', 'description' => 'Two or more siblings enrolled', 'is_active' => 1]);
    Discounts::save(null, ['name' => 'Early Enrollment', 'kind' => 'DISCOUNT', 'method' => 'FIXED', 'value' => '500', 'is_active' => 1]);
    Discounts::give($r2, $acad, "Dean's lister, 2nd Semester 2025-2026");
    Auth::actAs((int) Db::val("SELECT id FROM sis_users WHERE username = 'cashier'"));
    Cashier::pay($r1, 500000, 'CASH', null, bin2hex(random_bytes(16)), 'Down payment');
    Cashier::pay($r2, 300000, 'GCASH', 'GC-0001', bin2hex(random_bytes(16)), null);
    Auth::actAs((int) Db::val("SELECT id FROM sis_users WHERE username = 'admin'"));
    Admission::create(Admission::validate(['applicant_type' => 'TRANSFEREE', 'address' => '12 Rizal St., Brgy. Centro, Sample City', 'last_name' => 'Tan', 'first_name' => 'Eva', 'previous_school' => 'Northville College',
        'program' => 'BSIT', 'year_level' => '2nd Year', 'school_year' => $sy]));
    $num = (string) Db::val('SELECT student_number FROM sis_admissions WHERE id = ?', [$adm]);
    Db::q('INSERT IGNORE INTO sis_users (username, full_name, password_hash, role, student_number) VALUES (?, ?, ?, ?, ?)', [$num, 'Ben Cruz', $pw, 'STUDENT', $num]);
    Auth::actAs(null);
}
