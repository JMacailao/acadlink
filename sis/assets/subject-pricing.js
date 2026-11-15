/* v65.4: Subject pricing page (Assessment → Subject Fees → Set a subject price / Edit).
   Available subjects (Add / Added), the selected subjects with their own price per unit, the fee templates that charge
   them (chips + Choose templates), the summary and Save prices. The server saves each subject with SubjectFees::save. */
(function(register){register('subject-pricing',function(scope){
 'use strict';
 const form=document.querySelector('form[data-sp-form]'),dataEl=document.getElementById('spData');
 if(!form||!dataEl)return;
 let data;try{data=JSON.parse(dataEl.textContent);}catch(e){return;}
 const $=(s,r)=>(r||document).querySelector(s),$$=(s,r)=>Array.from((r||document).querySelectorAll(s));
 const byId=new Map(data.subjects.map(s=>[s.id,s]));
 const fee=$('#spFee'),basisSel=$('#spBasis'),top=$('#spRate');
 const RATE=/^\d{1,9}(\.\d{1,2})?$/;
 const clean=v=>String(v||'').replace(/[,\s₱]/g,'');
 const num=v=>{const n=parseFloat(clean(v));return isFinite(n)?n:0;};
 const peso=n=>'₱'+n.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
 const units=s=>{const b=basisSel?basisSel.value:'ALL';return b==='LEC'?s.lec:b==='LAB'?s.lab:s.units;};
 const fmtUnits=n=>String(+n.toFixed(2));
 const feeText=()=>{const o=fee.options[fee.selectedIndex];return o?o.textContent.split(' · ').slice(-1)[0]:'';};
 const existing=sid=>((data.existing[sid]||{})[fee.value])||null;
 const td=(t,c)=>{const e=document.createElement('td');if(c)e.className=c;e.textContent=t;return e;};

 /* ---------- unsaved changes ---------- */
 let dirty=false,saving=false;const dirtyEl=$('[data-cur-dirty]',form);
 const markDirty=()=>{dirty=true;if(dirtyEl){dirtyEl.textContent='Unsaved changes';dirtyEl.classList.add('is-dirty');}};
 scope.on(window,'beforeunload',e=>{if(dirty&&!saving){e.preventDefault();e.returnValue='';}});

 /* ---------- selected subjects: [{subject, fee, rate}] in the order added ---------- */
 const sel=[];
 data.initial.forEach(r=>{if(byId.has(r.subject)&&!sel.some(x=>x.subject===r.subject))sel.push({subject:r.subject,fee:r.fee||'',rate:r.rate||top.value||'0.00'});});
 const has=sid=>sel.some(x=>x.subject===sid);

 const avail=$('[data-sp-avail]',form),search=$('[data-sp-search]',form);
 // Available subjects: drawn once, then only their button changes
 data.subjects.forEach(s=>{const tr=document.createElement('tr');tr.dataset.sid=s.id;tr.dataset.text=(s.code+' '+s.name).toLowerCase();
   tr.append(td(s.code,'cur-code'),td(s.name),td(fmtUnits(s.units),'text-center'));
   const c=document.createElement('td');c.className='text-center';const b=document.createElement('button');b.type='button';b.dataset.spAdd=s.id;c.append(b);tr.append(c);avail.append(tr);});
 if(!data.subjects.length){const tr=document.createElement('tr'),c=td('No subjects yet. Add them in Curriculum → Subjects.','text-center text-muted py-4');c.colSpan=4;tr.append(c);avail.append(tr);}
 const none=document.createElement('tr');const nc=td('No subject matches your search.','text-center text-muted py-3');nc.colSpan=4;none.append(nc);none.hidden=true;avail.append(none);
 function renderAvail(){
   $$('button[data-sp-add]',avail).forEach(b=>{const on=has(b.dataset.spAdd),s=byId.get(b.dataset.spAdd);
     b.className=on?'sp-added':'btn btn-sm cur-act is-add';b.disabled=on;
     b.innerHTML=on?'<i class="bi bi-check-lg" aria-hidden="true"></i> Added':'<i class="bi bi-plus-lg" aria-hidden="true"></i> Add';
     b.setAttribute('aria-label',(on?'Added: ':'Add ')+s.code+' '+s.name);});
 }
 scope.on(search,'input',()=>{const q=search.value.trim().toLowerCase();let n=0;$$('tr[data-sid]',avail).forEach(tr=>{tr.hidden=!!q&&!tr.dataset.text.includes(q);if(!tr.hidden)n++;});none.hidden=n>0||!data.subjects.length;});
 scope.on(avail,'click',e=>{const b=e.target.closest('[data-sp-add]');if(!b||b.disabled)return;const sid=b.dataset.spAdd,ex=existing(sid);
   sel.push({subject:sid,fee:'',rate:ex&&!dirtyTop?ex.rate:(clean(top.value)||'0.00')});markDirty();render();});

 /* ---------- selected table ---------- */
 const body=$('[data-sp-selected]',form);
 function render(){
   body.textContent='';let total=0,u=0;
   sel.forEach((r,i)=>{const s=byId.get(r.subject);const n=units(s);const ok=RATE.test(clean(r.rate));const amt=ok?Math.round(num(r.rate)*100)*n/100:0;total+=amt;u+=n;
     const tr=document.createElement('tr');
     const code=td(s.code,'cur-code');const h1=document.createElement('input');h1.type='hidden';h1.name='p_subject[]';h1.value=r.subject;const h2=document.createElement('input');h2.type='hidden';h2.name='p_fee[]';h2.value=r.fee;code.append(h1,h2);
     const pc=document.createElement('td');const wrap=document.createElement('div');wrap.className='sp-peso sp-peso-sm';wrap.innerHTML='<span aria-hidden="true">₱</span>';
     const inp=document.createElement('input');inp.className='form-control form-control-sm'+(ok?'':' is-invalid');inp.name='p_rate[]';inp.inputMode='decimal';inp.autocomplete='off';inp.value=r.rate;inp.dataset.spRow=i;inp.setAttribute('aria-label','Price per unit for '+s.code);wrap.append(inp);pc.append(wrap);
     const rm=document.createElement('td');rm.className='text-center';const rb=document.createElement('button');rb.type='button';rb.className='cur-trash';rb.dataset.spRemove=r.subject;rb.setAttribute('aria-label','Remove '+s.code+' '+s.name);rb.innerHTML='<i class="bi bi-trash3" aria-hidden="true"></i>';rm.append(rb);
     tr.append(code,td(s.name),td(fmtUnits(n),'text-center sp-unitcol'),td(feeText(),'sp-feecol'),pc,td(peso(amt),'text-end sp-amt'),rm);body.append(tr);});
   if(!sel.length){const tr=document.createElement('tr'),c=td('No subjects yet. Press Add next to a subject above.','text-center text-muted py-4');c.colSpan=7;tr.append(c);body.append(tr);}
   $('[data-sp-foot]',form).textContent=peso(total);
   $('[data-sp-count]',form).textContent=sel.length;
   const st=k=>$('[data-sp-stat="'+k+'"]',form);
   st('count').textContent=sel.length;st('units').textContent=fmtUnits(u);st('rate').textContent=RATE.test(clean(top.value))?peso(num(top.value)):'—';st('total').textContent=peso(total);
   renderAvail();
 }
 scope.on(body,'input',e=>{const inp=e.target.closest('input[data-sp-row]');if(!inp)return;const r=sel[+inp.dataset.spRow];if(!r)return;r.rate=inp.value;markDirty();
   const ok=RATE.test(clean(inp.value));inp.classList.toggle('is-invalid',!ok);const s=byId.get(r.subject);const amt=ok?Math.round(num(inp.value)*100)*units(s)/100:0;inp.closest('tr').querySelector('.sp-amt').textContent=peso(amt);
   let total=0;sel.forEach(x=>{if(RATE.test(clean(x.rate)))total+=Math.round(num(x.rate)*100)*units(byId.get(x.subject))/100;});$('[data-sp-foot]',form).textContent=peso(total);$('[data-sp-stat="total"]',form).textContent=peso(total);});
 scope.on(body,'click',e=>{const b=e.target.closest('[data-sp-remove]');if(!b)return;const i=sel.findIndex(x=>x.subject===b.dataset.spRemove);if(i>=0){sel.splice(i,1);markDirty();render();}});

 /* ---------- fee, charged per and the price on top ---------- */
 let dirtyTop=false;
 scope.on(top,'input',()=>{dirtyTop=true;const ok=RATE.test(clean(top.value));top.classList.toggle('is-invalid',!ok);if(ok)sel.forEach(r=>{r.rate=clean(top.value);});markDirty();render();});
 scope.on(fee,'change',()=>{sel.forEach(r=>{if(r.fee)return;const ex=existing(r.subject);if(ex&&!dirtyTop)r.rate=ex.rate;});markDirty();render();});
 if(basisSel)scope.on(basisSel,'change',()=>{markDirty();render();});

 /* ---------- Set as default (the fee new prices start with) ---------- */
 const defBtn=$('[data-sp-default]',form),defMsg=$('[data-sp-default-msg]',form);
 scope.on(defBtn,'click',async()=>{const fd=new FormData();fd.append('_csrf',form.elements._csrf.value);fd.append('action','default_label');fd.append('label_id',fee.value);fd.append('ajax','1');
   defBtn.disabled=true;defMsg.textContent='Saving…';
   try{const r=await fetch(form.getAttribute('action')||location.href,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'fetch'}});defMsg.textContent=r.ok?'Default saved: '+feeText():'Could not save the default.';}
   catch(e){defMsg.textContent='Could not save the default.';}finally{defBtn.disabled=false;}});

 /* ---------- fee templates: chips and the Choose templates window ---------- */
 const tdlg=$('[data-sp-tdialog]',form),chips=$('[data-sp-chips]',form),tboxes=$$('input[name="templates[]"]',form);
 function renderChips(){chips.textContent='';const on=tboxes.filter(b=>b.checked);
   on.forEach(b=>{const c=document.createElement('span');c.className='sp-chip';c.append(document.createTextNode(b.dataset.name));const x=document.createElement('button');x.type='button';x.dataset.spUntick=b.value;x.setAttribute('aria-label','Do not charge in '+b.dataset.name);x.innerHTML='<i class="bi bi-x-lg" aria-hidden="true"></i>';c.append(x);chips.append(c);});
   if(!on.length){const e=document.createElement('span');e.className='sp-chip-none';e.textContent='No template selected';chips.append(e);}
   const tc=$('[data-sp-tcount]',form);if(tc)tc.textContent=on.length+' selected';}
 scope.on(chips,'click',e=>{const x=e.target.closest('[data-sp-untick]');if(!x)return;const b=tboxes.find(t=>t.value===x.dataset.spUntick);if(b){b.checked=false;markDirty();renderChips();}});
 scope.on($('[data-sp-choose]',form),'click',()=>{if(tdlg){tdlg.showModal();const s=$('[data-sp-tsearch]',tdlg);if(s){s.value='';s.dispatchEvent(new Event('input'));s.focus();}}});
 if(tdlg){scope.on(tdlg,'change',()=>{markDirty();renderChips();});
   scope.on(tdlg,'click',e=>{if(e.target.closest('[data-sp-tclose]'))tdlg.close();});
   const ts=$('[data-sp-tsearch]',tdlg);if(ts)scope.on(ts,'input',()=>{const q=ts.value.trim().toLowerCase();$$('.sp-titem',tdlg).forEach(l=>{l.hidden=!!q&&!l.textContent.toLowerCase().includes(q);});});}

 /* ---------- save ---------- */
 scope.on(form,'submit',e=>{
   if(!sel.length){e.preventDefault();search.focus();if(defMsg)defMsg.textContent='';alertMsg('Add at least one subject.');return;}
   const bad=$('input[data-sp-row].is-invalid',body);if(bad){e.preventDefault();bad.focus();alertMsg('Enter a price such as 450 or 450.50.');return;}
   $$('input[data-sp-row]',body).forEach(i=>{i.value=clean(i.value);});saving=true;});
 function alertMsg(t){let a=$('.sp-alert',form);if(!a){a=document.createElement('div');a.className='alert alert-warning sp-alert';a.setAttribute('role','alert');form.querySelector('.cur-savebar').before(a);}a.textContent=t;}

 // a price shows two decimals once you leave its box (450 → 450.00)
 const two=inp=>{const v=clean(inp.value);if(RATE.test(v))inp.value=num(v).toFixed(2);};
 scope.on(top,'focusout',()=>{two(top);if(RATE.test(clean(top.value)))sel.forEach(r=>{if(RATE.test(clean(r.rate)))r.rate=num(r.rate).toFixed(2);});render();});
 scope.on(body,'focusout',e=>{const inp=e.target.closest('input[data-sp-row]');if(!inp)return;two(inp);const r=sel[+inp.dataset.spRow];if(r)r.rate=inp.value;});
 render();renderChips();
},97);})(window.SISLifecycle?window.SISLifecycle.register:function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
