/* v65.2: Add / Edit curriculum page (Curriculum details, Subjects and Teachers tabs, Select subjects and Select teacher windows).
   - The ticked boxes subjectIds[] (inside the Select subjects window) are what is saved; the window works on a copy and only
     changes them on "Confirm selection".
   - Each subject's teacher is a hidden input; the teacher list is written once (in the Select teacher window), so 1,000 subjects
     × 1,000 teachers do not freeze the browser (v64.6).
   - Saving sends the teacher of the selected subjects only, so the form stays under the server's input limit (1,000). */
(function(register){register('curriculum-picker',function(scope){
 'use strict';
 const form=document.querySelector('form[data-cur-edit]');
 if(!form)return;
 const PROF='_acadlink_professor_x',PER=10;
 const $=(sel,root)=>(root||document).querySelector(sel),$$=(sel,root)=>Array.from((root||document).querySelectorAll(sel));
 const boxes=$$('input[type=checkbox][name="subjectIds[]"]',form);
 const byId=new Map(boxes.map(b=>[b.value,b]));
 const canEdit=boxes.some(b=>!b.disabled)||!!$('[data-cur-open-picker]',form);
 const num=v=>{const n=parseFloat(v);return isFinite(n)?n:0;};
 const fmt=n=>String(+n.toFixed(2));
 const plural=(n,w)=>n+' '+w+(n===1?'':'s');
 const cell=(text,cls)=>{const td=document.createElement('td');if(cls)td.className=cls;td.textContent=text;return td;};
 const field=n=>{const f=form.elements[n];return f?String(f.value||'').trim():'';};
 const context=()=>[field('program'),field('yearLevel'),field('term')].filter(Boolean).join(' • ');

 /* ---------- unsaved changes ---------- */
 let dirty=false,saving=false;const dirtyEl=$('[data-cur-dirty]',form);
 const markDirty=()=>{dirty=true;if(dirtyEl){dirtyEl.textContent='Unsaved changes';dirtyEl.classList.add('is-dirty');}};
 scope.on(form,'input',e=>{if(!e.target.closest('dialog')&&!e.target.matches('[type=search]'))markDirty();});
 scope.on(form,'change',e=>{if(!e.target.closest('dialog')&&!e.target.matches('[type=search]'))markDirty();});
 scope.on(window,'beforeunload',e=>{if(dirty&&!saving){e.preventDefault();e.returnValue='';}});
 scope.on(form,'submit',()=>{saving=true;
   $$('input[data-cur-teacher-input]',form).forEach(inp=>{const row=inp.closest('[data-cur-teacher-row]');const b=row&&byId.get(row.dataset.curTeacherRow);inp.disabled=!(b&&b.checked);});});

 /* ---------- tabs ---------- */
 const tabs=$$('[data-cur-tab]',form);
 const showTab=t=>{tabs.forEach(x=>{const on=x===t;x.classList.toggle('active',on);x.setAttribute('aria-selected',on?'true':'false');x.tabIndex=on?0:-1;const p=document.getElementById(x.dataset.curTab);if(p)p.hidden=!on;});};
 tabs.forEach(t=>{scope.on(t,'click',()=>showTab(t));scope.on(t,'keydown',e=>{if(e.key!=='ArrowRight'&&e.key!=='ArrowLeft')return;e.preventDefault();const i=(tabs.indexOf(t)+(e.key==='ArrowRight'?1:tabs.length-1))%tabs.length;showTab(tabs[i]);tabs[i].focus();});});

 /* ---------- Subjects tab ---------- */
 const selBody=$('[data-cur-selected]',form),meta=$('[data-cur-meta]',form);
 const chosen=()=>boxes.filter(b=>b.checked);
 function renderSelected(){
   const list=chosen();let units=0;selBody.textContent='';
   list.forEach(b=>{units+=num(b.dataset.units);const tr=document.createElement('tr');
     tr.append(cell(b.dataset.code,'cur-code'),cell(b.dataset.name),cell(b.dataset.units,'text-center'));
     if(canEdit){const td=document.createElement('td');td.className='text-center';const btn=document.createElement('button');btn.type='button';btn.className='cur-trash';btn.dataset.curRemove=b.value;btn.setAttribute('aria-label','Remove '+b.dataset.name);btn.innerHTML='<i class="bi bi-trash3" aria-hidden="true"></i>';td.append(btn);tr.append(td);}
     selBody.append(tr);});
   if(!list.length){const tr=document.createElement('tr'),td=cell(canEdit?'No subjects yet. Press Add subjects.':'No subjects.','text-center text-muted py-4');td.colSpan=canEdit?4:3;tr.append(td);selBody.append(tr);}
   if(meta)meta.textContent=plural(list.length,'subject')+' • '+fmt(units)+' total units';
   const cs=$('[data-cur-count-subjects]',form);if(cs)cs.textContent='('+list.length+')';
   renderTeachers();
 }
 scope.on(selBody,'click',e=>{const btn=e.target.closest('[data-cur-remove]');if(!btn)return;const b=byId.get(btn.dataset.curRemove);if(b){b.checked=false;markDirty();renderSelected();}});

 /* ---------- Select subjects window ---------- */
 const dlg=$('[data-cur-picker]',form);let pending=new Set();
 function renderPicker(){
   $$('#subjpick tbody tr[data-cur-sid]',dlg).forEach(tr=>{const on=pending.has(tr.dataset.curSid),btn=$('[data-cur-toggle]',tr);
     btn.className='btn btn-sm cur-act '+(on?'is-added':'is-add');btn.disabled=on;btn.innerHTML=on?'<i class="bi bi-check2" aria-hidden="true"></i> Added':'<i class="bi bi-plus-lg" aria-hidden="true"></i> Add';
     btn.setAttribute('aria-label',(on?'Added: ':'Add ')+(byId.get(tr.dataset.curSid)?.dataset.name||''));});
   const body=$('[data-cur-picked-list]',dlg);body.textContent='';let units=0;
   boxes.filter(b=>pending.has(b.value)).forEach(b=>{units+=num(b.dataset.units);const tr=document.createElement('tr');tr.append(cell(b.dataset.code,'cur-code'),cell(b.dataset.name),cell(b.dataset.units,'text-center cur-units'));
     const td=document.createElement('td');td.className='text-center';const btn=document.createElement('button');btn.type='button';btn.className='btn btn-sm cur-act is-remove';btn.dataset.curUnpick=b.value;btn.textContent='Remove';btn.setAttribute('aria-label','Remove '+b.dataset.name);td.append(btn);tr.append(td);body.append(tr);});
   if(!pending.size){const tr=document.createElement('tr'),td=cell('No subjects selected yet.','text-center text-muted py-3');td.colSpan=4;tr.append(td);body.append(tr);}
   $('[data-cur-pill]',dlg).textContent=plural(pending.size,'subject')+' • '+fmt(units)+' units';
   $('[data-cur-picked]',dlg).textContent=plural(pending.size,'subject')+' selected';
 }
 if(dlg){
   const opener=$('[data-cur-open-picker]',form);
   if(opener)scope.on(opener,'click',()=>{pending=new Set(chosen().map(b=>b.value));
     $('[data-cur-picker-sub]',dlg).textContent=[field('curriculumName'),field('yearLevel'),field('term')].filter(Boolean).join(' • ')||context();
     renderPicker();dlg.showModal();const s=$('input[type=search]',dlg);if(s)s.focus();});
   scope.on(dlg,'click',e=>{
     const add=e.target.closest('[data-cur-toggle]');if(add){pending.add(add.closest('tr').dataset.curSid);renderPicker();return;}
     const rm=e.target.closest('[data-cur-unpick]');if(rm){pending.delete(rm.dataset.curUnpick);renderPicker();return;}
     if(e.target.closest('[data-cur-cancel-picker]'))dlg.close();
     if(e.target.closest('[data-cur-confirm-picker]')){let changed=false;boxes.forEach(b=>{const on=pending.has(b.value);if(b.checked!==on){b.checked=on;changed=true;}});if(changed)markDirty();renderSelected();dlg.close();}
   });
 }

 /* ---------- Teachers tab ---------- */
 const trows=$$('[data-cur-teacher-row]',form),tsearch=$('[data-cur-teacher-search]',form);let tpage=1;
 const assigned=tr=>{const v=$('[data-cur-teacher-input]',tr).value;return v&&v!==PROF;};
 function renderTeachers(){
   const q=tsearch?tsearch.value.trim().toLowerCase():'';let shown=[],total=0,open=0,done=0;
   trows.forEach(tr=>{const b=byId.get(tr.dataset.curTeacherRow),inList=!!(b&&b.checked);tr.hidden=true;if(!inList)return;total++;
     const ok=assigned(tr);if(ok)done++;else open++;
     const name=$('[data-cur-tname]',tr);if(!ok)name.textContent='';
     const btn=$('[data-cur-choose]',tr);if(btn){btn.textContent=ok?'Change':'Choose teacher';btn.className='btn btn-sm cur-choose '+(ok?'is-change':'is-choose');}
     else if(!ok)name.textContent='Professor X · Needs teacher';
     if(!q||tr.textContent.toLowerCase().includes(q))shown.push(tr);});
   const pages=Math.max(1,Math.ceil(shown.length/PER));if(tpage>pages)tpage=pages;
   shown.slice((tpage-1)*PER,tpage*PER).forEach(tr=>tr.hidden=false);
   const empty=$('[data-cur-teacher-empty]',form);if(empty){empty.hidden=shown.length>0;empty.firstElementChild.textContent=total?'No subject matches your search.':'Add subjects first.';}
   const un=$('[data-cur-unassigned]',form);if(un){un.hidden=open===0;un.textContent=open+' unassigned';}
   const ct=$('[data-cur-count-teachers]',form);if(ct)ct.textContent='('+done+')';
   const info=$('[data-cur-tpage-info]',form);if(info)info.textContent=total?'Showing '+Math.min(PER,shown.length-(tpage-1)*PER)+' of '+plural(shown.length,'subject'):'';
   const nav=$('[data-cur-tpages]',form);if(nav){nav.textContent='';if(pages>1){
     const mk=(label,p,dis,cur)=>{const b=document.createElement('button');b.type='button';b.className='cur-pg'+(cur?' is-current':'');b.disabled=dis;b.dataset.curTpage=p;b.innerHTML=label;if(cur)b.setAttribute('aria-current','page');return b;};
     nav.append(mk('<i class="bi bi-chevron-left" aria-label="Previous page"></i>',tpage-1,tpage===1));
     for(let p=1;p<=pages;p++)nav.append(mk(String(p),p,false,p===tpage));
     nav.append(mk('<i class="bi bi-chevron-right" aria-label="Next page"></i>',tpage+1,tpage===pages));}}
 }
 if(tsearch)scope.on(tsearch,'input',()=>{tpage=1;renderTeachers();});
 scope.on(form,'click',e=>{const p=e.target.closest('[data-cur-tpage]');if(p&&!p.disabled){tpage=+p.dataset.curTpage;renderTeachers();}});

 /* ---------- Select teacher window ---------- */
 const tdlg=$('[data-cur-tpicker]');let forRow=null;
 if(tdlg){
   const radios=$$('input[name=curTeacherPick]',tdlg),ok=$('[data-cur-tconfirm]',tdlg),ts=$('[data-cur-tsearch]',tdlg),picked=$('[data-cur-tpicked]',tdlg);
   const sync=()=>{const r=radios.find(x=>x.checked);ok.disabled=!r;picked.textContent=r?'1 teacher selected':'No teacher selected';radios.forEach(x=>x.closest('tr').classList.toggle('is-picked',x.checked));};
   const filter=()=>{const q=ts.value.trim().toLowerCase();let n=0;radios.forEach(x=>{const tr=x.closest('tr'),hit=!q||tr.textContent.toLowerCase().includes(q);tr.hidden=!hit;if(hit)n++;});$('[data-cur-tnone]',tdlg).hidden=n>0;};
   scope.on(form,'click',e=>{const btn=e.target.closest('[data-cur-choose]');if(!btn)return;forRow=btn.closest('[data-cur-teacher-row]');
     const cur=$('[data-cur-teacher-input]',forRow).value;radios.forEach(x=>x.checked=x.value===cur);
     const c=forRow.cells;$('[data-cur-tpicker-subject]',tdlg).textContent=c[0].textContent.trim()+' · '+c[1].textContent.trim();
     $('[data-cur-tpicker-ctx]',tdlg).textContent=context();ts.value='';filter();sync();tdlg.showModal();ts.focus();
     const r=radios.find(x=>x.checked);if(r)r.closest('tr').scrollIntoView({block:'nearest'});});
   scope.on(tdlg,'change',sync);scope.on(ts,'input',filter);
   scope.on(tdlg,'click',e=>{
     if(e.target.closest('[data-cur-tcancel]')){tdlg.close();return;}
     if(e.target.closest('[data-cur-tconfirm]')){const r=radios.find(x=>x.checked);if(r&&forRow){const inp=$('[data-cur-teacher-input]',forRow);if(inp.value!==r.value){inp.value=r.value;markDirty();}
       $('[data-cur-tname]',forRow).textContent=$('[data-cur-tlabel]',r.closest('tr')).textContent;renderTeachers();}tdlg.close();
       const b=forRow&&$('[data-cur-choose]',forRow);if(b)b.focus();}
   });
 }
 renderSelected();
},96);})(window.SISLifecycle?window.SISLifecycle.register:function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
