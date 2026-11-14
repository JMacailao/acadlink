// ==========================================================
// auth.js — Sign in with username + password, and first-run
// creation of the administrator account.
// ==========================================================

import {
  db, auth, isConfigured, doc, getDoc, writeBatch, serverTimestamp, databaseBackend,
  onAuthStateChanged, signInWithEmailAndPassword, signOut,
} from "./firebase-config.js";
import {
  rememberUi, loadProfile, authErrorMessage, passwordProblem, setBusy,
  clearErrors, fieldError, errorMessage, USERNAME_PATTERN, cleanUsername, usernameEmail,
  getSchool, cachedSchool, applyBranding, escapeHtml,
  getSecurity, claimSession, mySession, confirmDialog, timeAgo, autoLogoutLabel,
} from "./app.js";

const els = {
  loading: document.getElementById("loadingState"),
  reason: document.getElementById("reasonAlert"),
  configAlert: document.getElementById("config-alert"),
  signinForm: document.getElementById("signinForm"),
  signinError: document.getElementById("signinError"),
  username: document.getElementById("loginUsername"),
  password: document.getElementById("loginPassword"),
  btnSignin: document.getElementById("btnSignin"),
  setupForm: document.getElementById("setupForm"),
  setupError: document.getElementById("setupError"),
  setupName: document.getElementById("setupName"),
  setupUsername: document.getElementById("setupUsername"),
  setupPassword: document.getElementById("setupPassword"),
  setupConfirm: document.getElementById("setupConfirm"),
  btnSetup: document.getElementById("btnSetup"),
};

const REASONS = {
  disabled: "This account is disabled. Ask an administrator to turn it back on.",
  noprofile: "This account has been removed. Ask an administrator for access.",
  elsewhere: "You were signed out because your account was opened on another device.",
  idle: "You were signed out because there was no activity for a while.",
};

function destination() {
  const next = new URLSearchParams(location.search).get("next") || "";
  // Only allow our own page names
  // Without a page link, signing in shows the systems this account may open
  return /^[a-z-]+\.html$/.test(next) && next !== "login.html" && next !== "dashboard.html" ? next : "../sis/index.php?r=workspaces";
}

// Before signing in, visitors first choose: Grading System, Admission & Registration, or Student Portal.
// Straight to the sign-in form when returning from a sign-out reason or a page link (?next=…).
const chooser = document.getElementById("portalChooser");
// One account for every system: sign in first, then the systems this account may open are shown.
const skipChooser = () => true;

function show(which) {
  if (which === "signin" && chooser && !show.chosen && !skipChooser()) which = "choose";
  els.loading.classList.add("d-none");
  chooser?.classList.toggle("d-none", which !== "choose");
  document.querySelector(".sl-card")?.classList.toggle("is-wide", which === "choose");
  // The chooser takes the whole page; the sign-in form keeps the navy side panel
  document.querySelector(".sl")?.classList.toggle("is-choosing", which === "choose");
  if (which === "choose") {
    const school = document.getElementById("brandName")?.textContent?.trim();
    if (school) document.getElementById("chooserSchool").textContent = school;
    const seal = document.getElementById("brandSeal"), copy = document.getElementById("chooserSeal");
    if (seal && copy) { copy.innerHTML = seal.innerHTML; copy.className = seal.className; }
  }
  // Only one view at a time: hide the sign-in and setup forms while the cards are shown
  els.signinForm.classList.toggle("d-none", which !== "signin");
  els.setupForm?.classList.toggle("d-none", which !== "setup");
  if (which === "choose") return;
  const focus = which === "signin" ? els.username : els.setupName;
  focus?.focus();
}

function showError(box, message) {
  box.textContent = message;
  box.classList.remove("d-none");
}

function firstAuthState() {
  return new Promise((resolve) => {
    const stop = onAuthStateChanged(auth, (u) => { stop(); resolve(u); });
  });
}

