<?php
/** Authoritative identity service used by SIS and Grading. No legacy login fallback. */
declare(strict_types=1);
require_once __DIR__.'/module-access.php';
class CentralAccountError extends RuntimeException {}
final class CentralAccounts
{
    public const COOKIE = 'ACADLINK_AUTH';
    private array $accountCache=[], $groupCache=[], $permissionCache=[];
    private ?array $currentCache=null;
    private bool $currentChecked=false;
    private string $checkedToken='';
    public function __construct(private PDO $db, private bool $forceSecureCookie=false) {}
    public function clearCache(): void {
        $this->accountCache=$this->groupCache=$this->permissionCache=[];
        $this->currentChecked=false;
    }
    public function q(string $sql, array $args = []): PDOStatement {
        // Cache only within this PHP request. Any service mutation clears all identity data.
        if (!preg_match('/^\s*(SELECT|SHOW)\b/i',$sql)) {
            $this->clearCache();
        }
        $s=$this->db->prepare($sql); $s->execute($args); return $s;
    }
    public function one(string $sql, array $args = []): ?array { return $this->q($sql, $args)->fetch(PDO::FETCH_ASSOC) ?: null; }
    public function installed(): bool {
        return (bool)$this->q("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='central_sessions'")->fetchColumn();
    }
    public function install(): void {
        $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents(__DIR__.'/database/central-accounts.sql'));
        foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $statement) $this->db->exec($statement);
        $type = (string)$this->q("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_users' AND column_name='role'")->fetchColumn();
        if (!$this->q("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_admissions' AND column_name='application_term'")->fetchColumn()) $this->db->exec('ALTER TABLE sis_admissions ADD COLUMN application_term VARCHAR(20) NULL');
        if ($type && !str_contains($type, "'TEACHER'")) $this->db->exec("ALTER TABLE sis_users MODIFY role ENUM('ADMIN','REGISTRAR','ADMISSION','CASHIER','ACCOUNTING','TEACHER','STUDENT') NOT NULL");
        $this->installModuleAccess();
    }
    public function installModuleAccess(): void { ModuleAccess::install($this->db); }
    public function temporaryExpired(int $id): bool {
        if (!$this->q("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='central_temporary_password_expiry'")->fetchColumn()) return false;
        return (bool)$this->q('SELECT 1 FROM central_temporary_password_expiry e JOIN central_accounts a ON a.id=e.account_id WHERE e.account_id=? AND a.must_change_password=1 AND e.expires_at<=NOW()',[$id])->fetchColumn();
    }
    public static function passwordProblem(string $pw): ?string {
        return mb_strlen($pw,'UTF-8')<8 || strlen($pw)>72 || !preg_match('/[A-Za-z]/',$pw) || !preg_match('/\d/',$pw)
            ? 'Use 8–72 characters, including letters and numbers.' : null;
    }
    public function account(int $id): ?array {
        if (array_key_exists($id,$this->accountCache)) return $this->accountCache[$id];
        return $this->accountCache[$id]=$this->one('SELECT a.*,i.identifier AS username,p.teacher_doc_id,p.student_doc_id,p.student_number,p.verified_at AS person_verified FROM central_accounts a LEFT JOIN central_login_identifiers i ON i.account_id=a.id AND i.is_primary=1 LEFT JOIN central_person_links p ON p.account_id=a.id WHERE a.id=?',[$id]);
    }
    public function groups(int $id): array {
        if (isset($this->groupCache[$id])) return $this->groupCache[$id];
        return $this->groupCache[$id]=$this->q('SELECT g.* FROM central_groups g JOIN central_user_groups u ON u.group_id=g.id WHERE u.account_id=? AND u.revoked_at IS NULL AND u.valid_from<=NOW() AND (u.expires_at IS NULL OR u.expires_at>NOW()) ORDER BY g.id',[$id])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function group(int $id): string { return $this->groups($id)[0]['code'] ?? ''; }
    public function permissions(int $id): array {
        if (isset($this->permissionCache[$id])) return $this->permissionCache[$id];
        $a=$this->account($id);
        if (!$a || $a['status']!=='ACTIVE') return [];
        if ($this->group($id)==='TEACHER' && (!$a['person_verified'] || !$a['teacher_doc_id'])) return [];
        $rows=$this->q("SELECT p.code,gp.access_scope FROM central_user_groups ug JOIN central_groups g ON g.id=ug.group_id AND g.is_active=1 JOIN central_group_permissions gp ON gp.group_id=g.id AND gp.is_enabled=1 JOIN central_permissions p ON p.code=gp.permission_code WHERE ug.account_id=? AND ug.revoked_at IS NULL AND ug.valid_from<=NOW() AND (ug.expires_at IS NULL OR ug.expires_at>NOW()) AND (p.workspace='LMS' OR (?='STUDENT' AND p.workspace='PORTAL') OR (?='STAFF' AND p.workspace<>'PORTAL') OR p.code IN ('portal.workspace.view','portal.parent.view') AND g.code='PARENT')",[$id,$a['account_kind'],$a['account_kind']])->fetchAll(PDO::FETCH_ASSOC);
        $out=[];
        foreach ($rows as $r) $out[$r['code']]=$r['access_scope'];
        // Per-person module changes on top of the group (never for a Power User, who always keeps full access)
        $group=$this->group($id);
        $over=$group==='POWER_USER'?[]:$this->moduleOverrides($id);
        $out=ModuleAccess::apply($out,$over,$group);
        $student=$a['account_kind']==='STUDENT';
        foreach (array_keys($out) as $code) if (!str_starts_with($code,'lms.') && str_starts_with($code,'portal.')!==$student && !($group==='PARENT' && in_array($code,['portal.workspace.view','portal.parent.view'],true))) unset($out[$code]); // the LMS is for staff and students; v62: parents open the Portal
        if ($group==='PARENT') $out=array_intersect_key($out,array_flip(['portal.workspace.view','portal.parent.view'])); // v62: parents only see their children, read-only
        return $this->permissionCache[$id]=$out;
    }
    /** This person's module overrides (rows of central_user_module_access). */
    public function moduleOverrides(int $id): array {
        try { return $this->q('SELECT module_key,level,extras FROM central_user_module_access WHERE account_id=?',[$id])->fetchAll(PDO::FETCH_ASSOC); }
        catch (PDOException $e) { if ($e->getCode()==='42S02') return []; throw $e; }
    }
    public function can(int $id,string $permission): bool { return isset($this->permissions($id)[$permission]); }
    public function legacyId(int $id,string $system): ?string {
        $v=$this->q('SELECT source_key FROM central_legacy_links WHERE account_id=? AND source_system=?',[$id,$system])->fetchColumn();
        return $v===false ? null : (string)$v;
    }
    private function token(): string { return (string)($_COOKIE[self::COOKIE] ?? $_SESSION['central_token'] ?? ''); }
    private function cookie(string $token): void {
        $_SESSION['central_token']=$token;
        $_COOKIE[self::COOKIE]=$token;
        if (PHP_SAPI!=='cli') setcookie(self::COOKIE,$token,['expires'=>0,'path'=>'/','secure'=>($this->forceSecureCookie || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || ($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https'),'httponly'=>true,'samesite'=>'Lax']);
    }
    public function current(): ?array {
        $token=$this->token();
        if ($this->currentChecked && $this->checkedToken===$token) return $this->currentCache;
        $this->checkedToken=$token; $this->currentChecked=true; $this->currentCache=null;
        if (!preg_match('/^[a-f0-9]{64}$/D',$token) || !$this->installed()) return null;
        $s=$this->one("SELECT s.account_id FROM central_sessions s JOIN central_accounts a ON a.id=s.account_id JOIN central_security_policy p ON p.id=1 WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>NOW() AND s.last_seen_at>NOW()-INTERVAL 30 MINUTE AND a.status='ACTIVE' AND s.account_auth_version=a.auth_version AND s.policy_version=p.policy_version",[hash('sha256',$token)]);
        if (!$s || $this->temporaryExpired((int)$s['account_id'])) return null;
        $this->q('UPDATE central_sessions SET last_seen_at=NOW() WHERE token_hash=?',[hash('sha256',$token)]);
        $this->currentChecked=true;
        return $this->currentCache=$this->account((int)$s['account_id']);
    }
    public function establish(int $id): array {
        $a=$this->account($id);
        if (!$a || $a['status']!=='ACTIVE') throw new CentralAccountError('This account is disabled. Contact the Power User.');
        $token=bin2hex(random_bytes(32));
        $this->q('INSERT INTO central_sessions(account_id,token_hash,account_auth_version,policy_version,expires_at) SELECT ?,?,?,policy_version,NOW()+INTERVAL 8 HOUR FROM central_security_policy WHERE id=1',[$id,hash('sha256',$token),$a['auth_version']]);
        // Ended sign-ins are kept a week (for the audit trail), then removed a few hundred at a time
        $this->q('DELETE FROM central_sessions WHERE (expires_at < NOW() - INTERVAL 7 DAY OR revoked_at < NOW() - INTERVAL 7 DAY) LIMIT 500');
        if (PHP_SAPI!=='cli' && session_status()===PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $this->cookie($token);
        $_SESSION['csrf']=bin2hex(random_bytes(32));
        return $a;
    }
    public function login(string $login,string $password,string $workspace): array {
        if (!$this->installed()) throw new CentralAccountError('Central accounts are not installed. Ask the administrator to follow CENTRAL_ACCOUNTS.md.');
        $login=strtolower(trim($login));
        $this->q('DELETE FROM login_attempts WHERE at<NOW()-INTERVAL 1 DAY');
        $ip=substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'),0,64);
        $n=$this->one('SELECT (SELECT COUNT(*) FROM login_attempts WHERE ip=? AND at>NOW()-INTERVAL 15 MINUTE) AS ip_count,(SELECT COUNT(*) FROM login_attempts WHERE email=? AND at>NOW()-INTERVAL 15 MINUTE) AS user_count',[$ip,$login]);
        if ($n['ip_count']>=100 || $n['user_count']>=10) throw new CentralAccountError('Too many attempts. Try again in 15 minutes.');
        $r=$this->one('SELECT a.*,c.password_hash,(a.locked_until>NOW()) AS is_locked FROM central_login_identifiers i JOIN central_accounts a ON a.id=i.account_id JOIN central_credentials c ON c.account_id=a.id WHERE i.identifier_key=? AND i.verified_at IS NOT NULL',[$login]);
        $ok=password_verify($password,$r['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuJ6E9Bl2l8ImYw3BGSQ9xOJbm8yIt1lS');
        // Forgot password (v51): an emailed temporary password works once; it then becomes the password to change
        $usedTemporary=false;
        if ($r && !$ok && $r['status']==='ACTIVE' && empty($r['is_locked']) && $this->useTemporary((int)$r['id'],$password)) { $ok=true; $usedTemporary=true; }
        if (!$r || !$ok || $r['status']!=='ACTIVE' || !empty($r['is_locked'])) {
            $this->q('INSERT INTO login_attempts(ip,email) VALUES(?,?)',[$ip,mb_substr($login,0,191)]);
            if ($r && !$ok) $this->q('UPDATE central_accounts SET locked_until=IF(failed_logins>=4,NOW()+INTERVAL 15 MINUTE,locked_until),failed_logins=failed_logins+1 WHERE id=?',[$r['id']]);
            throw new CentralAccountError('Wrong username or password, or the account is unavailable.');
        }
        if ($this->temporaryExpired((int)$r['id'])) throw new CentralAccountError('Your temporary password has expired. Ask the Power User to reset it in SIS.');
        if ($workspace==='GRADING' && !$this->can((int)$r['id'],'grading.workspace.view')) throw new CentralAccountError('Use SIS or the Student Portal for this account.');
        $this->logout();
        $this->q('UPDATE central_accounts SET failed_logins=0,locked_until=NULL,last_login_at=NOW() WHERE id=?',[$r['id']]);
        $this->q('DELETE FROM login_attempts WHERE email=?',[$login]);
        if (!$usedTemporary && password_needs_rehash($r['password_hash'],PASSWORD_DEFAULT)) $this->q('UPDATE central_credentials SET password_hash=? WHERE account_id=?',[password_hash($password,PASSWORD_DEFAULT),$r['id']]);
        $this->audit((int)$r['id'],(int)$r['id'],'login',['workspace'=>$workspace]);
        return $this->establish((int)$r['id']);
    }
    /** An unused, unexpired emailed temporary password: makes it the account's password (must be changed now). */
    private function useTemporary(int $id,string $password): bool {
        if (!$this->q("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='central_password_resets'")->fetchColumn()) return false;
        foreach ($this->q('SELECT id,temp_hash FROM central_password_resets WHERE account_id=? AND sent=1 AND used_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 5',[$id])->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if (!password_verify($password,(string)$t['temp_hash'])) continue;
            $this->transaction(function() use($id,$t) {
                $this->q('UPDATE central_password_resets SET used_at=NOW() WHERE account_id=? AND used_at IS NULL',[$id]);
                $this->q('UPDATE central_credentials SET password_hash=?,password_changed_at=NOW() WHERE account_id=?',[$t['temp_hash'],$id]);
                $this->q('UPDATE central_accounts SET must_change_password=1,auth_version=auth_version+1 WHERE id=?',[$id]);
                $this->audit($id,$id,'password_reset_used',[]);
            });
            $this->clearCache();
            return true;
        }
        return false;
    }
    public function logout(): void {
        if ($this->installed() && $this->token()!=='') $this->q('UPDATE central_sessions SET revoked_at=NOW() WHERE token_hash=?',[hash('sha256',$this->token())]);
        $this->cookie('');
    }
    public function verifyPassword(int $id,string $password): bool {
        return !$this->temporaryExpired($id) && password_verify($password,(string)$this->q('SELECT password_hash FROM central_credentials WHERE account_id=?',[$id])->fetchColumn());
    }
    public function changePassword(int $id,string $current,string $new): void {
        if (!$this->verifyPassword($id,$current)) throw new CentralAccountError('Your current password is wrong.');
        if ($this->verifyPassword($id,$new)) throw new CentralAccountError('Choose a different password.');
        $this->setPassword($id,$new,false,$id);
        $this->establish($id);
    }
    public function setPassword(int $id,string $password,bool $temporary,?int $actor): void {
        if ($actor!==$id || $temporary) $this->requirePower($actor);
        if ($p=self::passwordProblem($password)) throw new CentralAccountError($p);
        $this->transaction(function() use($id,$password,$temporary,$actor) {
            if (!$this->account($id)) throw new CentralAccountError('Account not found.');
            $this->q('INSERT INTO central_credentials(account_id,password_hash) VALUES(?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),password_changed_at=NOW()',[$id,password_hash($password,PASSWORD_DEFAULT)]);
            $this->q('UPDATE central_accounts SET must_change_password=?,auth_version=auth_version+1,failed_logins=0,locked_until=NULL WHERE id=?',[(int)$temporary,$id]);
            if ($this->q("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='central_temporary_password_expiry'")->fetchColumn()) $this->q('DELETE FROM central_temporary_password_expiry WHERE account_id=?',[$id]);
            // A new password cancels emailed temporary passwords that were not used
            if ($this->q("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='central_password_resets'")->fetchColumn()) $this->q('UPDATE central_password_resets SET used_at=NOW() WHERE account_id=? AND used_at IS NULL',[$id]);
            $this->audit($actor,$id,$temporary?'password_reset':'password_changed',[]);
        });
    }
    public function requirePower(?int $id): void {
        if (!$id || $this->group($id)!=='POWER_USER' || !$this->can($id,'admin.accounts.manage')) throw new CentralAccountError('Only the Power User can manage accounts.');
    }
    public function transaction(callable $f): mixed {
        $outer=$this->db->inTransaction();
        if (!$outer) $this->db->beginTransaction();
        try { $r=$f(); if (!$outer) $this->db->commit(); return $r; }
        catch (Throwable $e) { if (!$outer && $this->db->inTransaction()) $this->db->rollBack(); $this->clearCache(); throw $e; }
    }
    public function audit(?int $actor,?int $target,string $action,array $details): void {
        $this->q('INSERT INTO central_account_audit(actor_account_id,target_account_id,action,details) VALUES(?,?,?,?)',[$actor,$target,$action,json_encode($details,JSON_THROW_ON_ERROR)]);
    }
    private function person(?string $teacher,?string $student): array {
        $td=null; $sd=null; $sn=null;
        if ($teacher) {
            if ($teacher==='_acadlink_professor_x') throw new CentralAccountError('Professor X is a placeholder and cannot have a login account.');
            $td=$this->q("SELECT data FROM docs WHERE col='teachers' AND id=?",[$teacher])->fetchColumn();
            if (!$td) throw new CentralAccountError('Choose an existing teacher record.');
        }
        if ($student) {
            $rows=$this->q("SELECT id,data FROM docs WHERE col='students' AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.studentId'))=?",[$student])->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows)!==1) throw new CentralAccountError('The Student ID must identify exactly one student record.');
            $sd=$rows[0]['id']; $sn=json_decode($rows[0]['data'],true)['studentId'];
        }
        return [$teacher?:null,$sd,$sn];
    }
    /** Called only from SIS administration (or the explicit, local bootstrap command). */
    private bool $parentMode=false;
    /** v62: Registrar staff (students manage) can create and update Parent / Guardian accounts only. */
    public function saveParent(int $actor,array $in): int {
        if ($this->group($actor)!=='POWER_USER' && !$this->can($actor,'sis.students.manage')) throw new CentralAccountError('Only the Registrar can create parent accounts.');
        $id=(int)($in['id']??0);
        if ($id && $this->group($id)!=='PARENT') throw new CentralAccountError('This account is not a parent account.');
        $this->parentMode=true;
        try { return $this->save($actor,['group'=>'PARENT','teacher_doc_id'=>'','student_number'=>'','source_sis'=>'','source_grading'=>'']+$in); } finally { $this->parentMode=false; }
    }
    public function save(?int $actor,array $in,bool $bootstrap=false): int {
        if (!$bootstrap) { if (!($this->parentMode && ($in['group']??'')==='PARENT')) $this->requirePower($actor); }
        elseif (PHP_SAPI!=='cli' || $this->q('SELECT COUNT(*) FROM central_accounts')->fetchColumn()) throw new CentralAccountError('Bootstrap is local and first-account only.');
        return $this->transaction(function() use($actor,$in,$bootstrap) {
            // Serialize account/group management and protect the last active Power User.
            $this->q('SELECT id FROM central_security_policy WHERE id=1 FOR UPDATE');
            $id=(int)($in['id']??0);
            $old=$id?$this->account($id):null;
            if ($id && !$old) throw new CentralAccountError('Account not found.');
            $group=(string)($in['group']??'');
            // The five built-in groups, or a group added in Users and Groups (v55, e.g. Library)
            if ($group==='' || !$this->q('SELECT 1 FROM central_groups WHERE code=?',[$group])->fetchColumn()) throw new CentralAccountError('Choose a default group.');
            $name=trim((string)($in['full_name']??'')); $username=strtolower(trim((string)($in['username']??'')));
            if ($name==='' || mb_strlen($name)>150) throw new CentralAccountError('Enter a full name, up to 150 characters.');
            if (!preg_match('/^[a-z0-9._-]{3,64}$/D',$username)) throw new CentralAccountError('Username: use 3–64 letters, numbers, dots, dashes or underscores.');
            $status=($in['status']??'')==='ACTIVE'?'ACTIVE':'DISABLED';
            if ($actor===$id && ($group!=='POWER_USER' || $status!=='ACTIVE')) throw new CentralAccountError('You cannot remove your own Power User access.');
            if ($old && $this->group($id)==='POWER_USER' && ($group!=='POWER_USER' || $status!=='ACTIVE')) {
                $n=$this->q("SELECT COUNT(*) FROM central_accounts a JOIN central_user_groups ug ON ug.account_id=a.id AND ug.revoked_at IS NULL JOIN central_groups g ON g.id=ug.group_id AND g.code='POWER_USER' WHERE a.status='ACTIVE' AND a.id<>?",[$id])->fetchColumn();
                if (!$n) throw new CentralAccountError('Keep at least one active Power User.');
            }
            // v63: power.admin is the account that backs up and restores: its username stays, and it stays an active Power User (its password can change)
            if ($old && strtolower((string)$old['username'])==='power.admin') {
                if ($username!=='power.admin') throw new CentralAccountError('The power.admin username can\'t be changed. You can change its password.');
                if ($group!=='POWER_USER' || $status!=='ACTIVE') throw new CentralAccountError('power.admin must stay an active Power User: it is the account that backs up and restores AcadLink.');
            }
            if (!$old && $username==='power.admin' && $this->q("SELECT 1 FROM central_login_identifiers WHERE identifier_key='power.admin'")->fetchColumn()) throw new CentralAccountError('This username is already used.');
            $teacher=trim((string)($in['teacher_doc_id']??'')); $student=trim((string)($in['student_number']??''));
            if ($group!=='TEACHER' && $teacher!=='') throw new CentralAccountError('Teacher links belong to Teacher accounts.');
            if ($group!=='STUDENT' && $student!=='') throw new CentralAccountError('Student links belong to Student accounts.');
            if ($group==='TEACHER' && !$teacher) throw new CentralAccountError('Select a teacher record, or create one with this account.');
            [$teacher,$sd,$sn]=$this->person($teacher,$student);
            $teacherLink=null;
            if (!$old && $teacher) {
                $this->q("SELECT id FROM docs WHERE col='teachers' AND id=? FOR UPDATE",[$teacher]);
                $teacherLink=$this->teacherAccountLinkState($teacher);
                if (in_array($teacherLink['state'],['central','legacy'],true) && trim((string)($in['source_grading']??''))==='') throw new CentralAccountError($teacherLink['message']);
            }
            $hash=null;
            if (!$old) {
                $pw=(string)($in['temp_password']??'');
                if ($p=self::passwordProblem($pw)) throw new CentralAccountError($p);
                $hash=password_hash($pw,PASSWORD_DEFAULT);
                $this->q('INSERT INTO central_accounts(display_name,account_kind,status,must_change_password) VALUES(?,?,?,1)',[$name,$group==='STUDENT'?'STUDENT':'STAFF',$status]);
                $id=(int)$this->db->lastInsertId();
                $this->q('INSERT INTO central_credentials(account_id,password_hash) VALUES(?,?)',[$id,$hash]);
                $this->q("INSERT INTO central_login_identifiers(account_id,identifier,identifier_kind,is_primary,verified_at) VALUES(?,?,'USERNAME',1,NOW())",[$id,$username]);
            } else {
                $this->q('UPDATE central_accounts SET display_name=?,account_kind=?,status=?,auth_version=auth_version+1 WHERE id=?',[$name,$group==='STUDENT'?'STUDENT':'STAFF',$status,$id]);
                $this->q('UPDATE central_login_identifiers SET identifier=?,verified_at=NOW() WHERE account_id=? AND is_primary=1',[$username,$id]);
            }
            $this->q('INSERT INTO central_person_links(account_id,teacher_doc_id,student_doc_id,student_number,verified_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE teacher_doc_id=VALUES(teacher_doc_id),student_doc_id=VALUES(student_doc_id),student_number=VALUES(student_number),verified_at=NOW()',[$id,$teacher,$sd,$sn]);
            // Reject unique-person collisions rather than updating another account through ON DUPLICATE KEY.
            $p=$this->one('SELECT * FROM central_person_links WHERE account_id=?',[$id]);
            if (!$p || $p['teacher_doc_id']!==$teacher || $p['student_number']!==$sn) throw new CentralAccountError('This teacher or student is already linked to another account.');
            $this->q('UPDATE central_user_groups SET revoked_at=NOW() WHERE account_id=?',[$id]);
            $gid=$this->q('SELECT id FROM central_groups WHERE code=? AND is_active=1',[$group])->fetchColumn();
            if (!$gid) throw new CentralAccountError('This group is disabled.');
            $this->q('INSERT INTO central_user_groups(account_id,group_id,assigned_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE revoked_at=NULL,valid_from=NOW(),expires_at=NULL,assigned_by=VALUES(assigned_by)',[$id,$gid,$actor]);
            foreach (['SIS'=>'source_sis','GRADING'=>'source_grading'] as $system=>$key) {
                $source=trim((string)($in[$key]??''));
                if ($source!=='') {
                    if (trim((string)($in['verification_note']??''))==='') throw new CentralAccountError('Record how you verified the legacy account belongs to this person.');
                    $this->attachLegacy($id,$system,$source,$actor,(string)$in['verification_note']);
                }
            }
            $this->syncProjections($id);
            if ($teacher && ($teacherLink['state']??'')==='stale') {
                $uid=$this->legacyId($id,'GRADING');
                if (!$uid) throw new CentralAccountError('The new Grading account link was not saved. No account was created.');
                $this->q("UPDATE docs SET data=JSON_SET(data,'$.userUid',?) WHERE col='teachers' AND id=?",[$uid,$teacher]);
                $this->audit($actor,$id,'stale_teacher_reference_repaired',['teacher_doc_id'=>$teacher,'old_grading_uid'=>$teacherLink['uid'],'new_grading_uid'=>$uid]);
            }
            $this->audit($actor,$id,$old?'account_updated':'account_created',['group'=>$group,'status'=>$status,'teacher'=>$teacher,'student'=>$sn]);
            return $id;
        });
    }
    /** First-name-first directory names: ignore middle names and trailing suffixes/credentials. */
    public static function teacherUsernameBase(string $name): string {
        $name=trim(preg_replace('/\s+/u',' ',$name));
        $suffixes=array_fill_keys(['jr','sr','ii','iii','iv','v','vi','rrt','rn','lpt','cpa','md','dmd','dds','rph','rmt','rpt','ptrp','rcrim','ree','rme','ece','rce','rl','rpm','rpsy','phd','edd','dba','dpa','dsc','ma','ms','msc','mba','mpa','maed','med','msed','msn','man','mph','llb','llm','jd','bsn'],true);
        // Longest match also accepts dotted/spaced credentials such as "Ph. D.".
        $stripSuffixes=static function(array &$tokens) use($suffixes): void {
            while($tokens) {
                $matched=false;
                for($length=min(3,count($tokens));$length>=1;$length--) {
                    $key=mb_strtolower(preg_replace('/[.\s]+/u','',implode(' ',array_slice($tokens,-$length))));
                    if(isset($suffixes[$key])) {
                        array_splice($tokens,-$length);
                        $matched=true;
                        break;
                    }
                }
                if(!$matched) break;
            }
        };
        $segments=explode(',',$name);
        $fullName=trim(array_shift($segments));
        foreach($segments as $segment) {
            $tokens=explode(' ',trim($segment));
            $stripSuffixes($tokens);
            if($tokens) throw new CentralAccountError('Use First name, optional middle name, then Last name; a comma may only introduce a recognized suffix or degree. Review this name or create the account manually in SIS Users and Groups.');
        }
        $parts=explode(' ',$fullName);
        $stripSuffixes($parts);
        $first=array_shift($parts)??'';
        $last=array_pop($parts)??'';
        $particles=['de','del','dela','delos','la','las','los','da','das','do','dos','di','du','van','von','der','den','san','santa','sta.','sto.','st.'];
        while($parts && in_array(mb_strtolower((string)end($parts)),$particles,true)) $last=array_pop($parts).' '.$last;
        if ($first==='' || $last==='' || !preg_match('/\p{L}/u',$first) || !preg_match('/\p{L}{2}/u',$last)) throw new CentralAccountError('Enter the teacher’s first and last name in that order. Review the suggested username before creating the account; unusual surnames can be entered manually in SIS Users and Groups.');
        $text=mb_substr($first,0,1).$last;
        $ascii=function_exists('iconv')?iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$text):$text;
        $username=strtolower(preg_replace('/[^a-zA-Z0-9]/','',$ascii===false?$text:$ascii));
        if (strlen($username)<3 || strlen($username)>60) throw new CentralAccountError('The name cannot produce a 3–60 character username. Create this account manually in SIS Users and Groups.');
        return $username;
    }

    /** References are evidence only when the referenced profile or verified central link exists. */
    public function teacherAccountLinkState(string $teacherId): array {
        $person=$this->one('SELECT account_id FROM central_person_links WHERE teacher_doc_id=?',[$teacherId]);
        if ($person) return ['state'=>'central','message'=>'This teacher already has centralized account #'.$person['account_id'].'. Manage that account in SIS Users and Groups.'];
        $raw=$this->q("SELECT data FROM docs WHERE col='teachers' AND id=?",[$teacherId])->fetchColumn();
        if ($raw===false) throw new CentralAccountError('Teacher record not found.');
        $d=json_decode($raw,true)?:[];
        $value=$d['userUid']??'';
        if (!is_string($value) && $value!==null) return ['state'=>'legacy','message'=>'The teacher has an invalid account reference. Ask the Power User to review the teacher record.'];
        $uid=trim((string)$value);
        $profiles=$this->q("SELECT id FROM docs WHERE col='users' AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.teacherDocId'))=?",[$teacherId])->fetchAll(PDO::FETCH_COLUMN);
        if ($uid!=='') $profiles[]=$uid;
        foreach(array_unique($profiles) as $source) {
            $linked=$this->one("SELECT l.account_id,i.identifier FROM central_legacy_links l LEFT JOIN central_login_identifiers i ON i.account_id=l.account_id AND i.is_primary=1 WHERE l.source_system='GRADING' AND l.source_key=?",[$source]);
            if ($linked) return ['state'=>'central','message'=>'The teacher reference points to centralized account #'.$linked['account_id'].($linked['identifier']?' ('.$linked['identifier'].')':'').'. Review its linked teacher in SIS Users and Groups; no duplicate account was created.'];
            if ($this->one("SELECT id FROM docs WHERE col='users' AND id=?",[$source])) return ['state'=>'legacy','message'=>'An existing Grading account (UID '.$source.') references this teacher. Verify that account in SIS Users and Groups before creating another account.'];
        }
        return $uid!==''?['state'=>'stale','uid'=>$uid,'message'=>'The old sign-in reference points to a missing account. Creating this account will safely replace the outdated reference.']:['state'=>'clean','message'=>''];
    }

    /** SIS teacher directory shortcut: no password resets or automatic legacy identity merges. */
    public function createTeacherAccount(int $actor,string $teacherId): array {
        $this->requirePower($actor);
        if ($teacherId==='_acadlink_professor_x') throw new CentralAccountError('Professor X is a placeholder and cannot have a login account.');
        return $this->transaction(function() use($actor,$teacherId) {
            $this->q('SELECT id FROM central_security_policy WHERE id=1 FOR UPDATE');
            $teacher=$this->one("SELECT data FROM docs WHERE col='teachers' AND id=? FOR UPDATE",[$teacherId]);
            if (!$teacher) throw new CentralAccountError('Teacher record not found.');
            if ($this->one('SELECT account_id FROM central_person_links WHERE teacher_doc_id=?',[$teacherId])) throw new CentralAccountError('This teacher already has an account. Manage the existing account in SIS Users and Groups.');
            $d=json_decode($teacher['data'],true)?:[];
            $link=$this->teacherAccountLinkState($teacherId);
            if (in_array($link['state'],['central','legacy'],true)) throw new CentralAccountError($link['message']);
            $name=trim((string)($d['teacherName']??''));
            if ($name==='') throw new CentralAccountError('Add the teacher name before creating an account.');
            $base=self::teacherUsernameBase($name);
            $username=$base;
            for ($n=1;$n<=1000;$n++) {
                $used=$this->one('SELECT id FROM central_login_identifiers WHERE identifier_key=?',[$username])
                    || $this->one('SELECT id FROM sis_users WHERE username=?',[$username])
                    || $this->one("SELECT id FROM docs WHERE col='users' AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(data,'$.username')))=? LIMIT 1",[$username]);
                if (!$used) break;
                $username=$base.($n+1);
            }
            if ($used) throw new CentralAccountError('Unable to allocate a username. Create the account in SIS Users and Groups.');
            $password='Teach-'.bin2hex(random_bytes(8)).'-A1';
            $id=$this->save($actor,['username'=>$username,'full_name'=>$name,'group'=>'TEACHER','status'=>'ACTIVE','teacher_doc_id'=>$teacherId,'temp_password'=>$password]);
            return ['account_id'=>$id,'teacher_name'=>$name,'username'=>$username,'temporary_password'=>$password];
        });
    }

    public function attachLegacy(int $id,string $system,string $source,?int $actor,string $note): void {
        $existing=$this->one('SELECT account_id FROM central_legacy_links WHERE source_system=? AND source_key=?',[$system,$source]);
        if ($existing) { if ((int)$existing['account_id']!==$id) throw new CentralAccountError('That legacy account is already linked.'); return; }
        if ($this->legacyId($id,$system)!==null) throw new CentralAccountError('This central identity already has a '.$system.' link.');
        $row=$system==='SIS'?$this->one('SELECT * FROM sis_users WHERE id=?',[$source]):$this->one("SELECT data FROM docs WHERE col='users' AND id=?",[$source]);
        if (!$row) throw new CentralAccountError('Legacy account not found.');
        $this->q('INSERT INTO central_legacy_links(source_system,source_key,account_id,verified_by,verification_note) VALUES(?,?,?,?,?)',[$system,$source,$id,$actor,mb_substr($note,0,1000)]);
        $this->audit($actor,$id,'legacy_link_verified',['system'=>$system,'source'=>$source,'note'=>$note]);
    }
    /** Compatibility records preserve original IDs. Their random hashes never authenticate anyone. */
    /** The Grading System's role: admin, teacher, or staff ("registrar") for anyone else given Grading access. */
    public function gradingRole(int $id): string {
        $g=$this->group($id);
        if ($g==='POWER_USER') return 'admin';
        if ($g==='TEACHER') return 'teacher';
        if ($g==='REGISTRAR' || $this->can($id,'grading.workspace.view')) return 'registrar';
        return 'none';
    }
    public function syncProjections(int $id): void {
        $a=$this->account($id); $group=$this->group($id);
        $sisRole=['POWER_USER'=>'ADMIN','ACCOUNTING'=>'ACCOUNTING','CASHIER'=>'CASHIER','CANTEEN_CASHIER'=>'CASHIER','CANTEEN_SUPERVISOR'=>'CASHIER','LIBRARIAN'=>'CASHIER','REGISTRAR'=>'REGISTRAR','TEACHER'=>'TEACHER','STUDENT'=>'STUDENT','PARENT'=>'STUDENT'][$group]??'TEACHER';
        $sid=$this->legacyId($id,'SIS');
        if (!$sid) {
            // Avoid silently claiming a legacy username; it must be explicitly reviewed and linked.
            if ($this->q('SELECT id FROM sis_users WHERE username=?',[$a['username']])->fetchColumn()) throw new CentralAccountError('A legacy SIS account uses this username. Select it in the migration review fields.');
            $this->q('INSERT INTO sis_users(username,full_name,role,is_active,student_number,password_hash,must_change_password) VALUES(?,?,?,?,?,?,1)',[$a['username'],$a['display_name'],$sisRole,(int)($a['status']==='ACTIVE'),$a['student_number'],password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);
            $sid=(string)$this->db->lastInsertId();
            $this->q("INSERT INTO central_legacy_links(source_system,source_key,account_id,verification_note) VALUES('SIS',?,?,'Created in SIS central administration')",[$sid,$id]);
        } else $this->q('UPDATE sis_users SET username=?,full_name=?,role=?,is_active=?,student_number=? WHERE id=?',[$a['username'],$a['display_name'],$sisRole,(int)($a['status']==='ACTIVE'),$a['student_number'],$sid]);
        $uid=$this->legacyId($id,'GRADING');
        if (!$uid && (in_array($group,['POWER_USER','REGISTRAR','TEACHER'],true) || $this->can($id,'grading.workspace.view'))) {
            $uid=bin2hex(random_bytes(16));
            $this->q("INSERT INTO central_legacy_links(source_system,source_key,account_id,verification_note) VALUES('GRADING',?,?,'Created in SIS central administration')",[$uid,$id]);
        }
        if ($uid) {
            $raw=$this->q("SELECT data FROM docs WHERE col='users' AND id=?",[$uid])->fetchColumn();
            $profile=$raw?json_decode($raw,true):[];
            $profile=array_merge($profile?:[],['username'=>$a['username'],'displayName'=>$a['display_name'],'role'=>$this->gradingRole($id),'active'=>$a['status']==='ACTIVE','canApprove'=>in_array($group,['POWER_USER','REGISTRAR'],true),'teacherDocId'=>$a['teacher_doc_id'],'mustChangePassword'=>(bool)$a['must_change_password'],'authEmail'=>$a['username'].'@central.invalid']);
            $this->q("INSERT INTO docs(col,id,data) VALUES('users',?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)",[$uid,json_encode($profile,JSON_THROW_ON_ERROR)]);
        }
    }
    public function profile(int $id,array $profile): array {
        $a=$this->account($id); $g=$this->group($id);
        return array_merge($profile,['username'=>$a['username'],'displayName'=>$a['display_name'],'role'=>$this->gradingRole($id),'active'=>$a['status']==='ACTIVE','canApprove'=>$this->can($id,'grading.corrections.review'),'teacherDocId'=>$a['person_verified']?$a['teacher_doc_id']:null,'mustChangePassword'=>(bool)$a['must_change_password']]);
    }
    public function setGroupActive(int $actor,string $code,bool $enabled): void {
        $this->requirePower($actor);
        if (!$this->can($actor,'admin.groups.manage')) throw new CentralAccountError('Group management is not permitted.');
        if ($code==='POWER_USER') throw new CentralAccountError('Power User access stays enabled.');
        if (!$this->q('SELECT 1 FROM central_groups WHERE code=?',[$code])->fetchColumn()) throw new CentralAccountError('Group not found.');
        $this->transaction(function() use($actor,$code,$enabled) {
            $this->q('SELECT id FROM central_security_policy WHERE id=1 FOR UPDATE');
            $this->q('UPDATE central_groups SET is_active=? WHERE code=?',[(int)$enabled,$code]);
            $this->q('UPDATE central_security_policy SET policy_version=policy_version+1 WHERE id=1');
            $this->audit($actor,null,'group_access_updated',['group'=>$code,'enabled'=>$enabled]);
        });
    }
    /** v55: a new group (e.g. Library, Guidance, Dean, Clinic). It starts with no access except the LMS; set it per system and module. */
    public function createGroup(int $actor,string $name,string $description): string {
        $this->requirePower($actor);
        if (!$this->can($actor,'admin.groups.manage')) throw new CentralAccountError('Group management is not permitted.');
        $name=trim(preg_replace('/\s+/',' ',$name)); $description=trim($description);
        if (mb_strlen($name)<2 || mb_strlen($name)>100) throw new CentralAccountError('Group name: 2 to 100 characters, e.g. Library.');
        if (mb_strlen($description)>500) throw new CentralAccountError('Keep the description under 500 characters.');
        if ($this->q('SELECT 1 FROM central_groups WHERE LOWER(name)=LOWER(?)',[$name])->fetchColumn()) throw new CentralAccountError("A group named \"$name\" already exists.");
        return $this->transaction(function() use($actor,$name,$description) {
            $this->q('SELECT id FROM central_security_policy WHERE id=1 FOR UPDATE');
            $base=substr(trim(preg_replace('/[^A-Z0-9]+/','_',strtoupper(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name)?:$name)),'_'),0,28) ?: 'GROUP';
            $code=$base; $n=2;
            while ($this->q('SELECT 1 FROM central_groups WHERE code=?',[$code])->fetchColumn()) $code=$base.'_'.$n++;
            $this->q('INSERT INTO central_groups(code,name,description,is_default,is_active) VALUES(?,?,?,0,1)',[$code,$name,$description]);
            $gid=(int)$this->db->lastInsertId();
            $this->q("INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope) SELECT ?,code,'ALL' FROM central_permissions WHERE code IN ('lms.workspace.view','lms.access.view')",[$gid]);
            $this->audit($actor,null,'group_created',['group'=>$code,'name'=>$name,'description'=>$description]);
            return $code;
        });
    }
    /** Rename an added group or change its description (the five built-in groups keep theirs). */
    public function updateGroup(int $actor,string $code,string $name,string $description): void {
        $this->requirePower($actor);
        if (!$this->can($actor,'admin.groups.manage')) throw new CentralAccountError('Group management is not permitted.');
        $g=$this->one('SELECT * FROM central_groups WHERE code=?',[$code]);
        if (!$g) throw new CentralAccountError('Group not found.');
        if ((int)$g['is_default']===1) throw new CentralAccountError('Built-in groups keep their names.');
        $name=trim(preg_replace('/\s+/',' ',$name)); $description=trim($description);
        if (mb_strlen($name)<2 || mb_strlen($name)>100) throw new CentralAccountError('Group name: 2 to 100 characters.');
        if (mb_strlen($description)>500) throw new CentralAccountError('Keep the description under 500 characters.');
        if ($this->q('SELECT 1 FROM central_groups WHERE LOWER(name)=LOWER(?) AND code<>?',[$name,$code])->fetchColumn()) throw new CentralAccountError("A group named \"$name\" already exists.");
        $this->q('UPDATE central_groups SET name=?,description=? WHERE code=?',[$name,$description,$code]);
        $this->audit($actor,null,'group_renamed',['group'=>$code,'before'=>['name'=>$g['name'],'description'=>$g['description']],'after'=>['name'=>$name,'description'=>$description]]);
    }
    /** Delete an added group; refused while accounts use it (turn it off instead). */
    public function deleteGroup(int $actor,string $code): void {
        $this->requirePower($actor);
        if (!$this->can($actor,'admin.groups.manage')) throw new CentralAccountError('Group management is not permitted.');
        $g=$this->one('SELECT * FROM central_groups WHERE code=?',[$code]);
        if (!$g) throw new CentralAccountError('Group not found.');
        if ((int)$g['is_default']===1) throw new CentralAccountError('Built-in groups cannot be deleted.');
        $this->transaction(function() use($actor,$g) {
            $this->q('SELECT id FROM central_security_policy WHERE id=1 FOR UPDATE');
            $n=(int)$this->q('SELECT COUNT(*) FROM central_user_groups WHERE group_id=? AND revoked_at IS NULL',[$g['id']])->fetchColumn();
            if ($n) throw new CentralAccountError("{$g['name']} has $n account(s). Move them to another group first, or turn the group off.");
            if ($this->q('SELECT 1 FROM central_user_groups WHERE group_id=?',[$g['id']])->fetchColumn()) throw new CentralAccountError("{$g['name']} was used by accounts before, so it is kept for the record. Turn it off instead.");
            $this->q('DELETE FROM central_group_permissions WHERE group_id=?',[$g['id']]);
            $this->q('DELETE FROM central_groups WHERE id=?',[$g['id']]);
            $this->audit($actor,null,'group_deleted',['group'=>$g['code'],'name'=>$g['name']]);
        });
    }
    /** A group's default access per module: $modules = key => [level NONE|VIEW|EDIT, extra codes]. Takes effect on the next click. */
    public function setGroupModules(int $actor,string $code,array $modules): void {
        $this->requirePower($actor);
        if (!$this->can($actor,'admin.groups.manage')) throw new CentralAccountError('Group management is not permitted.');
        if ($code==='POWER_USER') throw new CentralAccountError('Power User keeps full access.');
        $g=$this->one('SELECT * FROM central_groups WHERE code=?',[$code]);
        if (!$g) throw new CentralAccountError('Group not found.');
        $systems=ModuleAccess::systemsFor($code); // v58: the same systems the access grid shows
        $this->transaction(function() use($actor,$code,$g,$modules,$systems) {
            $perms=[];
            foreach ($this->q('SELECT permission_code,access_scope FROM central_group_permissions WHERE group_id=? AND is_enabled=1',[$g['id']])->fetchAll(PDO::FETCH_ASSOC) as $r) $perms[$r['permission_code']]=$r['access_scope'];
            $changes=[];
            foreach (ModuleAccess::catalog() as $key=>$m) {
                if (!in_array($m[0],$systems,true) || !isset($modules[$key])) continue;
                [$level,$extras]=$modules[$key];
                if ($level==='') continue;
                $before=ModuleAccess::level($perms,$key);
                foreach (ModuleAccess::codes($key) as $c) unset($perms[$c]);
                foreach (ModuleAccess::grant($key,$level,$extras) as $c) $perms[$c]=ModuleAccess::scope($code,$c);
                $after=ModuleAccess::level($perms,$key);
                if ($before!==$after) $changes[$key]=$after;
            }
            $perms=ModuleAccess::apply($perms,[],$code);
            $this->q('DELETE FROM central_group_permissions WHERE group_id=?',[$g['id']]);
            foreach ($perms as $c=>$scope) $this->q('INSERT INTO central_group_permissions(group_id,permission_code,access_scope) SELECT ?,code,? FROM central_permissions WHERE code=?',[$g['id'],$scope,$c]);
            $this->audit($actor,null,'group_modules_updated',['group'=>$code,'changes'=>$changes]);
        });
        $this->clearCache();
        foreach ($this->q('SELECT account_id FROM central_user_groups WHERE group_id=? AND revoked_at IS NULL',[$g['id']])->fetchAll(PDO::FETCH_COLUMN) as $member) $this->syncProjections((int)$member);
    }
    /** One person's access: modules set to '' follow the group, others override it. Never for a Power User. */
    public function setUserModules(int $actor,int $id,array $modules): void {
        $this->requirePower($actor);
        if (!$this->can($actor,'admin.groups.manage')) throw new CentralAccountError('Access management is not permitted.');
        $a=$this->account($id); if (!$a) throw new CentralAccountError('Account not found.');
        $group=$this->group($id);
        if ($group==='POWER_USER') throw new CentralAccountError('A Power User always has full access; change the group instead.');
        $g=$this->one('SELECT id FROM central_groups WHERE code=?',[$group]);
        $groupPerms=[];
        if ($g) foreach ($this->q('SELECT permission_code,access_scope FROM central_group_permissions WHERE group_id=? AND is_enabled=1',[$g['id']])->fetchAll(PDO::FETCH_ASSOC) as $r) $groupPerms[$r['permission_code']]=$r['access_scope'];
        $systems=ModuleAccess::systemsFor($a['account_kind']==='STUDENT'?'STUDENT':'STAFF');
        $this->transaction(function() use($actor,$id,$modules,$groupPerms,$systems) {
            $changes=[];
            foreach (ModuleAccess::catalog() as $key=>$m) {
                if (!in_array($m[0],$systems,true) || !isset($modules[$key])) continue;
                [$level,$extras]=$modules[$key];
                $grant=$level===''?null:ModuleAccess::grant($key,$level,$extras);
                $same=$grant!==null && ModuleAccess::level(array_fill_keys($grant,'ALL'),$key)===ModuleAccess::level($groupPerms,$key);
                if ($grant===null || $same) { if ($this->q('DELETE FROM central_user_module_access WHERE account_id=? AND module_key=?',[$id,$key])->rowCount()) $changes[$key]='group'; continue; }
                $x=json_encode(array_values(array_intersect(array_keys($m[4]),$extras)));
                $old=$this->one('SELECT level,extras FROM central_user_module_access WHERE account_id=? AND module_key=?',[$id,$key]);
                if ($old && $old['level']===$level && $old['extras']===$x) continue;
                $this->q('INSERT INTO central_user_module_access(account_id,module_key,level,extras,updated_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE level=VALUES(level),extras=VALUES(extras),updated_by=VALUES(updated_by)',[$id,$key,$level,$x,$actor]);
                $changes[$key]=$level;
            }
            if ($changes) $this->audit($actor,$id,'user_modules_updated',['changes'=>$changes]);
        });
        $this->clearCache();
        $this->syncProjections($id);
    }
    /** Back to the group's access for every module. */
    public function resetUserModules(int $actor,int $id): void {
        $this->requirePower($actor);
        if (!$this->can($actor,'admin.groups.manage')) throw new CentralAccountError('Access management is not permitted.');
        $n=$this->q('DELETE FROM central_user_module_access WHERE account_id=?',[$id])->rowCount();
        if ($n) $this->audit($actor,$id,'user_modules_reset',['modules'=>$n]);
        $this->clearCache();
        $this->syncProjections($id);
    }
}
