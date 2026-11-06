<?php
/** Staged Power User restore. Uploads are private; applying all rows is ONE InnoDB transaction. */
declare(strict_types=1);
const GRADING_RESTORE_COLLECTIONS=['settings','subjects','curriculum','teachers','sections','students','gradingAssignments','grades','gradeChangeRequests','rosterRequests','gradeCompletions'];
function restoreActor(): array {
    $c=centralAccounts(); $a=$c->current();
    if (!$a || $a['must_change_password'] || $c->group((int)$a['id'])!=='POWER_USER' || !$c->can((int)$a['id'],'admin.backup.manage')) fail('permission-denied','Only a Power User can restore grading backups.');
    return $a;
}
function restoreTables(): void {
    StudentHistoryStore::install(pdo());
    // Existing installations upgrade automatically; these CREATEs are also in root INSTALL.sql.
    $sql=(string)file_get_contents(dirname(__DIR__).'/shared/database/grading-restore.sql');
    $sql=preg_replace('/^\s*--.*$/m','',$sql);
    foreach(array_filter(array_map('trim',explode(";\n",$sql))) as $stmt) pdo()->exec($stmt);
}
function restoreSessionHash(): string { return hash('sha256',(string)($_COOKIE[CentralAccounts::COOKIE]??$_SESSION['central_token']??'')); }
function restoreJob(string $token,array $actor,bool $lock=false): array {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) fail('not-found','Restore upload not found. Upload the backup again.');
    $s=pdo()->prepare('SELECT *,expires_at>NOW() AS is_current FROM grading_restore_jobs WHERE token_hash=?'.($lock?' FOR UPDATE':''));$s->execute([hash('sha256',$token)]);$job=$s->fetch();
    $policy=(int)pdo()->query('SELECT policy_version FROM central_security_policy WHERE id=1')->fetchColumn();
    if (!$job || !$job['is_current']) fail('not-found','Restore upload expired. Upload the backup again; no new restore has been applied.');
    if ((int)$job['actor_account_id']!==(int)$actor['id'] || (int)$job['actor_auth_version']!==(int)$actor['auth_version'] || (int)$job['policy_version']!==$policy || !hash_equals($job['session_hash'],restoreSessionHash())) fail('permission-denied','Sign in again and upload the backup from this account.');
    return $job;
}
function restoreBegin($counts,int $chunks,string $mode): array {
    $a=restoreActor();restoreTables();
    if (!($counts instanceof stdClass) || !in_array($mode,['merge','replace'],true) || $chunks<1 || $chunks>2000) fail('invalid-argument','Invalid restore upload plan.');
    $total=0;$checked=[];
    foreach(get_object_vars($counts) as $col=>$n) {
        if (!in_array($col,GRADING_RESTORE_COLLECTIONS,true) || !is_int($n) || $n<0) fail('invalid-argument','Only grading data collections can be restored; account imports are not allowed.');
        $checked[$col]=$n;$total+=$n;
    }
    if (!$checked || $total<1 || $total>200000) fail('invalid-argument','Restore must contain 1–200,000 grading records.');
    $db=pdo();$db->exec('DELETE FROM grading_restore_jobs WHERE expires_at<NOW()');
    $token=bin2hex(random_bytes(32));
    $policy=(int)$db->query('SELECT policy_version FROM central_security_policy WHERE id=1')->fetchColumn();
    $db->prepare('INSERT INTO grading_restore_jobs(token_hash,actor_account_id,actor_auth_version,policy_version,session_hash,restore_mode,expected_counts,expected_chunks,received_chunks,total_rows,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW()+INTERVAL 2 HOUR)')->execute([hash('sha256',$token),$a['id'],$a['auth_version'],$policy,restoreSessionHash(),$mode,json_encode($checked),$chunks,'{}',$total]);
    return ['token'=>$token,'total'=>$total];
}
function restoreUpload(string $token,int $index,array $records): array {
    $a=restoreActor(); $db=pdo();
    if (!$records || count($records)>400 || strlen(jsonText($records))>2*1024*1024) fail('invalid-argument','Restore chunks must contain at most 400 records and 2 MiB.');
    $db->beginTransaction();
    try {
        $job=restoreJob($token,$a,true);
        if ($job['status']!=='STAGING') { $db->commit(); return ['received'=>(int)$job['received_rows'],'state'=>'COMMITTED']; }
        if ($index<0 || $index>=(int)$job['expected_chunks']) fail('invalid-argument','Invalid upload chunk number.');
        $map=json_decode($job['received_chunks'],true)?:[];$digest=hash('sha256',jsonText($records));
        if (isset($map[$index])) {
            if (!hash_equals($map[$index],$digest)) fail('invalid-argument','This upload chunk differs from the original. Start a new restore.');
            $db->commit();return ['received'=>(int)$job['received_rows'],'state'=>'STAGING'];
        }
        $counts=json_decode($job['expected_counts'],true);
        $put=$db->prepare('INSERT INTO grading_restore_records(job_hash,col,id,data) VALUES(?,?,?,?)');
        foreach($records as $row) {
            $col=str(g($row,'collection'));$id=str(g($row,'id'));$data=g($row,'data');
            if (!array_key_exists($col,$counts) || $id==='' || mb_strlen($id)>191 || str_contains($id,'/') || !($data instanceof stdClass)) fail('invalid-argument','Invalid backup record or unselected collection.');
            try { $put->execute([$job['token_hash'],$col,$id,jsonText($data)]); }
            catch(PDOException $e) { if($e->getCode()==='23000') fail('invalid-argument','Duplicate backup record: '.$col.'/'.$id.'.'); throw $e; }
        }
        $received=(int)$job['received_rows']+count($records);
        if ($received>(int)$job['total_rows']) fail('invalid-argument','More records were uploaded than the backup plan declares.');
        $map[$index]=$digest;
        $db->prepare('UPDATE grading_restore_jobs SET received_chunks=?,received_rows=?,expires_at=NOW()+INTERVAL 2 HOUR WHERE token_hash=?')->execute([json_encode((object)$map),$received,$job['token_hash']]);
        $db->commit();return ['received'=>$received,'state'=>'STAGING'];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** Protect identifiers used by authentication, admissions and financial records. */
function restoreProtected(): array {
    $db=pdo();$protected=['teachers'=>[CurriculumAssignments::PLACEHOLDER=>true]];
    foreach($db->query('SELECT teacher_doc_id,student_doc_id,student_number FROM central_person_links FOR UPDATE') as $p) {
        if($p['teacher_doc_id'])$protected['teachers'][$p['teacher_doc_id']]=true;
        if($p['student_doc_id'])$protected['students'][$p['student_doc_id']]=true;
    }
    foreach($db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(data,'$.teacherDocId')) AS id FROM docs WHERE col='users'") as $p) if($p['id'])$protected['teachers'][$p['id']]=true;
    if($db->query("SHOW TABLES LIKE 'sis_student_history'")->fetchColumn())foreach($db->query('SELECT DISTINCT student_doc_id FROM sis_student_history') as $p)$protected['students'][$p['student_doc_id']]=true;
    if($db->query("SHOW TABLES LIKE 'sis_migration_targets'")->fetchColumn())foreach($db->query("SELECT target_id FROM sis_migration_targets WHERE kind='COMPLETION'") as $p)$protected['gradeCompletions'][$p['target_id']]=true;
    foreach($db->query('SELECT student_doc_id,section_doc_id FROM sis_registrations FOR UPDATE') as $p) {
        if($p['student_doc_id'])$protected['students'][$p['student_doc_id']]=true;
        if($p['section_doc_id'])$protected['sections'][$p['section_doc_id']]=true;
    }
    foreach($db->query("SELECT d.id FROM docs d WHERE d.col='students' AND (EXISTS(SELECT 1 FROM sis_admissions a WHERE a.student_number=d.f_studentId) OR EXISTS(SELECT 1 FROM sis_registrations r WHERE r.student_number=d.f_studentId))") as $p)$protected['students'][$p['id']]=true;
    foreach($db->query('SELECT subject_doc_id FROM sis_registration_subjects') as $p)$protected['subjects'][$p['subject_doc_id']]=true;
    if($db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sis_subject_prerequisites'")->fetchColumn())foreach($db->query('SELECT subject_doc_id,prerequisite_doc_id FROM sis_subject_prerequisites') as $p){$protected['subjects'][$p['subject_doc_id']]=true;$protected['subjects'][$p['prerequisite_doc_id']]=true;}
    return $protected;
}
/** Academic references; business Student IDs on student directory records are not document links. */
function restoreReferences(string $col,array $data): array {
    if ($col==='settings') return [];
    $refs=[];
    $walk=function(array $value)use(&$walk,&$refs,$col){
        foreach($value as $key=>$v){
            $parent=['sectionId'=>'sections','subjectId'=>'subjects','teacherDocId'=>'teachers','assignmentId'=>'gradingAssignments','gradeId'=>'grades','studentId'=>'students'][$key]??null;
            if($parent && !($col==='students' && $key==='studentId') && is_string($v) && $v!=='')$refs[$parent][$v]=true;
            if(in_array($key,['studentIds','subjectIds'],true) && is_array($v))foreach($v as $id)if(is_string($id)&&$id!=='')$refs[$key==='studentIds'?'students':'subjects'][$id]=true;
            if($key==='subjectTeachers' && is_array($v))foreach($v as $id)if(is_string($id)&&$id!=='')$refs['teachers'][$id]=true;
            if(is_array($v))$walk($v);
        }
    };
    $walk($data);return $refs;
}
/** Keep only source rows, unselected collections, protected identities, and their transitive parents. */
function restoreSurvivors(string $jobHash,array $selected,array $protected,array &$missing): array {
    $db=pdo();$present=[];$keep=$protected;$incoming=[];$visited=[];
    $names="'".implode("','",GRADING_RESTORE_COLLECTIONS)."'";
    foreach($db->query('SELECT col,id FROM docs WHERE col IN ('.$names.')') as $r){
        $present[$r['col']][$r['id']]=true;
        if(!array_key_exists($r['col'],$selected))$keep[$r['col']][$r['id']]=true;
    }
    $s=$db->prepare('SELECT col,id FROM grading_restore_records WHERE job_hash=?');$s->execute([$jobHash]);
    foreach($s as $r){$incoming[$r['col']][$r['id']]=true;$keep[$r['col']][$r['id']]=true;}
    do {
        $added=false;
        for($offset=0;;$offset+=500){
            $rows=$db->query('SELECT col,id,data FROM docs WHERE col IN ('.$names.') ORDER BY col,id LIMIT 500 OFFSET '.$offset)->fetchAll();
            if(!$rows)break;
            foreach($rows as $r){
                $col=$r['col'];$id=$r['id'];
                if(!isset($keep[$col][$id]) || isset($visited[$col][$id]))continue;
                $visited[$col][$id]=true;
                foreach(restoreReferences($col,json_decode($r['data'],true)?:[]) as $parent=>$ids)foreach($ids as $ref=>$_){
                    if(!isset($present[$parent][$ref])){
                        if(isset($incoming[$col][$id])){$missing['count']++;if(count($missing['examples'])<10)$missing['examples'][]=$col.'/'.$id.' → '.$parent.'/'.$ref;}
                        continue;
                    }
                    if(!isset($keep[$parent][$ref])){$keep[$parent][$ref]=true;$added=true;}
                }
            }
        }
    }while($added);
    return $keep;
}
/**
 * SIS owns Student IDs. Before validation, reconcile each staged student with SIS account links and
 * registrations instead of stopping at the first disagreement:
 *  - same student record, different SIS Student ID → restore it with the SIS number (and its grade copies);
 *  - backup Student ID now owned by a different student record → skip that student (never merge), keep the
 *    live record, and hold back its grading records when the student does not exist live.
 * Runs inside the restore transaction, so a later failure still rolls everything back.
 */
function restoreReconcileStudents(PDO $db,string $jobHash,array &$protected): array {
    $report=['identity_adjusted'=>0,'identity_adjusted_list'=>[],'skipped_students'=>0,'skipped_list'=>[],'held_records'=>0];
    $owners=$db->prepare('SELECT student_doc_id,student_number FROM central_person_links WHERE (student_doc_id=? OR student_number=?) AND student_number IS NOT NULL UNION SELECT student_doc_id,student_number FROM sis_registrations WHERE student_doc_id=? OR student_number=?');
    $live=$db->prepare("SELECT data FROM docs WHERE col='students' AND id=?");
    $setNumber=$db->prepare("UPDATE grading_restore_records SET data=JSON_SET(data,'$.studentId',?) WHERE job_hash=? AND col='students' AND id=?");
    // Copies of the Student ID inside this student's grading records follow the SIS number.
    $setCopies=$db->prepare("UPDATE grading_restore_records SET data=JSON_SET(data,'$.studentNumber',?) WHERE job_hash=? AND col IN ('grades','gradeCompletions','gradeChangeRequests','rosterRequests') AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.studentId'))=? AND JSON_CONTAINS_PATH(data,'one','$.studentNumber')");
    $dropStudent=$db->prepare("DELETE FROM grading_restore_records WHERE job_hash=? AND col='students' AND id=?");
    $dropRecords=$db->prepare("DELETE FROM grading_restore_records WHERE job_hash=? AND col IN ('grades','gradeCompletions','gradeChangeRequests','rosterRequests') AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.studentId'))=?");
    $staged=$db->prepare("SELECT id,JSON_UNQUOTE(JSON_EXTRACT(data,'$.studentId')) AS number,JSON_UNQUOTE(JSON_EXTRACT(data,'$.studentName')) AS name FROM grading_restore_records WHERE job_hash=? AND col='students' ORDER BY id");
    $staged->execute([$jobHash]);
    $add=function(string $list,array $row)use(&$report){$count=$list==='identity_adjusted_list'?'identity_adjusted':'skipped_students';$report[$count]++;if(count($report[$list])<500)$report[$list][]=$row;};
    foreach($staged->fetchAll() as $s){
        $id=(string)$s['id'];$number=(string)($s['number']??'');$name=(string)($s['name']??'');
        $owners->execute([$id,$number,$id,$number]);$rows=$owners->fetchAll();
        $sameRecord=array_values(array_unique(array_map(fn($o)=>(string)$o['student_number'],array_filter($rows,fn($o)=>(string)$o['student_doc_id']===$id))));
        $others=array_values(array_filter($rows,fn($o)=>(string)$o['student_doc_id']!==$id && (string)$o['student_number']===$number));
        if(count($sameRecord)===1){
            // SIS knows this exact student record; its Student ID wins.
            $sis=$sameRecord[0];
            $owners->execute(['',$sis,'',$sis]);
            $taken=array_filter($owners->fetchAll(),fn($o)=>(string)$o['student_doc_id']!==$id);
            if(!$taken){
                if($sis===$number)continue;
                $setNumber->execute([$sis,$jobHash,$id]);$setCopies->execute([$sis,$jobHash,$id]);$add('identity_adjusted_list',['id'=>$id,'name'=>$name,'backup'=>$number,'live'=>$sis]);continue;
            }
            $reason='Student ID '.$sis.' is used by more than one student record in SIS';$owner=(string)array_values($taken)[0]['student_doc_id'];
        }elseif(count($sameRecord)>1){$reason='SIS links this record to more than one Student ID';$owner=$id;}
        elseif($others){$owner=(string)$others[0]['student_doc_id'];$reason='Student ID '.$number.' now belongs to another student record in SIS';}
        else continue;
        $live->execute([$owner]);$ownerDoc=json_decode((string)($live->fetchColumn()?:'null'),true);
        $dropStudent->execute([$jobHash,$id]);
        $live->execute([$id]);$current=$live->fetchColumn();
        if($current!==false){
            // Keep the live record as it is (also in replace mode); its restored grades carry its live Student ID.
            $protected['students'][$id]=true;
            $liveNumber=(string)(json_decode((string)$current,true)['studentId']??'');if($liveNumber!=='')$setCopies->execute([$liveNumber,$jobHash,$id]);
        }
        else{$dropRecords->execute([$jobHash,$id]);$report['held_records']+=$dropRecords->rowCount();}
        $add('skipped_list',['id'=>$id,'name'=>$name,'backup'=>$number,'owner'=>$owner,'owner_name'=>(string)($ownerDoc['studentName']??''),'owner_number'=>(string)($ownerDoc['studentId']??''),'reason'=>$reason]);
    }
    return $report;
}
function restoreFinish(Ctx $ctx,string $token,string $confirmation): array {
    $a=restoreActor();$db=pdo();
    @set_time_limit(180);
    if ((string)$db->query("SELECT GET_LOCK('grading_system_commit',30)")->fetchColumn()!=='1') fail('unavailable','Grading is busy. Retry the same restore after a moment.');
    try {return CurriculumAssignments::locked($db,function()use($ctx,$token,$confirmation,$a,$db){
      try {
        $db->beginTransaction();
        // A central-policy/account lock prevents a concurrent privilege reset during the apply step.
        $db->query('SELECT id FROM central_security_policy WHERE id=1 FOR UPDATE')->fetchAll();
        $s=$db->prepare('SELECT auth_version,status FROM central_accounts WHERE id=? FOR UPDATE');$s->execute([$a['id']]);$fresh=$s->fetch();
        if(!$fresh || $fresh['status']!=='ACTIVE' || (int)$fresh['auth_version']!==(int)$a['auth_version']) fail('permission-denied','Your access changed. Sign in and upload again.');
        $job=restoreJob($token,$a,true);
        if ($job['status']==='COMMITTED') { $db->commit();return json_decode($job['result'],true); }
        if ($job['restore_mode']==='replace' && $confirmation!=='RESTORE') fail('invalid-argument','Type RESTORE to confirm replacement.');
        $expected=json_decode($job['expected_counts'],true);$received=json_decode($job['received_chunks'],true)?:[];
        if (count($received)!==(int)$job['expected_chunks'] || (int)$job['received_rows']!==(int)$job['total_rows']) fail('failed-precondition','Upload is incomplete. No live records have changed.');
        $s=$db->prepare('SELECT col,COUNT(*) AS n FROM grading_restore_records WHERE job_hash=? GROUP BY col');$s->execute([$job['token_hash']]);$actual=array_column($s->fetchAll(),'n','col');
        foreach($expected as $col=>$n)if((int)($actual[$col]??0)!==$n)fail('failed-precondition','Backup record count does not match '.$col.'. No live records have changed.');
        $protected=restoreProtected();
        $identity=restoreReconcileStudents($db,$job['token_hash'],$protected);
        $ctx->docs=[];$ctx->cols=[];$ctx->me=docData('users',(string)$ctx->uid);
        $s=$db->prepare("SELECT data FROM grading_restore_records WHERE job_hash=? AND col='settings' AND id='options'");$s->execute([$job['token_hash']]);$options=$s->fetchColumn();
        if($options)$ctx->docs['settings/options']=decodeJson($options);
        elseif($job['restore_mode']==='replace' && array_key_exists('settings',$expected))$ctx->docs['settings/options']=new stdClass();
        $rules=rulesFor($ctx,null);
        if(!$rules->isAdmin)fail('permission-denied','Power User access is required.');
        $put=$db->prepare('INSERT INTO docs(col,id,data) VALUES(?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)');
        $identityCheck=$db->prepare('SELECT student_doc_id,student_number FROM central_person_links WHERE student_doc_id=? OR student_number=? UNION SELECT student_doc_id,student_number FROM sis_registrations WHERE student_doc_id=? OR student_number=?');
        // Keep verified sign-in pointers current when older teacher backups are restored.
        $db->prepare("UPDATE grading_restore_records r JOIN central_person_links p ON p.teacher_doc_id=r.id JOIN central_legacy_links l ON l.account_id=p.account_id AND l.source_system='GRADING' SET r.data=JSON_SET(r.data,'$.userUid',l.source_key) WHERE r.job_hash=? AND r.col='teachers'")->execute([$job['token_hash']]);
        if($db->query("SHOW TABLES LIKE 'sis_migration_targets'")->fetchColumn())$db->prepare("UPDATE grading_restore_records r JOIN sis_migration_targets t ON t.kind='COMPLETION' AND t.target_id=r.id JOIN docs d ON d.col='gradeCompletions' AND d.id=r.id SET r.data=d.data WHERE r.job_hash=? AND r.col='gradeCompletions'")->execute([$job['token_hash']]);
        $stageData=$db->prepare('UPDATE grading_restore_records SET data=? WHERE job_hash=? AND col=? AND id=?');
        $written=0;
        // Paging bounds PHP memory even when restoring tens of thousands of records.
        for($offset=0;;$offset+=500){
            $s=$db->prepare('SELECT r.col,r.id,r.data,d.data AS before_data FROM grading_restore_records r LEFT JOIN docs d ON d.col=r.col AND d.id=r.id WHERE r.job_hash=? ORDER BY r.col,r.id LIMIT 500 OFFSET '.$offset);$s->execute([$job['token_hash']]);$rows=$s->fetchAll();if(!$rows)break;
            foreach($rows as $row){
                $data=decodeJson($row['data']);$raw=$row['before_data'];$before=$raw?decodeJson($raw):null;
                if($row['col']==='teachers' && $row['id']===CurriculumAssignments::PLACEHOLDER){
                    $clean=json_decode($row['data'],true);
                    foreach($clean as $key=>$value)if(!in_array($key,['teacherId','teacherName','isPlaceholder','createdAt','updatedAt'],true)){
                        if($value!==null && $value!=='')fail('invalid-argument','Professor X is a protected placeholder. No live records changed.');
                        unset($clean[$key]);
                    }
                    $data=decodeJson(json_encode($clean));$stageData->execute([json_encode($clean),$job['token_hash'],$row['col'],$row['id']]);
                }
                $managedCompletion=$row['col']==='gradeCompletions' && isset($protected['gradeCompletions'][$row['id']]) && $before && property_exists($before,'migrationBatch');
                if (!$managedCompletion && !canWrite($rules,$before?'update':'create',$row['col'],$row['id'],$before??U(),$data)) fail('invalid-argument','Backup record is not valid for restore: '.$row['col'].'/'.$row['id'].'. Nothing has been restored.');
                if($row['col']==='grades' && !validGradeRecord($data,$rules))fail('invalid-argument','Invalid grade at grades/'.$row['id'].'. Nothing has been restored.');
                if($row['col']==='students'){
                    $number=str(g($data,'studentId'));
                    // Safety net after reconciliation: any remaining disagreement still rolls back everything.
                    $identityCheck->execute([$row['id'],$number,$row['id'],$number]);foreach($identityCheck->fetchAll() as $owner)if($owner['student_number']!==null && ((string)$owner['student_number']!==$number || (string)$owner['student_doc_id']!==$row['id']))fail('failed-precondition','Student ID conflicts with an account or financial record at students/'.$row['id'].'. Nothing has been restored.');
                }
                if($row['col']==='teachers' && isset($protected['teachers'][$row['id']]) && $before && str(g($before,'teacherId'))!==str(g($data,'teacherId')))fail('failed-precondition','Teacher ID conflicts with a linked account at teachers/'.$row['id'].'. Nothing has been restored.');
                if($row['col']==='students'){StudentHistoryStore::capture($db,$row['id'],$before?json_decode($raw,true):null);StudentHistoryStore::capture($db,$row['id'],json_decode(jsonText($data),true));}
                if($row['col']==='gradingAssignments'){StudentHistoryStore::recordClass($db,$row['id'],$before?json_decode($raw,true):null);StudentHistoryStore::recordClass($db,$row['id'],json_decode(jsonText($data),true));}
                $written++;
            }
        }
        $db->prepare('INSERT INTO docs(col,id,data) SELECT col,id,data FROM grading_restore_records WHERE job_hash=? ON DUPLICATE KEY UPDATE data=VALUES(data)')->execute([$job['token_hash']]);
        $missing=['count'=>0,'examples'=>[]];
        $survivors=restoreSurvivors($job['token_hash'],$expected,$protected,$missing);
        $deleted=0;$retained=0;
        if($job['restore_mode']==='replace'){
            $protected=$survivors;
            foreach(array_keys($expected) as $col){
                $s=$db->prepare('SELECT d.id FROM docs d LEFT JOIN grading_restore_records r ON r.job_hash=? AND r.col=d.col AND r.id=d.id WHERE d.col=? AND r.id IS NULL');$s->execute([$job['token_hash'],$col]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);
                $remove=[];foreach($ids as $id){if(isset($protected[$col][$id])){$retained++;continue;}$remove[]=$id;}
                foreach(array_chunk($remove,500) as $batch){$del=$db->prepare('DELETE FROM docs WHERE col=? AND id IN ('.implode(',',array_fill(0,count($batch),'?')).')');$del->execute(array_merge([$col],$batch));$deleted+=$del->rowCount();}
            }
        }
        $result=['state'=>'COMMITTED','restored'=>$written,'deleted'=>$deleted,'retained'=>$retained,'mode'=>$job['restore_mode'],'missing_references'=>$missing['count'],'missing_examples'=>$missing['examples']]+$identity;
        $put->execute(['auditLog',bin2hex(random_bytes(16)),jsonText((object)['action'=>'restore','col'=>'grading','docId'=>$job['token_hash'],'label'=>'Grading backup restore','byUid'=>$ctx->uid,'byName'=>$a['display_name'],'byRole'=>'admin','at'=>(object)['__ts'=>nowIso()],'before'=>new stdClass(),'after'=>(object)$result])]);
        $db->prepare("UPDATE grading_restore_jobs SET status='COMMITTED',result=?,expires_at=NOW()+INTERVAL 1 DAY WHERE token_hash=?")->execute([json_encode($result),$job['token_hash']]);
        $db->prepare('DELETE FROM grading_restore_records WHERE job_hash=?')->execute([$job['token_hash']]);
        $db->commit();return $result;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });}finally{$db->query("SELECT RELEASE_LOCK('grading_system_commit')");}
}
