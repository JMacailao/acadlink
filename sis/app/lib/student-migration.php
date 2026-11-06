<?php
declare(strict_types=1);
/** Reviewed historical imports. Staging never changes academic or financial records. */
final class StudentMigration {
    public const BASE=['Student ID','Full Name','School Year','Year Level','Term','Section','Migration Reference'];
    public static function headers(string $kind): array {return array_merge(self::BASE,$kind==='BALANCES'?['Fee Category','Fee Name','Outstanding Balance']:['Subject Code','Subject Name','Units','Final Grade','Grade Scale']);}
    public static function install(): void {
        StudentHistoryStore::install(Db::pdo());
        $sql=(string)file_get_contents(dirname(SIS_ROOT).'/shared/database/student-migration.sql');
        foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')Db::q($statement);
        if(!Db::val("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_registrations' AND column_name='record_origin'"))Db::q("ALTER TABLE sis_registrations ADD COLUMN record_origin ENUM('ENROLLMENT','MIGRATION') NOT NULL DEFAULT 'ENROLLMENT' AFTER reg_no");
    }
    public static function canApprove(string $kind): bool {return Auth::can($kind==='BALANCES'?'migration.finance':'migration.academic');}
    public static function requireKind(string $kind): void {if($kind==='GRADES'&&!StudentHistory::canViewAllGrades())throw new Forbidden('Academic migration is restricted to Registrar and Power Users.');}
    public static function requireManage(): void {Auth::require('registration','accounting','users.manage');}
    public static function batch(int $id): array {self::requireManage();$b=Db::one('SELECT * FROM sis_migration_batches WHERE id=?',[$id]);if(!$b)throw new AppError('Migration batch not found.');self::requireKind($b['kind']);return $b;}
    private static function json($v): string {return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
    public static function validate(string $kind,array $input): array {
        if(!in_array($kind,['BALANCES','GRADES'],true))throw new AppError('Choose balances or academic records.');
        if(!$input || count($input)>1000)throw new AppError('Upload 1–1,000 completed rows per batch.');
        $out=[];$seen=[];$seenSubjects=[];$periods=[];$periodMeta=[];$totals=[];
        foreach($input as $index=>$raw){
            $row=['row'=>(int)($raw['__excel_row']??($index+2)),'input'=>$raw,'data'=>null,'error'=>'','warning'=>''];
            try{
                $v=fn($key)=>trim((string)($raw[$key]??''));
                $num=$v('Student ID');$matches=Db::all("SELECT id,data FROM docs WHERE col='students' AND f_studentId=?",[$num]);
                if($num===''||count($matches)!==1)throw new AppError('Student ID must match exactly one verified SIS student record.');
                $s=json_decode($matches[0]['data'],true);$sid=$matches[0]['id'];
                if($v('Full Name')==='')throw new AppError('Full Name is required.');
                if(mb_strtolower(preg_replace('/\s+/',' ',$v('Full Name')))!==mb_strtolower(preg_replace('/\s+/',' ',trim($s['studentName']??''))))$row['warning']='Name differs: SIS record is '.($s['studentName']??'').'. Verify identity using Student ID and source records.';
                $sy=$v('School Year');$term=$v('Term');$yl=$v('Year Level');$ref=$v('Migration Reference');
                if(!GS::validSchoolYear($sy)||!in_array($term,TERMS,true)||!in_array($yl,YEAR_LEVELS,true))throw new AppError('Choose a valid School Year, Year Level and Term.');
                if($ref===''||mb_strlen($ref)>100)throw new AppError('A stable source Migration Reference (up to 100 characters) is required.');
                if(mb_strlen($v('Section'))>80)throw new AppError('Section is too long.');
                $d=['student_doc_id'=>$sid,'student_number'=>$num,'student_name'=>$s['studentName'],'school_year'=>$sy,'year_level'=>$yl,'term'=>$term,'section_name'=>$v('Section'),'reference'=>$ref];
                $period=$sid.'|'.$sy.'|'.$term;
                $meta=$yl.'|'.$v('Section');if(isset($periodMeta[$period])&&$periodMeta[$period]!==$meta)throw new AppError('Use one year level and section per student and term.');$periodMeta[$period]=$meta;
                if($kind==='BALANCES'){
                    $fees=Db::all('SELECT l.id,l.name,c.name AS category FROM sis_fee_labels l JOIN sis_fee_categories c ON c.id=l.category_id WHERE l.name=? AND c.name=? AND l.is_active=1 AND c.is_active=1',[$v('Fee Name'),$v('Fee Category')]);
                    if(count($fees)!==1)throw new AppError('Fee Category / Fee Name must match one active fee in Assessment. Map or create the fee there, then upload again.');
                    $amount=$v('Outstanding Balance');if(!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D',$amount)||Money::cents($amount)<=0)throw new AppError('Outstanding Balance must be a positive amount with at most two decimal places, without commas or currency symbols.');
                    if(Db::val("SELECT id FROM sis_registrations WHERE student_doc_id=? AND school_year=? AND term=? AND status='REGISTERED'",[$sid,$sy,$term]))throw new AppError('An assessment already exists for this student and period. Review it instead of importing another opening balance.');
                    $d+=['fee_id'=>(int)$fees[0]['id'],'category'=>$fees[0]['category'],'fee_name'=>$fees[0]['name'],'cents'=>Money::cents($amount)];
                    $totals[$period]=($totals[$period]??0)+$d['cents'];if($totals[$period]>999999999999)throw new AppError('The period total exceeds the supported accounting amount. Split or verify the historical source.');
                    $key=$period.'|'.$d['fee_id'].'|'.$ref;
                    if(isset($periods[$period.'|'.$d['fee_id']]))$row['warning'].=' Repeated fee with a different reference: verify that both are separate unpaid obligations.';
                    $periods[$period.'|'.$d['fee_id']]=true;
                }else{
                    $subjects=Db::all("SELECT id,data FROM docs WHERE col='subjects' AND UPPER(JSON_UNQUOTE(JSON_EXTRACT(data,'$.subjectCode')))=?",[strtoupper($v('Subject Code'))]);
                    if(count($subjects)!==1)throw new AppError('Subject Code must match one SIS subject.');
                    $subject=json_decode($subjects[0]['data'],true);$grade=strtoupper($v('Final Grade'));$scale=$v('Grade Scale');
                    if(!in_array($scale,['percent','point5'],true))throw new AppError('Grade Scale must be percent or point5.');
                    $mark=in_array($grade,['INC','DRP'],true)?$grade:'';
                    // point5 uses Grading's own steps: 5.00 (highest) down to 3.00 Passed, 2.00 Conditional, 1.00 Failed.
                    $point5=[5,4.75,4.5,4.25,4,3.75,3.5,3.25,3,2,1];
                    if(!$mark&&(!is_numeric($grade)||!preg_match('/^\d+(?:\.\d{1,2})?$/D',$grade)||($scale==='percent'&&((float)$grade<0||(float)$grade>100))||($scale==='point5'&&!in_array((float)$grade,$point5,true))))
                        throw new AppError($scale==='point5'?'Final grade must be a step on the 1.00-5.00 scale: 5.00, 4.75 … 3.00, 2.00 or 1.00.':'Final grade must be 0 to 100, with at most 2 decimals.');
                    $units=$v('Units');if(!preg_match('/^\d{1,2}(?:\.\d)?$/D',$units)||(float)$units<=0)throw new AppError('Units must be positive, with at most one decimal place.');
                    foreach(GS::completions($sid) as $c)if(($c['schoolYear']??'')===$sy&&($c['term']??'')===$term)throw new AppError('Finalized grades already exist for this period. Use the authorized grade-correction process.');
                    if(Db::val("SELECT id FROM docs WHERE col='grades' AND f_studentId=? AND f_schoolYear=? AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.term'))=? LIMIT 1",[$sid,$sy,$term]))throw new AppError('Grading records already exist for this period; review them before migration.');
                    $d+=['subject_id'=>$subjects[0]['id'],'subject_code'=>$subject['subjectCode'],'subject_name'=>$subject['subjectName'],'units'=>(float)$units,'grade'=>$mark?null:(float)$grade,'mark'=>$mark,'scale'=>$scale,'remarks'=>$mark?:($scale==='point5'?((float)$grade>=3?'Passed':((float)$grade==2.0?'Conditional':'Failed')):((float)$grade>=75?'Passed':'Failed'))];
                    $logical=$period.'|'.$d['subject_id'];if(isset($seenSubjects[$logical]))throw new AppError('Duplicate student, term and subject in this file.');$seenSubjects[$logical]=true;
                    $key=$logical.'|'.$ref;
                    if(isset($periods[$period])&&$periods[$period]!==$scale)throw new AppError('Use one grade scale per student and term.');$periods[$period]=$scale;
                }
                $hash=hash('sha256',$kind.'|'.$key);
                if(isset($seen[$hash]))throw new AppError('Duplicate student / period / fee-reference or subject in this file.');$seen[$hash]=true;
                if(Db::val('SELECT 1 FROM sis_migration_claims WHERE key_hash=?',[$hash]))throw new AppError('This source record was already imported. Review the original migration batch.');
                $d['key']=$hash;$row['data']=$d;
            }catch(AppError $e){$row['error']=$e->getMessage();}
            $out[]=$row;
        }
        return $out;
    }
    public static function upload(string $kind,string $path,string $name): int {
        self::requireManage();self::requireKind($kind);if(filesize($path)>5*1024*1024)throw new AppError('Upload a file smaller than 5 MB.');
        $hash=hash_file('sha256',$path);$old=Db::val('SELECT id FROM sis_migration_batches WHERE kind=? AND file_hash=?',[$kind,$hash]);if($old)return (int)$old;
        $rows=Xlsx::read($path,$name,strtolower(pathinfo($name,PATHINFO_EXTENSION))==='xlsx'?'Records':null);
        $sourceRows=Xlsx::$sourceRows;array_shift($sourceRows);
        if(!$rows || array_shift($rows)!==self::headers($kind))throw new AppError('Use the downloaded template without changing the Records column headers.');
        $input=[];foreach($rows as $rowIndex=>$r){$r=array_pad($r,count(self::headers($kind)),'');$d=array_combine(self::headers($kind),array_slice($r,0,count(self::headers($kind))));
            // Populated templates have names and IDs but no obligations until completed.
            $detail=$kind==='BALANCES'?['Fee Category','Fee Name','Outstanding Balance']:['Subject Code','Subject Name','Units','Final Grade'];
            if(!array_filter($detail,fn($k)=>trim($d[$k])!==''))continue;$d['__excel_row']=$sourceRows[$rowIndex]??($rowIndex+2);$input[]=$d;
        }
        $review=self::validate($kind,$input);
        return Db::tx(function()use($kind,$hash,$name,$input,$review){$id=Db::insert('sis_migration_batches',['kind'=>$kind,'file_hash'=>$hash,'source_name'=>mb_substr(basename($name),0,191),'input_json'=>self::json($input),'review_json'=>self::json($review),'created_by'=>Auth::id()]);Audit::log('migration_uploaded','migration',$id,['kind'=>$kind,'rows'=>count($input)]);return $id;});
    }
    public static function approve(int $id,string $reason,bool $ack,int $equity=0): void {
        $b=self::batch($id);if(!self::canApprove($b['kind']))throw new Forbidden('This migration requires the authorized Accounting or Registrar reviewer.');
        if(mb_strlen(trim($reason))<8)throw new AppError('Enter a review reason of at least 8 characters.');
        Db::tx(function()use($id,$reason,$ack,$equity){$b=Db::one('SELECT * FROM sis_migration_batches WHERE id=? FOR UPDATE',[$id]);if($b['status']!=='DRAFT')throw new AppError('Only a draft batch can be approved.');
            if(Settings::on('acct_separate_duties')&&(int)$b['created_by']===(int)Auth::id())throw new AppError('You uploaded this batch, so another authorized reviewer must approve it (separation of duties). For a one-person office, a Power User can turn this rule off in Settings.');
            $review=self::validate($b['kind'],json_decode($b['input_json'],true));
            if(array_filter($review,fn($r)=>$r['error']!==''))throw new AppError('Fix all row errors in the source file and upload the corrected file.');
            if(array_filter($review,fn($r)=>trim($r['warning'])!=='')&&!$ack)throw new AppError('Explicitly verify all identity and duplicate-fee warnings first.');
            if($b['kind']==='BALANCES'){
                if(!Db::val("SELECT id FROM sis_accounts WHERE id=? AND type='EQUITY' AND is_active=1",[$equity]))throw new AppError('Choose an active opening-balance equity account.');
                $map=Accounting::map();if(empty($map['ar'])||!Db::val("SELECT id FROM sis_accounts WHERE id=? AND type='ASSET' AND is_active=1",[$map['ar']]))throw new AppError('Configure an active Accounts Receivable asset mapping first.');
            }
            Db::q("UPDATE sis_migration_batches SET status='APPROVED',review_json=?,approved_hash=?,equity_account_id=?,reviewed_by=?,review_reason=? WHERE id=?",[self::json($review),hash('sha256',self::json($review)),$equity?:null,Auth::id(),$reason,$id]);Audit::log('migration_approved','migration',$id,['reason'=>$reason,'warnings_verified'=>$ack,'equity'=>$equity]);
        });
    }
    public static function import(int $id): void {
        $b=self::batch($id);if(!self::canApprove($b['kind']))throw new Forbidden('Only the authorized reviewer can import this batch.');
        CurriculumAssignments::locked(Db::pdo(),fn()=>Db::tx(function()use($id){
            $b=Db::one('SELECT * FROM sis_migration_batches WHERE id=? FOR UPDATE',[$id]);if($b['status']==='IMPORTED')return;if($b['status']!=='APPROVED')throw new AppError('Approve this batch first.');
            Db::q('SELECT id FROM central_security_policy WHERE id=1 FOR UPDATE');
            Auth::central()->clearCache();if(!self::canApprove($b['kind']))throw new Forbidden('Migration access changed. Sign in again.');
            $input=json_decode($b['input_json'],true);foreach($input as $raw)Db::q("SELECT id FROM docs WHERE col='students' AND f_studentId=? FOR UPDATE",[$raw['Student ID']]);
            $review=self::validate($b['kind'],$input);
            if(!hash_equals($b['approved_hash'],hash('sha256',self::json($review))))throw new AppError('Source records changed after approval. Discard this unposted batch and upload for a fresh review.');
            $groups=[];foreach($review as $r){$d=$r['data'];$groups[$d['student_doc_id'].'|'.$d['school_year'].'|'.$d['term']][]=$d;Db::insert('sis_migration_claims',['key_hash'=>$d['key'],'batch_id'=>$id,'source_row'=>$r['row']]);}
            foreach($groups as $group){$d=$group[0];
                if($b['kind']==='BALANCES'){
                    $total=array_sum(array_column($group,'cents'));$rid=Db::insert('sis_registrations',['reg_no'=>'MIG-'.str_pad((string)Seq::next('migration_registration'),12,'0',STR_PAD_LEFT),'record_origin'=>'MIGRATION','student_number'=>$d['student_number'],'student_doc_id'=>$d['student_doc_id'],'student_name'=>$d['student_name'],'school_year'=>$d['school_year'],'term'=>$d['term'],'year_level'=>$d['year_level'],'section_doc_id'=>'','section_name'=>$d['section_name'],'total_amount'=>Money::dec($total),'locked_at'=>date('Y-m-d H:i:s'),'locked_by'=>Auth::id(),'created_by'=>Auth::id(),'remarks'=>'Opening balances; migration #'.$id]);
                    foreach($group as $fee)Db::insert('sis_registration_items',['registration_id'=>$rid,'category_name'=>$fee['category'],'label_name'=>$fee['fee_name'],'charge_type'=>'FIXED','quantity'=>1,'unit_amount'=>Money::dec($fee['cents']),'amount'=>Money::dec($fee['cents'])]);
                    $map=Accounting::map();if(empty($map['ar'])||!Db::val("SELECT id FROM sis_accounts WHERE id=? AND type='ASSET' AND is_active=1",[$map['ar']]))throw new AppError('Accounts Receivable mapping changed; review the accounting configuration.');if(!Db::val("SELECT id FROM sis_accounts WHERE id=? AND type='EQUITY' AND is_active=1",[$b['equity_account_id']]))throw new AppError('Opening equity account changed; review the accounting configuration.');
                    Accounting::post(date('Y-m-d'),'ASSESSMENT',$rid,$rid,'Opening balances · migration #'.$id,[[((int)($map['ar']??0)),$total,0,'Opening receivables'],[(int)$b['equity_account_id'],0,$total,'Opening balance equity']],null,'POSTED');
                    Db::insert('sis_migration_targets',['batch_id'=>$id,'kind'=>'REGISTRATION','target_id'=>(string)$rid]);
                }else{
                    $subjects=[];$weighted=0;$units=0;$incomplete=false;foreach($group as $g){$subjects[]=['subjectId'=>$g['subject_id'],'subjectCode'=>$g['subject_code'],'subjectName'=>$g['subject_name'],'units'=>$g['units'],'finalGrade'=>$g['grade'],'mark'=>$g['mark'],'remarks'=>$g['remarks']];if($g['mark'])$incomplete=true;else{$weighted+=$g['grade']*$g['units'];$units+=$g['units'];}}
                    $termKey=preg_replace('/[^a-z0-9]+/','-',strtolower($d['term']));$cid=$d['student_doc_id'].'__'.$d['school_year'].'__'.$termKey;
                    $data=['studentId'=>$d['student_doc_id'],'studentNumber'=>$d['student_number'],'studentName'=>$d['student_name'],'schoolYear'=>$d['school_year'],'yearLevel'=>$d['year_level'],'term'=>$d['term'],'sectionName'=>$d['section_name'],'subjects'=>$subjects,'pending'=>[],'incomplete'=>$incomplete,'totalUnits'=>array_sum(array_column($group,'units')),'gwa'=>$units?round($weighted/$units,2):null,'gradingScale'=>$d['scale'],'completedAt'=>GS::ts(),'completedByName'=>Auth::user()['full_name'],'migrationBatch'=>$id,'termKey'=>$termKey];
                    Db::q("INSERT INTO docs(col,id,data) VALUES('gradeCompletions',?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)",[$cid,self::json($data)]);Db::insert('sis_migration_targets',['batch_id'=>$id,'kind'=>'COMPLETION','target_id'=>$cid]);
                    StudentHistoryStore::capture(Db::pdo(),$d['student_doc_id'],['studentId'=>$d['student_number'],'studentName'=>$d['student_name'],'schoolYear'=>$d['school_year'],'yearLevel'=>$d['year_level'],'sectionName'=>$d['section_name'],'status'=>'Historical import'],$d['term']);
                }
            }
            Db::q("UPDATE sis_migration_batches SET status='IMPORTED',imported_at=NOW() WHERE id=?",[$id]);Audit::log('migration_imported','migration',$id,['rows'=>count($review),'periods'=>count($groups),'kind'=>$b['kind']]);
        }));
    }
    public static function itemBalance(array $item): int {
        if(Db::val('SELECT status FROM sis_registrations WHERE id=?',[$item['registration_id']])==='CANCELLED')return 0;
        $paid=Db::val("SELECT COALESCE(SUM(a.amount),0) FROM sis_migration_payment_items a JOIN sis_payments p ON p.id=a.payment_id WHERE a.item_id=? AND p.status='POSTED'",[$item['id']]);
        $refund=Db::val("SELECT COALESCE(SUM(a.amount),0) FROM sis_refund_allocations a JOIN sis_accounting_documents d ON d.id=a.document_id JOIN sis_journal j ON j.id=d.journal_id WHERE a.item_id=? AND j.status='POSTED' AND j.reversed_by_id IS NULL",[$item['id']]);
        return max(0,Money::cents($item['amount'])-Money::cents($paid)+Money::cents($refund));
    }
    public static function allocatePayment(int $paymentId,array $r,int $cents,?int $itemId=null): void {
        if(($r['record_origin']??'')!=='MIGRATION')return;
        $items=Db::all('SELECT * FROM sis_registration_items WHERE registration_id=? ORDER BY sort_order,id',[$r['id']]);
        if($itemId){$items=array_values(array_filter($items,fn($i)=>(int)$i['id']===$itemId));if(!$items||self::itemBalance($items[0])<$cents)throw new AppError('The payment exceeds the selected fee balance.');}
        foreach($items as $item){$part=min($cents,self::itemBalance($item));if($part>0){Db::insert('sis_migration_payment_items',['payment_id'=>$paymentId,'item_id'=>$item['id'],'amount'=>Money::dec($part)]);$cents-=$part;}if($cents===0)break;}
        if($cents!==0)throw new AppError('The opening-fee allocation does not match the outstanding balance.');
    }
    public static function reverse(int $id,string $reason): void {
        $b=self::batch($id);if(!self::canApprove($b['kind']))throw new Forbidden('Only the authorized reviewer can reverse this migration.');if(mb_strlen(trim($reason))<8)throw new AppError('Enter a reversal reason of at least 8 characters.');
        CurriculumAssignments::locked(Db::pdo(),fn()=>Db::tx(function()use($id,$reason){$b=Db::one('SELECT * FROM sis_migration_batches WHERE id=? FOR UPDATE',[$id]);if($b['status']!=='IMPORTED')throw new AppError('Only an imported batch can be reversed.');Db::q('SELECT id FROM central_security_policy WHERE id=1 FOR UPDATE');Auth::central()->clearCache();if(!self::canApprove($b['kind']))throw new Forbidden('Migration access changed. Sign in again.');
            foreach(Db::all('SELECT * FROM sis_migration_targets WHERE batch_id=?',[$id]) as $t){if($t['kind']==='REGISTRATION'){
                $r=Db::one('SELECT * FROM sis_registrations WHERE id=? FOR UPDATE',[$t['target_id']]);if(Db::val("SELECT 1 FROM sis_payments WHERE registration_id=? AND status='POSTED'",[$t['target_id']]))throw new AppError('Void the posted receipts through the authorized accounting process before reversing opening balances.');
                Accounting::reverseAssessment((int)$r['id'],$reason);Db::q("UPDATE sis_registrations SET status='CANCELLED' WHERE id=?",[$r['id']]);
            }else{$d=GS::get('gradeCompletions',$t['target_id']);$d['migrationReversed']=true;$d['reversalReason']=$reason;unset($d['id']);Db::q("UPDATE docs SET data=? WHERE col='gradeCompletions' AND id=?",[self::json($d),$t['target_id']]);}}
            Db::q("UPDATE sis_migration_batches SET status='REVERSED' WHERE id=?",[$id]);Audit::log('migration_reversed','migration',$id,['reason'=>$reason]);
        }));
    }
}
