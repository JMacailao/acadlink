<?php
declare(strict_types=1);

/** Existing-student balance enrollment policy. Balances are always derived from SIS registrations/payments. */
final class BalanceEnrollment
{
    public static function enabled(): bool { return Settings::on('balance_enrollment_enabled'); }

    public static function roles(string $kind): array
    {
        $raw = Settings::get($kind === 'SPECIAL' ? 'balance_special_approver_roles' : 'balance_arrangement_approver_roles');
        return array_values(array_filter(array_map('trim', explode(',', strtoupper($raw)))));
    }

    public static function canApprove(string $kind): bool
    {
        // The "Approve balances" permission is required; the approver-roles setting can narrow it further.
        if (!Auth::can('balances.approve')) return false;
        return in_array((string) Auth::role(), self::roles($kind), true) || Auth::can('users.manage');
    }

    /** Active previous-semester balances only; no duplicate balance ledger is created. */
    public static function outstanding(string $studentNumber, string $targetSchoolYear, string $targetTerm = '', bool $currentRead = false): array
    {
        $rows = Db::all("SELECT * FROM sis_registrations WHERE student_number = ? AND status = 'REGISTERED' ORDER BY school_year, id" . ($currentRead && Db::inTx() ? ' FOR UPDATE' : ''), [$studentNumber]);
        $targetStart = (int) substr($targetSchoolYear, 0, 4);
        $targetTermIndex = array_search($targetTerm, TERMS, true);
        if ($targetTermIndex === false) $targetTermIndex = 0;
        $total = 0; $items = [];
        foreach ($rows as $r) {
            $start = (int) substr((string) $r['school_year'], 0, 4);
            if ($start > $targetStart) continue;
            if ($r['school_year'] === $targetSchoolYear) {
                $rowTermIndex = array_search($r['term'], TERMS, true);
                if ($rowTermIndex === false || $rowTermIndex >= $targetTermIndex) continue;
            }
            $bal = Registration::balance($r);
            if ($bal <= 0) continue;
            $total += $bal;
            $items[] = ['registration' => $r, 'balance_cents' => $bal];
        }
        return ['cents' => $total, 'items' => $items];
    }

    public static function activeCase(string $studentNumber, string $targetSchoolYear, string $targetTerm): ?array
    {
        return Db::one("SELECT * FROM sis_balance_cases WHERE student_number = ? AND target_school_year = ? AND target_term = ? AND status IN ('PENDING','APPROVED') ORDER BY id DESC LIMIT 1", [$studentNumber, $targetSchoolYear, $targetTerm]);
    }

    /** Display-only carried-forward balance for an approved latest assessment. Payments still post to the original registrations. */
    public static function carriedForward(array $registration): array
    {
        // Display is an independent administrator choice. A school may show an approved
        // prior balance on the assessment even when enrollment blocking is disabled.
        if (!Settings::on('balance_show_on_latest_assessment')) return ['cents' => 0, 'items' => [], 'case' => null];
        $case = Db::one("SELECT * FROM sis_balance_cases WHERE student_number = ? AND target_school_year = ? AND target_term = ? AND status = 'APPROVED' ORDER BY id DESC LIMIT 1", [$registration['student_number'], $registration['school_year'], $registration['term']]);
        if (!$case) return ['cents' => 0, 'items' => [], 'case' => null];
        $outstanding = self::outstanding((string) $registration['student_number'], (string) $registration['school_year'], (string) $registration['term']);
        // Preserve the approved case amount when the original registration is not yet
        // visible to the derived-balance query, while never exceeding the current balance.
        $approvedCents = Money::cents($case['balance_amount']);
        $cents = $outstanding['cents'] > 0 ? $outstanding['cents'] : $approvedCents;
        return ['cents' => $cents, 'items' => $outstanding['items'], 'case' => $case];
    }

    /** Human-readable hold shown in Registration; backend enforcement is assertEligible(). */
    public static function hold(string $studentNumber, string $targetSchoolYear, string $targetTerm): ?string
    {
        if (!self::enabled() || $studentNumber === '') return null;
        $o = self::outstanding($studentNumber, $targetSchoolYear, $targetTerm);
        if ($o['cents'] <= 0) return null;
        if (!Settings::on('balance_require_approval')) return null;
        $min = Money::cents(Settings::get('balance_min_payment'));
        $case = self::activeCase($studentNumber, $targetSchoolYear, $targetTerm);
        if ($case && $case['status'] === 'APPROVED') return null;
        $choices = [];
        if (Settings::on('balance_allow_pay')) $choices[] = 'pay the previous balance';
        if (Settings::on('balance_allow_arrangement')) $choices[] = 'request a payment arrangement';
        if (Settings::on('balance_allow_special')) $choices[] = 'request special approval';
        return 'Previous-semester outstanding balance: ₱' . Money::fmt($o['cents']) . '. ' . ($choices ? 'Before enrollment, ' . implode(', or ', $choices) . '.' : 'No balance-enrollment option is currently enabled.') . ($min > 0 ? ' Minimum payment required by policy: ₱' . Money::fmt($min) . '.' : '');
    }

