<?php
declare(strict_types=1);

/** Read models shared by the balance directory and notification detail screens. */
final class BalanceWorkspace
{
    public const REQUEST_STATES = ['ALL'=>'All requests','NONE'=>'No request','PENDING'=>'Pending','APPROVED'=>'Approved','REJECTED'=>'Declined','EXPIRED'=>'Expired'];
    public const PAYMENT_STATES = ['ALL'=>'All payment statuses','UNPAID'=>'Unpaid','PARTIAL'=>'Partially paid','SETTLED'=>'Settled'];

    public static function isPrior(array $r, string $sy, string $term): bool
    {
        $start = (int) substr((string) $r['school_year'], 0, 4); $target = (int) substr($sy, 0, 4);
        if ($start > $target) return false;
        if ($r['school_year'] !== $sy) return true;
        $index = array_search($r['term'], TERMS, true); $targetIndex = array_search($term, TERMS, true);
        return $index !== false && $index < ($targetIndex === false ? 0 : $targetIndex);
    }

    public static function priorRegistrations(string $number, string $sy, string $term): array
    {
        return array_values(array_filter(Db::all("SELECT * FROM sis_registrations WHERE student_number = ? AND status = 'REGISTERED' ORDER BY school_year, id", [$number]), fn($r) => self::isPrior($r, $sy, $term)));
    }

    /** Totals cover the target term, before directory search and status filtering. */
    public static function directory(string $sy, string $term): array
    {
        $rows = [];
        foreach (Db::all("SELECT student_number, student_name, school_year, term, total_amount, amount_paid FROM sis_registrations WHERE status = 'REGISTERED' AND school_year <= ?", [$sy]) as $r) {
            if (!self::isPrior($r, $sy, $term)) continue;
            $number = (string) $r['student_number'];
            if (!isset($rows[$number])) $rows[$number] = ['number'=>$number,'name'=>$r['student_name'],'balance'=>0,'paid'=>0,'case'=>null];
            $rows[$number]['balance'] += max(0, Registration::balance($r));
            if (Registration::balance($r)>0) $rows[$number]['paid'] += Money::cents($r['amount_paid']);
        }
        $cases = Db::all('SELECT * FROM sis_balance_cases WHERE target_school_year = ? AND target_term = ? ORDER BY id DESC', [$sy,$term]);
        foreach ($cases as $case) {
            $number = (string) $case['student_number'];
            if (!isset($rows[$number])) $rows[$number] = ['number'=>$number,'name'=>$case['student_name'],'balance'=>0,'paid'=>0,'case'=>null];
            // Prefer an active case; otherwise display the latest historical request.
            $old = $rows[$number]['case'];
            if (!$old || (!in_array($old['status'], ['PENDING','APPROVED'], true) && in_array($case['status'], ['PENDING','APPROVED'], true))) $rows[$number]['case'] = $case;
        }
        $names = [];
        foreach (GS::students() as $s) $names[(string) $s['studentId']] = (string) $s['studentName'];
        foreach ($rows as $number => &$row) {
            $row['name'] = $names[$number] ?? $row['name'];
            $row['payment_state'] = $row['balance'] <= 0 ? 'SETTLED' : ($row['paid'] > 0 ? 'PARTIAL' : 'UNPAID');
            $row['request_state'] = $row['case']['status'] ?? 'NONE';
        }
        unset($row);
        $rows = array_values(array_filter($rows, fn($r) => $r['balance'] > 0 || $r['case']));
        usort($rows, fn($a,$b) => strcasecmp($a['name'],$b['name']) ?: strcmp($a['number'],$b['number']));
        return ['rows'=>$rows,'total_balance'=>array_sum(array_column($rows,'balance')),'students'=>count(array_filter($rows,fn($r)=>$r['balance']>0)),
            'pending'=>count(array_filter($cases,fn($c)=>$c['status']==='PENDING')),'approved'=>count(array_filter($cases,fn($c)=>$c['status']==='APPROVED'))];
    }

    public static function filterRows(array $rows, string $q, string $request, string $payment): array
    {
        $q = mb_strtolower(trim($q));
        return array_values(array_filter($rows, fn($r) => ($q === '' || str_contains(mb_strtolower($r['name'].' '.$r['number']),$q)) && ($request === 'ALL' || $r['request_state'] === $request) && ($payment === 'ALL' || $r['payment_state'] === $payment)));
    }

    public static function case(int $id): ?array
    {
        return Db::one('SELECT c.*, u.full_name AS reviewer_name FROM sis_balance_cases c LEFT JOIN sis_users u ON u.id = c.reviewed_by WHERE c.id = ?', [$id]);
    }

    public static function history(int $id): array
    {
        return Db::all('SELECT e.*, u.full_name AS actor_name FROM sis_balance_case_events e LEFT JOIN sis_users u ON u.id = e.actor_id WHERE e.case_id = ? ORDER BY e.id DESC', [$id]);
    }

    public static function badge(string $status, bool $payment = false): string
    {
        $label = $payment ? (self::PAYMENT_STATES[$status] ?? $status) : (self::REQUEST_STATES[$status] ?? $status);
        $class = match($status) {'APPROVED','SETTLED'=>'positive','PENDING','PARTIAL'=>'waiting','UNPAID','REJECTED'=>'negative',default=>'neutral'};
        return '<span class="workspace-status is-'.$class.'">'.h($label).'</span>';
    }

    public static function relativeTime(string $date): string
    {
        $seconds = max(0,time()-(strtotime($date) ?: time()));
        if ($seconds < 60) return 'Just now';
        if ($seconds < 3600) { $n=(int)floor($seconds/60);return "$n minute".($n===1?'':'s').' ago'; }
        if ($seconds < 86400) { $n=(int)floor($seconds/3600);return "$n hour".($n===1?'':'s').' ago'; }
        if ($seconds < 604800) { $n=(int)floor($seconds/86400);return "$n day".($n===1?'':'s').' ago'; }
        return date('M j, Y',strtotime($date));
    }

    public static function dateFilter(array $input, string $name): string
    {
        $value = trim((string) ($input[$name] ?? ''));
        if ($value !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value) || !checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4)))) throw new AppError('Choose a valid date.');
        return $value;
    }
}
