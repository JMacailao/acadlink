<?php
/**
 * SIS modules:
 *   Assessment   Categories (Tuition, Miscellaneous…) → Labels (fee lines) → Names (fee templates)
 *   Transaction  Admission → Registration (section, subjects, assessment) → Cashier (payments, receipts)
 * Money is in integer cents in PHP and DECIMAL(12,2) in MySQL; every write runs in a transaction.
 */
declare(strict_types=1);

final class Assessment
{
    // ------------------------------------------------------------ categories
    public static function categories(bool $activeOnly = false): array
    {
        return Db::all('SELECT c.*, (SELECT COUNT(*) FROM sis_fee_labels l WHERE l.category_id = c.id) AS labels FROM sis_fee_categories c'
            . ($activeOnly ? ' WHERE c.is_active = 1' : '') . ' ORDER BY c.sort_order, c.name');
    }

    public static function saveCategory(?int $id, array $in): int
    {
        Auth::require('assessment.edit');
        $d = ['name' => In::str($in, 'name', 80, true, 'Category name'), 'description' => In::str($in, 'description', 255),
            'sort_order' => In::int($in, 'sort_order', false, 'Order', 1, 999) ?? 100, 'is_active' => In::bool($in, 'is_active')];
        return self::save('sis_fee_categories', $id, $d, 'category');
    }

    // ------------------------------------------------------------ labels
    public static function labels(bool $activeOnly = false): array
    {
        return Db::all('SELECT l.*, c.name AS category_name, c.sort_order AS category_order FROM sis_fee_labels l JOIN sis_fee_categories c ON c.id = l.category_id'
            . ($activeOnly ? ' WHERE l.is_active = 1 AND c.is_active = 1' : '') . ' ORDER BY c.sort_order, c.name, l.name');
    }

    public static function saveLabel(?int $id, array $in): int
    {
        Auth::require('assessment.edit');
        $d = ['category_id' => In::int($in, 'category_id', true, 'Category'), 'name' => In::str($in, 'name', 100, true, 'Label'),
            'description' => In::str($in, 'description', 255), 'charge_type' => In::enum($in, 'charge_type', array_keys(CHARGE_TYPES), true, 'Charged'),
            'default_amount' => Money::dec((int) (In::money($in, 'default_amount', false, 'Default amount') ?? 0)), 'is_active' => In::bool($in, 'is_active')];
        if (!Db::val('SELECT 1 FROM sis_fee_categories WHERE id = ?', [$d['category_id']])) throw new AppError('Category not found.');
        return self::save('sis_fee_labels', $id, $d, 'label');
    }

    // ------------------------------------------------------------ names (fee templates)
    public static function names(bool $activeOnly = false): array
    {
        return Db::all('SELECT n.*, (SELECT COUNT(*) FROM sis_assessment_name_items i WHERE i.assessment_name_id = n.id) AS items FROM sis_assessment_names n'
            . ($activeOnly ? ' WHERE n.is_active = 1' : '') . ' ORDER BY n.name');
    }

    public static function saveName(?int $id, array $in): int
    {
        Auth::require('assessment.edit');
        $yl = In::str($in, 'year_level', 20);
        $term = In::str($in, 'term', 20);
        $sy = In::str($in, 'school_year', 9);
        if ($yl !== null && !in_array($yl, YEAR_LEVELS, true)) throw new AppError('Choose a year level.');
        if ($term !== null && !in_array($term, TERMS, true)) throw new AppError('Choose a term.');
        if ($sy !== null && !GS::validSchoolYear($sy)) throw new AppError('School year must look like 2026-2027.');
        $prog = In::str($in, 'program', 40);
        $d = ['name' => In::str($in, 'name', 120, true, 'Assessment name'), 'program' => $prog === null ? null : strtoupper($prog),
            'year_level' => $yl, 'term' => $term, 'school_year' => $sy, 'notes' => In::str($in, 'notes', 255), 'is_active' => In::bool($in, 'is_active')];
        return self::save('sis_assessment_names', $id, $d, 'assessment_name');
    }

