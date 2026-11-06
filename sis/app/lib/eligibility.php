<?php
declare(strict_types=1);

/** One source of eligibility decisions for previews, creation and final confirmation. */
final class EnrollmentEligibility
{
    public const VERSION = 'eligibility-v1';

    public static function check(string $docId, string $number, string $sy, string $term, array $subjectIds = [], ?int $registrationId = null, ?array $admission = null, ?string $override = null): array
    {
        if (!GS::validSchoolYear($sy) || !in_array($term, TERMS, true)) throw new AppError('Choose a valid school year and term for the eligibility check.');
        $checks = [];
        $add = function (string $code, string $label, string $state, string $message, string $office, string $route = '', array $params = [], string $scope = 'ENROLLMENT', ?string $subject = null, bool $overrideable = false) use (&$checks): void {
            $checks[] = compact('code', 'label', 'state', 'message', 'office', 'route', 'params', 'scope', 'subject', 'overrideable');
        };
        $student = $docId !== '' ? GS::get('students', $docId) : null;
        if ((!$student || (string) $student['studentId'] !== $number) && !($admission && $admission['status'] === 'ADMITTED' && $admission['student_number'] === $number)) {
            $add('student', 'Student record', 'BLOCKED', 'Choose a valid student or admitted applicant.', 'Registrar', 'students');
            return self::summarize($checks, $number, $sy, $term, $subjectIds);
        }
        $add('student', 'Student record', 'PASS', 'Student identity verified.', 'Registrar');
        $duplicate = Db::one("SELECT id, reg_no FROM sis_registrations WHERE student_number = ? AND school_year = ? AND term = ? AND status <> 'CANCELLED' AND id <> ?" . (Db::inTx() ? ' FOR UPDATE' : ''), [$number, $sy, $term, $registrationId ?? 0]);
        $add('duplicate', 'Existing registration', $duplicate ? 'BLOCKED' : 'PASS', $duplicate ? 'The student is already registered for this term: ' . $duplicate['reg_no'] . '. Open that registration to continue.' : 'No other active registration for this term.', 'Registrar', $duplicate ? 'registration' : '', $duplicate ? ['id' => $duplicate['id']] : []);

        $previous = $docId !== '' ? GS::previousTerm($docId, $number, $sy, $term) : null;
        if ($previous) {
            $complete = GS::isTermComplete($docId, $previous['schoolYear'], $previous['term']);
            $label = $previous['schoolYear'] . ' ' . ($previous['term'] ?: '(no term)');
            $add('grades', 'Previous-term grades', $complete ? 'PASS' : 'BLOCKED', 'The final grades of ' . $label . ($complete ? ' are tagged complete.' : ' are not tagged complete. Ask the registrar to review Final grades.'), 'Registrar', 'student', ['id' => $docId], 'ENROLLMENT', null, true);
            $tag = GS::completionFor($docId, $previous['schoolYear'], $previous['term']);
            $pending = array_merge((array) ($tag['pending'] ?? []), array_filter((array) ($tag['subjects'] ?? []), fn($s) => strtoupper((string) ($s['mark'] ?? '')) === 'INC'));
            if ($pending || !empty($tag['incomplete'])) {
                $codes = array_values(array_filter(array_unique(array_column($pending, 'subjectCode'))));
                $add('incomplete', 'Incomplete or missing grades', Settings::on('eligibility_block_incomplete') ? 'BLOCKED' : 'WARNING', 'Unresolved previous-term grades' . ($codes ? ': ' . implode(', ', $codes) : '') . '. ' . (Settings::on('eligibility_block_incomplete') ? 'Resolve these before enrollment.' : 'School policy allows registration; configured subject prerequisites still apply.'), 'Registrar', 'student', ['id' => $docId]);
            }
        } else $add('grades', 'Previous-term grades', 'INFO', 'No earlier term on record; no previous-term completion is required.', 'Registrar');

        $returning = $docId !== '' && Registration::studentType($docId, $number, $sy, $term)['key'] === 'RETURNING';
        $readmitted = !$returning || ($admission && $admission['applicant_type'] === 'RETURNEE' && $admission['status'] === 'ADMITTED' && $admission['school_year'] === $sy)
            || Db::val("SELECT 1 FROM sis_admissions WHERE applicant_type = 'RETURNEE' AND status = 'ADMITTED' AND student_number = ? AND school_year = ?", [$number, $sy]);
        if (!$readmitted) $readmitted = (bool) Db::val("SELECT 1 FROM sis_portal_enrollments WHERE student_number=? AND school_year=? AND term=? AND status='ACCEPTED'", [$number,$sy,$term]);
        $add('readmission', 'Readmission', $readmitted ? 'PASS' : 'BLOCKED', $readmitted ? 'No outstanding readmission requirement.' : 'Returning student: an approved readmission request for ' . $sy . ' is required.', 'Admission', 'admission', [], 'ENROLLMENT', null, true);
        $calendar = TermCalendar::get($sy, $term);
        $closed = $calendar && (date('Y-m-d') < $calendar['enrollment_start'] || date('Y-m-d') > $calendar['enrollment_end']);
        $add('calendar', 'Enrollment period', $closed ? 'BLOCKED' : ($calendar ? 'PASS' : 'INFO'), $calendar ? 'Enrollment for ' . $sy . ' ' . $term . ' is open from ' . $calendar['enrollment_start'] . ' to ' . $calendar['enrollment_end'] . ($closed ? '. The enrollment period is closed.' : '. Enrollment is open.') : 'No enrollment dates configured; existing open-enrollment policy applies.', 'Registrar', '', [], 'ENROLLMENT', null, true);

        $outstanding = BalanceEnrollment::outstanding($number, $sy, $term, Db::inTx());
        $case = Db::one("SELECT * FROM sis_balance_cases WHERE student_number = ? AND target_school_year = ? AND target_term = ? AND status IN ('PENDING','APPROVED') ORDER BY id DESC LIMIT 1" . (Db::inTx() ? ' FOR UPDATE' : ''), [$number, $sy, $term]);
        $balanceHold = BalanceEnrollment::enabled() && Settings::on('balance_require_approval') && $outstanding['cents'] > 0 && (!$case || $case['status'] !== 'APPROVED')
            ? 'Previous-semester outstanding balance: ₱' . Money::fmt($outstanding['cents']) . '. Settle the balance at Cashier or obtain a valid enabled balance arrangement / special approval for this term.' : null;
        $balanceState = $balanceHold ? ((Settings::on('balance_allow_arrangement') || Settings::on('balance_allow_special')) ? 'NEEDS_APPROVAL' : 'BLOCKED') : ($outstanding['cents'] > 0 ? 'WARNING' : 'PASS');
        $balanceText = $balanceHold ?: ($outstanding['cents'] > 0 ? 'Previous balance: ₱' . Money::fmt($outstanding['cents']) . '. ' . ($case && $case['status'] === 'APPROVED' ? 'Approved balance case #' . $case['id'] . ' applies to this student and term.' : 'Balance blocking is disabled by the current school policy.') : 'No unpaid balance from an earlier term.');
        // An approval is student/term scoped and cannot silently waive its payment condition.
        if (BalanceEnrollment::enabled() && Settings::on('balance_require_approval') && $outstanding['cents'] > 0 && $case && $case['status'] === 'APPROVED') {
            if ($outstanding['cents'] > Money::cents($case['balance_amount'])) {
                $balanceState = 'BLOCKED'; $balanceText = 'The current previous balance exceeds the amount reviewed in approved case #' . $case['id'] . '. Accounting must review the changed balance.';
            } elseif ($case['option_code'] === 'ARRANGEMENT') {
                $required = max(Money::cents(Settings::get('balance_min_payment')), Money::cents($case['requested_payment']));
                $paid = min(max(0, Money::cents($case['balance_amount']) - $outstanding['cents']), self::arrangementPayments($number, $sy, $term, $case['created_at']));
                if ($paid < $required) { $balanceState = 'BLOCKED'; $balanceText = 'Approved arrangement #' . $case['id'] . ' requires ₱' . Money::fmt($required) . ' paid toward earlier-term balances after the request. Posted, non-voided payments: ₱' . Money::fmt($paid) . '. Pay the remaining ₱' . Money::fmt($required - $paid) . ' at Cashier.'; }
            }
        }
        $add('balance', 'Previous balance', $balanceState, $balanceText, 'Cashier / Accounting', 'balance', ['student' => $number, 'sy' => $sy, 'term' => $term]);

        $holds = Db::all("SELECT * FROM sis_eligibility_holds WHERE student_number = ? AND released_at IS NULL AND (school_year IS NULL OR school_year = ?) AND (term IS NULL OR term = ?) AND (expires_on IS NULL OR expires_on >= CURRENT_DATE) ORDER BY id" . (Db::inTx() ? ' FOR UPDATE' : ''), [$number, $sy, $term]);
        foreach ($holds as $hold) $add('hold_' . $hold['id'], ucfirst(strtolower($hold['kind'])) . ' hold', 'BLOCKED', $hold['reason'], $hold['office'], 'eligibility', ['student' => $number, 'sy' => $sy, 'term' => $term]);
        if (!$holds) $add('holds', 'Enrollment holds', 'PASS', 'No active explicit enrollment holds for this student and term.', 'Registrar');
        $add('documents', 'Document verification', 'INFO', 'Automatic document clearance is not connected. Staff must record a DOCUMENT hold for a missing required document; this result does not certify document completeness.', 'Admission', 'eligibility', ['student' => $number, 'sy' => $sy, 'term' => $term]);

        // v55: clearance of the student's previous term (a warning: the registrar can confirm with a reason and their password)
        if ($number !== '' && ($need = Clearance::requirement($number, $sy, $term))) {
            $p = $need['registration'];
            $ov = $registrationId ? Db::one('SELECT reason FROM sis_clearance_overrides WHERE registration_id = ? ORDER BY id DESC LIMIT 1', [$registrationId]) : null;
            if ($need['complete']) $add('clearance', 'Clearance', 'PASS', 'Clearance of ' . $p['school_year'] . ' ' . $p['term'] . ' is completed' . ($need['final'] ? ' and approved.' : '.'), 'Registrar', 'clearance', ['reg' => $p['id']]);
            elseif ($ov) $add('clearance', 'Clearance', 'OVERRIDDEN', 'Clearance of ' . $p['school_year'] . ' ' . $p['term'] . ' was not completed; the registrar confirmed anyway: ' . $ov['reason'], 'Registrar', 'clearance', ['reg' => $p['id']]);
            else $add('clearance', 'Clearance', 'WARNING', 'Clearance of ' . $p['school_year'] . ' ' . $p['term'] . ' is not completed. Pending: ' . implode('; ', $need['pending']) . '. The registrar can still confirm the enrollment with a reason and their password.', 'Registrar', 'clearance', ['reg' => $p['id']]);
        }

        $rules = Db::all('SELECT * FROM sis_subject_prerequisites ORDER BY id');
        $subjects = [];
        foreach (GS::subjects() as $s) $subjects[$s['id']] = $s;
        $completions = $docId !== '' ? GS::completions($docId) : [];
        $passed = self::passedSubjectCodes($completions, $sy, $term);
        $matched = 0;
        foreach ($rules as $rule) {
            if (!in_array($rule['subject_doc_id'], $subjectIds, true)) continue;
            $matched++;
            $s = $subjects[$rule['subject_doc_id']] ?? null; $p = $subjects[$rule['prerequisite_doc_id']] ?? null;
            $code = trim((string) ($p['subjectCode'] ?? ''));
            $codeMatches = array_filter($subjects, fn($subject) => strcasecmp(trim((string) ($subject['subjectCode'] ?? '')), $code) === 0);
            $ok = count($codeMatches) === 1 && $code !== '' && isset($passed[strtoupper($code)]);
            // v54: the registrar allowed this subject without the prerequisite, with a reason
            $ov = !$ok && $registrationId ? Db::one('SELECT reason FROM sis_prereq_overrides WHERE registration_id = ? AND subject_doc_id = ?', [$registrationId, $rule['subject_doc_id']]) : null;
            if ($ov) { $add('prerequisite_' . $rule['id'], 'Subject prerequisite', 'OVERRIDDEN', ($s['subjectCode'] ?? '') . ' allowed without ' . ($code ?: 'its prerequisite') . ' by the registrar: ' . $ov['reason'], 'Registrar', '', [], 'SUBJECT', $rule['subject_doc_id']); continue; }
            $add('prerequisite_' . $rule['id'], 'Subject prerequisite', $ok ? 'PASS' : 'BLOCKED', ($s['subjectCode'] ?? $rule['subject_doc_id']) . ' requires a completed passing grade in ' . ($code ?: 'the configured prerequisite (subject record missing)') . ($ok ? '.' : '. Remove this subject from the draft or resolve the prerequisite first.'), 'Registrar', $docId !== '' ? 'student' : '', $docId !== '' ? ['id' => $docId] : [], 'SUBJECT', $rule['subject_doc_id']);
        }
        if (!$matched) $add('prerequisites', 'Subject prerequisites', 'INFO', $subjectIds ? 'No prerequisite mappings apply to the selected subjects. Configure mappings before relying on this check.' : 'Select subjects to evaluate configured prerequisites.', 'Registrar');
        foreach ($subjectIds as $sid) if (!isset($subjects[$sid])) $add('subject_' . $sid, 'Subject record', 'BLOCKED', 'A selected subject no longer exists. Remove it from the draft.', 'Registrar', '', [], 'SUBJECT', $sid);
        // Only the three legacy creation exceptions are eligible for explicit administrator override.
        if ($override !== null && trim($override) !== '' && Auth::can('users.manage')) {
            foreach ($checks as &$c) if ($c['state'] === 'BLOCKED' && $c['overrideable']) { $c['state'] = 'OVERRIDDEN'; $c['override_reason'] = mb_substr(trim($override), 0, 255); }
            unset($c);
        }
        $result = self::summarize($checks, $number, $sy, $term, $subjectIds);
        $result['balance_case_id'] = $case && $case['status'] === 'APPROVED' ? (int) $case['id'] : null;
        $result['policies'] = ['balance_enabled' => BalanceEnrollment::enabled(), 'balance_approval_required' => Settings::on('balance_require_approval'), 'block_incomplete' => Settings::on('eligibility_block_incomplete')];
        return $result;
    }

