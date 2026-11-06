<?php
declare(strict_types=1);

/** Own-record applications; registrar approval reuses the existing registration service. */
final class PortalEnrollment
{
    /** Additive upgrade; old applications keep their original records and nullable placement fields. */
    public static function install(): void
    {
        $columns=[
            'current_enrollment'=>'LONGTEXT NULL',
            'requested_year_level'=>'VARCHAR(30) NULL',
            'preferred_section_doc_id'=>'VARCHAR(191) NULL',
            'preferred_section_name'=>'VARCHAR(150) NULL',
            'approved_placement'=>'LONGTEXT NULL',
        ];
        foreach ($columns as $column=>$type) {
            if (Db::val('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',['sis_portal_enrollments',$column])) continue;
            try { Db::q("ALTER TABLE sis_portal_enrollments ADD COLUMN `$column` $type"); }
            catch (PDOException $e) {
                // Two sessions may discover the same missing column during deployment.
                if ((int)($e->errorInfo[1]??0)!==1060) throw $e;
            }
        }
    }

    /** Use confirmed enrollment or completed-grade evidence, never assignment semester labels. */
    public static function currentEnrollment(array $student): array
    {
        $current=['school_year'=>(string)($student['schoolYear']??''),'term'=>'','year_level'=>(string)($student['yearLevel']??''),'section_doc_id'=>(string)($student['sectionId']??''),'section_name'=>(string)($student['sectionName']??''),'source'=>'directory'];
        $r=Db::one("SELECT school_year,term,year_level,section_doc_id,section_name FROM sis_registrations WHERE student_doc_id=? AND student_number=? AND status='REGISTERED' AND locked_at IS NOT NULL AND record_origin='ENROLLMENT' ORDER BY school_year DESC,FIELD(term,'1st Semester','2nd Semester','3rd Semester','Summer') DESC,id DESC LIMIT 1",[$student['id'],$student['studentId']]);
        if ($r) $current=$r+['source'=>'enrollment'];
        foreach (GS::completions($student['id']) as $c) {
            $sy=(string)($c['schoolYear']??'');$term=(string)($c['term']??'');
            if (!GS::validSchoolYear($sy) || !in_array($term,TERMS,true) || !empty($c['migrationReversed'])) continue;
            if ($current['source']!=='directory' && self::periodKey($sy,$term)<=self::periodKey($current['school_year'],$current['term'])) continue;
            // Do not replace a later directory year with an older historical grade record.
            if ($current['source']==='directory' && $sy<$current['school_year'] && empty($student['promotionState'])) continue;
            $current=['school_year'=>$sy,'term'=>$term,'year_level'=>(string)($c['yearLevel']??$current['year_level']),'section_doc_id'=>(string)($c['sectionId']??$current['section_doc_id']),'section_name'=>(string)($c['sectionName']??$current['section_name']),'source'=>'grades'];
        }
        return $current;
    }

    public static function promotionReady(array $student,string $schoolYear): bool
    {
        foreach (GS::completions($student['id']) as $c) {
            if (($c['schoolYear']??'')!==$schoolYear || ($c['term']??'')!=='2nd Semester' || empty($c['subjects']) || !empty($c['pending']) || !empty($c['incomplete']) || !empty($c['migrationReversed'])) continue;
            $passed=true;
            foreach ($c['subjects'] as $subject) if (strcasecmp((string)($subject['remarks']??''),'Passed')!==0 || !empty($subject['mark'])) $passed=false;
            if ($passed) return true;
        }
        return false;
    }

