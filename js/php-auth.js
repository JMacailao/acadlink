// ==========================================================
// php-auth.js — Sign-in through the school's own server (api/api.php).
//
// Gives the pages the same sign-in functions they always used
// (onAuthStateChanged, signInWithEmailAndPassword, signOut, …), but the
// passwords are checked by PHP against MySQL. The sign-in is kept in a
// secure session cookie that page code can't read.
// ==========================================================

import { createReadCache } from "./read-cache.js";
import { track } from "./loading.js";

export const API_URL = new URL("../api/api.php", import.meta.url).href;

function apiError(code, message) {
  const e = new Error(message);
  e.code = code;
  e.name = "FirebaseError"; // the pages' error messages look at .code, as before
  return e;
}

/** One request to the server; returns its answer or throws an error with .code. */
let clientScope='', readCache;
const dataChannel=typeof BroadcastChannel==='function'?new BroadcastChannel('acadlink-data-v31'):null;
export function clearReadCache(announce=false){readCache?.clear();if(announce){dataChannel?.postMessage({type:'invalidate'});if(typeof window!=='undefined')window.dispatchEvent(new Event('acadlink:data-invalidated'));}}
dataChannel?.addEventListener('message',()=>{clearReadCache();if(typeof window!=='undefined')window.dispatchEvent(new Event('acadlink:data-invalidated'));});
if(typeof window!=='undefined'){
  window.addEventListener('pagehide',()=>clearReadCache());
  window.addEventListener('focus',()=>clearReadCache());
  window.AcadLinkDataCache={clear:()=>clearReadCache(true)};
}
async function rawApiPost(body) {
  let res;
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),['get','query','count','whoami'].includes(body.action)?25000:90000);
  try {
    res = await fetch(API_URL, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "text/plain;charset=UTF-8" },
      body: JSON.stringify(body),
      cache: "no-store",
      signal: controller.signal,
    });
  } catch (error) {
    clearTimeout(timer);
    if(error.name==='AbortError')throw apiError('unavailable','The request timed out. Check your connection. If you were saving, check the record before submitting again.');
    if (location.protocol === "file:") {
      throw apiError("unavailable", "The pages were opened as files. Open them through the web server instead, e.g. http://localhost/GradingSystem-PHP-MySQL/pages/login.html (XAMPP: start Apache and MySQL first).");
    }
    throw apiError("unavailable", "Can't reach the school's server (api/api.php). Check that the web server is running and your internet connection.");
  }
  let text;
  try { text = await res.text(); }
  catch { throw apiError('unavailable','The connection stopped while receiving data. Try loading again. If you were saving, check the record first.'); }
  finally {clearTimeout(timer);}
  const responseScope=res.headers.get('X-AcadLink-Scope')||'';
  if(clientScope!==responseScope){clientScope=responseScope;clearReadCache();}
  let data;
  try {
    data = JSON.parse(text);
  } catch {
    const why = res.status === 404 ? "api/api.php wasn't found: upload the api/ folder next to pages/."
      : /<\?php/.test(text) ? "PHP isn't running on this web server (it sent the PHP code instead of running it)."
      : res.status >= 500 ? `PHP stopped with an error (HTTP ${res.status}): check that PHP is 8.1 or newer with pdo_mysql, and look in the server's PHP error log.`
      : `It answered HTTP ${res.status} without data.`;
    throw apiError("unavailable", `The school's server didn't answer correctly. ${why} Open api/api.php in the browser to check the database connection.`);
  }
  if (!data.ok) {clearReadCache();throw apiError(data.code || "internal", data.message || "The server refused the request.");}
  return data;
}

readCache=createReadCache({
  send:rawApiPost,
  getScope:()=>clientScope,
  verify:async()=>{
    const before=clientScope,result=await rawApiPost({action:'whoami'});
    if(!result.user||before!==clientScope||result.user.uid!==auth.currentUser?.uid){
      clearReadCache();
      auth.currentUser=makeUser(result.user);emit();
      throw apiError('unauthenticated','Your sign-in or permissions changed. Sign in again to load current records.');
    }
  }
});
export function apiPost(body){
  // Presence heartbeats change no academic data and should not refresh every open view.
  const presence=body.action==='commit'&&Array.isArray(body.ops)&&body.ops.length>0&&body.ops.every(op=>op.type==='update'&&/^users\/[^/]+$/.test(op.path||'')&&op.data&&Object.keys(op.data).length===1&&Object.hasOwn(op.data,'lastSeen'));
  if(presence)return rawApiPost(body);
  const read=['get','query','count','whoami','ping','accountAccess','adminUids','sisStatus','enrollmentStatus'].includes(body.action);
  if(!read)clearReadCache();
  const promise=readCache.run(body).finally(()=>{if(!read)clearReadCache(true);});
  if(body.action==='whoami')return promise;
  return track(promise,read?'Loading records…':'Saving changes…');
}

function makeUser(u) {
  return u ? { uid: u.uid, email: u.email, mustChangePassword: !!u.mustChangePassword, getIdToken: () => Promise.resolve(null) } : null;
}

// ---------- The signed-in person ----------
export const auth = { currentUser: null, name: "[DEFAULT]" };
const listeners = new Set();
const ready = apiPost({ action: "whoami" })
  .then((r) => { auth.currentUser = makeUser(r.user); })
  .catch(() => { auth.currentUser = null; });

function emit() {
  listeners.forEach((cb) => {
    try { cb(auth.currentUser); } catch (err) { console.error(err); }
  });
}

export function onAuthStateChanged(a, callback) {
  listeners.add(callback);
  ready.then(() => { if (listeners.has(callback)) callback(a.secondary ? null : auth.currentUser); });
  return () => listeners.delete(callback);
}

export async function signInWithEmailAndPassword(a, email, password) {
  const r = await apiPost({ action: "login", email, password });
  const user = makeUser(r.user);
  if (!a.secondary) {
    auth.currentUser = user;
    emit();
  }
  return { user };
}

/**
 * A new login. On the main sign-in (first-time setup) the new account is signed in; from
 * the second "in-memory" sign-in (administrators creating accounts) it isn't.
 */
export async function createUserWithEmailAndPassword(a, email, password) {
  const r = await apiPost({ action: "createAccount", email, password, login: !a.secondary });
  const user = makeUser(r.user);
  if (!a.secondary) {
    auth.currentUser = user;
    emit();
  }
  return { user };
}

export async function signOut(a) {
  if (a && a.secondary) return;
  try { await apiPost({ action: "logout" }); } catch {}
  auth.currentUser = null;
  emit();
}

export const EmailAuthProvider = { credential: (email, password) => ({ email, password }) };

export async function reauthenticateWithCredential(user, credential) {
  await apiPost({ action: "reauth", password: credential.password });
  return { user };
}

export async function updatePassword(user, password) {
  await apiPost({ action: "changePassword", password });
}

// A second sign-in kept in memory (used to create accounts without signing the admin out)
export function initializeAuth() {
  return { secondary: true, currentUser: null, name: "secondary" };
}
export const inMemoryPersistence = {};
export function initializeApp(options = {}, name = "[DEFAULT]") {
  return { options, name };
}
export async function deleteApp() {}

/** Administrators: a new password for someone (same account). */
export function setUserPassword(uid, password) {
  return apiPost({ action: "setPassword", uid, password });
}

/** Administrators: temporary passwords; uids = null for every account without a password yet. */
export async function makeTemporaryPasswords(uids = null) {
  const r = await apiPost({ action: "tempPasswords", uids });
  return r.accounts || [];
}
