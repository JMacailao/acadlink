<?php
declare(strict_types=1);

/**
 * Access by system and module: None / View / Edit, plus extra actions under Edit.
 * Each module is a set of the existing central permission codes, so every server check keeps using those codes.
 * A group sets the defaults; one person can be given a different level for a module (an override).
 */
final class ModuleAccess
{
    // v58: Finance is its own system; Canteen POS and Library are planned systems with their own access
    public const SYSTEMS = ['GRADING' => 'Grading System', 'SIS' => 'School Information System', 'FINANCE' => 'Finance', 'PORTAL' => 'Student Portal', 'LMS' => 'Learning Management System', 'CANTEEN' => 'Canteen POS', 'LIBRARY' => 'Library'];
    public const WORKSPACE = ['GRADING' => 'grading.workspace.view', 'SIS' => 'sis.workspace.view', 'FINANCE' => 'finance.workspace.view', 'PORTAL' => 'portal.workspace.view', 'LMS' => 'lms.workspace.view', 'CANTEEN' => 'canteen.workspace.view', 'LIBRARY' => 'library.workspace.view'];
    /** v58.1: modules that are used in more than one system. Cashier works in the SIS and in Finance. */
    public const ALSO_OPENS = ['sis.cashier' => ['SIS']];
    /** Order of the cards after sign-in. */
    public const CHOOSER_ORDER = ['GRADING', 'SIS', 'LMS', 'CANTEEN', 'LIBRARY', 'FINANCE', 'PORTAL'];
    /** Systems shown with a "Coming soon" card: they can be given to people, but do not open yet. */
    public const COMING_SOON = []; // v59 Canteen POS, v60 Library and v61 LMS are open
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
            'sis.cashier'        => ['FINANCE', 'Cashier and receipts (opens in the SIS and in Finance)', ['sis.receipts.view'], ['sis.payments.receive'], ['sis.payments.void' => 'Void payments']],
            'sis.balances'       => ['FINANCE', 'Previous balances', ['sis.balances.view'], ['sis.balances.approve'], []],
            'sis.accounting'     => ['FINANCE', 'Accounting', ['sis.accounting.view'], ['sis.journals.prepare'], ['sis.journals.review' => 'Approve journal entries', 'sis.journals.post' => 'Post journal entries', 'sis.reconciliation.manage' => 'Bank reconciliation', 'sis.periods.close' => 'Close accounting periods']],
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
            'portal.parent'      => ['PORTAL', 'Parent view: their linked children\'s grades, attendance and balance (read-only)', ['portal.parent.view'], [], []],
            'lms.access'         => ['LMS', 'LMS: classes (teachers teach their classes, students take theirs)', ['lms.access.view'], [], ['lms.manage.all' => 'Coordinator: open and manage every class']],
            'canteen.pos'        => ['CANTEEN', 'Canteen: sell, receipts and own shift', ['canteen.sales.view'], ['canteen.sales.make'], []],
            'canteen.products'   => ['CANTEEN', 'Canteen: products, prices and QR code', ['canteen.products.view'], ['canteen.products.manage'], []],
            'canteen.inventory'  => ['CANTEEN', 'Canteen: inventory and stocktakes', ['canteen.inventory.view'], ['canteen.inventory.manage'], []],
            'canteen.supervise'  => ['CANTEEN', 'Canteen: approve voids and refunds, accept cash counts', [], ['canteen.supervise'], []],
            'canteen.reports'    => ['CANTEEN', 'Canteen: sales reports', ['canteen.reports.view'], [], []],
            'library.circulation' => ['LIBRARY', 'Library: check-out, returns, renewals and holds', ['library.loans.view'], ['library.loans.manage'], ['library.fines.waive' => 'Waive fines']],
            'library.catalog'    => ['LIBRARY', 'Library: catalog, copies and barcode labels', ['library.catalog.view'], ['library.catalog.manage'], []],
            'library.acquisitions' => ['LIBRARY', 'Library: acquisitions (orders and receiving)', [], ['library.acquisitions.manage'], []],
            'library.stocktake'  => ['LIBRARY', 'Library: stocktakes', [], ['library.stocktake.manage'], []],
            'library.reports'    => ['LIBRARY', 'Library: reports (Edit: loan rules)', ['library.reports.view'], ['library.settings.manage'], []],
        ];
    }

    /** The systems whose modules can be given to an account kind or group (students: Student Portal and LMS). */
    public static function systemsFor(string $kindOrGroup): array
    {
        return $kindOrGroup === 'STUDENT' ? ['PORTAL', 'LMS'] : ['GRADING', 'SIS', 'FINANCE', 'LMS', 'CANTEEN', 'LIBRARY'];
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
            foreach ($catalog as $key => $m) if (($m[0] === $system || in_array($system, self::ALSO_OPENS[$key] ?? [], true)) && self::level($perms, $key)['level'] !== 'NONE') { $open = true; break; }
            if ($open) $perms[$code] ??= self::scope($groupCode, $code); else unset($perms[$code]);
        }
        return $perms;
    }

    /** The systems a set of permissions opens, in the order shown after sign-in. */
    public static function systems(array $perms): array
    {
        return array_values(array_filter(self::CHOOSER_ORDER, fn($s) => isset($perms[self::WORKSPACE[$s]])));
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
        self::installV58($db);
        self::installV59($db);
        self::installV60($db);
        self::installV62($db);
        $db->exec("INSERT IGNORE INTO central_permissions(code,workspace,label) VALUES ('lms.manage.all','LMS','Open and manage every LMS class (coordinator)')"); // v61
    }

    /** v60: Library permissions and the Librarian group. */
    /** v62: Parent / Guardian group: opens the Student Portal and sees only the children linked to it, read-only. */
    private static function installV62(PDO $db): void
    {
        $db->exec("INSERT IGNORE INTO central_permissions(code,workspace,label) VALUES ('portal.parent.view','PORTAL','See linked children: grades, attendance and balance (parents)')");
        if ($db->query("SELECT 1 FROM central_schema_migrations WHERE migration_key='parents_v62'")->fetchColumn()) return;
        $db->beginTransaction();
        try {
            $db->exec("INSERT IGNORE INTO central_groups(code,name,description,is_default,is_active) VALUES
                ('PARENT','Parent / Guardian','See their own children only: grades, LMS work and attendance, balance and receipts. Read-only. The Registrar creates and links these accounts.',1,1)");
            $db->exec("INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
                       SELECT g.id,c.code,'ALL' FROM central_groups g JOIN central_permissions c ON c.code IN ('portal.workspace.view','portal.parent.view') WHERE g.code = 'PARENT'");
            $db->exec("INSERT IGNORE INTO central_schema_migrations(migration_key) VALUES ('parents_v62')");
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function installV60(PDO $db): void
    {
        $db->exec("INSERT IGNORE INTO central_permissions(code,workspace,label) VALUES
            ('library.loans.view','LIBRARY','See library loans, holds and borrowers'),('library.loans.manage','LIBRARY','Check out, return, renew and place holds'),
            ('library.fines.waive','LIBRARY','Waive library fines'),('library.catalog.view','LIBRARY','See the library catalog'),('library.catalog.manage','LIBRARY','Manage titles, copies and barcode labels'),
            ('library.acquisitions.manage','LIBRARY','Order and receive library materials'),('library.stocktake.manage','LIBRARY','Run library stocktakes'),
            ('library.reports.view','LIBRARY','See library reports'),('library.settings.manage','LIBRARY','Set library loan rules and fines')");
        if ($db->query("SELECT 1 FROM central_schema_migrations WHERE migration_key='library_v60'")->fetchColumn()) return;
        $db->beginTransaction();
        try {
            $all = "'library.loans.view','library.loans.manage','library.fines.waive','library.catalog.view','library.catalog.manage','library.acquisitions.manage','library.stocktake.manage','library.reports.view','library.settings.manage'";
            $db->exec("INSERT IGNORE INTO central_groups(code,name,description,is_default,is_active) VALUES
                ('LIBRARIAN','Librarian','Run the library: catalog, check-out and returns, renewals, holds, fines, acquisitions, stocktakes, reports and loan rules.',1,1)");
            $db->exec("INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
                       SELECT g.id,c.code,'ALL' FROM central_groups g JOIN central_permissions c ON c.code IN ($all) WHERE g.code IN ('POWER_USER','LIBRARIAN')");
            $db->exec("INSERT IGNORE INTO central_schema_migrations(migration_key) VALUES ('library_v60')");
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** v59: Canteen POS permissions and the Canteen Cashier and Canteen Supervisor groups. */
    private static function installV59(PDO $db): void
    {
        $db->exec("INSERT IGNORE INTO central_permissions(code,workspace,label) VALUES
            ('canteen.sales.view','CANTEEN','See canteen sales and receipts'),('canteen.sales.make','CANTEEN','Sell at the canteen and run a cashier shift'),
            ('canteen.products.view','CANTEEN','See canteen products and prices'),('canteen.products.manage','CANTEEN','Manage canteen products, prices and the QR code'),
            ('canteen.inventory.view','CANTEEN','See canteen stock'),('canteen.inventory.manage','CANTEEN','Receive, prepare, adjust and count canteen stock'),
            ('canteen.supervise','CANTEEN','Approve canteen voids and refunds and accept cash counts'),('canteen.reports.view','CANTEEN','See canteen sales reports')");
        if ($db->query("SELECT 1 FROM central_schema_migrations WHERE migration_key='canteen_v59'")->fetchColumn()) return;
        $db->beginTransaction();
        try {
            $all = "'canteen.sales.view','canteen.sales.make','canteen.products.view','canteen.products.manage','canteen.inventory.view','canteen.inventory.manage','canteen.supervise','canteen.reports.view'";
            $db->exec("INSERT IGNORE INTO central_groups(code,name,description,is_default,is_active) VALUES
                ('CANTEEN_CASHIER','Canteen Cashier','Sell at the canteen, print receipts, run and count their own shift, and ask for voids and refunds.',1,1),
                ('CANTEEN_SUPERVISOR','Canteen Supervisor','Run the canteen: products, prices, stock, stocktakes, approve voids and refunds, accept cash counts and see reports.',1,1)");
            $db->exec("INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
                       SELECT g.id,c.code,'ALL' FROM central_groups g JOIN central_permissions c ON c.code IN ($all) WHERE g.code IN ('POWER_USER','CANTEEN_SUPERVISOR')");
            $db->exec("INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
                       SELECT g.id,c.code,'ALL' FROM central_groups g JOIN central_permissions c ON c.code IN ('canteen.sales.view','canteen.sales.make','canteen.products.view','canteen.inventory.view') WHERE g.code='CANTEEN_CASHIER'");
            $db->exec("INSERT IGNORE INTO central_schema_migrations(migration_key) VALUES ('canteen_v59')");
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * v58: Finance, Canteen POS and Library systems, and a Cashier group.
     * Nobody loses access: the Cashier, Balances and Accounting modules keep their permission codes, so everyone
     * who used them in the SIS opens them in Finance. Canteen POS and Library start with the Power User only.
     */
    private static function installV58(PDO $db): void
    {
        $type = (string) $db->query("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='central_permissions' AND column_name='workspace'")->fetchColumn();
        if ($type !== '' && !str_contains($type, "'FINANCE'")) $db->exec("ALTER TABLE central_permissions MODIFY workspace ENUM('ADMIN','GRADING','SIS','PORTAL','LMS','FINANCE','CANTEEN','LIBRARY') NOT NULL");
        $db->exec("INSERT IGNORE INTO central_permissions(code,workspace,label) VALUES
            ('finance.workspace.view','FINANCE','Open Finance'),
            ('canteen.workspace.view','CANTEEN','Open the Canteen POS'),('canteen.access.view','CANTEEN','Use the Canteen POS'),
            ('library.workspace.view','LIBRARY','Open the Library'),('library.access.view','LIBRARY','Use the Library')");
        if ($db->query("SELECT 1 FROM central_schema_migrations WHERE migration_key='finance_v58'")->fetchColumn()) return;
        $db->beginTransaction();
        try {
            // The money pages' permissions now belong to Finance (labels in Users and Groups)
            $db->exec("UPDATE central_permissions SET workspace='FINANCE' WHERE code IN ('sis.receipts.view','sis.payments.receive','sis.payments.void','sis.balances.view','sis.balances.approve','sis.accounting.view','sis.journals.prepare','sis.journals.review','sis.journals.post','sis.reconciliation.manage','sis.periods.close')");
            $db->exec("INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
                       SELECT g.id,c.code,'ALL' FROM central_groups g JOIN central_permissions c ON c.code IN ('canteen.access.view','library.access.view')
                       WHERE g.code='POWER_USER'");
            // Cashiers take payments and print receipts; they do not see the books. Accountants keep the books.
            $db->exec("INSERT IGNORE INTO central_groups(code,name,description,is_default,is_active) VALUES
                       ('CASHIER','Cashier','Receive payments, print receipts and see students\' balances. No access to the accounting books.',1,1)");
            $db->exec("INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
                       SELECT g.id,c.code,'ALL' FROM central_groups g JOIN central_permissions c ON c.code IN ('sis.receipts.view','sis.payments.receive','sis.balances.view')
                       WHERE g.code='CASHIER'");
            $db->exec("INSERT IGNORE INTO central_schema_migrations(migration_key) VALUES ('finance_v58')");
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