    /** The server owns the available levels and sections; changing a semester never promotes by itself. */
    public static function placementOptions(array $student,string $sy,string $term): array
    {
        if (!GS::validSchoolYear($sy) || !in_array($term,TERMS,true)) return ['levels'=>[],'sections'=>[]];
        $current=self::currentEnrollment($student);
        $base=array_search($current['year_level'],YEAR_LEVELS,true);
        if ($base===false || $sy<$current['school_year']) return ['levels'=>[],'sections'=>[]];
        $levels=[$current['year_level']];
        if ($sy>$current['school_year'] && $term==='1st Semester' && isset(YEAR_LEVELS[$base+1]) && self::promotionReady($student,$current['school_year'])) $levels[]=YEAR_LEVELS[$base+1];
        $program=strtoupper((string)GS::programOf($current['section_name']));
        $sections=array_values(array_filter(GS::sections($sy),function($s)use($levels,$program,$student,$sy){
            if (!in_array($s['yearLevel']??'', $levels,true)) return false;
            if ($program!=='' && strtoupper((string)GS::programOf((string)($s['sectionName']??'')))!==$program) return false;
            if (($student['promotionState']??'')==='PROMOTED_PENDING_REGISTRATION' && ($student['schoolYear']??'')===$sy && ($student['sectionId']??'')!==$s['id']) return false;
            return true;
        }));
        $levels=array_values(array_filter($levels,fn($level)=>(bool)array_filter($sections,fn($s)=>$s['yearLevel']===$level)));
        return ['levels'=>$levels,'sections'=>array_map(fn($s)=>['id'=>$s['id'],'year_level'=>$s['yearLevel'],'name'=>$s['sectionName']],$sections)];
    }

    public static function validatePlacement(array $student,string $sy,string $term,string $level,string $preferred=''): ?array
    {
        $options=self::placementOptions($student,$sy,$term);
        if (!in_array($level,$options['levels'],true)) throw new AppError('Choose an available year level. Moving to the next year requires a later school year, First Semester, and all second-semester subjects finalized as Passed. Contact the registrar if your placement needs review.');
        if ($preferred==='') return null;
        foreach ($options['sections'] as $section) if ($section['id']===$preferred && $section['year_level']===$level) return $section;
        throw new AppError('Choose a preferred section matching the application school year, year level and current program, or let the registrar assign one.');
    }

    /** Apply an accepted placement only when the existing enrollment-confirmation transaction succeeds. */
    public static function applyPlacement(int $rid): void
    {
        $a=Db::one("SELECT * FROM sis_portal_enrollments WHERE registration_id=? AND status='ACCEPTED' FOR UPDATE",[$rid]);
        if (!$a) return;
        $r=Registration::get($rid);
        $student=GS::get('students',(string)$a['student_doc_id']);
        if (!$student || (string)$student['studentId']!==(string)$a['student_number']) throw new AppError('Verify the linked student identity before confirming enrollment.');
        if (($student['status']??'')==='graduated') throw new AppError('A graduated student cannot be confirmed through continuing enrollment.');
        if (($student['schoolYear']??'')>$r['school_year']) throw new AppError('The student has already moved to a later school year. Review the current placement before confirming.');
        if (Db::val("SELECT id FROM sis_registrations WHERE student_doc_id=? AND id<>? AND status='REGISTERED' AND locked_at IS NOT NULL AND record_origin='ENROLLMENT' AND (school_year>? OR (school_year=? AND FIELD(term,'1st Semester','2nd Semester','3rd Semester','Summer')>FIELD(?,'1st Semester','2nd Semester','3rd Semester','Summer'))) LIMIT 1",[$student['id'],$rid,$r['school_year'],$r['school_year'],$r['term']])) throw new AppError('A later enrollment is already confirmed. Contact the registrar before changing the current placement.');
        $approved=json_decode((string)($a['approved_placement']??''),true);
        // Older accepted applications retain their original confirmation workflow.
        if ($approved) {
            if (($approved['section_doc_id']??'')!==$r['section_doc_id'] || ($approved['year_level']??'')!==$r['year_level']) throw new AppError('The registration placement differs from the approved application. Review it before confirming.');
            self::validatePlacement($student,$r['school_year'],$r['term'],$r['year_level'],$r['section_doc_id']);
        }
        if (($student['sectionId']??'')!==$r['section_doc_id'] || ($student['schoolYear']??'')!==$r['school_year'] || ($student['yearLevel']??'')!==$r['year_level']) {
            GS::saveStudent($student['id'],['studentId'=>$student['studentId'],'studentName'=>$student['studentName'],'email'=>$student['email']??'','sectionId'=>$r['section_doc_id']]);
        }
        GS::clearPromotion($student['id']);
    }

    public static function periodKey(string $sy, string $term): array
    {
        return [$sy, ($i = array_search($term, TERMS, true)) === false ? -1 : $i];
    }

