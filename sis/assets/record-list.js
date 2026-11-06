/* Registered once; navigation disposes page listeners and observers before remounting. */
(function(register){register('record-list',function(scope){
/* One explicit-apply toolbar for SIS directories. Server-side gates own result loading. */
(() => {
  'use strict';
  const panel=document.querySelector('[data-record-list]');if(!panel)return;
  document.body.classList.add('record-list-page');
  const form=document.getElementById(panel.dataset.filterForm);if(!form)return;
  const status=panel.querySelector('[role=status]'), toolbar=panel.querySelector('.record-toolbar');
  const applied=panel.dataset.applied==='1';let dirty=false,selected=null;
  form.dataset.recordFilters='';
  form.querySelectorAll('[data-autosubmit]').forEach(el=>el.removeAttribute('data-autosubmit'));
  // A native Apply button is removed below, so the applied flag must travel as a hidden field.
  if(!form.querySelector('input[name="apply"]')){const field=document.createElement('input');field.type='hidden';field.name='apply';field.value='1';form.append(field);}
  // Move existing filter controls so native date/status filters remain the source of truth.
  if(!panel.contains(form))panel.insertBefore(form,status);
  form.classList.remove('d-none');form.hidden=false;
  const oldTools=[...document.querySelectorAll('.or-toolbar')].find(el=>el.querySelector(`[form="${form.id}"]`));
  if(oldTools){const screen=oldTools.closest('.or-screen');if(screen)screen.prepend(panel);
    const alternative=oldTools.querySelector('.tb-search input:not([type=hidden])');
    if(alternative&&!form.querySelector(`[name="${alternative.name}"]:not([type=hidden])`)){
      const name=alternative.name;form.querySelectorAll(`input[type=hidden][name="${name}"]`).forEach(x=>x.remove());
      const box=document.createElement('div');box.className='mt-2';const label=document.createElement('label');label.className='form-label small';alternative.id='listNativeSearch';label.htmlFor=alternative.id;label.textContent=alternative.placeholder||'Search';box.append(label,alternative);form.append(box);
    }
    oldTools.querySelectorAll('[data-row]').forEach(el=>{el.classList.remove('tb');el.classList.add('btn','btn-sm','btn-outline-secondary');panel.querySelector('[data-list-actions]').append(el);});
    const add=[...oldTools.querySelectorAll('a,button')].find(el=>/^(New|Add|Receive)/i.test(el.textContent.trim()));if(add)panel.querySelector('[data-list-add]').append(add);
    oldTools.remove();
  }
  // Keep the existing Add form/modal behavior and permission checks.
  const addSlot=panel.querySelector('[data-list-add]');
  if(!addSlot.children.length){const add=document.querySelector('.page-head a[href*="#add"],.page-head button[data-bs-target="#mEntry"],.admission-new');if(add)addSlot.append(add);}
  if(addSlot.firstElementChild){const add=addSlot.firstElementChild;add.classList.remove('btn-primary','tb');add.classList.add('btn','btn-sm','btn-outline-primary');add.title=add.textContent.trim();add.setAttribute('aria-label',add.title);add.textContent='Add New';}
  document.querySelectorAll('#main input[data-filter]').forEach(input=>{if(!input.closest('form,dialog,.modal')){input.labels&&[...input.labels].forEach(label=>label.remove());input.remove();}});
  form.querySelectorAll('select').forEach((input,index)=>{if(input.closest('.fp-row')||input.labels?.length)return;const previous=input.previousElementSibling;if(previous?.tagName==='LABEL'){input.id=input.id||`recordNative${index}`;previous.htmlFor=input.id;return;}const label=document.createElement('label');input.id=input.id||`recordNative${index}`;label.htmlFor=input.id;label.className='form-label small';label.textContent=input.getAttribute('aria-label')||({sy:'School year',year:'Year level',section:'Section'}[input.name])||input.name;input.before(label);});
  // A separate search submit or Reset button no longer changes records implicitly.
  form.querySelectorAll('button:not([type=button]),button[type=submit]').forEach(el=>el.remove());
  form.querySelectorAll('a').forEach(el=>{if(/^(Reset|Clear)/i.test(el.textContent.trim()))el.remove();});
  // Bare flex-form fields need a label/field cell; otherwise labels become narrow flex items.
  [...form.children].filter(input=>input.matches('select,input:not([type=hidden]):not([type=checkbox]):not([type=radio])')).forEach((input,index)=>{
    const cell=document.createElement('div');cell.className='record-filter-cell';
    const label=input.labels?.[0]||document.createElement('label');
    input.id=input.id||`recordField${index}`;label.htmlFor=input.id;
    if(!label.textContent.trim())label.textContent=input.getAttribute('aria-label')||input.placeholder||'Search';
    input.before(cell);cell.append(label,input);
  });
  if(form.matches('.row,.d-flex,.record-filter-fields')){
    form.classList.add('record-filter-grid');
    [...form.children].filter(el=>el.tagName==='DIV').forEach(cell=>{
      if(!cell.children.length&&!cell.textContent.trim()){cell.remove();return;}
      cell.classList.add('record-filter-cell');
    });
    form.querySelectorAll('label.visually-hidden').forEach(label=>label.classList.remove('visually-hidden'));
  }
  // Reuse the native query field and its parameter; form= keeps GET submission intact outside the form.
  let search=form.querySelector('input[name="q"]:not([type=hidden]),input[name="payer"]:not([type=hidden]),input[type=search]');
  if(!search){search=document.createElement('input');search.name='q';search.value=new URLSearchParams(location.search).get('q')||'';}
  const oldParent=search.parentElement;
  if(search.labels)[...search.labels].forEach(label=>label.remove());
  search.type='search';search.classList.add('form-control');search.setAttribute('form',form.id);
  const route=document.body.dataset.route;
  search.placeholder=({students:'Search name or Student ID…',teachers:'Search name or Teacher ID…',admission:'Search applicant, Student ID or admission no.…',registration:'Search name, Student ID or registration no.…',cashier:'Search student name or Student ID…',sections:'Search section name…',enrollment:'Search application no., student name or student ID…'}[route])||'Search name, ID or reference…';
  if(route==='accounting'&&new URLSearchParams(location.search).get('tab')==='financial')search.placeholder='Search receivables by name or Student ID…';
  search.setAttribute('aria-label',search.placeholder.replace('…',''));search.autocomplete='off';
  const searchBox=document.createElement('div');searchBox.className='record-toolbar-search';searchBox.dataset.listSearch='';
  searchBox.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 5 5"/></svg>';
  searchBox.append(search);
  if(oldParent&&oldParent!==form&&!oldParent.querySelector('input,select,textarea,button,a')&&!oldParent.textContent.trim()){
    const parent=oldParent.parentElement;oldParent.remove();
    if(parent&&parent!==form&&parent.matches('.record-filter-cell')&&!parent.querySelector('input,select,textarea,button,a'))parent.remove();
  }
  const refresh=toolbar.querySelector('[data-list-refresh]'),buttons=document.createElement('div');buttons.className='record-toolbar-buttons';buttons.setAttribute('role','group');buttons.setAttribute('aria-label','List actions');
  [...toolbar.children].filter(el=>el!==refresh).forEach(el=>buttons.append(el));
  toolbar.append(buttons,searchBox);
  if(refresh){refresh.textContent='';refresh.setAttribute('aria-label','Refresh list');refresh.title='Refresh list';refresh.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 7v5h-5"/><path d="M19 12a7 7 0 1 0-1.7 5M20 7l-3-3"/></svg>';toolbar.append(refresh);}
  search.addEventListener('input',markDirty);search.addEventListener('change',markDirty);
  search.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.isComposing){event.preventDefault();form.requestSubmit(toolbar.querySelector('[data-list-apply]'));}});
  const toggle=panel.querySelector('[data-list-toggle]');toggle.addEventListener('click',()=>{form.hidden=!form.hidden;toggle.textContent=form.hidden?'Show Filters':'Hide Filters';toggle.setAttribute('aria-expanded',String(!form.hidden));});
  // School year → year level → section choices update without submitting the form.
  const sectionData=JSON.parse(panel.dataset.sections||'[]'), levelData=JSON.parse(panel.dataset.levels||'[]'), school=form.elements.sy, year=form.elements.year, section=form.elements.section;
  function updateSections(){
    if(!section||!sectionData.length)return;
    [...section.options].forEach(option=>{if(!option.value)return;const s=sectionData.find(s=>s.id===option.value);option.disabled=option.hidden=!!(s&&((school?.value&&s.sy!==school.value)||(year?.value&&s.year!==year.value)));});
    if(section.selectedOptions[0]?.disabled)section.value='';
  }
  function updateYears(){if(year&&school&&(levelData.length||sectionData.length)){const values=new Set([...sectionData,...levelData].filter(item=>!school.value||(item.sy||'__unassigned')===school.value).map(item=>item.year||'__unassigned'));[...year.options].forEach(option=>{if(option.value)option.disabled=option.hidden=!values.has(option.value);});if(year.selectedOptions[0]?.disabled)year.value='';}updateSections();}
  school?.addEventListener('change',updateYears);updateYears();year?.addEventListener('change',updateSections);updateSections();
  if(document.body.dataset.route==='bulkaccounts'){const type=form.elements.group;function accountType(){['sy','year','section'].forEach(key=>form.elements[key].disabled=type.value!=='STUDENT');}type.addEventListener('change',accountType);accountType();}
  // Lookup pickers inside Add/Edit forms are independent of result tables.
  const main=document.getElementById('main');
  let tables=[...main.querySelectorAll('table')].filter(t=>(!t.closest('form,dialog,.modal')||t.querySelector('#bulkRecords'))&&!t.closest('#bulkJob')&&!t.closest('#entryDetail')&&!t.closest('#paymentPlans')&&!t.closest('[data-record-detail]'));
  if(document.body.dataset.route==='migration')tables=tables.filter(t=>t.closest('section')?.querySelector('h2')?.textContent.trim()==='Recent batches');
  if(document.body.dataset.route==='bulkaccounts')tables=tables.filter(t=>t.querySelector('#bulkRecords'));
  if(document.body.dataset.route==='accounting'&&new URLSearchParams(location.search).get('tab')==='financial')tables=tables.filter(t=>!t.closest('[data-record-list]'));
  const getRows=()=>tables.flatMap(t=>[...t.querySelectorAll('tbody > tr')]).filter(tr=>!tr.querySelector('[colspan]')&&tr.cells.length>1);
  const print=panel.querySelector('[data-list-print]');
  const rowActions=[...panel.querySelectorAll('[data-row]')];
  const open=document.createElement('button');open.type='button';open.className='btn btn-sm btn-outline-secondary record-selection-action';open.textContent='Open selected';open.disabled=true;
  const hasOpen=getRows().some(tr=>tr.querySelector('td:last-child a[href],td a[href*="?r="]'));
  if(hasOpen&&!rowActions.length){panel.querySelector('[data-list-actions]').append(open);open.addEventListener('click',()=>{const link=selected&&(selected.querySelector('td:last-child a[href]')||selected.querySelector('td a[href*="?r="]'));if(link&&!dirty)link.click();});}
  function deselect(){selected=null;getRows().forEach(tr=>{tr.classList.remove('is-selected','selected');tr.setAttribute('aria-selected','false');});open.disabled=true;rowActions.forEach(el=>{if('disabled' in el)el.disabled=true;el.setAttribute('aria-disabled','true');});document.querySelectorAll('[data-row-fill]').forEach(el=>{if('value' in el)el.value='';});}
  function markDirty(){if(dirty)return;document.querySelectorAll('#bulkSelect [name="records[]"]').forEach(input=>input.checked=false);document.querySelectorAll('#bulkSelect button[type=submit],#bulkSelect button:not([type])').forEach(button=>button.disabled=true);dirty=true;panel.classList.add('is-dirty');deselect();print.disabled=true;status.textContent='Filters changed — click Apply Filters.';}
  form.addEventListener('input',markDirty);form.addEventListener('change',markDirty);
  tables.forEach(table=>{
    ['click','dblclick','keydown'].forEach(name=>table.addEventListener(name,event=>{if(dirty){event.preventDefault();event.stopImmediatePropagation();}},true));
    if(!applied){const body=table.tBodies[0]||table.createTBody();body.replaceChildren();const row=body.insertRow(),cell=row.insertCell();cell.colSpan=table.tHead?.rows[0]?.cells.length||6;cell.className='text-center text-muted py-4';cell.textContent='Choose your filters, then click Apply Filters to show records.';}
    table.addEventListener('click',event=>{const row=event.target.closest('tbody tr');if(!row||row.querySelector('[colspan]')||dirty)return;getRows().forEach(tr=>{tr.classList.toggle('is-selected',tr===row);tr.setAttribute('aria-selected',String(tr===row));});selected=row;open.disabled=!row.querySelector('td:last-child a[href],td a[href*="?r="]');});
    table.addEventListener('keydown',event=>{if(event.key==='Enter'&&event.target.tagName==='TR')event.target.click();});
    getRows().forEach(row=>{if(!row.hasAttribute('tabindex'))row.tabIndex=0;});
  });
  const rows=getRows(), total=rows.length+main.querySelectorAll('[data-record-card]').length;print.disabled=!applied||total===0;
  if(applied)status.textContent=total?`${total} record${total===1?'':'s'} shown for the applied filters.`:'No records match your filters.';
  // Preserve the applied filters across row edits and pagination, never across module tabs.
  if(applied){const params=new URLSearchParams(location.search);main.querySelectorAll('a[href]').forEach(link=>{const target=new URL(link.href,location.href);if(target.origin!==location.origin||target.pathname!==location.pathname||target.searchParams.get('r')!==params.get('r'))return;
    if(['edit','open','id','page'].some(k=>target.searchParams.has(k))&&(target.searchParams.get('tab')||'')===(params.get('tab')||'')){params.forEach((value,key)=>{if(!['id','edit','open','entry','page','action'].includes(key)&&!target.searchParams.has(key))target.searchParams.set(key,value);});link.href=target.href;}
  });}
  form.addEventListener('submit',()=>{status.textContent='Loading records…';panel.setAttribute('aria-busy','true');});
})();

},10);})(window.SISLifecycle ? window.SISLifecycle.register : function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
