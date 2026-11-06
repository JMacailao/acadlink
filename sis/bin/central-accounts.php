<?php
/** Local operator only. Never exposed through HTTP. Read credentials from stdin. */
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/../app/bootstrap.php';
sis_boot(); $c=Auth::central();
$args=[];
foreach(array_slice($argv,2) as $arg) if(str_starts_with($arg,'--')) { [$k,$v]=array_pad(explode('=',substr($arg,2),2),2,''); $args[$k]=$v; }
try {
    $command=$argv[1]??'';
    if($command==='install') { $c->install(); echo "Central schema installed. Existing identities have not been merged or activated.\n"; exit; }
    if(!$c->installed()) throw new CentralAccountError('Run install first.');
    if(!in_array($command,['bootstrap-sis','bootstrap-grading','bootstrap-new'],true)) {
        echo "Commands: install | bootstrap-sis --source=ID --username=NAME | bootstrap-grading --source=UID --username=NAME | bootstrap-new --username=NAME --name=FULLNAME\nRead CENTRAL_ACCOUNTS.md before rollout. Password is read from standard input.\n"; exit(1);
    }
    if($c->q('SELECT COUNT(*) FROM central_accounts')->fetchColumn()) throw new CentralAccountError('Bootstrap is available only before the first central account. Use SIS Users and Groups.');
    fwrite(STDERR,$command==='bootstrap-new'?"Enter the initial temporary password on standard input:\n":"Enter the selected legacy administrator password on standard input:\n");
    $password=rtrim((string)fgets(STDIN),"\r\n");
    $in=['full_name'=>$args['name']??'Power User','username'=>$args['username']??'','group'=>'POWER_USER','status'=>'ACTIVE','temp_password'=>bin2hex(random_bytes(16)).'A1','verification_note'=>'Explicit local operator bootstrap; source ID and existing credential verified.'];
    $hash=null;
    if($command==='bootstrap-sis') {
        $u=$c->one("SELECT * FROM sis_users WHERE id=? AND role='ADMIN' AND is_active=1",[$args['source']??'']);
        if(!$u || !password_verify($password,$u['password_hash'])) throw new CentralAccountError('The selected active legacy administrator credential could not be verified.');
        $in['full_name']=$u['full_name']; $in['source_sis']=(string)$u['id']; $hash=$u['password_hash'];
    } elseif($command==='bootstrap-grading') {
        $u=$c->one("SELECT d.data,a.password_hash FROM docs d JOIN accounts a ON a.uid=d.id WHERE d.col='users' AND d.id=?",[$args['source']??'']);
        $profile=$u?json_decode($u['data'],true):[];
        if(!$u || ($profile['role']??'')!=='admin' || ($profile['active']??false)!==true || !password_verify($password,$u['password_hash'])) throw new CentralAccountError('The selected active legacy administrator credential could not be verified.');
        $in['full_name']=$profile['displayName']; $in['source_grading']=$args['source']; $hash=$u['password_hash'];
    } else {
        if($p=CentralAccounts::passwordProblem($password)) throw new CentralAccountError($p);
        $in['temp_password']=$password;
    }
    // Optional second source is also an explicit operator decision; never inferred from a name.
    if(isset($args['sis-id'])) $in['source_sis']=$args['sis-id'];
    if(isset($args['grading-uid'])) $in['source_grading']=$args['grading-uid'];
    $id=$c->transaction(function()use($c,$in,$hash){
        $id=$c->save(null,$in,true);
        if($hash) $c->q('UPDATE central_credentials SET password_hash=? WHERE account_id=?',[$hash,$id]);
        return $id;
    });
    echo "Power User created (central ID $id). Sign in through SIS and change the temporary password. Review and migrate other accounts in SIS.\n";
} catch(Throwable $e) { fwrite(STDERR,"Central account setup failed: ".$e->getMessage()."\n"); exit(1); }