    private static function arrangementPayments(string $number, string $sy, string $term, string $since): int
    {
        $paid = 0;
        $order = fn(string $t) => ($i = array_search($t, TERMS, true)) === false ? -1 : $i;
        foreach (Db::all("SELECT p.amount, r.school_year, r.term FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id WHERE r.student_number = ? AND r.status <> 'CANCELLED' AND p.status = 'POSTED' AND p.paid_at >= ?" . (Db::inTx() ? ' FOR UPDATE' : ''), [$number, $since]) as $p) {
            if ([(string) $p['school_year'], $order($p['term'])] < [$sy, $order($term)]) $paid += Money::cents($p['amount']);
        }
        return $paid;
    }

    /** Only an explicit Passed result in an earlier finalized term is evidence; never guess a grading scale. */
    public static function passedSubjectCodes(array $completions, string $sy, string $term): array
    {
        $passed = [];
        $order = fn(string $t) => ($i = array_search($t, TERMS, true)) === false ? -1 : $i;
        foreach ($completions as $completion) {
            if (empty($completion['term']) && (string) ($completion['schoolYear'] ?? '') >= $sy) continue;
            if ([(string) ($completion['schoolYear'] ?? ''), $order((string) ($completion['term'] ?? ''))] >= [$sy, $order($term)]) continue;
            foreach ((array) ($completion['subjects'] ?? []) as $s) {
                if (empty($s['mark']) && is_numeric($s['finalGrade'] ?? null) && strcasecmp(trim((string) ($s['remarks'] ?? '')), 'Passed') === 0 && trim((string) ($s['subjectCode'] ?? '')) !== '') $passed[strtoupper(trim($s['subjectCode']))] = true;
            }
        }
        return $passed;
    }

