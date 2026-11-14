<?php
/**
 * College Grading System: the website's only door to the MySQL database.
 * All the code is in lib.php; see README-PHP.md for setup.
 */
declare(strict_types=1);
require __DIR__ . "/lib.php";
require __DIR__ . "/grading-restore.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: same-origin");

// ---------------------------------------------------------- the request
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  header("Content-Type: text/plain; charset=utf-8");
  echo "Grading System server is running.\n"; // v63.3: no PHP or app version for visitors
  if (PHP_VERSION_ID < 80100) { echo "PROBLEM: PHP 8.1 or newer is needed.\n"; exit; }
  if (!extension_loaded("pdo_mysql")) { echo "PROBLEM: PHP's pdo_mysql extension isn't enabled.\n"; exit; }
  try {
    pdo()->query("SELECT 1 FROM docs LIMIT 1");
    echo "Database: connected.\n";
  } catch (ApiError $e) {
    echo "PROBLEM: " . $e->getMessage() . "\n";
  } catch (PDOException $e) {
    echo "PROBLEM: connected to MySQL, but the grading tables aren't there: import api/install.sql into the database.\n";
  }
  exit;
}

try {
  sameOriginOnly();
  $body = decodeJson((string) file_get_contents("php://input"));
  if (!($body instanceof stdClass) || !is_string(g($body, "action"))) fail("invalid-argument", "The request couldn't be read.");
  startSession();
  $action = $body->action;
  if (in_array($action,['createAccount','setPassword','tempPasswords','sisInstall'],true)) fail('permission-denied','Accounts and access are managed in SIS.');

  // ---- sign-in ----
  switch ($action) {
    case "accountAccess":
      $a=centralAccounts()->current();
      // 'permissions': the Grading modules (and whether SIS opens) for this person, to show only their menu items
      $codes=$a?array_values(array_filter(array_keys(centralAccounts()->permissions((int)$a['id'])),fn($c)=>str_starts_with($c,'grading.')||$c==='sis.workspace.view')):[];
      // v55: the sidebar's Clearance link, when online clearance is on and this person signs or reviews clearance
      $clr=false;
      if ($a) { try { $c=centralAccounts(); $cid=(int)$a['id']; $g=$c->group($cid);
        if ($c->q("SELECT svalue FROM sis_settings WHERE skey='clr_enabled'")->fetchColumn()==='1')
          $clr=$g==='POWER_USER' || $c->can($cid,'sis.clearance.view') || ((string)$c->q('SELECT signs_clearance FROM central_groups WHERE code=? AND is_active=1',[$g])->fetchColumn()==='1' && (string)$c->q('SELECT COALESCE((SELECT can_sign FROM central_clearance_signers WHERE account_id=?),1)',[$cid])->fetchColumn()==='1');
      } catch (Throwable $e) { $clr=false; } }
      reply(['ok'=>true,'clearance'=>$clr,'manageAccounts'=>(bool)($a && centralAccounts()->group((int)$a['id'])==='POWER_USER' && centralAccounts()->can((int)$a['id'],'admin.accounts.manage')),'permissions'=>$codes,
        'systems'=>$a?ModuleAccess::systems(centralAccounts()->permissions((int)$a['id'])):[]]);
    case "whoami": reply(["ok" => true, "user" => currentUser()]);
    case "login": reply(["ok" => true, "user" => login(str(g($body, "email")), str(g($body, "password")), g($body, "takeOver") === true)]);
    case "logout": logout(); reply(["ok" => true]);
    case "reauth": reauth(str(g($body, "password"))); reply(["ok" => true]);
    case "changePassword": changePassword(str(g($body, "password"))); reply(["ok" => true]);
    case "createAccount": reply(["ok" => true, "user" => createAccount(str(g($body, "email")), str(g($body, "password")), g($body, "login") === true)]);
    case "setPassword": setPassword(str(g($body, "uid")), str(g($body, "password"))); reply(["ok" => true]);
    case "tempPasswords": reply(["ok" => true, "accounts" => tempPasswords(g($body, "uids"))]);
  }

  // ---- data ----
  $ctx = makeContext();
  session_write_close(); // reads and saves don't need the session any more (lets requests run side by side)
  switch ($action) {
    case "ping": reply(["ok" => true, "version" => VERSION, "name" => "MySQL", "backend" => "mysql"]);
    case "get": reply(["ok" => true, "doc" => getOne($ctx, str(g($body, "path")))]);
    case "query": reply(["ok" => true, "docs" => runQuery($ctx, str(g($body, "collection")), listOf(g($body, "where")), listOf(g($body, "orderBy")), g($body, "limit"))]);
    case "count": reply(["ok" => true, "count" => count(runQuery($ctx, str(g($body, "collection")), listOf(g($body, "where")), [], null))]);
    case "adminUids":
      if (!$ctx->uid) fail("unauthenticated", "Sign in first."); // v63.3: not for visitors
      $uids = [];
      foreach (loadCol($ctx, "users") as $id => $u) if (eq(g($u, "role"), "admin") && g($u, "active") === true) $uids[] = $id;
      reply(["ok" => true, "uids" => $uids]);
    case "restoreBegin": reply(['ok'=>true,'restore'=>restoreBegin(g($body,'counts'),is_int($body->chunks??null)?$body->chunks:0,str(g($body,'mode')))]);
    case "restoreUpload": reply(['ok'=>true,'restore'=>restoreUpload(str(g($body,'token')),is_int($body->index??null)?$body->index:-1,listOf(g($body,'records')))]);
    case "restoreFinish": reply(['ok'=>true,'restore'=>restoreFinish($ctx,str(g($body,'token')),str(g($body,'confirmation')))]);
    case "commit": commitOps($ctx, listOf(g($body, "ops"))); reply(["ok" => true]);
    case "sisStatus": reply(["ok" => true, "sis" => sisStatus($ctx)]);
    case "enrollmentStatus": reply(["ok" => true] + enrollmentStatus($ctx, str(g($body, "term"))));
    case "sisInstall": reply(["ok" => true, "sis" => sisInstall($ctx)]);
    default: fail("invalid-argument", "Unknown action.");
  }
} catch (ApiError $e) {
  reply(["ok" => false, "code" => $e->apiCode, "message" => $e->getMessage()]);
} catch (Throwable $e) {
  error_log("Grading System: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
  reply(["ok" => false, "code" => "internal", "message" => "The server had a problem. Try again; if it keeps happening, check the PHP error log."]);
}

