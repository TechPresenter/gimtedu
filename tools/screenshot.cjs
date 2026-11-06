/**
 * Visual QA helper: logs into the admin and screenshots pages; reports console errors and failed requests.
 *
 *   node tools/screenshot.cjs --base http://127.0.0.1:8000 --out /tmp/shots /admin/ /admin/students "/admin/academics?tab=programs"
 *   node tools/screenshot.cjs --base http://127.0.0.1:8000 --mobile --public / /about /contact
 *   node tools/screenshot.cjs --base http://127.0.0.1:5175 --portal student --user GIMT25BBA0002 --pass Demo@12345 /portal/student /portal/student/fees
 *
 * Options: --user admin --pass Admin@12345 --mobile (390px) --both (desktop+mobile) --public (no login) --full (full page)
 *          --dark (dark theme) --wait 1200 (ms after load) --motion (keep normal motion; default emulates
 *          prefers-reduced-motion so scroll-reveal sections, counters and charts show their final state)
 *          --portal student|faculty (sign in at /portal/{area}/login instead of the admin login; --user / --pass are the
 *          portal credentials, e.g. a Student ID and Demo@12345) --height 900 (desktop viewport height)
 * Requires Playwright - run with NODE_PATH=tools/.cache/pw/node_modules (see CLAUDE.md).
 *
 * Admin runs log in once per viewport (waiting for the SPA to hydrate, retrying up to 3 times) and verify the session
 * with GET /api/auth/session. A page that lands on /login is re-logged in and retried once; if it still shows the login
 * screen it is reported as a failure (✗), never as a successful screenshot.
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
const motion = flag('motion');
const portal = opt('portal', '');
const deskHeight = Number(opt('height', '900'));
if (portal && !['student', 'faculty'].includes(portal)) {
  console.error('--portal must be student or faculty');
  process.exit(2);
}
const pages = args.length ? args : ['/admin/'];
fs.mkdirSync(out, { recursive: true });

// PHP error output as rendered text: "Warning: Undefined variable $x in F:\gimt\x.php on line 12" (a page that merely
// contains the word "Notice:" — e.g. a notice board — is not an error)
const PHP_ERROR = /(Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error): .{1,300}? in \S+\.php(?::\d+| on line \d+)|Uncaught (?:\w+\\)*\w*(?:Exception|Error)\b[^\n]{0,200}/g;

const onLoginPage = (page) => /\/login\/?$/.test(new URL(page.url()).pathname);
const sessionPath = portal ? `/api/portal/${portal}/session` : '/api/auth/session';
const loginPath = portal ? `/portal/${portal}/login` : '/admin/login';
const idField = portal ? `#${portal}-identifier` : '#identifier';
const pwField = portal ? `#${portal}-password` : '#password';

async function sessionOk(page) {
  try {
    const r = await page.evaluate(async ([b, sp]) => {
      const res = await fetch(`${b}${sp}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      return res.ok ? (await res.json()).data : null;
    }, [base, sessionPath]);
    return !!(r && r.authenticated);
  } catch {
    return false;
  }
}

async function login(page) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      await page.goto(`${base}${loginPath}`, { waitUntil: 'networkidle', timeout: 45000 });
      if (!onLoginPage(page) && (await sessionOk(page))) return true; // already signed in
      // the SPA must hydrate before typing, otherwise React resets the fields and the login silently fails
      await page.waitForSelector(idField, { state: 'visible', timeout: 30000 });
      await page.waitForTimeout(1200 + attempt * 600);
      await page.fill(idField, user);
      await page.fill(pwField, pass);
      await Promise.all([
        page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 30000 }).catch(() => null),
        page.click('button[type=submit]'),
      ]);
      await page.waitForTimeout(800);
      if (!onLoginPage(page) && (await sessionOk(page))) return true;
      console.log(`    login attempt ${attempt} did not stick (still on ${new URL(page.url()).pathname}), retrying…`);
    } catch (e) {
      console.log(`    login attempt ${attempt} failed: ${e.message.split('\n')[0]}`);
    }
  }
  return false;
}

const browser = await chromium.launch();
const sizes = both ? [['desktop', 1440, deskHeight], ['mobile', 390, 844]] : mobile ? [['mobile', 390, 844]] : [['desktop', 1440, deskHeight]];
let problems = 0;
for (const [label, width, height] of sizes) {
  const ctx = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 1, colorScheme: dark ? 'dark' : 'light', reducedMotion: motion ? 'no-preference' : 'reduce' });
  if (dark) await ctx.addInitScript(() => { try { localStorage.setItem('gimt.theme', 'dark'); localStorage.setItem('gimt-theme', 'dark'); } catch {} });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => m.type() === 'error' && errors.push(`console: ${m.text()}`));
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  page.on('response', (r) => r.status() >= 400 && !r.url().includes('favicon') && errors.push(`HTTP ${r.status()} ${r.request().method()} ${r.url()}`));
  if (!isPublic && !(await login(page))) {
    problems++;
    console.log(`✗ [${label}] could not sign in as ${user} after 3 attempts - admin pages skipped`);
    await ctx.close();
    continue;
  }
  for (const p of pages) {
    const url = p.startsWith('http') ? p : `${base}${p}`;
    let res;
    for (let attempt = 1; attempt <= 2; attempt++) {
      errors.length = 0;
      res = await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 }).catch((e) => ({ status: () => 0, err: e }));
      await page.waitForTimeout(wait);
      // the admin session was dropped (another agent / timeout): sign in again and retry once
      if (!isPublic && onLoginPage(page) && !/\/login/.test(p) && attempt === 1) {
        console.log(`    [${label}] ${p} landed on the login page, signing in again…`);
        if (!(await login(page))) break;
        continue;
      }
      break;
    }
    const name = `${label}-${p.replace(/^\//, '').replace(/[^a-z0-9]+/gi, '_') || 'home'}.png`;
    await page.screenshot({ path: path.join(out, name), fullPage: full });
    const status = res?.status?.() ?? 0;
    const bodyText = await page.evaluate(() => document.body.innerText.slice(0, 20000)).catch(() => '');
    const phpErrors = bodyText.match(PHP_ERROR) || [];
    const loginWall = !isPublic && onLoginPage(page) && !/\/login/.test(p);
    const bad = status >= 400 || errors.length || phpErrors.length || loginWall || (res && res.err);
    if (bad) problems++;
    console.log(`${bad ? '✗' : '✓'} [${label}] ${p} -> ${status}${loginWall ? ' (LOGIN PAGE - session lost)' : ''} ${path.join(out, name)}`);
    [...(res && res.err ? [`navigation: ${res.err.message.split('\n')[0]}`] : []), ...errors, ...phpErrors].slice(0, 15).forEach((e) => console.log(`    ${e}`));
  }
  await ctx.close();
}
await browser.close();
process.exit(problems ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
