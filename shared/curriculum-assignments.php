<?php
declare(strict_types=1);
require_once __DIR__.'/student-history.php';
/** Shared curriculum defaults and non-destructive class generation for SIS and Grading. */
final class CurriculumAssignments
{
    public const PLACEHOLDER='_acadlink_professor_x';
    /** v56: result of the last teacher synchronization: ['updated'=>n, 'kept'=>n (classes with submitted grades)]. */
    public array $teacherSync=['updated'=>0,'kept'=>0];
    public function __construct(private PDO $db) {}
    public static function locked(PDO $db,callable $fn): mixed {
        $key='acadlink_curriculum_'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,24);
        $s=$db->prepare('SELECT GET_LOCK(?,30)'); $s->execute([$key]);
        if ((string)$s->fetchColumn()!=='1') throw new RuntimeException('Curriculum is busy. Try again in a moment.');
        try { return $fn(); } finally { $s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$key]); }
    }
    private function rows(string $col): array {
        $s=$this->db->prepare('SELECT id,data FROM docs WHERE col=?');$s->execute([$col]);$out=[];
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['id']]=json_decode($r['data'],true,512,JSON_THROW_ON_ERROR);
        return $out;
    }
    private function get(string $col,string $id): ?array {
        $s=$this->db->prepare('SELECT data FROM docs WHERE col=? AND id=?');$s->execute([$col,$id]);$v=$s->fetchColumn();return $v===false?null:json_decode($v,true,512,JSON_THROW_ON_ERROR);
    }
    private function put(string $col,string $id,array $data): void {
        $s=$this->db->prepare('INSERT INTO docs(col,id,data) VALUES(?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)');$s->execute([$col,$id,json_encode($data,JSON_THROW_ON_ERROR)]);
    }
    public function defaultTeacher(): string {
        $s=$this->db->prepare("INSERT IGNORE INTO docs(col,id,data) VALUES('teachers',?,?)");
        $s->execute([self::PLACEHOLDER,json_encode(['teacherId'=>'PROF-X','teacherName'=>'Professor X','isPlaceholder'=>true])]);
        $d=$this->get('teachers',self::PLACEHOLDER);
        if (($d['isPlaceholder']??false)!==true || ($d['teacherName']??'')!=='Professor X') throw new RuntimeException('The reserved Professor X record conflicts with an existing record.');
        return self::PLACEHOLDER;
    }
    public function normalize(array $data,?array $before=null): array {
        $default=$this->defaultTeacher(); $subjects=$this->rows('subjects'); $teachers=$this->rows('teachers');
        $ids=$data['subjectIds']??[];
        if (!is_array($ids)) throw new RuntimeException('Select valid curriculum subjects.');
        $map=$data['subjectTeachers']??($before['subjectTeachers']??[]);
        if (!is_array($map)) throw new RuntimeException('Choose a teacher for each curriculum subject.');
        $clean=[];$unique=[];
        foreach($ids as $sid) {
            if (!is_string($sid) || !isset($subjects[$sid])) throw new RuntimeException('A selected curriculum subject no longer exists.');
            $tid=$map[$sid]??$default;
            if ($tid==='') $tid=$default;
            if (!is_string($tid) || !isset($teachers[$tid])) throw new RuntimeException('A selected teacher no longer exists. Choose another teacher or Professor X.');
            $unique[$sid]=true;$clean[$sid]=$tid;
        }
        $data['subjectIds']=array_values(array_unique($ids));$data['subjectTeachers']=$clean;
        return $data;
    }
    public function syncCurriculum(string $id,?array $before=null): int {
        $c=$this->get('curriculum',$id); if (!$c) return 0;
        // v56: a new curriculum needs a semester; older ones saved without a semester stay readable.
        if ($before===null && (string)($c['term']??'')==='') throw new RuntimeException('Choose the semester: 1st Semester, 2nd Semester, 3rd Semester or Summer.');
        $c=$this->normalize($c,$before);$this->put('curriculum',$id,$c);$n=0;
        $this->teacherSync=['updated'=>0,'kept'=>0];
        foreach($this->rows('sections') as $sid=>$section) if ($this->matches($c,$section)) {
            $this->syncTeachers($c,$before,$sid,$section);
            $n+=$this->createMissing($id,$c,$sid,$section);
        }
        return $n;
    }
    public function syncSection(string $id): int {
        $section=$this->get('sections',$id); if (!$section) return 0;$n=0;
        foreach($this->rows('curriculum') as $cid=>$c) if ($this->matches($c,$section)) {
            $c=$this->normalize($c);$this->put('curriculum',$cid,$c);$n+=$this->createMissing($cid,$c,$id,$section);
        }
        return $n;
    }
    private function matches(array $c,array $s): bool {
        preg_match('/^([^\d]*)\d/u',(string)($s['sectionName']??''),$m);
        $program=mb_strtoupper(trim(preg_replace('/[\s\-–_.]+$/u','',$m[1]??'')));
        return $program!=='' && $program===mb_strtoupper(trim((string)($c['program']??''))) && ($s['yearLevel']??'')===($c['yearLevel']??'') && !empty($s['schoolYear']);
    }
    private function createMissing(string $cid,array $c,string $sectionId,array $section): int {
        $all=$this->rows('gradingAssignments');$subjects=$this->rows('subjects');$teachers=$this->rows('teachers');$n=0;
        // v56: no new whole-school-year classes; an older curriculum without a semester creates none.
        if ((string)($c['term']??'')==='') return 0;
        foreach($c['subjectIds'] as $sid) {
            $term=(string)($c['term']??'');$exists=false;
            foreach($all as $a) if (($a['sectionId']??'')===$sectionId && ($a['schoolYear']??'')===$section['schoolYear'] && ($a['term']??'')===$term && ($a['subjectId']??'')===$sid) {$exists=true;break;}
            if ($exists) continue; // A later curriculum save never resets teacher, roster or grades.
            $tid=$c['subjectTeachers'][$sid];$t=$teachers[$tid];$sub=$subjects[$sid];
            $id='cur_'.substr(hash('sha256',json_encode([$sectionId,$section['schoolYear'],$term,$sid],JSON_THROW_ON_ERROR)),0,48);
            $students=[];
            $has=$this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sis_registration_subjects'")->fetchColumn();
            if ($has) {
                $s=$this->db->prepare("SELECT DISTINCT r.student_doc_id FROM sis_registrations r JOIN sis_registration_subjects s ON s.registration_id=r.id WHERE r.section_doc_id=? AND r.school_year=? AND r.term=? AND r.status='REGISTERED' AND r.locked_at IS NOT NULL AND s.subject_doc_id=?");
                $s->execute([$sectionId,$section['schoolYear'],$term,$sid]);
                foreach($s->fetchAll(PDO::FETCH_COLUMN) as $student) {
                    $taken=false;
                    foreach($all as $a) if (($a['schoolYear']??'')===$section['schoolYear'] && ($a['term']??'')===$term && ($a['subjectId']??'')===$sid && in_array($student,$a['studentIds']??[],true)) {$taken=true;break;}
                    if (!$taken) $students[]=$student;
                }
            }
            $stamp=['__ts'=>gmdate('Y-m-d\TH:i:s.000\Z')];
            $a=['teacherDocId'=>$tid,'teacherId'=>$t['teacherId']??'','teacherName'=>$t['teacherName'],'subjectId'=>$sid,'subjectCode'=>$sub['subjectCode']??'','subjectName'=>$sub['subjectName']??'','units'=>(float)($sub['units']??0),'schoolYear'=>$section['schoolYear'],'term'=>$term,'yearLevel'=>$section['yearLevel'],'sectionId'=>$sectionId,'sectionName'=>$section['sectionName'],'studentIds'=>$students,'curriculumId'=>$cid,'autoCreated'=>true,'createdAt'=>$stamp,'updatedAt'=>$stamp];
            $this->put('gradingAssignments',$id,$a);$all[$id]=$a;$n++;
        }
        return $n;
    }
    /**
     * v56: a teacher changed beside a curriculum subject also changes the ACTIVE class of that subject:
     * the section's current school year, the curriculum's semester, and only while the class still has the
     * previous curriculum teacher (a teacher chosen in Grading Assignments stays). Classes whose grades were
     * already submitted keep their teacher (counted as 'kept'; change them in Grading Assignments).
     * Grade values and grading history are never changed: draft grades only move to the new teacher.
     */
    private function syncTeachers(array $c,?array $before,string $sectionId,array $section): void {
        if (!$before || (string)($c['term']??'')==='') return;
        $oldIds=array_flip(array_values(array_filter((array)($before['subjectIds']??[]),'is_string')));
        $changed=[];
        foreach($c['subjectTeachers'] as $sid=>$tid) {
            if (!isset($oldIds[$sid])) continue; // a newly added subject: createMissing() gives it the teacher
            $old=(string)(($before['subjectTeachers']??[])[$sid]??self::PLACEHOLDER);
            if ($old==='') $old=self::PLACEHOLDER;
            if ($old!==$tid) $changed[$sid]=[$old,$tid];
        }
        if (!$changed) return;
        $teachers=$this->rows('teachers');
        foreach($this->rows('gradingAssignments') as $aid=>$a) {
            $sid=(string)($a['subjectId']??'');
            if (!isset($changed[$sid]) || ($a['sectionId']??'')!==$sectionId || ($a['schoolYear']??'')!==($section['schoolYear']??null) || (string)($a['term']??'')!==(string)$c['term']) continue;
            [$old,$new]=$changed[$sid];
            if ((string)($a['teacherDocId']??'')!==$old) continue;
            $g=$this->db->prepare("SELECT data FROM docs WHERE col='grades' AND f_assignmentId=?");$g->execute([(string)$aid]);
            $submitted=false;
            foreach($g->fetchAll(PDO::FETCH_COLUMN) as $raw){$d=json_decode((string)$raw,true);if(($d['draft']??true)===false){$submitted=true;break;}}
            if ($submitted) {$this->teacherSync['kept']++;continue;}
            $t=$teachers[$new]??null; if (!$t) continue;
            $after=array_replace($a,['teacherDocId'=>$new,'teacherId'=>$t['teacherId']??'','teacherName'=>$t['teacherName']??'','updatedAt'=>['__ts'=>gmdate('Y-m-d\TH:i:s.000\Z')]]);
            $this->put('gradingAssignments',(string)$aid,$after);
            $this->reassignGrades((string)$aid,$after,$a);
            $this->teacherSync['updated']++;
        }
    }
    /** Teacher reassignment updates ownership copies, never grade values or other assignments. */
    public function reassignGrades(string $assignmentId,array $assignment,?array $before): void {
        StudentHistoryStore::recordClass($this->db,$assignmentId,$before);
        StudentHistoryStore::recordClass($this->db,$assignmentId,$assignment);
        if (!$before || ($before['teacherDocId']??null)===($assignment['teacherDocId']??null)) return;
        $teacher=$this->get('teachers',(string)($assignment['teacherDocId']??''));
        if (!$teacher) throw new RuntimeException('Choose an existing teacher.');
        $ownership=['teacherDocId'=>$assignment['teacherDocId'],'teacherId'=>$teacher['teacherId']??'','teacherName'=>$teacher['teacherName']??''];
        $assignment=array_replace($assignment,$ownership);
        $this->put('gradingAssignments',$assignmentId,$assignment);
        $s=$this->db->prepare("SELECT id,data FROM docs WHERE col='grades' AND f_assignmentId=? FOR UPDATE");$s->execute([$assignmentId]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d=json_decode($r['data'],true,512,JSON_THROW_ON_ERROR);
            foreach($ownership as $k=>$value) $d[$k]=$value;
            $this->put('grades',$r['id'],$d);
        }
    }
}
