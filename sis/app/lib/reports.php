<?php
/**
 * Report Manager: the printable reports. Each report has a group, a title, remarks, who may run it,
 * its parameters, and a builder that returns a table:
 *   ['cols' => [[label, 'n' for numbers]], 'sections' => [['title' => ?, 'rows' => [[cell…]], 'total' => [cell…]|null]],
 *    'grand' => [cell…]|null, 'summary' => [[label, value]], 'note' => string]
 * Cells are plain text (escaped when shown); money is already formatted.
 */
declare(strict_types=1);

final class Reports
{
    /** key => [group, title, remarks, permissions (any of), parameters, landscape] */
    public const LIST = [
        'census'             => ['Admission and Enrollment', 'Enrollment Census', 'Registered students per section and year level, new and continuing', ['registration', 'payments.view'], ['sy', 'term'], false],
        'class_list'         => ['Admission and Enrollment', 'Enrollment List by Section', 'Class list of confirmed (enrolled) students per section', ['registration', 'payments.view'], ['sy', 'term', 'section'], false],
        'admission_list'     => ['Admission and Enrollment', 'Admission List by Date Range', 'Applicants recorded between two dates', ['admission'], ['dates', 'adm_status'], true],
        'admission_summary'  => ['Admission and Enrollment', 'Admission Summary', 'Applicants per program and year level: type and status', ['admission'], ['sy'], true],
        'not_registered'     => ['Admission and Enrollment', 'Admitted, Not Registered', 'Admitted applicants who still need to be registered', ['admission', 'registration'], ['sy'], false],
        'cashier_details'    => ['Cashier and Collections', 'Cashier Report Details', 'Every official receipt per cashier, with cash / check / card / others; voided receipts listed apart', ['payments.view'], ['dates', 'cashier'], true],
        'collection_summary' => ['Cashier and Collections', 'Collection Summary', 'Collections per day and cashier', ['payments.view'], ['dates', 'cashier'], false],
        'voided_receipts'    => ['Cashier and Collections', 'Voided Official Receipts', 'Receipts voided, with the reason', ['payments.view'], ['dates', 'cashier'], false],
        'balances'           => ['Assessment and Balances', 'Students with Balances', 'Accounts receivable: assessment, payments and balance per student', ['registration', 'payments.view'], ['sy', 'term', 'section'], false],
        'fee_summary'        => ['Assessment and Balances', 'Assessment Summary by Fee', 'Total charged per fee category and fee label', ['registration', 'payments.view'], ['sy', 'term'], false],
        'trial_balance'      => ['Accounting', 'Trial Balance', 'Debit and credit balance of every account on a date; the two totals must be equal', ['accounting'], ['asof','fund','department'], false],
        'general_ledger'     => ['Accounting', 'General Ledger', 'Every entry of one account (or all) with the running balance', ['accounting'], ['dates', 'account','fund','department'], true],
        'income_statement'   => ['Accounting', 'Income Statement', 'Fee revenue less scholarships and discounts, less expenses, for a period', ['accounting'], ['dates','fund','department'], false],
        'balance_sheet'      => ['Accounting', 'Balance Sheet', 'Assets, liabilities and fund balance on a date', ['accounting'], ['asof','fund','department'], false],
        'ar_ledger'          => ['Accounting', 'Accounts Receivable Ledger', 'Receivable per student from the ledger, checked against the SIS balance', ['accounting', 'payments.view'], ['sy', 'term'], false],
        'collections_by_account' => ['Accounting', 'Collections by Cash and Bank Account', 'Payments booked to each cash, bank and e-wallet account; voided ones reversed', ['accounting', 'payments.view'], ['dates'], false],
        'grantees'           => ['Assessment and Balances', 'Scholarship and Discount Grantees', 'Students given a scholarship or discount, with the amount taken off', ['registration', 'payments.view'], ['sy', 'term'], false],
    ];

    /** The reports this user may run, grouped: [group => [key => def]] */
    public static function available(): array
    {
        $out = [];
        foreach (self::LIST as $k => $d) if (Auth::can(...$d[3])) $out[$d[0]][$k] = $d;
        return $out;
    }

    public static function def(string $key): array
    {
        $d = self::LIST[$key] ?? null;
        if (!$d) throw new AppError('Report not found.');
        if (!Auth::can(...$d[3])) throw new Forbidden('You may not run this report.');
        return $d;
    }

