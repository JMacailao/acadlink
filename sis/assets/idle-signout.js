/* v63.12: "Sign out automatically after no activity" (Settings → System Settings → Sign-in security) in every system.
   Activity is shared by all tabs (and with the Grading System) through localStorage, a warning with "Stay signed in"
   shows shortly before, and while someone is active the server is told now and then so a long form doesn't expire. */
(function () {
  'use strict';
  var meta = document.querySelector('meta[name="acadlink-idle"]');
  if (!meta) return;
  var minutes = Number(meta.content) || 0;
  if (!minutes) return;
  var sis = meta.getAttribute('data-sis') || '', csrf = meta.getAttribute('data-csrf') || '';
  var KEY = 'gs-last-activity'; // the same key as the Grading System, so activity there counts too
  var limit = minutes * 60000, warnBefore = Math.min(60000, Math.max(15000, limit * 0.25));
  var pingEvery = Math.max(60000, Math.min(300000, limit / 2));
  var lastMark = 0, lastPing = Date.now(), activeSincePing = false, signingOut = false, box = null;

  function read() { try { return Number(localStorage.getItem(KEY) || 0) || Date.now(); } catch (e) { return lastMark || Date.now(); } }
  function mark() { lastMark = Date.now(); try { localStorage.setItem(KEY, String(lastMark)); } catch (e) {} }
  function toLogin(reason) { location.href = sis + 'index.php?r=login' + (reason ? '&reason=' + reason : ''); }

  function warn(secondsLeft) {
    if (secondsLeft === null) { if (box) { box.remove(); box = null; } return; }
    if (!box) {
      box = document.createElement('div');
      box.className = 'idle-warn';
      box.setAttribute('role', 'alertdialog'); box.setAttribute('aria-live', 'assertive');
      box.innerHTML = '<div class="idle-warn-card"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div><b>Still there?</b>' +
        '<span>You will be signed out in <b class="idle-left"></b> because of no activity.</span></div>' +
        '<button type="button" class="btn btn-primary btn-sm">Stay signed in</button></div>';
      box.querySelector('button').addEventListener('click', function () { mark(); activeSincePing = true; ping(); warn(null); });
      document.body.appendChild(box);
      box.querySelector('button').focus();
    }
    box.querySelector('.idle-left').textContent = secondsLeft + ' s';
  }

  function ping() {
    lastPing = Date.now(); activeSincePing = false;
    fetch(sis + 'index.php?r=keepalive', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
      if (r.status === 401 || r.redirected || /[?&]r=login/.test(r.url)) toLogin(''); // signed out meanwhile (e.g. on another device)
    }).catch(function () { /* offline: try again later */ });
  }

  function signOut() {
    signingOut = true; warn(null);
    var body = new URLSearchParams(); body.set('_csrf', csrf);
    fetch(sis + 'index.php?r=logout', { method: 'POST', body: body, credentials: 'same-origin' })
      .catch(function () {}).then(function () { toLogin('idle'); });
  }

  // Away longer than the limit (laptop closed, tab asleep): sign out right away
  try { var stored = Number(localStorage.getItem(KEY) || 0); if (stored && Date.now() - stored > limit) { signOut(); return; } } catch (e) {}
  mark();
  ['pointerdown', 'keydown', 'scroll', 'touchstart', 'wheel', 'mousemove'].forEach(function (ev) {
    document.addEventListener(ev, function () {
      if (signingOut) return;
      var now = Date.now();
      if (now - lastMark > 5000) mark();
      activeSincePing = true;
      if (box) warn(null);
    }, { passive: true, capture: true });
  });
  setInterval(function () {
    if (signingOut) return;
    var idle = Date.now() - read(); // includes activity in other tabs
    if (idle >= limit) signOut();
    else if (idle >= limit - warnBefore) warn(Math.ceil((limit - idle) / 1000));
    else { if (box) warn(null); if (activeSincePing && Date.now() - lastPing >= pingEvery) ping(); }
  }, 1000);
})();
