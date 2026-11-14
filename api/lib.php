<?php
/**
 * ==========================================================
 * College Grading System: PHP + MySQL server (shared code; api.php is the entry point)
 * ==========================================================
 * The only door between the website and the MySQL database.
 * Every request is checked against the signed-in user with the same
 * role rules as the other versions (firestore.rules / Database.gs).
 *
 * Setup: see README-PHP.md (edit config.php, run install.sql once).
 * Needs PHP 8.1 or newer with pdo_mysql, and MySQL 5.7+ or MariaDB 10.3+.
 *
 * The website sends JSON (POST) with an "action":
 *   sign-in:  whoami, login, logout, reauth, changePassword, createAccount,
 *             setPassword, tempPasswords
 *   data:     ping, get, query, count, adminUids, commit
 * Answers are { ok: true, … } or { ok: false, code, message }.
 * ==========================================================
 */

declare(strict_types=1);

const VERSION = 1;
const USERNAME_EMAIL_DOMAIN = "gradingsystem.local"; // must match the website (don't change)


// ---------------------------------------------------------- answers and errors
final class ApiError extends Exception {
  public string $apiCode;
  public function __construct(string $code, string $message) { parent::__construct($message); $this->apiCode = $code; }
}
function fail(string $code, string $message): never { throw new ApiError($code, $message); }
function reply(array $obj): never {
  // An opaque namespace only; authentication and permissions are still checked per request.
  try { if (function_exists('centralAccounts')) header('X-AcadLink-Scope: '.acadlink_client_scope(centralAccounts())); }
  catch (Throwable $ignored) { /* Preserve the original API error if the database is unavailable. */ }
  echo json_encode($obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

// ---------------------------------------------------------- JavaScript-like values
// Records are decoded as objects (stdClass) and lists (arrays), so {} and [] stay apart.
// A missing field is the special value U() (JavaScript's "undefined"), which is not null.
final class Undef {}
function U(): Undef { static $u = null; return $u ??= new Undef(); }
/** o[k] as JavaScript reads it: U() when missing. */
function g($o, string $k) { return ($o instanceof stdClass && property_exists($o, $k)) ? $o->$k : U(); }
function has($o, string $k): bool { return $o instanceof stdClass && property_exists($o, $k); }
/** JavaScript truthiness ({} and [] are true; undefined, null, false, 0, "" are false). */
function truthy($v): bool {
  if ($v instanceof Undef || $v === null || $v === false || $v === "" || $v === 0) return false;
  if (is_float($v)) return $v != 0.0 && !is_nan($v);
  return true;
}
function isNum($v): bool { return is_int($v) || is_float($v); }
function enc($v): string {
  if ($v instanceof Undef) return "\0undefined";
  return jsonText($v);
}
function jsonText($v): string { return (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
/** JSON.stringify(a) === JSON.stringify(b), as in the other versions. */
function same($a, $b): bool { return enc($a) === enc($b); }
/** === for plain values (numbers compare by value, like JavaScript). */
function eq($a, $b): bool {
  if (isNum($a) && isNum($b)) return $a == $b;
  return $a === $b;
}
function obj($v): stdClass { return $v instanceof stdClass ? $v : new stdClass(); }
/** Object.assign({}, a, b) */
function assign(...$parts): stdClass {
  $o = new stdClass();
  foreach ($parts as $p) if ($p instanceof stdClass) foreach (get_object_vars($p) as $k => $v) $o->$k = $v;
  return $o;
}
function decodeJson(string $s) { return json_decode($s, false, 512, JSON_BIGINT_AS_STRING); }
function nowIso(): string {
  $t = microtime(true);
  return gmdate("Y-m-d\\TH:i:s", (int) $t) . sprintf(".%03dZ", (int) (($t - floor($t)) * 1000));
}
function nowMs(): float { return floor(microtime(true) * 1000); }
function parseIsoMs($s): ?float {
  if (!is_string($s) || $s === "") return null;
  try { $d = new DateTime($s); } catch (Exception $e) { return null; }
  return (float) $d->format("U") * 1000 + (int) $d->format("v");
}

// ---------------------------------------------------------- settings and database
function config(): array {
  static $c = null;
  if ($c === null) {
    $file = __DIR__ . "/config.php";
    if (!is_file($file)) fail("failed-precondition", "The server isn't set up yet: copy api/config.sample.php to api/config.php and fill in your MySQL details.");
    $c = require $file;
  }
  return $c;
}
function pdo(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = config();
  $dsn = "mysql:host=" . ($c["db_host"] ?? "localhost") . ";port=" . ($c["db_port"] ?? 3306) . ";dbname=" . $c["db_name"] . ";charset=utf8mb4";
  if (!empty($c["db_socket"])) $dsn = "mysql:unix_socket=" . $c["db_socket"] . ";dbname=" . $c["db_name"] . ";charset=utf8mb4";
  try {
    $pdo = new PDO($dsn, $c["db_user"], $c["db_pass"], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
  } catch (PDOException $e) {
    $n = (int) ($e->errorInfo[1] ?? 0); if (!$n && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) $n = (int) $m[1];
    if (in_array($n, [1040, 1203, 1226], true)) fail("unavailable", "The school's server is busy right now. Nothing was saved. Wait a few seconds and try again.");
    fail("unavailable", "Can't connect to the MySQL database: " . dbConnectProblem($e) . " Check the details in api/config.php.");
  }
  // v63.19: one text-comparison rule (collation) for the connection, as in the SIS (sis/app/lib/core.php)
  $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
  // Both workspaces must compare central session timestamps in the school timezone.
  date_default_timezone_set($c['timezone'] ?? 'Asia/Manila');
  $pdo->exec("SET time_zone = " . $pdo->quote(date('P')));
  return $pdo;
}

/** Why the connection failed, in words (no user names or passwords). */
function dbConnectProblem(PDOException $e): string {
  $n = (int) ($e->errorInfo[1] ?? 0);
  if ($n === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) $n = (int) $m[1];
  return match (true) {
    $n === 1045, $n === 1698 => "the MySQL user name or password (db_user / db_pass) is wrong.",
    $n === 1044 => "that MySQL user has no access to the database (db_name).",
    $n === 1049 => "there is no database with that name (db_name). Create it, or fix the name.",
    in_array($n, [2002, 2003, 2005, 2006], true) => "the MySQL server isn't running or isn't at that address (db_host / db_port / db_socket). XAMPP: start MySQL.",
    !extension_loaded("pdo_mysql") => "PHP's pdo_mysql extension isn't enabled.",
    default => "MySQL error " . ($n ?: "unknown") . ".",
  };
}

function str($v): string { return is_string($v) ? $v : ""; }
function listOf($v): array { return is_array($v) ? $v : []; }

/** Requests from other websites are refused (the browser always says where a request comes from). */
function sameOriginOnly(): void {
  $origin = $_SERVER["HTTP_ORIGIN"] ?? "";
  if ($origin === "") return;
  $host = $_SERVER["HTTP_HOST"] ?? "";
  $allowed = array_merge([$host], config()["allowed_hosts"] ?? []);
  $originHost = parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ":" . parse_url($origin, PHP_URL_PORT) : "");
  if (!in_array($originHost, $allowed, true)) fail("permission-denied", "Requests from other websites aren't allowed.");
}

// ==========================================================
// SIGN-IN
// ==========================================================
function isHttps(): bool {
  return (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");
}
function startSession(): void {
  session_name(config()["session_name"] ?? "GSSESSION");
  session_set_cookie_params(["lifetime" => 0, "path" => "/", "secure" => isHttps(), "httponly" => true, "samesite" => "Lax"]);
  ini_set("session.use_strict_mode", "1");
  ini_set("session.use_only_cookies", "1");
  session_start();
}
require_once dirname(__DIR__).'/shared/central-accounts.php';
require_once dirname(__DIR__).'/shared/client-state.php';
require_once dirname(__DIR__).'/shared/curriculum-assignments.php';
require_once dirname(__DIR__).'/shared/student-history.php';
function centralAccounts(): CentralAccounts {
  static $service=null;
  return $service ??= new CentralAccounts(pdo(),(bool)(config()['sis_force_secure_cookie']??false));
}
function sessionUid(): ?string {
  $a=centralAccounts()->current();
  if (!$a || !centralAccounts()->can((int)$a['id'],'grading.workspace.view')) return null;
  return centralAccounts()->legacyId((int)$a['id'],'GRADING');
}
function accountByUid(string $uid): ?array {
  $r=centralAccounts()->one("SELECT account_id FROM central_legacy_links WHERE source_system='GRADING' AND source_key=?",[$uid]);
  if (!$r) return null;
  $a=centralAccounts()->account((int)$r['account_id']);
  return ['uid'=>$uid,'email'=>$a['username'].'@central.invalid'];
}
function currentUser(): ?array {
  $uid=sessionUid();
  if (!$uid) return null;
  $a=centralAccounts()->current();
  return ['uid'=>$uid,'email'=>$a['username'].'@central.invalid','mustChangePassword'=>(bool)$a['must_change_password']];
}
function clientIp(): string { return substr((string)($_SERVER['REMOTE_ADDR']??'?'),0,64); }
function login(string $email,string $password,bool $takeOver=false): array {
  // v63.12: open on another device (One device at a time): the page asks, then signs in again with takeOver
  try { $a=centralAccounts()->login($email,$password,'GRADING',$takeOver); }
  catch(CentralSessionConflict $c) { $ago=max(0,time()-(int)strtotime($c->lastSeen)); fail('auth/session-conflict','It was last active '.($ago<90?'just now':round($ago/60).' minutes ago').'.'); }
  catch(CentralAccountError $e) { fail('auth/invalid-credential',$e->getMessage()); }
  $_SESSION['reauth']=time();
  if ($a['must_change_password']) return ['uid'=>centralAccounts()->legacyId((int)$a['id'],'GRADING'),'email'=>$a['username'].'@central.invalid','mustChangePassword'=>true];
  return currentUser() ?? throw new ApiError('auth/invalid-credential','The Grading account link is missing. Contact the Power User.');
}

function logout(): void {
  centralAccounts()->logout();
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), "", ["expires" => time() - 3600, "path" => $p["path"], "secure" => $p["secure"], "httponly" => true, "samesite" => "Lax"]);
  }
  session_destroy();
}

/** "Enter your current password" before changing it. */
function reauth(string $password): void {
  $a=centralAccounts()->current();
  if (!$a || !centralAccounts()->verifyPassword((int)$a['id'],$password)) fail('auth/wrong-password','Your current password is wrong.');
  $_SESSION['reauth']=time();
  $_SESSION['reauth_password_proof']=hash('sha256',$_COOKIE[CentralAccounts::COOKIE]??$_SESSION['central_token']??'');
}
function passwordProblem(string $pw): ?string { return CentralAccounts::passwordProblem($pw); }
function changePassword(string $password): void {
  $a=centralAccounts()->current();
  $proof=hash('sha256',$_COOKIE[CentralAccounts::COOKIE]??$_SESSION['central_token']??'');
  if (!$a || time()-(int)($_SESSION['reauth']??0)>600 || !hash_equals((string)($_SESSION['reauth_password_proof']??''),$proof)) fail('auth/requires-recent-login','Enter your current password again.');
  if (centralAccounts()->verifyPassword((int)$a['id'],$password)) fail('auth/weak-password','Choose a password different from the current one.');
  try { centralAccounts()->setPassword((int)$a['id'],$password,false,(int)$a['id']); centralAccounts()->establish((int)$a['id']); }
  catch(CentralAccountError $e) { fail('auth/weak-password',$e->getMessage()); }
}

function newUid(): string {
  $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
  $out = "";
  for ($i = 0; $i < 28; $i++) $out .= $chars[random_int(0, 61)];
  return $out;
}
function setupDone(): bool {
  $s = pdo()->prepare("SELECT 1 FROM docs WHERE col = 'meta' AND id = 'setup'");
  $s->execute();
  return (bool) $s->fetchColumn();
}
/** The signed-in person is an active administrator (from their users record). */
function sessionIsAdmin(): bool {
  $a=centralAccounts()->current();
  return $a && centralAccounts()->can((int)$a['id'],'admin.accounts.manage');
}
function createAccount(string $email,string $password,bool $signIn): array { fail('permission-denied','Accounts and access are managed in SIS.'); }
function setPassword(string $uid,string $password): void { fail('permission-denied','Reset passwords in SIS.'); }

function docData(string $col, string $id): ?stdClass {
  $s = pdo()->prepare("SELECT data FROM docs WHERE col = ? AND id = ?");
  $s->execute([$col, $id]);
  $raw = $s->fetchColumn();
  $d = $raw ? decodeJson((string) $raw) : null;
  if ($col==='users' && $d instanceof stdClass && centralAccounts()->installed()) {
    $link=centralAccounts()->one("SELECT account_id FROM central_legacy_links WHERE source_system='GRADING' AND source_key=?",[$id]);
    if ($link) $d=(object)centralAccounts()->profile((int)$link['account_id'],(array)$d);
  }
  return $d instanceof stdClass ? $d : null;
}

function tempPasswords($uids): array { fail('permission-denied','Temporary passwords are managed in SIS.'); }

// ==========================================================
// DATA (the same requests and rules as Database.gs)
// ==========================================================
final class Ctx {
  public ?string $uid = null;
  public $me = null;               // users record of the signed-in person (stdClass) or null
  public array $docs = [];         // "col/id" => stdClass|null, read in this request
  public array $cols = [];         // col => [id => stdClass], whole lists read in this request
}

function makeContext(): Ctx {
  $ctx = new Ctx();
  $a=centralAccounts()->current();
  if ($a && !centralAccounts()->can((int)$a['id'],'grading.workspace.view')) fail('permission-denied','Use SIS or the Student Portal for this account.');
  $u = currentUser();
  if ($u && $u['mustChangePassword']) fail('auth/password-change-required','Change your temporary password in SIS before continuing.');
  $ctx->uid = $u ? $u["uid"] : null;
  if ($ctx->uid) $ctx->me = beforeDoc($ctx, "users", $ctx->uid);
  return $ctx;
}

function parsePath(string $path): array {
  $parts = explode("/", $path);
  if (count($parts) !== 2 || $parts[0] === "" || $parts[1] === "") fail("invalid-argument", "Bad record path: " . $path);
  return [$parts[0], $parts[1]];
}

/** One record as it is in the database (cached for this request). */
function beforeDoc(Ctx $ctx, string $col, string $id) {
  $key = $col . "/" . $id;
  if (!array_key_exists($key, $ctx->docs)) {
    if (isset($ctx->cols[$col])) $ctx->docs[$key] = $ctx->cols[$col][$id] ?? null;
    else $ctx->docs[$key] = docData($col, $id);
  }
  return $ctx->docs[$key];
}
/** A whole list (only for small lists such as users). */
function loadCol(Ctx $ctx, string $col): array {
  if (!isset($ctx->cols[$col])) {
    $s = pdo()->prepare("SELECT id, data FROM docs WHERE col = ?");
    $s->execute([$col]);
    $map = [];
    foreach ($s as $row) { $d = decodeJson($row["data"]); $map[(string) $row["id"]] = $d instanceof stdClass ? $d : new stdClass(); }
    $ctx->cols[$col] = $map;
  }
  return $ctx->cols[$col];
}

// ---------------------------------------------------------- rules (same as firestore.rules / Database.gs)
final class Rules {
  public ?string $uid; public bool $signedIn; public bool $active; public bool $isAdmin; public bool $isStaff;
  public bool $isTeacher; public bool $isApprover; public $myTeacher;
  public Ctx $ctx; public ?array $after;
  public function before(string $col, string $id) { return beforeDoc($this->ctx, $col, $id); }
  public function after(string $col, string $id) {
    if ($this->after !== null && isset($this->after[$col]) && array_key_exists($id, $this->after[$col])) return $this->after[$col][$id];
    return beforeDoc($this->ctx, $col, $id);
  }
}

function rulesFor(Ctx $ctx, ?array $after): Rules {
  $me = $ctx->me;
  $central=centralAccounts()->current();
  if ($central && $me instanceof stdClass) $me=(object)centralAccounts()->profile((int)$central['id'],(array)$me);
  $r = new Rules();
  $r->ctx = $ctx;
  $r->after = $after;
  $r->uid = $ctx->uid;
  $r->signedIn = (bool) $ctx->uid;
  $r->active = (bool) ($central && !$central["must_change_password"] && centralAccounts()->can((int)$central["id"],"grading.workspace.view") && $ctx->uid && $me && g($me, "active") === true);
  $role = $me ? g($me, "role") : null;
  $r->isAdmin = $r->active && eq($role, "admin");
  $r->isStaff = $r->active && (eq($role, "admin") || eq($role, "registrar"));
  $r->isTeacher = $r->active && eq($role, "teacher");
  $r->isApprover = $r->active && g($me, "canApprove") === true;
  $r->myTeacher = $me ? g($me, "teacherDocId") : null;
  return $r;
}

/** The school's grading scale (Setup and options → settings/options) */
function gradingOf(Rules $r): array {
  $o = obj($r->before("settings", "options"));
  $s = g($o, "gradingScale");
  $scale = (eq($s, "point1") || eq($s, "point5")) ? $s : "percent";
  return ["scale" => $scale, "cond" => g($o, "allowConditional") !== false];
}
function inList($n, array $list): bool { foreach ($list as $v) if ($n == $v) return true; return false; }
function isGrade($n, Rules $r): bool {
  if (!isNum($n)) return false;
  $g = gradingOf($r);
  $steps = [1, 1.25, 1.5, 1.75, 2, 2.25, 2.5, 2.75, 3];
  if ($g["scale"] === "point1") return inList($n, array_merge($steps, $g["cond"] ? [4] : [], [5]));
  if ($g["scale"] === "point5") return inList($n, array_merge(array_map(fn($v) => 6 - $v, $steps), $g["cond"] ? [2] : [], [1]));
  return $n >= 0 && $n <= 100 && abs($n * 100 - round($n * 100)) < 1e-6; // at most 2 decimals
}
/** A remark instead of a number: INC (Incomplete) or DRP (Dropped); the grade is then null. */
function isMark($grade, $remarks): bool {
  return ($grade === null || $grade instanceof Undef) && (eq($remarks, "Incomplete") || eq($remarks, "Dropped"));
}
function validGradeRecord($d, Rules $r): bool {
  return truthy($d) && (isMark(g($d, "finalGrade"), g($d, "remarks")) || (isGrade(g($d, "finalGrade"), $r) && eq(g($d, "remarks"), remarksOf(g($d, "finalGrade"), $r))));
}
/** Term deadline: after it, teachers can't add that term's grades. */
function termOpen(Rules $r, $a): bool {
  $o = obj($r->before("settings", "options"));
  $term = truthy($a) && truthy(g($a, "term")) ? g($a, "term") : "";
  $dl = g(obj(g($o, "termDeadlines")), is_string($term) ? $term : "");
  $at = ($dl instanceof stdClass && truthy(g($dl, "__ts"))) ? parseIsoMs(g($dl, "__ts")) : null;
  return !$at || nowMs() < $at;
}
function remarksOf($n, Rules $r): string {
  $scale = gradingOf($r)["scale"];
  if ($scale === "point1") return $n == 4 ? "Conditional" : ($n <= 3 ? "Passed" : "Failed");
  if ($scale === "point5") return $n == 2 ? "Conditional" : ($n >= 3 ? "Passed" : "Failed");
  return $n >= 75 ? "Passed" : "Failed";
}
function onlyKeysChanged($before, $after, array $allowed): bool {
  $keys = [];
  foreach (get_object_vars(obj($before)) as $k => $_) $keys[(string) $k] = 1;
  foreach (get_object_vars(obj($after)) as $k => $_) $keys[(string) $k] = 1;
  foreach (array_keys($keys) as $k) {
    $k = (string) $k;
    if (!same(g(obj($before), $k), g(obj($after), $k)) && !in_array($k, $allowed, true)) return false;
  }
  return true;
}
function firstSetup(Rules $r): bool { return !truthy($r->before("meta", "setup")) && truthy($r->after("meta", "setup")); }

function canRead(Rules $r, string $col, string $id, $data): bool {
  if ($col==='usernames') return false;
  if (!in_array($col,['settings','meta'],true) && !$r->signedIn) return false;
  if ($r->isTeacher && $col==='gradingAssignments') return eq(g($data,'teacherDocId'),$r->myTeacher);
  if ($r->isTeacher && $col==='students') {
    foreach(loadCol($r->ctx,'gradingAssignments') as $a) if(eq(g($a,'teacherDocId'),$r->myTeacher) && inArrayJs(g($a,'studentIds'),$id)) return true;
    return false;
  }
  $central=centralAccounts()->current();
  $readPermission=['students'=>'grading.students.view','grades'=>'grading.grades.view','gradeCompletions'=>'grading.grades.view','gradeChangeRequests'=>'grading.grades.view','rosterRequests'=>'grading.grades.view'];
  if (isset($readPermission[$col]) && (!$central || !centralAccounts()->can((int)$central['id'],$readPermission[$col]))) return false;
  if ($r->isTeacher && $col==='grades') {
    $a=$r->before('gradingAssignments',str(g($data,'assignmentId')));
    return truthy($a) && eq(g($a,'teacherDocId'),$r->myTeacher) && eq(g($data,'teacherDocId'),$r->myTeacher);
  }
  switch ($col) {
    case "meta": return $id === "setup";
    case "usernames": return true; // single look-ups only (listing is blocked in runQuery)
    case "users": return ($r->signedIn && ($r->uid === $id || $r->isAdmin)) || ($r->active && g($data, "canApprove") === true);
    case "settings": return $id === "school" || (($id === "security" || $id === "downtime" || $id === "options") && $r->signedIn) || ($id === "email" && $r->isStaff) || $r->isAdmin;
    case "subjects": case "teachers": case "sections": case "students": case "gradingAssignments": case "curriculum": return $r->active;
    case "grades": return $r->isStaff || ($r->isTeacher && eq(g($data, "teacherDocId"), $r->myTeacher));
    case "gradeChangeRequests": return $r->isStaff || $r->isApprover || ($r->isTeacher && eq(g($data, "teacherDocId"), $r->myTeacher));
    case "rosterRequests": return $r->isStaff || ($r->isTeacher && eq(g($data, "teacherDocId"), $r->myTeacher));
    case "gradeCompletions": return $r->isStaff;
    case "notifications": return $r->signedIn && eq(g($data, "toUid"), $r->uid);
    default: return $r->isAdmin;
  }
}

function canWrite(Rules $r, string $op, string $col, string $id, $before, $after): bool {
  if (!$r->active) return false;
  if ($col==='teachers' && $id===CurriculumAssignments::PLACEHOLDER &&
      ($op==='delete' || g($after,'teacherId')!=='PROF-X' || g($after,'teacherName')!=='Professor X' || g($after,'isPlaceholder')!==true || array_diff(array_keys(get_object_vars($after)),['teacherId','teacherName','isPlaceholder','createdAt','updatedAt']))) return false;
  if ($op==='delete' && in_array($col,['teachers','students'],true)) {
    $field=$col==='teachers'?'teacher_doc_id':'student_doc_id';
    if (centralAccounts()->q("SELECT account_id FROM central_person_links WHERE $field=?",[$id])->fetchColumn()) return false;
  }
  // A student with SIS records or grades is kept (set a status instead); the Student ID changes only in SIS, which updates every record.
  if ($col==='students' && $op!=='create' && studentInUse($id) && ($op==='delete' || (string)g($before,'studentId')!==(string)g($after,'studentId'))) return false;
  $central=centralAccounts()->current();
  $writePermission=['students'=>'grading.students.manage','subjects'=>'grading.subjects.manage','sections'=>'grading.sections.manage','teachers'=>'grading.teachers.manage','curriculum'=>'grading.curriculum.manage','gradingAssignments'=>'grading.teachers.manage','grades'=>'grading.grades.enter','gradeCompletions'=>'grading.grades.finalize'];
  if (isset($writePermission[$col]) && (!$central || !centralAccounts()->can((int)$central['id'],$writePermission[$col]))) return false;
  if ($col==='grades' && $op==='update' && g($before,'draft')===true && g($after,'draft')===false && (!$central || !centralAccounts()->can((int)$central['id'],'grading.grades.submit'))) return false;
  switch ($col) {
    case "meta":
      if ($id !== "setup") return $r->isAdmin;
      return false;
    case "usernames": return false;
    case "users":
      return $op==='update' && $r->signedIn && $r->uid===$id &&
        onlyKeysChanged($before,$after,['activeSession','updatedAt','lastSeen']);
    case "settings":
      return $r->isAdmin;
    case "subjects": case "teachers": case "sections": case "students": case "gradingAssignments": case "curriculum":
      return $r->isStaff;
    case "grades": {
      $valid = validGradeRecord($after, $r);
      // Once the registrar tags the student's term Complete, teachers no longer change those grades.
      $tagged = !$r->isStaff && termTagged($op === "create" ? $after : $before);
      if ($op === "create") return ($r->isStaff || (teacherOwnsNew($r, $after, $id) && !teachersLocked($r) && draftFlagOk($after) && !$tagged)) && $valid;
      // A teacher changes their own draft; an approver changes only the grade itself.
      // Staff edits must also be valid grades, and a submitted grade's value changes only through an approved change request.
      $gradeChanged = !onlyKeysChanged($before, $after, array_values(array_diff(array_keys(get_object_vars($after instanceof stdClass ? $after : new stdClass)), ["finalGrade", "remarks"])));
      if ($op === "update") return ($r->isStaff && $valid && (g($before, "draft") !== false || !$gradeChanged || approvedChange($r, $id, $after))) ||
        (teacherDraft($r, $before) && draftStaysInClass($r, $before, $after, $id) && $valid && !$tagged) ||
        ($r->isApprover && $valid && approvedChange($r, $id, $after) &&
          onlyKeysChanged($before, $after, ["finalGrade", "remarks", "lastChangeRequestId", "lastChangedByName", "updatedAt"]));
      return $r->isStaff || (teacherDraft($r, $before) && !$tagged);
    }
    case "gradeChangeRequests": {
      if ($op === "create") {
        if ($r->isAdmin) return true;
        $g = truthy($after) ? $r->before("grades", str(g($after, "gradeId"))) : null;
        $reason = g($after, "reason");
        return $r->isTeacher && !teachersLocked($r) && eq(g($after, "teacherDocId"), $r->myTeacher) && truthy($g) && eq(g($g, "teacherDocId"), $r->myTeacher) &&
          eq(g($after, "requestedBy"), $r->uid) && eq(g($after, "status"), "pending") &&
          (isMark(g($after, "newGrade"), g($after, "newRemarks")) || (isGrade(g($after, "newGrade"), $r) && eq(g($after, "newRemarks"), remarksOf(g($after, "newGrade"), $r)))) &&
          is_string($reason) && jsLength($reason) >= 10;
      }
      if ($op === "update") {
        if ($r->isAdmin) return true;
        $decide = $r->isApprover && eq(g($before, "status"), "pending") && !eq(g($before, "requestedBy"), $r->uid) &&
          (eq(g($after, "status"), "approved") || eq(g($after, "status"), "declined")) && eq(g($after, "decidedBy"), $r->uid) &&
          onlyKeysChanged($before, $after, ["status", "decidedBy", "decidedByName", "decidedAt", "decisionNote"]);
        $cancel = $r->active && eq(g($before, "requestedBy"), $r->uid) && eq(g($before, "status"), "pending") && eq(g($after, "status"), "cancelled") &&
          onlyKeysChanged($before, $after, ["status", "decidedAt"]);
        return $decide || $cancel;
      }
      return $r->isStaff;
    }
    case "rosterRequests": {
      // Class list requests: a teacher drafts and submits for their own class, and may withdraw
      // until the registrar decides the first line; only staff decide.
      if ($r->isStaff) return true;
      if (!$r->isTeacher || teachersLocked($r)) return false;
      $itemsOk = fn($d) => truthy($d) && is_array(g($d, "items")) && count(g($d, "items")) <= 50;
      if ($op === "create") {
        $a = truthy($after) ? $r->before("gradingAssignments", str(g($after, "assignmentId"))) : null;
        return $itemsOk($after) && eq(g($after, "teacherDocId"), $r->myTeacher) && truthy($a) && eq(g($a, "teacherDocId"), $r->myTeacher) &&
          eq(g($after, "requestedBy"), $r->uid) && (eq(g($after, "status"), "draft") || eq(g($after, "status"), "submitted")) && g($after, "reviewStarted") === false;
      }
      $own = truthy($before) && eq(g($before, "teacherDocId"), $r->myTeacher) && eq(g($before, "requestedBy"), $r->uid);
      if ($op === "update") {
        if (!$own || !$itemsOk($after)) return false;
        foreach (["assignmentId", "teacherDocId", "requestedBy", "reviewStarted"] as $k) if (!same(g($before, $k), g($after, $k))) return false;
        if (eq(g($before, "status"), "draft")) return in_array(g($after, "status"), ["draft", "submitted", "withdrawn"], true);
        return eq(g($before, "status"), "submitted") && eq(g($after, "status"), "withdrawn") && g($before, "reviewStarted") === false && same(g($before, "items"), g($after, "items"));
      }
      return $own && eq(g($before, "status"), "draft");
    }
    case "gradeCompletions":
      if(($before instanceof stdClass && property_exists($before,'migrationBatch')) || ($after instanceof stdClass && property_exists($after,'migrationBatch')))return false;
      // Final grades tagged complete by the registrar (shown in the SIS)
      if (!$r->isStaff) return false;
      if ($op === "delete") return true;
      return truthy($after) && is_string(g($after, "studentId")) && g($after, "studentId") !== "" && is_string(g($after, "schoolYear")) &&
        is_string(g($after, "term")) && is_array(g($after, "subjects")) && $id === g($after, "studentId") . "__" . g($after, "schoolYear") . "__" . str(g($after, "termKey")) &&
        completionMatchesGrades($after);
    case "auditLog":
      // Entries are written by this server with each change; administrators may delete old ones
      return $op === "delete" && $r->isAdmin;
    case "notifications":
      if ($op === "create") return $r->active && eq(g($after, "fromUid"), $r->uid) && g($after, "read") === false;
      if ($op === "update") return $r->signedIn && eq(g($before, "toUid"), $r->uid) && onlyKeysChanged($before, $after, ["read", "readAt"]);
      return $r->signedIn && eq(g($before, "toUid"), $r->uid);
    default:
      return $r->isAdmin;
  }
}
/** String length as JavaScript counts it (UTF-16 code units). */
function jsLength(string $s): int { return intdiv(strlen((string) mb_convert_encoding($s, "UTF-16LE", "UTF-8")), 2); }

/** Teacher downtime (School settings): teachers can't add grades or change requests. */
function teachersLocked(Rules $r): bool {
  $d = $r->before("settings", "downtime");
  if (!truthy($d) || g($d, "teachersLocked") !== true) return false;
  $until = g($d, "until");
  $at = ($until instanceof stdClass && truthy(g($until, "__ts"))) ? parseIsoMs(g($until, "__ts")) : null;
  return !$at || nowMs() < $at;
}
/**
 * Draft, then Submit: a teacher may change or clear their own grade while it is a draft, until the
 * term deadline or downtime. Submitted grades, and grades saved before drafts existed, stay locked.
 */
/** Grades and classes of one student for a tag's school year and term ("year" = every term, "noterm" = classes without a term). */
function tagScope(string $studentId, string $schoolYear, string $termKey, string $term): array {
  $grades=[];$classes=[];
  $inTerm=fn($d)=>$termKey==='year' || ($termKey==='noterm' ? (string)($d['term']??'')==='' : (string)($d['term']??'')===$term);
  $st=pdo()->prepare("SELECT id,data FROM docs WHERE col='grades' AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.studentId'))=? AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.schoolYear'))=?");
  $st->execute([$studentId,$schoolYear]);
  foreach($st as $row){$d=json_decode($row['data'],true)?:[];if($inTerm($d))$grades[(string)($d['assignmentId']??$row['id'])]=$d;}
  $st=pdo()->prepare("SELECT id,data FROM docs WHERE col='gradingAssignments' AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.schoolYear'))=? AND JSON_CONTAINS(JSON_EXTRACT(data,'$.studentIds'),JSON_QUOTE(?))");
  $st->execute([$schoolYear,$studentId]);
  foreach($st as $row){$d=json_decode($row['data'],true)?:[];if($inTerm($d))$classes[$row['id']]=$d;}
  return [$grades,$classes];
}
/** A "tagged Complete" record must copy the student's real grades: same subjects and values, and every ungraded class listed as pending. */
function completionMatchesGrades($after): bool {
  [$grades,$classes]=tagScope((string)g($after,'studentId'),(string)g($after,'schoolYear'),str(g($after,'termKey')),(string)g($after,'term'));
  $subjects=json_decode(json_encode(g($after,'subjects')),true);$pending=json_decode(json_encode(g($after,'pending')),true);
  if(!is_array($subjects) || !is_array($pending) || count($subjects)!==count($grades))return false;
  $real=[];foreach($grades as $d)$real[]=[(string)($d['subjectCode']??''),is_numeric($d['finalGrade']??null)?round((float)$d['finalGrade'],2):null,(string)($d['remarks']??'')];
  foreach($subjects as $x){
    $want=[(string)($x['subjectCode']??''),is_numeric($x['finalGrade']??null)?round((float)$x['finalGrade'],2):null];
    $hit=null;foreach($real as $i=>$rv)if($rv[0]===$want[0]&&$rv[1]===$want[1]&&($want[1]!==null||strcasecmp($rv[2],(string)($x['remarks']??''))===0)){$hit=$i;break;}
    if($hit===null)return false;unset($real[$hit]);
  }
  $ungraded=count(array_diff_key($classes,$grades));
  if(count($pending)!==$ungraded)return false;
  $inc=$ungraded>0;foreach($subjects as $x)if(($x['mark']??null)==='INC')$inc=true;
  return !$inc || g($after,'incomplete')===true;
}
/** True when the grade's student already has their term tagged Complete by the registrar. */
function termTagged($d): bool {
  if(!($d instanceof stdClass))return false;
  $sid=(string)g($d,'studentId');$sy=(string)g($d,'schoolYear');$term=(string)g($d,'term');
  if($sid===''||$sy==='')return false;
  $key=$term===''?'noterm':trim(preg_replace('/[^a-z0-9]+/','-',strtolower($term)),'-');
  $st=pdo()->prepare("SELECT 1 FROM docs WHERE col='gradeCompletions' AND id IN (?,?)");$st->execute(["{$sid}__{$sy}__{$key}","{$sid}__{$sy}__year"]);
  return (bool)$st->fetchColumn();
}
/** A grading student that SIS or the grade book already depends on. */
function studentInUse(string $id): bool {
  $st=pdo()->prepare("SELECT 1 FROM sis_registrations WHERE student_doc_id=? UNION SELECT 1 FROM docs WHERE col IN ('grades','gradeCompletions') AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.studentId'))=? LIMIT 1");
  try{$st->execute([$id,$id]);}catch(PDOException $e){return false;} // SIS tables not installed: grading only
  return (bool)$st->fetchColumn();
}
function teacherDraft(Rules $r, $b): bool {
  if (!$r->isTeacher || teachersLocked($r) || !truthy($b) || g($b, "draft") !== true || !eq(g($b, "teacherDocId"), $r->myTeacher)) return false;
  $a = $r->before("gradingAssignments", str(g($b, "assignmentId")));
  return truthy($a) && eq(g($a, "teacherDocId"), $r->myTeacher) && termOpen($r, $a) && inSchoolYear($a);
}
/** A draft belongs to its school year: from Aug 1 of the year it ends it is locked for good. */
function inSchoolYear($a): bool {
  $sy = g($a, "schoolYear");
  if (!preg_match('/^[^-]*-(\d{4})$/', is_string($sy) ? $sy : (isNum($sy) ? (string) $sy : ""), $m)) return true;
  return nowMs() < gmmktime(0, 0, 0, 8, 1, (int) $m[1]) * 1000;
}
/** The changed draft still belongs to the same student and class. */
function draftStaysInClass(Rules $r, $b, $d, string $id): bool {
  if (!truthy($d) || !eq(g($d, "teacherDocId"), $r->myTeacher) || !eq(g($d, "assignmentId"), g($b, "assignmentId")) || !eq(g($d, "studentId"), g($b, "studentId"))) return false;
  $a = $r->before("gradingAssignments", str(g($d, "assignmentId")));
  return truthy($a) && inArrayJs(g($a, "studentIds"), g($d, "studentId")) && matchesClass($d, $a, $id) && draftFlagOk($d);
}
function draftFlagOk($d): bool { $v = g($d, "draft"); return $v instanceof Undef || is_bool($v); }
function inArrayJs($list, $v): bool {
  if (!is_array($list)) return false;
  foreach ($list as $x) if (eq($x, $v) && !($x instanceof stdClass) && !is_array($x)) return true;
  return false;
}
function teacherOwnsNew(Rules $r, $d, string $id): bool {
  if (!$r->isTeacher || !truthy($d) || !eq(g($d, "teacherDocId"), $r->myTeacher)) return false;
  $a = $r->before("gradingAssignments", str(g($d, "assignmentId")));
  return truthy($a) && eq(g($a, "teacherDocId"), $r->myTeacher) && inArrayJs(g($a, "studentIds"), g($d, "studentId")) && termOpen($r, $a) &&
    matchesClass($d, $a, $id);
}
/** A teacher's grade must belong to its class exactly (one record per student and class). */
function matchesClass($d, $a, string $id): bool {
  $v = fn($x) => $x instanceof Undef ? null : $x;
  $jsStr = fn($x) => $x instanceof Undef ? "undefined" : ($x === null ? "null" : (is_bool($x) ? ($x ? "true" : "false") : (isNum($x) ? jsNumber($x) : (is_string($x) ? $x : enc($x)))));
  $term = fn($o) => truthy(g($o, "term")) ? g($o, "term") : "";
  return $id === $jsStr(g($d, "assignmentId")) . "_" . $jsStr(g($d, "studentId")) &&
    same($v(g($d, "subjectId")), $v(g($a, "subjectId"))) && same($v(g($d, "units")), $v(g($a, "units"))) && same($v(g($d, "schoolYear")), $v(g($a, "schoolYear"))) &&
    eq($term($d), $term($a)) && same($v(g($d, "sectionId")), $v(g($a, "sectionId")));
}
function jsNumber($n): string { return is_int($n) ? (string) $n : rtrim(rtrim(sprintf("%.15g", $n), "0"), "."); }
function approvedChange(Rules $r, string $gradeId, $d): bool {
  $req = truthy($d) && truthy(g($d, "lastChangeRequestId")) ? $r->after("gradeChangeRequests", str(g($d, "lastChangeRequestId"))) : null;
  $n = fn($v) => $v instanceof Undef ? null : $v;
  return truthy($req) && eq(g($req, "status"), "approved") && eq(g($req, "gradeId"), $gradeId) && eq($n(g($req, "newGrade")), $n(g($d, "finalGrade"))) &&
    eq($n(g($req, "newRemarks")), $n(g($d, "remarks"))) && eq(g($req, "decidedBy"), $r->uid);
}

// ---------------------------------------------------------- audit trail (same as js/audit.js)
const AUDIT_NOISE = ["activeSession", "lastSeen", "updatedAt", "createdAt"];

function auditValue($v) {
  if ($v instanceof Undef || $v === null) return null;
  if (is_string($v)) {
    if (str_starts_with($v, "data:image")) return "[image]";
    return jsLength($v) > 300 ? mb_substr($v, 0, 300) . "…" : $v;
  }
  if (is_array($v)) {
    $list = array_map("auditValue", array_slice($v, 0, 100));
    if (count($v) > 100) $list[] = "… and " . (count($v) - 100) . " more";
    return $list;
  }
  if (!($v instanceof stdClass)) return $v;
  if (is_string(g($v, "__ts"))) return $v->__ts;
  $o = new stdClass();
  foreach (get_object_vars($v) as $k => $x) $o->$k = auditValue($x);
  return $o;
}

function auditLabel(string $col, $d, string $id): string {
  $d = obj($d);
  $j = function (...$xs) {
    $keep = [];
    foreach ($xs as $x) if (!($x instanceof Undef) && $x !== null && trim(is_string($x) ? $x : jsText($x)) !== "") $keep[] = is_string($x) ? $x : jsText($x);
    return implode(" · ", $keep);
  };
  switch ($col) {
    case "students": return $j(g($d, "studentName"), g($d, "studentId"));
    case "teachers": return $j(g($d, "teacherName"), g($d, "teacherId"));
    case "subjects": return $j(g($d, "subjectCode"), g($d, "subjectName"));
    case "sections": return $j(g($d, "sectionName"), g($d, "yearLevel"), g($d, "schoolYear"));
    case "gradingAssignments": return $j(g($d, "subjectCode"), g($d, "sectionName"), g($d, "teacherName"), g($d, "schoolYear"), g($d, "term"));
    case "curriculum": return $j(g($d, "program"), g($d, "yearLevel"), g($d, "term"));
    case "grades": return $j(g($d, "studentName"), g($d, "subjectCode"), g($d, "sectionName"));
    case "gradeChangeRequests": return $j(g($d, "studentName"), g($d, "subjectCode"), g($d, "status"));
    case "rosterRequests": return $j(g($d, "subjectCode"), g($d, "sectionName"), g($d, "status"));
    case "gradeCompletions": return $j(g($d, "studentName"), g($d, "schoolYear"), g($d, "term"));
    case "users": return $j(g($d, "displayName"), g($d, "username"), g($d, "role"));
    case "settings": return ["school" => "School details", "security" => "Sign-in security", "email" => "Email sending", "downtime" => "Teacher downtime", "options" => "Setup and options", "sheets" => "Google Sheets copy", "signin" => "Sign-in page picture"][$id] ?? $id;
    default: return $id;
  }
}
function jsText($x): string {
  if (is_bool($x)) return $x ? "true" : "false";
  if (isNum($x)) return jsNumber($x);
  if (is_array($x)) return implode(",", array_map(fn($v) => ($v === null || $v instanceof Undef) ? "" : jsText($v), $x));
  if ($x instanceof stdClass) return "[object Object]";
  return (string) $x;
}

function auditEntries(array $planned, Ctx $ctx, string $nowIso): array {
  $me = obj($ctx->me);
  $who = [
    "byUid" => $ctx->uid ?? "",
    "byName" => truthy(g($me, "displayName")) ? g($me, "displayName") : (truthy(g($me, "username")) ? g($me, "username") : ""),
    "byRole" => truthy(g($me, "role")) ? g($me, "role") : "",
  ];
  $out = [];
  $auditDeletes = 0;
  foreach ($planned as $p) {
    if ($p["col"] === "auditLog") { if ($p["type"] === "delete" && $p["before"]) $auditDeletes++; continue; }
    if ($p["col"] === "notifications") continue;
    if ($p["type"] === "delete" && !$p["before"]) continue;
    $action = $p["type"] === "delete" ? "delete" : ($p["before"] ? "update" : "create");
    $seen = [];
    foreach (get_object_vars(obj($p["before"])) as $k => $_) $seen[(string) $k] = 1;
    foreach (get_object_vars(obj($p["after"])) as $k => $_) $seen[(string) $k] = 1;
    $changed = [];
    foreach (array_keys($seen) as $k) {
      $k = (string) $k;
      if (in_array($k, AUDIT_NOISE, true)) continue;
      if ($action !== "update" || !same(g(obj($p["before"]), $k), g(obj($p["after"]), $k))) $changed[] = $k;
    }
    if ($action === "update" && !$changed) continue; // only background noise changed
    $pick = function ($o) use ($changed) {
      $x = new stdClass();
      if ($o instanceof stdClass) foreach ($changed as $k) if (property_exists($o, $k)) $x->$k = auditValue($o->$k);
      return $x;
    };
    $out[] = (object) array_merge(["at" => (object) ["__ts" => $nowIso]], $who, [
      "action" => $action, "col" => $p["col"], "docId" => $p["id"], "label" => auditLabel($p["col"], $p["after"] ?: $p["before"], $p["id"]),
      "before" => $action === "create" ? new stdClass() : $pick($p["before"]),
      "after" => $action === "delete" ? new stdClass() : $pick($p["after"]),
    ]);
  }
  if ($auditDeletes) {
    $out[] = (object) array_merge(["at" => (object) ["__ts" => $nowIso]], $who, [
      "action" => "delete", "col" => "auditLog", "docId" => "", "label" => $auditDeletes . " audit trail entr" . ($auditDeletes === 1 ? "y" : "ies"),
      "before" => new stdClass(), "after" => new stdClass(),
    ]);
  }
  return $out;
}

// ---------------------------------------------------------- reads
function getOne(Ctx $ctx, string $path): ?array {
  [$col, $id] = parsePath($path);
  $data = beforeDoc($ctx, $col, $id);
  if (!$data) return null;
  if (!canRead(rulesFor($ctx, null), $col, $id, $data)) fail("permission-denied", "You don't have access to this record.");
  return ["id" => $id, "data" => $data];
}

function fieldValue(array $d, string $field) { return $field === "__name__" ? $d["id"] : g($d["data"], $field); }
function sortKey($v) { return ($v instanceof stdClass && is_string(g($v, "__ts"))) ? $v->__ts : $v; }
function compareJs($a, $b): int {
  $a = sortKey($a); $b = sortKey($b);
  if ($a === $b || (isNum($a) && isNum($b) && $a == $b)) return 0;
  if ($a instanceof Undef || $a === null) return 1;
  if ($b instanceof Undef || $b === null) return -1;
  if (isNum($a) && isNum($b)) return $a < $b ? -1 : 1;
  return localeCompare(jsText($a), jsText($b));
}
/**
 * Sorts like compareJs, but works out each value's sort key once (60,000 audit entries sort in a
 * fraction of a second instead of seconds). Same order as compareJs, which matches Database.gs.
 */
function sortDocs(array $docs, array $orderBy): array {
  $keys = [];
  foreach ($docs as $i => $d) foreach ($orderBy as $k => $o) $keys[$i][$k] = sortKeyOf(fieldValue($d, (string) ($o[0] ?? "")));
  $idx = array_keys($docs);
  usort($idx, function ($x, $y) use ($keys, $orderBy) {
    foreach ($orderBy as $k => $o) {
      $c = compareKeys($keys[$x][$k], $keys[$y][$k]);
      if ($c) return ($o[1] ?? "asc") === "desc" ? -$c : $c;
    }
    return $x <=> $y; // stable, like JavaScript's sort
  });
  return array_map(fn($i) => $docs[$i], $idx);
}
/** [kind, value]: kind 2 = missing/null (sorted last), 1 = number, 0 = other (text sort key). */
function sortKeyOf($v): array {
  $v = sortKey($v);
  if ($v instanceof Undef || $v === null) return [2, null, null];
  if (isNum($v)) return [1, $v, localeKey(jsText($v))];
  return [0, null, localeKey(jsText($v))];
}
function compareKeys(array $a, array $b): int {
  if ($a[0] === 2 || $b[0] === 2) return $a[0] === $b[0] ? 0 : ($a[0] === 2 ? 1 : -1);
  if ($a[0] === 1 && $b[0] === 1) return $a[1] == $b[1] ? 0 : ($a[1] < $b[1] ? -1 : 1);
  return strcmp($a[2], $b[2]) <=> 0;
}
function localeKey(string $s): string {
  static $coll = null;
  if (class_exists("Collator")) {
    if (!$coll) {
      $coll = new Collator("en");
      $coll->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
      $coll->setStrength(Collator::PRIMARY);
    }
    $k = $coll->getSortKey($s);
    if ($k !== false) return $k;
  }
  return strtolower($s);
}

/** String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: "base" }) */
function localeCompare(string $a, string $b): int {
  static $coll = null;
  if (class_exists("Collator")) {
    if (!$coll) {
      $coll = new Collator("en");
      $coll->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
      $coll->setStrength(Collator::PRIMARY);
    }
    return $coll->compare($a, $b) <=> 0;
  }
  return strnatcasecmp($a, $b) <=> 0;
}
function matchesWhere(array $d, $w): bool {
  if (!is_array($w) || count($w) < 3) fail("invalid-argument", "Bad filter.");
  [$field, $op, $val] = $w;
  $v = fieldValue($d, (string) $field);
  $def = !($v instanceof Undef);
  switch ($op) {
    case "==": return $def && same($v, $val);
    case "!=": return $def && !same($v, $val);
    case "in": case "not-in":
      $list = truthy($val) ? $val : []; // (val || []) as in the other versions
      if (!is_array($list)) fail("invalid-argument", "The \"" . $op . "\" filter needs a list.");
      if (!$def) return false;
      foreach ($list as $x) if (same($v, $x)) return $op === "in";
      return $op === "not-in";
    case "array-contains": if (!is_array($v)) return false; foreach ($v as $x) if (same($x, $val)) return true; return false;
    case "<": return $def && compareJs($v, $val) < 0;
    case "<=": return $def && compareJs($v, $val) <= 0;
    case ">": return $def && compareJs($v, $val) > 0;
    case ">=": return $def && compareJs($v, $val) >= 0;
    default: fail("invalid-argument", "Unsupported filter: " . (is_string($op) ? $op : "?"));
  }
}

/** Indexed columns (install.sql) the database can search first; the exact checks follow in PHP. */
const INDEXED = ["assignmentId" => "f_assignmentId", "teacherDocId" => "f_teacherDocId", "studentId" => "f_studentId",
  "schoolYear" => "f_schoolYear", "yearLevel" => "f_yearLevel", "sectionId" => "f_sectionId", "toUid" => "f_toUid"];

function runQuery(Ctx $ctx, string $col, array $where, array $orderBy, $limitN): array {
  $r = rulesFor($ctx, null);
  if (($col === "usernames" || $col === "settings" || $col === "meta") && !$r->isAdmin) fail("permission-denied", "You can't list " . $col . ".");
  if ($col === "") fail("invalid-argument", "No list given.");
  // Narrow the search in MySQL where an index helps; every condition is checked again exactly below
  $sql = "SELECT id, data FROM docs WHERE col = ?";
  $args = [$col];
  foreach ($where as $w) {
    if (!is_array($w) || count($w) < 3) continue;
    [$field, $op, $val] = $w;
    if ($field === "__name__" && $op === "==" && is_string($val)) { $sql .= " AND id = ?"; $args[] = $val; }
    elseif ($field === "__name__" && $op === "in" && is_array($val) && $val && count(array_filter($val, "is_string")) === count($val)) {
      $sql .= " AND id IN (" . implode(",", array_fill(0, count($val), "?")) . ")"; array_push($args, ...$val);
    }
    elseif ($op === "==" && is_string($val) && isset(INDEXED[$field])) { $sql .= " AND " . INDEXED[$field] . " = ?"; $args[] = $val; }
  }
  $s = pdo()->prepare($sql);
  $s->execute($args);
  $docs = [];
  foreach ($s as $row) {
    $data = decodeJson($row["data"]);
    if (!($data instanceof stdClass)) $data = new stdClass();
    $d = ["id" => (string) $row["id"], "data" => $data];
    foreach ($where as $w) if (!matchesWhere($d, $w)) continue 2;
    if (!canRead($r, $col, $d["id"], $data)) continue;
    $docs[] = $d;
  }
  if ($orderBy) $docs = sortDocs($docs, $orderBy);
  if (truthy($limitN) && isNum($limitN)) $docs = array_slice($docs, 0, (int) $limitN);
  return $docs;
}

// ---------------------------------------------------------- writes (all-or-nothing)
function resolveServerTimes($v, string $nowIso) {
  if (is_array($v)) return array_map(fn($x) => resolveServerTimes($x, $nowIso), $v);
  if ($v instanceof stdClass) {
    if (g($v, "__serverTimestamp") === true) return (object) ["__ts" => $nowIso];
    $o = new stdClass();
    foreach (get_object_vars($v) as $k => $x) $o->$k = resolveServerTimes($x, $nowIso);
    return $o;
  }
  return $v;
}

/**
 * The records as they will be after these changes, checked against the rules
 * (throws "permission-denied" on the first change that isn't allowed). Nothing is saved.
 */
function planWrites(Ctx $ctx, array $ops, string $nowIso): array {
    $after = [];
    $planned = [];
    foreach ($ops as $op) {
      if (!($op instanceof stdClass)) fail("invalid-argument", "Bad change.");
      [$col, $id] = parsePath(str(g($op, "path")));
      $current = (isset($after[$col]) && array_key_exists($id, $after[$col])) ? $after[$col][$id] : beforeDoc($ctx, $col, $id);
      $data = has($op, "data") && $op->data !== null ? resolveServerTimes($op->data, $nowIso) : null;
      $type = g($op, "type");
      if ($type === "delete") $next = null;
      elseif ($type === "update") {
        if (!$current) fail("not-found", "No record to update: " . $op->path);
        $next = assign($current, $data);
      } elseif ($type === "set") {
        $next = (g($op, "merge") === true && $current) ? assign($current, $data) : assign($data);
      } else fail("invalid-argument", "Unknown write: " . (is_string($type) ? $type : "?"));
      $after[$col][$id] = $next;
      $planned[] = ["type" => $type, "col" => $col, "id" => $id, "before" => $current, "after" => $next];
    }
    // Promotion to a later school year requires the source year's second-semester
    // final grades to be tagged complete. The browser checks this too, but the
    // API must enforce it because a client can submit commit operations directly.
    foreach ($planned as $p) {
      if ($p["col"] !== "students" || !$p["before"] || !$p["after"]) continue;
      $from = str(g($p["before"], "schoolYear"));
      $to = str(g($p["after"], "schoolYear"));
      if (!preg_match('/^(\d{4})-(\d{4})$/', $from, $fm) || !preg_match('/^(\d{4})-(\d{4})$/', $to, $tm)) continue;
      if ((int) $tm[1] !== (int) $fm[1] + 1) continue;
      $completionId = $p["id"] . "__" . $from . "__2nd-semester";
      $completion=$after['gradeCompletions'][$completionId]??beforeDoc($ctx,'gradeCompletions',$completionId);
      if (!$completion || g($completion,'migrationReversed')===true) {
        fail("failed-precondition", "Cannot promote this student: the second-semester final grades for " . $from . " are not tagged complete.");
      }
    }
    // v56: new grading assignments need a semester (no "whole school year"). Older ones keep theirs.
    foreach ($planned as $p) {
      if ($p["col"] === "gradingAssignments" && !$p["before"] && $p["after"] && !in_array(str(g($p["after"], "term")), ["1st Semester", "2nd Semester", "3rd Semester", "Summer"], true))
        fail("invalid-argument", "Choose the semester: 1st Semester, 2nd Semester, 3rd Semester or Summer.");
    }
    $r = rulesFor($ctx, $after);
    foreach ($planned as $p) {
      if ($p["col"]==='gradeCompletions' && $p['before'] instanceof stdClass && property_exists($p['before'],'migrationBatch'))fail('failed-precondition','Imported academic history is managed through SIS Data Migration. Use its reviewed reversal/correction process.');
      if ($p["type"] === "delete" && !$p["before"]) continue; // deleting something missing is fine
      $kind = $p["type"] === "delete" ? "delete" : ($p["before"] ? "update" : "create");
      if (!canWrite($r, $kind, $p["col"], $p["id"], $p["before"] ?? U(), $p["after"] ?? U())) {
        // A timed-out sign-in is told apart from a real refusal, so the page can say "sign in again".
        if ($ctx->uid === null) fail("unauthenticated", "Your sign-in ended, so this was NOT saved. Sign in again, then save once more.");
        fail("permission-denied", "Not allowed: " . $kind . " in " . $p["col"] . ".");
      }
    }
    return [$after, $planned];
}

function commitOps(Ctx $ctx, array $ops): void {
  if (!$ops) return;
  if (count($ops)>2000) fail('invalid-argument','Too many changes at once.');
  $db=pdo();
  foreach($ops as $op) if(preg_match('#^(students|gradingAssignments)/#',(string)($op->path??''))) {StudentHistoryStore::install($db);break;}
  $got=$db->query("SELECT GET_LOCK('grading_system_commit',30)")->fetchColumn();
  if ((string)$got!=='1') fail('unavailable','The database is busy. Try again in a moment.');
  try {
    CurriculumAssignments::locked($db,function()use($db,$ctx,$ops){
      $db->beginTransaction();
      try {
        $ctx->docs=[];$ctx->cols=[];
        if ($ctx->uid) $ctx->me=beforeDoc($ctx,'users',$ctx->uid);
        $nowIso=nowIso();[$after,$planned]=planWrites($ctx,$ops,$nowIso);
        $service=new CurriculumAssignments($db);$assignmentBefore=[];$curriculumBefore=[];
        foreach($after['curriculum']??[] as $id=>$d) if ($d!==null) $curriculumBefore[$id]=json_decode(json_encode(beforeDoc($ctx,'curriculum',(string)$id)),true);
        foreach($after['gradingAssignments']??[] as $id=>$d) if ($d!==null) $assignmentBefore[$id]=json_decode(json_encode(beforeDoc($ctx,'gradingAssignments',(string)$id)),true);
        foreach($after['gradingAssignments']??[] as $id=>$data) {
          StudentHistoryStore::recordClass($db,(string)$id,json_decode(json_encode(beforeDoc($ctx,'gradingAssignments',(string)$id)),true));
          StudentHistoryStore::recordClass($db,(string)$id,$data===null?null:json_decode(jsonText($data),true));
        }
        foreach($after['students']??[] as $id=>$data) {
          StudentHistoryStore::capture($db,(string)$id,json_decode(json_encode(beforeDoc($ctx,'students',(string)$id)),true));
          StudentHistoryStore::capture($db,(string)$id,$data===null?null:json_decode(jsonText($data),true));
        }
        foreach(auditEntries($planned,$ctx,$nowIso) as $e) $after['auditLog'][bin2hex(random_bytes(16))]=$e;
        $put=$db->prepare('INSERT INTO docs(col,id,data) VALUES(?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)');
        $del=$db->prepare('DELETE FROM docs WHERE col=? AND id=?');
        foreach($after as $col=>$byId) foreach($byId as $id=>$d) {
          if ($d===null) $del->execute([$col,(string)$id]);
          else $put->execute([$col,(string)$id,jsonText($d)]);
        }
        foreach($after['gradingAssignments']??[] as $id=>$d) if ($d!==null) $service->reassignGrades((string)$id,json_decode(jsonText($d),true),$assignmentBefore[$id]??null);
        foreach($after['curriculum']??[] as $id=>$d) if ($d!==null) {try{$service->syncCurriculum((string)$id,$curriculumBefore[$id]??null);}catch(RuntimeException $e){fail('invalid-argument',$e->getMessage());}}
        foreach($after['sections']??[] as $id=>$d) if ($d!==null) $service->syncSection((string)$id);
        $db->commit();$ctx->docs=[];$ctx->cols=[];
      } catch(Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
    });
  } finally {$db->query("SELECT RELEASE_LOCK('grading_system_commit')");}
}

// ---------------------------------------------------------- School Information System (../sis)
// The SIS lives in the sis/ folder and uses this same database. Its students, sections, year
// levels, teachers, subjects and curriculum ARE this system's records (docs); it only adds its own
// sis_* tables for Assessment (fee categories, labels, names) and Transactions (admission,
// registration, cashier).

const SIS_DIR = __DIR__ . "/../sis";

function sisRules(Ctx $ctx): Rules {
  $r = rulesFor($ctx, null);
  if (!$r->active) fail("permission-denied", "Sign in first.");
  return $r;
}

/**
 * v56.5: enrollment status for the Students list (staff only, read only).
 * "<student doc id>|<school year>" => "confirmed" (a confirmed SIS enrollment that year) or "pending" (registered,
 * not confirmed yet). Students without an SIS enrollment for their school year are not listed.
 */
function enrollmentStatus(Ctx $ctx, string $term): array {
  $r = sisRules($ctx);
  if (!$r->isStaff) fail("permission-denied", "Only staff can see enrollment status.");
  if (!is_file(SIS_DIR . "/index.php") || !sisTablesInstalled()) return ["installed" => false, "status" => (object) []];
  $terms = ["1st Semester", "2nd Semester", "3rd Semester", "Summer"];
  $sql = "SELECT student_doc_id, school_year, MAX(locked_at IS NOT NULL) AS done FROM sis_registrations
           WHERE status <> 'CANCELLED' AND record_origin = 'ENROLLMENT'" . (in_array($term, $terms, true) ? " AND term = ?" : "") . " GROUP BY student_doc_id, school_year";
  $q = pdo()->prepare($sql); $q->execute(in_array($term, $terms, true) ? [$term] : []);
  $out = [];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[$row["student_doc_id"] . "|" . $row["school_year"]] = (int) $row["done"] ? "confirmed" : "pending";
  return ["installed" => true, "status" => (object) $out];
}

function sisTablesInstalled(): bool {
  $s = pdo()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                      AND table_name IN ('sis_users','sis_fee_categories','sis_registrations','sis_payments')");
  return (int) $s->fetchColumn() === 4;
}

/** What the SIS page in the grading system shows. */
function sisStatus(Ctx $ctx): array {
  $r = sisRules($ctx);
  $present = is_file(SIS_DIR . "/index.php") && is_file(SIS_DIR . "/database/schema.sql");
  $installed = $present && sisTablesInstalled();
  $out = [
    "present" => $present,
    "installed" => $installed,
    "database" => (string) pdo()->query("SELECT DATABASE()")->fetchColumn(),
    "canInstall" => $r->isAdmin && $present && !$installed,
  ];
  if ($installed) {
    $n = fn(string $sql) => (int) pdo()->query($sql)->fetchColumn();
    $sy = (string) pdo()->query("SELECT COALESCE(MAX(school_year), '') FROM sis_registrations")->fetchColumn();
    $q = pdo()->prepare("SELECT COUNT(*) FROM sis_registrations WHERE school_year = ? AND status = 'REGISTERED'");
    $q->execute([$sy]);
    $out["counts"] = [
      "pendingAdmissions" => $n("SELECT COUNT(*) FROM sis_admissions WHERE status = 'PENDING'"),
      "registrations" => (int) $q->fetchColumn(),
      "assessmentNames" => $n("SELECT COUNT(*) FROM sis_assessment_names WHERE is_active = 1"),
      "collectedToday" => (string) pdo()->query("SELECT COALESCE(SUM(amount), 0) FROM sis_payments WHERE status = 'POSTED' AND DATE(paid_at) = CURDATE()")->fetchColumn(),
    ];
    $out["schoolYear"] = $sy ?: null;
  }
  return $out;
}

/** Administrators: create the SIS tables in this database (only when they don't exist yet). */
function sisInstall(Ctx $ctx): array {
  fail('permission-denied','Install and manage SIS through the SIS command line. Read CENTRAL_ACCOUNTS.md.');
}
