/* v64.6: "Inspect" is restricted on AcadLink pages.
   Right-click (outside text boxes), F12, Ctrl+Shift+I / J / C and Ctrl+U (view source) are blocked with a short message.
   This only discourages casual looking: Chrome's own menu (More tools → Developer tools) cannot be blocked by any website.
   AcadLink's real protection is on the server, which checks every permission whatever happens in the browser. */
(function () {
  'use strict';
  if (window.__alNoInspect) return; window.__alNoInspect = true;
  var tip, timer;
  function say() {
    if (!document.body) return;
    if (!tip) {
      tip = document.createElement('div'); tip.setAttribute('role', 'status');
      tip.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:2147483646;background:#123D70;color:#fff;font:600 14px/1.4 system-ui,sans-serif;padding:10px 18px;border-radius:999px;box-shadow:0 8px 24px #12305a40;pointer-events:none;transition:opacity .2s;opacity:0';
      tip.textContent = 'Inspect is restricted on AcadLink.';
      document.body.appendChild(tip);
    }
    tip.style.opacity = '1'; clearTimeout(timer); timer = setTimeout(function () { tip.style.opacity = '0'; }, 1800);
  }
  var typing = function (el) { return el && el.closest && el.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]'); };
  document.addEventListener('contextmenu', function (e) { if (typing(e.target)) return; e.preventDefault(); say(); }, true);
  document.addEventListener('keydown', function (e) {
    var k = (e.key || '').toLowerCase(), mod = e.ctrlKey || e.metaKey;
    var devtools = e.key === 'F12' || (mod && e.shiftKey && (k === 'i' || k === 'j' || k === 'c')) || (e.metaKey && e.altKey && (k === 'i' || k === 'j' || k === 'c' || k === 'u'));
    var source = mod && !e.shiftKey && !e.altKey && k === 'u';
    if (devtools || source) { e.preventDefault(); e.stopPropagation(); say(); }
  }, true);
})();
