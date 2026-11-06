// ==========================================================
// loading.js — Shows people that the system is working.
//
// firebase-config.js wraps every database read and save with track(),
// so a spinner appears on its own whenever something is being fetched:
// a small status card with the operation and collection name. It waits a short moment first, so quick answers don't make
// it flicker. It never blocks clicks.
// ==========================================================

const SHOW_MS = 150;

const tasks = new Map();
let nextTask = 0;
let slowTimer = null;
let slow = false;
let showTimer = null;
let spinner = null;

function element() {
  if (spinner || !document.body) return spinner;
  spinner = document.createElement("div");
  spinner.className = "load-spinner";
  spinner.setAttribute("role", "status");
  spinner.innerHTML = '<span class="load-ring" aria-hidden="true"></span><span class="load-text">Loading…</span>';
  document.body.append(spinner);
  return spinner;
}

function message() {
  if (!spinner) return;
  const values=[...tasks.values()];
  const label=values[values.length-1] || 'Loading…';
  spinner.querySelector('.load-text').textContent=label+(slow?' This is taking longer than usual. Please wait; check the result before repeating a save.':'');
}
function start(label) {
  const id=++nextTask; tasks.set(id,label);message();
  if (tasks.size > 1) return id;
  slow=false;clearTimeout(slowTimer);slowTimer=setTimeout(()=>{slow=true;message();},8000);
  clearTimeout(showTimer);
  showTimer = setTimeout(() => {
    const el = element();
    if (el) {message();el.classList.add("is-on");}
    document.documentElement.setAttribute("aria-busy", "true");
  }, SHOW_MS);
  return id;
}

function stop(id) {
  tasks.delete(id);message();
  if (tasks.size) return;
  clearTimeout(slowTimer);slow=false;
  clearTimeout(showTimer);
  document.documentElement.removeAttribute("aria-busy");
  document.querySelectorAll('[data-loading-records]').forEach(el=>{el.removeAttribute('aria-busy');delete el.dataset.loadingRecords;});
  if (spinner) spinner.classList.remove("is-on");
}

/** Shows the spinner until the promise settles; returns the same promise. */
export function track(promise, label="Loading…") {
  const id=start(label);
  Promise.resolve(promise).then(()=>stop(id), ()=>stop(id));
  return promise;
}

/** Wraps an async function so each call shows the spinner. */
export function tracked(fn, verb="Loading") {
  return function (...args) {
    const names={gradingAssignments:'classes',gradeChangeRequests:'grade change requests',gradeCompletions:'final grades',rosterRequests:'class lists'};
    const col=String(args[0]?.path||'').split('/')[0];
    const label=`${verb} ${names[col]||col||'records'}…`;
    return track(fn.apply(this, args),label);
  };
}

/** Wraps onSnapshot: the spinner shows until the first answer (or error) arrives. */
export function trackedSnapshot(onSnapshot) {
  return function (target, ...args) {
    const i = args.findIndex((a) => typeof a === "function");
    if (i < 0) return onSnapshot(target, ...args); // observer objects: not tracked
    let done = false;
    let finish = () => {};
    const first = new Promise((resolve) => { finish = resolve; });
    const once = (fn) => function (...a) {
      if (!done) { done = true; finish(); }
      return fn.apply(this, a);
    };
    const wrapped = args.map((a) => (typeof a === "function" ? once(a) : a));
    const col=String(target?.path||'').split('/')[0];
    track(first,`Loading ${col==='gradingAssignments'?'classes':col||'records'}…`);
    // Give up on the spinner after 15 seconds (e.g. offline), the listener keeps working
    setTimeout(() => { if (!done) { done = true; finish(); } }, 15000);
    const unsubscribe = onSnapshot(target, ...wrapped);
    return () => {
      if (!done) { done = true; finish(); }
      unsubscribe();
    };
  };
}

// Existing rows stay visible while updating but cannot trigger actions on outdated results.
for(const type of ['click','keydown'])document.addEventListener(type,event=>{
  if(event.target.closest?.('[data-loading-records]')){event.preventDefault();event.stopImmediatePropagation();}
},true);