    public static function summarize(array $checks, string $number, string $sy, string $term, array $subjectIds = []): array
    {
        $blocked = array_values(array_filter($checks, fn($c) => $c['state'] === 'BLOCKED'));
        $approval = array_values(array_filter($checks, fn($c) => $c['state'] === 'NEEDS_APPROVAL'));
        return ['result' => $blocked ? 'BLOCKED' : ($approval ? 'NEEDS_APPROVAL' : 'ELIGIBLE'), 'can_confirm' => !$blocked && !$approval,
            'can_create' => !array_filter(array_merge($blocked, $approval), fn($c) => $c['scope'] === 'ENROLLMENT'),
            'student_number' => $number, 'school_year' => $sy, 'term' => $term, 'subject_ids' => array_values($subjectIds),
            'blocked_subjects' => array_values(array_unique(array_filter(array_column($blocked, 'subject')))),
            'checks' => $checks, 'policy_version' => self::VERSION, 'checked_at' => date(DATE_ATOM)];
    }

    public static function assertAllowed(array $result, bool $creating = false): void
    {
        if ($result[$creating ? 'can_create' : 'can_confirm']) return;
        $bad = array_filter($result['checks'], fn($c) => in_array($c['state'], ['BLOCKED', 'NEEDS_APPROVAL'], true) && (!$creating || $c['scope'] === 'ENROLLMENT'));
        throw new AppError("Enrollment eligibility: " . str_replace('_', ' ', $result['result']) . "\n• " . implode("\n• ", array_column($bad, 'message')));
    }

