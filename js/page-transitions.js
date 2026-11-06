/* Preserve Grading's existing page-specific ES modules; use native visual transitions. */
import {track} from './loading.js';
let finish,bar,showTimer,slowTimer;
function end(){clearTimeout(showTimer);clearTimeout(slowTimer);if(finish){finish();finish=null;}bar?.remove();bar=null;}
window.addEventListener('pageshow',end);
document.addEventListener('click',event=>{
  const link=event.target.closest('a[href]');
  if(!link||event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||link.target||link.hasAttribute('download')||link.hasAttribute('data-bs-toggle'))return;
  const url=new URL(link.href,location.href);
  if(url.origin!==location.origin||url.hash||url.href===location.href||!/\.html$/.test(url.pathname))return;
  end();track(new Promise(resolve=>finish=resolve),'Opening '+(link.textContent.trim()||'module')+'…');
  showTimer=setTimeout(()=>{bar=document.createElement('div');bar.className='acadlink-page-progress';bar.setAttribute('aria-hidden','true');document.body.append(bar);},160);
  slowTimer=setTimeout(end,25000);
});
