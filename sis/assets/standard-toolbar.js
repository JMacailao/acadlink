/* SIS standard toolbar: New · Edit · Void · Post · Select All | Filters · Apply Filter | Search · Refresh · More.
   Shown on every staff page. Each slot clicks the module's own control (row links and buttons, the list's
   Add button, Accounting's action buttons), so permissions, confirmations and server checks are unchanged.
   The old toolbar stays in the page, hidden, because its controls are the ones being clicked. */
(function(register){register('standard-toolbar',function(scope){
 'use strict';
 const T=window.AcadToolbar;
 const main=document.querySelector('.app-shell #main');
 if(!T||!main||document.body.classList.contains('bare')||document.documentElement.dataset.system==='portal')return;
 const route=document.body.dataset.route||'dashboard';
 if(['login','forgot','password','logout','signinpic','navstate','receipt','docreceipt','clearanceslip','clearancefile'].includes(route))return;
 if(document.querySelector('.std-tb'))return;
 const moduleName=/[?&](id|new|edit|reg|entry)=/.test(location.search)?'This page':(main.querySelector('.sis-module-title')?.textContent||document.title.split('·')[0]||'This page').trim();
 const list=document.querySelector('[data-record-list]');
 const old=document.querySelector('.sis-toolbar-header .record-toolbar')||main.querySelector('.record-toolbar');
 const q=(sel)=>old?old.querySelector(sel):null;
 const toggle=q('[data-list-toggle]'),apply=q('[data-list-apply]'),refresh=q('[data-list-refresh]'),searchInput=q('[data-list-search] input');
 const form=list&&document.getElementById(list.dataset.filterForm);
 // Pages without the shared record list but with their own GET filter form (Reports, Balance, History…):
 // Apply Filter submits that form, Hide/Show Filters hides it, and its search box moves to the toolbar.
 const pageForm=!list&&!/[?&](id|new|edit|reg|entry)=/.test(location.search)?[...main.querySelectorAll('form')].find(f=>(f.getAttribute('method')||'get').toLowerCase()==='get'&&!f.closest('.std-tb,.topbar,dialog,.modal,table,.sis-actions-panel,.sy-card')&&!f.matches('.sy-card')&&!f.querySelector('#globalSearch')&&f.querySelector('select,input:not([type=hidden])')):null;
 let pageSearch=pageForm?pageForm.querySelector('input[type=search],input[name=q]'):null;
 if(pageSearch){if(!pageForm.id)pageForm.id='stdPageFilters';pageSearch.setAttribute('form',pageForm.id);}
 const SKIP='.std-tb,.std-tb-menu,nav,#sidebar,.topbar,dialog,.modal,.dropdown-menu,.sis-row-icons,.sis-list-bar,.breadcrumbs';
 const visibleLabel=el=>T.labelOf(el);

 // New: the list's Add control, else the page's own Add/New button.
 const NEW=/^(\+\s*)?(add|new|create)\b/i;
 const pageControls=()=>[...document.querySelectorAll('#main a[href], #main button, .sis-actions-drawer a[href], .sis-actions-drawer button')]
   .filter(el=>!el.closest(SKIP)&&!el.closest('table')&&!(old&&old.contains(el))&&visibleLabel(el)&&!el.matches('.sis-panel-toggle,.btn-close,[data-bs-dismiss],.sis-sort,.sis-pager-btn'));
 const newSource=q('[data-list-add] > a, [data-list-add] > button')||pageControls().find(el=>NEW.test(visibleLabel(el))&&el.closest('.page-head,.sis-quick')&&(!el.closest('form')||el.type==='button'||el.tagName==='A'));

 // Rows: the selected record and its own controls (same rule as the Actions explorer).
 const tables=[...main.querySelectorAll('table')].filter(t=>t.tBodies[0]&&!t.closest('form,dialog,.modal,.std-tb-menu'));
 const isRow=tr=>!tr.querySelector('[colspan]')&&tr.cells.length>1;
 const allRows=()=>tables.flatMap(t=>[...t.tBodies].flatMap(b=>[...b.rows])).filter(isRow);
 const selectedRow=()=>main.querySelector('tr.is-selected, tr.accounting-entry-selected, tbody.accounting-entry-selected, tr.selected[aria-selected="true"]');
 const rowControls=tr=>{if(!tr)return [];const cells=[...(tr.cells||tr.querySelectorAll('td'))].filter(c=>!c.classList.contains('sis-row-icons')&&!c.classList.contains('sis-select-cell'));const cell=cells[cells.length-1];if(!cell)return [];
   return [...cell.querySelectorAll('a[href],button')].filter(el=>!el.closest('.dropdown-menu')&&visibleLabel(el)&&(!el.closest('form')||el.type==='submit'||el.tagName==='A'));};
 // A few modules name their edit action differently.
 const EDIT_ALIAS={registration:/^open$/i,users:/^manage$/i}[route];
 const slotOf=text=>T.slotOf(text)||(EDIT_ALIAS&&EDIT_ALIAS.test(text)?'edit':null);
 const rowTools=[...main.querySelectorAll('.or-toolbar [data-row], .record-toolbar [data-row]')].filter(visibleLabel);
 const accounting=[...document.querySelectorAll('[data-accounting-action]')];
 const ACC_SLOT={reverse_posted:'void',post:'post'};
 // What each slot could ever do on this page (to say "has no Void action" instead of "select a row").
 const known={edit:false,void:false,post:false};
 const note=el=>{const k=slotOf(visibleLabel(el));if(k)known[k]=true;};
 allRows().forEach(tr=>rowControls(tr).forEach(note));rowTools.forEach(note);accounting.forEach(b=>{const k=ACC_SLOT[b.dataset.accountingAction];if(k)known[k]=true;});
 const params=new URLSearchParams(location.search);
 // A single-record page (or a tool page without rows): page-level buttons act on the open record.
 const detail=['id','new','edit','reg','entry'].some(k=>params.has(k))||!allRows().length;
 // An action that only exists as a confirmation pop-up (e.g. Cancel Admission) opens that pop-up.
 const modalOpeners=[...main.querySelectorAll('.modal')].flatMap(m=>[...m.querySelectorAll('form button[type=submit], form button:not([type])')].filter(b=>slotOf(visibleLabel(b))).map(b=>{
   const o=document.createElement('button');o.type='button';o.textContent=visibleLabel(b);o.disabled=b.disabled;
   o.addEventListener('click',()=>{if(window.bootstrap?.Modal)window.bootstrap.Modal.getOrCreateInstance(m).show();});return o;}));
 const pageSlot=k=>[...pageControls(),...modalOpeners].filter(el=>slotOf(visibleLabel(el))===k);
 if(detail)['edit','void','post'].forEach(k=>{if(pageSlot(k).length)known[k]=true;});

 function sourcesFor(k){
   const tr=selectedRow();const out=[];
   if(tr){rowControls(tr).forEach(el=>{if(slotOf(visibleLabel(el))===k)out.push(el);});}
   rowTools.forEach(el=>{if(slotOf(visibleLabel(el))===k)out.push(el);});
   accounting.forEach(b=>{if(ACC_SLOT[b.dataset.accountingAction]===k)out.push(b);});
   if(detail)out.push(...pageSlot(k));
   return out;
 }
 const WORD={edit:'Edit',void:'Void',post:'Post'};

 // Build
 const search=searchInput||pageSearch||null;
 const tb=T.create({search,searchPlaceholder:'Search',searchTitle:list?'':'This page has no list to search',more:moreItems});
 tb.el.dataset.route=route;
 if(old){old.classList.add('std-tb-legacy');old.before(tb.el);}
 else{
   // Same place as on list pages: the fixed bar under the top bar (in the page heading on phones).
   const host=document.createElement('div');host.className='sis-toolbar-header sis-std-header d-print-none';host.append(tb.el);
   const heading=main.querySelector('.sis-page-heading');
   if(heading){heading.append(host);document.body.classList.add('std-tools-fixed');}else{host.classList.add('sis-std-static');main.prepend(host);}
 }
 if(route==='registration'&&list&&old&&document.body.classList.contains('sis-easy')){
   const appbar=document.querySelector('.sis-appbar');
   if(appbar){
     const desktop=matchMedia('(min-width:992px)');
     const wide=matchMedia('(min-width:1440px)');
     const host=old.parentElement;
     tb.el.setAttribute('aria-label','Registration tools');
     function placeToolbar(){
       T.closeMenu();
       if(wide.matches)appbar.prepend(tb.el);
       else if(desktop.matches)appbar.append(tb.el);
       else old.before(tb.el);
       tb.el.classList.toggle('sis-appbar-record-tools',desktop.matches);
       host.classList.toggle('sis-toolbar-in-appbar',desktop.matches);
     }
     placeToolbar();
     scope.on(desktop,'change',placeToolbar);
     scope.on(wide,'change',placeToolbar);
     scope.cleanup(()=>tb.el.remove());
   }
 }
 document.body.classList.add('has-std-toolbar');

 // More Actions: everything else this page offers, run through the original control.
 function moreItems(){
   const items=[],seen=new Set(),used=new Set([newSource,toggle,apply,refresh].filter(Boolean));
   ['edit','void','post'].forEach(k=>sourcesFor(k).forEach(el=>used.add(el)));
   const add=(el,text)=>{text=text||visibleLabel(el);if(!text||seen.has(text.toLowerCase())||used.has(el))return;seen.add(text.toLowerCase());
     items.push({text,icon:el.dataset.icon||T.iconFor(text),disabled:!T.usable(el),title:el.title,run:()=>el.click()});};
   const tr=selectedRow();
   const view=q('.record-selection-action');if(view)add(view,'View');
   accounting.filter(b=>!ACC_SLOT[b.dataset.accountingAction]).forEach(b=>add(b));
   if(tr)rowControls(tr).forEach(el=>add(el));
   rowTools.forEach(el=>add(el));
   if(old)[...old.querySelectorAll('a[href],button')].filter(el=>!el.closest('.std-tb')&&!el.matches('.sis-more,[data-theme-edit],[data-theme-delete],[data-theme-select-all],[data-list-search] *')&&!el.closest('[data-accounting-toolbar]')&&!rowTools.includes(el)).forEach(el=>add(el,el.matches('.sis-actions-toggle')?'Actions panel':el.matches('[data-list-print]')?'Print List':null));
   const head=main.querySelector('.sis-page-heading .page-head, .sis-toolbar-header .page-head');
   if(head)[...head.querySelectorAll('a[href],button')].filter(el=>el!==newSource).forEach(el=>add(el));
   if(detail)pageControls().filter(el=>!el.closest('.sis-actions-panel')&&(!el.closest('form')||(el.closest('form').getAttribute('method')||'get').toLowerCase()==='get'&&el.tagName==='A')&&!el.matches('.sis-actions-toggle')).forEach(el=>add(el));
   if(!old){const tg=document.querySelector('.sis-actions-toggle');if(tg)add(tg,'Actions panel');}
   return items;
 }

 // Rows of tables the theme does not make selectable still select on click.
 scope.on(main,'click',e=>{const tr=e.target.closest('tbody tr');if(!tr||!isRow(tr)||e.target.closest('a,button,input,select,textarea,label')||!tables.some(t=>t.contains(tr)))return;
   setTimeout(()=>{if(tr.classList.contains('is-selected')||tr.classList.contains('accounting-entry-selected'))return;allRows().forEach(r=>{r.classList.toggle('is-selected',r===tr);r.setAttribute('aria-selected',String(r===tr));});update();});});
 // State
 function update(){
   const tr=selectedRow();
   const s={};
   s.new=newSource?{enabled:T.usable(newSource),run:()=>newSource.click(),title:visibleLabel(newSource)}:{enabled:false,title:moduleName+' has no New action here'};
   ['edit','void','post'].forEach(k=>{
     const src=sourcesFor(k),ok=src.find(T.usable);
     if(ok)s[k]={enabled:true,run:()=>ok.click(),title:visibleLabel(ok)};
     else if(!known[k])s[k]={enabled:false,title:moduleName+' has no '+WORD[k]+' action'};
     else if(!tr&&!detail)s[k]={enabled:false,title:'Select a row first'};
     else s[k]={enabled:false,title:(src[0]?.title&&src[0].title!==visibleLabel(src[0])?src[0].title:WORD[k]+' is not available for this record')};
   });
   const bulkAll=document.getElementById('bulkAll')||main.querySelector('[data-select-all]');
   s.selectAll=bulkAll?{enabled:T.usable(bulkAll),run:()=>bulkAll.click(),title:'Select every row on this page',label:bulkAll.checked?'Clear All':'Select All'}:{enabled:false,title:moduleName+' has no bulk actions'};
   if(toggle&&form)s.filters={enabled:true,run:()=>{toggle.click();update();},label:/show/i.test(toggle.textContent)?'Show Filters':'Hide Filters',pressed:!form.hidden};
   else if(pageForm)s.filters={enabled:true,run:()=>{pageForm.classList.toggle('std-tb-filters-hidden');update();},label:pageForm.classList.contains('std-tb-filters-hidden')?'Show Filters':'Hide Filters',pressed:!pageForm.classList.contains('std-tb-filters-hidden')};
   else s.filters={enabled:false,title:'This page has no filters'};
   s.apply=apply?{enabled:true,run:()=>apply.click(),title:'Apply Filter'}:pageForm?{enabled:true,run:()=>pageForm.requestSubmit(),title:'Apply Filter'}:{enabled:false,title:'This page has no filters'};
   s.refresh=refresh&&T.usable(refresh)?{enabled:true,run:()=>refresh.click(),title:'Reload the list with the same filters'}
     :{enabled:true,run:()=>{if(window.SISNavigation&&window.SISNavigation.navigate)window.SISNavigation.navigate(location.href);else location.reload();},title:'Reload this page'};
   Object.entries(s).forEach(([k,v])=>tb.set(k,v));
   // Staff SIS shows only actions the module supports. Selection-dependent actions
   // remain visible so users can discover them before selecting a record.
   if(document.body.classList.contains('sis-easy')){
     const supported={new:!!newSource,edit:known.edit||!!s.edit?.enabled,void:known.void||!!s.void?.enabled,post:known.post||!!s.post?.enabled,selectAll:!!bulkAll,filters:!!(form||pageForm),apply:!!(apply||pageForm)};
     Object.entries(supported).forEach(([key,show])=>{const button=tb.el.querySelector('[data-std-slot="'+key+'"]');if(button)button.hidden=!show;});
     if(newSource)tb.set('new',{...s.new,label:visibleLabel(newSource)});
   }
   tb.el.classList.toggle('std-tb-dirty',!!list?.classList.contains('is-dirty'));
 }
 let queued=false;const later=()=>{if(queued)return;queued=true;requestAnimationFrame(()=>{queued=false;update();});};
 scope.on(document,'click',e=>{if(!e.target.closest('.std-tb'))later();});
 scope.on(document,'keydown',later);scope.on(document,'change',later);scope.on(document,'input',later);
 if('MutationObserver' in window){
   const watch=[old,list,...accounting.map(b=>b.parentElement)].filter(Boolean);
   const mo=new MutationObserver(recs=>{if(recs.some(r=>!r.target.closest?.('.std-tb')))later();});
   watch.forEach(n=>mo.observe(n,{attributes:true,subtree:true,attributeFilter:['disabled','class','aria-disabled']}));
   scope.cleanup(()=>mo.disconnect());
 }
 scope.cleanup(()=>T.closeMenu());
 if(document.body.classList.contains('sis-easy')&&route==='students'){
   const heading=main.querySelector('.sis-page-heading'),title=heading?.querySelector('.sis-module-title'),add=tb.el.querySelector('[data-std-slot="new"]');
   if(title&&add&&newSource){const row=document.createElement('div');row.className='sis-title-row';title.before(row);row.append(title,add);}
 }
 update();
},97);})(window.SISLifecycle?window.SISLifecycle.register:function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