    /** Parameters from the request, with defaults. */
    public static function params(array $in): array
    {
        $sy = (string) ($in['sy'] ?? '');
        $from = In::date($in, 'from') ?? date('Y-m-d');
        $to = In::date($in, 'to') ?? $from;
        if ($to < $from) [$from, $to] = [$to, $from];
        return [
            'sy' => preg_match('/^\d{4}-\d{4}$/', $sy) ? $sy : selected_sy(),
            'term' => in_array($in['term'] ?? '', TERMS, true) ? $in['term'] : '',
            'from' => $from, 'to' => $to,
            'cashier' => isset($in['cashier']) && ctype_digit((string) $in['cashier']) ? (int) $in['cashier'] : null,
            'section' => trim((string) ($in['section'] ?? '')),
            'adm_status' => in_array($in['adm_status'] ?? '', ['PENDING', 'ADMITTED', 'REJECTED'], true) ? $in['adm_status'] : '',
            'asof' => In::date($in, 'asof') ?? date('Y-m-d'),
            'fund'=>isset(AccountingWorkflow::FUNDS[$in['fund']??''])?(string)$in['fund']:'', 'department'=>mb_substr(trim((string)($in['department']??'')),0,80),
            'account' => isset($in['account']) && ctype_digit((string) $in['account']) ? (int) $in['account'] : null,
        ];
    }

    /** "2026-2027 · 1st Semester · BSIT 1A" — what the report covers. */
    public static function describe(string $key, array $p): string
    {
        $parts = [];
        foreach (self::LIST[$key][4] as $name) {
            if ($name === 'sy') $parts[] = 'School year ' . $p['sy'];
            if ($name === 'term') $parts[] = $p['term'] !== '' ? $p['term'] : 'All terms';
            if ($name === 'section' && $p['section'] !== '') $parts[] = 'Section ' . $p['section'];
            if ($name === 'dates') $parts[] = $p['from'] === $p['to'] ? date('F j, Y', strtotime($p['from'])) : date('M j, Y', strtotime($p['from'])) . ' – ' . date('M j, Y', strtotime($p['to']));
            if ($name === 'cashier') $parts[] = $p['cashier'] ? 'Cashier ' . (string) Db::val('SELECT full_name FROM sis_users WHERE id = ?', [$p['cashier']]) : 'All cashiers';
            if ($name === 'adm_status' && $p['adm_status'] !== '') $parts[] = ucfirst(strtolower($p['adm_status']));
            if ($name === 'asof') $parts[] = 'As of ' . date('F j, Y', strtotime($p['asof']));
            if($name==='fund')$parts[]=$p['fund']?AccountingWorkflow::FUNDS[$p['fund']]:'All funds';
            if($name==='department')$parts[]=$p['department']?:'All departments';
            if ($name === 'account') $parts[] = $p['account'] ? (($a = Accounting::account($p['account'])) ? $a['code'] . ' ' . $a['name'] : '') : 'All accounts';
        }
        return implode(' · ', $parts);
    }

    public static function run(string $key, array $p): array
    {
        self::def($key);
        $method = 'r_' . $key;
        return self::$method($p);
    }

    // ------------------------------------------------------------------ helpers
    private static function m(int $cents): string { return Money::fmt($cents); }

    /** Registrations of a school year (and term / section), not voided. */
    private static function regs(array $p, string $order = 'r.year_level, r.section_name, r.student_name', bool $enrollmentsOnly=false, bool $confirmedOnly=false): array
    {
        $where = ["r.school_year = ?", "r.status <> 'CANCELLED'"]; $args = [$p['sy']];
        if($enrollmentsOnly)$where[]="r.record_origin='ENROLLMENT'";
        if($confirmedOnly)$where[]='r.locked_at IS NOT NULL'; // v56: official lists show confirmed enrollments only
        if ($p['term'] !== '') { $where[] = 'r.term = ?'; $args[] = $p['term']; }
        if ($p['section'] !== '') { $where[] = 'r.section_name = ?'; $args[] = $p['section']; }
        return Db::all('SELECT r.* FROM sis_registrations r WHERE ' . implode(' AND ', $where) . " ORDER BY $order", $args);
    }

