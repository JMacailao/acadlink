/* Registered once; navigation disposes page listeners and observers before remounting. */
(function(register){register('bulk-accounts',function(scope){
(()=>{
  const $=id=>document.getElementById(id), select=$('bulkSelect');
  if(!select)return;
  const inputs=()=>[...select.querySelectorAll('[name="records[]"]')];
  const tableRows=[...$('bulkRecords').querySelectorAll('tr')], year=$('bulkYear'), section=$('bulkSection'), school=$('bulkSchoolYear');
  const saved=JSON.parse($('bulkFilterInitial').textContent);
  $('bulkSearch').value=saved.search||'';
  let filterTimer, selectionCleared=false;
  function count(){
    const matched=inputs().filter(x=>!x.closest('tr').hidden), checked=matched.filter(x=>x.checked).length;
    $('selectionCount').textContent=`${matched.length} ${year?'students':'teachers'} found · ${checked} selected · maximum 1,000`;
    $('bulkAll').checked=matched.length>0&&checked===matched.length;
    $('bulkAll').indeterminate=checked>0&&checked<matched.length;
    $('bulkAll').disabled=matched.length===0;
  }
  function fillSections(){
    if(!section)return;
    const keep=section.value, options=new Map();
    tableRows.filter(tr=>(!school.value||tr.dataset.schoolYear===school.value)&&(!year.value||tr.dataset.year===year.value)).forEach(tr=>options.set(tr.dataset.section,tr.dataset.sectionLabel));
    section.replaceChildren(new Option('All sections',''),...[...options].sort((a,b)=>a[1].localeCompare(b[1],undefined,{numeric:true})).map(([id,label])=>new Option(label,id)));
    section.value=options.has(keep)?keep:'';
  }
  function applyFilters(){
    const q=$('bulkSearch').value.trim().toLocaleLowerCase();
    tableRows.forEach(tr=>tr.hidden=!!((school&&school.value&&tr.dataset.schoolYear!==school.value)||(year&&year.value&&tr.dataset.year!==year.value)||(section&&section.value&&tr.dataset.section!==section.value)||!tr.textContent.toLocaleLowerCase().includes(q)));
    const empty=tableRows.every(tr=>tr.hidden);
    $('bulkEmpty').hidden=!empty;
    $('bulkEmpty').textContent=year?'No students found for this school year, year level, section and search.':'No teachers match this search.';
    $('bulkRecords').removeAttribute('aria-busy'); count();
  }
  function filterChanged(refreshSections=false){
    clearTimeout(filterTimer);
    const hadSelection=inputs().some(x=>x.checked);selectionCleared=selectionCleared||hadSelection;
    inputs().forEach(x=>x.checked=false);
    $('bulkAll').checked=$('bulkAll').indeterminate=false;
    if(refreshSections)fillSections();
    $('filterNotice').textContent=(selectionCleared?'Selection cleared because filters changed. ':'')+'Updating list…';
    $('bulkRecords').setAttribute('aria-busy','true');
    filterTimer=scope.timeout(()=>{applyFilters();$('filterNotice').textContent=selectionCleared?'Selection cleared because filters changed.':'';selectionCleared=false;},120);
    count();
  }
  function fillYears(){
    const keep=year.value;
    const values=[...new Set(tableRows.filter(tr=>!school.value||tr.dataset.schoolYear===school.value).map(tr=>tr.dataset.year))].sort((a,b)=>a.localeCompare(b,undefined,{numeric:true}));
    year.replaceChildren(new Option('All year levels',''),...values.map(value=>new Option(value==='__unassigned'?'Unassigned year level':value,value)));
    year.value=values.includes(keep)?keep:'';
  }
  if(year){
    tableRows.forEach(tr=>{if(!tr.dataset.year)tr.dataset.year='__unassigned';if(!tr.dataset.schoolYear)tr.dataset.schoolYear='__unassigned';});
    const schoolYears=[...new Set(tableRows.map(tr=>tr.dataset.schoolYear))].sort().reverse();
    school.append(...schoolYears.map(value=>new Option(value==='__unassigned'?'Unassigned school year':value,value)));
    const initial=Object.prototype.hasOwnProperty.call(saved,'school_year')?saved.school_year:school.dataset.default;
    school.value=schoolYears.includes(initial)?initial:'';
    fillYears();if([...year.options].some(o=>o.value===saved.year))year.value=saved.year;
    fillSections();if([...section.options].some(o=>o.value===saved.section))section.value=saved.section;
    school.addEventListener('change',()=>{fillYears();filterChanged(true);});
    year.addEventListener('change',()=>filterChanged(true));section.addEventListener('change',()=>filterChanged());
    $('bulkResetFilters').addEventListener('click',()=>{school.value='';year.value='';section.value='';$('bulkSearch').value='';fillYears();filterChanged(true);});
  }
  $('bulkSearch').addEventListener('input',()=>filterChanged());
  $('bulkAll').addEventListener('change',e=>{const checked=e.target.checked;clearTimeout(filterTimer);applyFilters();inputs().filter(x=>!x.closest('tr').hidden).forEach(x=>x.checked=checked);count();});
  select.addEventListener('change',count);applyFilters();
  select.addEventListener('submit',e=>{
    clearTimeout(filterTimer);applyFilters();
    const checked=inputs().filter(x=>x.checked&&!x.closest('tr').hidden);
    if(!checked.length||checked.length>1000){e.preventDefault();$('selectionCount').textContent='Select 1–1,000 records before previewing.';return;}
    // One field avoids PHP's usual 1,000-input limit for large selections.
    let field=select.querySelector('[name="records_json"]');
    if(!field){field=document.createElement('input');field.type='hidden';field.name='records_json';select.append(field);}
    field.value=JSON.stringify(checked.map(x=>x.value));inputs().forEach(x=>x.disabled=true);
  });
  function mode(){const admin=$('passwordMode').value==='admin';$('adminPassword').hidden=!admin;$('adminPassword').querySelectorAll('input').forEach(x=>{x.disabled=!admin;x.required=admin;});}
  $('passwordMode').addEventListener('change',mode);mode();
  const job=$('bulkJob');if(!job)return;
  let rows=JSON.parse($('bulkInitial').textContent),busy=false,paused=false,finished=job.dataset.finished==='1';
  const status=$('bulkProgress');
  function render(){
    const visible=finished?rows.filter(r=>!['Created','Skipped'].includes(r.status)):rows;
    $('bulkResultsWrap').hidden=visible.length===0;
    $('bulkResults').replaceChildren(...visible.map(r=>{const tr=document.createElement('tr');[`${r.name} · ${r.number}`,`${r.year_level||''} ${r.section_name||''}`.trim(),r.username,r.status,r.message].forEach(s=>{const td=document.createElement('td');td.textContent=s;tr.append(td);});return tr;}));
    const counts={};rows.forEach(r=>counts[r.status]=(counts[r.status]||0)+1);
    $('bulkMeter').value=rows.length-(counts.Ready||0);
    $('bulkStart').hidden=finished;$('bulkRetry').hidden=finished&&!counts.Failed;$('bulkPause').hidden=finished;
    $('bulkPasswordControls').hidden=finished&&!counts.Failed;$('bulkMeter').hidden=finished;
    $('bulkJobTitle').textContent=(finished?'Batch results':'Batch preview')+' · '+(job.dataset.group==='STUDENT'?'Students':'Teachers');
    if(finished&&!busy){
      status.textContent=`Completed: ${counts.Created||0} created · ${counts.Skipped||0} skipped · ${(counts['Needs review']||0)+(counts['Missing information']||0)} need attention · ${counts.Failed||0} failed. Download the results.`;
      return;
    }

    status.textContent=`${busy?'Creating accounts · ':''}${rows.length-(counts.Ready||0)} of ${rows.length} processed · Created ${counts.Created||0} · Ready ${counts.Ready||0} · Skipped ${counts.Skipped||0} · Needs review ${counts['Needs review']||0} · Missing information ${counts['Missing information']||0} · Failed ${counts.Failed||0}`;
  }
  function clearCompleted(){
    inputs().forEach(x=>x.checked=false);$('bulkAll').checked=$('bulkAll').indeterminate=false;count();
    [$('batchPassword'),...select.querySelectorAll('input[type="password"]')].forEach(x=>{x.value='';x.defaultValue='';});
    $('bulkSecretDisplay')?.remove();
  }
  async function run(retry=false){
    if(busy)return;
    if(!$('batchPassword').value){status.textContent='Enter the original shared temporary password to continue.';return;}
    let completed=false;
    busy=true;paused=false;$('bulkStart').disabled=$('bulkRetry').disabled=true;
    $('bulkStart').textContent='Creating accounts…';$('bulkPause').disabled=false;
    select.querySelectorAll('button, input, select').forEach(el=>{el.dataset.bulkWasDisabled=String(el.disabled);el.disabled=true;});
    $('bulkType').disabled=true;job.setAttribute('aria-busy','true');render();
    try{
      do{
        const data=new FormData();data.set('_csrf',select.querySelector('[name="_csrf"]').value);data.set('action',retry?'retry':'run');data.set('token',job.dataset.token);data.set('password',$('batchPassword').value);
        const slow=scope.timeout(()=>{status.textContent+=' · This is taking longer than usual. Please wait; do not start another batch.';},8000);
        let response;
        try{response=await fetch(location.href,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});}finally{clearTimeout(slow);}
        if(!response.headers.get('content-type')?.includes('application/json'))throw Error('Your session expired. Sign in again and check Users and Groups before starting a new batch.');
        const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Request failed.');
        rows=result.rows;finished=!!result.finished;render();retry=false;
      }while(!paused&&rows.some(r=>r.status==='Ready'));
      completed=finished;
      if(!finished)status.textContent+=(paused?' · Paused. Resume when ready.':' · Finished. Download the results.');
    }catch(e){status.textContent=e.message+' You can safely resume this batch; completed accounts are kept.';}
    finally{busy=false;$('bulkStart').disabled=$('bulkRetry').disabled=false;$('bulkStart').textContent='Create / resume accounts';$('bulkPause').disabled=true;job.removeAttribute('aria-busy');
      select.querySelectorAll('[data-bulk-was-disabled]').forEach(el=>{el.disabled=el.dataset.bulkWasDisabled==='true';delete el.dataset.bulkWasDisabled;});$('bulkType').disabled=false;if(completed){render();clearCompleted();}}
  }
  $('bulkStart').addEventListener('click',()=>run());$('bulkRetry').addEventListener('click',()=>run(true));$('bulkPause').addEventListener('click',()=>{paused=true;$('bulkPause').disabled=true;status.textContent+=' · Pausing after the current group…';});$('bulkPause').disabled=true;render();if(finished)clearCompleted();
})();

},70);})(window.SISLifecycle ? window.SISLifecycle.register : function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
