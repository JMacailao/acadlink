<?php
/**
 * Sign-in, sessions, role-based permissions and CSRF protection.
 * Every protected action calls Auth::require('permission.code') on the server.
 */
declare(strict_types=1);

final class Auth
{
    /** What each role may do (checked on the server for every page and action). ADMIN may do everything except the student view. */
    public const ROLE_PERMS = [
        'REGISTRAR' => ['students', 'students.edit', 'sections', 'sections.edit', 'yearlevels', 'teachers', 'teachers.edit',
                        'migration.academic', 'curriculum', 'curriculum.edit', 'assessment', 'assessment.edit', 'admission', 'registration', 'payments.view', 'activity', 'documents', 'documents.issue', 'documents.void', 'schedules', 'schedules.edit',
                        'admission.view', 'admission.approve', 'registration.view', 'enrollment.confirm', 'balances.view', 'eligibility.view', 'eligibility.holds', 'clearance', 'clearance.manage'],
        'ADMISSION' => ['students', 'sections', 'yearlevels', 'curriculum', 'assessment', 'admission', 'registration', 'admission.view', 'admission.approve', 'registration.view'],
        'CASHIER'   => ['students', 'payments.view', 'payments.record', 'payments.void'],
        'ACCOUNTING' => ['accounting.reconcile','accounting.review','accounting.post','accounting.close','migration.finance','students', 'payments.view', 'payments.record', 'payments.void', 'assessment', 'assessment.edit', 'accounting', 'accounting.edit', 'activity', 'registration.view', 'balances.view', 'balances.approve', 'discounts.manage'],
        'TEACHER' => [],
        'STUDENT'   => ['self'],
        // v59: not a role; lets the CLI administrator (tests, tools) use the Canteen POS
        'CANTEEN_ADMIN' => ['canteen.view', 'canteen.sell', 'canteen.products', 'canteen.products.edit', 'canteen.inventory', 'canteen.inventory.edit', 'canteen.supervise', 'canteen.reports'],
    ];
    public const ROLES = ['ADMIN' => 'Power User / Super Admin', 'TEACHER' => 'Teacher', 'REGISTRAR' => 'Registrar', 'ADMISSION' => 'Admission staff', 'CASHIER' => 'Cashier', 'ACCOUNTING' => 'Accounting staff', 'STUDENT' => 'Student'];

    private const MAX_FAILED = 5;
    private const LOCK_MINUTES = 15;
    private const IDLE_SECONDS = 1800;

    private static ?array $user = null;
    private static ?array $perms = null;
    /** Used by tests / CLI to act as a user without a session. */
    private static ?int $actingAs = null;

    public static function startSession(array $cfg): void
    {
        if (PHP_SAPI === 'cli') return;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($cfg['force_secure_cookie'] ?? false);
        session_name($cfg['session_name'] ?? 'SISSESSION');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
        // The shared central token enforces inactivity across both workspaces.
    }

    public static function actAs(?int $userId): void
    {
        self::$actingAs = $userId;
        self::$user = null;
        self::$perms = null;
    }

    public static function central(): CentralAccounts {
        static $service=null, $connection=null;
        if ($connection!==Db::pdo()) { $connection=Db::pdo(); $service=new CentralAccounts($connection,(bool)(sis_config()['force_secure_cookie']??false)); }
        return $service;
    }
    public static function centralId(): ?int { return ($a=self::central()->current()) ? (int)$a['id'] : null; }
    public static function id(): ?int
    {
        if (PHP_SAPI==='cli' && self::$actingAs!==null) return self::$actingAs;
        $a=self::central()->current();
        return $a ? (int)self::central()->legacyId((int)$a['id'],'SIS') ?: null : null;
    }
    public static function user(): ?array
    {
        $id=self::id();
        if (!$id) return null;
        $u=Db::one('SELECT * FROM sis_users WHERE id=?',[$id]);
        if (!$u) return null;
        if (!(PHP_SAPI==='cli' && self::$actingAs!==null)) {
            $c=self::central(); $a=$c->current();
            if (!$a) return null;
            $g=$c->group((int)$a['id']);
            $u['central_id']=(int)$a['id'];
            $u['role']=['POWER_USER'=>'ADMIN','ACCOUNTING'=>'ACCOUNTING','CASHIER'=>'CASHIER','CANTEEN_CASHIER'=>'CASHIER','CANTEEN_SUPERVISOR'=>'CASHIER','REGISTRAR'=>'REGISTRAR','TEACHER'=>'TEACHER','STUDENT'=>'STUDENT'][$g]??'TEACHER';
            $u['username']=$a['username']; $u['full_name']=$a['display_name'];
            $u['must_change_password']=$a['must_change_password'];
            $u['student_number']=$a['person_verified']?$a['student_number']:null;
            unset($u['password_hash']);
        } elseif (!$u['is_active']) return null;
        $u['role_code']=$u['role']; $u['role_name']=self::ROLES[$u['role']]??$u['role'];
        if (isset($g) && in_array($g, ['CANTEEN_CASHIER','CANTEEN_SUPERVISOR'], true)) $u['role_name'] = $g === 'CANTEEN_SUPERVISOR' ? 'Canteen Supervisor' : 'Canteen Cashier';
        return $u;
    }