    private static function payments(array $p, ?string $status = null): array
    {
        $where = ['DATE(p.paid_at) BETWEEN ? AND ?']; $args = [$p['from'], $p['to']];
        if ($status) { $where[] = 'p.status = ?'; $args[] = $status; }
        if ($p['cashier']) { $where[] = 'p.received_by = ?'; $args[] = $p['cashier']; }
        return Db::all('SELECT p.*, r.reg_no, r.student_name, r.student_number, u.full_name AS cashier FROM sis_payments p
                          JOIN sis_registrations r ON r.id = p.registration_id JOIN sis_users u ON u.id = p.received_by
                         WHERE ' . implode(' AND ', $where) . ' ORDER BY u.full_name, p.paid_at, p.id', $args);
    }

    private static function methodCol(string $method): int { return match ($method) { 'CASH' => 0, 'CHECK' => 1, 'CARD' => 2, default => 3 }; }

    // ------------------------------------------------------------------ Admission and Enrollment
    private static function r_census(array $p): array
    {
        $p['section'] = '';
        $new = array_flip(array_column(Db::all("SELECT student_number FROM sis_admissions WHERE status = 'ADMITTED' AND school_year = ?", [$p['sy']]), 'student_number'));
        $by = [];
        foreach (self::regs($p,'r.year_level, r.section_name, r.student_name',true) as $r) {
            $k = $r['year_level'] . "\t" . $r['section_name'];
            $by[$k] ??= ['level' => $r['year_level'], 'section' => $r['section_name'], 'n' => 0, 'new' => 0, 'units' => 0.0, 'done' => 0];
            $by[$k]['n']++; $by[$k]['units'] += (float) $r['total_units'];
            if (isset($new[$r['student_number']])) $by[$k]['new']++;
            if ($r['locked_at']) $by[$k]['done']++;
        }
        uksort($by, fn($a, $b) => [array_search(explode("\t", $a)[0], YEAR_LEVELS), $a] <=> [array_search(explode("\t", $b)[0], YEAR_LEVELS), $b]);
        $sections = []; $grand = [0, 0, 0, 0];
        foreach ($by as $x) {
            $s = &$sections[$x['level']];
            $s ??= ['title' => $x['level'], 'rows' => [], 't' => [0, 0, 0, 0]];
            $s['rows'][] = [$x['section'], (string) $x['n'], (string) $x['new'], (string) ($x['n'] - $x['new']), (string) $x['done'], number_format($x['units'], 1)];
            foreach ([$x['n'], $x['new'], $x['n'] - $x['new'], $x['done']] as $i => $v) { $s['t'][$i] += $v; $grand[$i] += $v; }
            unset($s);
        }
        $out = [];
        foreach ($sections as $s) $out[] = ['title' => $s['title'], 'rows' => $s['rows'], 'total' => ['Subtotal · ' . $s['title'], ...array_map('strval', $s['t']), '']];
        $total = $grand[0];
        return ['cols' => [['Section'], ['Registered', 'n'], ['New', 'n'], ['Continuing', 'n'], ['Confirmed (enrolled)', 'n'], ['Total units', 'n']],
            'sections' => $out, 'grand' => $out ? ['Grand total', ...array_map('strval', $grand), ''] : null,
            'summary' => [['Registered students', number_format($total)], ['Sections', (string) count($by)], ['New students', number_format($grand[1])]],
            'note' => 'New = admitted through Admission for this school year; continuing = everyone else.'];
    }

    private static function r_class_list(array $p): array
    {
        $out = [];
        foreach (self::regs($p, 'r.section_name, r.student_name',true,true) as $r) {
            $s = &$out[$r['section_name']];
            $s ??= ['title' => $r['section_name'] . ' · ' . $r['year_level'], 'rows' => [], 'total' => null];
            $s['rows'][] = [(string) (count($s['rows']) + 1), $r['student_number'], mb_strtoupper($r['student_name']), $r['term'], (string) $r['total_units'], $r['reg_no']];
            unset($s);
        }
        foreach ($out as &$s) $s['total'] = [count($s['rows']) . ' student(s)', '', '', '', '', ''];
        unset($s);
        $n = array_sum(array_map(fn($s) => count($s['rows']), $out));
        return ['cols' => [['No.', 'n'], ['Student ID'], ['Student name'], ['Term'], ['Units', 'n'], ['Registration No.']], 'sections' => array_values($out), 'grand' => null,
            'summary' => [['Enrolled students (confirmed)', number_format($n)], ['Sections', (string) count($out)]], 'note' => 'Only confirmed enrollments are listed. Pending registrations stay in Registration until the enrollment is confirmed.'];
    }

    private static function r_admission_list(array $p): array
    {
        $where = ['DATE(a.created_at) BETWEEN ? AND ?']; $args = [$p['from'], $p['to']];
        if ($p['adm_status'] !== '') { $where[] = 'a.status = ?'; $args[] = $p['adm_status']; }
        $rows = [];
        foreach (Db::all('SELECT a.* FROM sis_admissions a WHERE ' . implode(' AND ', $where) . ' ORDER BY a.created_at, a.id', $args) as $a) {
            $rows[] = [$a['admission_no'], date('m/d/Y', strtotime($a['created_at'])), mb_strtoupper($a['last_name'] . ', ' . $a['first_name']), Admission::TYPES[$a['applicant_type']],
                (string) $a['program'], $a['year_level'], $a['school_year'], $a['status'], (string) $a['student_number'], (string) $a['phone']];
        }
        return ['cols' => [['Admission No.'], ['Date'], ['Applicant'], ['Type'], ['Program'], ['Year level'], ['School year'], ['Status'], ['Student ID'], ['Mobile']],
            'sections' => [['title' => null, 'rows' => $rows, 'total' => null]], 'grand' => null, 'summary' => [['Applicants', (string) count($rows)]], 'note' => ''];
    }

    private static function r_admission_summary(array $p): array
    {
        $by = [];
        foreach (Db::all('SELECT * FROM sis_admissions WHERE school_year = ?', [$p['sy']]) as $a) {
            $k = ($a['program'] ?: '—') . "\t" . $a['year_level'];
            $by[$k] ??= ['NEW' => 0, 'CONTINUING' => 0, 'TRANSFEREE' => 0, 'UNIT_EARNER' => 0, 'RETURNEE' => 0, 'PENDING' => 0, 'ADMITTED' => 0, 'REJECTED' => 0, 'CANCELLED' => 0, 'n' => 0];
            $by[$k][$a['applicant_type']] = ($by[$k][$a['applicant_type']] ?? 0) + 1; $by[$k][$a['status']] = ($by[$k][$a['status']] ?? 0) + 1; $by[$k]['n']++;
        }
        ksort($by);
        $keys = ['NEW', 'CONTINUING', 'TRANSFEREE', 'UNIT_EARNER', 'RETURNEE', 'PENDING', 'ADMITTED', 'REJECTED', 'n'];
        $rows = []; $g = array_fill_keys($keys, 0);
        foreach ($by as $k => $x) {
            [$prog, $lvl] = explode("\t", $k);
            $rows[] = [$prog, $lvl, ...array_map(fn($c) => (string) $x[$c], $keys)];
            foreach ($keys as $c) $g[$c] += $x[$c];
        }
        return ['cols' => [['Program'], ['Year level'], ['New', 'n'], ['Continuing', 'n'], ['Transferee', 'n'], ['Unit Earner', 'n'], ['Returnee', 'n'], ['Pending', 'n'], ['Admitted', 'n'], ['Rejected', 'n'], ['Total', 'n']],
            'sections' => [['title' => null, 'rows' => $rows, 'total' => null]], 'grand' => $rows ? ['Total', '', ...array_map('strval', array_values($g))] : null,
            'summary' => [['Applicants', (string) $g['n']], ['Admitted', (string) $g['ADMITTED']], ['Pending', (string) $g['PENDING']]], 'note' => ''];
    }

    private static function r_not_registered(array $p): array
    {
        $rows = [];
        foreach (Db::all("SELECT a.* FROM sis_admissions a WHERE a.status = 'ADMITTED' AND a.school_year = ?
                            AND NOT EXISTS (SELECT 1 FROM sis_registrations r WHERE r.student_number = a.student_number AND r.record_origin='ENROLLMENT' AND r.status <> 'CANCELLED') ORDER BY a.last_name, a.first_name", [$p['sy']]) as $a) {
            $rows[] = [(string) $a['student_number'], mb_strtoupper($a['last_name'] . ', ' . $a['first_name']), (string) $a['program'], $a['year_level'], $a['decided_at'] ? date('m/d/Y', strtotime($a['decided_at'])) : '', (string) $a['phone'], (string) $a['guardian_phone']];
        }
        return ['cols' => [['Student ID'], ['Name'], ['Program'], ['Year level'], ['Admitted on'], ['Mobile'], ["Guardian's mobile"]],
            'sections' => [['title' => null, 'rows' => $rows, 'total' => null]], 'grand' => null, 'summary' => [['Still to register', (string) count($rows)]], 'note' => ''];
    }

    // ------------------------------------------------------------------ Cashier and Collections
    private static function r_cashier_details(array $p): array
    {
        $out = []; $grand = [0, 0, 0, 0, 0]; $voided = [];
        foreach (self::payments($p) as $x) {
            $amt = Money::cents($x['amount']);
            if ($x['status'] === 'VOIDED') { $voided[] = $x; continue; }
            $cols = ['0.00', '0.00', '0.00', '0.00']; $c = self::methodCol($x['method']); $cols[$c] = self::m($amt);
            $s = &$out[$x['cashier']];
            $s ??= ['title' => 'Cashier: ' . mb_strtoupper($x['cashier']), 'rows' => [], 't' => [0, 0, 0, 0, 0]];
            $s['rows'][] = [$x['receipt_no'], date('m/d/Y g:i A', strtotime($x['paid_at'])), mb_strtoupper($x['student_name']), $x['student_number'], $x['reg_no'],
                $x['method'] . ($x['reference_no'] ? ' ' . $x['reference_no'] : ''), ...$cols, self::m($amt)];
            $s['t'][$c] += $amt; $s['t'][4] += $amt; $grand[$c] += $amt; $grand[4] += $amt;
            unset($s);
        }
        $sections = [];
        foreach ($out as $s) $sections[] = ['title' => $s['title'], 'rows' => $s['rows'], 'total' => [count($s['rows']) . ' receipt(s)', '', '', '', '', 'Subtotal', ...array_map([self::class, 'm'], $s['t'])]];
        if ($voided) {
            $rows = [];
            foreach ($voided as $x) $rows[] = [$x['receipt_no'], date('m/d/Y g:i A', strtotime($x['paid_at'])), mb_strtoupper($x['student_name']), $x['student_number'], $x['reg_no'], 'VOID: ' . $x['void_reason'], '', '', '', '', self::m(Money::cents($x['amount']))];
            $sections[] = ['title' => 'Voided (not counted)', 'rows' => $rows, 'total' => null, 'void' => true];
        }
        $n = array_sum(array_map(fn($s) => count($s['rows']), $out));
        return ['cols' => [['O.R. No.'], ['Date / time'], ['Payer'], ['Student ID'], ['Registration'], ['Method / ref.'], ['Cash', 'n'], ['Check', 'n'], ['Card', 'n'], ['Others', 'n'], ['Total', 'n']],
            'sections' => $sections, 'grand' => $n ? ['Grand total', '', '', '', '', $n . ' receipt(s)', ...array_map([self::class, 'm'], $grand)] : null,
            'summary' => [['Total cash', self::m($grand[0])], ['Total check', self::m($grand[1])], ['Total card', self::m($grand[2])], ['Total others (GCash, Maya, Bank)', self::m($grand[3])], ['Grand total', self::m($grand[4])], ['Voided receipts', (string) count($voided)]],
            'note' => 'Others = GCash, Maya and bank transfers.', 'signs' => ['Prepared by (Cashier)', 'Checked by', 'Received by (Accounting)']];
    }

    private static function r_collection_summary(array $p): array
    {
        $by = [];
        foreach (self::payments($p, 'POSTED') as $x) {
            $k = substr($x['paid_at'], 0, 10) . "\t" . $x['cashier'];
            $by[$k] ??= [0, 0, 0, 0, 0, 0];
            $amt = Money::cents($x['amount']);
            $by[$k][0]++; $by[$k][1 + self::methodCol($x['method'])] += $amt; $by[$k][5] += $amt;
        }
        ksort($by);
        $rows = []; $g = [0, 0, 0, 0, 0, 0];
        foreach ($by as $k => $v) {
            [$d, $c] = explode("\t", $k);
            $rows[] = [date('m/d/Y', strtotime($d)), mb_strtoupper($c), (string) $v[0], self::m($v[1]), self::m($v[2]), self::m($v[3]), self::m($v[4]), self::m($v[5])];
            foreach ($v as $i => $n) $g[$i] += $n;
        }
        return ['cols' => [['Date'], ['Cashier'], ['Receipts', 'n'], ['Cash', 'n'], ['Check', 'n'], ['Card', 'n'], ['Others', 'n'], ['Total', 'n']],
            'sections' => [['title' => null, 'rows' => $rows, 'total' => null]], 'grand' => $rows ? ['Total', '', (string) $g[0], self::m($g[1]), self::m($g[2]), self::m($g[3]), self::m($g[4]), self::m($g[5])] : null,
            'summary' => [['Receipts', (string) $g[0]], ['Grand total', self::m($g[5])]], 'note' => 'Voided receipts are not counted.'];
    }

    private static function r_voided_receipts(array $p): array
    {
        $rows = []; $t = 0;
        foreach (self::payments($p, 'VOIDED') as $x) {
            $amt = Money::cents($x['amount']); $t += $amt;
            $rows[] = [$x['receipt_no'], date('m/d/Y g:i A', strtotime($x['paid_at'])), mb_strtoupper($x['student_name']), mb_strtoupper($x['cashier']), (string) $x['void_reason'], self::m($amt)];
        }
        return ['cols' => [['O.R. No.'], ['Date / time'], ['Payer'], ['Cashier'], ['Reason'], ['Amount', 'n']],
            'sections' => [['title' => null, 'rows' => $rows, 'total' => null]], 'grand' => $rows ? [count($rows) . ' voided', '', '', '', '', self::m($t)] : null,
            'summary' => [['Voided receipts', (string) count($rows)], ['Amount', self::m($t)]], 'note' => ''];
    }

    // ------------------------------------------------------------------ Assessment and Balances
    private static function r_balances(array $p): array
    {
        $out = []; $g = [0, 0, 0];
        foreach (self::regs($p, 'r.section_name, r.student_name') as $r) {
            $bal = Registration::balance($r);
            if ($bal <= 0) continue;
            $tot = Money::cents($r['total_amount']); $paid = Money::cents($r['amount_paid']);
            $s = &$out[$r['section_name']];
            $s ??= ['title' => $r['section_name'] . ' · ' . $r['year_level'], 'rows' => [], 't' => [0, 0, 0]];
            $s['rows'][] = [$r['student_number'], mb_strtoupper($r['student_name']), $r['term'], $r['reg_no'], self::m($tot), self::m($paid), self::m($bal)];
            $s['t'][0] += $tot; $s['t'][1] += $paid; $s['t'][2] += $bal; $g[0] += $tot; $g[1] += $paid; $g[2] += $bal;
            unset($s);
        }
        $sections = [];
        foreach ($out as $s) $sections[] = ['title' => $s['title'], 'rows' => $s['rows'], 'total' => [count($s['rows']) . ' student(s)', '', '', 'Subtotal', ...array_map([self::class, 'm'], $s['t'])]];
        $n = array_sum(array_map(fn($s) => count($s['rows']), $out));
        return ['cols' => [['Student ID'], ['Student name'], ['Term'], ['Registration'], ['Assessment', 'n'], ['Paid', 'n'], ['Balance', 'n']],
            'sections' => $sections, 'grand' => $n ? ['Grand total', $n . ' student(s)', '', '', ...array_map([self::class, 'm'], $g)] : null,
            'summary' => [['Students with balances', (string) $n], ['Balance to collect', self::m($g[2])]], 'note' => ''];
    }

    private static function r_fee_summary(array $p): array
    {
        $where = ["r.school_year = ?", "r.status <> 'CANCELLED'"]; $args = [$p['sy']];
        if($enrollmentsOnly)$where[]="r.record_origin='ENROLLMENT'";
        if ($p['term'] !== '') { $where[] = 'r.term = ?'; $args[] = $p['term']; }
        $rows = Db::all('SELECT i.category_name, i.label_name, COUNT(DISTINCT i.registration_id) AS n, SUM(i.amount) AS total
                           FROM sis_registration_items i JOIN sis_registrations r ON r.id = i.registration_id
                          WHERE ' . implode(' AND ', $where) . ' GROUP BY i.category_name, i.label_name ORDER BY MIN(i.sort_order), i.category_name, i.label_name', $args);
        $out = []; $g = 0;
        foreach ($rows as $x) {
            $amt = Money::cents((string) $x['total']); $g += $amt;
            $s = &$out[$x['category_name']];
            $s ??= ['title' => $x['category_name'], 'rows' => [], 't' => 0];
            $s['rows'][] = [$x['label_name'], (string) $x['n'], self::m($amt)];
            $s['t'] += $amt;
            unset($s);
        }
        $sections = [];
        foreach ($out as $s) $sections[] = ['title' => $s['title'], 'rows' => $s['rows'], 'total' => ['Subtotal · ' . $s['title'], '', self::m($s['t'])]];
        return ['cols' => [['Fee'], ['Students', 'n'], ['Amount', 'n']], 'sections' => $sections, 'grand' => $sections ? ['Total assessment', '', self::m($g)] : null,
            'summary' => [['Total assessment', self::m($g)]], 'note' => 'Voided registrations are not counted. Discounts show as negative amounts.'];
    }

    private static function r_grantees(array $p): array
    {
        $out = []; $g = 0;
        foreach (Discounts::grantees($p['sy'], $p['term']) as $x) {
            $s = &$out[$x['name']];
            $s ??= ['title' => $x['name'] . ' (' . ($x['kind'] === 'SCHOLARSHIP' ? 'Scholarship' : 'Discount') . ')', 'rows' => [], 't' => 0];
            $s['rows'][] = [$x['student_number'], mb_strtoupper($x['student_name']), $x['section_name'], $x['term'], (string) $x['remarks'], self::m($x['amount'])];
            $s['t'] += $x['amount']; $g += $x['amount'];
            unset($s);
        }
        $sections = [];
        foreach ($out as $s) $sections[] = ['title' => $s['title'], 'rows' => $s['rows'], 'total' => [count($s['rows']) . ' grantee(s)', '', '', '', 'Subtotal', self::m($s['t'])]];
        $n = array_sum(array_map(fn($s) => count($s['rows']), $out));
        return ['cols' => [['Student ID'], ['Student name'], ['Section'], ['Term'], ['Remarks'], ['Amount off', 'n']], 'sections' => $sections,
            'grand' => $n ? ['Total', $n . ' grantee(s)', '', '', '', self::m($g)] : null, 'summary' => [['Grantees', (string) $n], ['Total taken off', self::m($g)]], 'note' => ''];
    }

    // ------------------------------------------------------------ accounting
    private static function signed(array $a, int $debitPositive): int { return Accounting::normal($a, $debitPositive); }

    private static function r_trial_balance(array $p): array
    {
        $bal = Accounting::balances($p['asof'],null,$p['fund']?:null,$p['department']?:null); $rows = []; $dr = 0; $cr = 0;
        foreach (Accounting::accounts() as $a) {
            $b = $bal[(int) $a['id']] ?? 0;
            if ($b === 0) continue;
            $rows[] = [$a['code'], $a['name'], Accounting::TYPES[$a['type']], $b > 0 ? self::m($b) : '', $b < 0 ? self::m(-$b) : ''];
            if ($b > 0) $dr += $b; else $cr -= $b;
        }
        return ['cols' => [['Code'], ['Account'], ['Type'], ['Debit', 'n'], ['Credit', 'n']], 'sections' => [['title' => null, 'rows' => $rows, 'total' => null]],
            'grand' => ['Total', '', '', self::m($dr), self::m($cr)],
            'summary' => [['Total debits', self::m($dr)], ['Total credits', self::m($cr)], ['In balance', $dr === $cr ? 'Yes' : 'NO — difference ' . self::m($dr - $cr)]],
            'note' => 'Every entry is booked with equal debits and credits, so the totals are always equal.', 'signs' => ['Prepared by (Accounting)', 'Checked by']];
    }

    private static function r_general_ledger(array $p): array
    {
        $accounts = $p['account'] ? array_filter([Accounting::account($p['account'])]) : Accounting::accounts();
        $sections = [];
        foreach ($accounts as $a) {
            $l = Accounting::ledger((int) $a['id'], $p['from'], $p['to'],$p['fund']?:null,$p['department']?:null);
            if (!$l['rows'] && $l['opening'] === 0) continue;
            $run = $l['opening']; $rows = [['', 'Opening balance', '', '', '', self::m(self::signed($a, $run))]]; $d = 0; $c = 0;
            foreach ($l['rows'] as $r) {
                $dc = Money::cents($r['debit']); $cc = Money::cents($r['credit']); $run += $dc - $cc; $d += $dc; $c += $cc;
                $rows[] = [date('m/d/Y', strtotime($r['entry_date'])), $r['entry_no'] . ' · ' . $r['memo'] . ($r['reversed_by_id'] ? ' (reversed)' : ''), $r['line_memo'] ?? '',
                    $dc ? self::m($dc) : '', $cc ? self::m($cc) : '', self::m(self::signed($a, $run))];
            }
            $sections[] = ['title' => $a['code'] . ' · ' . $a['name'], 'rows' => $rows, 'total' => ['', 'Totals and closing balance', '', self::m($d), self::m($c), self::m(self::signed($a, $run))]];
        }
        return ['cols' => [['Date'], ['Entry'], ['Line'], ['Debit', 'n'], ['Credit', 'n'], ['Balance', 'n']], 'sections' => $sections, 'grand' => null,
            'summary' => [['Accounts', (string) count($sections)]], 'note' => 'Balances are shown on each account\'s normal side (debit for assets and expenses, credit for revenue, liabilities and fund balance).'];
    }

    /** Totals per account type for a period (revenue etc.) or up to a date (balance sheet). */
    private static function byType(array $bal, array $types): array
    {
        $out = [];
        foreach (Accounting::accounts() as $a) {
            if (!in_array($a['type'], $types, true)) continue;
            $b = self::signed($a, $bal[(int) $a['id']] ?? 0);
            if ($b !== 0) $out[$a['type']][] = [$a['code'], $a['name'], $b];
        }
        return $out;
    }

    private static function r_income_statement(array $p): array
    {
        $g = self::byType(Accounting::balances($p['to'], $p['from'],$p['fund']?:null,$p['department']?:null), ['REVENUE', 'CONTRA_REVENUE', 'EXPENSE']);
        $sum = fn(string $t) => array_sum(array_column($g[$t] ?? [], 2));
        $sec = fn(string $t, string $title) => ['title' => $title, 'rows' => array_map(fn($r) => [$r[0], $r[1], self::m($r[2])], $g[$t] ?? []), 'total' => ['', 'Total ' . strtolower($title), self::m($sum($t))]];
        $rev = $sum('REVENUE'); $less = $sum('CONTRA_REVENUE'); $exp = $sum('EXPENSE'); $net = $rev - $less - $exp;
        return ['cols' => [['Code'], ['Account'], ['Amount', 'n']],
            'sections' => [$sec('REVENUE', 'Revenue'), $sec('CONTRA_REVENUE', 'Less: scholarships and discounts'), ['title' => null, 'rows' => [['', 'Net revenue', self::m($rev - $less)]], 'total' => null], $sec('EXPENSE', 'Expenses')],
            'grand' => ['', 'Net income (surplus)', self::m($net)],
            'summary' => [['Revenue', self::m($rev)], ['Scholarships and discounts', self::m($less)], ['Expenses', self::m($exp)], ['Net income', self::m($net)]], 'note' => ''];
    }

    private static function r_balance_sheet(array $p): array
    {
        $bal = Accounting::balances($p['asof'],null,$p['fund']?:null,$p['department']?:null);
        $g = self::byType($bal, ['ASSET', 'LIABILITY', 'EQUITY']);
        $pl = self::byType($bal, ['REVENUE', 'CONTRA_REVENUE', 'EXPENSE']);
        $surplus = array_sum(array_column($pl['REVENUE'] ?? [], 2)) - array_sum(array_column($pl['CONTRA_REVENUE'] ?? [], 2)) - array_sum(array_column($pl['EXPENSE'] ?? [], 2));
        $sum = fn(string $t) => array_sum(array_column($g[$t] ?? [], 2));
        $rows = fn(string $t) => array_map(fn($r) => [$r[0], $r[1], self::m($r[2])], $g[$t] ?? []);
        $assets = $sum('ASSET'); $liab = $sum('LIABILITY'); $eq = $sum('EQUITY') + $surplus;
        return ['cols' => [['Code'], ['Account'], ['Amount', 'n']],
            'sections' => [['title' => 'Assets', 'rows' => $rows('ASSET'), 'total' => ['', 'Total assets', self::m($assets)]],
                ['title' => 'Liabilities', 'rows' => $rows('LIABILITY'), 'total' => ['', 'Total liabilities', self::m($liab)]],
                ['title' => 'Fund balance', 'rows' => array_merge($rows('EQUITY'), [['', 'Surplus to date (revenue less discounts and expenses)', self::m($surplus)]]), 'total' => ['', 'Total fund balance', self::m($eq)]]],
            'grand' => ['', 'Total liabilities and fund balance', self::m($liab + $eq)],
            'summary' => [['Total assets', self::m($assets)], ['Liabilities and fund balance', self::m($liab + $eq)], ['In balance', $assets === $liab + $eq ? 'Yes' : 'NO']], 'note' => ''];
    }

    private static function r_ar_ledger(array $p): array
    {
        $ar = (int) (Accounting::map()['ar'] ?? 0);
        $out = []; $g = [0, 0]; $diff = 0;
        foreach (self::regs($p, 'r.section_name, r.student_name') as $r) {
            $led = Money::cents((string) Db::val('SELECT COALESCE(SUM(l.debit) - SUM(l.credit), 0) FROM sis_journal_lines l JOIN sis_journal j ON j.id = l.journal_id WHERE j.status=\'POSTED\' AND j.registration_id = ? AND l.account_id = ?', [$r['id'], $ar]));
            $sis = empty($r['locked_at']) ? -Money::cents($r['amount_paid']) : Registration::balance($r);
            if ($led === 0 && $sis === 0) continue;
            $s = &$out[$r['section_name']];
            $s ??= ['title' => $r['section_name'], 'rows' => [], 't' => [0, 0]];
            $s['rows'][] = [$r['student_number'], mb_strtoupper($r['student_name']), $r['reg_no'], empty($r['locked_at']) ? 'Not confirmed' : 'Confirmed', self::m($led), $led === $sis ? 'OK' : 'Check (' . self::m($sis) . ')'];
            $s['t'][0] += $led; $g[0] += $led; if ($led !== $sis) $diff++;
            unset($s);
        }
        $sections = [];
        foreach ($out as $s) $sections[] = ['title' => $s['title'], 'rows' => $s['rows'], 'total' => ['', 'Subtotal', '', '', self::m($s['t'][0]), '']];
        return ['cols' => [['Student ID'], ['Student'], ['Registration'], ['Enrollment'], ['Receivable', 'n'], ['Matches SIS']], 'sections' => $sections,
            'grand' => ['', 'Total receivable', '', '', self::m($g[0]), ''],
            'summary' => [['Total receivable', self::m($g[0])], ['Not matching the SIS', (string) $diff]],
            'note' => 'Only Accounting-posted entries appear in the ledger. Unposted assessments and receipts cause expected differences until review and posting.'];
    }

    private static function r_collections_by_account(array $p): array
    {
        $rows = Db::all("SELECT a.code, a.name, SUM(l.debit) AS dr, SUM(l.credit) AS cr, COUNT(*) AS n FROM sis_journal_lines l JOIN sis_journal j ON j.id = l.journal_id
                           JOIN sis_accounts a ON a.id = l.account_id WHERE j.status='POSTED' AND j.source = 'PAYMENT' AND a.is_cash = 1 AND j.entry_date BETWEEN ? AND ? GROUP BY a.id ORDER BY a.code", [$p['from'], $p['to']]);
        $out = []; $g = [0, 0, 0];
        foreach ($rows as $r) {
            $d = Money::cents((string) $r['dr']); $c = Money::cents((string) $r['cr']);
            $out[] = [$r['code'], $r['name'], self::m($d), self::m($c), self::m($d - $c)];
            $g[0] += $d; $g[1] += $c; $g[2] += $d - $c;
        }
        return ['cols' => [['Code'], ['Account'], ['Collected', 'n'], ['Voided', 'n'], ['Net', 'n']], 'sections' => [['title' => null, 'rows' => $out, 'total' => null]],
            'grand' => ['', 'Total', self::m($g[0]), self::m($g[1]), self::m($g[2])], 'summary' => [['Net collections', self::m($g[2])]],
            'note' => 'Voided receipts are reversed on the day they were voided.', 'signs' => ['Prepared by (Cashier)', 'Received by (Accounting)']];
    }
}
