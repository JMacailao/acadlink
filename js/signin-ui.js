// ==========================================================
// signin-ui.js — the shared sign-in page design (grading system, SIS staff, Student Portal):
// live date and time, the school picture, a tick once a username is typed, show / hide the
// password ([data-pw-toggle]; the grading page uses its own [data-toggle-pw]), and
// "Remember me" (keeps the username in this browser only, never the password, and greets
// the person by it next time: "Welcome Back, Admin").
// ==========================================================
(function () {
  "use strict";
  var dEl = document.querySelector("[data-clock-date]"), tEl = document.querySelector("[data-clock-time]");
  function tick() {
    var now = new Date();
    if (dEl) dEl.textContent = now.toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric", year: "numeric" });
    if (tEl) tEl.textContent = now.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" });
  }
  if (dEl || tEl) { tick(); setInterval(tick, 15000); }

  // The school picture: shown once it loads; hidden when none is set
  document.querySelectorAll("img[data-signin-pic]").forEach(function (img) {
    var side = img.closest(".sl-side");
    function ok() { if (img.naturalWidth > 0 && side) side.classList.add("has-pic"); }
    function gone() { img.remove(); if (side) side.classList.remove("has-pic"); }
    if (img.complete) { if (img.naturalWidth > 0) ok(); else gone(); }
    img.addEventListener("load", ok);
    img.addEventListener("error", gone);
  });

  document.querySelectorAll("[data-pw-toggle]").forEach(function (b) {
    var input = document.querySelector(b.dataset.pwToggle); if (!input) return;
    b.addEventListener("click", function () {
      var show = input.type === "password";
      input.type = show ? "text" : "password";
      b.setAttribute("aria-pressed", show ? "true" : "false");
      b.setAttribute("aria-label", show ? "Hide password" : "Show password");
      b.querySelector("i").className = "bi " + (show ? "bi-eye" : "bi-eye-slash");
    });
  });

  var form = document.querySelector("[data-signin]"); if (!form) return;
  var user = form.querySelector("[data-signin-user]"), remember = form.querySelector("[data-remember]");
  if (!user) return;
  var KEY = "signin.rememberUser." + (form.dataset.signin || "sis");
  function mark() { var f = user.closest(".sl-field"); if (f) f.classList.toggle("has-value", user.value.trim() !== ""); }
  var saved = null; try { saved = localStorage.getItem(KEY); } catch (e) {}
  if (saved) {
    user.value = saved; if (remember) remember.checked = true;
    var line = document.querySelector("[data-welcome-line]"), name = document.querySelector("[data-welcome-name]");
    if (line) line.textContent = "Welcome Back,";
    if (name) name.textContent = saved.charAt(0).toUpperCase() + saved.slice(1);
    var pw = form.querySelector("input[type=password]");
    if (pw && form.offsetParent !== null) pw.focus();
  }
  mark(); user.addEventListener("input", mark);
  form.addEventListener("submit", function () {
    try { if (remember && remember.checked && user.value.trim()) localStorage.setItem(KEY, user.value.trim()); else localStorage.removeItem(KEY); } catch (e) {}
  });
})();
