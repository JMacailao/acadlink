<?php
declare(strict_types=1);
/** Immutable snapshots shared by SIS and Grading; captured in the caller's transaction. */
final class StudentHistoryStore {
    public static function install(PDO $db): void {
        foreach(explode(';',(string)file_get_contents(__DIR__.'/database/student-history.sql')) as $sql)if(trim($sql)!=='')$db->exec($sql);
    }
    public static function capture(PDO $db,string $id,?array $data,string $term=''): void {
        if (!$data) return;
        $snapshot=['studentId'=>(string)($data['studentId']??''),'studentName'=>(string)($data['studentName']??''),
          'schoolYear'=>(string)($data['schoolYear']??''),'yearLevel'=>(string)($data['yearLevel']??''),
          'sectionId'=>(string)($data['sectionId']??''),'sectionName'=>(string)($data['sectionName']??''),
          'term'=>$term,'status'=>(string)($data['status']??'active')];
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $q=$db->prepare('INSERT IGNORE INTO sis_student_history(student_doc_id,fingerprint,snapshot) VALUES(?,?,?)');
        $q->execute([$id,hash('sha256',$id.'|'.$json),$json]);
    }
    public static function recordClass(PDO $db,string $id,?array $class): void {
        if(!$class || empty($class['teacherDocId']) || $class['teacherDocId']==='_acadlink_professor_x')return;
        $q=$db->prepare('INSERT IGNORE INTO sis_teacher_student_history(teacher_doc_id,student_doc_id,assignment_id,school_year,term) VALUES(?,?,?,?,?)');
        foreach((array)($class['studentIds']??[]) as $student)$q->execute([$class['teacherDocId'],(string)$student,$id,(string)($class['schoolYear']??''),(string)($class['term']??'')]);
    }
}