    public static function eligibility(array $student, string $sy, string $term): array
    {
        if (!GS::validSchoolYear($sy) || !in_array($term, TERMS, true)) throw new AppError('Choose a valid enrollment period.');
        $number = (string)$student['studentId'];
        $result = EnrollmentEligibility::check($student['id'], $number, $sy, $term);
        // Readmission approval is the outcome of this application, not a submission prerequisite.
        $checks = array_values(array_filter($result['checks'], fn($c) => !in_array($c['code'], ['readmission','grades','incomplete'], true)));
        $add = function(string $code, string $state, string $message) use (&$checks): void {
            $checks[] = ['code'=>$code,'label'=>'Academic readiness','state'=>$state,'message'=>$message,'office'=>'Registrar','route'=>'','params'=>[],'scope'=>'ENROLLMENT','subject'=>null,'overrideable'=>false];
        };
        if (strtolower((string)($student['status']??''))==='graduated') $add('graduated','BLOCKED','Your record is marked Graduated. Contact the registrar about further admission.');
        $calendar = TermCalendar::get($sy, $term);
        if (!$calendar) $add('application_calendar','BLOCKED','The school has not opened an application period for this school year and term.');
        $previous = GS::previousTerm($student['id'], $number, $sy, $term);
        $target = self::periodKey($sy, $term);
        foreach (GS::completions($student['id']) as $c) {
            $key = self::periodKey((string)($c['schoolYear']??''), (string)($c['term']??''));
            if ($key >= $target) $add('future_grades','BLOCKED','Choose a term after your latest completed academic record.');
            elseif (!$previous || $key > self::periodKey($previous['schoolYear'], $previous['term'])) $previous=['schoolYear'=>(string)$c['schoolYear'],'term'=>(string)($c['term']??'')];
        }
        foreach (Db::all("SELECT school_year,term FROM sis_registrations WHERE student_number=? AND status<>'CANCELLED' AND record_origin='ENROLLMENT'", [$number]) as $r) {
            if (self::periodKey($r['school_year'],$r['term']) > $target) $add('earlier_period','BLOCKED','You already have a registration after the selected period.');
        }
        if ((string)($student['schoolYear']??'') > $sy) $add('earlier_year','BLOCKED','Select the current or next school year.');
        $tag = $previous ? GS::completionFor($student['id'], $previous['schoolYear'], $previous['term']) : null;
        if (!$tag || !empty($tag['migrationReversed']) || ($previous && !GS::isTermComplete($student['id'],$previous['schoolYear'],$previous['term']))) $add('grades_complete','BLOCKED','Your previous-term final grades must be tagged Complete by the registrar before applying.');
        else {
            $pending=(array)($tag['pending']??[]);
            foreach ((array)($tag['subjects']??[]) as $s) if (in_array(strtoupper((string)($s['mark']??'')),['INC','INCOMPLETE'],true)) $pending[]=$s;
            if ($pending || !empty($tag['incomplete'])) $add('grades_complete','BLOCKED','Resolve incomplete or missing grades first'.($pending ? ': '.implode(', ',array_filter(array_column($pending,'subjectCode'))) : '').'.');
            else $add('grades_complete','PASS','Previous-term grades are tagged Complete. The registrar must still review promotion and subject prerequisites.');
        }
        return EnrollmentEligibility::summarize($checks,$number,$sy,$term);
    }

    public static function ownStudent(): array
    {
        Auth::require('self');
        $aid=(int)Auth::centralId();
        if (!$aid || !Auth::central()->can($aid,'portal.admission.apply')) throw new Forbidden('Enrollment applications are not enabled for your account.');
        $number=Auth::studentNumber();
        $account=Auth::central()->account($aid);
        $student=$number && !empty($account['person_verified']) ? GS::get('students',(string)($account['student_doc_id']??'')) : null;
        if ($student && ((string)$student['studentId']!==$number || (GS::studentByNumber($number)['id']??'')!==$student['id'])) throw new AppError('The student identity link is ambiguous. Ask SIS to verify your Student ID.');
        if (!$student) throw new AppError('Ask SIS to verify and link your Student ID first. New applicants should use My admission application.');
        return $student;
    }

