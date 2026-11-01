// Browser test for the Find Parking map in real headless Chromium.
//   node tests/browser/geolocation.mjs      (needs playwright-core + a Chromium; set PLAYWRIGHT_CORE / CHROMIUM if needed)
// LIMITATIONS (be honest when reporting): Leaflet is replaced by a tiny stub (tests/browser/leaflet-stub.js) because the
// CDN is not reachable from the sandbox, OpenStreetMap tiles and Google Maps are never contacted, and geolocation is
// the browser's emulated geolocation (granted / denied / blocked), not a real GPS fix.
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');
const exe = process.env.CHROMIUM || '/opt/pw-browsers/chromium';

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pk_browser_'));
const db = path.join(tmp, 'b.sqlite');
execFileSync('php', ['-r', `$p=new PDO('sqlite:${db}');$p->exec(file_get_contents('${root}/tests/sqlite/schema.sql'));`]);
const port = 40000 + Math.floor(Math.random() * 9000);
const env = { ...process.env, PK_TEST_DB: db, PK_FAKE_STATE: path.join(tmp, 'g.json'), APP_KEY: 'browser-test', APP_TIMEZONE: 'Asia/Kolkata', DB_HOST: '127.0.0.1', DB_USER: 'x', DB_PASS: 'x', DB_NAME: 'none' };
const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${root}/tests/sqlite/prepend.php`], { env, stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 1200));
const base = `http://127.0.0.1:${port}`;

let pass = 0, fail = 0;
const ok = (c, label) => { if (c) { pass++; console.log('  ok   ' + label); } else { fail++; console.log('  FAIL ' + label); } };
const stub = fs.readFileSync(path.join(here, 'leaflet-stub.js'), 'utf8');

const browser = await chromium.launch({ executablePath: exe, args: ['--no-sandbox'] });
async function open(ctxOpts, url, withLeaflet = true) {
  const ctx = await browser.newContext(ctxOpts);
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.route(/unpkg\.com\/leaflet.*\.js/, (r) => (withLeaflet ? r.fulfill({ contentType: 'application/javascript', body: stub }) : r.abort()));
  await page.route(/unpkg\.com\/leaflet.*\.css/, (r) => r.fulfill({ contentType: 'text/css', body: '' }));
  await page.route(/tile\.openstreetmap\.org/, (r) => r.abort());
  await page.goto(base + url);
  await page.waitForTimeout(400);
  return { ctx, page, errors };
}
const status = (p) => p.locator('#fp-status').innerText();

console.log('\ngeolocation granted (emulated position in Kolhapur)');
{
  const { ctx, page, errors } = await open({ geolocation: { latitude: 16.7050, longitude: 74.2433, accuracy: 30 }, permissions: ['geolocation'] }, '/find_parking.php?kind=free&city=Kolhapur');
  ok(errors.length === 0, 'no JavaScript errors on load');
  const st = await page.evaluate(() => window.__fp);
  ok(st && st.userMarkers === 1, 'user marker is drawn only after the position arrives');
  ok(/Showing your location/.test(await status(page)), 'status message says the location is shown');
  const d = await page.locator('.card.place [data-dist]').first().innerText();
  ok(/^About (\d+ m|[\d.]+ km) away \(straight line\)$/.test(d), 'distance shown as approximate straight-line: "' + d + '"');
  const hrefs = await page.locator('.card.place a[data-dir]').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
  ok(hrefs.length > 10 && hrefs.every((h) => h.includes('origin=16.705%2C74.2433') && h.includes('&destination=') && h.endsWith('travelmode=driving')), 'every directions link now has the user\'s coordinates as origin');
  const first = page.locator('.card.place').first();
  ok((await first.locator('a[data-dir]').getAttribute('target')) === '_blank' && /noopener/.test(await first.locator('a[data-dir]').getAttribute('rel')), 'directions open in a new tab (noopener)');
  const dists = await page.locator('.card.place [data-dist]').evaluateAll((a) => a.map((x) => x.textContent));
  const num = (s) => { const m = s.match(/About ([\d.]+) (m|km)/); return m ? parseFloat(m[1]) * (m[2] === 'km' ? 1000 : 1) : 1e12; };
  ok(dists.map(num).every((v, i, arr) => i === 0 || arr[i - 1] <= v + 10), 'cards are sorted nearest first');
  // card <-> marker sync
  await first.click({ position: { x: 20, y: 20 } });
  ok(await first.evaluate((e) => e.classList.contains('fp-sel')), 'clicking a card selects it');
  ok((await page.evaluate(() => window.__fp.lastPopup)) === (await first.getAttribute('data-key')), '...and opens that marker (map and card in sync)');
  const second = page.locator('.card.place').nth(2);
  const key2 = await second.getAttribute('data-key');
  await page.evaluate((k) => window.__fp.clickMarker(k), key2);
  ok(await second.evaluate((e) => e.classList.contains('fp-sel')) && !(await first.evaluate((e) => e.classList.contains('fp-sel'))), 'clicking a marker selects its card only');
  await ctx.close();
}

