<?php
declare(strict_types=1);

/**
 * Access by system and module: None / View / Edit, plus extra actions under Edit.
 * Each module is a set of the existing central permission codes, so every server check keeps using those codes.
 * A group sets the defaults; one person can be given a different level for a module (an override).
 */
final class ModuleAccess
{
    public const SYSTEMS = ['GRADING' => 'Grading System', 'SIS' => 'School Information System', 'PORTAL' => 'Student Portal', 'LMS' => 'Learning Management System'];
    public const WORKSPACE = ['GRADING' => 'grading.workspace.view', 'SIS' => 'sis.workspace.view', 'PORTAL' => 'portal.workspace.view', 'LMS' => 'lms.workspace.view'];
    public const LEVELS = ['NONE' => 'No access', 'VIEW' => 'View only', 'EDIT' => 'View and edit'];

    /** key => [system, label, view codes, edit codes, extra actions under Edit (code => label)] */
    public static function catalog(): array
    {
        return [
            'grading.grades'     => ['GRADING', 'Grades', ['grading.grades.view'], ['grading.grades.enter'], ['grading.grades.submit' => 'Submit grades for review', 'grading.grades.finalize' => 'Final grades and completion (tagging)', 'grading.corrections.review' => 'Review grade corrections']],
            'grading.students'   => ['GRADING', 'Students', ['grading.students.view'], ['grading.students.manage'], []],
            'grading.sections'   => ['GRADING', 'Sections', [], ['grading.sections.manage'], []],
            'grading.subjects'   => ['GRADING', 'Subjects', [], ['grading.subjects.manage'], []],
            'grading.curriculum' => ['GRADING', 'Curriculum', [], ['grading.curriculum.manage'], []],
            'grading.teachers'   => ['GRADING', 'Teachers and class assignments', [], ['grading.teachers.manage'], []],
            'grading.reports'    => ['GRADING', 'Academic reports', ['grading.reports.view'], [], []],
            'sis.students'       => ['SIS', 'Students', ['sis.students.view'], ['sis.students.manage'], []],
            'sis.admission'      => ['SIS', 'Admission and enrollment applications', ['sis.admission.view'], ['sis.admission.manage'], ['sis.admission.approve' => 'Admit, reject or cancel admission']],
            'sis.registration'   => ['SIS', 'Registration', ['sis.registration.view'], ['sis.registration.manage'], ['sis.enrollment.confirm' => 'Confirm enrollment', 'sis.enrollment.reopen' => 'Reopen a confirmed enrollment']],
            'sis.eligibility'    => ['SIS', 'Enrollment eligibility and holds', ['sis.eligibility.view'], ['sis.eligibility.holds.manage'], []],
            'sis.assessment'     => ['SIS', 'Assessment and fees', ['sis.assessment.view'], ['sis.assessment.manage'], ['sis.discounts.manage' => 'Scholarships and discounts']],
            'sis.cashier'        => ['SIS', 'Cashier and receipts', ['sis.receipts.view'], ['sis.payments.receive'], ['sis.payments.void' => 'Void payments']],
            'sis.balances'       => ['SIS', 'Previous balances', ['sis.balances.view'], ['sis.balances.approve'], []],
            'sis.accounting'     => ['SIS', 'Accounting', ['sis.accounting.view'], ['sis.journals.prepare'], ['sis.journals.review' => 'Approve journal entries', 'sis.journals.post' => 'Post journal entries', 'sis.reconciliation.manage' => 'Bank reconciliation', 'sis.periods.close' => 'Close accounting periods']],
            'sis.schedules'      => ['SIS', 'Class schedules and rooms', ['sis.schedules.view'], ['sis.schedules.manage'], []],
            'sis.clearance'      => ['SIS', 'Online clearance (see every student\'s clearance; Edit: open periods, final approval)', ['sis.clearance.view'], ['sis.clearance.manage'], []],
            'sis.documents'      => ['SIS', 'Documents (TOR and certificates)', ['sis.documents.view'], ['sis.documents.issue'], ['sis.documents.void' => 'Reissue or void documents']],
            'sis.reports'        => ['SIS', 'Reports', ['sis.reports.view'], [], []],
            'sis.activity'       => ['SIS', 'Activity history', ['sis.audit.view'], [], []],
            'admin.accounts'     => ['SIS', 'Administration: user accounts', [], ['admin.accounts.manage'], []],
            'admin.groups'       => ['SIS', 'Administration: groups and access', [], ['admin.groups.manage'], []],
            'admin.settings'     => ['SIS', 'Administration: settings', [], ['admin.settings.manage'], []],
            'admin.backup'       => ['SIS', 'Administration: backup and restore', [], ['admin.backup.manage'], []],
            'admin.audit'        => ['SIS', 'Administration: sign-in audit', ['admin.audit.view'], [], []],
            'portal.grades'      => ['PORTAL', 'My grades', ['portal.grades.view'], [], []],
            'portal.assessment'  => ['PORTAL', 'My assessment', ['portal.assessment.view'], [], []],
            'portal.balances'    => ['PORTAL', 'My balances', ['portal.balances.view'], [], []],
            'portal.receipts'    => ['PORTAL', 'My receipts', ['portal.receipts.view'], [], []],
            'portal.enrollment'  => ['PORTAL', 'My enrollment status', ['portal.enrollment.view'], [], []],
            'portal.apply'       => ['PORTAL', 'Apply for admission or enrollment', [], ['portal.admission.apply'], []],
            'lms.access'         => ['LMS', 'LMS: Access', ['lms.access.view'], [], []],
        ];
    }