    public static function items(int $nameId): array
    {
        return Db::all('SELECT i.*, l.name AS label_name, c.name AS category_name, c.sort_order FROM sis_assessment_name_items i
                         JOIN sis_fee_labels l ON l.id = i.label_id JOIN sis_fee_categories c ON c.id = l.category_id
                        WHERE i.assessment_name_id = ? ORDER BY c.sort_order, c.name, l.name', [$nameId]);
    }

    public static function setItem(int $nameId, int $labelId, string $chargeType, int $cents): void
    {
        Auth::require('assessment.edit');
        if (!isset(CHARGE_TYPES[$chargeType])) throw new AppError('Invalid charge type.');
        if ($cents < 0) throw new AppError('Amount cannot be negative.');
        if (!Db::val('SELECT 1 FROM sis_assessment_names WHERE id = ?', [$nameId])) throw new AppError('Assessment name not found.');
        if (!Db::val('SELECT 1 FROM sis_fee_labels WHERE id = ?', [$labelId])) throw new AppError('Label not found.');
        Db::q('INSERT INTO sis_assessment_name_items (assessment_name_id, label_id, charge_type, amount) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE charge_type = VALUES(charge_type), amount = VALUES(amount)', [$nameId, $labelId, $chargeType, Money::dec($cents)]);
        Audit::log('assessment_item_set', 'assessment_name', $nameId, ['label_id' => $labelId, 'type' => $chargeType, 'amount' => Money::dec($cents)]);
    }

    public static function removeItem(int $nameId, int $itemId): void
    {
        Auth::require('assessment.edit');
        Db::q('DELETE FROM sis_assessment_name_items WHERE id = ? AND assessment_name_id = ?', [$itemId, $nameId]);
        Audit::log('assessment_item_removed', 'assessment_name', $nameId, ['item_id' => $itemId]);
    }

    /** Copy an assessment name with all its fee lines (handy for the next year / term). */
    public static function copyName(int $nameId, string $newName): int
    {
        Auth::require('assessment.edit');
        return Db::tx(function () use ($nameId, $newName) {
            $n = Db::one('SELECT * FROM sis_assessment_names WHERE id = ?', [$nameId]);
            if (!$n) throw new AppError('Assessment name not found.');
            $newName = trim($newName);
            if ($newName === '' || mb_strlen($newName) > 120) throw new AppError('Enter the new name.');
            if (Db::val('SELECT 1 FROM sis_assessment_names WHERE name = ?', [$newName])) throw new AppError('That name is already used.');
            $id = Db::insert('sis_assessment_names', ['name' => $newName, 'program' => $n['program'], 'year_level' => $n['year_level'],
                'term' => $n['term'], 'school_year' => $n['school_year'], 'notes' => $n['notes'], 'is_active' => 1]);
            Db::q('INSERT INTO sis_assessment_name_items (assessment_name_id, label_id, charge_type, amount)
                   SELECT ?, label_id, charge_type, amount FROM sis_assessment_name_items WHERE assessment_name_id = ?', [$id, $nameId]);
            // v56: the copy charges the same ticked subject fees and the same own subject prices
            if (Db::val("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sis_assessment_name_subject_fees'"))
                Db::q('INSERT INTO sis_assessment_name_subject_fees (assessment_name_id, subject_fee_id) SELECT ?, subject_fee_id FROM sis_assessment_name_subject_fees WHERE assessment_name_id = ?', [$id, $nameId]);
            if (LecLab::ready('sis_subject_fees')) Db::q('INSERT INTO sis_subject_fees (subject_doc_id, assessment_name_id, label_id, rate, unit_basis, created_by) SELECT subject_doc_id, ?, label_id, rate, unit_basis, ? FROM sis_subject_fees WHERE assessment_name_id = ?', [$id, Auth::id(), $nameId]);
            else Db::q('INSERT INTO sis_subject_fees (subject_doc_id, assessment_name_id, label_id, rate, created_by) SELECT subject_doc_id, ?, label_id, rate, ? FROM sis_subject_fees WHERE assessment_name_id = ?', [$id, Auth::id(), $nameId]);
            Audit::log('assessment_name_copied', 'assessment_name', $id, ['from' => $nameId]);
            return $id;
        });
    }

    /** The best assessment name for a program / year level / term / school year (most specific wins). */
    public static function match(?string $program, string $yearLevel, string $term, string $schoolYear): ?array
    {
        return self::matchInfo($program, $yearLevel, $term, $schoolYear)['best'];
    }

    /**
     * match() plus what the registrar should know: 'ties' = other active names that fit just as well
     * (the first by name is used), and whether the chosen one has no fee lines.
     */
    public static function matchInfo(?string $program, string $yearLevel, string $term, string $schoolYear): array
    {
        $best = null;
        $bestScore = -1;
        $ties = [];
        foreach (self::names(true) as $n) {
            $score = 0;
            foreach (['program' => [$program, 8], 'year_level' => [$yearLevel, 4], 'term' => [$term, 2], 'school_year' => [$schoolYear, 1]] as $col => [$val, $w]) {
                if ($n[$col] === null) continue;
                if ((string) $n[$col] !== (string) $val) continue 2;
                $score += $w;
            }
            if ($score > $bestScore) { $best = $n; $bestScore = $score; $ties = []; }
            elseif ($score === $bestScore) $ties[] = $n['name'];
        }
        return ['best' => $best, 'ties' => $ties, 'empty' => $best !== null && !(int) $best['items']];
    }

    /**
     * Problems in the fee setup worth fixing before registrations use it:
     * active names with no fee lines, and active names that fit exactly the same students (only the first is ever chosen).
     */
    public static function setupProblems(): array
    {
        $out = [];
        $groups = [];
        foreach (self::names(true) as $n) {
            if (!(int) $n['items']) $out[] = '"' . $n['name'] . '" has no fee lines: students it matches get no fees.';
            $groups[implode('|', [$n['program'], $n['year_level'], $n['term'], $n['school_year']])][] = $n['name'];
        }
        foreach ($groups as $names) {
            if (count($names) > 1) $out[] = 'These names fit exactly the same students: "' . implode('", "', $names) . '". Automatic choice always takes "' . $names[0] . '": make one more specific or turn the others off.';
        }
        return $out;
    }

    /**
     * Fee lines for an assessment name: FIXED = amount, PER_UNIT = amount × units, PER_SUBJECT = amount × subjects.
     * Returns [ [category_name, label_name, charge_type, quantity, unit_cents, amount_cents, sort_order], … ]
     */
    public static function compute(int $nameId, float $units, int $subjectCount): array
    {
        $out = [];
        foreach (self::items($nameId) as $i) {
            $l = self::line($i, Money::cents($i['amount']), $units, $subjectCount);
            if ($l) $out[] = $l;
        }
        return $out;
    }

    /** One fee line: fixed = amount, per unit = amount × units, per subject = amount × subjects (null when it comes to ₱0). */
    public static function line(array $label, int $unit, float $units, int $subjectCount): ?array
    {
        [$qty, $amt] = match ($label['charge_type']) {
            'PER_UNIT' => [(string) $units, Money::times($unit, (string) $units)],
            'PER_SUBJECT' => [(string) $subjectCount, $unit * $subjectCount],
            default => ['1', $unit],
        };
        if ($amt <= 0) return null;
        return ['label_id' => (int) $label['label_id'], 'category_name' => $label['category_name'], 'label_name' => $label['label_name'], 'charge_type' => $label['charge_type'],
            'quantity' => $qty, 'unit_cents' => $unit, 'amount_cents' => $amt, 'sort_order' => (int) $label['sort_order']];
    }

    private static function save(string $table, ?int $id, array $d, string $entity): int
    {
        try {
            if ($id) {
                if (!Db::val("SELECT 1 FROM $table WHERE id = ?", [$id])) throw new AppError('Record not found.');
                Db::update($table, $d, $id);
            } else {
                $id = Db::insert($table, $d);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') throw new AppError('That name is already used.');
            throw $e;
        }
        Audit::log($entity . '_saved', $entity, $id, $d);
        return $id;
    }
}

final class Admission
{
    /** v56 student classification. Continuing students and returnees keep their Student ID. */
    public const TYPES = ['NEW' => 'New Student', 'CONTINUING' => 'Continuing Student', 'TRANSFEREE' => 'Transferee', 'UNIT_EARNER' => 'Unit Earner', 'RETURNEE' => 'Returnee (readmission)'];
    public const CLASSIFICATIONS = self::TYPES;
    public const KEEPS_ID = ['CONTINUING', 'RETURNEE'];

    public static function validate(array $in): array
    {
        $d = [
            'applicant_type' => In::enum($in, 'applicant_type', array_keys(self::TYPES), true, 'Student classification'),
            'last_name' => In::str($in, 'last_name', 80, true, 'Last name'), 'first_name' => In::str($in, 'first_name', 80, true, 'First name'),
            'middle_name' => In::str($in, 'middle_name', 80), 'birth_date' => In::date($in, 'birth_date', true, 'Birthday'),
            'sex' => In::enum($in, 'sex', ['M', 'F'], false), 'email' => In::str($in, 'email', 191), 'phone' => In::str($in, 'phone', 30),
            'address' => In::str($in, 'address', 255, true, 'Complete address'), 'guardian_name' => In::str($in, 'guardian_name', 150), 'guardian_phone' => In::str($in, 'guardian_phone', 30),
            'program' => ($p = In::str($in, 'program', 40)) === null ? null : strtoupper($p),
            'year_level' => In::enum($in, 'year_level', YEAR_LEVELS, true, 'Year level'), 'school_year' => In::str($in, 'school_year', 9, true, 'School year'),
            'previous_school' => In::str($in, 'previous_school', 150), 'remarks' => In::str($in, 'remarks', 255),
        ];
        if ($d['email'] !== null && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new AppError('Email address is not valid.');
        if (mb_strlen((string) $d['address']) < 5) throw new AppError('Complete address: enter the house number / street, barangay, town or city and province.');
        if ($d['birth_date'] > date('Y-m-d')) throw new AppError('Birthday cannot be in the future.');
        if (!GS::validSchoolYear($d['school_year'])) throw new AppError('School year must look like 2026-2027.');
        if ($d['applicant_type'] === 'TRANSFEREE' && !$d['previous_school']) throw new AppError('Enter the previous school of the transferee.');
        // A returnee asks for readmission with the Student ID they already have (no new ID)
        $d['student_number'] = null;
        if (in_array($d['applicant_type'], self::KEEPS_ID, true)) {
            $num = trim((string) ($in['student_number'] ?? ''));
            if ($num === '' || !GS::studentByNumber($num)) throw new AppError(self::TYPES[$d['applicant_type']] . ': enter the Student ID they already have (it must be in the grading system).');
            $d['student_number'] = $num;
        }
        return $d;
    }

    public static function create(array $d): int
    {
        Auth::require('admission');
        return self::createRecord($d);
    }

    private static function createRecord(array $d): int
    {
        return Db::tx(function () use ($d) {
            // Rejected and cancelled applications remain historical records; only active applications block a new applicant.
            $dup = Db::one("SELECT admission_no, status FROM sis_admissions WHERE last_name = ? AND first_name = ? AND birth_date <=> ? AND status IN ('PENDING','ADMITTED')",
                [$d['last_name'], $d['first_name'], $d['birth_date']]);
            if ($dup) throw new AppError('This applicant is already recorded (' . $dup['admission_no'] . ', ' . strtolower((string) $dup['status']) . '). Cancel or complete the existing active admission before creating another one.');
            $d['admission_no'] = 'ADM-' . substr($d['school_year'], 0, 4) . '-' . str_pad((string) Seq::next('admission'), 5, '0', STR_PAD_LEFT);
            $d['created_by'] = Auth::id();
            $id = Db::insert('sis_admissions', $d);
            Audit::log('admission_created', 'admission', $id, ['admission_no' => $d['admission_no']]);
            return $id;
        });
    }

    /** Applicant ownership is attached in the same transaction; no self-approval. */
    public static function applyOwn(array $in): int
    {
        Auth::require('self');
        $c=Auth::central(); $aid=(int)Auth::centralId();
        if (!$c->can($aid,'portal.admission.apply')) throw new Forbidden('Admission applications are not enabled for your account.');
        return Db::tx(function() use($in,$c,$aid) {
            Db::q('SELECT id FROM central_accounts WHERE id=? FOR UPDATE',[$aid]);
            $existing=Db::val("SELECT a.id FROM central_record_owners o JOIN sis_admissions a ON a.id=CAST(o.entity_key AS UNSIGNED) WHERE o.workspace='SIS' AND o.entity_type='admission' AND o.account_id=? AND a.status='PENDING'",[$aid]);
            if ($existing) throw new AppError('You already have a pending application. Wait for the registrar to review it.');
            $in['student_number']=Auth::studentNumber();
            if ($in['student_number']) { if (!in_array($in['applicant_type']??'',self::KEEPS_ID,true)) $in['applicant_type']='RETURNEE'; }
            elseif (!in_array($in['applicant_type']??'',['NEW','TRANSFEREE','UNIT_EARNER'],true)) throw new AppError('Choose New Student, Transferee or Unit Earner. Existing students must be linked by staff first.');
            $d=self::validate($in);
            $term=In::enum($in,'application_term',TERMS,true,'Term');
            $calendar=TermCalendar::get($d['school_year'],$term);
            if (!$calendar || date('Y-m-d')<$calendar['enrollment_start'] || date('Y-m-d')>$calendar['enrollment_end']) throw new AppError('Applications are accepted only during an open enrollment period.');
            $d['application_term']=$term;
            $id=self::createRecord($d);
            Db::insert('central_record_owners',['workspace'=>'SIS','entity_type'=>'admission','entity_key'=>(string)$id,'account_id'=>$aid]);
            $c->audit($aid,$aid,'admission_application_submitted',['admission_id'=>$id]);
            return $id;
        });
    }

    public static function update(int $id, array $d): void
    {
        Auth::require('admission');
        Db::tx(function () use ($id, $d) {
            $a = Db::one('SELECT * FROM sis_admissions WHERE id = ? FOR UPDATE', [$id]);
            if (!$a) throw new AppError('Admission not found.');
            if ($a['status'] !== 'PENDING') throw new AppError('Only a pending admission can be edited.');
            Db::update('sis_admissions', $d, $id);
            Audit::log('admission_updated', 'admission', $id);
        });
    }

    /**
     * Admit: gives the applicant a Student ID (next number of the grading system).
     * A returnee's readmission request is approved instead: they keep the Student ID they already have.
     */
    public static function admit(int $id): string
    {
        Auth::require('admission.approve');
        return Db::tx(function () use ($id) {
            $a = Db::one('SELECT * FROM sis_admissions WHERE id = ? FOR UPDATE', [$id]);
            if (!$a || $a['status'] !== 'PENDING') throw new AppError('Only a pending admission can be admitted.');
            if (in_array($a['applicant_type'], self::KEEPS_ID, true)) {
                if (!$a['student_number'] || !GS::studentByNumber((string) $a['student_number'])) throw new AppError('This application has no existing Student ID. Edit it and enter the Student ID.');
                Db::q("UPDATE sis_admissions SET status = 'ADMITTED', decided_by = ?, decided_at = NOW() WHERE id = ?", [Auth::id(), $id]);
                Audit::log('readmission_approved', 'admission', $id, ['student_number' => $a['student_number']]);
                return (string) $a['student_number'];
            }
            Db::val("SELECT COUNT(*) FROM docs WHERE col = 'students' FOR UPDATE");
            $number = GS::nextStudentNumber($a['school_year']);
            Db::q("UPDATE sis_admissions SET status = 'ADMITTED', student_number = ?, decided_by = ?, decided_at = NOW() WHERE id = ?", [$number, Auth::id(), $id]);
            Audit::log('admission_admitted', 'admission', $id, ['student_number' => $number]);
            return $number;
        });
    }

    public static function reject(int $id, string $reason): void
    {
        Auth::require('admission.approve');
        if (trim($reason) === '') throw new AppError('Give the reason.');
        Db::tx(function () use ($id, $reason) {
            $a = Db::one('SELECT * FROM sis_admissions WHERE id = ? FOR UPDATE', [$id]);
            if (!$a || $a['status'] !== 'PENDING') throw new AppError('Only a pending admission can be rejected.');
            Db::q("UPDATE sis_admissions SET status = 'REJECTED', remarks = ?, decided_by = ?, decided_at = NOW() WHERE id = ?", [$reason, Auth::id(), $id]);
            Audit::log('admission_rejected', 'admission', $id, ['reason' => $reason]);
        });
    }

    /** Cancel an admitted applicant before registration; keep the record for audit. */
    public static function cancel(int $id, string $reason): void
    {
        Auth::require('admission.approve');
        if (trim($reason) === '') throw new AppError('Give the reason for cancelling the admission.');
        Db::tx(function () use ($id, $reason) {
            $a = Db::one('SELECT * FROM sis_admissions WHERE id = ? FOR UPDATE', [$id]);
            if (!$a || $a['status'] !== 'ADMITTED') throw new AppError('Only an admitted applicant can be cancelled.');
            $active = Db::val("SELECT id FROM sis_registrations WHERE student_number = ? AND status <> 'CANCELLED' ORDER BY id DESC LIMIT 1", [$a['student_number']]);
            if ($active) throw new AppError('This student already has an active registration. Void the registration first before cancelling the admission.');
            Db::q("UPDATE sis_admissions SET status = 'CANCELLED', remarks = ?, decided_by = ?, decided_at = NOW() WHERE id = ?", [mb_substr(trim($reason), 0, 255), Auth::id(), $id]);
            Audit::log('admission_cancelled', 'admission', $id, ['reason' => $reason, 'student_number' => $a['student_number']]);
        });
    }

    /** "Dela Cruz, Juan P." — the grading system's name format */
    public static function gradingName(array $a): string
    {
        $mi = $a['middle_name'] ? ' ' . mb_strtoupper(mb_substr($a['middle_name'], 0, 1)) . '.' : '';
        return $a['last_name'] . ', ' . $a['first_name'] . $mi;
    }
}

final class Registration
{
    public static function get(int $id): array
    {
        $r = Db::one('SELECT r.*, n.name AS assessment_name FROM sis_registrations r LEFT JOIN sis_assessment_names n ON n.id = r.assessment_name_id WHERE r.id = ?', [$id]);
        if (!$r) throw new AppError('Registration not found.');
        return $r;
    }

    public static function balance(array $r): int
    {
        return Money::cents($r['total_amount']) - Money::cents($r['amount_paid']);
    }

    /** OPEN (can be changed), DONE (view only) or VOIDED (view only). */
    public static function state(array $r): string
    {
        if ($r['status'] === 'CANCELLED') return 'VOIDED';
        return empty($r['locked_at']) ? 'OPEN' : 'DONE';
    }

    public static function payStatus(array $r): string
    {
        if ($r['status'] === 'CANCELLED') return 'VOIDED';
        $paid = Money::cents($r['amount_paid']);
        if ($paid <= 0) return Money::cents($r['total_amount']) > 0 ? 'UNPAID' : 'PAID';
        return self::balance($r) > 0 ? 'PARTIAL' : 'PAID';
    }

    /**
     * Register a student for a term: admitted applicant ($admissionId) or an existing grading-system
     * student ($studentNumber). The section decides school year, year level and program.
     * Returns [registration id, notes].
     */
    /**
     * What stops a student from registering for a term (flowchart "Student type?"): previous-term final grades not
     * tagged complete, a returning student without an approved readmission, or enrollment closed for the term.
     * Returns a list of messages (empty = may register). An administrator can still register with a reason.
     */
    public static function holds(string $studentDocId, string $studentNumber, string $schoolYear, string $term, ?array $admission = null): array
    {
        $out = [];
        $prev = GS::previousTerm($studentDocId, $studentNumber, $schoolYear, $term);
        if ($prev && !GS::isTermComplete($studentDocId, $prev['schoolYear'], $prev['term'])) {
            $out[] = 'The final grades of ' . $prev['schoolYear'] . ' ' . ($prev['term'] !== '' ? $prev['term'] : '(no term)') .
                ' are not tagged complete yet. The registrar tags them on the grading system\'s Final grades page (resolve missing or INC grades first).';
        }
        if (self::studentType($studentDocId, $studentNumber, $schoolYear, $term)['key'] === 'RETURNING') {
            $ok = ($admission && $admission['applicant_type'] === 'RETURNEE' && $admission['status'] === 'ADMITTED')
               || Db::val("SELECT 1 FROM sis_admissions WHERE applicant_type = 'RETURNEE' AND status = 'ADMITTED' AND student_number = ? AND school_year = ?", [$studentNumber, $schoolYear]);
            if (!$ok) $out[] = 'Returning student: their readmission request for ' . $schoolYear . ' must be approved first (Admission → New, type Returnee, with the Student ID; then Approve).';
        }
        if ($c = TermCalendar::get($schoolYear, $term)) {
            $today = date('Y-m-d');
            if ($today < $c['enrollment_start'] || $today > $c['enrollment_end']) {
                $out[] = "Enrollment for $schoolYear $term is open from " . date('M j, Y', strtotime($c['enrollment_start'])) . ' to ' . date('M j, Y', strtotime($c['enrollment_end'])) . '.';
            }
        }
        if ($balanceHold = BalanceEnrollment::hold($studentNumber, $schoolYear, $term)) $out[] = $balanceHold;
        return $out;
    }

    public static function create(?int $admissionId, ?string $studentNumber, string $sectionId, string $term, ?int $nameId, ?array $subjectIds = null, array $extraLabelIds = [], ?string $override = null, bool $deferPlacement = false): array
    {
        Auth::require('registration');
        $override = $override !== null && trim($override) !== '' ? mb_substr(trim($override), 0, 255) : null;
        if ($override !== null && !Auth::can('users.manage')) throw new Forbidden('Only an administrator can register a student a rule would block.');
        if (!in_array($term, TERMS, true)) throw new AppError('Choose the term.');
        $section = GS::get('sections', $sectionId);
        if (!$section) throw new AppError('Choose a section.');
        $sy = (string) $section['schoolYear'];
        $level = (string) $section['yearLevel'];
        $program = GS::programOf((string) $section['sectionName']) ?: null;
        $notes = [];
        return Db::tx(function () use ($admissionId, $studentNumber, $section, $sy, $level, $program, $term, $nameId, $subjectIds, $extraLabelIds, $override, $deferPlacement, &$notes) {
            // 1. The student record in the grading system
            $a = null;
            if ($admissionId) {
                $a = Db::one('SELECT * FROM sis_admissions WHERE id = ? FOR UPDATE', [$admissionId]);
                if (!$a || $a['status'] !== 'ADMITTED') throw new AppError('Only an admitted applicant can be registered.');
                $doc = GS::studentByNumber((string) $a['student_number']);
                if (!$doc) {
                    $docId = GS::saveStudent(null, ['studentId' => $a['student_number'], 'studentName' => Admission::gradingName($a), 'email' => (string) $a['email'], 'sectionId' => $section['id']]);
                    $notes[] = 'Student ' . $a['student_number'] . ' added to the grading system in ' . $section['sectionName'] . '.';
                }
            } else {
                $doc = $studentNumber !== null ? GS::studentByNumber(trim($studentNumber)) : null;
                if (!$doc) throw new AppError('No student with that Student ID in the grading system.');
            }
            if ($doc) { // an existing student record (continuing, or a returnee whose readmission was approved)
                $docId = $doc['id'];
                Db::one("SELECT id FROM docs WHERE col = 'students' AND id = ? FOR UPDATE", [$docId]);
                if (($doc['schoolYear'] ?? '') !== $sy) {
                    // New school year: move the student's record forward, as Promote does in the grading system
                    if (!$deferPlacement) GS::saveStudent($docId, ['studentId' => $doc['studentId'], 'studentName' => $doc['studentName'], 'email' => $doc['email'] ?? '', 'sectionId' => $section['id']]);
                    $notes[] = ($deferPlacement ? 'Placement reserved until enrollment confirmation: ' : 'Student moved to ') . $section['sectionName'] . " ($sy) in the grading system.";
                } elseif (($doc['sectionId'] ?? '') !== $section['id'] && ($doc['promotionState'] ?? '') === 'PROMOTED_PENDING_REGISTRATION') {
                    // Promotion already selected the student's section for this
                    // school year. Do not create a SIS registration in another
                    // section while leaving the shared grading record behind.
                    // That would make the SIS and grading class disagree.
                    throw new AppError('This student is already assigned to ' . ($doc['sectionName'] ?? 'another section') . ' for ' . $sy . '. Select that promoted section, or change the student\'s section first in Grading System → Students.');
                }
            }
            $doc = GS::get('students', $docId);
            // 2. Subjects: the curriculum of the program, year level and term (or the ones chosen)
            if ($subjectIds === null) {
                $cur = $program ? GS::curriculumFor($program, $level, $term) : null;
                $subjectIds = $cur['subjectIds'] ?? [];
                if (!$cur) $notes[] = "No curriculum for $program $level $term: add the subjects below.";
            }
            $eligibility = EnrollmentEligibility::check($docId, (string) $doc['studentId'], $sy, $term, array_values($subjectIds), null, $a, $override);
            EnrollmentEligibility::assertAllowed($eligibility, true);
            $holds = array_column(array_filter($eligibility['checks'], fn($c) => $c['state'] === 'OVERRIDDEN'), 'message');
            // 3. Assessment name
            if (!$nameId) {
                $mi = Assessment::matchInfo($program, $level, $term, $sy);
                $m = $mi['best'];
                $nameId = $m ? (int) $m['id'] : null;
                if (!$m) $notes[] = 'No assessment name matches this program, year level and term: choose one with Assessment name.';
                elseif ($mi['ties']) $notes[] = 'Assessment name "' . $m['name'] . '" was used; "' . implode('", "', $mi['ties']) . '" fit(s) just as well. Check the fees.';
                if ($m && $mi['empty']) $notes[] = 'Assessment name "' . $m['name'] . '" has no fee lines yet: the assessment is ₱0 until fees are added.';
            }
            $no = 'REG-' . substr($sy, 0, 4) . '-' . str_pad((string) Seq::next('registration'), 6, '0', STR_PAD_LEFT);
            $id = Db::insert('sis_registrations', ['reg_no' => $no, 'student_number' => $doc['studentId'], 'student_doc_id' => $docId,
                'student_name' => $doc['studentName'], 'school_year' => $sy, 'term' => $term, 'year_level' => $level,
                'section_doc_id' => $section['id'], 'section_name' => $section['sectionName'], 'program' => $program,
                'assessment_name_id' => $nameId, 'created_by' => Auth::id(), 'override_reason' => $holds ? $override : null]);
            if ($holds) Audit::log('registration_override', 'registration', $id, ['reason' => $override, 'rules' => $holds]);
            self::writeSubjects($id, $subjectIds);
            EnrollmentEligibility::record($id, 'CREATED', $eligibility);
            foreach (array_unique(array_map('intval', $extraLabelIds)) as $lid) {
                if (!Db::val('SELECT 1 FROM sis_fee_labels WHERE id = ? AND is_active = 1', [$lid])) throw new AppError('A chosen fee label was not found or is not active.');
                Db::insert('sis_registration_labels', ['registration_id' => $id, 'label_id' => $lid, 'mode' => 'ADD', 'unit_amount' => null]);
            }
            self::recompute($id);
            // The promoted student has now been handed off to a term-specific
            // SIS registration. Clear the one-time marker, while preserving
            // the shared student record and Student ID.
            if (!$deferPlacement) GS::clearPromotion($docId);
            Audit::log('registration_created', 'registration', $id, ['reg_no' => $no, 'student' => $doc['studentId'], 'term' => "$sy $term"]);
            return [$id, $notes];
        });
    }

    private static function writeSubjects(int $id, array $subjectIds): void
    {
        Db::q('DELETE FROM sis_registration_subjects WHERE registration_id = ?', [$id]);
        foreach (array_unique($subjectIds) as $sid) {
            $s = GS::get('subjects', (string) $sid);
            if (!$s) continue;
            [$lec, $lab] = LecLab::ofSubject($s); // v56.2: keeps the lecture / laboratory split of the subject
            Db::insert('sis_registration_subjects', ['registration_id' => $id, 'subject_doc_id' => $s['id'], 'subject_code' => $s['subjectCode'] ?? null,
                'subject_name' => (string) $s['subjectName'], 'units' => (float) $s['units']] + (LecLab::ready('sis_registration_subjects') ? ['lec_units' => $lec, 'lab_units' => $lab] : []));
        }
    }

    private static function lockOpen(int $id): array
    {
        $r = Db::one('SELECT * FROM sis_registrations WHERE id = ? FOR UPDATE', [$id]);
        if (!$r) throw new AppError('Registration not found.');
        if ($r['status'] === 'CANCELLED') throw new AppError('This registration is voided. It can only be viewed.');
        if (!empty($r['locked_at'])) throw new AppError('This registration is done. It can only be viewed.');
        return $r;
    }

    /**
     * Build the assessment from the CURRENT fee setup without saving it: the assessment name's fee lines,
     * minus the labels taken off for this student, plus the labels added for this student (an added label
     * replaces the template's line for it), the saved adjustments (charges / less), then scholarships and
     * discounts (computed on the fee lines, never more than what is left).
     * Returns ['units', 'subjects', 'lines' (fee lines), 'adjust_cents', 'discounts' ([label, cents]), 'fees_cents', 'total_cents'].
     */
    public static function build(array $r): array
    {
        $id = (int) $r['id'];
        $subs = Db::all('SELECT * FROM sis_registration_subjects WHERE registration_id = ?', [$id]);
        $units = array_sum(array_map(fn($s) => (float) $s['units'], $subs));
        $lines = $r['assessment_name_id'] ? Assessment::compute((int) $r['assessment_name_id'], $units, count($subs)) : [];
        $chosen = self::chosenLabels($id);
        $lines = array_values(array_filter($lines, fn($l) => !isset($chosen[$l['label_id']])));
        foreach ($chosen as $c) {
            if ($c['mode'] !== 'ADD') continue;
            $l = Assessment::line($c, $c['unit_amount'] !== null ? Money::cents($c['unit_amount']) : Money::cents($c['default_amount']), $units, count($subs));
            if ($l) $lines[] = $l;
        }
        $lines = array_merge($lines, SubjectFees::lines($subs, $r['assessment_name_id'] ? (int) $r['assessment_name_id'] : null)); // fees of particular subjects, × their units
        usort($lines, fn($a, $b) => $a['sort_order'] <=> $b['sort_order'] ?: strcmp($a['label_name'], $b['label_name']));
        $fees = array_sum(array_column($lines, 'amount_cents'));
        $adjust = Money::cents((string) Db::val("SELECT COALESCE(SUM(amount), 0) FROM sis_registration_items WHERE registration_id = ? AND charge_type = 'ADJUSTMENT'", [$id]));
        $left = $fees + $adjust;
        $discounts = [];
        foreach (Discounts::linesFor($id, $lines) as $d) {
            $off = min($d['cents'], max(0, $left));
            if ($off <= 0) continue;
            $left -= $off;
            $discounts[] = ['label' => $d['label'], 'cents' => $off, 'discount_id' => $d['discount_id'] ?? null];
        }
        return ['units' => $units, 'subjects' => count($subs), 'lines' => $lines, 'adjust_cents' => $adjust, 'discounts' => $discounts,
            'fees_cents' => $fees, 'total_cents' => $fees + $adjust - array_sum(array_column($discounts, 'cents'))];
    }

    /** Recompute and save the assessment (see build()). Open registrations only. */
    public static function recompute(int $id): void
    {
        Db::tx(function () use ($id) {
            $r = self::lockOpen($id);
            LecLab::refreshRegistration($id); // v56.2: open assessments follow the subjects' lecture / laboratory units
            $b = self::build($r);
            if ($b['total_cents'] < 0) throw new AppError('Adjustments make the total negative.');
            if ($b['total_cents'] < Money::cents($r['amount_paid'])) throw new AppError('The new total (₱' . Money::fmt($b['total_cents']) . ') is less than what was already paid. Void a payment first.');
            Db::q("DELETE FROM sis_registration_items WHERE registration_id = ? AND charge_type <> 'ADJUSTMENT'", [$id]);
            foreach ($b['lines'] as $l) {
                Db::insert('sis_registration_items', ['registration_id' => $id, 'category_name' => $l['category_name'], 'label_name' => $l['label_name'],
                    'charge_type' => $l['charge_type'], 'quantity' => $l['quantity'], 'unit_amount' => Money::dec($l['unit_cents']),
                    'amount' => Money::dec($l['amount_cents']), 'sort_order' => $l['sort_order']]);
            }
            foreach ($b['discounts'] as $d) {
                Db::insert('sis_registration_items', ['registration_id' => $id, 'category_name' => Discounts::CATEGORY, 'label_name' => mb_substr($d['label'], 0, 100),
                    'charge_type' => 'DISCOUNT', 'quantity' => 1, 'unit_amount' => Money::dec(-$d['cents']), 'amount' => Money::dec(-$d['cents']), 'sort_order' => 950]);
            }
            Db::q('UPDATE sis_registrations SET total_units = ?, total_amount = ? WHERE id = ?', [$b['units'], Money::dec($b['total_cents']), $id]);
            PaymentPlans::sync($id);
        });
    }

    /**
     * New, transferee, returning or continuing, from what is already recorded: the admission (if any)
     * and the student's earlier terms (grading-system classes and SIS registrations).
     * Returns ['key' => NEW|TRANSFEREE|RETURNING|CONTINUING, 'label', 'detail'].
     */
    public static function studentType(?string $studentDocId, string $studentNumber, string $schoolYear, string $term): array
    {
        $prev = $studentDocId ? GS::previousTerm($studentDocId, $studentNumber, $schoolYear, $term) : null;
        if (!$prev) {
            $type = (string) Db::val("SELECT applicant_type FROM sis_admissions WHERE student_number = ? AND status = 'ADMITTED' ORDER BY id DESC LIMIT 1", [$studentNumber]);
            return match ($type) {
                'TRANSFEREE' => ['key' => 'TRANSFEREE', 'label' => 'Transferee', 'detail' => 'Admitted as a transferee; first term here.'],
                'RETURNEE' => ['key' => 'RETURNING', 'label' => 'Returning student', 'detail' => 'Admitted as a returnee.'],
                'CONTINUING' => ['key' => 'CONTINUING', 'label' => 'Continuing student', 'detail' => 'Classified as continuing at admission.'],
                'UNIT_EARNER' => ['key' => 'UNIT_EARNER', 'label' => 'Unit Earner', 'detail' => 'Admitted as a unit earner.'],
                default => ['key' => 'NEW', 'label' => 'New student', 'detail' => 'No earlier term on record.'],
            };
        }
        $last = $prev['schoolYear'] . ' ' . ($prev['term'] !== '' ? $prev['term'] : '(whole year)');
        // v56: a unit earner stays a unit earner in later terms
        if (Db::val("SELECT applicant_type FROM sis_admissions WHERE student_number = ? AND status = 'ADMITTED' ORDER BY id DESC LIMIT 1", [$studentNumber]) === 'UNIT_EARNER')
            return ['key' => 'UNIT_EARNER', 'label' => 'Unit Earner', 'detail' => "Previous term: $last."];
        // A whole school year or more without classes = returning; otherwise continuing
        $gap = (int) substr($schoolYear, 0, 4) - (int) substr($prev['schoolYear'], 0, 4);
        return $gap >= 2
            ? ['key' => 'RETURNING', 'label' => 'Returning student', 'detail' => "Last enrolled $last."]
            : ['key' => 'CONTINUING', 'label' => 'Continuing student', 'detail' => "Previous term: $last."];
    }

    /**
     * "Assessment correct?" — the checks shown before Confirm Enrollment.
     * Each check: ['level' => error|warn|ok|info, 'text']. Errors block; warnings must be confirmed.
     * Also returns the totals that confirming will save (from the current fee setup).
     */
    public static function review(int $id): array
    {
        $r = self::get($id);
        $b = self::build($r);
        $checks = [];
        $add = function (string $level, string $text) use (&$checks) { $checks[] = ['level' => $level, 'text' => $text]; };
        // Student and previous term
        $type = self::studentType($r['student_doc_id'], $r['student_number'], $r['school_year'], $r['term']);
        $add('info', $type['label'] . '. ' . $type['detail']);
        $prev = GS::previousTerm($r['student_doc_id'], $r['student_number'], $r['school_year'], $r['term']);
        if ($prev) {
            $label = $prev['schoolYear'] . ' ' . ($prev['term'] !== '' ? $prev['term'] : '(whole year)');
            GS::isTermComplete($r['student_doc_id'], $prev['schoolYear'], $prev['term'])
                ? $add('ok', "Final grades of $label are tagged complete.")
                : $add('warn', "Final grades of $label are no longer tagged complete in the grading system.");
        }
        // Subjects
        $subIds = Db::col('SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ?', [$id]);
        if (!$subIds) $add('error', 'No subjects yet: choose the curriculum or tick the subjects.');
        else {
            $cur = GS::curriculumMatching($subIds);
            $add($cur ? 'ok' : 'info', count($subIds) . ' subject(s), ' . rtrim(rtrim(number_format((float) $b['units'], 1), '0'), '.') . ' units' .
                ($cur ? ' — curriculum ' . trim(($cur['program'] ?? '') . ' · ' . ($cur['yearLevel'] ?? '') . ' · ' . ($cur['term'] ?? ''), ' ·') . '.' : ' — picked by hand (not a whole curriculum).'));
        }
        // Assessment name (fee template)
        if ($r['assessment_name_id']) {
            $n = Db::one('SELECT * FROM sis_assessment_names WHERE id = ?', [$r['assessment_name_id']]);
            $misfit = [];
            foreach (['program' => $r['program'], 'year_level' => $r['year_level'], 'term' => $r['term'], 'school_year' => $r['school_year']] as $col => $val) {
                if ($n[$col] !== null && (string) $n[$col] !== (string) $val) $misfit[] = str_replace('_', ' ', $col) . ' ' . $n[$col];
            }
            if ($misfit) $add('warn', 'Assessment name "' . $n['name'] . '" is for ' . implode(', ', $misfit) . ', not the student\'s ' . trim(($r['program'] ?? '') . ' ' . $r['year_level'] . ' ' . $r['term'] . ' ' . $r['school_year']) . '.');
            elseif (!(int) $n['is_active']) $add('warn', 'Assessment name "' . $n['name'] . '" is no longer active.');
            else $add('ok', 'Assessment name: ' . $n['name'] . '.');
        } elseif ($b['lines']) {
            $add('info', 'No assessment name: only the fees added for this student are charged.');
        }
        if (!$b['lines'] && !$b['adjust_cents']) $add('warn', 'No fees: the assessment is ₱0.00. Choose an assessment name or add a fee if the student should pay.');
        // Fee setup changed since the assessment was saved?
        $saved = Money::cents($r['total_amount']);
        if ($saved !== $b['total_cents']) $add('info', 'The fee setup changed since this assessment was made: total ₱' . Money::fmt($saved) . ' becomes ₱' . Money::fmt($b['total_cents']) . ' when you confirm.');
        if ($b['discounts']) $add('ok', count($b['discounts']) . ' scholarship / discount applied: −₱' . Money::fmt(array_sum(array_column($b['discounts'], 'cents'))) . '.');
        $paid = Money::cents($r['amount_paid']);
        // The balance never stops the confirmation: the cashier collects it later (down payment, installments)
        $owed = $b['total_cents'] - $paid;
        if ($owed > 0) $add('info', 'Balance ₱' . Money::fmt($owed) . ' is not settled yet' . ($paid > 0 ? ' (₱' . Money::fmt($paid) . ' paid)' : '') . '. You can still confirm the enrollment; the cashier collects the balance later.');
        elseif ($b['total_cents'] > 0) $add('ok', 'Fully paid.');
        if ($b['total_cents'] < $paid) $add('error', 'The total (₱' . Money::fmt($b['total_cents']) . ') would be less than what was already paid (₱' . Money::fmt($paid) . '). The cashier must void a payment first.');
        $levels = array_column($checks, 'level');
        return ['checks' => $checks, 'build' => $b, 'paid_cents' => $paid, 'balance_cents' => $b['total_cents'] - $paid,
            'errors' => count(array_keys($levels, 'error', true)), 'warnings' => count(array_keys($levels, 'warn', true)), 'type' => $type];
    }

    /** Labels added to / taken off this registration, by label id. */
    public static function chosenLabels(int $id): array
    {
        $out = [];
        foreach (Db::all('SELECT x.*, x.label_id, l.name AS label_name, l.charge_type, l.default_amount, c.name AS category_name, c.sort_order
                            FROM sis_registration_labels x JOIN sis_fee_labels l ON l.id = x.label_id JOIN sis_fee_categories c ON c.id = l.category_id
                           WHERE x.registration_id = ?', [$id]) as $row) $out[(int) $row['label_id']] = $row;
        return $out;
    }

    /** Charge a fee label to this student ($cents null = the label's default amount). */
    public static function chargeLabel(int $id, int $labelId, ?int $cents): void
    {
        Auth::require('registration');
        $label = Db::one('SELECT * FROM sis_fee_labels WHERE id = ? AND is_active = 1', [$labelId]);
        if (!$label) throw new AppError('Choose a fee label.');
        if ($cents !== null && $cents < 0) throw new AppError('The amount can\'t be negative. For a discount, use Less / discount.');
        Db::tx(function () use ($id, $labelId, $cents, $label) {
            self::lockOpen($id);
            Db::q("INSERT INTO sis_registration_labels (registration_id, label_id, mode, unit_amount) VALUES (?, ?, 'ADD', ?)
                   ON DUPLICATE KEY UPDATE mode = 'ADD', unit_amount = VALUES(unit_amount)", [$id, $labelId, $cents === null ? null : Money::dec($cents)]);
            self::recompute($id);
            Audit::log('registration_label_charged', 'registration', $id, ['label' => $label['name'], 'amount' => $cents === null ? 'default' : Money::dec($cents)]);
        });
    }

    /** Take a fee off this student's assessment (a template fee is marked "not charged"; an added one is removed). */
    public static function removeLabel(int $id, int $labelId): void
    {
        Auth::require('registration');
        Db::tx(function () use ($id, $labelId) {
            $r = self::lockOpen($id);
            $inTemplate = $r['assessment_name_id'] && Db::val('SELECT 1 FROM sis_assessment_name_items WHERE assessment_name_id = ? AND label_id = ?', [$r['assessment_name_id'], $labelId]);
            if ($inTemplate) {
                Db::q("INSERT INTO sis_registration_labels (registration_id, label_id, mode, unit_amount) VALUES (?, ?, 'EXCLUDE', NULL)
                       ON DUPLICATE KEY UPDATE mode = 'EXCLUDE', unit_amount = NULL", [$id, $labelId]);
            } else {
                Db::q('DELETE FROM sis_registration_labels WHERE registration_id = ? AND label_id = ?', [$id, $labelId]);
            }
            self::recompute($id);
            Audit::log('registration_label_removed', 'registration', $id, ['label_id' => $labelId]);
        });
    }

    /** Back to the template's amount for this label (undoes a removal or a changed amount). */
    public static function resetLabel(int $id, int $labelId): void
    {
        Auth::require('registration');
        Db::tx(function () use ($id, $labelId) {
            self::lockOpen($id);
            Db::q('DELETE FROM sis_registration_labels WHERE registration_id = ? AND label_id = ?', [$id, $labelId]);
            self::recompute($id);
            Audit::log('registration_label_reset', 'registration', $id, ['label_id' => $labelId]);
        });
    }

    /**
     * Save the assessment name and subjects, then recompute. $applyName = the assessment name was (re)chosen:
     * its fees are charged again in full, so fees taken off this student earlier come back
     * (fees added by hand and changed amounts stay).
     */
    public static function update(int $id, ?int $nameId, array $subjectIds, bool $applyName = false, ?string $prereqOverride = null): void
    {
        Auth::require('registration');
        Db::tx(function () use ($id, $nameId, $subjectIds, $applyName, $prereqOverride) {
            self::lockOpen($id);
            AddDrop::checkPrerequisites($id, $subjectIds, $prereqOverride); // v54: refused, or allowed by the registrar with a reason
            if ($nameId !== null && !Db::val('SELECT 1 FROM sis_assessment_names WHERE id = ?', [$nameId])) throw new AppError('Assessment name not found.');
            // v53: two subjects that meet at the same time can't both be taken
            $sched = Schedules::forRegistration(self::get($id), $subjectIds);
            if ($sched['clashes']) throw new AppError("Time conflict in the class schedule:\n• " . implode("\n• ", $sched['clashes']) . "\nRemove one of the subjects, or change the schedule in Class Schedules.");
            Db::q('UPDATE sis_registrations SET assessment_name_id = ? WHERE id = ?', [$nameId, $id]);
            $restored = $applyName ? Db::q("DELETE FROM sis_registration_labels WHERE registration_id = ? AND mode = 'EXCLUDE'", [$id])->rowCount() : 0;
            self::writeSubjects($id, $subjectIds);
            self::recompute($id);
            Audit::log('registration_updated', 'registration', $id, ['assessment_name_id' => $nameId, 'subjects' => count($subjectIds)] + ($restored ? ['fees_restored' => $restored] : []));
        });
    }

    /**
     * v54: add and/or drop subjects of a CONFIRMED enrollment (registrar), or withdraw (drop all).
     * The enrollment stays confirmed; its assessment is booked again. A dropped subject keeps part of its fees
     * (AddDrop::charge, by week of classes) as an "Add/drop" charge. Dropped subjects get DRP in the Grading System.
     */
    public static function addDrop(int $id, array $add, array $drop, string $reason, ?string $prereqOverride = null, bool $withdraw = false): array
    {
        Auth::require('registration');
        $reason = trim(preg_replace('/\s+/', ' ', $reason));
        if (mb_strlen($reason) < 3) throw new AppError('Enter the reason, e.g. "Student request: schedule conflict".');
        return CurriculumAssignments::locked(Db::pdo(), fn() => Db::tx(function () use ($id, $add, $drop, $reason, $prereqOverride, $withdraw) {
            $r = Db::one('SELECT * FROM sis_registrations WHERE id = ? FOR UPDATE', [$id]) ?: throw new AppError('Registration not found.');
            if ($r['status'] === 'CANCELLED') throw new AppError('This registration is voided.');
            if (empty($r['locked_at'])) throw new AppError('This enrollment is not confirmed yet: change its subjects directly in Subjects.');
            if (($r['record_origin'] ?? '') === 'MIGRATION') throw new AppError('An imported balance has no subjects to add or drop.');
            $current = Db::col('SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ?', [$id]);
            if ($withdraw) { $drop = $current; $add = []; }
            $add = array_values(array_unique(array_filter($add, fn($x) => is_string($x) && $x !== '')));
            $drop = array_values(array_unique(array_filter($drop, fn($x) => is_string($x) && $x !== '')));
            if (!$add && !$drop) throw new AppError('Choose the subjects to add or drop.');
            foreach ($drop as $d) if (!in_array($d, $current, true)) throw new AppError('A subject to drop is not in this enrollment.');
            foreach ($add as $a) {
                if (in_array($a, $current, true)) throw new AppError((GS::get('subjects', $a)['subjectCode'] ?? 'A subject') . ' is already in this enrollment.');
                if (!GS::get('subjects', $a)) throw new AppError('A subject to add no longer exists.');
            }
            if ($drop && (string) $r['student_doc_id'] !== '') {
                $graded = GS::removeFromAssignments((string) $r['student_doc_id'], (string) $r['section_doc_id'], (string) $r['school_year'], (string) $r['term'], $drop, true)['graded'];
                if ($graded) throw new AppError(implode(', ', $graded) . ' already has a grade for this student, so it can\'t be dropped.');
            }
            $final = array_values(array_merge(array_diff($current, $drop), $add));
            $sched = Schedules::forRegistration($r, $final);
            if ($sched['clashes']) throw new AppError("Time conflict in the class schedule:\n• " . implode("\n• ", $sched['clashes']));
            AddDrop::checkPrerequisites($id, $add, $prereqOverride);
            $codes = fn(array $ids) => implode(', ', array_map(fn($x) => (string) (GS::get('subjects', $x)['subjectCode'] ?? $x), $ids));
            $before = Money::cents($r['total_amount']);
            Accounting::reverseAssessment($id, ($withdraw ? 'withdrawal' : 'add/drop') . ' — ' . $reason); // booked again below
            Db::q('UPDATE sis_registrations SET locked_at = NULL WHERE id = ?', [$id]);
            $charge = AddDrop::charge($r); $charged = 0;
            if ($drop) {
                $full = self::build(self::get($id))['fees_cents'];
                self::writeSubjects($id, array_values(array_diff($current, $drop)));
                $value = max(0, $full - self::build(self::get($id))['fees_cents']);
                $charged = (int) round($value * $charge['percent'] / 100);
                if ($charged > 0) Db::insert('sis_registration_items', ['registration_id' => $id, 'category_name' => 'Add/drop', 'label_name' => mb_substr('Dropped ' . $codes($drop) . ': ' . $charge['percent'] . '% of ₱' . Money::fmt($value), 0, 100),
                    'charge_type' => 'ADJUSTMENT', 'quantity' => 1, 'unit_amount' => Money::dec($charged), 'amount' => Money::dec($charged), 'sort_order' => 900]);
            }
            self::writeSubjects($id, $final);
            self::recompute($id);
            Db::q('UPDATE sis_registrations SET locked_at = ? WHERE id = ?', [$r['locked_at'], $id]);
            Accounting::postAssessment($id);
            $doc = (string) $r['student_doc_id'];
            if ($add && $doc !== '') GS::addToAssignments($doc, (string) $r['section_doc_id'], (string) $r['school_year'], (string) $r['term'], $add);
            if ($drop && $doc !== '') GS::markDropped($doc, (string) $r['section_doc_id'], (string) $r['school_year'], (string) $r['term'], $drop);
            $after = Money::cents((string) Db::val('SELECT total_amount FROM sis_registrations WHERE id = ?', [$id]));
            if ($withdraw) Db::q('UPDATE sis_registrations SET remarks = ? WHERE id = ?', [mb_substr('Withdrawn: ' . $reason, 0, 255), $id]);
            Db::insert('sis_adddrop_log', ['registration_id' => $id, 'kind' => $withdraw ? 'WITHDRAW' : 'ADD_DROP', 'added' => mb_substr($codes($add), 0, 500), 'dropped' => mb_substr($codes($drop), 0, 500),
                'charge_percent' => $drop ? $charge['percent'] : 0, 'charged' => Money::dec($charged), 'total_before' => Money::dec($before), 'total_after' => Money::dec($after), 'reason' => mb_substr($reason, 0, 255), 'created_by' => Auth::id()]);
            Audit::log($withdraw ? 'enrollment_withdrawn' : 'subjects_added_dropped', 'registration', $id, ['added' => $codes($add), 'dropped' => $codes($drop), 'charged' => Money::dec($charged), 'reason' => $reason]);
            return ['charged' => $charged, 'percent' => $charge['percent'], 'before' => $before, 'after' => $after];
        }));
    }

    /** A manual charge (positive) or less/discount (negative) line, with a reason. */
    public static function addAdjustment(int $id, string $label, int $cents, string $category = 'Adjustment'): void
    {
        Auth::require('registration');
        if (trim($label) === '') throw new AppError('Describe the adjustment.');
        if ($cents === 0) throw new AppError('Enter an amount.');
        if ($category === 'Other Charges' && $cents < 0) throw new AppError('A charge must be a positive amount. Use "Less / discount" to take an amount off.');
        Db::tx(function () use ($id, $label, $cents, $category) {
            self::lockOpen($id);
            Db::insert('sis_registration_items', ['registration_id' => $id, 'category_name' => mb_substr($category, 0, 80), 'label_name' => mb_substr($label, 0, 100),
                'charge_type' => 'ADJUSTMENT', 'quantity' => 1, 'unit_amount' => Money::dec($cents), 'amount' => Money::dec($cents), 'sort_order' => 900]);
            self::recompute($id);
            Audit::log('registration_adjusted', 'registration', $id, ['label' => $label, 'amount' => Money::dec($cents)]);
        });
    }

    public static function removeAdjustment(int $id, int $itemId): void
    {
        Auth::require('registration');
        Db::tx(function () use ($id, $itemId) {
            self::lockOpen($id);
            if(Db::val('SELECT registration_id FROM sis_registration_plans WHERE discount_item_id=?',[$itemId]))throw new AppError('Payment-plan discounts follow payment eligibility and cannot be removed manually.');
            Db::q("DELETE FROM sis_registration_items WHERE id = ? AND registration_id = ? AND charge_type = 'ADJUSTMENT'", [$itemId, $id]);
            self::recompute($id);
            Audit::log('registration_adjustment_removed', 'registration', $id, ['item_id' => $itemId]);
        });
    }

    /**
     * Confirm Enrollment: the assessment is recomputed from the current fee setup and
     * checked (review()). Errors stop it; warnings must be confirmed ($warningsConfirmed). From then on the
     * registration can only be viewed (payments can still be received).
     */
    public static function finish(int $id, bool $warningsConfirmed = false, ?string $eligibilityOverride = null, ?string $clearanceReason = null, string $clearancePassword = ''): void
    {
        Auth::require('registration');
        Auth::require('enrollment.confirm');
        if (trim((string) $eligibilityOverride) !== '' && !Auth::can('users.manage')) throw new Forbidden('Only an administrator may record an eligibility exception.');
        CurriculumAssignments::locked(Db::pdo(),fn()=>Db::tx(function () use ($id, $warningsConfirmed, $eligibilityOverride, $clearanceReason, $clearancePassword) {
            $registration = self::lockOpen($id);
            Db::one("SELECT id FROM docs WHERE col = 'students' AND id = ? FOR UPDATE", [$registration['student_doc_id']]);
            $eligibility = EnrollmentEligibility::forRegistration($registration, $eligibilityOverride);
            EnrollmentEligibility::assertAllowed($eligibility);
            // v55: the previous term's clearance; when not completed the registrar confirms with a reason and their password
            $clr = Clearance::requirement((string) $registration['student_number'], (string) $registration['school_year'], (string) $registration['term']);
            if ($clr && !$clr['complete'] && !Db::val('SELECT 1 FROM sis_clearance_overrides WHERE registration_id = ?', [$id])) Clearance::override($id, $clr, (string) $clearanceReason, $clearancePassword);
            PortalEnrollment::assertPayment($id, true);
            $rv = self::review($id);
            foreach ($rv['checks'] as $c) if ($c['level'] === 'error') throw new AppError($c['text']);
            if ($rv['warnings'] && !$warningsConfirmed) {
                $w = array_column(array_filter($rv['checks'], fn($c) => $c['level'] === 'warn'), 'text');
                throw new AppError("Check before confirming:\n• " . implode("\n• ", $w) . "\nTick \"I checked these\" to confirm anyway.");
            }
            self::recompute($id);
            PortalEnrollment::assertPayment($id, true);
            PortalEnrollment::applyPlacement($id);
            Db::q('UPDATE sis_registrations SET locked_at = NOW(), locked_by = ? WHERE id = ?', [Auth::id(), $id]);
            EnrollmentEligibility::record($id, 'CONFIRMED', $eligibility);
            PortalEnrollment::confirmed($id);
            Accounting::postAssessment($id); // Dr Accounts Receivable, Cr fee revenue (Accounting → Ledger)
            $r = self::get($id); // the student joins the grading assignments (classes) of their subjects that already have a teacher
            GS::addToAssignments((string) $r['student_doc_id'], (string) $r['section_doc_id'], (string) $r['school_year'], (string) $r['term'],
                Db::col('SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ?', [$id]));
            Audit::log('registration_done', 'registration', $id, ['total' => Money::dec($rv['build']['total_cents']), 'warnings_confirmed' => $rv['warnings']]);
        }));
    }

    /** Administrator only: open a done registration again for changes, with the reason. */
    public static function reopen(int $id, string $reason): void
    {
        Auth::require('enrollment.reopen');
        if (trim($reason) === '') throw new AppError('Give the reason for reopening.');
        Db::tx(function () use ($id, $reason) {
            $r = Db::one('SELECT * FROM sis_registrations WHERE id = ? FOR UPDATE', [$id]);
            if (($r['record_origin']??'')==='MIGRATION') throw new AppError('Reverse imported balances through Data Migration; they cannot be reopened as an enrollment.');
            if(Db::val("SELECT d.id FROM sis_accounting_documents d JOIN sis_journal j ON j.id=d.journal_id WHERE d.registration_id=? AND j.status='POSTED' AND j.reversed_by_id IS NULL",[$id]))throw new AppError('Reverse the linked Accounting adjustments or refunds before reopening this assessment.');
            if (!$r || self::state($r) !== 'DONE') throw new AppError('Only a confirmed enrollment can be reopened.');
            Db::q('UPDATE sis_registrations SET locked_at = NULL, locked_by = NULL WHERE id = ?', [$id]);
            Accounting::reverseAssessment($id, 'enrollment reopened — ' . trim($reason)); // booked again when confirmed again
            Audit::log('registration_reopened', 'registration', $id, ['reason' => $reason]);
        });
    }

    /** Void the registration (open or done): kept for the record, view only. Payments must be voided first. */
    public static function void(int $id, string $reason): void
    {
        Auth::require('registration');
        if (trim($reason) === '') throw new AppError('Give the reason for voiding.');
        CurriculumAssignments::locked(Db::pdo(), fn() => Db::tx(function () use ($id, $reason) {
            $r = Db::one('SELECT * FROM sis_registrations WHERE id = ? FOR UPDATE', [$id]);
            if (!$r) throw new AppError('Registration not found.');
            if (($r['record_origin']??'')==='MIGRATION') throw new AppError('Reverse imported balances through Data Migration.');
            if ($r['status'] === 'CANCELLED') throw new AppError('This registration is already voided.');
            if (Money::cents($r['amount_paid']) > 0) throw new AppError('Payments were made. The cashier must void them first.');
            // A voided registration also leaves its classes in the grading system (unless grades were already given there).
            $subjects = Db::col('SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ?', [$id]);
            $classes = ['removed' => [], 'graded' => []];
            if ($r['student_doc_id'] && $r['section_doc_id']) {
                $args = [(string) $r['student_doc_id'], (string) $r['section_doc_id'], (string) $r['school_year'], (string) $r['term'], $subjects];
                $check = GS::removeFromAssignments(...[...$args, true]);
                if ($check['graded']) throw new AppError('Grades are already recorded for this student in ' . implode(', ', $check['graded']) . '. Clear those grades in the grading system first, then void the registration.');
                $classes = GS::removeFromAssignments(...$args);
            }
            Db::q("UPDATE sis_registrations SET status = 'CANCELLED', remarks = ? WHERE id = ?", [mb_substr(trim($reason), 0, 255), $id]);
            Accounting::reverseAssessment($id, 'registration voided — ' . trim($reason));
            PortalEnrollment::registrationVoided($id, $reason);
            Audit::log('registration_voided', 'registration', $id, ['reason' => $reason, 'left_classes' => $classes['removed']]);
        }));
    }

    /** Older name of void(). */
    public static function cancel(int $id, string $reason): void
    {
        self::void($id, $reason);
    }
}

/** Scholarship and discount programs, and the ones given to each registration. */
final class Discounts
{
    public const CATEGORY = 'Less: Scholarships & Discounts';
    public const KINDS = ['SCHOLARSHIP' => 'Scholarship', 'DISCOUNT' => 'Discount'];
    public const METHODS = ['PERCENT' => 'Percentage (%)', 'FIXED' => 'Fixed amount (₱)'];

    /** Programs with the names of the categories they apply to (empty = whole assessment). */
    public static function all(bool $activeOnly = false): array
    {
        $rows = Db::all('SELECT d.*, (SELECT COUNT(*) FROM sis_registration_discounts x JOIN sis_registrations r ON r.id = x.registration_id
                                       WHERE x.discount_id = d.id AND r.status <> \'CANCELLED\') AS used
                           FROM sis_discounts d' . ($activeOnly ? ' WHERE d.is_active = 1' : '') . ' ORDER BY d.kind DESC, d.name');
        foreach ($rows as &$r) $r['categories'] = self::categoriesOf((int) $r['id']);
        return $rows;
    }

    /** [category id => name] */
    public static function categoriesOf(int $discountId): array
    {
        $out = [];
        foreach (Db::all('SELECT c.id, c.name FROM sis_discount_categories dc JOIN sis_fee_categories c ON c.id = dc.category_id WHERE dc.discount_id = ? ORDER BY c.sort_order, c.name', [$discountId]) as $c) $out[(int) $c['id']] = $c['name'];
        return $out;
    }

    /** "50% of Tuition", "₱1,000.00 off the assessment (up to ₱5,000.00)" */
    public static function describe(array $d): string
    {
        $what = $d['categories'] ? implode(', ', $d['categories']) : 'the whole assessment';
        $s = $d['method'] === 'PERCENT' ? rtrim(rtrim((string) $d['value'], '0'), '.') . '% of ' . $what : '₱' . Money::fmt(Money::cents($d['value'])) . ' off ' . $what;
        return $s . ($d['max_amount'] !== null ? ' (up to ₱' . Money::fmt(Money::cents($d['max_amount'])) . ')' : '');
    }

    public static function save(?int $id, array $in): int
    {
        Auth::require('discounts.manage');
        $method = In::enum($in, 'method', array_keys(self::METHODS), true, 'Computation');
        $value = trim((string) ($in['value'] ?? ''));
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', str_replace(',', '', $value)) || (float) str_replace(',', '', $value) <= 0) throw new AppError('Enter the percentage or the amount (more than 0).');
        $value = str_replace(',', '', $value);
        if ($method === 'PERCENT' && (float) $value > 100) throw new AppError('A percentage can be at most 100.');
        $max = trim((string) ($in['max_amount'] ?? ''));
        if ($max !== '' && (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', str_replace(',', '', $max)) || (float) str_replace(',', '', $max) <= 0)) throw new AppError('The maximum amount must be more than 0, or empty for no limit.');
        $d = ['name' => In::str($in, 'name', 100, true, 'Name'), 'kind' => In::enum($in, 'kind', array_keys(self::KINDS), true, 'Type'),
            'method' => $method, 'value' => $value, 'max_amount' => $max === '' ? null : str_replace(',', '', $max),
            'description' => In::str($in, 'description', 255), 'is_active' => In::bool($in, 'is_active')];
        $cats = array_values(array_unique(array_map('intval', array_filter((array) ($in['categories'] ?? []), fn($v) => is_string($v) && ctype_digit($v)))));
        return Db::tx(function () use ($id, $d, $cats) {
            try {
                if ($id) {
                    if (!Db::val('SELECT 1 FROM sis_discounts WHERE id = ?', [$id])) throw new AppError('Program not found.');
                    Db::update('sis_discounts', $d, $id);
                } else {
                    $id = Db::insert('sis_discounts', $d);
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') throw new AppError('That name is already used.');
                throw $e;
            }
            Db::q('DELETE FROM sis_discount_categories WHERE discount_id = ?', [$id]);
            foreach ($cats as $c) {
                if (!Db::val('SELECT 1 FROM sis_fee_categories WHERE id = ?', [$c])) throw new AppError('Category not found.');
                Db::insert('sis_discount_categories', ['discount_id' => $id, 'category_id' => $c]);
            }
            Audit::log('discount_saved', 'discount', $id, $d + ['categories' => $cats]);
            return $id;
        });
    }

    /** The programs given to a registration. */
    public static function given(int $regId): array
    {
        $rows = Db::all('SELECT x.*, d.name, d.kind, d.method, d.value, d.max_amount, d.is_active, u.full_name AS added_by_name
                           FROM sis_registration_discounts x JOIN sis_discounts d ON d.id = x.discount_id LEFT JOIN sis_users u ON u.id = x.added_by
                          WHERE x.registration_id = ? ORDER BY x.id', [$regId]);
        foreach ($rows as &$r) $r['categories'] = self::categoriesOf((int) $r['discount_id']);
        return $rows;
    }

    /**
     * The amounts to take off, from the registration's fee lines (before adjustments):
     * [['label' => 'Academic Scholarship (50% of Tuition)', 'cents' => 425000], …]
     */
    public static function linesFor(int $regId, array $feeLines): array
    {
        $out = [];
        foreach (self::given($regId) as $g) {
            $cats = array_values($g['categories']);
            $base = 0;
            foreach ($feeLines as $l) if (!$cats || in_array($l['category_name'], $cats, true)) $base += (int) $l['amount_cents'];
            $cents = $g['method'] === 'PERCENT' ? Money::percentOf($base, Money::cents($g['value'])) : min(Money::cents($g['value']), $base);
            if ($g['max_amount'] !== null) $cents = min($cents, Money::cents($g['max_amount']));
            if ($cents > 0) $out[] = ['label' => $g['name'] . ' (' . self::describe($g) . ')', 'cents' => $cents, 'discount_id' => (int) $g['discount_id']];
        }
        return $out;
    }

    /** Give a program to a registration (open registrations only). */
    public static function give(int $regId, int $discountId, ?string $remarks): void
    {
        Auth::require('registration');
        $d = Db::one('SELECT * FROM sis_discounts WHERE id = ? AND is_active = 1', [$discountId]);
        if (!$d) throw new AppError('Choose a scholarship or discount.');
        Db::tx(function () use ($regId, $discountId, $remarks, $d) {
            $r = Db::one('SELECT * FROM sis_registrations WHERE id = ? FOR UPDATE', [$regId]);
            if (!$r) throw new AppError('Registration not found.');
            if (Db::val('SELECT 1 FROM sis_registration_discounts WHERE registration_id = ? AND discount_id = ?', [$regId, $discountId])) throw new AppError($d['name'] . ' is already given to this registration.');
            Db::insert('sis_registration_discounts', ['registration_id' => $regId, 'discount_id' => $discountId, 'remarks' => $remarks !== null && trim($remarks) !== '' ? mb_substr(trim($remarks), 0, 255) : null, 'added_by' => Auth::id()]);
            Registration::recompute($regId); // refuses done / voided registrations
            Audit::log('discount_given', 'registration', $regId, ['discount' => $d['name'], 'remarks' => $remarks]);
        });
    }

    public static function remove(int $regId, int $discountId): void
    {
        Auth::require('registration');
        Db::tx(function () use ($regId, $discountId) {
            Db::q('DELETE FROM sis_registration_discounts WHERE registration_id = ? AND discount_id = ?', [$regId, $discountId]);
            Registration::recompute($regId);
            Audit::log('discount_removed', 'registration', $regId, ['discount_id' => $discountId]);
        });
    }

    /** Who received what in a school year (and term): for the Grantees list. */
    public static function grantees(string $schoolYear, string $term = '', ?int $discountId = null): array
    {
        $where = ["r.school_year = ?", "r.status <> 'CANCELLED'"]; $p = [$schoolYear];
        if ($term !== '') { $where[] = 'r.term = ?'; $p[] = $term; }
        if ($discountId) { $where[] = 'd.id = ?'; $p[] = $discountId; }
        $rows = Db::all('SELECT r.id AS registration_id, r.reg_no, r.student_number, r.student_name, r.term, r.section_name, r.total_amount, x.remarks, d.id AS discount_id, d.name, d.kind
                           FROM sis_registration_discounts x JOIN sis_registrations r ON r.id = x.registration_id JOIN sis_discounts d ON d.id = x.discount_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY d.kind DESC, d.name, r.student_name', $p);
        foreach ($rows as &$row) {
            // the amount on the assessment: the DISCOUNT line that starts with the program name
            $row['amount'] = -Money::cents((string) Db::val("SELECT COALESCE(SUM(amount), 0) FROM sis_registration_items WHERE registration_id = ? AND charge_type = 'DISCOUNT' AND label_name LIKE ?",
                [$row['registration_id'], str_replace(['%', '_'], ['\\%', '\\_'], $row['name']) . ' (%']));
        }
        return $rows;
    }
}

/** Official receipt series (Settings → O.R. Series): how receipts are numbered and which template prints them. */
final class OrSeries
{
    public const TEMPLATES = ['STANDARD' => 'Standard receipt', 'OFFICIAL' => 'Template Official Receipt'];
    public const FLAGS = ['is_active' => 'Active', 'auto_number' => 'Auto-number', 'allow_edit' => 'Editable O.R. No.', 'is_default' => 'Default'];
    public const NUMBER_RE = '/^[A-Za-z0-9#\/][A-Za-z0-9#\/-]{0,29}$/';

    public static function all(bool $activeOnly = false): array
    {
        return Db::all('SELECT s.*, (SELECT COUNT(*) FROM sis_payments p WHERE p.series_id = s.id) AS used
                          FROM sis_or_series s' . ($activeOnly ? ' WHERE s.is_active = 1' : '') . ' ORDER BY s.is_default DESC, s.code');
    }

    public static function get(int $id): ?array
    {
        return Db::one('SELECT * FROM sis_or_series WHERE id = ?', [$id]);
    }

    public static function format(array $s, int $n): string
    {
        return $s['prefix'] . str_pad((string) $n, (int) $s['digits'], '0', STR_PAD_LEFT);
    }

    /** The number part of $no when it is written in this series' format (prefix + digits), else null. */
    public static function numberOf(array $s, string $no): ?int
    {
        return preg_match('/^' . preg_quote((string) $s['prefix'], '/') . '(\d{' . (int) $s['digits'] . ',})$/', $no, $m) ? (int) $m[1] : null;
    }

    /** The next unused number of an auto-numbered series ('' when the cashier types it). */
    public static function suggest(array $s): string
    {
        if (!(int) $s['auto_number']) return '';
        $n = max(1, (int) $s['next_number']);
        for ($i = 0; $i < 100000; $i++, $n++) {
            $no = self::format($s, $n);
            if (!Db::val('SELECT 1 FROM sis_payments WHERE receipt_no = ?', [$no]) && !Db::val('SELECT 1 FROM sis_document_payments WHERE receipt_no = ?', [$no])) return $no; // v52: document fee receipts share the series
        }
        throw new AppError('No free receipt number in this series.');
    }

    /** A typed whole number (no decimals, letters or exponents). */
    private static function whole(mixed $v, string $label): int
    {
        $v = trim((string) $v);
        if (!preg_match('/^\d{1,10}$/', $v)) throw new AppError("$label must be a whole number.");
        return (int) $v;
    }

    public static function save(?int $id, array $in): int
    {
        Auth::require('settings.manage');
        $d = ['code' => strtoupper(trim((string) ($in['code'] ?? ''))), 'description' => trim((string) ($in['description'] ?? '')),
              'prefix' => trim((string) ($in['prefix'] ?? '')), 'digits' => self::whole($in['digits'] ?? '7', 'Digits'), 'next_number' => self::whole($in['next_number'] ?? '1', 'Next number'),
              'template' => (string) ($in['template'] ?? 'STANDARD')];
        foreach (array_keys(self::FLAGS) as $f) $d[$f] = !empty($in[$f]) ? 1 : 0;
        if (!preg_match('/^[A-Z0-9]{1,10}$/', $d['code'])) throw new AppError('Code: 1 to 10 letters or numbers (e.g. OR1).');
        if ($d['description'] === '' || mb_strlen($d['description']) > 100) throw new AppError('Enter the description (up to 100 characters).');
        if (!preg_match('/^([A-Za-z0-9#\/][A-Za-z0-9#\/-]{0,7})?$/', $d['prefix'])) throw new AppError('Prefix: up to 8 letters, numbers, -, # or / (not starting with -).');
        if ($d['digits'] < 3 || $d['digits'] > 10) throw new AppError('Digits: 3 to 10.');
        if ($d['next_number'] < 1) throw new AppError('Next number: 1 or more.');
        if ($d['next_number'] >= 10 ** $d['digits']) throw new AppError('Next number ' . $d['next_number'] . ' does not fit in ' . $d['digits'] . ' digits.');
        if (!isset(self::TEMPLATES[$d['template']])) throw new AppError('Choose the receipt template.');
        if ($d['is_default'] && !$d['is_active']) throw new AppError('The default series must be active.');
        return Db::tx(function () use ($id, $d) {
            if (Db::val('SELECT 1 FROM sis_or_series WHERE code = ? AND id <> ?', [$d['code'], (int) $id])) throw new AppError("The code {$d['code']} is already used.");
            if ($id !== null) {
                if (!Db::one('SELECT id FROM sis_or_series WHERE id = ? FOR UPDATE', [$id])) throw new AppError('O.R. series not found.');
                Db::update('sis_or_series', $d, $id);
            } else {
                $id = Db::insert('sis_or_series', $d);
            }
            if ($d['is_default']) Db::q('UPDATE sis_or_series SET is_default = 0 WHERE id <> ?', [$id]);
            elseif (!Db::val('SELECT 1 FROM sis_or_series WHERE is_default = 1')) throw new AppError('Keep one series as the default.');
            Audit::log('or_series_saved', 'or_series', $id, $d);
            return $id;
        });
    }

    public static function delete(int $id): void
    {
        Auth::require('settings.manage');
        Db::tx(function () use ($id) {
            $s = Db::one('SELECT * FROM sis_or_series WHERE id = ? FOR UPDATE', [$id]);
            if (!$s) throw new AppError('O.R. series not found.');
            if ((int) $s['is_default']) throw new AppError('This is the default series. Make another series the default first.');
            $used = (int) Db::val('SELECT COUNT(*) FROM sis_payments WHERE series_id = ?', [$id]);
            if ($used) throw new AppError("This series has $used receipt(s), so it can't be deleted. Untick Active instead.");
            Db::q('DELETE FROM sis_or_series WHERE id = ?', [$id]);
            Audit::log('or_series_deleted', 'or_series', $id, ['code' => $s['code']]);
        });
    }
}

final class Cashier
{
    /**
     * Record a payment. $idem is a one-time token from the form: a repeated submit returns the first payment.
     * $seriesId: the O.R. series (null = the default). $orNo: the O.R. number typed by the cashier
     * (null = the next number of an auto-numbered series). A number is used only once.
     */
    public static function pay(int $regId, int $cents, string $method, ?string $ref, string $idem, ?string $remarks, ?int $seriesId = null, ?string $orNo = null, ?int $feeItemId = null, ?int $expectedPaid = null): array
    {
        Auth::require('payments.record');
        if (!preg_match('/^[a-f0-9]{32}$/', $idem)) throw new AppError('The payment form expired. Reload the page and try again.');
        if ($cents <= 0) throw new AppError('Enter the amount received.');
        if (!in_array($method, PAYMENT_METHODS, true)) throw new AppError('Choose the payment method.');
        if ($method !== 'CASH' && !$ref) throw new AppError("Enter the reference number of the $method payment.");
        $orNo = $orNo !== null && trim($orNo) !== '' ? trim($orNo) : null;
        if ($orNo !== null && !preg_match(OrSeries::NUMBER_RE, $orNo)) throw new AppError('O.R. No.: up to 30 letters, numbers, -, # or / (not starting with -).');
        return Db::tx(function () use ($regId, $cents, $method, $ref, $idem, $remarks, $seriesId, $orNo, $feeItemId, $expectedPaid) {
            AccountingWorkflow::lock();
            $r = Db::one('SELECT * FROM sis_registrations WHERE id = ? FOR UPDATE', [$regId]);
            $dup = Db::one('SELECT id, receipt_no FROM sis_payments WHERE idempotency_key = ?', [$idem]);
            if ($dup) return ['id' => (int) $dup['id'], 'receipt_no' => $dup['receipt_no'], 'duplicate' => true];
            if (!$r || $r['status'] === 'CANCELLED') throw new AppError('Registration not found or voided.');
            // Another tab or cashier recorded a payment after this form was opened: stop instead of taking the same money twice.
            if ($expectedPaid !== null && Money::cents($r['amount_paid']) !== $expectedPaid)
                throw new AppError('Another payment of ₱' . Money::fmt(Money::cents($r['amount_paid']) - $expectedPaid) . ' was recorded for this student after you opened this page (another tab or cashier). The balance is now ₱' . Money::fmt(max(0, Registration::balance($r))) . '. Check the Payments list, then reload to take another payment.');
            PortalEnrollment::assertPayment($regId);
            if ($block = PaymentPlans::paymentBlock($r)) throw new AppError($block); // v56: payment plan first
            PaymentPlans::beforePayment($r,$cents);
            $bal = Registration::balance($r);
            if ($cents > $bal) throw new AppError('The amount (₱' . Money::fmt($cents) . ') is more than the balance (₱' . Money::fmt(max(0, $bal)) . ').');
            $s = $seriesId ? Db::one('SELECT * FROM sis_or_series WHERE id = ? FOR UPDATE', [$seriesId])
                           : Db::one('SELECT * FROM sis_or_series WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1 FOR UPDATE');
            if (!$s) throw new AppError('No O.R. series to use. Set one up in Settings → O.R. Series.');
            if (!(int) $s['is_active']) throw new AppError("The O.R. series {$s['code']} is not active.");
            if ($orNo === null && !(int) $s['auto_number']) throw new AppError("Enter the O.R. number (series {$s['code']} is numbered by hand).");
            $no = $orNo ?? OrSeries::suggest($s);
            self::assertUnused($no);
            $id = Db::insert('sis_payments', ['receipt_no' => $no, 'series_id' => (int) $s['id'], 'idempotency_key' => $idem, 'registration_id' => $regId, 'amount' => Money::dec($cents),
                'method' => $method, 'reference_no' => $ref, 'remarks' => $remarks, 'received_by' => Auth::id()]);
            $n = OrSeries::numberOf($s, $no);
            if ($n !== null && $n >= (int) $s['next_number']) Db::q('UPDATE sis_or_series SET next_number = ? WHERE id = ?', [$n + 1, $s['id']]);
            Db::q('UPDATE sis_registrations SET amount_paid = amount_paid + ? WHERE id = ?', [Money::dec($cents), $regId]);
            AccountingWorkflow::allocatePayment($id,$r,$cents,$feeItemId);
            PaymentPlans::sync($regId);
            Accounting::postPayment($id); // Dr Cash / Bank, Cr Accounts Receivable
            Audit::log('payment_recorded', 'payment', $id, ['receipt_no' => $no, 'series' => $s['code'], 'registration' => $r['reg_no'], 'amount' => Money::dec($cents), 'method' => $method]);
            return ['id' => $id, 'receipt_no' => $no, 'duplicate' => false];
        });
    }

    /** Correct the O.R. number of a posted receipt (when its series allows it). The new number must be unused. */
    public static function editNumber(int $paymentId, string $newNo, string $reason): void
    {
        Auth::require('payments.void');
        $newNo = trim($newNo);
        if (!preg_match(OrSeries::NUMBER_RE, $newNo)) throw new AppError('O.R. No.: up to 30 letters, numbers, -, # or / (not starting with -).');
        if (trim($reason) === '') throw new AppError('Give the reason for changing the O.R. number.');
        Db::tx(function () use ($paymentId, $newNo, $reason) {
            AccountingWorkflow::lock();
            $p = Db::one('SELECT * FROM sis_payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$p) throw new AppError('Receipt not found.');
            if ($p['status'] !== 'POSTED') throw new AppError('Only an active receipt can get a new O.R. number.');
            if(AccountingWorkflow::receiptLocked($paymentId))throw new AppError('This receipt is posted in Accounting. Accounting must reverse the posting first.');
            if(Db::val("SELECT id FROM sis_journal WHERE source='PAYMENT' AND source_id=? AND reverses_id IS NULL AND reversed_by_id IS NOT NULL",[$paymentId]))throw new AppError('This receipt was reversed in Accounting. Void it and issue the corrected receipt.');
            $s = $p['series_id'] ? Db::one('SELECT * FROM sis_or_series WHERE id = ? FOR UPDATE', [$p['series_id']]) : null;
            if ($s && !(int) $s['allow_edit']) throw new AppError("O.R. numbers of the series {$s['code']} can't be edited (Settings → O.R. Series).");
            if ($newNo === $p['receipt_no']) return;
            self::assertUnused($newNo);
            Db::q('UPDATE sis_payments SET receipt_no = ? WHERE id = ?', [$newNo, $paymentId]);
            Db::q("UPDATE sis_journal SET memo=REPLACE(memo,?,?),reviewed_by=NULL,reviewed_at=NULL WHERE source='PAYMENT' AND source_id=? AND status='REVIEW'",[$p['receipt_no'],$newNo,$paymentId]);
            $n = $s ? OrSeries::numberOf($s, $newNo) : null;
            if ($n !== null && $n >= (int) $s['next_number']) Db::q('UPDATE sis_or_series SET next_number = ? WHERE id = ?', [$n + 1, $s['id']]);
            Audit::log('payment_or_changed', 'payment', $paymentId, ['from' => $p['receipt_no'], 'to' => $newNo, 'reason' => $reason]);
        });
    }

    /**
     * Void a payment. $negate: the O.R. number becomes negative (OR-0000012 → -OR-0000012) so the number
     * is free to use again; otherwise the voided receipt keeps it.
     */
    public static function void(int $paymentId, string $reason, bool $negate = false): void
    {
        Auth::require('payments.void');
        if (trim($reason) === '') throw new AppError('Give the reason for voiding.');
        Db::tx(function () use ($paymentId, $reason, $negate) {
            AccountingWorkflow::lock();
            $p = Db::one('SELECT * FROM sis_payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$p || $p['status'] !== 'POSTED') throw new AppError('Payment not found or already voided.');
            AccountingWorkflow::assertOpen(date('Y-m-d'));
            if(AccountingWorkflow::receiptLocked($paymentId))throw new AppError('This receipt is posted in Accounting. Accounting must reverse the posting before Cashier can void it.');
            $reg = Db::one('SELECT * FROM sis_registrations WHERE id = ? FOR UPDATE', [$p['registration_id']]);
            // A confirmed Student Portal enrollment must keep its required payment.
            $app = Db::one("SELECT minimum_payment FROM sis_portal_enrollments WHERE registration_id = ? AND status = 'ACCEPTED'", [$p['registration_id']]);
            if ($app && !empty($reg['locked_at'])) {
                $need = min(Money::cents($reg['total_amount']), Money::cents($app['minimum_payment']));
                if (Money::cents($reg['amount_paid']) - Money::cents($p['amount']) < $need)
                    throw new AppError('This payment counts toward the required enrollment payment of ₱' . Money::fmt($need) . ' for a confirmed enrollment. Record the replacement payment first, or ask the registrar to reopen the registration, then void this receipt.');
            }
            $no = $p['receipt_no'];
            if ($negate) {
                $base = '-' . $p['receipt_no'];
                for ($no = $base, $i = 2; Db::val('SELECT 1 FROM sis_payments WHERE receipt_no = ?', [$no]); $i++) $no = $base . '-' . $i;
                if (strlen($no) > 30) throw new AppError('This O.R. number is too long to make negative.');
                // The series keeps counting forward: a printed O.R. number is never suggested for another receipt.
            }
            Db::q("UPDATE sis_payments SET status = 'VOIDED', receipt_no = ?, voided_by = ?, voided_at = NOW(), void_reason = ? WHERE id = ?", [$no, Auth::id(), $reason, $paymentId]);
            Db::q('UPDATE sis_registrations SET amount_paid = amount_paid - ? WHERE id = ?', [$p['amount'], $p['registration_id']]);
            PaymentPlans::sync((int)$p['registration_id']);
            Accounting::reversePayment($paymentId, $reason); // the ledger is voided together with the receipt
            Audit::log('payment_voided', 'payment', $paymentId, ['receipt_no' => $p['receipt_no'], 'amount' => $p['amount'], 'reason' => $reason] + ($negate ? ['negative_no' => $no] : []));
        });
    }

    private static function assertUnused(string $no): void
    {
        $used = Db::one('SELECT p.receipt_no, p.status, r.student_name FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id WHERE p.receipt_no = ?', [$no]);
        if (!$used && ($doc = Db::one('SELECT p.status, r.student_name FROM sis_document_payments p JOIN sis_document_requests r ON r.id = p.request_id WHERE p.receipt_no = ?', [$no]))) $used = ['status' => $doc['status'], 'student_name' => $doc['student_name'] . ' (document fee)'];
        if ($used) throw new AppError("O.R. No. $no is already used (" . ($used['status'] === 'VOIDED' ? 'voided receipt of ' : 'receipt of ') . $used['student_name'] . '). An O.R. number can be used only once.');
    }
}

/** The activity log in words, for Home and the Activity page. */
final class Activity
{
    private const WORDS = [
        'registration_created' => 'New registration', 'registration_updated' => 'Registration changed', 'registration_done' => 'Enrollment confirmed',
        'registration_voided' => 'Registration voided', 'registration_reopened' => 'Registration reopened', 'registration_adjusted' => 'Charge / less added',
        'registration_adjustment_removed' => 'Charge / less removed', 'registration_label_charged' => 'Fee added', 'registration_label_removed' => 'Fee taken off',
        'registration_label_reset' => 'Fee charged again', 'payment_recorded' => 'Payment received', 'payment_voided' => 'Payment voided', 'payment_or_changed' => 'O.R. number changed', 'journal_entry_made' => 'Journal entry made', 'journal_entry_reversed' => 'Journal entry reversed', 'account_saved' => 'Account saved', 'account_map_saved' => 'Account mappings saved', 'account_reconciled' => 'Bank reconciled', 'ledger_synced' => 'Earlier records booked to the ledger', 'term_calendar_saved' => 'Academic term saved', 'registration_override' => 'Registration allowed by the administrator', 'readmission_approved' => 'Readmission approved', 'teacher_assigned' => 'Teacher assigned', 'or_series_saved' => 'O.R. series saved', 'or_series_deleted' => 'O.R. series deleted',
        'admission_created' => 'New applicant', 'admission_updated' => 'Applicant updated', 'admission_admitted' => 'Applicant admitted', 'admission_rejected' => 'Applicant rejected', 'admission_cancelled' => 'Admission cancelled',
        'discount_given' => 'Scholarship / discount given', 'discount_removed' => 'Scholarship / discount removed', 'discount_saved' => 'Scholarship / discount program saved',
        'assessment_item_set' => 'Fee setup updated', 'assessment_item_removed' => 'Fee setup updated', 'assessment_name_copied' => 'Fee template copied',
        'category_saved' => 'Fee category saved', 'label_saved' => 'Fee label saved', 'name_saved' => 'Fee template saved',
        'student_added' => 'Student added', 'student_updated' => 'Student updated', 'section_added' => 'Section added', 'section_updated' => 'Section updated',
        'teacher_added' => 'Teacher added', 'teacher_updated' => 'Teacher updated', 'subject_added' => 'Subject added', 'subject_updated' => 'Subject updated',
        'curriculum_saved' => 'Curriculum saved', 'user_created' => 'Account created', 'user_updated' => 'Account updated', 'password_reset' => 'Password reset',
        'password_changed' => 'Password changed', 'login' => 'Signed in', 'logout' => 'Signed out',
    ];

    /** Latest entries: [['at', 'action', 'name', 'detail', 'user', 'role', 'link'], …] */
    public static function recent(int $limit = 8, bool $withSignIns = false, int $offset = 0, ?int $userId = null): array
    {
        $where = []; $p = [];
        if (!$withSignIns) $where[] = "a.action NOT IN ('login', 'logout', 'login_failed', 'password_changed')";
        if ($userId !== null) { $where[] = 'a.user_id = ?'; $p[] = $userId; }
        $rows = Db::all('SELECT a.*, u.username, u.role FROM sis_audit_logs a LEFT JOIN sis_users u ON u.id = a.user_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY a.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), $p);
        return array_map([self::class, 'describe'], $rows);
    }

    public static function describe(array $a): array
    {
        $d = json_decode((string) $a['details'], true) ?: [];
        $out = ['at' => $a['created_at'], 'action' => self::WORDS[$a['action']] ?? ucfirst(str_replace('_', ' ', $a['action'])),
            'name' => '', 'detail' => '', 'user' => $a['username'] ?? 'system', 'role' => $a['role'] ?? '', 'link' => null];
        $id = $a['entity_id'];
        switch ($a['entity_type']) {
            case 'registration':
                if ($r = Db::one('SELECT id, reg_no, student_name, section_name, program, year_level FROM sis_registrations WHERE id = ?', [(int) $id])) {
                    $out['name'] = $r['student_name'];
                    $out['detail'] = trim(($r['program'] ? $r['program'] . ' · ' : '') . $r['year_level'] . ' · ' . $r['reg_no']);
                    if (isset($d['discount'])) $out['detail'] = $d['discount'] . ' · ' . $r['reg_no'];
                    $out['link'] = url('registration', ['id' => $r['id']]);
                }
                break;
            case 'payment':
                if ($p = Db::one('SELECT p.id, p.receipt_no, p.amount, r.student_name FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id WHERE p.id = ?', [(int) $id])) {
                    $out['name'] = $p['student_name'];
                    $out['detail'] = 'Receipt ' . $p['receipt_no'] . ' · ₱' . Money::fmt(Money::cents($p['amount']));
                    $out['link'] = url('receipt', ['id' => $p['id']]);
                }
                break;
            case 'admission':
                if ($x = Db::one('SELECT id, last_name, first_name, program, year_level, admission_no FROM sis_admissions WHERE id = ?', [(int) $id])) {
                    $out['name'] = $x['last_name'] . ', ' . $x['first_name'];
                    $out['detail'] = trim(($x['program'] ? $x['program'] . ' · ' : '') . $x['year_level'] . ' · ' . $x['admission_no']);
                    $out['link'] = url('admission', ['id' => $x['id']]);
                }
                break;
            default:
                $out['name'] = (string) ($d['name'] ?? $d['label'] ?? $d['studentId'] ?? $d['username'] ?? '');
                $out['detail'] = (string) ($d['section'] ?? $d['term'] ?? '');
        }
        if ($out['name'] === '' && $out['detail'] === '') $out['detail'] = $a['entity_type'] . ($id !== null ? ' #' . $id : '');
        return $out;
    }
}

/** Academic terms and deadlines (Settings → Academic terms): when enrollment is open, classes start and grades are due. */
final class TermCalendar
{
    public static function all(): array
    {
        return Db::all('SELECT * FROM sis_term_calendar ORDER BY school_year DESC, FIELD(term, ' . implode(',', array_map(fn($t) => Db::pdo()->quote($t), TERMS)) . ')');
    }

    public static function get(string $schoolYear, string $term): ?array
    {
        return Db::one('SELECT * FROM sis_term_calendar WHERE school_year = ? AND term = ?', [$schoolYear, $term]);
    }

    public static function save(?int $id, array $in): int
    {
        Auth::require('settings.manage');
        $d = ['school_year' => trim((string) ($in['school_year'] ?? '')), 'term' => (string) ($in['term'] ?? ''),
              'enrollment_start' => In::date($in, 'enrollment_start', true, 'Enrollment opens'), 'enrollment_end' => In::date($in, 'enrollment_end', true, 'Enrollment closes'),
              'classes_start' => In::date($in, 'classes_start'), 'grades_due' => In::date($in, 'grades_due'), 'notes' => In::str($in, 'notes', 255)];
        if (!GS::validSchoolYear($d['school_year'])) throw new AppError('School year must look like 2026-2027.');
        if (!in_array($d['term'], TERMS, true)) throw new AppError('Choose the term.');
        if ($d['enrollment_end'] < $d['enrollment_start']) throw new AppError('Enrollment must close after it opens.');
        if ($d['grades_due'] && $d['classes_start'] && $d['grades_due'] < $d['classes_start']) throw new AppError('Grades are due after classes start.');
        return Db::tx(function () use ($id, $d) {
            if (Db::val('SELECT 1 FROM sis_term_calendar WHERE school_year = ? AND term = ? AND id <> ?', [$d['school_year'], $d['term'], (int) $id])) throw new AppError("{$d['school_year']} {$d['term']} is already set.");
            if ($id) Db::update('sis_term_calendar', $d, $id); else $id = Db::insert('sis_term_calendar', $d);
            Audit::log('term_calendar_saved', 'term_calendar', $id, $d);
            return $id;
        });
    }

    public static function delete(int $id): void
    {
        Auth::require('settings.manage');
        Db::q('DELETE FROM sis_term_calendar WHERE id = ?', [$id]);
        Audit::log('term_calendar_saved', 'term_calendar', $id, ['deleted' => true]);
    }
}
