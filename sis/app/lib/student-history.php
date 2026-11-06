<?php
declare(strict_types=1);
final class StudentHistory {
    public static function canViewAllGrades(): bool {$a=Auth::central()->current();return Auth::can('registration','users.manage')&&(PHP_SAPI==='cli'||($a&&Auth::central()->can((int)$a['id'],'grading.grades.view')));}
    public static function ownStudent(): ?array {$a=Auth::central()->current();return $a&&!empty($a['person_verified'])&&!empty($a['student_doc_id'])?GS::get('students',$a['student_doc_id']):null;}
    public static function teacherStudents(): array {
        $a=Auth::central()->current();
        if (!$a || !$a['person_verified'] || !$a['teacher_doc_id'] || !Auth::central()->can((int)$a['id'],'grading.grades.view')) return [];
        static $cache=[];$key=$a['teacher_doc_id'];if(PHP_SAPI!=='cli'&&isset($cache[$key]))return $cache[$key];
        $ids=[];
        foreach (Db::all("SELECT data FROM docs WHERE col IN ('gradingAssignments','grades') AND f_teacherDocId=?",[$a['teacher_doc_id']]) as $row) {
            $d=json_decode($row['data'],true);
            foreach ((array)($d['studentIds']??[]) as $id) $ids[(string)$id]=true;
            if (!empty($d['studentId'])) $ids[(string)$d['studentId']]=true;
        }
        foreach(Db::col('SELECT student_doc_id FROM sis_teacher_student_history WHERE teacher_doc_id=?',[$a['teacher_doc_id']]) as $id)$ids[(string)$id]=true;
        $cache[$key]=$ids;return $ids;
    }
    /** The grade entries this person may see for a student: a teacher only their own, staff all. */
    public static function gradeEntries(string $studentDocId): array {
        if (Auth::role()==='TEACHER') {
            $a=Auth::central()->current();
            if (!$a || empty($a['teacher_doc_id'])) return [];
            $rows=Db::all("SELECT data FROM docs WHERE col='grades' AND f_studentId=? AND f_teacherDocId=?",[$studentDocId,$a['teacher_doc_id']]);
        } else $rows=Db::all("SELECT data FROM docs WHERE col='grades' AND f_studentId=?",[$studentDocId]);
        return array_map(fn($r)=>json_decode($r['data'],true),$rows);
    }
    public static function allowed(array $s,string $tab): bool {
        if (Auth::role()==='STUDENT') {$a=Auth::central()->current();return $a&&Auth::central()->can((int)$a['id'],$tab==='grades'?'portal.grades.view':'portal.assessment.view')&&!empty($a['person_verified'])&&(string)($a['student_doc_id']??'')===(string)$s['id']&&Auth::studentNumber()!==null && (string)$s['studentId']===(string)Auth::studentNumber();}
        if (Auth::role()==='TEACHER') return $tab==='grades' && isset(self::teacherStudents()[$s['id']]);
        if ($tab==='grades') return self::canViewAllGrades();
        return Auth::can('registration','accounting','payments.view');
    }
    public static function snapshots(array $s): array {
        StudentHistoryStore::capture(Db::pdo(),$s['id'],$s);
        foreach (Db::all("SELECT * FROM sis_registrations WHERE student_doc_id=? AND record_origin='ENROLLMENT'",[$s['id']]) as $r)
            StudentHistoryStore::capture(Db::pdo(),$s['id'],['studentId'=>$r['student_number'],'studentName'=>$r['student_name'],'schoolYear'=>$r['school_year'],'yearLevel'=>$r['year_level'],'sectionId'=>$r['section_doc_id'],'sectionName'=>$r['section_name'],'status'=>$r['status']],$r['term']);
        foreach (GS::completions($s['id']) as $c) StudentHistoryStore::capture(Db::pdo(),$s['id'],array_merge($c,['studentId'=>$s['studentId'],'sectionId'=>$c['sectionId']??'','status'=>'Completed']),(string)($c['term']??''));
        foreach(Db::all("SELECT data FROM docs WHERE col='grades' AND f_studentId=?",[$s['id']]) as $row){$g=json_decode($row['data'],true);StudentHistoryStore::capture(Db::pdo(),$s['id'],['studentId'=>$g['studentNumber']??$s['studentId'],'studentName'=>$g['studentName']??$s['studentName'],'schoolYear'=>$g['schoolYear']??'','yearLevel'=>$g['yearLevel']??'','sectionId'=>$g['sectionId']??'','sectionName'=>$g['sectionName']??'','status'=>$g['status']??'Grade record'],(string)($g['term']??''));}
        return array_map(fn($r)=>json_decode($r['snapshot'],true),Db::all('SELECT snapshot FROM sis_student_history WHERE student_doc_id=? ORDER BY id DESC',[$s['id']]));
    }
    public static function grades(array $s): array {
        // Teachers see only their own grade entries (as in the Grading API), not other teachers' subjects
        if(Auth::role()==='TEACHER') return [];
        $list=GS::completions($s['id']);
        if(Auth::role()!=='STUDENT') return $list;
        if(!Settings::on('portal_hide_grades_unpaid'))return $list;
        return array_values(array_filter($list,function($c)use($s){
            $sum=Db::val("SELECT COALESCE(SUM(GREATEST(total_amount-amount_paid,0)),0) FROM sis_registrations WHERE student_doc_id=? AND school_year=? AND status='REGISTERED'".(!empty($c['term'])?' AND term=?':''),array_merge([$s['id'],$c['schoolYear']??''],!empty($c['term'])?[$c['term']]:[]));
            return Money::cents($sum)<=0;
        }));
    }
    public static function promote(string $id,string $sectionId,string $reason,bool $graduate=false): void {
        Auth::require('registration');
        if(mb_strlen(trim($reason))<8)throw new AppError('Explain the promotion or graduation in at least 8 characters.');
        CurriculumAssignments::locked(Db::pdo(),fn()=>Db::tx(function()use($id,$sectionId,$reason,$graduate){
            Db::q("SELECT id FROM docs WHERE col='students' AND id=? FOR UPDATE",[$id]);$s=GS::get('students',$id);
            if(!$s)throw new AppError('Student not found.');
            if(($s['status']??'')==='graduated')throw new AppError('This student is already graduated.');
            $completed=GS::completions($id);$ok=false;
            foreach($completed as $c)if(($c['schoolYear']??'')===($s['schoolYear']??'') && ($c['term']??'')==='2nd Semester' && !empty($c['subjects']) && empty($c['incomplete']) && empty($c['pending'])){$ok=true;foreach($c['subjects']??[] as $subject)if(($subject['remarks']??'')!=='Passed')$ok=false;}
            if(!$ok)throw new AppError('Finalize all second-semester subjects as Passed before promotion or graduation.');
            StudentHistoryStore::capture(Db::pdo(),$id,$s);
            if($graduate){$s['status']='graduated';$s['statusNote']=$reason;}
            else {
                $to=GS::get('sections',$sectionId);if(!$to || ($to['schoolYear']??'')<=($s['schoolYear']??''))throw new AppError('Choose a section in a later school year.');
                $fromIndex=array_search($s['yearLevel']??'',YEAR_LEVELS,true);$toIndex=array_search($to['yearLevel']??'',YEAR_LEVELS,true);
                if($fromIndex===false||$toIndex!==$fromIndex+1)throw new AppError('Choose the next year level.');
                $s=array_merge($s,['schoolYear'=>$to['schoolYear'],'yearLevel'=>$to['yearLevel'],'sectionId'=>$to['id'],'sectionName'=>$to['sectionName'],'promotionState'=>'PROMOTED_PENDING_REGISTRATION','promotedFromSchoolYear'=>$s['schoolYear'],'promotedAt'=>GS::ts()]);
            }
            $s['updatedAt']=GS::ts();unset($s['id']);Db::q("UPDATE docs SET data=? WHERE col='students' AND id=?",[json_encode($s,JSON_THROW_ON_ERROR),$id]);
            StudentHistoryStore::capture(Db::pdo(),$id,$s);Audit::log($graduate?'student_graduated':'student_promoted','grading_student',$id,['reason'=>$reason,'to'=>$s]);
        }));
    }
}