    public static function role(): ?string { return self::user()['role_code'] ?? null; }
    /** NONE / VIEW / EDIT for a module of the access catalog (shared/module-access.php). */
    public static function moduleLevel(string $key): string {
        $id=self::centralId(); if(!$id) return 'NONE';
        return ModuleAccess::level(self::central()->permissions($id),$key)['level'];
    }
    /** True when this page belongs to a module the person may only look at. */
    public static function viewOnly(string $route): bool {
        $key=ModuleAccess::SIS_ROUTES[$route]??null;
        return $key!==null && self::user()!==null && self::moduleLevel($key)==='VIEW';
    }

    /** Student ID (grading system) of the signed-in student account; null for staff. */
    public static function studentNumber(): ?string
    {
        $u = self::user();
        return $u && $u['role'] === 'STUDENT' ? $u['student_number'] : null;
    }

    public static function permissions(): array
    {
        $u=self::user();
        if (!$u) return [];
        if (PHP_SAPI==='cli' && self::$actingAs!==null) {
            $all=array_merge(...array_values(self::ROLE_PERMS));
            return array_flip($u['role']==='ADMIN'?array_diff(array_unique(array_merge($all,['users.manage','settings.manage'])),['self']):(self::ROLE_PERMS[$u['role']]??[]));
        }
        $central=self::central()->permissions((int)$u['central_id']);
        $map=[
            'users.manage'=>['admin.accounts.manage'], 'settings.manage'=>['admin.settings.manage'],
            'accounting.reconcile'=>['sis.reconciliation.manage'], 'accounting.review'=>['sis.journals.review'], 'accounting.post'=>['sis.journals.post'], 'accounting.close'=>['sis.periods.close'],
            'migration.finance'=>['sis.journals.post'], 'migration.academic'=>['grading.grades.finalize'],
            'activity'=>['sis.audit.view','admin.audit.view'], 'students'=>['sis.students.view'], 'students.edit'=>['sis.students.manage'],
            'sections'=>['grading.sections.manage'], 'sections.edit'=>['grading.sections.manage'], 'yearlevels'=>['grading.sections.manage'],
            'teachers'=>['grading.teachers.manage'], 'teachers.edit'=>['grading.teachers.manage'],
            'curriculum'=>['grading.curriculum.manage'], 'curriculum.edit'=>['grading.curriculum.manage'],
            'assessment'=>['sis.assessment.view'], 'assessment.edit'=>['sis.assessment.manage'],
            'admission'=>['sis.admission.manage'], 'registration'=>['sis.registration.manage'],
            'payments.view'=>['sis.receipts.view'], 'payments.record'=>['sis.payments.receive'], 'payments.void'=>['sis.payments.void'],
            'accounting'=>['sis.accounting.view','sis.journals.prepare','sis.journals.review','sis.journals.post','sis.reconciliation.manage','sis.reports.view'],
            'groups.manage'=>['admin.groups.manage'], 'reports'=>['sis.reports.view'],
            'accounting.edit'=>['sis.journals.prepare'], 'self'=>['portal.workspace.view'],
            // Listed in Users and Groups; each one now controls the action it names
            'admission.view'=>['sis.admission.view','sis.admission.manage'], 'admission.approve'=>['sis.admission.approve'],
            'registration.view'=>['sis.registration.view','sis.registration.manage'], 'enrollment.confirm'=>['sis.enrollment.confirm'], 'enrollment.reopen'=>['sis.enrollment.reopen'],
            'balances.view'=>['sis.balances.view'], 'balances.approve'=>['sis.balances.approve'], 'discounts.manage'=>['sis.discounts.manage'],
            'eligibility.view'=>['sis.eligibility.view'], 'eligibility.holds'=>['sis.eligibility.holds.manage'],
            'documents'=>['sis.documents.view','sis.documents.issue'], 'schedules'=>['sis.schedules.view','sis.schedules.manage'], 'schedules.edit'=>['sis.schedules.manage'], 'documents.issue'=>['sis.documents.issue'], 'documents.void'=>['sis.documents.void'],
            'clearance'=>['sis.clearance.view','sis.clearance.manage'], 'clearance.manage'=>['sis.clearance.manage'],
            // v59: Canteen POS
            'canteen.view'=>['canteen.sales.view','canteen.sales.make','canteen.supervise'], 'canteen.sell'=>['canteen.sales.make'],
            'canteen.products'=>['canteen.products.view','canteen.products.manage'], 'canteen.products.edit'=>['canteen.products.manage'],
            'canteen.inventory'=>['canteen.inventory.view','canteen.inventory.manage'], 'canteen.inventory.edit'=>['canteen.inventory.manage'],
            'canteen.supervise'=>['canteen.supervise'], 'canteen.reports'=>['canteen.reports.view','canteen.supervise'],
        ];
        $out=[];
        foreach($map as $p=>$keys) foreach($keys as $key) if(isset($central[$key])) { $out[$p]=true; break; }
        // Registrar reports do not grant access to the financial accounting workspace.
        if (!isset($central['sis.accounting.view']) && !isset($central['sis.journals.prepare']) && !isset($central['sis.journals.review']) && !isset($central['sis.journals.post']) && !isset($central['sis.reconciliation.manage'])) unset($out['accounting']);
        return $out;
    }

