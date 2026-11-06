<?php
declare(strict_types=1);
/** Shared explicit-apply contract for SIS record lists. Exact-record views are independent. */
final class RecordList
{
    private static bool $rendered=false;
    public static function route(): string {return is_string($_GET['r']??null)?$_GET['r']:'dashboard';}
    public static function enabled(): bool {
        $r=self::route();if(!in_array($r,['enrollment','students','teachers','sections','yearlevels','curriculum','assessment','discounts','users','bulkaccounts','admission','registration','cashier','migration','accounting'],true))return false;
        if(in_array($r,['enrollment','admission','registration','cashier'],true)&&(!empty($_GET['id'])||isset($_GET['new'])||!empty($_GET['reg'])))return false;
        if($r==='accounting'&&in_array($_GET['tab']??'overview',['overview','mappings','studentledger'],true))return false;
        if($r==='users'&&($_GET['tab']??'')==='groups')return false;
        return true;
    }
    /**
     * v57: a list opens already applied. When nothing is applied yet: the filters applied earlier in this
     * session, or else the defaults (top-bar school year, today's date). ?clear=1 goes back to the defaults.
     * Filters given in the link (e.g. Sections → its students) are kept and only the missing ones are filled.
     */
    public static function openDefaults(): void {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'||!self::enabled()||self::applied()||isset($_GET['embed']))return;
        $clear=isset($_GET['clear']);unset($_GET['clear']);$key=self::key();
        if($clear)unset($_SESSION['record_lists'][$key]);
        $given=array_diff_key(self::context(),['r'=>1,'tab'=>1,'group'=>1,'apply'=>1]);
        $saved=!$clear&&!$given?($_SESSION['record_lists'][$key]??[]):[];
        $values=$saved?:array_replace(self::defaults(),$given);
        unset($values['r'],$values['apply']);
        foreach($values as $k=>$v)if(is_string($v))$_GET[$k]=$v;
        $_GET['apply']='1';
    }
    /** Default filters for a list on first open. */
    public static function defaults(): array {
        $r=self::route();$tab=(string)($_GET['tab']??'');$d=[];
        if(in_array($r,['enrollment','admission','registration','students'],true)||($r==='bulkaccounts'&&($_GET['group']??'TEACHER')==='STUDENT'))$d['sy']=selected_sy();
        $today=date('Y-m-d');
        if($r==='cashier'){$d['from']=$today;$d['to']=$today;}
        if($r==='accounting'&&$tab==='ledger'){$d['from']=$today;$d['to']=$today;}
        return $d;
    }
    public static function applied(): bool {return is_string($_GET['apply']??null)&&$_GET['apply']==='1';}
    public static function key(): string {return self::route().'|'.(string)($_GET['tab']??'').'|'.(self::route()==='bulkaccounts'?(string)($_GET['group']??'TEACHER'):'');}
    public static function context(): array {return array_filter($_GET,fn($v,$k)=>is_string($v)&&!in_array($k,['id','edit','open','editName','new','entry','action','template','embed'],true),ARRAY_FILTER_USE_BOTH);}
    public static function base(): array { $p=[];foreach(['tab','group'] as $k)if(isset($_GET[$k])&&is_string($_GET[$k])&&($k!=='group'||self::route()==='bulkaccounts'))$p[$k]=$_GET[$k];return $p; }
    public static function remember(): void {if(self::enabled()&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'){if(self::applied())$_SESSION['record_lists'][self::key()]=self::context();else unset($_SESSION['record_lists'][self::key()]);}}
    public static function redirectParams(string $route,array $params): array {
        if(($route===self::route())&&($_SERVER['REQUEST_METHOD']??'')==='POST'&&self::enabled()&&!isset($params['new'])){
            $saved=$_SESSION['record_lists'][self::key()]??[];if(isset($params['tab'])&&$params['tab']!==($_GET['tab']??''))return $params;unset($saved['r']);return array_replace($saved,$params);
        }return $params;
    }
    /** Lookup data used by Add/Edit forms stays separate from the gated result query. */
    public static function rows(callable $load,bool $filter=true): array {if(!self::applied())return []; $rows=$load();return $filter?self::filter($rows):array_values($rows);}
    public static function query(string $sql,array $params=[]): array {return self::rows(fn()=>Db::all($sql,$params),in_array(self::route(),['migration','accounting'],true));}
    public static function filter(array $rows): array {
        $r=self::route();$tab=$_GET['tab']??'';
        $maps=[
            'students'=>['sy'=>['schoolYear'],'year'=>['yearLevel'],'section'=>['sectionId']],
            'teachers'=>[], 'sections'=>['sy'=>['schoolYear'],'year'=>['yearLevel']],
            'curriculum'=>$tab==='subjects'?[]:['program'=>['program'],'year'=>['yearLevel'],'term'=>['term']],
            'assessment'=>['category_id'=>['category_id'],'program'=>['program'],'year'=>['year_level'],'term'=>['term'],'active'=>['is_active']],
            'discounts'=>['active'=>['is_active']], 'users'=>['group'=>['group_code'],'status'=>['status']],
            'migration'=>['kind'=>['kind'],'status'=>['status']],
            'bulkaccounts'=>['sy'=>['_schoolYear'],'year'=>['_year'],'section'=>['_section']],
            'accounting'=>['fund'=>['fund'],'department'=>['department']],
        ];
        if($r==='bulkaccounts'&&($_GET['group']??'TEACHER')==='TEACHER')$maps['bulkaccounts']=[];
        $q=mb_strtolower(trim((string)($_GET['q']??'')));
        return array_values(array_filter($rows,function($row)use($maps,$r,$q){
            foreach($maps[$r]??[] as $name=>$keys){$value=$_GET[$name]??'';if(!is_string($value)||$value==='')continue;$found=false;foreach($keys as $key)if((string)($row[$key]??'')===$value||($r==='bulkaccounts'&&$value==='__unassigned'&&empty($row[$key])))$found=true;if(!$found)return false;}
            return $q===''||str_contains(mb_strtolower((string)json_encode($row,JSON_UNESCAPED_UNICODE)),$q);
        }));
    }
    public static function nativeForm(): ?string {
        return match(self::route()){'enrollment'=>'enrollmentFilters','bulkaccounts'=>'bulkFilters','students'=>'studentFilters','sections'=>'sectionFilters','yearlevels'=>'yearFilters','admission'=>'admFilters','registration'=>'regFilters','cashier'=>'orFilters','discounts'=>(($_GET['tab']??'')==='grantees'?'discountFilters':null),
            'accounting'=>match($_GET['tab']??''){'review'=>'accountingReviewFilters','ledger'=>'accountingLedgerFilters','reconcile'=>'accountingReconcileFilters','financial'=>'accountingFinancialFilters',default=>null},default=>null};
    }
    private static function field(string $name,string $label,array $choices): string {
        $selected=(string)($_GET[$name]??'');$id='listFilter'.ucfirst($name);$out='<div><label for="'.$id.'">'.h($label).'</label><select class="form-select" name="'.h($name).'" id="'.$id.'"><option value="">All '.h(mb_strtolower($label)).'</option>';
        foreach($choices as $value=>$text)$out.='<option value="'.h((string)$value).'"'.($selected===(string)$value?' selected':'').'>'.h((string)$text).'</option>';return $out.'</select></div>';
    }
    public static function panel(): void {
        if(self::$rendered||!self::enabled())return;self::$rendered=true;self::remember();$r=self::route();$base=url($r,self::base());$native=self::nativeForm();$form=$native??'recordFilters';$applied=self::applied();$sectionData=[];$levelData=[];if(in_array($r,['students','bulkaccounts'],true))$levelData=Db::all("SELECT DISTINCT f_schoolYear AS sy,f_yearLevel AS year FROM docs WHERE col='students'");if(in_array($r,['students','sections','bulkaccounts'],true))foreach(GS::sections() as $s)$sectionData[]=['id'=>$s['id'],'sy'=>$s['schoolYear'],'year'=>$s['yearLevel'],'name'=>$s['sectionName']];
        echo '<section class="record-list-controls d-print-none" data-record-list data-levels="'.h(json_encode($levelData,JSON_THROW_ON_ERROR)).'" data-sections="'.h(json_encode($sectionData,JSON_THROW_ON_ERROR)).'" data-applied="'.($applied?'1':'0').'" data-filter-form="'.h($form).'" data-clear-url="'.h(url($r,self::base()+['clear'=>'1'])).'" data-refresh-url="'.h(url($r,self::context())).'">';
        echo '<div class="record-toolbar" role="toolbar" aria-label="Record list tools"><span data-list-add></span><button type="button" class="btn btn-sm btn-outline-secondary" data-list-toggle aria-controls="'.h($form).'" aria-expanded="true">Hide Filters</button><button type="submit" class="btn btn-sm btn-primary" form="'.h($form).'" data-list-apply>Apply Filters</button><a class="btn btn-sm btn-outline-secondary" href="'.h(url($r,self::base()+['clear'=>'1'])).'" data-list-clear>Clear</a>';
        if($applied)echo '<a class="btn btn-sm btn-outline-secondary" href="'.h(url($r,self::context())).'" data-list-refresh>Refresh</a>';else echo '<button class="btn btn-sm btn-outline-secondary" disabled data-list-refresh>Refresh</button>';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary" data-print data-list-print'.(!$applied?' disabled':'').'>Print List</button><span data-list-actions class="d-flex flex-wrap gap-2"></span></div>';
        if(!$native){echo '<form method="get" id="recordFilters" class="record-filter-fields" data-record-filters><input type="hidden" name="r" value="'.h($r).'"><input type="hidden" name="apply" value="1">';foreach(self::base() as $k=>$v)echo '<input type="hidden" name="'.h($k).'" value="'.h($v).'">';
            if(in_array($r,['curriculum','assessment'],true)&&!(($r==='curriculum'&&($_GET['tab']??'')==='subjects')||($r==='assessment'&&($_GET['tab']??'')!=='names'))){echo self::field('program','Programs',array_combine(GS::programs(),GS::programs()));echo self::field('year','Year levels',array_combine(YEAR_LEVELS,YEAR_LEVELS));echo self::field('term','Terms',array_combine(TERMS,TERMS));}
            if(in_array($r,['assessment','discounts'],true))echo self::field('active','Statuses',['1'=>'Active','0'=>'Inactive']);
            if($r==='users'){echo self::field('group','Groups',array_column(Db::all('SELECT code,name FROM central_groups ORDER BY id'),'name','code'));echo self::field('status','Statuses',['ACTIVE'=>'Active','DISABLED'=>'Disabled']);}
            if($r==='migration'){echo self::field('kind','Record types',['BALANCES'=>'Previous balances','GRADES'=>'Previous academic records']);echo self::field('status','Statuses',array_combine(['DRAFT','APPROVED','IMPORTED','REVERSED','DISCARDED'],['Draft','Approved','Imported','Reversed','Discarded']));}
            if($r==='bulkaccounts'){echo '<input type="hidden" name="group" value="'.h($_GET['group']??'TEACHER').'">';if(($_GET['group']??'TEACHER')==='STUDENT'){
                echo '<div><label for="listSchoolYear">School year</label><select id="listSchoolYear" class="form-select" name="sy">'.sy_options((string)($_GET['sy']??''),true).'</select></div>';echo self::field('year','Year levels',array_combine(YEAR_LEVELS,YEAR_LEVELS));$choices=[];foreach(GS::sections() as $s)$choices[$s['id']]=$s['sectionName'].' · '.$s['schoolYear'].' · '.$s['yearLevel'];echo self::field('section','Sections',$choices);
            }}
            echo '<div><label for="recordQuery">Search</label><input type="search" class="form-control" id="recordQuery" name="q" value="'.h($_GET['q']??'').'" placeholder="Name, ID or reference"></div></form>';
        }
        echo '<p class="record-list-status mb-0" role="status" aria-live="polite">'.($applied?'Showing applied results.':'Choose your filters, then click Apply Filters to show records.').'</p></section>';
    }
}
