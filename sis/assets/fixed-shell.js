/* Registered once; navigation disposes page listeners and observers before remounting. */
(function(register){register('fixed-shell',function(scope){
/* Scroll state is tab-scoped, account-scoped, and contains only UI positions. */
(() => {
  'use strict';
  const main=document.querySelector('.app-shell #main'), sidebar=document.querySelector('#sidebar .sidebar-inner > nav');
  if(!main||document.body.classList.contains('bare'))return;
  const prefix='sis.position.'+document.body.dataset.userId+'.';
  function read(key){try{return JSON.parse(sessionStorage.getItem(prefix+key));}catch(_){return null;}}
  function write(key,value){try{sessionStorage.setItem(prefix+key,JSON.stringify(value));}catch(_){/* Storage prevention must not block navigation. */}}
  if(sidebar){const saved=read('sidebar');if(Number.isFinite(saved))sidebar.scrollTop=saved;
    scope.on(sidebar,'scroll',()=>write('sidebar',sidebar.scrollTop),{passive:true});
    scope.on(document,'click',()=>write('sidebar',sidebar.scrollTop),true);
  }
  // Only placeholder links are suppressed; genuine navigation and fragment targets retain their behavior.
  document.querySelectorAll('#sidebar a[href="#"]').forEach(link=>link.addEventListener('click',event=>event.preventDefault()));
  const pageHeader=main.querySelector('.sis-page-heading');
  if(pageHeader){pageHeader.classList.add('sis-workspace-header');main.classList.add('sis-header-layout');}
  function headerSize(){const header=document.querySelector('.app-main > .topbar');if(header)main.style.setProperty('--sis-mobile-header',header.getBoundingClientRect().height+'px');}
  headerSize();scope.on(window,'resize',headerSize,{passive:true});
  if('ResizeObserver' in window){const header=document.querySelector('.app-main > .topbar');if(header){const observer=new ResizeObserver(headerSize);observer.observe(header);scope.cleanup(()=>observer.disconnect());}}
  const panel=main.querySelector('[data-record-list]');if(!panel)return;
  const dock=document.createElement('div');dock.className='sis-list-dock';
  let heading=main.querySelector('.page-head');
  if(!heading){heading=main.querySelector('.admission-heading');if(heading){heading.classList.add('page-head');heading.querySelector('h1')?.classList.add('page-title');heading.querySelector('p')?.classList.add('page-lede');}}
  const toolbar=panel.querySelector('.record-toolbar'),toggle=panel.querySelector('[data-list-toggle]');
  // Admissions already provides the same Add action in the shared toolbar.
  if(panel.querySelector('[data-list-add] a,[data-list-add] button'))heading?.querySelector('.admission-new')?.remove();
  const header=document.createElement('div');header.className='sis-toolbar-header';
  const description=heading?.querySelector('.page-lede');
  if(description){description.classList.add('sis-toolbar-description');description.remove();}
  if(heading){heading.querySelectorAll('.page-title').forEach(title=>title.remove());if(heading.textContent.trim()||heading.querySelector('a,button,input,select'))header.append(heading);else heading.remove();}header.append(toolbar);header.classList.add('d-print-none');
  if(pageHeader)pageHeader.append(header);else panel.prepend(header);
  if(description)panel.prepend(description);
  dock.append(panel);
  const results=document.createElement('div');results.className='sis-record-scroll';results.tabIndex=0;results.setAttribute('role','region');results.setAttribute('aria-label','Records and page content');
  [...main.children].filter(child=>!child.matches('.sis-page-heading')).forEach(child=>results.append(child));
  main.append(dock,results);document.body.classList.add('fixed-record-shell');
  const desktop=matchMedia('(min-width:992px)'), form=document.getElementById(panel.dataset.filterForm);
  const current=new URL(location.href);
  function key(url){return [url.pathname,...['r','tab','id','edit','open','entry','job','batch'].map(name=>url.searchParams.get(name)||'')].join('|');}
  const context=key(current);
  const filterKey=()=> 'filters.'+context+(desktop.matches?'.desktop':'.mobile');
  function filterLayout(){if(!form||!toggle)return;const saved=read(filterKey());form.hidden=saved===false||(saved===null&&!desktop.matches);toggle.textContent=form.hidden?'Show Filters':'Hide Filters';toggle.setAttribute('aria-expanded',String(!form.hidden));}
  filterLayout();
  toggle?.addEventListener('click',()=>write(filterKey(),!form.hidden));
  const hint=document.createElement('p');
  hint.className='sis-tools-hint d-print-none';hint.textContent='Swipe tools for more actions. Swipe tables to see all columns.';header.after(hint);
  function toolOverflow(){const buttons=toolbar.querySelector('.record-toolbar-buttons')||toolbar;hint.hidden=desktop.matches||buttons.scrollWidth<=buttons.clientWidth+1;}
  function responsiveLayout(){filterLayout();headerSize();toolOverflow();}
  if(desktop.addEventListener)scope.on(desktop,'change',responsiveLayout);
  toolOverflow();scope.on(window,'resize',toolOverflow,{passive:true});
  function snapshot(){write('pending',{context,top:desktop.matches?results.scrollTop:window.scrollY,left:results.scrollLeft,time:Date.now()});}
  // Restore once for the same list/detail context. Module changes begin at the top.
  const saved=read('pending');write('pending',null);
  if(saved&&saved.context===context&&Date.now()-saved.time<120000){
    scope.frame(()=>scope.frame(()=>{
      if(desktop.matches){results.scrollTop=Math.max(0,saved.top||0);results.scrollLeft=Math.max(0,saved.left||0);}
      else window.scrollTo({top:Math.max(0,saved.top||0),behavior:'instant'});
    }));
  }
  scope.on(window,'pagehide',snapshot);
  scope.on(document,'submit',snapshot,true);
  scope.on(document,'click',event=>{
    const link=event.target.closest('a[href]');if(!link||link.target==='_blank'||event.ctrlKey||event.metaKey)return;
    const target=new URL(link.href,location.href);if(target.origin===location.origin&&key(target)===context)snapshot();
  },true);
})();

},20);})(window.SISLifecycle ? window.SISLifecycle.register : function(){(window.SISModuleQueue=window.SISModuleQueue||[]).push(Array.from(arguments));});
