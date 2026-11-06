<?php
/** Included by the disposable centralized-account suite. */
if(PHP_SAPI!=='cli') exit;
ca_test('teacher shortcut repairs a dangling UID without changing classes or grades',function()use($c,$power,$section,$subject,$student){
    Auth::actAs((int)$c->legacyId($power,'SIS'));
    $tid=GS::saveTeacher(null,'LINK001','Stale Linkteacher');
    $c->q("UPDATE docs SET data=JSON_SET(data,'$.userUid','deleted_firebase_uid','$.notes','Keep these details') WHERE col='teachers' AND id=?",[$tid]);
    $aid=GS::assignTeacher($section,'Summer',$subject,$tid,[$student]);
    $c->q("INSERT INTO docs(col,id,data) VALUES('grades','stale_link_grade',?)",[json_encode(['assignmentId'=>$aid,'teacherDocId'=>$tid,'studentId'=>$student,'finalGrade'=>91,'draft'=>false])]);
    $assignment=Db::val("SELECT data FROM docs WHERE col='gradingAssignments' AND id=?",[$aid]);$grade=Db::val("SELECT data FROM docs WHERE col='grades' AND id='stale_link_grade'");
    ca_ok($c->teacherAccountLinkState($tid)['state']==='stale');
    $r=$c->createTeacherAccount($power,$tid);$t=GS::get('teachers',$tid);
    ca_ok($c->verifyPassword($r['account_id'],$r['temporary_password']));ca_ok($c->account($r['account_id'])['teacher_doc_id']===$tid);
    ca_ok($t['userUid']===$c->legacyId($r['account_id'],'GRADING')&&$t['teacherId']==='LINK001'&&$t['notes']==='Keep these details');
    ca_ok(Db::val("SELECT data FROM docs WHERE col='gradingAssignments' AND id=?",[$aid])===$assignment&&Db::val("SELECT data FROM docs WHERE col='grades' AND id='stale_link_grade'")===$grade);
    ca_ok((bool)$c->account($r['account_id'])['must_change_password']);ca_denied(fn()=>$c->createTeacherAccount($power,$tid));
    ca_ok((bool)Db::val("SELECT 1 FROM central_account_audit WHERE target_account_id=? AND action='stale_teacher_reference_repaired'",[$r['account_id']]));
});
ca_test('bulk preview is read only and bulk creation safely replaces a dangling teacher reference',function()use($c,$power){
    $tid=GS::saveTeacher(null,'LINK002','Bulk Linkteacher');$c->q("UPDATE docs SET data=JSON_SET(data,'$.userUid','deleted_bulk_uid') WHERE col='teachers' AND id=?",[$tid]);
    $bulk=new BulkAccounts($c,$power);$preview=$bulk->preview('TEACHER',[$tid])[0];
    ca_ok($preview['status']==='Ready'&&GS::get('teachers',$tid)['userUid']==='deleted_bulk_uid');
    $r=$bulk->create('TEACHER',$preview,'SharedTemporary123',7);
    ca_ok($r['status']==='Created'&&GS::get('teachers',$tid)['userUid']===$c->legacyId($r['account_id'],'GRADING'));
    ca_ok($bulk->create('TEACHER',$preview,'OtherTemporary123',7)['status']==='Skipped');ca_ok($c->verifyPassword($r['account_id'],'SharedTemporary123'));
});
ca_test('a real legacy profile referenced only by UID still blocks duplicate account creation',function()use($c,$power){
    $tid=GS::saveTeacher(null,'LINK003','Real Linkteacher');$c->q("UPDATE docs SET data=JSON_SET(data,'$.userUid','live_legacy_uid') WHERE col='teachers' AND id=?",[$tid]);
    $c->q("INSERT INTO docs(col,id,data) VALUES('users','live_legacy_uid',?)",[json_encode(['username'=>'real.legacy.uid','role'=>'teacher','active'=>true])]);
    $count=Db::val('SELECT COUNT(*) FROM central_accounts');ca_denied(fn()=>$c->createTeacherAccount($power,$tid));
    ca_denied(fn()=>$c->save($power,['full_name'=>'Real Linkteacher','username'=>'real.linkteacher','group'=>'TEACHER','teacher_doc_id'=>$tid,'status'=>'ACTIVE','temp_password'=>'Temporary12345']));
    ca_ok((new BulkAccounts($c,$power))->preview('TEACHER',[$tid])[0]['status']==='Needs review');ca_ok(Db::val('SELECT COUNT(*) FROM central_accounts')===$count);
});
ca_test('a verified central legacy link is protected even when its old profile is missing',function()use($c,$power){
    $target=ca_create('verified.link.target','REGISTRAR');$uid=$c->legacyId($target,'GRADING');
    $c->q("DELETE FROM docs WHERE col='users' AND id=?",[$uid]);
    $tid=GS::saveTeacher(null,'LINK004','Verified Linkteacher');$c->q("UPDATE docs SET data=JSON_SET(data,'$.userUid',?) WHERE col='teachers' AND id=?",[$uid,$tid]);
    $state=$c->teacherAccountLinkState($tid);ca_ok($state['state']==='central'&&str_contains($state['message'],(string)$target));
    ca_denied(fn()=>$c->createTeacherAccount($power,$tid));ca_ok((new BulkAccounts($c,$power))->preview('TEACHER',[$tid])[0]['status']==='Needs review');$c->syncProjections($target);
});
ca_test('late repair failure rolls back credentials, accounts, projections and teacher metadata',function()use($c,$power){
    $tid=GS::saveTeacher(null,'LINK005','Rollback Linkteacher');$c->q("UPDATE docs SET data=JSON_SET(data,'$.userUid','rollback_missing_uid') WHERE col='teachers' AND id=?",[$tid]);
    $before=[];foreach(['central_accounts','central_credentials','central_legacy_links','sis_users'] as $table)$before[$table]=Db::val("SELECT COUNT(*) FROM $table");
    $profileCount=Db::val("SELECT COUNT(*) FROM docs WHERE col='users'");
    $c->q("CREATE TRIGGER teacher_link_test_failure BEFORE UPDATE ON docs FOR EACH ROW BEGIN IF NEW.col='teachers' AND NEW.id=".Db::pdo()->quote($tid)." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='intentional repair failure'; END IF; END");
    try{ca_denied(fn()=>$c->createTeacherAccount($power,$tid));}finally{$c->q('DROP TRIGGER teacher_link_test_failure');}
    foreach($before as $table=>$count)ca_ok(Db::val("SELECT COUNT(*) FROM $table")===$count,$table.' partially written');
    ca_ok(Db::val("SELECT COUNT(*) FROM docs WHERE col='users'")===$profileCount&&GS::get('teachers',$tid)['userUid']==='rollback_missing_uid');
    $r=$c->createTeacherAccount($power,$tid);ca_ok($c->verifyPassword($r['account_id'],$r['temporary_password']));
});
if(getenv('TEACHER_LINK_FIXTURE')) {
    $tid=GS::saveTeacher(null,'LINKBROWSER','Browser Linkteacher');$c->q("UPDATE docs SET data=JSON_SET(data,'$.userUid','browser_missing_uid') WHERE col='teachers' AND id=?",[$tid]);
    file_put_contents(getenv('TEACHER_LINK_FIXTURE'),json_encode(['teacher'=>$tid]));
}