    public static function submit(array $in, ?int $id=null): int
    {
        $student=self::ownStudent();
        $sy=(string)In::str($in,'school_year',9,true,'School year');
        $term=(string)In::enum($in,'term',TERMS,true,'Term');
        if (($in['details_confirmed']??'')!=='1') throw new AppError('Confirm that your student details are correct. Contact the registrar if a correction is needed.');
        $level=(string)In::enum($in,'requested_year_level',YEAR_LEVELS,true,'Year level');
        $preferred=(string)(In::str($in,'preferred_section_id',191)??'');
        $note=In::str($in,'student_note',1000);
        return Db::tx(function()use($student,$sy,$term,$note,$level,$preferred,$id) {
            Db::one("SELECT id FROM docs WHERE col='students' AND id=? FOR UPDATE",[$student['id']]);
            $row=$id ? self::own($id,true) : null;
            if ($row && $row['status']!=='RETURNED') throw new AppError('Only an application returned for correction can be resubmitted.');
            if ($row && ($row['school_year']!==$sy || $row['term']!==$term)) throw new AppError('A returned application must keep its original period.');
            if (Db::val("SELECT id FROM sis_portal_enrollments WHERE student_number=? AND school_year=? AND term=? AND status<>'REJECTED' AND id<>?",[$student['studentId'],$sy,$term,$id??0])) throw new AppError('You already have an application for this period. Open its progress below.');
            $fresh=GS::get('students',$student['id']);
            if (!$fresh || $fresh['studentId']!==$student['studentId']) throw new AppError('Your student record changed. Reload the page and verify your Student ID.');
            $result=self::eligibility($fresh,$sy,$term);
            EnrollmentEligibility::assertAllowed($result,true);
            $choice=self::validatePlacement($fresh,$sy,$term,$level,$preferred);
            $current=self::currentEnrollment($fresh);
            $data=['current_enrollment'=>json_encode($current,JSON_THROW_ON_ERROR),'requested_year_level'=>$level,'preferred_section_doc_id'=>$preferred!==''?$preferred:null,'preferred_section_name'=>$choice['name']??null,'approved_placement'=>null,'account_id'=>(int)Auth::centralId(),'student_number'=>$student['studentId'],'student_doc_id'=>$student['id'],'student_name'=>$student['studentName'],'school_year'=>$sy,'term'=>$term,'status'=>'PENDING','student_note'=>$note,'eligibility_snapshot'=>json_encode($result,JSON_THROW_ON_ERROR),'review_note'=>null];
            if ($id) Db::update('sis_portal_enrollments',$data,$id);
            else $id=Db::insert('sis_portal_enrollments',$data);
            self::event($id,'SUBMITTED','Application submitted for '.$sy.' · '.$term.' · '.$level.'. '.($choice?'Preferred section: '.$choice['name'].'.':'The registrar will assign the section.'));
            foreach (['ADMIN','REGISTRAR'] as $role) Db::insert('sis_notifications',['recipient_role'=>$role,'type'=>'ENROLLMENT_REQUEST','title'=>'Enrollment application needs review','message'=>$student['studentName'].' · '.$sy.' '.$term,'entity_type'=>'portal_enrollment','entity_id'=>(string)$id,'is_read'=>0]);
            return $id;
        });
    }

    public static function own(int $id, bool $lock=false): array
    {
        self::ownStudent();
        $r=Db::one('SELECT * FROM sis_portal_enrollments WHERE id=? AND account_id=? AND student_number=?'.($lock?' FOR UPDATE':''),[$id,(int)Auth::centralId(),Auth::studentNumber()]);
        if (!$r) throw new Forbidden('Enrollment application not found for your account.');
        return $r;
    }

