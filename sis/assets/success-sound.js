/* AcadLink v56: a short chime after a confirmed successful Save or Send.
   It plays only when the server reported success (a green flash message, or a pop-up form that saved).
   The visual message always stays. Each person can turn the sound off (Profile → Sound), saved in this browser. */
(function () {
  "use strict";
  var KEY = "acadlink-sound", LAST = "acadlink-sound-last";
  function enabled() { try { return localStorage.getItem(KEY) !== "off"; } catch (e) { return true; } }
  function recently() {
    try { var t = Number(sessionStorage.getItem(LAST) || 0); sessionStorage.setItem(LAST, String(Date.now())); return Date.now() - t < 1500; }
    catch (e) { return false; }
  }
  function chime() {
    if (!enabled() || recently()) return;
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return;
    try {
      var ctx = new AC(), now = ctx.currentTime;
      [[880, 0], [1318.5, 0.11]].forEach(function (n) {
        var o = ctx.createOscillator(), g = ctx.createGain();
        o.type = "sine"; o.frequency.value = n[0];
        g.gain.setValueAtTime(0.0001, now + n[1]);
        g.gain.exponentialRampToValueAtTime(0.09, now + n[1] + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, now + n[1] + 0.22);
        o.connect(g); g.connect(ctx.destination);
        o.start(now + n[1]); o.stop(now + n[1] + 0.25);
      });
      // Browsers may block sound until the person has clicked on the site; then nothing plays (no error shown).
      if (ctx.state === "suspended" && ctx.resume) ctx.resume().catch(function () {});
      setTimeout(function () { try { ctx.close(); } catch (e) {} }, 800);
    } catch (e) { /* sound is optional */ }
  }
  window.AcadSound = {
    success: chime,
    enabled: enabled,
    set: function (on) { try { localStorage.setItem(KEY, on ? "on" : "off"); } catch (e) {} }
  };
  function onLoad() {
    if (document.querySelector('[data-flash="success"]') || document.body.getAttribute("data-changed") === "1") chime();
    bindToggles(document);
  }
  function bindToggles(root) {
    (root || document).querySelectorAll("[data-sound-toggle]:not([data-sound-bound])").forEach(function (box) {
      box.setAttribute("data-sound-bound", "1");
      box.checked = enabled();
      box.addEventListener("change", function () { window.AcadSound.set(box.checked); if (box.checked) { try { sessionStorage.removeItem(LAST); } catch (e) {} chime(); } });
    });
  }
  window.AcadSound.bind = bindToggles;
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", onLoad); else onLoad();
  // Pages shown by in-place navigation (SIS lifecycle) get their Sound switch wired too.
  if (window.SISLifecycle) window.SISLifecycle.register("success-sound", function () { bindToggles(document); }, 90);
})();