    /** SIS pages (index.php routes) that belong to a module, for view-only pages. */
    public const SIS_ROUTES = [
        'students' => 'sis.students', 'student' => 'sis.students',
        'admission' => 'sis.admission', 'enrollment' => 'sis.admission',
        'registration' => 'sis.registration', 'eligibility' => 'sis.eligibility',
        'assessment' => 'sis.assessment', 'discounts' => 'sis.assessment', 'subjectfees' => 'sis.assessment',
        'cashier' => 'sis.cashier', 'balance' => 'sis.balances', 'accounting' => 'sis.accounting', 'documents' => 'sis.documents', 'schedules' => 'sis.schedules',
    ];

    /** Every code a module controls. */
    public static function codes(string $key): array
    {
        $m = self::catalog()[$key];
        return array_merge($m[2], $m[3], array_keys($m[4]));
    }

    /** The level a set of permissions gives for a module: ['level' => NONE|VIEW|EDIT, 'extras' => [code, ...]] */
    public static function level(array $perms, string $key): array
    {
        [, , $view, $edit, $extras] = self::catalog()[$key];
        $has = fn(array $codes) => $codes && array_filter($codes, fn($c) => isset($perms[$c])) === $codes;
        $level = $has($edit) ? 'EDIT' : (($view && $has($view)) ? 'VIEW' : 'NONE');
        // A module with no "view" codes and no "edit" codes granted, but an extra action, still counts as edit access
        $x = array_values(array_filter(array_keys($extras), fn($c) => isset($perms[$c])));
        if ($level === 'NONE' && $x) $level = 'EDIT';
        return ['level' => $level, 'extras' => $level === 'EDIT' ? $x : []];
    }

    /** Levels a module offers (a module without view-only codes has no "View only"). */
    public static function levelsFor(string $key): array
    {
        [, , $view, $edit] = self::catalog()[$key];
        $out = ['NONE' => self::LEVELS['NONE']];
        if ($view) $out['VIEW'] = $edit ? self::LEVELS['VIEW'] : 'Yes';
        if ($edit) $out['EDIT'] = $view ? self::LEVELS['EDIT'] : 'Yes';
        return $out;
    }

    /** The permission codes a module level grants. */
    public static function grant(string $key, string $level, array $extras): array
    {
        [, , $view, $edit, $x] = self::catalog()[$key];
        if ($level === 'NONE') return [];
        if ($level === 'VIEW') return $view;
        return array_values(array_unique(array_merge($view, $edit, array_values(array_intersect(array_keys($x), $extras)))));
    }