console.log('\ngeolocation denied');
{
  // Headless Chromium leaves an un-granted prompt pending, so the "user clicked Block" answer (PERMISSION_DENIED = 1) is injected.
  const ctx = await browser.newContext();
  await ctx.addInitScript(() => { navigator.geolocation.getCurrentPosition = (ok, err) => err({ code: 1 }); });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.route(/unpkg\.com\/leaflet.*\.js/, (r) => r.fulfill({ contentType: 'application/javascript', body: stub }));
  await page.route(/unpkg\.com\/leaflet.*\.css/, (r) => r.fulfill({ contentType: 'text/css', body: '' }));
  await page.goto(base + '/find_parking.php?city=Ajara');
  await page.waitForTimeout(300);
  const s = await status(page);
  ok(errors.length === 0, 'no JavaScript errors');
  ok(/denied/i.test(s) && /city/i.test(s), 'helpful message that points to the city selector: "' + s + '"');
  ok((await page.evaluate(() => window.__fp.userMarkers)) === 0, 'no user marker is invented');
  const hrefs = await page.locator('.card.place a[data-dir]').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
  ok(hrefs.length > 5 && hrefs.every((h) => !h.includes('origin=')), 'directions have a destination only');
  const dist = await page.locator('.card.place [data-dist]').evaluateAll((a) => a.map((x) => x.textContent.trim()));
  ok(dist.every((t) => t === ''), 'no distances are shown');
  await ctx.close();
}

console.log('\ngeolocation unsupported / failing');
{
  const ctx = await browser.newContext();
  await ctx.addInitScript(() => { Object.defineProperty(navigator, 'geolocation', { value: undefined, configurable: true }); });
  const page = await ctx.newPage();
  await page.route(/unpkg\.com\/leaflet.*\.js/, (r) => r.fulfill({ contentType: 'application/javascript', body: stub }));
  await page.route(/unpkg\.com\/leaflet.*\.css/, (r) => r.fulfill({ contentType: 'text/css', body: '' }));
  await page.goto(base + '/find_parking.php');
  await page.waitForTimeout(300);
  ok(/does not support location/.test(await status(page)), 'unsupported browser: clear message');
  await ctx.close();
  for (const [code, re] of [[2, /not available/], [3, /timed out/]]) {
    const c2 = await browser.newContext();
    await c2.addInitScript((cd) => { navigator.geolocation.getCurrentPosition = (ok, err) => err({ code: cd }); }, code);
    const p2 = await c2.newPage();
    await p2.route(/unpkg\.com\/leaflet.*\.js/, (r) => r.fulfill({ contentType: 'application/javascript', body: stub }));
    await p2.route(/unpkg\.com\/leaflet.*\.css/, (r) => r.fulfill({ contentType: 'text/css', body: '' }));
    await p2.goto(base + '/find_parking.php');
    await p2.waitForTimeout(300);
    ok(re.test(await status(p2)), `error code ${code}: "${await status(p2)}"`);
    await c2.close();
  }
}

console.log('\nno Leaflet (CDN blocked) and manual city choice');
{
  const { ctx, page, errors } = await open({}, '/find_parking.php', false);
  ok(/could not be loaded/.test(await page.locator('#fp-map').innerText()), 'a clear message replaces the map');
  ok((await page.locator('.card.place').count()) > 100, 'the list still works');
  await page.selectOption('#city', 'Gadhinglaj');
  await page.waitForURL(/city=Gadhinglaj/);
  const names = await page.locator('.card.place h3').allInnerTexts();
  ok(names.length > 10 && names.every((n) => /Free Parking/.test(n)), 'choosing a city reloads with only that city\'s spots');
  ok(errors.length === 0, 'no uncaught JavaScript errors without Leaflet');
  await ctx.close();
}

await browser.close();
server.kill();
fs.rmSync(tmp, { recursive: true, force: true });
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
