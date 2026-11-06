<?php
declare(strict_types=1);
/** SIS-only orchestration. Passwords never enter jobs, audit data or exports. */
final class BulkAccounts
{
    public function __construct(private CentralAccounts $c, private int $actor) { $c->requirePower($actor); }
    public function install(): void {
        $sql=preg_replace('/^\s*--.*$/m','',(string)file_get_contents(dirname(SIS_ROOT).'/shared/database/bulk-accounts.sql'));
        $this->c->q(trim($sql));
    }
    private function used(string $username): bool {
        return (bool)($this->c->one('SELECT id FROM central_login_identifiers WHERE identifier_key=?',[$username])
          || $this->c->one('SELECT id FROM sis_users WHERE username=?',[$username])
          || $this->c->one("SELECT id FROM docs WHERE col='users' AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(data,'$.username')))=? LIMIT 1",[$username]));
    }
    public function preview(string $group,array $ids): array {
        $this->c->requirePower($this->actor);
        if (!in_array($group,['TEACHER','STUDENT'],true)) throw new CentralAccountError('Choose Teachers or Students.');
        if (!$this->c->one('SELECT id FROM central_groups WHERE code=? AND is_active=1',[$group])) throw new CentralAccountError('This group is disabled.');
        foreach($ids as $id) if (!is_string($id) || $id==='' || strlen($id)>128) throw new CentralAccountError('Invalid record selection.');
        $ids=array_values(array_unique($ids));
        if (!$ids || count($ids)>1000) throw new CentralAccountError('Select 1–1,000 records per batch.');
        $out=[]; $reserved=[]; $teacher=$group==='TEACHER'; $col=$teacher?'teachers':'students';
        foreach($ids as $id) {
            $r=['record_id'=>$id,'name'=>'','number'=>'','username'=>'','status'=>'Ready','message'=>''];
            try {
                if ($teacher && $id==='_acadlink_professor_x') throw new CentralAccountError('Professor X is a placeholder and cannot have a login account.');
                $row=$this->c->one('SELECT data FROM docs WHERE col=? AND id=?',[$col,$id]);
                if (!$row) throw new CentralAccountError('Record no longer exists.');
                $d=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);
                $r['name']=trim((string)($d[$teacher?'teacherName':'studentName']??''));
                $r['number']=trim((string)($d[$teacher?'teacherId':'studentId']??''));
                $section=!$teacher&&!empty($d['sectionId'])?$this->c->one("SELECT data FROM docs WHERE col='sections' AND id=?",[(string)$d['sectionId']]):null;
                $section=$section?json_decode($section['data'],true,512,JSON_THROW_ON_ERROR):[];
                $r['year_level']=$teacher?'':(trim((string)($d['yearLevel']??''))?:trim((string)($section['yearLevel']??'')));
                $r['section_name']=$teacher?'':(trim((string)($section['sectionName']??($d['sectionName']??'')))?:'Unassigned section');

                if ($this->c->one('SELECT account_id FROM central_person_links WHERE '.($teacher?'teacher_doc_id':'student_doc_id').'=?',[$id]) || (!$teacher && $r['number']!=='' && $this->c->one('SELECT account_id FROM central_person_links WHERE student_number=?',[$r['number']]))) {
                    $r['status']='Skipped'; $r['message']='Already has an account (including disabled accounts).';
                } else {
                    if ($r['name']==='' || mb_strlen($r['name'])>150 || $r['number']==='') throw new CentralAccountError('Add a valid name and record ID first.');
                    $link=$teacher?$this->c->teacherAccountLinkState($id):null;
                    $legacy=$teacher
                      ? in_array($link['state'],['central','legacy'],true)
                      : ($this->c->one("SELECT id FROM docs WHERE col='users' AND (JSON_UNQUOTE(JSON_EXTRACT(data,'$.studentId')) IN (?,?) OR JSON_UNQUOTE(JSON_EXTRACT(data,'$.studentDocId'))=? OR JSON_UNQUOTE(JSON_EXTRACT(data,'$.studentNumber'))=?) LIMIT 1",[$id,$r['number'],$id,$r['number']]) || $this->c->one('SELECT id FROM sis_users WHERE student_number=?',[$r['number']]));
                    if ($legacy || (!$teacher && !empty($d['userUid']))) { $r['status']='Needs review'; $r['message']=$teacher?$link['message']:'Verify the legacy account in Users and Groups.'; }
                    else {
                        if ($teacher && $link['state']==='stale') $r['message']=$link['message'];
                        if (!$teacher && (int)$this->c->q("SELECT COUNT(*) FROM docs WHERE col='students' AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.studentId'))=?",[$r['number']])->fetchColumn()!==1) throw new CentralAccountError('Student ID is duplicated. Correct the records first.');
                        $base=$teacher?CentralAccounts::teacherUsernameBase($r['name']):strtolower($r['number']);
                        if (!preg_match('/^[a-z0-9._-]{3,60}$/D',$base)) throw new CentralAccountError('Record ID cannot be used as a username. Create this account manually.');
                        $username=$base;
                        if ($teacher) { for($n=2;($this->used($username)||isset($reserved[$username])) && $n<=1001;$n++) $username=$base.$n; }
                        if ($this->used($username)||isset($reserved[$username])) { $r['status']='Needs review'; $r['message']='Username is already used. Review the existing identity.'; }
                        else { $r['username']=$username; $reserved[$username]=true; }
                    }
                }
            } catch(CentralAccountError $e) { $r['status']='Missing information'; $r['message']=$e->getMessage(); }
            $out[]=$r;
        }
        return $out;
    }
    /** A single account and both compatibility identities commit together. Retries skip linked people. */
    public function create(string $group,array $preview,string $password,int $days): array {
        $this->c->requirePower($this->actor);
        if ($p=CentralAccounts::passwordProblem($password)) throw new CentralAccountError($p);
        if ($days<1 || $days>30) throw new CentralAccountError('Choose an expiry of 1–30 days.');
        return $this->c->transaction(function()use($group,$preview,$password,$days) {
            $this->c->q('SELECT id FROM central_security_policy WHERE id=1 FOR UPDATE');
            $this->c->clearCache();
            $this->c->requirePower($this->actor);
            $this->c->q('SELECT id FROM docs WHERE col=? AND id=? FOR UPDATE',[$group==='TEACHER'?'teachers':'students',$preview['record_id']]);
            $fresh=$this->preview($group,[$preview['record_id']])[0];
            if ($fresh['status']!=='Ready') return $fresh;
            if ($fresh['name']!==$preview['name'] || $fresh['number']!==$preview['number'] || $fresh['username']!==$preview['username']) throw new CentralAccountError('Record or username changed. Preview a new batch.');
            $in=['group'=>$group,'full_name'=>$fresh['name'],'username'=>$fresh['username'],'status'=>'ACTIVE','temp_password'=>$password];
            $in[$group==='TEACHER'?'teacher_doc_id':'student_number']=$group==='TEACHER'?$fresh['record_id']:$fresh['number'];
            $id=$this->c->save($this->actor,$in);
            $this->c->q('INSERT INTO central_temporary_password_expiry(account_id,expires_at) VALUES(?,DATE_ADD(NOW(),INTERVAL ? DAY))',[$id,$days]);
            $this->c->audit($this->actor,$id,'bulk_account_created',['group'=>$group,'record_id'=>$fresh['record_id'],'temporary_days'=>$days]);
            return array_replace($fresh,['status'=>'Created','message'=>'Password change required at first sign-in.','account_id'=>$id]);
        });
    }
}