    public static function forRegistration(array $r, ?string $override = null): array
    {
        return self::check($r['student_doc_id'], $r['student_number'], $r['school_year'], $r['term'], Db::col('SELECT subject_doc_id FROM sis_registration_subjects WHERE registration_id = ?', [$r['id']]), (int) $r['id'], null, $override);
    }

    public static function record(int $registrationId, string $stage, array $result): void
    {
        Db::insert('sis_eligibility_decisions', ['registration_id' => $registrationId, 'stage' => $stage, 'result' => $result['result'], 'policy_version' => self::VERSION, 'snapshot' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'checked_by' => Auth::id()]);
        Audit::log('eligibility_' . strtolower($stage), 'registration', $registrationId, ['result' => $result['result'], 'policy_version' => self::VERSION]);
    }

    public static function addHold(array $in): void
    {
        Auth::require('eligibility.holds');
        $number = (string) In::str($in, 'student_number', 40, true, 'Student ID');
        $student = GS::studentByNumber($number);
        if (!$student) throw new AppError('Student ID not found.');
        $sy = trim((string) ($in['school_year'] ?? '')); $term = trim((string) ($in['term'] ?? ''));
        if ($sy !== '' && !GS::validSchoolYear($sy)) throw new AppError('Enter a valid school year.');
        if ($term !== '' && ($sy === '' || !in_array($term, TERMS, true))) throw new AppError('A term-specific hold requires a valid school year and term.');
        $expires = trim((string) ($in['expires_on'] ?? ''));
        if ($expires !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) || !checkdate((int) substr($expires,5,2), (int) substr($expires,8,2), (int) substr($expires,0,4)) || $expires < date('Y-m-d'))) throw new AppError('Choose a valid expiration date today or later.');
        $data = ['student_number' => $number, 'school_year' => $sy ?: null, 'term' => $term ?: null, 'kind' => In::enum($in, 'kind', ['ACADEMIC','FINANCIAL','DOCUMENT','OTHER'], true, 'Hold type'), 'reason' => In::str($in,'reason',1000,true,'Reason'), 'office' => In::str($in,'office',100,true,'Responsible office'), 'expires_on' => $expires ?: null, 'created_by' => Auth::id()];
        Db::tx(function () use ($student, $data) {
            Db::one("SELECT id FROM docs WHERE col = 'students' AND id = ? FOR UPDATE", [$student['id']]);
            $id = Db::insert('sis_eligibility_holds', $data); Audit::log('eligibility_hold_added', 'eligibility_hold', $id, $data);
        });
    }

    public static function releaseHold(int $id, string $reason): void
    {
        Auth::require('eligibility.holds');
        if (trim($reason) === '' || mb_strlen($reason) > 1000) throw new AppError('A release reason of at most 1000 characters is required.');
        Db::tx(function () use ($id, $reason) {
            $hold = Db::one('SELECT * FROM sis_eligibility_holds WHERE id = ?', [$id]);
            if (!$hold || $hold['released_at']) throw new AppError('Active hold not found.');
            $s = GS::studentByNumber($hold['student_number']);
            if ($s) Db::one("SELECT id FROM docs WHERE col = 'students' AND id = ? FOR UPDATE", [$s['id']]);
            $count = Db::q('UPDATE sis_eligibility_holds SET released_by = ?, released_at = NOW(), release_reason = ? WHERE id = ? AND released_at IS NULL', [Auth::id(), trim($reason), $id])->rowCount();
            if (!$count) throw new AppError('This hold has already been released.');
            Audit::log('eligibility_hold_released', 'eligibility_hold', $id, ['reason' => trim($reason)]);
        });
    }

    public static function savePrerequisite(string $subject, string $prerequisite): void
    {
        Auth::require('curriculum.edit');
        if ($subject === $prerequisite || !GS::get('subjects', $subject) || !GS::get('subjects', $prerequisite)) throw new AppError('Choose two different existing subjects.');
        // Reject cycles (including indirect chains) before saving a new mapping.
        Db::tx(function () use ($subject, $prerequisite) {
            Db::all("SELECT id FROM docs WHERE col = 'subjects' ORDER BY id FOR UPDATE");
            $graph = [];
            foreach (Db::all('SELECT subject_doc_id, prerequisite_doc_id FROM sis_subject_prerequisites') as $r) $graph[$r['subject_doc_id']][] = $r['prerequisite_doc_id'];
            $todo = [$prerequisite]; $seen = [];
            while ($todo) { $id = array_pop($todo); if ($id === $subject) throw new AppError('This prerequisite would create a circular requirement.'); if (isset($seen[$id])) continue; $seen[$id] = true; foreach ($graph[$id] ?? [] as $p) $todo[] = $p; }
            if (Db::val('SELECT id FROM sis_subject_prerequisites WHERE subject_doc_id = ? AND prerequisite_doc_id = ?', [$subject, $prerequisite])) throw new AppError('That prerequisite mapping already exists.');
            $id = Db::insert('sis_subject_prerequisites', ['subject_doc_id' => $subject, 'prerequisite_doc_id' => $prerequisite, 'created_by' => Auth::id()]); Audit::log('eligibility_prerequisite_added', 'prerequisite', $id, ['subject' => $subject, 'prerequisite' => $prerequisite]);
        });
    }
}
