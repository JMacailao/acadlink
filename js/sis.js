// ==========================================================
// sis.js — School Information System module (link to ../sis/)
// The SIS uses this same MySQL database; this page shows whether its
// tables are installed, lets an administrator install them, and opens it.
// ==========================================================

import { initLayout, toast, escapeHtml, setBusy, errorMessage, confirmDialog } from "./app.js";
import { apiPost } from "./php-auth.js";

const els = {
  badge: document.getElementById("sisBadge"),
  info: document.getElementById("sisInfo"),
  counts: document.getElementById("sisCounts"),
  open: document.getElementById("btnOpenSis"),
  install: document.getElementById("btnInstallSis"),
  creds: document.getElementById("sisCreds"),
};

function setBadge(text, cls) {
  els.badge.textContent = text;
  els.badge.className = "badge " + cls;
}

function show(sis) {
  els.counts.classList.add("d-none");
  els.open.classList.add("d-none");
  els.install.classList.add("d-none");
  if (!sis.present) {
    setBadge("Not uploaded", "text-bg-secondary");
    els.info.innerHTML = "The <code>sis/</code> folder isn't on this website. Upload it next to the <code>api/</code> folder.";
    return;
  }
  if (!sis.installed) {
    setBadge("Not installed", "text-bg-warning");
    els.info.innerHTML = `The SIS files are here, but its tables aren't in the database <strong>${escapeHtml(sis.database)}</strong> yet.` +
      " Ask the administrator to follow CENTRAL_ACCOUNTS.md and install through the SIS command line.";
    els.install.hidden = true;
    return;
  }
  setBadge("Installed", "text-bg-success");
  els.info.innerHTML = `Same database <strong>${escapeHtml(sis.database)}</strong>: the SIS uses this system's students, sections, year levels, teachers and curriculum, and adds Assessment and Transactions.` +
    (sis.schoolYear ? ` Latest school year with registrations: <strong>${escapeHtml(sis.schoolYear)}</strong>.` : "");
  const c = sis.counts || {};
  const tiles = [
    ["Admissions waiting", c.pendingAdmissions, "bi-person-plus"],
    ["Registered students", c.registrations, "bi-journal-check"],
    ["Assessment names", c.assessmentNames, "bi-card-list"],
    ["Collected today", `₱${Number(c.collectedToday || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`, "bi-cash-coin"],
  ];
  els.counts.innerHTML = tiles.map(([label, n, icon]) =>
    `<div class="col-6 col-md-3"><div class="border rounded p-2 h-100"><div class="small text-secondary"><i class="bi ${icon} me-1"></i>${label}</div><div class="fs-4 fw-semibold">${typeof n === "string" ? escapeHtml(n) : Number(n || 0)}</div></div></div>`).join("");
  els.counts.classList.remove("d-none");
  els.open.classList.remove("d-none");
}

async function refresh() {
  try {
    const r = await apiPost({ action: "sisStatus" });
    show(r.sis);
  } catch (err) {
    setBadge("Problem", "text-bg-danger");
    els.info.textContent = errorMessage(err);
  }
}

const profile = await initLayout("sis");
if (profile) await refresh();

els.install.hidden = true;