    public static function assertEligible(string $studentNumber, string $targetSchoolYear, string $targetTerm): void
    {
        if (!self::enabled() || $studentNumber === '') return;
        $o = self::outstanding($studentNumber, $targetSchoolYear, $targetTerm);
        if ($o['cents'] <= 0) return;
        if (!Settings::on('balance_require_approval')) return;
        $case = self::activeCase($studentNumber, $targetSchoolYear, $targetTerm);
        if ($case && $case['status'] === 'APPROVED') return;
        throw new AppError((self::hold($studentNumber, $targetSchoolYear, $targetTerm) ?? 'Outstanding balance must be settled before enrollment.') . ' Registration cannot proceed until eligibility is cleared.');
    }

    public static function request(string $studentNumber, string $schoolYear, string $term, string $option, int $requestedCents, string $reason): int
    {
        if (!in_array($option, ['ARRANGEMENT', 'SPECIAL'], true)) throw new AppError('Choose a valid balance option.');
        if ($option === 'ARRANGEMENT' && !Settings::on('balance_allow_arrangement')) throw new Forbidden('Payment arrangements are disabled by policy.');
        if ($option === 'SPECIAL' && !Settings::on('balance_allow_special')) throw new Forbidden('Special approval is disabled by policy.');
        $studentNumber = trim($studentNumber); $reason = trim($reason);
        if ($studentNumber === '' || $reason === '') throw new AppError('Student ID and reason are required.');
        $o = self::outstanding($studentNumber, $schoolYear, $term);
        if ($o['cents'] <= 0) throw new AppError('This student has no outstanding previous-semester balance.');
        $requestedCents = max(0, $requestedCents);
        if ($requestedCents > $o['cents']) throw new AppError('The initial payment (₱' . Money::fmt($requestedCents) . ') cannot be more than the outstanding balance (₱' . Money::fmt($o['cents']) . ').');
        if ($option === 'ARRANGEMENT' && $requestedCents < Money::cents(Settings::get('balance_min_payment'))) throw new AppError('The requested payment is below the configured minimum payment.');
        $student = GS::studentByNumber($studentNumber);
        $name = (string) ($student['studentName'] ?? $studentNumber);
        return Db::tx(function () use ($studentNumber, $name, $schoolYear, $term, $option, $requestedCents, $reason, $o) {
            $old = Db::one("SELECT id FROM sis_balance_cases WHERE student_number = ? AND target_school_year = ? AND target_term = ? AND status IN ('PENDING','APPROVED') FOR UPDATE", [$studentNumber, $schoolYear, $term]);
            if ($old) throw new AppError('There is already an active balance-enrollment case for this student and term.');
            $id = Db::insert('sis_balance_cases', ['student_number' => $studentNumber, 'student_name' => $name, 'target_school_year' => $schoolYear, 'target_term' => $term, 'option_code' => $option, 'balance_amount' => Money::dec($o['cents']), 'requested_payment' => Money::dec($requestedCents), 'arrangement_rules' => Settings::get('balance_installment_rules'), 'request_reason' => mb_substr($reason, 0, 1000), 'created_by' => Auth::id()]);
            Db::insert('sis_balance_case_events', ['case_id' => $id, 'event_type' => 'REQUESTED', 'actor_id' => Auth::id(), 'details' => json_encode(['option' => $option, 'balance' => Money::dec($o['cents']), 'requested_payment' => Money::dec($requestedCents), 'reason' => $reason])]);
            Audit::log('balance_case_requested', 'balance_case', $id, ['student' => $studentNumber, 'option' => $option, 'balance' => Money::dec($o['cents'])]);
            NotificationCenter::notifyBalanceCase($id, 'REQUESTED');
            return $id;
        });
    }

    public static function decide(int $id, string $decision, string $reason, ?string $expectedStatus = null): void
    {
        $decision = strtoupper($decision); $reason = trim($reason);
        if (!in_array($decision, ['APPROVED', 'REJECTED'], true)) throw new AppError('Invalid decision.');
        if ($reason === '') throw new AppError('Record the decision reason.');
        Db::tx(function () use ($id, $decision, $reason, $expectedStatus) {
            $c = Db::one('SELECT * FROM sis_balance_cases WHERE id = ? FOR UPDATE', [$id]);
            if (!$c || !in_array($c['status'], ['PENDING', 'APPROVED'], true)) throw new AppError('Balance case is not pending or approved.');
            if ($expectedStatus !== null && $c['status'] !== $expectedStatus) throw new AppError('This request changed while you were reviewing it. Refresh and review its latest status before deciding.');
            if ($c['status'] === 'APPROVED') {
                if ($decision !== 'REJECTED') throw new AppError('An approved balance case can only be declined again.');
                if (!NotificationCenter::canDeclineApproved()) throw new Forbidden('Only an administrator, registrar, cashier, or accounting officer can decline an approved balance case.');
            } elseif (!self::canApprove((string) $c['option_code'])) {
                throw new Forbidden('Your role cannot approve this balance option.');
            }
            Db::q('UPDATE sis_balance_cases SET status = ?, reviewed_by = ?, reviewed_at = NOW(), decision_reason = ? WHERE id = ?', [$decision, Auth::id(), mb_substr($reason, 0, 1000), $id]);
            $event = $c['status'] === 'APPROVED' ? 'REVOKED' : $decision;
            Db::insert('sis_balance_case_events', ['case_id' => $id, 'event_type' => $event, 'actor_id' => Auth::id(), 'details' => json_encode(['reason' => $reason, 'previous_status' => $c['status']])]);
            Audit::log('balance_case_' . strtolower($event), 'balance_case', $id, ['student' => $c['student_number'], 'option' => $c['option_code'], 'reason' => $reason]);
            NotificationCenter::notifyBalanceCase($id, $event);
        });
    }
}
