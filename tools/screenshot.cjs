/**
 * Visual QA helper: logs into the admin and screenshots pages; reports console errors and failed requests.
 *
 *   node tools/screenshot.cjs --base http://127.0.0.1:8000 --out /tmp/shots /admin/ /admin/students "/admin/academics?tab=programs"
 *   node tools/screenshot.cjs --base http://127.0.0.1:8000 --mobile --public / /about /contact
 *
 * Options: --user admin --pass Admin@12345 --mobile (390px) --both (desktop+mobile) --public (no login) --full (full page)
 *          --dark (dark theme) --wait 1200 (ms after load)
 * Requires Playwright (preinstalled at /opt/node-tools/node_modules) - run with NODE_PATH=/opt/node-tools/node_modules
 */
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');

(async () => {

const args = process.argv.slice(2);
const opt = (name, def) => {
  const i = args.indexOf(`--${name}`);
  if (i === -1) return def;
  const v = args[i + 1];
  args.splice(i, 2);
  return v;
};
const flag = (name) => {
  const i = args.indexOf(`--${name}`);
  if (i === -1) return false;
  args.splice(i, 1);
  return true;
};
const base = opt('base', 'http://127.0.0.1:8000');
const out = opt('out', '/tmp/shots');
const user = opt('user', 'admin');
const pass = opt('pass', 'Admin@12345');
const wait = Number(opt('wait', '1200'));
const mobile = flag('mobile');
const both = flag('both');
const isPublic = flag('public');
const full = flag('full');
const dark = flag('dark');
const pages = args.length ? args : ['/admin/'];
fs.mkdirSync(out, { recursive: true });

const browser = await chromium.launch();
const sizes = both ? [['desktop', 1440, 900], ['mobile', 390, 844]] : mobile ? [['mobile', 390, 844]] : [['desktop', 1440, 900]];
let problems = 0;
for (const [label, width, height] of sizes) {
  const ctx = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 1, colorScheme: dark ? 'dark' : 'light' });
  if (dark) await ctx.addInitScript(() => localStorage.setItem('gimt.theme', 'dark'));
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => m.type() === 'error' && errors.push(`console: ${m.text()}`));
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  page.on('response', (r) => r.status() >= 400 && !r.url().includes('favicon') && errors.push(`HTTP ${r.status()} ${r.request().method()} ${r.url()}`));
  if (!isPublic) {
    await page.goto(`${base}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('#identifier', user);
    await page.fill('#password', pass);
    await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 }).catch(() => null), page.click('button[type=submit]')]);
    await page.waitForTimeout(800);
  }
  for (const p of pages) {
    errors.length = 0;
    const url = p.startsWith('http') ? p : `${base}${p}`;
    const res = await page.goto(url, { waitUntil: 'networkidle' }).catch((e) => ({ status: () => 0, err: e }));
    await page.waitForTimeout(wait);
    const name = `${label}-${p.replace(/^\//, '').replace(/[^a-z0-9]+/gi, '_') || 'home'}.png`;
    await page.screenshot({ path: path.join(out, name), fullPage: full });
    const status = res?.status?.() ?? 0;
    const bodyText = await page.evaluate(() => document.body.innerText.slice(0, 20000));
    const phpErrors = (bodyText.match(/(Fatal error|Warning:|Notice:|Deprecated:|Parse error|Uncaught)[^\n]{0,200}/g) || []);
    const bad = status >= 400 || errors.length || phpErrors.length;
    if (bad) problems++;
    console.log(`${bad ? '✗' : '✓'} [${label}] ${p} -> ${status} ${path.join(out, name)}`);
    [...errors, ...phpErrors].slice(0, 15).forEach((e) => console.log(`    ${e}`));
  }
  await ctx.close();
}
await browser.close();
process.exit(problems ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