// ---------- Sign in ----------
async function signin(e) {
  e.preventDefault();
  clearErrors(els.signinForm);
  els.signinError.classList.add("d-none");

  const username = cleanUsername(els.username.value);
  const password = els.password.value;
  let ok = true;
  if (!username) { fieldError(els.username, "Enter your username."); ok = false; }
  if (!password) { fieldError(els.password, "Enter your password."); ok = false; }
  if (!ok) return;

  setBusy(els.btnSignin, true, "Signing in…");
  try {
    let cred;
    try {
      cred = await signInWithEmailAndPassword(auth, username, password);
    } catch (err) {
      // v63.12: One device at a time covers every AcadLink system; nothing has changed yet, so ask first
      if (err.code !== "auth/session-conflict") throw err;
      const ok = await confirmDialog({
        title: "Your account is open on another device",
        message: `${err.message} Only one device can be signed in at a time. Sign out the other device and continue here?`,
        confirmText: "Sign out the other device and continue",
        variant: "primary",
      });
      if (!ok) { showError(els.signinError, "Sign-in cancelled. Your account stays open on the other device."); return; }
      cred = await signInWithEmailAndPassword(auth, username, password, true);
    }
    if (cred.user.mustChangePassword) { location.assign('../sis/index.php?r=password'); return; }
    const profile = await loadProfile(cred.user.uid);
    if (!profile || profile.active === false) {
      await signOut(auth);
      showError(els.signinError, profile ? REASONS.disabled : REASONS.noprofile);
      return;
    }
    // One device at a time: the server already asked (above); mark this device as the Grading System's active one
    const security = await getSecurity(true);
    if (security.singleSession) await claimSession(cred.user.uid);
    rememberUi(profile);
    location.replace(destination());
  } catch (err) {
    showError(els.signinError, authErrorMessage(err));
  } finally {
    setBusy(els.btnSignin, false);
  }
}

// ---------- Show / hide password ----------
document.querySelectorAll("[data-toggle-pw]").forEach((btn) => {
  btn.addEventListener("click", () => {
    const input = document.getElementById(btn.dataset.togglePw);
    const showing = input.type === "text";
    input.type = showing ? "password" : "text";
    btn.innerHTML = `<i class="bi ${showing ? "bi-eye-slash" : "bi-eye"}"></i>`;
    btn.setAttribute("aria-label", showing ? "Show password" : "Hide password");
  });
});

// ---------- Start ----------
async function init() {
  if (!isConfigured) {
    location.replace("setup.html");
    return;
  }

  const reason = new URLSearchParams(location.search).get("reason");
  if (REASONS[reason]) {
    els.reason.textContent = REASONS[reason];
    els.reason.classList.remove("d-none");
  }

  // Already signed in? Go straight in.
  const user = await firstAuthState();
  if (user) {
    try {
      if (user.mustChangePassword) { location.assign("../sis/index.php?r=password"); return; }
      const profile = await loadProfile(user.uid);
      if (profile && profile.active !== false) {
        rememberUi(profile);
        location.replace(destination());
        return;
      }
    } catch (err) {
      console.error(err);
    }
    await signOut(auth);
  }

  // One sign-in page for every system (SIS); it then shows the systems this account may open
  const q = new URLSearchParams(location.search), fwd = new URLSearchParams({ r: "login" });
  if (q.get("reason")) fwd.set("reason", q.get("reason"));
  if (/^[a-z-]+\.html$/.test(q.get("next") || "")) fwd.set("next", q.get("next"));
  location.replace("../sis/index.php?" + fwd.toString());
}

// School name and logo (settings/school is readable before sign-in)
function showSchool(s) {
  applyBranding(s);
  const el = document.getElementById("loginSchool");
  if (el && s.schoolName) {
    el.innerHTML = `<strong>${escapeHtml(s.schoolName)}</strong>${s.address ? `<br>${escapeHtml(s.address)}` : ""}`;
  }
}
showSchool(cachedSchool());
if (isConfigured) getSchool().then(showSchool);

document.getElementById("btnChooseGrading")?.addEventListener("click", () => { show.chosen = true; show("signin"); });
document.getElementById("btnBackToChooser")?.addEventListener("click", () => { show.chosen = false; show("choose"); });
els.signinForm.addEventListener("submit", signin);
els.setupForm?.remove();
init();
