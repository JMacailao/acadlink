/* AcadLink navigation: persistent shell, bounded memory-only read cache, native writes. */
(() => {
  'use strict';
  const registrations=new Map(); let cleanups=[], mounted=false;
  function unmount(){cleanups.splice(0).reverse().forEach(fn=>{try{fn();}catch(error){console.error(error);}});mounted=false;}
  function mount(){
    mounted=true;
    document.body.classList.remove('fixed-record-shell','record-list-page');
    [...registrations.values()].sort((a,b)=>a.order-b.order).forEach(({init})=>{
      const controller=new AbortController(), callbacks=[];
      const scope={
        signal:controller.signal,
        on(target,type,listener,options){if(!target)return;target.addEventListener(type,listener,typeof options==='boolean'?{capture:options,signal:controller.signal}:{...options,signal:controller.signal});},
        cleanup(fn){callbacks.push(fn);},
        frame(fn){const id=requestAnimationFrame(()=>{if(!controller.signal.aborted)fn();});callbacks.push(()=>cancelAnimationFrame(id));return id;},
        timeout(fn,ms){const id=setTimeout(()=>{if(!controller.signal.aborted)fn();},ms);callbacks.push(()=>clearTimeout(id));return id;}
      };
      cleanups.push(()=>{controller.abort();callbacks.reverse().forEach(fn=>fn());});
      try{init(scope);}catch(error){console.error('Module initialization failed:',error);}
    });
    document.dispatchEvent(new CustomEvent('sis:mounted'));
  }
  window.SISLifecycle={register(name,init,order=70){registrations.set(name,{init,order});},mount,unmount};
  (window.SISModuleQueue||[]).forEach(args=>window.SISLifecycle.register(...args));
  delete window.SISModuleQueue;
  const main=document.querySelector('.app-shell #main');
  // Embedded forms and sign-in keep their normal native lifecycle.
  if(!main||document.body.classList.contains('bare')){document.addEventListener('DOMContentLoaded',mount,{once:true});return;}
  if('scrollRestoration' in history)history.scrollRestoration='manual';
  const allowed=new Set(['dashboard','notifications','enrollment','my','myenrollment','myadmission','admission','registration','cashier','balance','eligibility','students','student','sections','yearlevels','teachers','curriculum','assessment','discounts','accounting','reports','users','bulkaccounts','migration','settings','orseries','terms','activity','help','history','search']);
  const assets=new Set(['record-list','fixed-shell','accounting-ui','app','friendly-ui','eligibility','workspaces','bulk-accounts','migration','central-accounts','accounting-workflow','portal-enrollment','sis-actions','fee-manage','clearance']);
  const cache=new Map(), loaded=new Map(); let bytes=0, epoch=0, sequence=0, active=null, renderedURL=location.href;
  let scopeKey=document.body.dataset.clientScope||'';
  const channel=typeof BroadcastChannel==='function'?new BroadcastChannel('acadlink-data-v31'):null;
  function invalidate(announce=false){cache.clear();bytes=0;epoch++;if(announce)channel?.postMessage({type:'invalidate'});}
  channel?.addEventListener('message',()=>invalidate());
  window.addEventListener('pagehide',()=>invalidate());
  function eligible(url){return url.origin===location.origin&&url.pathname===location.pathname&&allowed.has(url.searchParams.get('r')||'dashboard')&&!url.hash&&!['embed','action','download','export','print','template','preview','json'].some(key=>url.searchParams.has(key));}
  function key(url){const next=new URL(url);next.hash='';next.searchParams.sort();return scopeKey+'|'+next.href;}
  function ttl(url){
    const r=url.searchParams.get('r')||'dashboard';
    if(['id','edit','open','editName','new','entry','batch','job','reg'].some(k=>url.searchParams.has(k)))return 0;
    if(['sections','yearlevels','curriculum'].includes(r))return 300000;
    if(['students','teachers'].includes(r))return 60000;
    if(r==='history'&&url.searchParams.get('tab')==='grades')return 30000;
    // Financial, admission, security, migration and one-time responses are never cached.
    return 0;
  }
  function remember(url,html,parsed){
    const life=ttl(url);if(!life||html.length>1000000||parsed.querySelector('#teacherAccountCredentials'))return;
    const k=key(url),old=cache.get(k);if(old)bytes-=old.html.length;cache.delete(k);
    cache.set(k,{html,expires:Date.now()+life});bytes+=html.length;
    while(cache.size>12||bytes>4000000){const first=cache.keys().next().value;bytes-=cache.get(first).html.length;cache.delete(first);}
  }
  let validation;
  async function validate(){
    if(validation)return validation;
    validation=(async()=>{
      const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),15000);
      try{
        const url=new URL(location.pathname,location.href);url.search='?r=navstate';
        const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json','X-AcadLink-Read':'1'}});
        // Only a real sign-out goes to the login page; a busy or failing server shows Retry instead.
        if(response.status>=500)throw new Error('The school\'s server is busy. Wait a moment, then select Retry.');
        if(response.redirected||!response.ok||!(response.headers.get('content-type')||'').includes('application/json')){invalidate();location.assign(response.redirected?response.url:'index.php?r=login');throw new Error('Please sign in again.');}
        const data=await response.json();
        if(!data.scope||data.scope!==scopeKey){invalidate();location.reload();throw new Error('Your account access changed. Reloading…');}
      }finally{clearTimeout(timer);}
    })().finally(()=>validation=null);
    return validation;
  }
  async function ensureAssets(parsed){
    for(const original of parsed.querySelectorAll('script[src]')){
      const url=new URL(original.getAttribute('src'),location.href),match=url.pathname.match(/\/assets\/([a-z-]+)\.js$/);
      if(url.origin!==location.origin||!match||!assets.has(match[1]))continue;
      if(registrations.has(match[1]))continue;
      if(!loaded.has(url.href))loaded.set(url.href,new Promise((resolve,reject)=>{const script=document.createElement('script');script.src=url.href;script.onload=resolve;script.onerror=()=>{script.remove();loaded.delete(url.href);reject(new Error('Could not load this module. Try again.'));};document.head.append(script);}));
      await loaded.get(url.href);
    }
  }
  function position(){const scroller=main.querySelector('.sis-record-scroll');return {x:window.scrollX,y:window.scrollY,top:scroller?.scrollTop||0,left:scroller?.scrollLeft||0};}
  function savePosition(){history.replaceState({...history.state,sis:true,scroll:position()},'',location.href);}
  function restorePosition(value){if(!value)return;const scroller=main.querySelector('.sis-record-scroll');if(scroller){scroller.scrollTop=value.top;scroller.scrollLeft=value.left;}window.scrollTo(value.x||0,value.y||0);}
  function notice(error,url,options){
    document.getElementById('sisNavigationError')?.remove();
    const box=document.createElement('div');box.id='sisNavigationError';box.className='sis-navigation-error';box.setAttribute('role','alert');
    const offline=navigator.onLine===false||error instanceof TypeError;
    const text=document.createElement('span');text.textContent=error.name==='AbortError'?'Loading timed out. Check your connection and try again.':offline?(navigator.onLine===false?'You are offline. Reconnect to the internet, then select Retry.':'Can\'t reach the school\'s server. Check your internet connection, then select Retry.'):error.message;
    const retry=document.createElement('button');retry.type='button';retry.className='btn btn-sm btn-outline-primary';retry.textContent='Retry';retry.onclick=()=>navigate(url,{...options,force:true});
    const fallback=document.createElement('a');fallback.className='btn btn-sm btn-light';fallback.href=url.href;fallback.dataset.native='';fallback.textContent='Open full page';box.append(text,retry,fallback);document.body.append(box);
  }
  async function navigate(input,options={}){
    const url=new URL(input,location.href);
    if(!eligible(url)){location.assign(url.href);return;}
    if(window.SISActivity?.writing)return;
    if(active?.url===url.href&&!options.force)return active.promise;
    const currentEntry=cache.get(key(url));
    if(!active&&!options.force&&!options.scroll&&key(url)===key(renderedURL)&&currentEntry?.expires>Date.now()&&!main.querySelector('[data-record-list].is-dirty')){
      return validate().then(()=>{const panel=main.querySelector('[data-record-list]');if(panel){panel.removeAttribute('aria-busy');const status=panel.querySelector('[role=status]');if(status)status.textContent='Showing applied results. Select Refresh to check for changes.';}}).catch(error=>notice(error,url,options));
    }
    active?.controller.abort();
    const controller=new AbortController(),seq=++sequence,startEpoch=epoch;
    const sameModule=new URL(renderedURL).searchParams.get('r')===url.searchParams.get('r')&&new URL(renderedURL).searchParams.get('tab')===url.searchParams.get('tab');
    const scroll=options.scroll||(sameModule?position():{x:0,y:0,top:0,left:0});
    const focused=document.activeElement,focusName=sameModule&&focused?.name,selection=focusName&&focused.selectionStart;
    const operation=(async()=>{
      // A slow connection gets a normal page load (no time limit, browser progress bar) instead of a Retry that times out again.
      let timedOut=false;
      const timer=setTimeout(()=>{timedOut=true;controller.abort();},25000),done=window.SISActivity?.start(sameModule?'Updating results…':'Opening module…');
      main.setAttribute('aria-busy','true');document.getElementById('sisNavigationError')?.remove();
      try{
        let entry=cache.get(key(url)),html,parsed;
        if(!options.force&&entry&&entry.expires>Date.now()){
          await validate();html=entry.html;
        }else{
          const response=await fetch(url,{signal:controller.signal,credentials:'same-origin',cache:'no-store',headers:{Accept:'text/html','X-AcadLink-Read':'1'}});
          if(response.redirected){invalidate();location.assign(response.url);return;}
          if(!response.ok)throw new Error(response.status===403?'Your access changed. Open the full page to check your permissions.':response.status===503?'The system is busy right now. Wait a few seconds, then select Retry.':'Unable to load this page (HTTP '+response.status+').');
          if(!(response.headers.get('content-type')||'').includes('text/html')){location.assign(url.href);return;}
          html=await response.text();
        }
        if(controller.signal.aborted||seq!==sequence)return;
        parsed=new DOMParser().parseFromString(html,'text/html');
        const next=parsed.querySelector('.app-shell #main');
        if(!next){invalidate();location.assign(url.href);return;}
        if(parsed.body.dataset.clientScope!==scopeKey){invalidate();location.assign(url.href);return;}
        await ensureAssets(parsed);
        if(controller.signal.aborted||seq!==sequence)return;
        // A write in another tab while this read was in flight makes the response unusable.
        if(epoch!==startEpoch)throw new Error('Records changed while loading. Select Retry to fetch the latest results.');
        if(options.history!==false){savePosition();history.pushState({sis:true,scroll},'',url.href);}
        unmount();
        document.querySelectorAll('.modal.show').forEach(el=>window.bootstrap?.Modal.getInstance(el)?.hide());
        const offcanvas=document.getElementById('sidebar');if(offcanvas)window.bootstrap?.Offcanvas.getInstance(offcanvas)?.hide();
        document.body.dataset.route=parsed.body.dataset.route;
        document.title=parsed.title;
        // Keep the shell, sidebar and main element; replace the module once, without an opacity flash.
        main.className=next.className;main.replaceChildren(...[...next.childNodes].map(node=>document.importNode(node,true)));
        main.querySelectorAll('script[src]').forEach(script=>script.remove());
        const selected=new Set([...parsed.querySelectorAll('#sidebar .side-link.active')].map(a=>new URL(a.getAttribute('href'),url).href));
        document.querySelectorAll('#sidebar .side-link').forEach(link=>{const yes=selected.has(link.href);link.classList.toggle('active',yes);if(yes)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');});
        renderedURL=url.href;mount();restorePosition(scroll);
        if(focusName){const control=[...main.querySelectorAll('input,select,textarea')].find(el=>el.name===focusName);if(control){control.focus({preventScroll:true});if(selection!==null&&typeof control.setSelectionRange==='function'&&/^(search|text)$/.test(control.type))control.setSelectionRange(selection,selection);}}
        else if(!sameModule){const title=main.querySelector('h1');if(title){title.tabIndex=-1;title.focus({preventScroll:true});}}
        remember(url,html,parsed);
      }catch(error){
        if(seq!==sequence)return;
        if(timedOut){invalidate();location.assign(url.href);return;}
        if(options.history===false)history.replaceState({sis:true},'',renderedURL);
        notice(error,url,{...options,history:true});
      }finally{
        clearTimeout(timer);if(done)done();
        if(seq===sequence){main.removeAttribute('aria-busy');main.querySelectorAll('[data-record-list]').forEach(el=>el.removeAttribute('aria-busy'));window.SISActivity?.end();active=null;}
      }
    })();
    active={url:url.href,controller,promise:operation};return operation;
  }
  window.SISNavigation={navigate,refresh(){invalidate(true);return navigate(location.href,{force:true,history:false});},invalidate};
  document.addEventListener('click',event=>{
    const link=event.target.closest('a[href]');
    if(!link||event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||link.target||link.hasAttribute('download')||link.matches('[data-native],[data-frame],[data-frame-close],[data-bs-toggle],[data-workspace-link]')||link.closest('dialog'))return;
    const url=new URL(link.href,location.href);if(!eligible(url))return;
    event.preventDefault();navigate(url,{force:link.hasAttribute('data-list-refresh')});
  });
  document.addEventListener('submit',event=>{
    const form=event.target;if(event.defaultPrevented)return;
    // Read the attributes: a field named "method" or "action" (e.g. the cashier's payment method) shadows form.method / form.action.
    if((event.submitter?.getAttribute('formmethod')||form.getAttribute('method')||'get').toLowerCase()!=='get'){invalidate(true);return;}
    if(form.target||form.hasAttribute('data-native')||/download|export|print/i.test(event.submitter?.textContent||''))return;
    const url=new URL(event.submitter?.getAttribute('formaction')||form.getAttribute('action')||location.href,location.href);url.search='';
    const values=new FormData(form);if(event.submitter?.name)values.append(event.submitter.name,event.submitter.value);
    for(const [name,value] of values){if(typeof value!=='string')return;url.searchParams.append(name,value);}
    if(!eligible(url))return;event.preventDefault();navigate(url);
  });
  window.addEventListener('popstate',event=>{if(event.state?.sis)navigate(location.href,{history:false,scroll:event.state.scroll});else location.reload();});
  window.addEventListener('focus',()=>{invalidate();validate().catch(()=>{});});
  window.addEventListener('pageshow',event=>{if(event.persisted){invalidate();validate().then(()=>navigate(location.href,{force:true,history:false})).catch(()=>{});}});
  document.addEventListener('DOMContentLoaded',()=>{history.replaceState({sis:true,scroll:position()},'',location.href);remember(new URL(location.href),document.documentElement.outerHTML,document);mount();},{once:true});
})();
