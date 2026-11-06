<?php
declare(strict_types=1);
/** Saved terms are immutable per registration. Schedules allocate existing charges, never new revenue. */
final class PaymentPlans {
    private static ?array $managedUi=null;
    public static function managedForUi(int $id): bool {if(self::$managedUi===null)self::$managedUi=array_fill_keys(array_map('intval',Db::col('SELECT journal_id FROM sis_payment_plan_events WHERE journal_id IS NOT NULL')),true);return isset(self::$managedUi[$id]);}
    public static function basis(string $v): int {if(!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D',trim($v)))throw new AppError('Enter a percentage with at most two decimal places.');$n=Money::cents($v);if($n<=0||$n>10000)throw new AppError('Percentages must be greater than zero and at most 100.');return $n;}
    public static function save(array $in): int {
        Auth::require('users.manage');$name=trim((string)($in['name']??''));if($name===''||mb_strlen($name)>120)throw new AppError('Enter a plan name of up to 120 characters.');
        $kind=($in['kind']??'')==='FULL'?'FULL':'INSTALLMENT';$steps=[];$sum=0;
        foreach((array)($in['steps']??[]) as $s){if(trim((string)($s['label']??''))===''&&trim((string)($s['percent']??''))==='')continue;$label=trim((string)($s['label']??''));if($label===''||mb_strlen($label)>60)throw new AppError('Give each installment a short label.');$date=AccountingWorkflow::date((string)($s['due']??''));$bp=self::basis((string)($s['percent']??''));$sum+=$bp;$steps[]=['label'=>$label,'bp'=>$bp,'due'=>$date];}
        if($kind==='FULL')$steps=[['label'=>'Full payment','bp'=>10000,'due'=>AccountingWorkflow::date((string)($in['deadline']??''))]];
        elseif(count($steps)<2||count($steps)>24||$sum!==10000)throw new AppError('Use 2–24 installments whose percentages total exactly 100%.');
        $last='';foreach($steps as $s){if($s['due']<$last)throw new AppError('Installment due dates must be in order.');$last=$s['due'];}
        $labels=array_values(array_unique(array_map('intval',(array)($in['labels']??[]))));$scope=[];foreach($labels as $id){$l=Db::one('SELECT l.name,c.name AS category FROM sis_fee_labels l JOIN sis_fee_categories c ON c.id=l.category_id WHERE l.id=?',[$id]);if(!$l)throw new AppError('Choose valid fee labels.');$scope[]=$l+['id'=>$id];}
        $mode=($in['discount_mode']??'PERCENT')==='FIXED'?'FIXED':'PERCENT';$value=0;if($kind==='FULL'&&trim((string)($in['discount']??''))!=='')$value=$mode==='PERCENT'?self::basis((string)$in['discount']):Money::cents((string)$in['discount']);if($value<0||$value>99999999999)throw new AppError('Check the discount amount.');
        if($kind==='FULL'&&$mode==='PERCENT'&&$value>=10000)throw new AppError('A full-payment discount must be below 100%; use scholarships for a fully waived assessment.');
        $sy=trim((string)($in['sy']??''));if($sy!==''&&!GS::validSchoolYear($sy))throw new AppError('Choose a valid school year.');$term=trim((string)($in['term']??''));if($term!==''&&!in_array($term,TERMS,true))throw new AppError('Choose a valid semester.');$level=trim((string)($in['level']??''));if($level!==''&&!in_array($level,YEAR_LEVELS,true))throw new AppError('Choose a valid year level.');
        $rules=['steps'=>$steps,'scope'=>$scope,'discount_mode'=>$mode,'discount'=>$value,'stack'=>!empty($in['stack']),'apply_now'=>$kind==='FULL'&&!empty($in['apply_now']),'sy'=>$sy,'term'=>$term,'program'=>trim((string)($in['program']??'')),'level'=>$level];
        return Db::tx(function()use($in,$name,$kind,$rules){$id=(int)($in['id']??0);$data=['name'=>$name,'kind'=>$kind,'rules'=>json_encode($rules,JSON_THROW_ON_ERROR),'is_active'=>!empty($in['is_active'])?1:0];if($id){if(!Db::val('SELECT id FROM sis_payment_plans WHERE id=?',[$id]))throw new AppError('Payment plan not found.');Db::update('sis_payment_plans',$data,$id);}else $id=Db::insert('sis_payment_plans',$data+['created_by'=>Auth::id()]);Audit::log('payment_plan_saved','payment_plan',$id);return $id;});
    }
    public static function applicable(array $r,array $rules): bool {foreach(['sy'=>'school_year','term'=>'term','program'=>'program','level'=>'year_level'] as $a=>$b)if(($rules[$a]??'')!==''&&strcasecmp((string)$rules[$a],(string)($r[$b]??''))!==0)return false;return true;}
    public static function assign(int $rid,int $pid): void {
        Auth::require('registration');Db::tx(function()use($rid,$pid){AccountingWorkflow::lock();$r=Db::one('SELECT * FROM sis_registrations WHERE id=? FOR UPDATE',[$rid]);if(!$r||$r['status']==='CANCELLED'||($r['record_origin']??'ENROLLMENT')!=='ENROLLMENT'||Money::cents($r['amount_paid'])!==0||Db::val('SELECT id FROM sis_payments WHERE registration_id=?',[$rid]))throw new AppError('Choose the payment plan before the first payment of the enrollment.');
            $old=self::saved($rid);if($old&&(int)$old['earned_cents']){Db::q('UPDATE sis_registration_plans SET snapshot=JSON_SET(snapshot,?,false,?,0) WHERE registration_id=?',['$.apply_now','$.discount',$rid]);self::sync($rid);} // v56.4: take back a discount applied when chosen
            if(!$pid){Db::q('DELETE FROM sis_registration_plans WHERE registration_id=?',[$rid]);Audit::log('payment_plan_removed','registration',$rid);return;}
            $p=Db::one('SELECT * FROM sis_payment_plans WHERE id=? AND is_active=1',[$pid]);if(!$p)throw new AppError('Choose an active payment plan.');$rules=json_decode($p['rules'],true,512,JSON_THROW_ON_ERROR);if(!self::applicable($r,$rules))throw new AppError('This plan does not apply to this school year, term, program or year level.');if(!$rules['stack']&&$p['kind']==='FULL'&&self::hasOtherDiscounts($rid))throw new AppError('This full-payment offer cannot be combined with scholarships. Choose another plan.');
            $snapshot=$rules+['name'=>$p['name'],'kind'=>$p['kind']];Db::q('INSERT INTO sis_registration_plans(registration_id,plan_id,snapshot,assigned_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE plan_id=VALUES(plan_id),snapshot=VALUES(snapshot),assigned_by=VALUES(assigned_by),assigned_at=NOW()',[$rid,$pid,json_encode($snapshot,JSON_THROW_ON_ERROR),Auth::id()]);if($p['kind']==='FULL'&&($snapshot['discount']??0)>0&&self::discount($r,['terms'=>$snapshot,'earned_cents'=>0])===0)throw new AppError('The full-payment offer must leave a positive amount to pay.');Audit::log('payment_plan_assigned','registration',$rid,['plan'=>$pid]);
            self::sync($rid); // v56.4: a plan with "deduct as soon as chosen" lowers the balance now
        });
    }
    /**
     * v56: why the Cashier may not take a payment yet, or null. An enrollment needs its payment plan before its
     * first payment (Settings → Cashier). Opening balances from Data Migration and registrations that already have
     * a payment (recorded before this rule) are never blocked, so collections on existing balances continue.
     */
    public static function paymentBlock(array $r): ?string {
        if (!Settings::on('cashier_require_plan') || ($r['record_origin'] ?? 'ENROLLMENT') !== 'ENROLLMENT' || ($r['status'] ?? '') === 'CANCELLED') return null;
        if (self::saved((int) $r['id'])) return null;
        if (Db::val('SELECT 1 FROM sis_payments WHERE registration_id = ? LIMIT 1', [(int) $r['id']])) return null;
        // Registrations made before this rule was installed (upgrade from v55 or earlier) are not blocked.
        $since = (string) Db::val("SELECT svalue FROM sis_settings WHERE skey = 'cashier_plan_rule_since'");
        if ($since !== '' && (string) ($r['created_at'] ?? '') !== '' && (string) $r['created_at'] < $since) return null;
        $any = (bool) array_filter(Db::all('SELECT rules FROM sis_payment_plans WHERE is_active = 1'), fn($p) => self::applicable($r, json_decode((string) $p['rules'], true) ?: []));
        return 'No payment plan is selected for ' . $r['student_name'] . ' (' . $r['reg_no'] . '). Choose the payment plan (Full payment or Installment) on the registration before receiving the first payment.'
            . ($any ? '' : ' No active payment plan applies to this term yet: an administrator adds one in Assessment → Labels → Payment plans.');
    }
    /** v56: remembers when the payment-plan rule started (registrations made earlier are not blocked). Repeatable. */
    public static function installRule(): void {
        Db::q("INSERT IGNORE INTO sis_settings (skey, svalue) VALUES ('cashier_plan_rule_since', ?)", [date('Y-m-d H:i:s')]);
    }
    public static function saved(int $rid): ?array { $p=Db::one('SELECT * FROM sis_registration_plans WHERE registration_id=?',[$rid]);if(!$p)return null;$p['terms']=json_decode($p['snapshot'],true,512,JSON_THROW_ON_ERROR);return $p; }
    private static function hasOtherDiscounts(int $rid,int $own=0): bool {return (bool)Db::val('SELECT id FROM sis_registration_discounts WHERE registration_id=? LIMIT 1',[$rid])||(bool)Db::val('SELECT id FROM sis_registration_items WHERE registration_id=? AND amount<0 AND id<>? LIMIT 1',[$rid,$own]);}
    public static function discount(array $r,array $p): int {
        $s=$p['terms'];if($s['kind']!=='FULL'||!$s['discount'])return 0;if(!$s['stack']&&self::hasOtherDiscounts((int)$r['id'],(int)($p['discount_item_id']??0)))return 0;
        $base=Money::cents($r['total_amount'])+(int)$p['earned_cents'];$gross=0;$eligible=0;$pairs=self::scopePairs($s);
        foreach(Db::all('SELECT * FROM sis_registration_items WHERE registration_id=? AND amount>0',[$r['id']]) as $i){$c=Money::cents($i['amount']);$gross+=$c;$yes=!$s['scope']||isset($pairs[$i['category_name']."\0".$i['label_name']]);if($yes)$eligible+=$c;}
        // Scholarships and other reductions are distributed proportionally before the plan offer.
        $eligible=$gross?min($eligible,AccountingWorkflow::mulDiv($eligible,max(0,$base),$gross)):0;
        $candidate=min(max(0,$base),$s['discount_mode']==='PERCENT'?AccountingWorkflow::mulDiv($eligible,(int)$s['discount'],10000):min($eligible,(int)$s['discount']));return $candidate>0&&$candidate<$base?$candidate:0;
    }
    public static function beforePayment(array $r,int $cents): void { $p=self::saved((int)$r['id']);if(!$p)return;$s=$p['terms'];if($s['kind']!=='FULL'||date('Y-m-d')>$s['steps'][0]['due'])return;$off=self::discount($r,$p);$target=Money::cents($r['total_amount'])+(int)$p['earned_cents']-$off;$paid=Money::cents($r['amount_paid']);if($off&&$paid+$cents>$target)throw new AppError('The full-payment offer requires only ₱'.Money::fmt(max(0,$target-$paid)).' more. Enter that amount to avoid overpayment.'); }
    public static function reverseJournals(int $rid,string $reason,string $date): void { foreach(Db::all("SELECT j.id FROM sis_payment_plan_events e JOIN sis_journal j ON j.id=e.journal_id WHERE e.registration_id=? AND j.status IN('POSTED','REVIEW','DRAFT') AND j.reversed_by_id IS NULL",[$rid]) as $j)Accounting::reverse((int)$j['id'],'Assessment correction: '.$reason,$date); }
    public static function managedJournal(int $id): bool {return (bool)Db::val('SELECT id FROM sis_payment_plan_events WHERE journal_id=?',[$id]);}
    public static function sync(int $rid): void {
        // Called only from transactional payment/refund/recompute services, never from page rendering.
        $r=Db::one('SELECT * FROM sis_registrations WHERE id=? FOR UPDATE',[$rid]);$p=self::saved($rid);if(!$r||!$p||$p['terms']['kind']!=='FULL')return;
        $old=(int)$p['earned_cents'];$off=self::discount($r,$p);$base=Money::cents($r['total_amount'])+$old;
        $onTime=Money::cents(Db::val("SELECT COALESCE(SUM(amount),0) FROM sis_payments WHERE registration_id=? AND status='POSTED' AND DATE(paid_at)<=?",[$rid,$p['terms']['steps'][0]['due']]));
        $paid=Money::cents($r['amount_paid']);$new=$off&&(!empty($p['terms']['apply_now'])||min($paid,$onTime)>=$base-$off)?$off:0;if($r['status']==='CANCELLED')$new=0;if($new===$old)return;$delta=$new-$old;
        $a=Db::one("SELECT * FROM sis_portal_enrollments WHERE registration_id=? AND status='ACCEPTED'",[$rid]);$published=$a&&PortalEnrollment::published($a,$r);
        $item=(int)$p['discount_item_id'];$data=['registration_id'=>$rid,'category_name'=>'Payment plan discount','label_name'=>mb_substr('Full payment: '.$p['terms']['name'],0,100),'charge_type'=>'ADJUSTMENT','quantity'=>1,'unit_amount'=>Money::dec(-$new),'amount'=>Money::dec(-$new),'sort_order'=>960];if($item)Db::update('sis_registration_items',$data,$item);else $item=Db::insert('sis_registration_items',$data);
        Db::q('UPDATE sis_registrations SET total_amount=? WHERE id=?',[Money::dec($base-$new),$rid]);Db::q('UPDATE sis_registration_plans SET earned_cents=?,discount_item_id=? WHERE registration_id=?',[$new,$item,$rid]);
        $journal=null;
        if($r['locked_at']&&Accounting::activeEntry('ASSESSMENT',$rid)){$map=Accounting::map();$less=(int)($map['default_discount']??0);$ar=(int)($map['ar']??0);$source=Accounting::activeEntry('ASSESSMENT',$rid);$c=abs($delta);$journal=Accounting::post(date('Y-m-d'),'MANUAL',null,$rid,($delta>0?'Earned':'Withdrawn').' full-payment discount · '.$r['reg_no'],[[$less,$delta>0?$c:0,$delta<0?$c:0],[$ar,$delta<0?$c:0,$delta>0?$c:0]],null,'REVIEW',$source['fund'],$source['department']);}
        self::$managedUi=null;Db::insert('sis_payment_plan_events',['registration_id'=>$rid,'delta_cents'=>$delta,'journal_id'=>$journal,'actor_id'=>Auth::id()]);Audit::log('payment_plan_discount_changed','registration',$rid,['before'=>$old,'after'=>$new,'journal'=>$journal]);
        if($published)Db::q('UPDATE sis_portal_enrollments SET assessment_hash=? WHERE id=?',[PortalEnrollment::fingerprint(Registration::get($rid)),$a['id']]);
    }
    private static function scopePairs(array $terms): array {
        $pairs=[];$ids=[];foreach($terms['scope'] as $s){$pairs[$s['category']."\0".$s['name']]=true;if(!empty($s['id']))$ids[]=(int)$s['id'];}
        if($ids)foreach(Db::all('SELECT l.name,c.name AS category FROM sis_fee_labels l JOIN sis_fee_categories c ON c.id=l.category_id WHERE l.id IN '.Db::in($ids),$ids) as $s)$pairs[$s['category']."\0".$s['name']]=true;return $pairs;
    }
    public static function feeCharges(array $r,array $items): array {
        $p=self::saved((int)$r['id']);$earned=(int)($p['earned_cents']??0);$base=Money::cents($r['total_amount'])+$earned;$gross=array_sum(array_map(fn($i)=>Money::cents($i['amount']),$items));$charges=[];$eligible=[];$cumulative=0;$previous=0;$pairs=$p?self::scopePairs($p['terms']):[];
        foreach($items as $item){$cumulative+=Money::cents($item['amount']);$end=$gross?AccountingWorkflow::mulDiv($base,$cumulative,$gross):0;$id=(int)$item['id'];$charges[$id]=$end-$previous;$previous=$end;$yes=$p&&(!$p['terms']['scope']||isset($pairs[$item['category_name']."\0".$item['label_name']]));if($yes)$eligible[$id]=$charges[$id];}
        $all=array_sum($eligible);$running=0;$previous=0;foreach($eligible as $id=>$amount){$running+=$amount;$end=$all?AccountingWorkflow::mulDiv($earned,$running,$all):0;$charges[$id]-=$end-$previous;$previous=$end;}return $charges;
    }
    public static function schedule(array $r): array { $p=self::saved((int)$r['id']);return $p?self::scheduleFor($r,$p):[]; }
    /**
     * v56.2: what a plan WOULD look like on this registration, before it is saved (live preview on the assessment).
     * Same figures as schedule() after saving; nothing is written.
     */
    public static function preview(array $r,int $pid): array {
        $p=Db::one('SELECT * FROM sis_payment_plans WHERE id=? AND is_active=1',[$pid]);if(!$p)throw new AppError('Choose an active payment plan.');
        $rules=json_decode($p['rules'],true,512,JSON_THROW_ON_ERROR);if(!self::applicable($r,$rules))throw new AppError('This plan does not apply to this school year, term, program or year level.');
        return self::scheduleFor($r,['plan_id'=>$pid,'terms'=>$rules+['name'=>$p['name'],'kind'=>$p['kind']],'earned_cents'=>0,'discount_item_id'=>0,'preview'=>true]);
    }
    private static function scheduleFor(array $r,array $p): array {
        $s=$p['terms'];$total=Money::cents($r['total_amount']);$paid=max(0,Money::cents($r['amount_paid']));$rows=[];$used=0;$basisUsed=0;$due=0;$overdue=0;$next=null;
        foreach($s['steps'] as $index=>$step){$basisUsed+=$step['bp'];$end=$index===count($s['steps'])-1?$total:AccountingWorkflow::mulDiv($total,$basisUsed,10000);$amount=$end-$used;$used=$end;$got=min($paid,$amount);$paid-=$got;$remaining=$amount-$got;$late=$remaining>0&&$step['due']<date('Y-m-d');if($step['due']<=date('Y-m-d'))$due+=$remaining;if($late)$overdue+=$remaining;if($remaining&&$next===null)$next=$step['due'];$rows[]=$step+['amount'=>$amount,'paid'=>$got,'remaining'=>$remaining,'status'=>$remaining===0?'Paid':($late?'Overdue':($got?'Partial':'Unpaid'))];}
        return ['saved'=>$p,'rows'=>$rows,'due'=>$due,'overdue'=>$overdue,'next'=>$next,'offer'=>self::discount($r,$p),'expired'=>date('Y-m-d')>$s['steps'][0]['due']];
    }
}