    public static function accept(int $id, array $in): int
    {
        Auth::require('admission'); Auth::require('registration');
        if (($in['review_confirmed']??'')!=='1') throw new AppError('Confirm the identity, requirements, promotion and prerequisite review before accepting.');
        return Db::tx(function()use($id,$in) {
            $app=Db::one('SELECT * FROM sis_portal_enrollments WHERE id=? FOR UPDATE',[$id]);
            if (!$app || $app['status']!=='PENDING') throw new AppError('Only a pending application can be accepted.');
            $student=GS::studentByNumber($app['student_number']);
            if (!$student || $student['id']!==$app['student_doc_id']) throw new AppError('The linked student identity changed. Verify the Student ID before accepting.');
            Db::one("SELECT id FROM docs WHERE col='students' AND id=? FOR UPDATE",[$student['id']]);
            $student=GS::get('students',$student['id']);
            if (!$student || $student['studentId']!==$app['student_number']) throw new AppError('The student identity changed. Reload and review the application.');
            $result=self::eligibility($student,$app['school_year'],$app['term']);
            EnrollmentEligibility::assertAllowed($result,true);
            $section=GS::get('sections',(string)($in['section_id']??''));
            if (!$section || $section['schoolYear']!==$app['school_year']) throw new AppError('Choose a section for the application school year.');
            $requested=(string)($app['requested_year_level']??'');
            if ($requested!=='' && $section['yearLevel']!==$requested) throw new AppError('Choose a section for the requested year level, or return the application so the student can correct their request.');
            self::validatePlacement($student,$app['school_year'],$app['term'],$section['yearLevel'],$section['id']);
            $curId=trim((string)($in['curriculum_id']??''));
            $cur=$curId!=='' ? GS::get('curriculum',$curId) : null;
            if ($curId!=='' && (!$cur || ($cur['yearLevel']??'')!==$section['yearLevel'] || ($cur['term']??'')!==$app['term'] || strtoupper((string)($cur['program']??''))!==strtoupper((string)GS::programOf($section['sectionName'])))) throw new AppError('Choose a curriculum matching the section program, year level and application term.');
            Db::q("UPDATE sis_portal_enrollments SET status='ACCEPTED',reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=?",[Auth::id(),In::str($in,'review_note',1000),$id]);
            [$rid]=Registration::create(null,$app['student_number'],$section['id'],$app['term'],In::int($in,'assessment_name_id'),$cur?array_values((array)($cur['subjectIds']??[])):null,deferPlacement:true);
            Db::update('sis_portal_enrollments',['registration_id'=>$rid,'approved_placement'=>json_encode(['school_year'=>$app['school_year'],'term'=>$app['term'],'year_level'=>$section['yearLevel'],'section_doc_id'=>$section['id'],'section_name'=>$section['sectionName']],JSON_THROW_ON_ERROR),'eligibility_snapshot'=>json_encode($result,JSON_THROW_ON_ERROR)],$id);
            self::event($id,'ACCEPTED','Registrar accepted your application for '.$section['yearLevel'].' · '.$section['sectionName'].'. Your assessment is being prepared. Your current section changes only after enrollment confirmation.');
            return $rid;
        });
    }

    public static function decide(int $id, string $status, string $reason): void
    {
        Auth::require('admission');
        if (!in_array($status,['RETURNED','REJECTED'],true) || trim($reason)==='' || mb_strlen($reason)>1000) throw new AppError('Choose a decision and give a reason (up to 1000 characters).');
        Db::tx(function()use($id,$status,$reason) {
            $r=Db::one('SELECT * FROM sis_portal_enrollments WHERE id=? FOR UPDATE',[$id]);
            if (!$r || $r['status']!=='PENDING') throw new AppError('Only a pending application can be returned or rejected.');
            Db::q('UPDATE sis_portal_enrollments SET status=?,review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?',[$status,trim($reason),Auth::id(),$id]);
            self::event($id,$status,trim($reason));
        });
    }

    public static function fingerprint(array $r): string
    {
        $payload=[$r['total_amount'],$r['assessment_name_id'],Db::all('SELECT subject_doc_id,subject_code,subject_name,units FROM sis_registration_subjects WHERE registration_id=? ORDER BY subject_doc_id',[$r['id']]),Db::all('SELECT category_name,label_name,charge_type,quantity,unit_amount,amount,sort_order FROM sis_registration_items WHERE registration_id=? ORDER BY sort_order,category_name,label_name,charge_type,amount',[$r['id']])];
        $plan=Db::val('SELECT snapshot FROM sis_registration_plans WHERE registration_id=?',[$r['id']]);if($plan!==null)$payload[]=['payment_plan'=>$plan];
        return hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
    }

    public static function published(array $a, array $r): bool
    {
        return $r['status']!=='CANCELLED' && self::visible($a,$r);
    }

    /** Published historical assessments remain viewable when the registration is voided. */
    public static function visible(array $a, array $r): bool
    {
        return !empty($a['assessment_published_at']) && hash_equals((string)$a['assessment_hash'],self::fingerprint($r));
    }

