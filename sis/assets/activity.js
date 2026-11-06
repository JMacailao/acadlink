/* Shared, concurrent loading feedback; reads delay their indicator to avoid fast-request flashes. */
(() => {
  'use strict';
  let panel,progress,showTimer,slowTimer,legacyDone,writes=0;
  const tasks=new Map();let next=0;
  function paint(){
    if(!tasks.size)return;
    if(!panel){panel=document.createElement('div');panel.className='activity-status';panel.setAttribute('role','status');panel.setAttribute('aria-live','polite');panel.innerHTML='<span class="activity-ring" aria-hidden="true"></span><span class="activity-message"></span>';document.body.append(panel);progress=document.createElement('div');progress.className='sis-network-progress';progress.setAttribute('aria-hidden','true');document.body.append(progress);}
    panel.hidden=false;progress.hidden=false;panel.querySelector('.activity-message').textContent=[...tasks.values()].at(-1);
  }
  function start(message='Loading…'){
    const id=++next;tasks.set(id,message);
    if(tasks.size===1){showTimer=setTimeout(paint,160);slowTimer=setTimeout(()=>{if(panel&&!panel.hidden)panel.querySelector('.activity-message').textContent=message+' This is taking longer than usual. Please wait.';},8000);}else if(panel&&!panel.hidden)paint();
    let done=false;return ()=>{if(done)return;done=true;tasks.delete(id);if(tasks.size){if(panel&&!panel.hidden)paint();return;}clearTimeout(showTimer);clearTimeout(slowTimer);if(panel)panel.hidden=true;if(progress)progress.hidden=true;};
  }
  function end(){if(legacyDone){legacyDone();legacyDone=null;}}
  window.SISActivity={start,begin(message){end();legacyDone=start(message);},end,get writing(){return writes>0;}};
  window.addEventListener('pageshow',()=>{end();document.querySelectorAll('form[data-submitted]').forEach(form=>{delete form.dataset.submitted;form.querySelectorAll('[data-submit-copy]').forEach(el=>el.remove());form.querySelectorAll('[data-activity-original]').forEach(button=>{button.innerHTML=button.dataset.activityOriginal;delete button.dataset.activityOriginal;button.disabled=false;button.removeAttribute('aria-busy');});});});
  const nativeFetch=window.fetch.bind(window);
  window.fetch=async function(input,options={}){
    const url=new URL(input instanceof Request?input.url:input,location.href);
    if(url.origin!==location.origin)return nativeFetch(input,options);
    const method=(options.method||(input instanceof Request?input.method:'GET')).toUpperCase(),write=!['GET','HEAD'].includes(method);
    const done=start(write?'Saving changes…':'Loading data…');
    if(write){writes++;window.SISNavigation?.invalidate();}
    try{
      const response=await nativeFetch(input,options);
      // Keep the activity visible until the response body has arrived. Return a normal Response.
      await response.clone().arrayBuffer();
      return response;
    }finally{if(write){writes--;window.SISNavigation?.invalidate(true);}done();}
  };
  document.addEventListener('click',event=>{
    const link=event.target.closest('a[href]');
    if(!link||event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||link.target||link.hasAttribute('download')||link.matches('[data-frame],[data-bs-toggle]'))return;
    const url=new URL(link.href,location.href);
    if(url.origin===location.origin&&url.pathname===location.pathname&&!url.hash&&!/(?:export|download|print|receipt)/i.test(url.search))window.SISActivity.begin('Opening page…');
  });
})();
