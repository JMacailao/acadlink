<?php
/** Disposable MySQL workflow and ownership checks. Never uses the configured live DB. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit;
require __DIR__.'/../app/bootstrap.php';
// The configured MySQL server (or PORTAL_TEST_PORT=3369 for a separate test server); a new disposable database each run.
$cfg=sis_config(); if(getenv('PORTAL_TEST_PORT')){$cfg['db_host']='127.0.0.1';$cfg['db_port']=(int)getenv('PORTAL_TEST_PORT');unset($cfg['db_socket']);} $name='acadlink_portal_test_'.bin2hex(random_bytes(4));
$dsn=!empty($cfg['db_socket'])?'mysql:unix_socket='.$cfg['db_socket'].';charset=utf8mb4':sprintf('mysql:host=%s;port=%d;charset=utf8mb4',$cfg['db_host'],$cfg['db_port']);
$server=new PDO($dsn,$cfg['db_user'],$cfg['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$cfg['db_name']=$name;Db::connect($cfg);
Db::pdo()->exec(file_get_contents(dirname(SIS_ROOT).'/INSTALL.sql'));
$legacyApp=Db::insert('sis_portal_enrollments',['account_id'=>1,'student_number'=>'UPGRADE_ONLY','student_doc_id'=>'upgrade-fixture','student_name'=>'Upgrade Fixture','school_year'=>'2026-2027','term'=>'1st Semester','eligibility_snapshot'=>'{}']);
foreach (['current_enrollment','requested_year_level','preferred_section_doc_id','preferred_section_name','approved_placement'] as $column) Db::q("ALTER TABLE sis_portal_enrollments DROP COLUMN `$column`");
sis_upgrade();sis_upgrade();
require __DIR__ . '/_legacy-payments.php'; // v56: these flows predate the payment-plan rule

$legacy=Db::one('SELECT * FROM sis_portal_enrollments WHERE id=?',[$legacyApp]);
if ($legacy['student_number']!=='UPGRADE_ONLY' || !array_key_exists('approved_placement',$legacy) || $legacy['current_enrollment']!==null) throw new RuntimeException('Additive upgrade lost or changed a legacy application.');
Db::q('DELETE FROM sis_portal_enrollments WHERE id=?',[$legacyApp]);
echo "PASS fresh install and repeatable populated v31 schema upgrade\n";
$c=Auth::central(); $power=(int)Db::val('SELECT id FROM central_accounts ORDER BY id LIMIT 1');
function actor(int $id):void {Auth::actAs(null);Auth::central()->establish($id);}
function expect(bool $v,string $m='Assertion failed'):void{if(!$v)throw new RuntimeException($m);}
function denied(callable $fn,string $part=''):void{try{$fn();}catch(AppError|Forbidden|CentralAccountError $e){if($part!=='')expect(stripos($e->getMessage(),$part)!==false,$e->getMessage());return;}throw new RuntimeException('Expected denial: '.$part);}
$passed=0;$failed=0;
function test(string $name,callable $fn):void{global $passed,$failed;try{$fn();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".$e->getMessage()."\n";}}
actor($power);
Settings::save(array_replace(Settings::all(),['balance_enrollment_enabled'=>'0']));
$section=GS::saveSection(null,'2026-2027','1st Year','BSIT 1A');
$preferredSection=GS::saveSection(null,'2026-2027','1st Year','BSIT 1B');
$approvedSection=GS::saveSection(null,'2026-2027','1st Year','BSIT 1C');
$foreignSection=GS::saveSection(null,'2026-2027','1st Year','BSBA 1A');
$otherSection=GS::saveSection(null,'2027-2028','2nd Year','BSIT 2A');
$subject=GS::saveSubject(null,'PE101','Portal Course','3');
$fee=Assessment::saveName(null,['name'=>'Portal Fees','is_active'=>'1']);
$cat=Assessment::saveCategory(null,['name'=>'Portal Tuition','is_active'=>'1']);
$label=Assessment::saveLabel(null,['category_id'=>$cat,'name'=>'Portal Tuition Fee','charge_type'=>'FIXED','default_amount'=>'1000.00','is_active'=>'1']);
Assessment::setItem($fee,$label,'FIXED',100000);
$doc=GS::saveStudent(null,['studentId'=>'PORTAL001','studentName'=>'Student, Portal','email'=>'','sectionId'=>$section]);
$doc2=GS::saveStudent(null,['studentId'=>'PORTAL002','studentName'=>'Other, Student','email'=>'','sectionId'=>$section]);
$student=$c->save($power,['full_name'=>'Portal Student','username'=>'portal.test.student','group'=>'STUDENT','status'=>'ACTIVE','student_number'=>'PORTAL001','temp_password'=>'Testing123']);
$other=$c->save($power,['full_name'=>'Other Student','username'=>'portal.test.other','group'=>'STUDENT','status'=>'ACTIVE','student_number'=>'PORTAL002','temp_password'=>'Testing123']);
$registrar=$c->save($power,['full_name'=>'Portal Registrar','username'=>'portal.test.registrar','group'=>'REGISTRAR','status'=>'ACTIVE','temp_password'=>'Testing123']);
$accountant=$c->save($power,['full_name'=>'Portal Accountant','username'=>'portal.test.accountant','group'=>'ACCOUNTING','status'=>'ACTIVE','temp_password'=>'Testing123']);
TermCalendar::save(null,['school_year'=>'2026-2027','term'=>'2nd Semester','enrollment_start'=>date('Y-m-d',strtotime('-2 days')),'enrollment_end'=>date('Y-m-d',strtotime('+20 days'))]);
[$old]=Registration::create(null,'PORTAL001',$section,'1st Semester',$fee,[$subject]);
$in=['requested_year_level'=>'1st Year','preferred_section_id'=>$preferredSection,'school_year'=>'2026-2027','term'=>'2nd Semester','details_confirmed'=>'1','student_note'=>'Please review my enrollment.','student_number'=>'PORTAL002'];
actor($student);
test('unfinalized grades block submission',fn()=>denied(fn()=>PortalEnrollment::submit($in),'tagged Complete'));
actor($power);
$tag=['studentId'=>$doc,'studentNumber'=>'PORTAL001','schoolYear'=>'2026-2027','term'=>'1st Semester','yearLevel'=>'1st Year','sectionId'=>$section,'sectionName'=>'BSIT 1A','incomplete'=>false,'pending'=>[],'subjects'=>[['subjectCode'=>'PE101','subjectName'=>'Portal Course','finalGrade'=>92,'remarks'=>'Passed','mark'=>'']]];
$tagId=$doc.'__2026-2027__1st-semester';
Db::q("INSERT INTO docs(col,id,data) VALUES('gradeCompletions',?,?)",[$tagId,json_encode($tag)]);
actor($student);
test('forged preferred section in another program is rejected',fn()=>denied(fn()=>PortalEnrollment::submit(array_replace($in,['preferred_section_id'=>$foreignSection])),'preferred section'));
test('semester continuation cannot promote a student',fn()=>denied(fn()=>PortalEnrollment::submit(array_replace($in,['requested_year_level'=>'2nd Year','preferred_section_id'=>''])),'year level'));
$id=PortalEnrollment::submit($in);
test('submission uses authenticated Student ID and creates no registration',function()use($id){$a=PortalEnrollment::own($id);expect($a['student_number']==='PORTAL001');expect($a['status']==='PENDING'&&!$a['registration_id']);});
test('application records current and requested placement without changing the student',function()use($id,$doc,$section,$preferredSection){$a=PortalEnrollment::own($id);$before=json_decode($a['current_enrollment'],true);expect($before['section_doc_id']===$section);expect($before['term']==='1st Semester');expect($a['requested_year_level']==='1st Year'&&$a['preferred_section_doc_id']===$preferredSection);expect(GS::get('students',$doc)['sectionId']===$section);});
test('duplicate submissions are blocked',fn()=>denied(fn()=>PortalEnrollment::submit($in),'already have'));
test('student cannot approve an application',fn()=>denied(fn()=>PortalEnrollment::accept($id,['review_confirmed'=>'1','section_id'=>'bad'])));
actor($other);
test('another student cannot view the application',fn()=>denied(fn()=>PortalEnrollment::own($id)));
actor($registrar);
test('review reason is required',fn()=>denied(fn()=>PortalEnrollment::decide($id,'RETURNED',''),'reason'));
PortalEnrollment::decide($id,'RETURNED','Please confirm your contact details with the registrar.');
actor($student);
test('returned application resubmits in place',function()use($in,$id){expect(PortalEnrollment::submit($in,$id)===$id);expect((int)Db::val('SELECT COUNT(*) FROM sis_portal_enrollments')===1);});
actor($registrar);
test('acceptance requires explicit registrar review',fn()=>denied(fn()=>PortalEnrollment::accept($id,['section_id'=>$section]),'Confirm'));
test('wrong target year fails atomically',function()use($id,$otherSection){denied(fn()=>PortalEnrollment::accept($id,['section_id'=>$otherSection,'review_confirmed'=>'1']),'school year');expect(Db::val('SELECT status FROM sis_portal_enrollments WHERE id=?',[$id])==='PENDING');});
$rid=PortalEnrollment::accept($id,['section_id'=>$approvedSection,'review_confirmed'=>'1','assessment_name_id'=>$fee]);
test('acceptance creates one open draft and preserves earlier records',function()use($rid,$old,$tagId){expect(Registration::state(Registration::get($rid))==='OPEN');expect(Registration::get($old)['student_number']==='PORTAL001');expect((bool)GS::get('gradeCompletions',$tagId));expect((int)Db::val('SELECT COUNT(*) FROM sis_portal_enrollments WHERE registration_id=?',[$rid])===1);});
test('registrar can approve another section without overwriting the request or current student',function()use($id,$doc,$section,$preferredSection,$approvedSection){$a=Db::one('SELECT * FROM sis_portal_enrollments WHERE id=?',[$id]);$approved=json_decode($a['approved_placement'],true);expect($a['preferred_section_doc_id']===$preferredSection);expect($approved['section_doc_id']===$approvedSection);expect(GS::get('students',$doc)['sectionId']===$section);});
test('accepted application cannot be accepted twice',fn()=>denied(fn()=>PortalEnrollment::accept($id,['section_id'=>$section,'review_confirmed'=>'1']),'pending'));
actor($accountant);
test('payments require a published assessment',fn()=>denied(fn()=>Cashier::pay($rid,10000,'CASH',null,bin2hex(random_bytes(16)),null),'Publish'));
actor($registrar);
Registration::update($rid,$fee,[$subject]);

// Capture pending registrar controls and an unpublished registration before finishing the flow.
if ($preview=getenv('PORTAL_RENDER_DIR')) {
    require_once SIS_ROOT.'/app/views/layout.php';
    $isPost=false;$_SERVER['REQUEST_METHOD']='GET';$_GET=['r'=>'registration','id'=>$rid];$route='registration';
    (function()use($preview,$rid,$isPost){$_GET=['r'=>'registration','id'=>$rid];ob_start();include SIS_ROOT.'/app/pages/registration.php';file_put_contents($preview.'/registration-draft.html',ob_get_clean());})();
}
test('publish requires assessment review',fn()=>denied(fn()=>PortalEnrollment::publish($rid,['minimum_payment'=>'200.00']),'Confirm'));
PortalEnrollment::publish($rid,['assessment_reviewed'=>'1','minimum_payment'=>'200.00']);
$a=Db::one('SELECT * FROM sis_portal_enrollments WHERE id=?',[$id]);
test('published assessment is visible and acceptance is not enrollment',function()use($a,$rid){expect(PortalEnrollment::published($a,Registration::get($rid)));expect(PortalEnrollment::progress($a)[1]===3);});
test('enrollment confirmation enforces required payment',fn()=>denied(fn()=>Registration::finish($rid,true),'required enrollment payment'));
Registration::addAdjustment($rid,'Additional books',10000);
test('fee changes invalidate publication',function()use($a,$rid){expect(!PortalEnrollment::published($a,Registration::get($rid)));denied(fn()=>PortalEnrollment::assertPayment($rid),'Publish');});
PortalEnrollment::publish($rid,['assessment_reviewed'=>'1','minimum_payment'=>'200.00']);
actor($accountant);$payment=Cashier::pay($rid,20000,'CASH',null,bin2hex(random_bytes(16)),null);
actor($registrar);Registration::finish($rid,true);
test('payment then confirmation produces enrolled status',function()use($id,$rid){$a=Db::one('SELECT * FROM sis_portal_enrollments WHERE id=?',[$id]);expect(PortalEnrollment::progress($a)[1]===5);expect(Registration::state(Registration::get($rid))==='DONE');});
test('confirmation applies the approved section and preserves the original request',function()use($id,$doc,$approvedSection,$preferredSection){expect(GS::get('students',$doc)['sectionId']===$approvedSection);$a=Db::one('SELECT * FROM sis_portal_enrollments WHERE id=?',[$id]);expect($a['preferred_section_doc_id']===$preferredSection);});
test('earlier grade and registration history remains intact',function()use($old,$tagId){expect((bool)GS::get('gradeCompletions',$tagId));expect(Registration::get($old)['status']==='REGISTERED');});
actor($student);
test('closed application period cannot be forged',fn()=>denied(fn()=>PortalEnrollment::submit(array_replace($in,['school_year'=>'2027-2028','term'=>'1st Semester'])),'not opened'));
actor($power);
test('upgrade runs repeatedly without altering application history',function()use($id){sis_upgrade();sis_upgrade();expect((int)Db::val('SELECT COUNT(*) FROM sis_portal_enrollment_events WHERE application_id=?',[$id])>=4);});

actor($power);
TermCalendar::save(null,['school_year'=>'2027-2028','term'=>'1st Semester','enrollment_start'=>date('Y-m-d',strtotime('-2 days')),'enrollment_end'=>date('Y-m-d',strtotime('+20 days'))]);
$tag2=$tag;$tag2['studentId']=$doc2;$tag2['studentNumber']='PORTAL002';$tag2['term']='2nd Semester';$tag2['incomplete']=true;$tag2['pending']=[['subjectCode'=>'PE101']];
$tag2id=$doc2.'__2026-2027__2nd-semester';
Db::q("INSERT INTO docs(col,id,data) VALUES('gradeCompletions',?,?)",[$tag2id,json_encode($tag2)]);
$nextIn=array_replace($in,['school_year'=>'2027-2028','term'=>'1st Semester','requested_year_level'=>'2nd Year','preferred_section_id'=>$otherSection]);
actor($other);
test('INC blocks applications even if school allows incomplete registration',fn()=>denied(fn()=>PortalEnrollment::submit($nextIn),'incomplete or missing'));
actor($power);$tag2['incomplete']=false;$tag2['pending']=[];Db::q("UPDATE docs SET data=? WHERE col='gradeCompletions' AND id=?",[json_encode($tag2),$tag2id]);
actor($other);$id2=PortalEnrollment::submit($nextIn);
actor($registrar);PortalEnrollment::decide($id2,'REJECTED','Requirements not met.');
actor($other);
test('rejected request remains readable with decision reason',function()use($id2){$a=PortalEnrollment::own($id2);expect($a['status']==='REJECTED'&&$a['review_note']==='Requirements not met.');});
$id3=PortalEnrollment::submit($nextIn);
test('new application after rejection keeps previous history',function()use($id2,$id3){expect($id3!==$id2);expect((int)Db::val('SELECT COUNT(*) FROM sis_portal_enrollments WHERE student_number=?',['PORTAL002'])===2);});
actor($power);$tag2['subjects'][0]['remarks']='Failed';$tag2['subjects'][0]['finalGrade']=65;Db::q("UPDATE docs SET data=? WHERE col='gradeCompletions' AND id=?",[json_encode($tag2),$tag2id]);
actor($registrar);
test('Complete failing grades do not authorize next-year promotion',fn()=>denied(fn()=>PortalEnrollment::accept($id3,['section_id'=>$otherSection,'review_confirmed'=>'1']),'finalized as Passed'));
actor($power);Db::q("DELETE FROM docs WHERE col='gradeCompletions' AND id=?",[$tag2id]);
actor($registrar);
test('grades withdrawn after submission block acceptance',fn()=>denied(fn()=>PortalEnrollment::accept($id3,['section_id'=>$otherSection,'review_confirmed'=>'1']),'tagged Complete'));
actor($power);$tag2['subjects'][0]['remarks']='Passed';$tag2['subjects'][0]['finalGrade']=92;Db::q("INSERT INTO docs(col,id,data) VALUES('gradeCompletions',?,?)",[$tag2id,json_encode($tag2)]);
actor($registrar);$rid2=PortalEnrollment::accept($id3,['section_id'=>$otherSection,'review_confirmed'=>'1','assessment_name_id'=>$fee]);test('next-year acceptance leaves the current year unchanged until confirmation',fn()=>expect(GS::get('students',$doc2)['schoolYear']==='2026-2027'));Registration::update($rid2,$fee,[$subject]);PortalEnrollment::publish($rid2,['assessment_reviewed'=>'1','minimum_payment'=>'0.00']);Registration::finish($rid2,true);
test('zero-minimum policy permits explicit staff confirmation without payment',function()use($rid2){expect(Registration::state(Registration::get($rid2))==='DONE');expect(Money::cents(Registration::get($rid2)['amount_paid'])===0);});
test('next-year confirmation applies the approved year level',fn()=>expect(GS::get('students',$doc2)['sectionId']===$otherSection&&GS::get('students',$doc2)['yearLevel']==='2nd Year'));
actor($registrar);
Registration::void($rid2,'Correction needed before reenrollment');
test('voided registration releases the active application and preserves the published assessment',function()use($id3,$rid2){$a=Db::one('SELECT * FROM sis_portal_enrollments WHERE id=?',[$id3]);expect($a['status']==='REJECTED');expect(PortalEnrollment::visible($a,Registration::get($rid2)));expect(!PortalEnrollment::published($a,Registration::get($rid2)));});
actor($accountant);
test('Accounting cannot accept or reject enrollment applications',function()use($id3){denied(fn()=>PortalEnrollment::decide($id3,'REJECTED','Wrong office'));denied(fn()=>PortalEnrollment::accept($id3,['review_confirmed'=>'1']));});
// The requested Second Year -> Third Year scenario, with placement changes deferred to confirmation.
actor($power);
$secondYear=GS::saveSection(null,'2026-2027','2nd Year','BSIT 2A');
$thirdYearA=GS::saveSection(null,'2027-2028','3rd Year','BSIT 3A');
$thirdYearB=GS::saveSection(null,'2027-2028','3rd Year','BSIT 3B');
$promoteDoc=GS::saveStudent(null,['studentId'=>'PROMOTE003','studentName'=>'Third, Year Student','sectionId'=>$secondYear]);
$promoteAccount=$c->save($power,['full_name'=>'Third Year Student','username'=>'portal.third.year','group'=>'STUDENT','status'=>'ACTIVE','student_number'=>'PROMOTE003','temp_password'=>'Testing123']);
$thirdTag=$tag;$thirdTag['studentId']=$promoteDoc;$thirdTag['studentNumber']='PROMOTE003';$thirdTag['term']='2nd Semester';$thirdTag['yearLevel']='2nd Year';$thirdTag['sectionId']=$secondYear;$thirdTag['sectionName']='BSIT 2A';
$thirdTagId=$promoteDoc.'__2026-2027__2nd-semester';
Db::q("INSERT INTO docs(col,id,data) VALUES('gradeCompletions',?,?)",[$thirdTagId,json_encode($thirdTag)]);
$thirdIn=['school_year'=>'2027-2028','term'=>'1st Semester','requested_year_level'=>'3rd Year','preferred_section_id'=>$thirdYearA,'details_confirmed'=>'1'];
actor($promoteAccount);
test('higher year cannot be forged in the same academic year',fn()=>denied(fn()=>PortalEnrollment::validatePlacement(GS::get('students',$promoteDoc),'2026-2027','2nd Semester','3rd Year'),'year level'));
test('promotion cannot skip a year level',fn()=>denied(fn()=>PortalEnrollment::submit(array_replace($thirdIn,['requested_year_level'=>'4th Year'])),'year level'));
if ($preview=getenv('PORTAL_RENDER_DIR')) {
    require_once SIS_ROOT.'/app/views/layout.php';
    $_SERVER['REQUEST_METHOD']='GET';$isPost=false;$route='myenrollment';$_GET=['r'=>'myenrollment','check'=>'1']+$thirdIn;
    ob_start();(function()use($isPost){include dirname(SIS_ROOT).'/portal/pages/myenrollment.php';})();file_put_contents($preview.'/student-apply-third-year.html',ob_get_clean());
}
$idThird=PortalEnrollment::submit($thirdIn);
actor($registrar);
if ($preview=getenv('PORTAL_RENDER_DIR')) {
    $route='enrollment';$_GET=['r'=>'enrollment','id'=>$idThird];ob_start();(function()use($isPost){include SIS_ROOT.'/app/pages/enrollment.php';})();file_put_contents($preview.'/registrar-review-third-year.html',ob_get_clean());
}
$ridThird=PortalEnrollment::accept($idThird,['section_id'=>$thirdYearB,'assessment_name_id'=>$fee,'review_confirmed'=>'1']);
test('third-year approval preserves second-year current section until confirmation',fn()=>expect(GS::get('students',$promoteDoc)['sectionId']===$secondYear));
Registration::update($ridThird,$fee,[$subject]);PortalEnrollment::publish($ridThird,['assessment_reviewed'=>'1','minimum_payment'=>'0.00']);
actor($power);$thirdTag['subjects'][0]['remarks']='Failed';Db::q("UPDATE docs SET data=? WHERE col='gradeCompletions' AND id=?",[json_encode($thirdTag),$thirdTagId]);
actor($registrar);
test('promotion is rechecked at confirmation and rollback preserves current placement',function()use($ridThird,$promoteDoc,$secondYear){denied(fn()=>Registration::finish($ridThird,true),'year level');expect(Registration::state(Registration::get($ridThird))==='OPEN');expect(GS::get('students',$promoteDoc)['sectionId']===$secondYear);});
actor($power);$thirdTag['subjects'][0]['remarks']='Passed';Db::q("UPDATE docs SET data=? WHERE col='gradeCompletions' AND id=?",[json_encode($thirdTag),$thirdTagId]);
actor($registrar);Registration::finish($ridThird,true);
test('second-year student becomes third-year only after confirmation and old records remain',function()use($idThird,$promoteDoc,$thirdYearA,$thirdYearB,$secondYear,$thirdTagId){$s=GS::get('students',$promoteDoc);expect($s['sectionId']===$thirdYearB&&$s['yearLevel']==='3rd Year');$a=Db::one('SELECT * FROM sis_portal_enrollments WHERE id=?',[$idThird]);expect($a['preferred_section_doc_id']===$thirdYearA);expect(json_decode($a['current_enrollment'],true)['section_doc_id']===$secondYear);expect(GS::get('gradeCompletions',$thirdTagId)['sectionId']===$secondYear);});
// Render the actual portal and staff pages with production layout code for visual/HTTP-error checks.
require_once SIS_ROOT.'/app/views/layout.php';
$_SERVER['REQUEST_METHOD']='GET';$isPost=false;
$out=getenv('PORTAL_RENDER_DIR');
if ($out) {
    foreach ([['myenrollment',$student,['id'=>$id]],['myenrollment',$other,['id'=>$id3]],['enrollment',$registrar,['id'=>$id3]],['registration',$registrar,['id'=>$rid2]],['my',$student,[]],['history',$student,['tab'=>'assessments']]] as $case) {
        [$route,$who,$query]=$case;actor($who);$_GET=['r'=>$route]+$query;ob_start();
        $file=in_array($route,['my','myenrollment','myadmission'],true)?dirname(SIS_ROOT).'/portal/pages/'.$route.'.php':SIS_ROOT.'/app/pages/'.$route.'.php';
        try {(function($file)use($isPost){include $file;})($file);$html=ob_get_clean();file_put_contents($out.'/'.$route.'-'.$who.'.html',$html);test('render '.$route.' for account '.$who,fn()=>expect(stripos($html,'Fatal error')===false&&stripos($html,'Warning:')===false));}
        catch(Throwable $e){ob_end_clean();test('render '.$route.' for account '.$who,function()use($e){throw $e;});}
    }
}
actor($power);
GS::saveStudent($doc2,['studentId'=>'PORTAL002','studentName'=>'Other, Student','sectionId'=>$section]);
$tag2['migrationReversed']=true;
Db::q("UPDATE docs SET data=? WHERE col='gradeCompletions' AND id=?",[json_encode($tag2),$tag2id]);
actor($other);
test('reversed imported grade evidence cannot permit enrollment',fn()=>denied(fn()=>PortalEnrollment::submit($nextIn),'tagged Complete'));
actor($power);unset($tag2['migrationReversed']);Db::q("UPDATE docs SET data=? WHERE col='gradeCompletions' AND id=?",[json_encode($tag2),$tag2id]);
$originalStudent=GS::get('students',$doc2);$graduatedStudent=$originalStudent;$graduatedStudent['status']='graduated';unset($graduatedStudent['id']);
Db::q("UPDATE docs SET data=? WHERE col='students' AND id=?",[json_encode($graduatedStudent),$doc2]);
actor($other);
test('graduated records require registrar review rather than automatic enrollment',fn()=>denied(fn()=>PortalEnrollment::submit($nextIn),'Graduated'));
actor($power);unset($originalStudent['id']);Db::q("UPDATE docs SET data=? WHERE col='students' AND id=?",[json_encode($originalStudent),$doc2]);
Db::q('UPDATE central_accounts SET must_change_password=0');
echo "$passed passed, $failed failed\n";
file_put_contents(getenv('PORTAL_TEST_RESULT')?:sys_get_temp_dir().'/portal-test-db.txt',$name);
exit($failed?1:0);
