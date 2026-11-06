<?php
declare(strict_types=1);

/** SIS notifications for enrollment and existing-balance approval workflows. */
final class NotificationCenter
{
    private const BALANCE_ROLES = ['ADMIN', 'REGISTRAR', 'CASHIER', 'ACCOUNTING'];

    public static function canManageBalance(): bool
    {
        return in_array((string) Auth::role(), self::BALANCE_ROLES, true);
    }

    public static function canDeclineApproved(): bool
    {
        return self::canManageBalance();
    }

    public static function notifyBalanceCase(int $caseId, string $event = 'REQUESTED'): void
    {
        $c = Db::one('SELECT * FROM sis_balance_cases WHERE id = ?', [$caseId]);
        if (!$c) return;
        $event = strtoupper($event);
        $label = $c['option_code'] === 'SPECIAL' ? 'Special approval' : 'Payment arrangement';
        $title = match ($event) {
            'REQUESTED' => "$label request needs review",
            'APPROVED' => "$label request approved",
            'REJECTED' => "$label request declined",
            'REVOKED' => 'Approval revoked',
            default => "$label request updated",
        };
        $student = (string) $c['student_name'];
        $target = (string) $c['target_school_year'] . ' ' . (string) $c['target_term'];
        $message = match ($event) {
            'REQUESTED' => "$student submitted a $label request for $target.",
            'APPROVED' => "$label for $student was approved, but authorized staff may still decline it if policy changes.",
            'REJECTED', 'REVOKED' => "$label for $student is no longer approved.",
            default => "$label for $student is now " . (string) $c['status'] . ".",
        };
        foreach (self::BALANCE_ROLES as $role) {
            Db::insert('sis_notifications', [
                'recipient_role' => $role, 'type' => 'BALANCE_' . $event, 'title' => $title,
                'message' => $message, 'entity_type' => 'balance_case', 'entity_id' => (string) $caseId,
                'is_read' => 0,
            ]);
        }
    }

    public static function forCurrentUser(int $limit = 100): array
    {
        $uid = Auth::id(); $role = (string) Auth::role();
        return Db::all('SELECT * FROM sis_notifications WHERE (recipient_user_id = ? OR recipient_role = ?) ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(500, $limit)), [$uid, $role]);
    }

    public static function unreadCount(): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM sis_notifications WHERE is_read = 0 AND (recipient_user_id = ? OR recipient_role = ?)', [Auth::id(), (string) Auth::role()]);
    }

    public static function markRead(int $id): void
    {
        Db::q('UPDATE sis_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND is_read = 0 AND (recipient_user_id = ? OR recipient_role = ?)', [$id, Auth::id(), (string) Auth::role()]);
    }

    public static function markAllRead(): void
    {
        Db::q('UPDATE sis_notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0 AND (recipient_user_id = ? OR recipient_role = ?)', [Auth::id(), (string) Auth::role()]);
    }

    public static function balanceCaseId(array $n): ?int
    {
        return ($n['entity_type'] ?? '') === 'balance_case' && ctype_digit((string) ($n['entity_id'] ?? '')) ? (int) $n['entity_id'] : null;
    }

    /** Inbox queries always retain recipient scope, including selected details and all filters. */
    private static function inboxSelect(): string
    {
        return "SELECT n.*, c.student_number, c.student_name, c.target_school_year, c.target_term, c.option_code, c.balance_amount, c.requested_payment, c.status AS case_status,
            CASE WHEN n.type = 'BALANCE_REQUESTED' THEN 'REQUESTED' WHEN n.type = 'BALANCE_APPROVED' THEN 'APPROVED'
              WHEN n.type = 'BALANCE_REVOKED' THEN 'REVOKED' WHEN n.type = 'BALANCE_REJECTED' THEN 'DECLINED'
              WHEN n.type = 'BALANCE_CASE' AND LOWER(n.title) LIKE '%approved%' THEN 'APPROVED'
              WHEN n.type = 'BALANCE_CASE' AND LOWER(n.title) LIKE '%declined%' THEN 'DECLINED'
              WHEN n.type = 'BALANCE_CASE' AND LOWER(n.title) LIKE '%review%' THEN 'REQUESTED' ELSE 'OTHER' END AS event
            FROM sis_notifications n LEFT JOIN sis_balance_cases c ON n.entity_type = 'balance_case' AND n.entity_id REGEXP '^[0-9]+$' AND c.id = CAST(n.entity_id AS UNSIGNED)
            WHERE (n.recipient_user_id = ? OR n.recipient_role = ?)";
    }

    public static function findCurrent(int $id): ?array
    {
        return Db::one(self::inboxSelect() . ' AND n.id = ?', [Auth::id(), (string)Auth::role(), $id]);
    }

    public static function inbox(array $filters): array
    {
        $where = []; $params = [Auth::id(), (string)Auth::role()];
        if ($filters['tab'] === 'unread') $where[] = 'is_read = 0';
        if ($filters['tab'] === 'action') {
            $options = [];
            foreach (['ARRANGEMENT','SPECIAL'] as $option) if (BalanceEnrollment::canApprove($option)) $options[] = $option;
            $where[] = "case_status = 'PENDING' AND event = 'REQUESTED' AND option_code IN " . Db::in($options);
            array_push($params,...$options);
        }
        if ($filters['event'] !== 'ALL') { $where[] = 'event = ?'; $params[] = $filters['event']; }
        if ($filters['q'] !== '') { $where[] = "LOWER(CONCAT_WS(' ',title,message,student_name,student_number)) LIKE ?"; $params[] = '%'.mb_strtolower($filters['q']).'%'; }
        if ($filters['after'] !== '') { $where[] = 'created_at >= ?'; $params[] = $filters['after']; }
        if ($filters['before'] !== '') { $where[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $filters['before']; }
        $source = '(' . self::inboxSelect() . ') inbox' . ($where ? ' WHERE '.implode(' AND ',$where) : '');
        $total = (int)Db::val('SELECT COUNT(*) FROM '.$source,$params);
        $pages = max(1,(int)ceil($total/20)); $page = min(max(1,(int)$filters['page']),$pages);
        $rows = Db::all('SELECT * FROM '.$source.' ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET '.(($page-1)*20),$params);
        return compact('rows','total','page','pages');
    }

    public static function banner(string $context = ''): string
    {
        if (!self::canManageBalance()) return '';
        $count = (int) Db::val("SELECT COUNT(*) FROM sis_balance_cases WHERE status = 'PENDING' AND option_code = 'SPECIAL'");
        $approved = (int) Db::val("SELECT COUNT(*) FROM sis_balance_cases WHERE status = 'APPROVED' AND option_code = 'SPECIAL'");
        if ($count === 0 && $approved === 0) return '';
        $text = $count ? "$count special approval request" . ($count === 1 ? '' : 's') . ' waiting for review' : 'No pending special approval requests';
        if ($approved) $text .= "; $approved approved request" . ($approved === 1 ? '' : 's') . ' can still be declined by authorized staff';
        return '<div class="alert alert-info d-flex flex-wrap align-items-center gap-2 py-2 small"><i class="bi bi-bell"></i><span class="me-auto">' . h($text) . '.</span><a class="btn btn-sm btn-outline-primary" href="' . h(url('notifications')) . '">Open Notifications</a></div>';
    }
}
