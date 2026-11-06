<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit;
require __DIR__.'/../app/bootstrap.php';
$cfg=sis_config();$name='acadlink_curriculum_test_'.bin2hex(random_bytes(4));
$db=new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4',$cfg['db_host'],$cfg['db_port']),$cfg['db_user'],$cfg['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$db->exec("USE `$name`");
$sql=preg_replace('/^\s*--.*$/m','',(string)file_get_contents(dirname(SIS_ROOT).'/INSTALL.sql'));
foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql))) as $stmt){$s=$db->query($stmt);while($s->nextRowset()){}$s->closeCursor();}
$cfg['db_name']=$name;Db::connect($cfg);
$c=Auth::central();$power=(int)Db::val('SELECT id FROM central_accounts LIMIT 1');
$c->changePassword($power,'Start-32f32c74-A1','CurriculumAdmin123');Auth::actAs((int)$c->legacyId($power,'SIS'));
$checks=0;
function check(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;echo "PASS $message\n";}
$x=CurriculumAssignments::PLACEHOLDER;
check(GS::get('teachers',$x)['teacherName']==='Professor X','one INSTALL.sql creates the reserved Professor X directory record');
$one=GS::saveTeacher(null,'CURT1','Maria Cruz');$two=GS::saveTeacher(null,'CURT2','Juan Santos');
$s1=GS::saveSubject(null,'CUR101','Mathematics','3');$s2=GS::saveSubject(null,'CUR102','English','3');$s3=GS::saveSubject(null,'CUR103','Science','3');
$sec=GS::saveSection(null,'2026-2027','1st Year','BSIT 1A');$sec2=GS::saveSection(null,'2026-2027','1st Year','BSIT 1B');
$cid=GS::saveCurriculum('BSIT','1st Year','1st Semester',[$s1,$s2,$s3],[$s3=>$one]);
$assign=GS::sectionAssignments($sec,'2026-2027','1st Semester');
check(count($assign)===3 && $assign[$s1]['teacherDocId']===$x && $assign[$s2]['teacherDocId']===$x && $assign[$s3]['teacherDocId']===$one,'saving SIS curriculum creates all matching subjects with chosen teachers or Professor X');
check(count(GS::sectionAssignments($sec2,'2026-2027','1st Semester'))===3,'each matching section gets its own assignments');
GS::saveCurriculum('BSIT','1st Year','1st Semester',[$s1,$s2,$s3],[$s3=>$one]);
check(count(GS::all('gradingAssignments'))===6,'repeated curriculum saves never duplicate assignments');
$later=GS::saveCurriculum('BSN','2nd Year','2nd Semester',[$s1],[]);
$laterSec=GS::saveSection(null,'2026-2027','2nd Year','BSN 2A');
check(count(GS::sectionAssignments($laterSec,'2026-2027','2nd Semester'))===1,'a section added after its curriculum automatically receives assignments');
$wrong=GS::saveSection(null,'2026-2027','3rd Year','BSN 3A');
check(count(GS::sectionAssignments($wrong,'2026-2027','2nd Semester'))===0,'other programs, year levels and terms stay separate');
$before=Db::val("SELECT data FROM docs WHERE col='curriculum' AND id=?",[$cid]);
try{GS::saveCurriculum('BSIT','1st Year','1st Semester',[$s1],[$s1=>'missing-teacher']);throw new LogicException('Missing teacher accepted');}catch(AppError $e){}
check(Db::val("SELECT data FROM docs WHERE col='curriculum' AND id=?",[$cid])===$before,'invalid teacher rolls back the curriculum and assignments');
try{$c->save($power,['full_name'=>'Professor X','username'=>'professor.x','group'=>'TEACHER','teacher_doc_id'=>$x,'status'=>'ACTIVE','temp_password'=>'Temporary12345']);throw new LogicException('Placeholder login accepted');}catch(CentralAccountError $e){}
check(!Db::val('SELECT account_id FROM central_person_links WHERE teacher_doc_id=?',[$x]),'Professor X cannot get a centralized login');
require SIS_ROOT.'/app/lib/bulk-accounts.php';$b=new BulkAccounts($c,$power);
check($b->preview('TEACHER',[$x])[0]['status']!=='Ready','bulk account creation excludes Professor X');
try{GS::saveTeacher($x,'PROF-X','Renamed');throw new LogicException('Placeholder edit accepted');}catch(AppError $e){}
check(GS::get('teachers',$x)['teacherName']==='Professor X','SIS protects the default teacher identity');
$student=GS::saveStudent(null,['studentId'=>'CURST001','studentName'=>'Curriculum Student','sectionId'=>$sec]);
$aid=$assign[$s1]['id'];$data=GS::get('gradingAssignments',$aid);unset($data['id']);$data['studentIds']=[$student];
Db::q("UPDATE docs SET data=? WHERE col='gradingAssignments' AND id=?",[json_encode($data),$aid]);
$grade='curriculum_grade_one';$g=$data+['assignmentId'=>$aid,'studentId'=>$student,'studentNumber'=>'CURST001','studentName'=>'Curriculum Student','finalGrade'=>91,'remarks'=>'Passed','draft'=>false,'status'=>'SUBMITTED'];
Db::q("INSERT INTO docs(col,id,data) VALUES('grades',?,?)",[$grade,json_encode($g)]);
$teacherOne=$c->save($power,['full_name'=>'Maria Cruz','username'=>'curriculum.teacher.one','group'=>'TEACHER','teacher_doc_id'=>$one,'status'=>'ACTIVE','temp_password'=>'CurriculumTemp123']);
$teacherTwo=$c->save($power,['full_name'=>'Juan Santos','username'=>'curriculum.teacher.two','group'=>'TEACHER','teacher_doc_id'=>$two,'status'=>'ACTIVE','temp_password'=>'CurriculumTemp123']);
$c->q('UPDATE central_accounts SET must_change_password=0 WHERE id IN (?,?)',[$teacherOne,$teacherTwo]);

