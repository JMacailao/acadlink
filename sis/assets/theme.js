/* SIS workspace presentation: collapsible sidebar groups, selectable record rows, status pills,
   aligned numbers, list footer with paging, and the Actions explorer (Record Actions, Module Tools,
   Related Modules). Existing controls are reused, never copied: explorer and toolbar buttons click the
   row's own links, so permissions, confirmations and workflow rules stay where they are. */
(function(register){register('sis-theme',function(scope){
 'use strict';
 const store={get(k){try{return localStorage.getItem(k);}catch{return null;}},set(k,v){try{localStorage.setItem(k,v);}catch{}}};

 // Sidebar groups. The group holding the current page always opens.
 document.querySelectorAll('#sidebar .nav-group').forEach(group=>{
   const button=group.querySelector('.nav-group-toggle'),items=group.querySelector('.nav-group-items'),key='sis.nav.'+group.dataset.navGroup;
   const set=open=>{button.setAttribute('aria-expanded',String(open));items.hidden=!open;group.classList.toggle('is-collapsed',!open);};
   set(group.querySelector('.side-link.active')?true:(store.get(key)||((document.body.classList.contains('sis-easy')||['Assessment','Accounting'].includes(group.dataset.navGroup))?'closed':'open'))!=='closed');
   if(!button.dataset.bound){button.dataset.bound='1';button.addEventListener('click',()=>{const open=button.getAttribute('aria-expanded')!=='true';set(open);store.set(key,open?'open':'closed');});}
 });

 // Ctrl+K (⌘K on a Mac) jumps to the search in the top bar.
 scope.on(document,'keydown',e=>{if((e.ctrlKey||e.metaKey)&&!e.altKey&&e.key.toLowerCase()==='k'){const q=document.getElementById('globalSearch');if(q&&q.offsetParent){e.preventDefault();q.focus();q.select();}}});
 const main=document.querySelector('.app-shell #main');if(!main||document.body.classList.contains('bare'))return;
 // The page's description sits under the breadcrumb, in the banner.
 const lede=main.querySelector('.sis-toolbar-description'),crumbs=main.querySelector('.sis-page-heading .breadcrumbs');if(lede&&crumbs&&!crumbs.nextElementSibling?.classList.contains('sis-heading-lede')){lede.classList.add('sis-heading-lede');crumbs.after(lede);}
 if(document.body.classList.contains('sis-easy')){
   const shortcuts=main.querySelector('.sis-student-shortcuts');
   if(shortcuts)main.querySelector('.sis-page-heading')?.append(shortcuts);
 }
 const route=document.body.dataset.route||'',params=new URLSearchParams(location.search),tab=params.get('tab')||'';
 const NOUNS={enrollment:'application',admission:'applicant',registration:'registration',cashier:'receipt',students:'student',teachers:'teacher',sections:'section',yearlevels:'year level',curriculum:'curriculum',assessment:tab==='names'?'fee template':tab==='labels'?'label':'category',discounts:'discount',users:'account',bulkaccounts:'record',migration:'batch',accounting:'entry',notifications:'notification',balance:'student',activity:'activity',history:'record'};
 const plural=n=>/[^aeiou]y$/.test(n)?n.slice(0,-1)+'ies':/(s|x|ch)$/.test(n)?n+'es':n+'s';
 const title=s=>s.replace(/\b\w/g,c=>c.toUpperCase());

 // Status words become pills: cells under a "Status" heading, or marked data-status.
 const TONES={pending:'pending','for review':'pending',draft:'pending','needs approval':'pending',unread:'pending',returned:'returned','payment due':'returned','transferred out':'returned',open:'info','for assessment':'info','ready to enroll':'info',irregular:'info',approved:'success',accepted:'success',admitted:'success',enrolled:'success',registered:'success',posted:'success',active:'success',done:'success',imported:'success',paid:'success',regular:'success',rejected:'danger',voided:'danger',void:'danger',cancelled:'danger',dropped:'danger',disabled:'neutral',inactive:'neutral',reversed:'neutral',discarded:'neutral',graduated:'neutral'};
 function pill(cell){
   if(cell.querySelector('.sis-pill,a,button,input,select,form'))return;
   const text=cell.textContent.trim();const tone=TONES[text.toLowerCase()];if(!tone||text.length>24)return;
   const span=document.createElement('span');span.className='sis-pill';span.dataset.tone=tone;
   span.textContent=text===text.toUpperCase()?title(text.toLowerCase()):text;cell.replaceChildren(span);
 }
 // Money and counts align right; identifiers such as Student IDs stay left.
 const MONEY=/^[-–]?\(?₱?\s?[-–]?[\d,]+\.\d{2}\)?$/,COUNT=/^[-–]?[\d,]+(\.\d+)?$/,NUMERIC_HEAD=/amount|total|balance|paid|debit|credit|units|count|classes|labels|students|registered|qty|quantity|percent|%|fee|charge|price|in$|out$/i;
 main.querySelectorAll('table').forEach(table=>{
   const heads=[...(table.tHead?.rows[0]?.cells||[])],body=[...(table.tBodies[0]?.rows||[])].filter(tr=>!tr.querySelector('[colspan]'));
   const col=heads.findIndex(th=>/^status$/i.test(th.textContent.trim()));
   body.forEach(tr=>{const marked=tr.querySelector('td[data-status]');if(marked)pill(marked);else if(col>=0&&tr.cells[col])pill(tr.cells[col]);});
   heads.forEach((th,i)=>{
     const values=body.map(tr=>tr.cells[i]?.textContent.trim()||'').filter(v=>v&&v!=='—'&&v!=='-');if(!values.length)return;
     const money=values.every(v=>MONEY.test(v)),count=NUMERIC_HEAD.test(th.textContent)&&values.every(v=>COUNT.test(v)||MONEY.test(v));
     if(money||count){th.classList.add('sis-num');body.forEach(tr=>tr.cells[i]?.classList.add('sis-num'));}
   });
 });

 // Related modules come from the permission-filtered sidebar, so only allowed modules appear.
 const RELATED={enrollment:['admission','registration','students'],admission:['enrollment','registration','students'],registration:['cashier','students','eligibility','balance'],cashier:['registration','accounting&tab=review','reports'],balance:['cashier','registration','accounting&tab=overview'],eligibility:['registration','students','curriculum'],
   students:['registration','cashier','migration'],teachers:['sections','curriculum','users'],sections:['students','yearlevels','curriculum'],yearlevels:['sections','students','curriculum'],curriculum:['sections','assessment&tab=names','eligibility'],
   assessment:['discounts','assessment&tab=labels&plan=list','registration'],discounts:['assessment&tab=names','registration','cashier'],accounting:['cashier','balance','reports'],reports:['cashier','registration','accounting&tab=overview'],
   users:['bulkaccounts','teachers','students'],bulkaccounts:['users','teachers','students'],migration:['students','balance','accounting&tab=overview'],settings:['reports','users','activity'],activity:['users','settings'],history:['students','registration','reports'],notifications:['enrollment','admission','balance'],help:['dashboard','enrollment','registration']};
 function sideLink(key){
   const [r,...rest]=key.split('&'),want=new URLSearchParams(rest.join('&'));
   return [...document.querySelectorAll('#sidebar .side-link')].find(a=>{const u=new URL(a.href,location.href).searchParams;if((u.get('r')||'dashboard')!==r)return false;for(const [k,v] of want)if(u.get(k)!==v)return false;return !want.has('plan')?!u.has('plan'):true;});
 }

 // Record tables: the same results the shared record list gates behind Apply Filters.
 const list=document.querySelector('[data-record-list]');
 const tables=[...main.querySelectorAll('.sis-record-scroll table, .sis-actions-content table, #main > table')].filter(t=>t.tHead&&t.tBodies[0]&&!t.closest('form,dialog,.modal,#entryDetail,#paymentSetup,#paymentPlans,.payment-plan-summary,[data-record-list],.sis-actions-panel')&&!t.querySelector('input[type=checkbox]')&&(list||t.querySelector('tbody td:last-child a[href]')));
 // Only the result tables are the list; a detail table further down (e.g. a fee template's fee lines) keeps its own layout.
 const headOf=t=>t.tHead.rows[0].textContent.replace(/\s+/g,' ').trim();
 if(tables.length>1){const h=headOf(tables[0]);tables.splice(0,tables.length,...tables.filter(t=>headOf(t)===h));}
 const noun=tables[0]?.dataset.recordNoun||NOUNS[route]||'record';
 const isRow=tr=>tr.parentElement?.tagName==='TBODY'&&!tr.querySelector('[colspan]')&&tr.cells.length>1;
 const rows=()=>tables.flatMap(t=>[...t.tBodies[0].rows]).filter(isRow);
 // Row links and the row's own submit buttons (a form's confirmation still runs when proxied).
 const controlsOf=tr=>{const cells=[...tr.cells].filter(c=>!c.classList.contains('sis-row-icons')),cell=cells[cells.length-1];return cell?[...cell.querySelectorAll('a[href],button')].filter(el=>!el.closest('.dropdown-menu')&&(!el.closest('form')||(el.type==='submit'&&cell.contains(el.form)))&&(el.textContent.trim()||el.getAttribute('aria-label')||el.title)):[];};
 // Screen-reader-only text (e.g. the student's name after "View") stays out of the shared action label.
 const labelOf=el=>{const c=el.cloneNode(true);c.querySelectorAll('.visually-hidden').forEach(x=>x.remove());return c.textContent.trim()||el.getAttribute('aria-label')||el.title;};

 tables.forEach(table=>{
   if(table.dataset.themeSelect)return;table.dataset.themeSelect='1';table.classList.add('sis-select-table');
   // A last column holding only row links is shown in the explorer instead of as an empty-looking column.
   const body=[...table.tBodies[0].rows].filter(isRow),head=table.tHead.rows[0],lastHead=head.cells[head.cells.length-1];
   const onlyLinks=body.length&&body.every(tr=>{const c=tr.cells[tr.cells.length-1];return c&&!c.querySelector('form,input,select,textarea,.dropdown')&&!c.textContent.replace(/\s+/g,'').replace([...c.querySelectorAll('a,button')].map(e=>e.textContent.replace(/\s+/g,'')).join(''),'');});
   if((onlyLinks||!body.length&&/^\s*(actions?)?\s*$/i.test(lastHead.textContent))&&head.cells.length>2){lastHead.classList.add('sis-row-actions');body.forEach(tr=>tr.cells[tr.cells.length-1].classList.add('sis-row-actions'));}
   const th=document.createElement('th');th.className='sis-select-cell';th.innerHTML='<span class="visually-hidden">Select</span>';head.prepend(th);
   // Totals rows widen their first cell over the new column, so each total stays under its own heading.
   [...(table.tFoot?.rows||[])].forEach(tr=>{const c=tr.cells[0];if(c)c.colSpan+=1;});
   [...table.tBodies[0].rows].forEach(tr=>{
     if(!isRow(tr)){const wide=tr.querySelector('[colspan]');if(wide)wide.colSpan+=1;else tr.prepend(document.createElement('td'));return;}
     const td=document.createElement('td');td.className='sis-select-cell';
     const box=document.createElement('input');box.type='checkbox';box.className='form-check-input';box.tabIndex=-1;
     box.setAttribute('aria-label','Select '+(tr.cells[0]?.textContent.trim()||noun));
     box.addEventListener('click',e=>{e.stopPropagation();e.preventDefault();(tr.cells[1]||tr).click();});
     td.append(box);tr.prepend(td);
   });
 });
 // Tables outside the shared record list select rows the same way.
 if(!list)tables.forEach(table=>scope.on(table,'click',e=>{const tr=e.target.closest('tbody tr');if(!tr||!isRow(tr)||e.target.closest('a,button,input,select,textarea'))return;rows().forEach(r=>{r.classList.toggle('is-selected',r===tr);r.setAttribute('aria-selected',String(r===tr));});}));
 const ICONS=[[/^view|review|open|details/i,'eye'],[/edit|change|rename|manage/i,'pencil-square'],[/approve|accept|confirm|post\b|admit/i,'check2-square'],[/assess|fee|compute/i,'calculator'],[/grade/i,'journal-text'],[/history/i,'clock-history'],[/print|o\.r\.|receipt/i,'printer'],[/pay|cash|collect|receive/i,'cash-coin'],[/void|cancel|reject|remove|delete|discard|revoke/i,'x-circle'],[/password|reset/i,'key'],[/reverse/i,'arrow-counterclockwise'],[/copy|duplicate/i,'files'],[/import|upload/i,'upload'],[/export|download/i,'download'],[/account/i,'person-plus'],[/register|enrol/i,'journal-check']];
 const iconFor=(el,text)=>el?.dataset?.icon||(ICONS.find(([re])=>re.test(text))||[,'chevron-right'])[1];
 // Grid screens (Admission, Cashier, Registration…) keep their row actions on the toolbar (data-row, see app.js).
 const rowTools=[...main.querySelectorAll('.or-toolbar [data-row], .record-toolbar [data-row]')].filter(el=>labelOf(el));
 let all=rows();
 const current=()=>all.find(tr=>tr.classList.contains('is-selected'))||null;
 const sync=()=>all.forEach(tr=>{const box=tr.querySelector('.sis-select-cell input');if(box)box.checked=tr.classList.contains('is-selected');});
 const selectRow=tr=>{if(!tr.classList.contains('is-selected'))(tr.cells[1]||tr).click();};

 // Names get an initials badge, as on a class list ("Abaca, Jasmin Rose" → AJ).
 const TONE_COUNT=8;
 tables.forEach(table=>{
   const heads=[...table.tHead.rows[0].cells],ni=heads.findIndex(th=>/^(student|applicant|teacher|account|payer)?\s*name\b|^name\s*\/|^student$|^applicant$|^payer$/i.test(th.textContent.trim()));
   if(ni<0)return;
   [...table.tBodies[0].rows].filter(isRow).forEach(tr=>{
     const cell=tr.cells[ni];if(!cell||cell.querySelector('.sis-avatar'))return;
     const name=(cell.querySelector('strong,a')||cell).textContent.replace(/\s+/g,' ').trim();if(!name||name==='—')return;
     const words=name.replace(/[^\p{L}\s,]/gu,'').split(/[\s,]+/).filter(Boolean);
     const av=document.createElement('span');av.className='sis-avatar';av.setAttribute('aria-hidden','true');
     av.textContent=((words[0]||'')[0]||'')+((words[1]||'')[0]||'');av.textContent=av.textContent.toUpperCase();
     let h=0;for(const ch of name)h=(h*31+ch.codePointAt(0))>>>0;av.dataset.tone=String(h%TONE_COUNT);
     cell.classList.add('sis-name-cell');cell.prepend(av);
   });
 });

 // Each row gets its own View / Edit / ⋯ buttons. They click the row's own links, so rules and confirmations stay.
 const ROW_ICONS=[[/^(view|open|review)\b/i,'eye','View'],[/^manage fees\b/i,'cash-coin','Manage fees'],[/^edit\b/i,'pencil','Edit']];
 let menu=null;const closeMenu=()=>{menu?.remove();menu=null;};
 scope.on(document,'click',e=>{if(menu&&!menu.contains(e.target)&&!e.target.closest('.sis-row-more'))closeMenu();});
 scope.on(document,'keydown',e=>{if(e.key==='Escape'&&menu){const opener=menu._opener;closeMenu();opener?.focus();}});
 scope.cleanup(closeMenu);
 function openMenu(btn,tr,items){
   closeMenu();menu=document.createElement('div');menu.className='sis-row-menu';menu.setAttribute('role','menu');menu._opener=btn;
   items.forEach(({text,run,icon})=>{const it=document.createElement('button');it.type='button';it.setAttribute('role','menuitem');it.innerHTML='<i class="bi bi-'+icon+'" aria-hidden="true"></i>';it.append(document.createTextNode(text));it.addEventListener('click',()=>{closeMenu();run();});menu.append(it);});
   if(!items.length){const p=document.createElement('p');p.textContent='No other actions';menu.append(p);}
   document.body.append(menu);const r=btn.getBoundingClientRect(),w=menu.offsetWidth,h=menu.offsetHeight;
   menu.style.left=Math.max(8,Math.min(innerWidth-w-8,r.right-w))+'px';menu.style.top=(r.bottom+h+8>innerHeight?r.top-h-4:r.bottom+4)+'px';
   menu.querySelector('button')?.focus();
 }
 const rowActions=tr=>{
   const own=controlsOf(tr).map(el=>({text:labelOf(el),icon:iconFor(el,labelOf(el)),run:()=>{selectRow(tr);el.click();},el}));
   if(own.length)return own;
   return rowTools.map(el=>({text:labelOf(el),icon:iconFor(el,labelOf(el)),run:()=>{selectRow(tr);render();if(!el.disabled)el.click();},el}));
 };
 tables.forEach(table=>{
   if(table.dataset.themeRowIcons)return;
   const body=[...table.tBodies[0].rows].filter(isRow);
   if(!body.some(tr=>rowActions(tr).length))return;
   table.dataset.themeRowIcons='1';
   const th=document.createElement('th');th.className='sis-row-icons';th.textContent='Actions';table.tHead.rows[0].append(th);
   [...(table.tFoot?.rows||[])].forEach(tr=>{const c=tr.cells[tr.cells.length-1];if(c)c.colSpan+=1;});
   [...table.tBodies[0].rows].forEach(tr=>{
     if(!isRow(tr)){const wide=tr.querySelector('[colspan]');if(wide)wide.colSpan+=1;return;}
     const td=document.createElement('td');td.className='sis-row-icons';const acts=rowActions(tr),who=tr.cells[1]?.textContent.trim()||noun;
     const used=new Set();
     ROW_ICONS.forEach(([re,icon,word])=>{const a=acts.find(x=>re.test(x.text));if(!a)return;used.add(a);const b=document.createElement('button');b.type='button';b.className='sis-row-btn';b.innerHTML='<i class="bi bi-'+icon+'" aria-hidden="true"></i>';b.title=a.text;b.setAttribute('aria-label',a.text+': '+who);b.addEventListener('click',e=>{e.stopPropagation();a.run();});td.append(b);});
     const more=document.createElement('button');more.type='button';more.className='sis-row-btn sis-row-more';more.innerHTML='<i class="bi bi-three-dots" aria-hidden="true"></i>';more.setAttribute('aria-label','More actions: '+who);more.setAttribute('aria-haspopup','menu');
     more.addEventListener('click',e=>{e.stopPropagation();selectRow(tr);render();if(menu&&menu._opener===more){closeMenu();return;}openMenu(more,tr,acts);});
     td.append(more);tr.append(td);
   });
 });

 // Sorting: click a column heading (again to reverse). Numbers and dates sort by value.
 const sortKey=t=>{const v=t.replace(/[₱,\s]/g,'');return /^-?\d+(\.\d+)?$/.test(v)?Number(v):t.toLowerCase();};
 tables.forEach(table=>{
   const head=table.tHead.rows[0];
   [...head.cells].forEach((th,i)=>{
     if(th.classList.contains('sis-select-cell')||th.classList.contains('sis-row-icons')||th.classList.contains('sis-row-actions')||!th.textContent.trim()||th.querySelector('button,a,input'))return;
     const label=th.textContent.trim();const b=document.createElement('button');b.type='button';b.className='sis-sort';b.innerHTML='<span></span><i class="bi bi-arrow-down-up" aria-hidden="true"></i>';b.firstChild.textContent=label;
     th.replaceChildren(b);th.setAttribute('aria-sort','none');
     b.addEventListener('click',()=>{
       const dir=th.getAttribute('aria-sort')==='ascending'?'descending':'ascending';
       head.querySelectorAll('th[aria-sort]').forEach(x=>{x.setAttribute('aria-sort','none');const ic=x.querySelector('.sis-sort i');if(ic)ic.className='bi bi-arrow-down-up';});
       th.setAttribute('aria-sort',dir);b.querySelector('i').className='bi bi-arrow-'+(dir==='ascending'?'up':'down');
       const tb=table.tBodies[0],list=[...tb.rows].filter(isRow),rest=[...tb.rows].filter(r=>!isRow(r));
       list.sort((x,y)=>{const a=sortKey(x.cells[i]?.textContent.trim()||''),c=sortKey(y.cells[i]?.textContent.trim()||'');const r=typeof a==='number'&&typeof c==='number'?a-c:String(a).localeCompare(String(c),undefined,{numeric:true});return dir==='ascending'?r:-r;});
       list.forEach(r=>tb.append(r));rest.forEach(r=>tb.append(r));
       all=rows();page=0;show();
     });
   });
 });

 // List bar above the table: selection, "Showing 1–15 of N", numbered pages and rows per page. Printing shows every row.
 const SIZES=[15,30,50,100];let size=Number(store.get('sis.perpage'))||15;if(!SIZES.includes(size))size=15;
 let page=0;const pages=()=>Math.max(1,Math.ceil(all.length/size));
 const bar=document.createElement('div');bar.className='sis-list-bar d-print-none';
 const pick=document.createElement('label');pick.className='sis-list-pick';
 const pickBox=document.createElement('input');pickBox.type='checkbox';pickBox.className='form-check-input';pickBox.disabled=true;pickBox.setAttribute('aria-label','Selected rows');
 const count=document.createElement('span');count.className='sis-list-count';count.setAttribute('aria-live','polite');pick.append(pickBox,count);
 pickBox.addEventListener('click',e=>{e.preventDefault();const tr=current();if(tr)(tr.cells[1]||tr).click();});
 const right=document.createElement('div');right.className='sis-list-right';
 const range=document.createElement('span');range.className='sis-pager-range';
 const pager=document.createElement('nav');pager.className='sis-pager';pager.setAttribute('aria-label','Pages');
 const per=document.createElement('select');per.className='form-select form-select-sm sis-per-page';per.setAttribute('aria-label','Rows per page');SIZES.forEach(n=>{const o=document.createElement('option');o.value=n;o.textContent=n+' / page';o.selected=n===size;per.append(o);});
 per.addEventListener('change',()=>{size=Number(per.value);store.set('sis.perpage',String(size));page=0;show();});
 right.append(range,pager,per);bar.append(pick,right);
 const pbtn=(html,label,to,cur)=>{const b=document.createElement('button');b.type='button';b.className='sis-pager-btn'+(cur?' is-current':'');b.innerHTML=html;b.setAttribute('aria-label',label);if(cur)b.setAttribute('aria-current','page');b.addEventListener('click',()=>go(to));return b;};
 function drawPager(){
   const n=pages(),list=[];let from=Math.max(0,Math.min(page-2,n-5)),to=Math.min(n-1,from+4);
   const prev=pbtn('<i class="bi bi-chevron-left" aria-hidden="true"></i>','Previous page',page-1);prev.disabled=page===0;list.push(prev);
   for(let i=from;i<=to;i++)list.push(pbtn(String(i+1),'Page '+(i+1),i,i===page));
   const next=pbtn('<i class="bi bi-chevron-right" aria-hidden="true"></i>','Next page',page+1);next.disabled=page>=n-1;list.push(next);
   pager.replaceChildren(...list);
 }
 function show(){
   page=Math.min(page,pages()-1);
   all.forEach((tr,i)=>tr.classList.toggle('sis-off-page',Math.floor(i/size)!==page));
   const a=all.length?page*size+1:0,b=Math.min(all.length,(page+1)*size);
   const many=all.length===1?noun:plural(noun);
   range.textContent=all.length?`Showing ${a}–${b} of ${all.length.toLocaleString()} ${many}`:`No ${plural(noun)}`;
   const picked=all.filter(tr=>tr.classList.contains('is-selected')).length;
   count.textContent=`${picked} selected`;pickBox.checked=picked>0;pickBox.disabled=!picked;
   drawPager();
 }
 const go=p=>{page=Math.min(pages()-1,Math.max(0,p));show();tables[0]?.closest('.sis-record-scroll')?.scrollTo({top:0});};
 if(tables.length&&list&&list.dataset.applied==='1'&&!main.querySelector('.workspace-pagination,.pagination')){const t=tables[0];(t.closest('.table-responsive,.or-grid-wrap')||t).before(bar);}
 show();

 // The Actions explorer.
 // On small screens the explorer already lives in its drawer, outside #main.
 const panel=main.querySelector('.sis-actions-panel')||document.querySelector('.sis-actions-drawer .sis-actions-panel');
 const actionButton=(icon,text)=>{const b=document.createElement('button');b.type='button';b.className='sis-panel-action';b.innerHTML='<i class="bi bi-'+icon+'" aria-hidden="true"></i>';b.append(document.createTextNode(text));return b;};
 function section(name,cls){const s=document.createElement('section');s.className='sis-panel-section '+(cls||'');const h=document.createElement('h2');h.textContent=name;const body=document.createElement('div');body.className='sis-panel-body';s.append(h,body);return s;}
 function collapsible(sec){
   const h=sec.querySelector(':scope > h2, :scope > .or-panel-title');if(!h||h.querySelector('.sis-panel-toggle'))return;
   const text=h.textContent.trim(),key='sis.panel.'+route+'.'+text;const b=document.createElement('button');b.type='button';b.className='sis-panel-toggle';
   b.append(document.createTextNode(text));b.insertAdjacentHTML('beforeend','<i class="bi bi-chevron-up" aria-hidden="true"></i>');h.replaceChildren(b);
   const set=open=>{b.setAttribute('aria-expanded',String(open));sec.classList.toggle('is-collapsed',!open);};
   set(store.get(key)!=='closed');b.addEventListener('click',()=>{const open=b.getAttribute('aria-expanded')!=='true';set(open);store.set(key,open?'open':'closed');});
 }
 const slots=[];// {group,text,button} — one per distinct row action, enabled when the selected row offers it
 if(panel&&!panel.dataset.theme){
   panel.dataset.theme='1';
   const heading=panel.querySelector('.sis-actions-heading h2');if(heading)heading.textContent='Actions';
   panel.querySelectorAll('section').forEach(s=>{
     const h=s.querySelector(':scope > h2');const name=h?.textContent.trim();
     if(name==='Record Actions')s.classList.add('sis-legacy-record-actions');
     else if(name==='Other Actions'){if(!s.querySelector('a,button,form'))s.remove();else{h.textContent='Tools';s.classList.add('sis-module-tools');}}
   });
   panel.querySelectorAll('.or-panel-title').forEach(t=>{if(/sub-?components/i.test(t.textContent))t.textContent='Tools';});
   const top=panel.querySelector('.sis-actions-heading');let after=top;
   const place=s=>{if(after)after.after(s);else panel.prepend(s);after=s;};
   if(all.length){
     // Every distinct row action, in first-seen order, grouped like the rows group them.
     const groups=new Map();
     all.forEach(tr=>controlsOf(tr).forEach(el=>{const g=el.dataset.panelGroup||'',t=labelOf(el);if(!groups.has(g))groups.set(g,new Map());if(!groups.get(g).has(t))groups.get(g).set(t,iconFor(el,t));}));
     if(!groups.has(''))groups.set('',new Map());
     rowTools.forEach(el=>{const t=labelOf(el);if(!groups.get('').has(t))groups.get('').set(t,iconFor(el,t));});
     [['',groups.get('')],...[...groups].filter(([g])=>g)].forEach(([g,items])=>{
       const s=section(g||'Record Actions',g?'':'sis-record-actions');const body=s.querySelector('.sis-panel-body');
       items.forEach((icon,text)=>{const b=actionButton(icon,text);b.disabled=true;b.setAttribute('aria-disabled','true');body.append(b);slots.push({group:g,text,button:b});});
       if(!items.size){const p=document.createElement('p');p.className='sis-panel-empty';p.textContent='No actions for these records.';body.append(p);}
       place(s);
     });
   }
   const tools=[...panel.querySelectorAll('.sis-module-tools,.or-side')];tools.forEach(t=>{if(after)after.after(t);after=t;});
   const related=(RELATED[route]||[]).map(sideLink).filter(Boolean).filter(a=>!a.classList.contains('active'));
   if(related.length){const s=section('Related Modules','sis-related');const body=s.querySelector('.sis-panel-body');related.forEach(a=>{const link=document.createElement('a');link.className='sis-panel-action';link.href=a.href;link.innerHTML=a.querySelector('i')?.outerHTML||'';link.append(document.createTextNode(a.textContent.trim()));body.append(link);});panel.append(s);}
   if(all.length){const hint=document.createElement('p');hint.className='sis-panel-hint';hint.innerHTML='<i class="bi bi-info-circle" aria-hidden="true"></i>';hint.append(document.createTextNode(`Select ${/^[aeiou]/.test(noun)?'an':'a'} ${noun} to use record actions.`));panel.append(hint);}
   // Quick Actions: the list's own Add, Import, Print and Reports, when this person has them.
   const quick=section('Quick Actions','sis-quick'),qb=quick.querySelector('.sis-panel-body');
   const qAdd=document.querySelector('.record-toolbar [data-list-add] > *');
   if(qAdd){const b=actionButton('plus-lg',title((qAdd.title||qAdd.textContent).replace(/^new\b/i,'Add').toLowerCase()));b.addEventListener('click',()=>qAdd.click());qb.append(b);}
   const qImport=[...panel.querySelectorAll('.sis-module-tools a, .or-side a')].find(a=>/import/i.test(a.textContent));
   if(qImport){const a=document.createElement('a');a.className='sis-panel-action';a.href=qImport.href;a.innerHTML='<i class="bi bi-upload" aria-hidden="true"></i>';a.append(document.createTextNode(qImport.textContent.trim()));qb.append(a);}
   const qPrint=document.querySelector('.record-toolbar [data-list-print]');
   if(qPrint&&all.length){const b=actionButton('printer','Print '+title(plural(noun))+' List');b.addEventListener('click',()=>qPrint.click());qb.append(b);}
   const qReports=route!=='reports'&&sideLink('reports');
   if(qReports){const a=document.createElement('a');a.className='sis-panel-action';a.href=qReports.href;a.innerHTML='<i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i>Generate Reports';qb.append(a);}
   if(qb.children.length)top?top.after(quick):panel.prepend(quick);
   // School Year card: the same choice as the top bar.
   const years=[...document.querySelectorAll('.sis-appbar-year .dropdown-item')];
   if(years.length){
     const card=document.createElement('div');card.className='sis-year-card';
     card.innerHTML='<i class="bi bi-calendar3" aria-hidden="true"></i><label><small>School Year</small><select class="form-select form-select-sm" aria-label="School year"></select></label>';
     const sel=card.querySelector('select');years.forEach(a=>{const o=document.createElement('option');o.value=a.href;o.textContent=a.textContent.trim().replace('-',' - ');o.selected=a.classList.contains('active');sel.append(o);});
     sel.addEventListener('change',()=>location.assign(sel.value));
     if(top)top.after(card);else panel.prepend(card);
   }
   panel.querySelectorAll(':scope > section, :scope > .or-side .or-panel').forEach(collapsible);
   // A round icon before each section name.
   [['.sis-quick','lightning-charge-fill'],['.sis-record-actions','record-circle'],['.sis-module-tools','tools'],['.sis-related','grid-fill'],['.sis-panel-section','list-ul'],['.or-side .or-panel','tools']].forEach(([sel,icon])=>panel.querySelectorAll(':scope > '+sel+' .sis-panel-toggle').forEach(b=>{if(!b.querySelector('.sis-sec-ic'))b.insertAdjacentHTML('afterbegin','<span class="sis-sec-ic" aria-hidden="true"><i class="bi bi-'+icon+'"></i></span>');}));
   // Module tool links moved from the page get the same icon column as record actions.
   panel.querySelectorAll('section > a, section > button:not(.sis-panel-toggle), .or-links a').forEach(el=>{el.classList.remove('btn','btn-sm','btn-primary','btn-outline-primary','btn-outline-secondary');if(!el.querySelector('.bi,svg'))el.insertAdjacentHTML('afterbegin','<i class="bi bi-'+iconFor(el,labelOf(el))+'" aria-hidden="true"></i>');});
 }

 // Toolbar: real record actions only. Add keeps the module's own wording; Edit and Select All appear only when supported.
 const toolbar=document.querySelector('.record-toolbar');
 const buttons=toolbar?.querySelector('.record-toolbar-buttons');
 const add=toolbar?.querySelector('[data-list-add] > *');if(add&&add.title){add.textContent=title(add.title.replace(/^new\b/i,'Add').toLowerCase()).replace(/\bO\.r\./,'O.R.');}
 const open=toolbar?.querySelector('.record-selection-action');if(open){open.textContent='View';buttons?.prepend(open);}
 const editSlot=slots.find(s=>/^edit\b/i.test(s.text)&&!s.group);let edit=null;
 if(buttons&&editSlot&&!toolbar.querySelector('[data-theme-edit]')){edit=document.createElement('button');edit.type='button';edit.className='btn btn-sm btn-outline-secondary';edit.dataset.themeEdit='';edit.textContent='Edit';edit.disabled=true;edit.addEventListener('click',()=>editSlot.button.click());(open||buttons.querySelector('[data-list-add]'))?.after(edit);}
 const bulkAll=document.getElementById('bulkAll');
 if(buttons&&bulkAll&&!toolbar.querySelector('[data-theme-select-all]')){const b=document.createElement('button');b.type='button';b.className='btn btn-sm btn-outline-secondary';b.dataset.themeSelectAll='';b.textContent='Select All';b.disabled=bulkAll.disabled;b.addEventListener('click',()=>bulkAll.click());buttons.querySelector('[data-list-toggle]')?.before(b);}
 const clear=toolbar?.querySelector('[data-list-clear]'),form=list&&document.getElementById(list.dataset.filterForm);
 if(clear&&form&&!form.contains(clear)){clear.classList.remove('btn','btn-sm','btn-outline-secondary');clear.classList.add('sis-filter-clear');form.append(clear);}
 const refresh=toolbar?.querySelector('[data-list-refresh]');if(refresh&&!refresh.querySelector('.sis-label')){const l=document.createElement('span');l.className='sis-label';l.textContent='Refresh';refresh.append(l);}
 const delSlot=slots.find(s=>/^(delete|remove)\b/i.test(s.text)&&!s.group);let del=null;
 if(buttons&&!toolbar.dataset.themeModern){
   toolbar.dataset.themeModern='1';
   // An icon only where the theme does not already draw one
   const ico=(el,icon)=>{if(el&&!el.querySelector('.bi,svg')&&getComputedStyle(el,'::before').content==='none')el.insertAdjacentHTML('afterbegin','<i class="bi bi-'+icon+'" aria-hidden="true"></i>');};
   const addLink=buttons.querySelector('[data-list-add] > *');if(addLink){addLink.classList.add('sis-tb-primary');ico(addLink,'plus-lg');buttons.prepend(buttons.querySelector('[data-list-add]'));}
   ico(open,'eye');ico(edit,'pencil');
   if(delSlot){del=document.createElement('button');del.type='button';del.className='btn btn-sm btn-outline-danger sis-tb-danger';del.dataset.themeDelete='';del.innerHTML='<i class="bi bi-trash3" aria-hidden="true"></i>Delete';del.disabled=true;del.addEventListener('click',()=>delSlot.button.click());(edit||open||buttons.querySelector('[data-list-add]'))?.after(del);}
   const print=buttons.querySelector('[data-list-print]');ico(print,'printer');
   // Less-used tools go under More Actions; they keep working exactly as before.
   const moreItems=[refresh,buttons.querySelector('.sis-actions-toggle')].filter(Boolean);
   if(moreItems.length){
     // The Actions button stays on the toolbar for phones (CSS hides it on wide screens, where the panel is open).
     const stash=document.createElement('span');stash.hidden=true;stash.className='sis-more-stash';moreItems.filter(el=>!el.matches('.sis-actions-toggle')).forEach(el=>stash.append(el));toolbar.append(stash);
     const more=document.createElement('button');more.type='button';more.className='btn btn-sm btn-outline-secondary sis-more';more.setAttribute('aria-haspopup','menu');more.innerHTML='<i class="bi bi-three-dots" aria-hidden="true"></i>More Actions<i class="bi bi-chevron-down sis-more-caret" aria-hidden="true"></i>';
     more.addEventListener('click',e=>{e.stopPropagation();if(menu&&menu._opener===more){closeMenu();return;}openMenu(more,null,moreItems.map(el=>({text:el.textContent.trim()||el.getAttribute('aria-label'),icon:el.matches('[data-list-toggle]')?'funnel':el.matches('[data-list-refresh]')?'arrow-clockwise':'layout-sidebar-reverse',run:()=>el.click()})));});
     toolbar.append(more);
   }
   const apply=buttons.querySelector('[data-list-apply]');
   // Keep the original controls and their listeners: relocate only their DOM nodes.
   const filterToggle=buttons.querySelector('[data-list-toggle]');
   if(filterToggle){filterToggle.classList.add('sis-toolbar-filter');filterToggle.setAttribute('aria-label','Show or hide filters');filterToggle.insertAdjacentHTML('afterbegin','<i class="bi bi-funnel" aria-hidden="true"></i>');buttons.append(filterToggle);}
   if(apply){apply.classList.add('sis-toolbar-apply');buttons.append(apply);}
   if(form){
     form.classList.add('sis-filter-card');
     const FILTER_ICONS={sy:'calendar3',school_year:'calendar3',year:'layers',year_level:'layers',program:'book',section:'people',status:'funnel',term:'calendar-week',from:'calendar-event',to:'calendar-event',method:'credit-card',cashier:'person-badge',type:'tag',group:'people',active:'toggle-on',source:'diagram-3',category:'tags',kind:'tag'};
     form.querySelectorAll('.record-filter-cell').forEach(cell=>{if(cell.querySelector('.sis-filter-icon'))return;const f=cell.querySelector('select,input:not([type=hidden])');if(!f)return;const i=document.createElement('span');i.className='sis-filter-icon';i.setAttribute('aria-hidden','true');i.innerHTML='<i class="bi bi-'+(FILTER_ICONS[f.name]||'sliders')+'"></i>';cell.prepend(i);});
     const clr=form.querySelector('[data-list-clear]');if(clr&&!clr.querySelector('.bi')){clr.innerHTML='<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Clear Filters';}
     const acts=document.createElement('div');acts.className='sis-filter-actions';
     if(apply){apply.classList.add('sis-filter-apply');if(!apply.querySelector('.bi'))apply.insertAdjacentHTML('afterbegin','<i class="bi bi-funnel" aria-hidden="true"></i>');}
     if(clr)acts.append(clr);
     if(acts.children.length)form.append(acts);
   }
 }

 function render(){
   const tr=current();sync();show();
   slots.forEach(slot=>{
     const source=tr&&(controlsOf(tr).find(el=>(el.dataset.panelGroup||'')===slot.group&&labelOf(el)===slot.text)||(!slot.group&&rowTools.find(el=>labelOf(el)===slot.text)));
     const usable=!!source&&!source.disabled&&source.getAttribute('aria-disabled')!=='true';
     slot.button.disabled=!usable;slot.button.setAttribute('aria-disabled',String(!usable));slot.button.onclick=usable?()=>source.click():null;
   });
   if(edit)edit.disabled=editSlot.button.disabled;
   if(del)del.disabled=delSlot.button.disabled;
   panel?.classList.toggle('has-selection',!!tr);
 }
 tables.forEach(table=>scope.on(table,'click',render));
 tables.forEach(table=>scope.on(table,'keydown',e=>{if(/^(Enter|ArrowUp|ArrowDown)$/.test(e.key))setTimeout(render);}));
 // Changing a filter clears the selection so no action runs on outdated results.
 main.querySelectorAll('[data-record-filters],[data-list-search]').forEach(el=>{const clearSel=()=>{all.forEach(tr=>{tr.classList.remove('is-selected','selected');tr.setAttribute('aria-selected','false');});render();};scope.on(el,'input',clearSel);scope.on(el,'change',clearSel);});
 render();
},95);})(window.SISLifecycle?window.SISLifecycle.register:function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
