// v63.18 Browser checks: signs in as each role and opens its main pages in a real browser (Chromium, via Playwright).
// A page fails on: a server error (5xx), a JavaScript error, a Content-Security-Policy refusal, PHP error text on the page,
// or being sent back to the sign-in page. Needs a running AcadLink built by sis/tests/ci-seed.php.
//   BASE=http://127.0.0.1:8090 PASSWORD=CiBrowser2026 node sis/tests/browser-smoke.mjs [screenshot folder]
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
import fs from 'fs';

const BASE = process.env.BASE || 'http://127.0.0.1:8090';
const PASSWORD = process.env.PASSWORD || 'CiBrowser2026';
const OUT = process.argv[2] || 'browser-smoke';
fs.mkdirSync(OUT, { recursive: true });

const SIS = r => `/sis/index.php?r=${r}`;
// [account, [path or [path, frame-url pattern]]]
const PLAN = [
  ['power.admin', [SIS('dashboard'), SIS('workspaces'), SIS('students'), SIS('registration'), SIS('admission'), SIS('cashier'), SIS('assessment&tab=categories'),
    SIS('curriculum'), SIS('sections'), SIS('teachers'), SIS('schedules'), SIS('reports'), SIS('users'), SIS('activity'), SIS('notifications'), SIS('profile'),
    SIS('syscheck'), SIS('school'), SIS('settings'), SIS('docrequirements'), SIS('emailsetup'), SIS('backup'), SIS('bulkaccounts'), SIS('migration'), SIS('importall'),
    [SIS('gradingoptions'), /pages\/options\.html/], [SIS('systemsettings'), /pages\/settings\.html/], [SIS('gradingaudit'), /pages\/audit\.html/],
    '/finance/index.php', '/canteen/index.php', '/library/index.php', '/lms/index.php', ['/', /pages\/dashboard\.html/]]],
  ['ci.registrar', [SIS('dashboard'), SIS('students'), SIS('registration'), SIS('admission'), SIS('enrollment')]],
  ['ci.cashier', [SIS('cashier'), '/finance/index.php']],
  ['ci.canteen', ['/canteen/index.php']],
  ['ci.librarian', ['/library/index.php']],
  ['ci.teacher', ['/lms/index.php', ['/', /pages\/dashboard\.html/]]],
  ['ci.student', ['/portal/index.php', '/portal/index.php?r=schedule', '/portal/index.php?r=my']],
];
const BAD_TEXT = /A database error occurred|Fatal error|Parse error|\bWarning:|\bDeprecated:|\bNotice:|Uncaught /;

const browser = await chromium.launch();
const failures = []; let pages = 0;
for (const [user, paths] of PLAN) {
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  await ctx.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort()); // web fonts are optional
  const page = await ctx.newPage();
  let current = '';
  const problems = [];
  page.on('pageerror', e => problems.push(`JavaScript error: ${e.message}`));
  page.on('console', m => { if (m.type() === 'error' && /Content Security Policy|Refused to/.test(m.text())) problems.push(`Security policy: ${m.text().slice(0, 200)}`); });
  page.on('response', r => { if (r.status() >= 500) problems.push(`HTTP ${r.status()} ${r.url().replace(BASE, '')}`); });

  await page.goto(BASE + SIS('login'));
  await page.fill('#username', user); await page.fill('#password', PASSWORD);
  await Promise.all([page.waitForNavigation(), page.click('.sl-submit')]);
  if (await page.$('button[name=takeover]')) await Promise.all([page.waitForNavigation(), page.click('button[name=takeover]')]);
  if (/r=login/.test(page.url())) { failures.push(`${user}: could not sign in (${await page.locator('.alert').first().innerText().catch(() => 'no message')})`); await ctx.close(); continue; }

  for (const item of paths) {
    const [path, framePattern] = Array.isArray(item) ? item : [item, null];
    current = path; problems.length = 0; pages++;
    let status = 0;
    try {
      const resp = await page.goto(BASE + path, { waitUntil: 'load', timeout: 45000 });
      status = resp ? resp.status() : 0;
      await page.waitForTimeout(framePattern ? 3500 : 600);
      let target = page;
      if (framePattern) {
        const f = page.frames().find(x => framePattern.test(x.url()));
        if (!f) problems.push(`the page inside (${framePattern}) did not load`); else target = f;
      }
      const text = await target.evaluate(() => document.body ? document.body.innerText : '');
      const m = text.match(BAD_TEXT); if (m) problems.push(`page shows "${m[0].trim()}": …${text.slice(Math.max(0, m.index - 60), m.index + 120).replace(/\s+/g, ' ')}…`);
      if (/[?&]r=login\b/.test(page.url())) problems.push('sent back to the sign-in page');
      const asked = new URL(BASE + path).searchParams.get('r'), landed = new URL(page.url()).searchParams.get('r');
      if (asked && landed !== asked) problems.push(`sent to ?r=${landed} instead of ?r=${asked}`);
      if (status >= 400 && status !== 404) problems.push(`HTTP ${status}`);
    } catch (e) { problems.push(`could not open: ${e.message.split('\n')[0]}`); }
    const tag = `${user} ${path}`;
    if (problems.length) {
      failures.push(`${tag}\n    - ${[...new Set(problems)].join('\n    - ')}`);
      await page.screenshot({ path: `${OUT}/${user}-${path.replace(/[^a-z0-9]+/gi, '_')}.png`, fullPage: true }).catch(() => {});
      console.log(`FAIL ${tag}`);
    } else console.log(`ok   ${tag}`);
  }
  await ctx.close();
}
await browser.close();
console.log(`\n${pages} pages opened, ${failures.length} with problems.`);
if (failures.length) { console.log('\n' + failures.join('\n')); process.exit(1); }
