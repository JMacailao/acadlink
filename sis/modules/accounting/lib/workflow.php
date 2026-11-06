<?php
declare(strict_types=1);

/** Reviewed accounting writes share one lock with period closing. Amounts are integer cents in PHP. */
final class AccountingWorkflow
{
    public const FUNDS=['OPERATING'=>'Operating fund','CAPITAL'=>'Capital fund','RESTRICTED'=>'Restricted / scholarship fund'];
    public const KINDS=['BILL'=>'Supplier bill','DISBURSEMENT'=>'Pay supplier bill','EXPENSE'=>'Paid expense','REIMBURSEMENT'=>'Staff reimbursement','PAYROLL'=>'Payroll summary','DEPRECIATION'=>'Asset depreciation','ADJUSTMENT'=>'Student charge / discount','REFUND'=>'Student refund'];
    public static function install(): void {
        if(Db::pdo()->inTransaction())throw new AppError('Accounting schema upgrades must finish before financial transactions start.');
        $lock='acadlink_acct_'.substr(hash('sha256',(string)Db::val('SELECT DATABASE()')),0,32);
        if((int)Db::val('SELECT GET_LOCK(?,10)',[$lock])!==1)throw new AppError('Accounting is being upgraded. Reload in a moment.');
        try {
        $cols=["status"=>"ENUM('DRAFT','REVIEW','POSTED','CANCELLED') NOT NULL DEFAULT 'POSTED'","fund"=>"ENUM('OPERATING','CAPITAL','RESTRICTED') NOT NULL DEFAULT 'OPERATING'","department"=>"VARCHAR(80) NOT NULL DEFAULT 'School'","reviewed_by"=>'INT UNSIGNED NULL',"reviewed_at"=>'DATETIME NULL',"posted_by"=>'INT UNSIGNED NULL',"posted_at"=>'DATETIME NULL',"review_note"=>'VARCHAR(1000) NULL'];
        foreach($cols as $name=>$type)if(!Db::val('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=\'sis_journal\' AND column_name=?',[$name]))Db::q("ALTER TABLE sis_journal ADD COLUMN $name $type");
        if(!Db::val("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='sis_journal' AND index_name='k_sis_journal_workflow'"))Db::q('ALTER TABLE sis_journal ADD KEY k_sis_journal_workflow(status,entry_date)');
        $sql=preg_replace('/^\s*--.*$/m','',(string)file_get_contents(dirname(SIS_ROOT).'/shared/database/accounting-workflow.sql'));
        foreach(array_filter(array_map('trim',explode(';',$sql))) as $stmt)Db::q($stmt);
        }finally{Db::val('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public static function lock(): void { Db::one('SELECT id FROM sis_accounting_control WHERE id=1 FOR UPDATE'); }
    public static function authorize(string $permission): void {
        Db::one('SELECT id FROM central_security_policy WHERE id=1 FOR UPDATE');Auth::central()->clearCache();Auth::require($permission);
    }
    public static function date(string $value): string {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value)throw new AppError('Choose a valid date.');return $value;
    }
    public static function money(string $value,bool $positive=true): int {
        if(!preg_match('/^\d{1,9}(\.\d{1,2})?$/',trim($value)))throw new AppError('Enter an amount with up to 2 decimals, without commas or currency symbols.');
        $c=Money::cents(trim($value));if($positive&&$c<=0)throw new AppError('Enter an amount greater than zero.');return $c;
    }
    public static function reason(string $reason): string { $reason=trim($reason);if(mb_strlen($reason)<8||mb_strlen($reason)>1000)throw new AppError('Give a reason of 8 to 1,000 characters.');return $reason; }
    public static function dimensions(array $in): array {
        $fund=(string)($in['fund']??'OPERATING');$dept=trim((string)($in['department']??'School'));
        if(!isset(self::FUNDS[$fund])||$dept===''||mb_strlen($dept)>80)throw new AppError('Choose a fund and enter a department of up to 80 characters.');return [$fund,$dept];
    }
    public static function assertOpen(string $date): void {
        self::date($date);self::lock();
        if(Db::val("SELECT id FROM sis_accounting_periods WHERE status='CLOSED' AND ? BETWEEN start_date AND end_date",[$date]))throw new AppError('This accounting period is closed. Ask Accounting to reopen it with a reason, or choose an open date.');
    }
    public static function classify(int $id,array $in): void {
        if(PaymentPlans::managedJournal($id))throw new AppError('Payment-plan adjustments inherit their assessment classification.');
        Db::tx(function()use($id,$in){self::lock();self::authorize('accounting.edit');[$fund,$dept]=self::dimensions($in);$j=Db::one('SELECT * FROM sis_journal WHERE id=? FOR UPDATE',[$id]);if(!$j||!in_array($j['status'],['DRAFT','REVIEW'],true))throw new AppError('Only unposted entries can be classified.');
            $d=Db::one('SELECT * FROM sis_accounting_documents WHERE journal_id=?',[$id]);
            if($d&&in_array($d['kind'],['DISBURSEMENT','REFUND'],true))throw new AppError('Supplier payments and refunds use the fund and department of their original transaction.');
            Db::q('UPDATE sis_journal SET fund=?,department=?,reviewed_by=NULL,reviewed_at=NULL WHERE id=?',[$fund,$dept,$id]);Audit::log('journal_classified','journal',$id,['fund'=>$fund,'department'=>$dept]);});
    }
    /** Exact proportional cents without an overflowing amount × amount intermediate. */
    public static function mulDiv(int $a,int $b,int $c): int {
        if($a<0||$b<0||$c<=0)throw new AppError('Invalid allocation amounts.');$whole=intdiv($a,$c)*$b;$a%=$c;$q=0;$r=0;
        foreach(str_split(decbin($b)) as $bit){$q*=2;$r*=2;if($bit==='1')$r+=$a;$q+=intdiv($r,$c);$r%=$c;}return $whole+$q;
    }
    public static function validateLines(int $id): void {
        $lines=Db::all('SELECT l.*,a.is_active FROM sis_journal_lines l JOIN sis_accounts a ON a.id=l.account_id WHERE journal_id=?',[$id]);$dr=0;$cr=0;
        foreach($lines as $l){$d=Money::cents($l['debit']);$c=Money::cents($l['credit']);if(!(int)$l['is_active']||$d<0||$c<0||($d>0&&$c>0))throw new AppError('Journal lines require active accounts and a single positive debit or credit.');$dr+=$d;$cr+=$c;}
        if(count($lines)<2||$dr<=0||$dr!==$cr)throw new AppError('Check the journal: positive total debits and credits must match.');
    }
    /** Separation of duties (Settings → Accounting): whoever prepared an entry cannot also approve or post it. */
    public static function assertSecondPerson(array $j,string $step): void {
        if(!Settings::on('acct_separate_duties'))return;
        if((int)$j['created_by']===(int)Auth::id())throw new AppError('You prepared '.($j['entry_no']??'this entry').', so another Accounting user must '.$step.' it (separation of duties). For a one-person office, a Power User can turn this rule off in Settings.');
    }
    public static function submit(int $id): void {
        Db::tx(function()use($id){self::lock();self::authorize('accounting.edit');$j=Db::one('SELECT * FROM sis_journal WHERE id=? FOR UPDATE',[$id]);if(!$j||$j['status']!=='DRAFT')throw new AppError('Only a draft can be sent for review.');self::assertOpen($j['entry_date']);self::validateLines($id);Db::q("UPDATE sis_journal SET status='REVIEW',reviewed_by=NULL,reviewed_at=NULL WHERE id=?",[$id]);Audit::log('journal_submitted','journal',$id);});
    }
    public static function review(int $id,string $reason,bool $approve): void {
        Db::tx(function()use($id,$reason,$approve){self::lock();self::authorize('accounting.review');$reason=self::reason($reason);$j=Db::one('SELECT * FROM sis_journal WHERE id=? FOR UPDATE',[$id]);if(!$j||$j['status']!=='REVIEW'||$j['reversed_by_id'])throw new AppError('Only an entry awaiting review can be reviewed.');self::validateLines($id);
            // Source-generated entries cannot become editable manual drafts.
            if($approve)self::assertSecondPerson($j,'approve');
            if(!$approve&&($j['source']!=='MANUAL'||PaymentPlans::managedJournal($id)))throw new AppError('Correct the source transaction through Registration or Cashier. Posted receipts need an Accounting reversal first.');
            Db::q('UPDATE sis_journal SET status=?,reviewed_by=?,reviewed_at=?,review_note=? WHERE id=?',[$approve?'REVIEW':'DRAFT',$approve?Auth::id():null,$approve?date('Y-m-d H:i:s'):null,$reason,$id]);Audit::log($approve?'journal_approved':'journal_returned','journal',$id,['reason'=>$reason]);});
    }
    public static function post(int $id): void {
        Db::tx(function()use($id){self::lock();self::authorize('accounting.post');$j=Db::one('SELECT * FROM sis_journal WHERE id=? FOR UPDATE',[$id]);if(!$j)throw new AppError('Journal entry not found.');if($j['status']==='POSTED')return;
            if($j['status']!=='REVIEW'||!$j['reviewed_by']||$j['reversed_by_id'])throw new AppError('Accounting must approve this entry before posting.');self::assertSecondPerson($j,'post');self::assertOpen($j['entry_date']);self::validateLines($id);
            if($j['source']==='PAYMENT'){$p=Db::one('SELECT * FROM sis_payments WHERE id=? FOR UPDATE',[$j['source_id']]);if(!$p||$p['status']!=='POSTED')throw new AppError('This receipt is no longer active.');}
            if($j['source']==='ASSESSMENT'){$r=Db::one('SELECT * FROM sis_registrations WHERE id=? FOR UPDATE',[$j['source_id']]);if(!$r||$r['status']==='CANCELLED'||!$r['locked_at'])throw new AppError('The assessment must be confirmed before posting.');}
            $doc=Db::one('SELECT * FROM sis_accounting_documents WHERE journal_id=? FOR UPDATE',[$id]);if($doc)self::applyDocument($doc,$j,false);
            Db::q("UPDATE sis_journal SET status='POSTED',posted_by=?,posted_at=NOW() WHERE id=?",[Auth::id(),$id]);if($doc&&in_array($doc['kind'],['REFUND','ADJUSTMENT'],true))PaymentPlans::sync((int)$doc['registration_id']);Audit::log('journal_posted','journal',$id,['source'=>$j['source'],'source_id'=>$j['source_id']]);});
    }
    public static function reverse(int $id,string $reason,string $date): int {
        return Db::tx(function()use($id,$reason,$date){self::lock();self::authorize('accounting.post');$reason=self::reason($reason);if(PaymentPlans::managedJournal($id))throw new AppError('This discount follows the saved payment plan. Correct its payment or refund to generate the matching adjustment.');$j=Db::one('SELECT * FROM sis_journal WHERE id=? FOR UPDATE',[$id]);if(!$j||$j['status']!=='POSTED'||$j['reverses_id'])throw new AppError('Choose an original posted entry.');if($j['reversed_by_id'])return (int)$j['reversed_by_id'];
            self::assertOpen($date);if($date<$j['entry_date'])throw new AppError('Reversal date cannot be earlier than the original entry.');
            if($j['source']==='ASSESSMENT'&&Db::val("SELECT id FROM sis_registrations WHERE id=? AND record_origin='MIGRATION'",[$j['source_id']]))throw new AppError('Reverse imported opening balances from Data Migration to keep their source records consistent.');
            if($j['source']==='PAYMENT'&&Db::val("SELECT d.id FROM sis_accounting_documents d JOIN sis_journal x ON x.id=d.journal_id WHERE d.kind='REFUND' AND d.payment_id=? AND x.status='POSTED' AND x.reversed_by_id IS NULL",[$j['source_id']]))throw new AppError('Reverse the linked refund before reversing this receipt.');
            $doc=Db::one('SELECT * FROM sis_accounting_documents WHERE journal_id=? FOR UPDATE',[$id]);if($doc)self::applyDocument($doc,$j,true);
            if($j['source']==='ASSESSMENT')PaymentPlans::reverseJournals((int)$j['source_id'],$reason,$date);$rid=Accounting::reverse($id,$reason,$date);if($doc&&in_array($doc['kind'],['REFUND','ADJUSTMENT'],true))PaymentPlans::sync((int)$doc['registration_id']);Audit::log('accounting_reversal','journal',$id,['reason'=>$reason,'reversal'=>$rid,'receipt_still_active'=>$j['source']==='PAYMENT']);return $rid;});
    }
    public static function cancelDraft(int $id,string $reason): void {
        Db::tx(function()use($id,$reason){self::lock();self::authorize('accounting.edit');if(PaymentPlans::managedJournal($id))throw new AppError('A managed payment-plan adjustment cannot be discarded.');$reason=self::reason($reason);$j=Db::one('SELECT * FROM sis_journal WHERE id=? FOR UPDATE',[$id]);if(!$j||$j['source']!=='MANUAL'||!in_array($j['status'],['DRAFT','REVIEW'],true))throw new AppError('Only an unposted manual draft can be discarded.');Db::q("UPDATE sis_journal SET status='CANCELLED',review_note=? WHERE id=?",[$reason,$id]);Audit::log('journal_discarded','journal',$id,['reason'=>$reason]);});
    }
    public static function receiptLocked(int $id): bool { return (bool)Db::val("SELECT id FROM sis_journal WHERE source='PAYMENT' AND source_id=? AND status='POSTED' AND reverses_id IS NULL AND reversed_by_id IS NULL",[$id]); }
    public static function receiptState(int $id): string {
        if(self::receiptLocked($id))return 'Posted · locked';
        if(Db::val("SELECT id FROM sis_journal WHERE source='PAYMENT' AND source_id=? AND reversed_by_id IS NOT NULL AND reverses_id IS NULL",[$id]))return 'Reversed · awaiting cashier void';
        return 'Needs Accounting review';
    }
    public static function period(string $from,string $to,string $reason,bool $close): void {
        Db::tx(function()use($from,$to,$reason,$close){self::lock();self::authorize('accounting.close');self::date($from);self::date($to);$reason=self::reason($reason);if($to<$from)throw new AppError('End date must follow the start date.');
            if($close){if(Db::val("SELECT id FROM sis_journal WHERE status IN('DRAFT','REVIEW') AND entry_date BETWEEN ? AND ?",[$from,$to]))throw new AppError('Review and post or discard pending entries in this period before closing.');
                if(Db::val("SELECT p.id FROM sis_payments p JOIN sis_journal j ON j.source='PAYMENT' AND j.source_id=p.id AND j.reverses_id IS NULL WHERE p.status='POSTED' AND j.reversed_by_id IS NOT NULL AND DATE(p.paid_at) BETWEEN ? AND ?",[$from,$to]))throw new AppError('Void receipts awaiting cashier correction before closing this period.');
                if(Db::val("SELECT l.id FROM sis_journal_lines l JOIN sis_journal j ON j.id=l.journal_id JOIN sis_accounts a ON a.id=l.account_id WHERE j.status='POSTED' AND a.is_cash=1 AND l.reconciliation_id IS NULL AND j.entry_date BETWEEN ? AND ?",[$from,$to]))throw new AppError('Reconcile the cash and bank lines in this period before closing.');
                if(Db::val("SELECT id FROM sis_accounting_periods WHERE status='CLOSED' AND start_date<=? AND end_date>=? AND NOT(start_date=? AND end_date=?)",[$to,$from,$from,$to]))throw new AppError('This range overlaps an existing closed period.');
                Db::q("INSERT INTO sis_accounting_periods(start_date,end_date,status,reason,changed_by) VALUES(?,?,'CLOSED',?,?) ON DUPLICATE KEY UPDATE status='CLOSED',reason=VALUES(reason),changed_by=VALUES(changed_by),changed_at=NOW()",[$from,$to,$reason,Auth::id()]);
            }else{if(!Db::val('SELECT id FROM sis_accounting_periods WHERE start_date=? AND end_date=?',[$from,$to]))throw new AppError('Choose the exact dates of an existing closed period.');Db::q("UPDATE sis_accounting_periods SET status='OPEN',reason=?,changed_by=?,changed_at=NOW() WHERE start_date=? AND end_date=?",[$reason,Auth::id(),$from,$to]);}
            Audit::log($close?'period_closed':'period_reopened','accounting',null,['from'=>$from,'to'=>$to,'reason'=>$reason]);});
    }
    private static function account(int $id,array $types=[],bool $cash=false): int {
        $a=Accounting::account($id);if(!$a||!$a['is_active']||($types&&!in_array($a['type'],$types,true))||($cash&&!$a['is_cash']))throw new AppError('Choose an active account of the correct type.');return $id;
    }
    public static function supplier(array $in): int {
        Auth::require('accounting.edit');$name=trim((string)($in['name']??''));$ref=trim((string)($in['reference_no']??''));if($name===''||mb_strlen($name)>120||$ref===''||mb_strlen($ref)>80)throw new AppError('Enter a supplier name and unique reference.');
        return Db::tx(function()use($name,$ref){$id=Db::insert('sis_accounting_suppliers',['name'=>$name,'reference_no'=>$ref]);Audit::log('supplier_created','supplier',$id,['name'=>$name]);return $id;});
    }
    public static function document(array $in): int {
        return Db::tx(function()use($in){self::lock();self::authorize('accounting.edit');$kind=(string)($in['kind']??'');if(!isset(self::KINDS[$kind])||$kind==='DEPRECIATION')throw new AppError('Choose a transaction type. Use the asset register for depreciation.');
            $ref=trim((string)($in['reference_no']??''));$memo=self::reason((string)($in['memo']??''));if($ref===''||mb_strlen($ref)>100)throw new AppError('Enter a stable source reference of up to 100 characters.');
            $existing=Db::one('SELECT journal_id FROM sis_accounting_documents WHERE kind=? AND reference_no=?',[$kind,$ref]);if($existing)return (int)$existing['journal_id'];
            $date=self::date((string)($in['entry_date']??''));[$fund,$dept]=self::dimensions($in);$c=self::money((string)($in['amount']??''));$reg=(int)($in['registration_id']??0);$payment=(int)($in['payment_id']??0);$supplier=(int)($in['supplier_id']??0);$bill=(int)($in['bill_id']??0);$direction=null;
            if($supplier&&!Db::val('SELECT id FROM sis_accounting_suppliers WHERE id=? AND is_active=1',[$supplier]))throw new AppError('Choose an active supplier.');
            $cash=(int)($in['cash_account_id']??0);$offset=(int)($in['account_id']??0);$ap=(int)($in['payable_account_id']??0);
            if($kind==='BILL'){if(!$supplier)throw new AppError('Choose the supplier.');$lines=[[self::account($offset,['EXPENSE','ASSET']),$c,0],[self::account($ap,['LIABILITY']),0,$c]];}
            elseif($kind==='DISBURSEMENT'){$b=Db::one("SELECT d.*,j.status,j.reversed_by_id,j.fund,j.department FROM sis_accounting_documents d JOIN sis_journal j ON j.id=d.journal_id WHERE d.id=? AND d.kind='BILL'",[$bill]);if(!$b||$b['status']!=='POSTED'||$b['reversed_by_id'])throw new AppError('Choose a posted supplier bill.');$supplier=(int)$b['supplier_id'];$fund=$b['fund'];$dept=$b['department'];$liability=(int)Db::val('SELECT account_id FROM sis_journal_lines WHERE journal_id=? AND credit>0',[$b['journal_id']]);$lines=[[$liability,$c,0],[self::account($cash,['ASSET'],true),0,$c]];}
            elseif(in_array($kind,['EXPENSE','REIMBURSEMENT'],true))$lines=[[self::account($offset,['EXPENSE','ASSET']),$c,0],[self::account($cash,['ASSET'],true),0,$c]];
            elseif($kind==='PAYROLL')$lines=[[self::account($offset,['EXPENSE']),$c,0],[self::account($ap,['LIABILITY']),0,$c]];
            elseif($kind==='ADJUSTMENT'){$r=Registration::get($reg);if($r['status']==='CANCELLED'||!$r['locked_at'])throw new AppError('Choose a confirmed assessment.');if($r['record_origin']==='MIGRATION')throw new AppError('Correct imported balances through Data Migration.');$direction=($in['direction']??'')==='LESS'?'LESS':'CHARGE';$ar=self::account((int)(Accounting::map()['ar']??0),['ASSET']);$offset=self::account($offset,$direction==='CHARGE'?['REVENUE']:['CONTRA_REVENUE','EXPENSE']);$lines=$direction==='CHARGE'?[[$ar,$c,0],[$offset,0,$c]]:[[$offset,$c,0],[$ar,0,$c]];}
            else{$p=Db::one('SELECT * FROM sis_payments WHERE id=?',[$payment]);if(!$p||$p['status']!=='POSTED'||!self::receiptLocked($payment))throw new AppError('Choose an active receipt already posted by Accounting.');$reg=(int)$p['registration_id'];$original=Accounting::activeEntry('PAYMENT',$payment);$fund=$original['fund'];$dept=$original['department'];$lines=[[self::account((int)(Accounting::map()['ar']??0),['ASSET']),$c,0],[self::account($cash,['ASSET'],true),0,$c]];}
            $id=Accounting::post($date,'MANUAL',null,$reg?:null,self::KINDS[$kind].' · '.$ref.' · '.$memo,$lines,null,'DRAFT',$fund,$dept);
            Db::insert('sis_accounting_documents',['kind'=>$kind,'reference_no'=>$ref,'journal_id'=>$id,'supplier_id'=>$supplier?:null,'bill_id'=>$bill?:null,'registration_id'=>$reg?:null,'payment_id'=>$payment?:null,'amount'=>Money::dec($c),'direction'=>$direction,'created_by'=>Auth::id()]);Audit::log('accounting_document_created','journal',$id,['kind'=>$kind,'reference'=>$ref]);return $id;});
    }
    private static function applyDocument(array $d,array $j,bool $reverse): void {
        $c=Money::cents($d['amount']);$kind=$d['kind'];
        if($kind==='BILL'&&$reverse&&Db::val("SELECT x.id FROM sis_accounting_documents x JOIN sis_journal z ON z.id=x.journal_id WHERE x.bill_id=? AND z.status='POSTED' AND z.reversed_by_id IS NULL",[$d['id']]))throw new AppError('Reverse supplier payments before reversing the bill.');
        if($kind==='DISBURSEMENT'&&!$reverse){$b=Db::one('SELECT d.*,j.status,j.reversed_by_id FROM sis_accounting_documents d JOIN sis_journal j ON j.id=d.journal_id WHERE d.id=? FOR UPDATE',[$d['bill_id']]);$paid=Money::cents(Db::val("SELECT COALESCE(SUM(x.amount),0) FROM sis_accounting_documents x JOIN sis_journal z ON z.id=x.journal_id WHERE x.bill_id=? AND z.status='POSTED' AND z.reversed_by_id IS NULL",[$d['bill_id']]));if(!$b||$b['status']!=='POSTED'||$b['reversed_by_id']||$paid+$c>Money::cents($b['amount']))throw new AppError('Supplier payment exceeds the unpaid bill or the bill was reversed.');}
        if($kind==='ADJUSTMENT'){$r=Db::one('SELECT * FROM sis_registrations WHERE id=? FOR UPDATE',[$d['registration_id']]);if(!$r||$r['status']==='CANCELLED'||!$r['locked_at'])throw new AppError('The source assessment is no longer confirmed.');$delta=($d['direction']==='LESS'?-$c:$c)*($reverse?-1:1);if(Registration::balance($r)+$delta<0)throw new AppError('This adjustment would make the assessment overpaid.');
            if($reverse&&Db::val("SELECT a.payment_id FROM sis_migration_payment_items a JOIN sis_payments p ON p.id=a.payment_id WHERE a.item_id=? AND p.status='POSTED'",[$d['item_id']]))throw new AppError('Void payments allocated to this charge before reversing it.');
            $item=Db::insert('sis_registration_items',['registration_id'=>$r['id'],'category_name'=>'Accounting adjustment','label_name'=>mb_substr(($reverse?'Reversal: ':'').$j['memo'],0,100),'charge_type'=>'ADJUSTMENT','quantity'=>1,'unit_amount'=>Money::dec($delta),'amount'=>Money::dec($delta),'sort_order'=>950]);Db::q('UPDATE sis_registrations SET total_amount=total_amount+? WHERE id=?',[Money::dec($delta),$r['id']]);Db::q('UPDATE sis_accounting_documents SET '.($reverse?'reversal_item_id':'item_id').'=? WHERE id=?',[$item,$d['id']]);
        }
        if($kind==='REFUND'){$p=Db::one('SELECT * FROM sis_payments WHERE id=? FOR UPDATE',[$d['payment_id']]);$r=Db::one('SELECT * FROM sis_registrations WHERE id=? FOR UPDATE',[$d['registration_id']]);if(!$p||$p['status']!=='POSTED'||!self::receiptLocked((int)$p['id']))throw new AppError('The original receipt must remain active and posted.');$refunded=Money::cents(Db::val("SELECT COALESCE(SUM(x.amount),0) FROM sis_accounting_documents x JOIN sis_journal z ON z.id=x.journal_id WHERE x.kind='REFUND' AND x.payment_id=? AND z.status='POSTED' AND z.reversed_by_id IS NULL",[$p['id']]));
            if(!$reverse&&($refunded+$c>Money::cents($p['amount'])||$c>Money::cents($r['amount_paid'])))throw new AppError('Refund exceeds the receipt amount remaining after previous refunds.');
            self::allocateRefund($d,$reverse);
            Db::q('UPDATE sis_registrations SET amount_paid=amount_paid+? WHERE id=?',[Money::dec($reverse?$c:-$c),$r['id']]);
        }
    }
    public static function budget(array $in): int {
        return Db::tx(function()use($in){self::lock();self::authorize('accounting.edit');$from=self::date((string)($in['start_date']??''));$to=self::date((string)($in['end_date']??''));if($to<$from)throw new AppError('Check the budget dates.');self::assertOpen($from);self::assertOpen($to);$sy=(string)($in['school_year']??'');if(!preg_match('/^(\d{4})-(\d{4})$/',$sy,$m)||(int)$m[2]!==((int)$m[1]+1))throw new AppError('Choose a valid school year.');[$fund,$dept]=self::dimensions($in);$account=self::account((int)($in['account_id']??0),['EXPENSE','REVENUE','CONTRA_REVENUE']);$amount=self::money((string)($in['amount']??''),false);
            Db::q('INSERT INTO sis_accounting_budgets(start_date,end_date,school_year,department,fund,account_id,amount,created_by) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount),created_by=VALUES(created_by)',[$from,$to,$sy,$dept,$fund,$account,Money::dec($amount),Auth::id()]);$id=(int)Db::val('SELECT id FROM sis_accounting_budgets WHERE start_date=? AND end_date=? AND school_year=? AND department=? AND fund=? AND account_id=?',[$from,$to,$sy,$dept,$fund,$account]);Audit::log('budget_saved','budget',$id,['amount'=>Money::dec($amount)]);return $id;});
    }
    public static function asset(array $in): int {
        return Db::tx(function()use($in){self::lock();self::authorize('accounting.edit');$code=trim((string)($in['asset_code']??''));$name=trim((string)($in['name']??''));if(!preg_match('/^[A-Za-z0-9_.-]{1,40}$/',$code)||$name===''||mb_strlen($name)>120)throw new AppError('Enter an asset code and name.');$date=self::date((string)($in['acquired_date']??''));$cost=self::money((string)($in['cost']??''));$residual=self::money((string)($in['residual']??'0'),false);$months=preg_match('/^\d{1,4}$/',trim((string)($in['useful_months']??'')))?(int)trim((string)$in['useful_months']):0;if($residual>=$cost||$months<1||$months>1200)throw new AppError('Residual value must be below cost; useful life is a whole number of months from 1 to 1,200.');[$fund,$dept]=self::dimensions($in);
            $id=Db::insert('sis_accounting_assets',['asset_code'=>$code,'name'=>$name,'acquired_date'=>$date,'cost'=>Money::dec($cost),'residual'=>Money::dec($residual),'useful_months'=>$months,'asset_account_id'=>self::account((int)($in['asset_account_id']??0),['ASSET']),'expense_account_id'=>self::account((int)($in['expense_account_id']??0),['EXPENSE']),'accumulated_account_id'=>self::account((int)($in['accumulated_account_id']??0),['ASSET']),'fund'=>$fund,'department'=>$dept,'created_by'=>Auth::id()]);Audit::log('asset_registered','asset',$id,['code'=>$code]);return $id;});
    }
    public static function depreciate(int $id,string $month): int {
        return Db::tx(function()use($id,$month){self::lock();self::authorize('accounting.edit');$date=self::date($month.'-01');$a=Db::one('SELECT * FROM sis_accounting_assets WHERE id=? FOR UPDATE',[$id]);if(!$a||$month<substr($a['acquired_date'],0,7))throw new AppError('Choose an asset and a month on or after acquisition.');$ref='ASSET-'.$id.'-'.$month;$existing=Db::val("SELECT journal_id FROM sis_accounting_documents WHERE kind='DEPRECIATION' AND reference_no=?",[$ref]);if($existing)return (int)$existing;
            $base=Money::cents($a['cost'])-Money::cents($a['residual']);$start=new DateTimeImmutable(substr($a['acquired_date'],0,7).'-01');$target=new DateTimeImmutable($date);$diff=$start->diff($target);$n=$diff->y*12+$diff->m;if($n>=(int)$a['useful_months'])throw new AppError('This month is outside the asset useful life.');$c=intdiv($base*($n+1),(int)$a['useful_months'])-intdiv($base*$n,(int)$a['useful_months']);if($c<=0)throw new AppError('No depreciation is due for this month.');$jid=Accounting::post(date('Y-m-t',strtotime($date)),'MANUAL',null,null,'Depreciation · '.$a['asset_code'].' · '.$month,[[(int)$a['expense_account_id'],$c,0],[(int)$a['accumulated_account_id'],0,$c]],null,'DRAFT',$a['fund'],$a['department']);Db::insert('sis_accounting_documents',['kind'=>'DEPRECIATION','reference_no'=>$ref,'journal_id'=>$jid,'amount'=>Money::dec($c),'created_by'=>Auth::id()]);Audit::log('depreciation_drafted','journal',$jid,['asset'=>$id,'month'=>$month]);return $jid;});
    }
    /** Fee-level balances for confirmed assessments; legacy unallocated payments are applied FIFO. */
    public static function feeBalances(array $r): array {
        $items=Db::all('SELECT * FROM sis_registration_items WHERE registration_id=? AND amount>0 ORDER BY sort_order,id',[$r['id']]);
        if(($r['record_origin']??'')==='MIGRATION'){foreach($items as &$item)$item['remaining']=StudentMigration::itemBalance($item);unset($item);return $items;}
        $exclude=Db::col("SELECT CASE WHEN d.direction='CHARGE' THEN d.item_id ELSE d.reversal_item_id END FROM sis_accounting_documents d JOIN sis_journal j ON j.id=d.journal_id WHERE d.registration_id=? AND d.kind='ADJUSTMENT' AND j.reversed_by_id IS NOT NULL",[$r['id']]);$items=array_values(array_filter($items,fn($item)=>!in_array($item['id'],$exclude)));
        $gross=array_sum(array_map(fn($x)=>Money::cents($x['amount']),$items));if(!$gross)return [];
        $net=Money::cents($r['total_amount']);$paid=[];
        foreach(Db::all("SELECT a.item_id,SUM(a.amount) AS amount FROM sis_payment_allocations a JOIN sis_payments p ON p.id=a.payment_id WHERE p.registration_id=? AND p.status='POSTED' GROUP BY a.item_id",[$r['id']]) as $a)$paid[(int)$a['item_id']]=Money::cents($a['amount']);
        foreach(Db::all("SELECT a.item_id,SUM(a.amount) AS amount FROM sis_refund_allocations a JOIN sis_accounting_documents d ON d.id=a.document_id JOIN sis_journal j ON j.id=d.journal_id WHERE d.registration_id=? AND j.status='POSTED' AND j.reversed_by_id IS NULL GROUP BY a.item_id",[$r['id']]) as $a)$paid[(int)$a['item_id']]=($paid[(int)$a['item_id']]??0)-Money::cents($a['amount']);
        // Only current fee IDs participate after an authorized assessment rebuild.
        $current=array_intersect_key($paid,array_flip(array_map(fn($x)=>(int)$x['id'],$items)));$legacy=max(0,Money::cents($r['amount_paid'])-array_sum($current));$cumulative=0;$previous=0;
        $planCharges=PaymentPlans::feeCharges($r,$items);
        foreach($items as &$item){$charge=$planCharges[(int)$item['id']];$paidForFee=$current[(int)$item['id']]??0;$item['remaining']=max(0,$charge-$paidForFee);$legacy+=max(0,$paidForFee-$charge);$item['net_charge']=$charge;}unset($item);
        foreach($items as &$item){$part=min($legacy,$item['remaining']);$legacy-=$part;$item['remaining']-=$part;}unset($item);return $items;
    }
    public static function allocatePayment(int $payment,array $r,int $cents,?int $selected): void {
        if(($r['record_origin']??'')==='MIGRATION'){StudentMigration::allocatePayment($payment,$r,$cents,$selected);return;}
        if(!$r['locked_at']){if($selected)throw new AppError('Confirm the assessment before choosing a specific fee.');return;}
        // The registration amount_paid already includes this payment. Compute availability before it.
        $before=$r;$before['amount_paid']=Money::dec(Money::cents($r['amount_paid']));$items=self::feeBalances($before);
        if($selected){$items=array_values(array_filter($items,fn($x)=>(int)$x['id']===$selected));if(!$items||$items[0]['remaining']<$cents)throw new AppError('The payment exceeds the selected fee balance.');}
        foreach($items as $item){$part=min($cents,$item['remaining']);if($part>0){Db::insert('sis_payment_allocations',['payment_id'=>$payment,'item_id'=>$item['id'],'category_name'=>$item['category_name'],'fee_name'=>$item['label_name'],'amount'=>Money::dec($part)]);$cents-=$part;}if(!$cents)break;}
        if($cents)throw new AppError('Fee allocations do not match the balance. Reload the assessment.');
    }
    private static function allocateRefund(array $d,bool $reverse): void {
        if($reverse)return; // Reversed refunds are excluded from fee-balance totals.
        $payment=(int)$d['payment_id'];$rows=Db::all('SELECT item_id,amount FROM sis_payment_allocations WHERE payment_id=? UNION ALL SELECT item_id,amount FROM sis_migration_payment_items WHERE payment_id=?',[$payment,$payment]);$left=Money::cents($d['amount']);
        foreach($rows as $a){$already=Money::cents(Db::val("SELECT COALESCE(SUM(a.amount),0) FROM sis_refund_allocations a JOIN sis_accounting_documents d ON d.id=a.document_id JOIN sis_journal j ON j.id=d.journal_id WHERE d.payment_id=? AND a.item_id=? AND j.status='POSTED' AND j.reversed_by_id IS NULL",[$payment,$a['item_id']]));$part=min($left,max(0,Money::cents($a['amount'])-$already));if($part){Db::insert('sis_refund_allocations',['document_id'=>$d['id'],'item_id'=>$a['item_id'],'amount'=>Money::dec($part)]);$left-=$part;}if(!$left)break;}
    }
    public static function dashboard(): array {
        return ['review'=>(int)Db::val("SELECT COUNT(*) FROM sis_journal WHERE status='REVIEW'"),'draft'=>(int)Db::val("SELECT COUNT(*) FROM sis_journal WHERE status='DRAFT'"),'collections'=>Money::cents(Db::val("SELECT COALESCE(SUM(l.debit-l.credit),0) FROM sis_journal_lines l JOIN sis_journal j ON j.id=l.journal_id JOIN sis_accounts a ON a.id=l.account_id WHERE j.source='PAYMENT' AND j.status='POSTED' AND a.is_cash=1 AND j.entry_date=CURDATE()")),'receivables'=>Money::cents(Db::val("SELECT COALESCE(SUM(GREATEST(total_amount-amount_paid,0)),0) FROM sis_registrations WHERE status<>'CANCELLED'")),'uncleared'=>(int)Db::val("SELECT COUNT(*) FROM sis_journal_lines l JOIN sis_journal j ON j.id=l.journal_id JOIN sis_accounts a ON a.id=l.account_id WHERE j.status='POSTED' AND a.is_cash=1 AND l.reconciliation_id IS NULL"),'corrections'=>(int)Db::val("SELECT COUNT(*) FROM sis_payments p WHERE p.status='POSTED' AND EXISTS(SELECT 1 FROM sis_journal j WHERE j.source='PAYMENT' AND j.source_id=p.id AND j.reverses_id IS NULL AND j.reversed_by_id IS NOT NULL)")];
    }
}
