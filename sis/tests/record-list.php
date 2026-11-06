<?php
/** php sis/tests/record-list.php — no database or web server needed. */
declare(strict_types=1);
require __DIR__.'/../app/lib/core.php';require __DIR__.'/../app/lib/record-list.php';
$checks=0;function check(bool $v,string $message):void{global $checks;if(!$v)throw new RuntimeException($message);$checks++;echo "PASS $message\n";}
$_GET=['r'=>'students'];check(RecordList::enabled()&&!RecordList::applied(),'student list begins unapplied');
check(RecordList::rows(fn()=>throw new RuntimeException('Unexpected initial record query'))===[],'initial list never executes the loader');
check(RecordList::query('INVALID SQL MUST NOT EXECUTE')===[],'initial list does not execute its result SQL');
$_GET['apply']=['1'];check(!RecordList::applied(),'array query values cannot activate a list');
$_GET=['r'=>'students','apply'=>'1','sy'=>'2026-2027','year'=>'1st Year','section'=>'A','q'=>'JUAN'];$records=[['id'=>'one','studentName'=>'Juan Dela Cruz','schoolYear'=>'2026-2027','yearLevel'=>'1st Year','sectionId'=>'A'],['id'=>'two','studentName'=>'Other Student','schoolYear'=>'2026-2027','yearLevel'=>'1st Year','sectionId'=>'A'],['id'=>'three','studentName'=>'Juan Another','schoolYear'=>'2025-2026','yearLevel'=>'1st Year','sectionId'=>'A']];check(array_column(RecordList::rows(fn()=>$records),'id')===['one'],'applied student filters use school year, level, section and case-insensitive search');
$_GET=['r'=>'teachers','apply'=>'1','q'=>'CRUZ'];check(count(RecordList::rows(fn()=>[['teacherName'=>'Maria Cruz'],['teacherName'=>'Juan Santos']]))===1,'teachers filter before rendering');
$_GET=['r'=>'users','apply'=>'1','group'=>'TEACHER','status'=>'ACTIVE'];check(count(RecordList::rows(fn()=>[['group_code'=>'TEACHER','status'=>'ACTIVE'],['group_code'=>'STUDENT','status'=>'ACTIVE'],['group_code'=>'TEACHER','status'=>'DISABLED']]))===1,'account group and status filters combine');
$_GET=['r'=>'bulkaccounts','group'=>'TEACHER','apply'=>'1','sy'=>'2026-2027'];check(count(RecordList::rows(fn()=>[['teacherName'=>'Global Teacher','_schoolYear'=>'']]))===1,'teacher account candidates are independent of student cohort filters');
foreach([['r'=>'admission','id'=>'1'],['r'=>'registration','new'=>'1'],['r'=>'cashier','reg'=>'1'],['r'=>'accounting','tab'=>'overview'],['r'=>'accounting','tab'=>'mappings'],['r'=>'accounting','tab'=>'studentledger','student'=>'one'],['r'=>'users','tab'=>'groups'],['r'=>'dashboard']] as $get){$_GET=$get;check(!RecordList::enabled(),'detail/config/dashboard bypass '.json_encode($get));}
$_GET=['r'=>'teachers','apply'=>'1','q'=>'Cruz','edit'=>'private-id'];$_SERVER['REQUEST_METHOD']='GET';$_SESSION=[];RecordList::remember();check(!isset($_SESSION['record_lists'][RecordList::key()]['edit']),'saved list context excludes the selected private record');
$_SERVER['REQUEST_METHOD']='POST';check(RecordList::redirectParams('teachers',[])===['apply'=>'1','q'=>'Cruz'],'saving restores the applied same-module filter');
check(RecordList::redirectParams('users',[])===[],'saving does not carry filters into another module');
$_GET=['r'=>'teachers'];$_SERVER['REQUEST_METHOD']='GET';RecordList::remember();$_SERVER['REQUEST_METHOD']='POST';check(RecordList::redirectParams('teachers',[])===[],'clear forgets previous applied list context');
echo "$checks record-list checks passed\n";