// Classes created after confirmation pick up only confirmed subject registrations.
$confirmedSec=GS::saveSection(null,'2026-2027','1st Year','CONF 1A');
$confirmedStudent=GS::saveStudent(null,['studentId'=>'CONFST001','studentName'=>'Confirmed Student','sectionId'=>$confirmedSec]);
$draftStudent=GS::saveStudent(null,['studentId'=>'CONFST002','studentName'=>'Draft Student','sectionId'=>$confirmedSec]);
foreach([[$confirmedStudent,'CONFST001',date('Y-m-d H:i:s')],[$draftStudent,'CONFST002',null]] as [$sd,$sn,$locked]) {
    $rid=Db::insert('sis_registrations',['reg_no'=>'TEST-'.$sn,'student_number'=>$sn,'student_doc_id'=>$sd,'student_name'=>$sn,'school_year'=>'2026-2027','term'=>'Summer','year_level'=>'1st Year','section_doc_id'=>$confirmedSec,'section_name'=>'CONF 1A','locked_at'=>$locked,'created_by'=>(int)$c->legacyId($power,'SIS')]);
    Db::insert('sis_registration_subjects',['registration_id'=>$rid,'subject_doc_id'=>$s1,'subject_code'=>'CUR101','subject_name'=>'Mathematics','units'=>3]);
}
GS::saveCurriculum('CONF','1st Year','Summer',[$s1]);
$confirmedAssignment=GS::sectionAssignments($confirmedSec,'2026-2027','Summer')[$s1];
check($confirmedAssignment['studentIds']===[$confirmedStudent],'new assignments include confirmed registrations and exclude draft registrations');
foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql))) as $stmt){$stmtResult=$db->query($stmt);while($stmtResult->nextRowset()){}$stmtResult->closeCursor();}
check((int)Db::val("SELECT COUNT(*) FROM docs WHERE col='teachers' AND id=?",[$x])===1,'repeat INSTALL.sql preserves one Professor X record');
echo "$checks curriculum integration checks passed\n";