    /** Scope a newly granted code takes: students see their own records, teachers their assigned classes. */
    public static function scope(string $groupCode, string $code): string
    {
        if ($groupCode === 'STUDENT') return 'OWN';
        if ($groupCode === 'TEACHER' && str_starts_with($code, 'grading.')) return 'ASSIGNED';
        return 'ALL';
    }

    /**
     * Group permissions with this person's module overrides applied, and each system opened only when
     * at least one of its modules is allowed. $perms: code => scope.
     */
    public static function apply(array $perms, array $overrides, string $groupCode): array
    {
        $catalog = self::catalog();
        foreach ($overrides as $o) {
            $key = (string) $o['module_key'];
            if (!isset($catalog[$key])) continue;
            foreach (self::codes($key) as $c) unset($perms[$c]);
            foreach (self::grant($key, (string) $o['level'], (array) json_decode((string) ($o['extras'] ?? '[]'), true)) as $c) $perms[$c] = self::scope($groupCode, $c);
        }
        foreach (self::WORKSPACE as $system => $code) {
            $open = false;
            foreach ($catalog as $key => $m) if ($m[0] === $system && self::level($perms, $key)['level'] !== 'NONE') { $open = true; break; }
            if ($open) $perms[$code] ??= self::scope($groupCode, $code); else unset($perms[$code]);
        }
        return $perms;
    }

    /** The systems a set of permissions opens, in the order shown after sign-in. */
    public static function systems(array $perms): array
    {
        return array_keys(array_filter(self::WORKSPACE, fn($code) => isset($perms[$code])));
    }

    /** Creates the per-person table and the "look only" Accounting permission (once). */
    public static function install(PDO $db): void
    {
        // Needs the central account tables (absent in a bare SIS database, e.g. some tests)
        if (!$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='central_permissions'")->fetchColumn()) return;
        $db->exec("CREATE TABLE IF NOT EXISTS central_user_module_access (
            account_id BIGINT UNSIGNED NOT NULL, module_key VARCHAR(64) NOT NULL,
            level ENUM('NONE','VIEW','EDIT') NOT NULL, extras VARCHAR(1000) NOT NULL DEFAULT '[]',
            updated_by BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (account_id, module_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("INSERT IGNORE INTO central_permissions(code,workspace,label) VALUES ('sis.accounting.view','SIS','View the accounting books and entries')");
        // Groups that already work in Accounting can also look at it
        $db->exec("INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
                   SELECT DISTINCT gp.group_id,'sis.accounting.view','ALL' FROM central_group_permissions gp
                   WHERE gp.permission_code IN ('sis.journals.prepare','sis.journals.review','sis.journals.post','sis.reconciliation.manage','sis.periods.close') AND gp.is_enabled=1
                   AND NOT EXISTS (SELECT 1 FROM central_schema_migrations WHERE migration_key='module_access_v1')");
        $db->exec("INSERT IGNORE INTO central_schema_migrations(migration_key) VALUES ('module_access_v1')");
        // v50.1: Learning Management System, a fourth system every group can open (adjustable in Access)
        $type = (string) $db->query("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='central_permissions' AND column_name='workspace'")->fetchColumn();
        if ($type !== '' && !str_contains($type, "'LMS'")) $db->exec("ALTER TABLE central_permissions MODIFY workspace ENUM('ADMIN','GRADING','SIS','PORTAL','LMS') NOT NULL");
        $db->exec("INSERT IGNORE INTO central_permissions(code,workspace,label) VALUES ('lms.workspace.view','LMS','Open the Learning Management System'),('lms.access.view','LMS','Use the Learning Management System')");
        $db->exec("INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
                   SELECT g.id,c.code,'ALL' FROM central_groups g JOIN central_permissions c ON c.code IN ('lms.workspace.view','lms.access.view')
                   WHERE NOT EXISTS (SELECT 1 FROM central_schema_migrations WHERE migration_key='lms_v1')");
        $db->exec("INSERT IGNORE INTO central_schema_migrations(migration_key) VALUES ('lms_v1')");
    }
}
