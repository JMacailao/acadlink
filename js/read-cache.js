/* Bounded, memory-only read cache. Validation and every write remain server-authoritative. */
export function createReadCache({send,verify=async()=>{},getScope=()=>'',now=Date.now,maxEntries=60,maxBytes=4000000}) {
  const entries=new Map(),pending=new Map();let epoch=0,bytes=0,writes=0,verification;
  const clone=value=>typeof structuredClone==='function'?structuredClone(value):JSON.parse(JSON.stringify(value));
  const stable=value=>Array.isArray(value)?value.map(stable):value&&typeof value==='object'?Object.fromEntries(Object.keys(value).sort().map(key=>[key,stable(value[key])])):value;
  const key=body=>getScope()+'|'+JSON.stringify(stable(body));
  function clear(){epoch++;entries.clear();pending.clear();bytes=0;}
  function lifetime(body){
    if(!['get','query','count'].includes(body.action))return 0;
    const col=String(body.collection||body.path||'').split('/')[0];
    if(['sections','subjects','curricula','curriculum','settings','teachers','yearLevels','programs','gradingScales','terms'].includes(col))return 300000;
    if(col==='students')return 60000;
    if(['gradingAssignments','grades','gradeCompletions'].includes(col))return 30000;
    return 0;
  }
  async function run(body){
    const read=['get','query','count','whoami','ping','accountAccess','adminUids','sisStatus','enrollmentStatus'].includes(body.action);
    if(!read){clear();writes++;try{return await send(body);}finally{writes--;clear();}}
    const ttl=lifetime(body);if(!ttl||!getScope()||writes)return send(body);
    const requestKey=key(body),entry=entries.get(requestKey);
    if(entry&&entry.expires>now()){
      const before=epoch,scope=getScope();
      if(!verification)verification=Promise.resolve().then(verify).finally(()=>verification=null);
      await verification;
      if(before===epoch&&scope===getScope()&&entries.get(requestKey)===entry){entries.delete(requestKey);entries.set(requestKey,entry);return clone(entry.value);}
      return run(body);
    }
    if(pending.has(requestKey))return clone(await pending.get(requestKey));
    const before=epoch;
    const request=Promise.resolve().then(()=>send(body)).then(value=>{
      if(epoch!==before){if(pending.get(requestKey)===request)pending.delete(requestKey);return run(body);}
      if(!writes){
        const size=JSON.stringify(value).length;
        if(size<=1000000){if(entries.has(requestKey))bytes-=entries.get(requestKey).size;entries.delete(requestKey);entries.set(requestKey,{value:clone(value),expires:now()+ttl,size});bytes+=size;
          while(entries.size>maxEntries||bytes>maxBytes){const first=entries.keys().next().value;bytes-=entries.get(first).size;entries.delete(first);}}
      }
      return value;
    }).finally(()=>{if(pending.get(requestKey)===request)pending.delete(requestKey);});
    pending.set(requestKey,request);return clone(await request);
  }
  return {run,clear,lifetime,get size(){return entries.size;}};
}