    public static function can(string ...$perms): bool
    {
        $mine = self::permissions();
        foreach ($perms as $p) if (isset($mine[$p])) return true;
        return false;
    }

    /** Stop with 403 unless the user has at least one of the permissions. */
    public static function require(string ...$perms): void
    {
        if (!self::user()) throw new AuthRequired();
        if (!self::can(...$perms)) {
            throw new Forbidden('You do not have permission to do this (' . implode(' or ', $perms) . ').');
        }
    }

    public static function login(string $username, string $password): array
    {
        try { $a=self::central()->login($username,$password,'SIS'); }
        catch(CentralAccountError $e) { throw new AppError($e->getMessage()); }
        $_SESSION['uid']=(int)self::central()->legacyId((int)$a['id'],'SIS');
        $_SESSION['last_seen']=time();
        self::$actingAs=null;
        return self::user() ?? throw new AppError('The SIS account link is missing. Contact the Power User.');
    }
    public static function logout(): void
    {
        self::central()->logout();
        $_SESSION=[];
        self::$user=null; self::$perms=null;
        if (session_status()===PHP_SESSION_ACTIVE) session_regenerate_id(true);
    }

    /** A temporary password to suggest to the administrator, e.g. "Mango-4827-Ray" (easy to read out, meets the rules). */
    public static function suggestPassword(): string
    {
        $words = ['Mango', 'River', 'Cloud', 'Tiger', 'Maple', 'Coral', 'Eagle', 'Pearl', 'Lotus', 'Cedar', 'Amber', 'Ocean', 'Sunny', 'Lemon', 'Falcon', 'Orchid'];
        $tails = ['Ray', 'Sky', 'Bay', 'Fox', 'Oak', 'Sun', 'Elm', 'Gem', 'Ivy', 'Owl'];
        return $words[random_int(0, count($words) - 1)] . '-' . random_int(1000, 9999) . '-' . $tails[random_int(0, count($tails) - 1)];
    }

    public static function validatePassword(string $pw): void
    {
        if (strlen($pw) > 72) throw new AppError('Password must be at most 72 characters.');
        if (mb_strlen($pw, 'UTF-8') < 8) throw new AppError('Password must be at least 8 characters.');
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            throw new AppError('Password must contain letters and numbers.');
        }
    }

    // ------------------------------------------------------------ CSRF
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        $sent = $_POST['_csrf'] ?? '';
        if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
            throw new Forbidden('The form expired or was sent from another site. Reload the page and try again.');
        }
    }
}

class AuthRequired extends RuntimeException {}
class Forbidden extends RuntimeException {}

/** Hidden one-time token for forms whose action must never apply twice (see FormOnce). */
function once_field(): string
{
    return '<input type="hidden" name="_once" value="' . FormOnce::token() . '">';
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(Auth::csrfToken()) . '">';
}