    public static function publish(int $rid, array $in): void
    {
        Auth::require('registration');
        if (($in['assessment_reviewed']??'')!=='1') throw new AppError('Confirm that subjects, fees, discounts and previous balances have been reviewed.');
        Db::tx(function()use($rid,$in) {
            $a=Db::one("SELECT * FROM sis_portal_enrollments WHERE registration_id=? AND status='ACCEPTED' FOR UPDATE",[$rid]);
            if (!$a) throw new AppError('No accepted portal application is linked to this registration.');
            $r=Registration::get($rid);
            if (Registration::state($r)!=='OPEN') throw new AppError('Only an open registration draft can publish an assessment.');
            $rv=Registration::review($rid);
            if ($rv['errors']) throw new AppError('Resolve registration review errors before publishing.');
            EnrollmentEligibility::assertAllowed(EnrollmentEligibility::forRegistration($r));
            Registration::recompute($rid); $r=Registration::get($rid);
            $minimum=In::money($in,'minimum_payment',false,'Required payment before confirmation')??0;
            if ($minimum<0 || $minimum>Money::cents($r['total_amount'])) throw new AppError('The required payment must be between zero and the assessment total.');
            Db::q('UPDATE sis_portal_enrollments SET assessment_hash=?,assessment_published_at=NOW(),assessment_published_by=?,minimum_payment=? WHERE id=?',[self::fingerprint($r),Auth::id(),Money::dec($minimum),$a['id']]);
            self::event((int)$a['id'],'ASSESSMENT_PUBLISHED','Your assessment is ready. View the charges and payment instructions in your Student Portal.');
        });
    }

    public static function assertPayment(int $rid, bool $confirm=false): void
    {
        $a=Db::one("SELECT * FROM sis_portal_enrollments WHERE registration_id=? AND status='ACCEPTED'",[$rid]);
        if (!$a) return;
        $r=Registration::get($rid);
        if (!self::published($a,$r)) throw new AppError('Publish the reviewed portal assessment before collecting payment or confirming enrollment. Changed assessments must be published again.');
        if ($confirm && Money::cents($r['amount_paid'])<min(Money::cents($r['total_amount']),Money::cents($a['minimum_payment']))) throw new AppError('The required enrollment payment is not yet met. Required: ₱'.Money::fmt(Money::cents($a['minimum_payment'])).'.');
    }

    public static function event(int $id, string $type, string $message): void
    {
        Db::insert('sis_portal_enrollment_events',['application_id'=>$id,'event_type'=>$type,'message'=>$message,'actor_id'=>Auth::id()]);
        Audit::log('portal_enrollment_'.strtolower($type),'portal_enrollment',$id,['message'=>$message]);
    }

    public static function confirmed(int $rid): void
    {
        $id=Db::val("SELECT id FROM sis_portal_enrollments WHERE registration_id=? AND status='ACCEPTED'",[$rid]);
        if ($id) self::event((int)$id,'ENROLLED','The registrar confirmed your enrollment.');
    }

    public static function registrationVoided(int $rid, string $reason): void
    {
        $id=Db::val("SELECT id FROM sis_portal_enrollments WHERE registration_id=? AND status='ACCEPTED'",[$rid]);
        if (!$id) return;
        Db::q("UPDATE sis_portal_enrollments SET status='REJECTED',review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?",['Registration voided: '.$reason,Auth::id(),$id]);
        self::event((int)$id,'REGISTRATION_VOIDED','Registration voided: '.$reason.'. Your previous application and assessment remain in history.');
    }

    public static function progress(array $a): array
    {
        if ($a['status']==='RETURNED') return ['Application returned for correction',1];
        if ($a['status']==='REJECTED') return ['Application rejected',1];
        if ($a['status']!=='ACCEPTED' || !$a['registration_id']) return ['Pending registrar review',1];
        $r=Registration::get((int)$a['registration_id']);
        if ($r['status']==='CANCELLED') return ['Registration voided — contact the registrar',2];
        if (!self::published($a,$r)) return ['Accepted — assessment being prepared',2];
        if (!empty($r['locked_at'])) return ['Enrolled',5];
        return Money::cents($r['amount_paid'])>=Money::cents($a['minimum_payment']) ? ['Payment requirement met — awaiting enrollment confirmation',4] : ['Assessment ready — payment required',3];
    }
}
