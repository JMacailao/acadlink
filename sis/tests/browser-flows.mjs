// v66 Browser flows: does real work in a real browser on the throwaway AcadLink built by sis/tests/ci-seed.php
// (sample data: BSIT sections, registrations REG …1 and …2, an Academic Scholarship on the second one).
//   BASE=http://127.0.0.1:8090 PASSWORD=CiBrowser2026 node sis/tests/browser-flows.mjs [screenshot folder]
// Each flow fails on a JavaScript error, a server error, or when the expected change does not happen.
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
import fs from 'fs';

const BASE = process.env.BASE || 'http://127.0.0.1:8090';
const PASSWORD = process.env.PASSWORD || 'CiBrowser2026';
const OUT = process.argv[2] || 'browser-flows';
fs.mkdirSync(OUT, { recursive: true });
const SIS = r => `${BASE}/sis/index.php?r=${r}`;
const money = t => Number(String(t).replace(/[^0-9.]/g, '')) || 0;

const browser = await chromium.launch();
const failures = [];
async function signIn(user) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await ctx.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
  const page = await ctx.newPage(); page.problems = [];
  page.on('pageerror', e => page.problems.push(`JavaScript error: ${e.message}`));
  page.on('response', r => { if (r.status() >= 500) page.problems.push(`HTTP ${r.status()} ${r.url().replace(BASE, '')}`); });
  page.on('dialog', d => d.accept());
  await page.goto(SIS('login')); await page.fill('#username', user); await page.fill('#password', PASSWORD);
  await Promise.all([page.waitForNavigation(), page.click('.sl-submit')]);
  if (await page.$('button[name=takeover]')) await Promise.all([page.waitForNavigation(), page.click('button[name=takeover]')]);
  return page;
}
async function flow(name, user, fn) {
  const page = await signIn(user);
  try { await fn(page); if (page.problems.length) throw new Error(page.problems.join('; ')); console.log(`ok   ${name}`); }
  catch (e) { failures.push(`${name}: ${e.message.split('\n')[0]}`); console.log(`FAIL ${name}`); await page.screenshot({ path: `${OUT}/${name.replace(/[^a-z0-9]+/gi, '_')}.png`, fullPage: true }).catch(() => {}); }
  await page.context().close();
}
const expect = (ok, what) => { if (!ok) throw new Error(what); };
const flash = async page => (await page.locator('.alert').first().textContent().catch(() => '')).trim();

// 1. Take a fee off a registration: the reason window, the lower total and the record in Changes and approvals
await flow('take a fee off with a reason', 'power.admin', async page => {
  await page.goto(SIS('registration&apply=1')); await page.waitForTimeout(500);
  const id = await page.evaluate(() => { const r = document.querySelector('tr[data-open*="r=registration&id="]'); return r ? new URL(r.dataset.open, location.href).searchParams.get('id') : null; });
  expect(id, 'no registration in the list');
  await page.goto(SIS('registration&id=' + id)); await page.waitForTimeout(400);
  const line = page.locator('.soa-fee-line', { has: page.locator('form[data-ask-reason]') }).first();
  expect(await line.count(), 'no fee line with an ×');
  const before = money(await page.locator('.soa-grand-total th').last().innerText());
  await line.locator('form button').click();
  await page.waitForSelector('dialog.ask-reason[open]', { timeout: 5000 });
  await page.fill('dialog.ask-reason textarea', 'Browser check: fee not charged');
  await Promise.all([page.waitForNavigation(), page.click('dialog.ask-reason button[value=ok]')]);
  const after = money(await page.locator('.soa-grand-total th').last().innerText());
  expect(after < before, `total did not go down (${before} → ${after})`);
  expect(await page.locator('#regChanges', { hasText: 'Browser check: fee not charged' }).count(), 'the change and its reason are not listed');
  await page.goto(SIS('approvals&tab=history')); expect(await page.locator('tbody tr', { hasText: 'Browser check' }).count(), 'not in Approvals → History');
});

// 2. Subject pricing: set a price for one subject and see it in the list
await flow('set a subject price', 'power.admin', async page => {
  await page.goto(SIS('subjectfees&new=1')); await page.waitForTimeout(400);
  await page.fill('#spRate', '123.45');
  await page.locator('[data-sp-avail] button.is-add').first().click();
  expect((await page.locator('[data-sp-stat="count"]').innerText()).trim() === '1', 'summary does not count the subject');
  await Promise.all([page.waitForNavigation(), page.click('[data-sp-save]')]);
  expect(/subject price/i.test(await flash(page)), 'no "saved" message: ' + (await flash(page)));
  expect(await page.locator('#subjectPrices td', { hasText: '123.45' }).count(), 'the price is not in the list');
});

// 3. Auto-schedule every section; the saved schedules show in a section
await flow('auto-schedule all sections', 'power.admin', async page => {
  await page.goto(SIS('schedules&autoall=1')); await page.waitForTimeout(500);
  const save = page.locator('.auto-draft button.btn-primary');
  if (await save.count()) {
    await Promise.all([page.waitForNavigation(), save.click()]);
    expect(/class schedules? saved/i.test(await flash(page)), 'not saved: ' + (await flash(page)));
  }
  await page.goto(SIS('schedules&autoall=1')); await page.waitForTimeout(400);
  expect(!(await page.locator('.auto-draft').count()), 'running it again still wants to add meetings');
});

// 4. The registrar can open Approvals and sees no approve buttons
await flow('registrar sees approvals without approving', process.env.REGISTRAR || 'ci.registrar', async page => {
  await page.goto(SIS('approvals')); expect(!/r=login/.test(page.url()), 'sent to sign-in');
  expect(!(await page.locator('button[value=approve]').count()), 'registrar can approve');
});

await browser.close();
console.log(`\n${failures.length} flow(s) with problems.`);
if (failures.length) { console.log('\n' + failures.join('\n')); process.exit(1); }
