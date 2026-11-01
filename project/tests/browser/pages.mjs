// Browser test for home.html and booking.html (Live map). Same limitations as geolocation.mjs: Leaflet is a stub, no tiles, no real GPS.
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import fs from 'node:fs'; import os from 'node:os'; import path from 'node:path';
import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)); const root = path.resolve(here, '../..');
const { chromium } = createRequire(import.meta.url)(process.env.PLAYWRIGHT_CORE || 'playwright-core');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pk_b2_')); const db = path.join(tmp, 'b.sqlite');
execFileSync('php', ['-r', `$p=new PDO('sqlite:${db}');$p->exec(file_get_contents('${root}/tests/sqlite/schema.sql'));`]);
const port = 40000 + Math.floor(Math.random() * 9000);
const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${root}/tests/sqlite/prepend.php`], { env: { ...process.env, PK_TEST_DB: db, PK_FAKE_STATE: path.join(tmp, 'g.json'), APP_KEY: 'x', APP_TIMEZONE: 'Asia/Kolkata', DB_HOST: '127.0.0.1', DB_USER: 'x', DB_PASS: 'x', DB_NAME: 'none' }, stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 1200)); const base = `http://127.0.0.1:${port}`;
let pass = 0, fail = 0; const ok = (c, l) => { c ? pass++ : fail++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const stub = fs.readFileSync(path.join(here, 'leaflet-stub.js'), 'utf8');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
async function open(url, ctxOpts = {}, vw = { width: 1280, height: 900 }) {
  const ctx = await browser.newContext({ viewport: vw, ...ctxOpts }); const page = await ctx.newPage(); const errors = [], bad = [];
  page.on('pageerror', (e) => errors.push(String(e))); page.on('requestfailed', () => {});
  page.on('response', (r) => { if (r.url().startsWith(base) && r.status() >= 400) bad.push(r.status() + ' ' + r.url()); });
  await page.route(/unpkg\.com\/leaflet.*\.js/, (r) => r.fulfill({ contentType: 'application/javascript', body: stub }));
  await page.route(/(unpkg\.com\/leaflet.*\.css|fonts\.g)/, (r) => r.fulfill({ contentType: 'text/css', body: '' }));
  await page.route(/tile\.openstreetmap\.org/, (r) => r.abort());
  await page.goto(base + url); await page.waitForTimeout(500); return { ctx, page, errors, bad };
}
console.log('home.html');
{
  const { ctx, page, errors, bad } = await open('/home.html');
  ok(errors.length === 0 && bad.length === 0, 'loads with no JS errors and no failed local requests ' + bad.join());
  const hrefs = await page.locator('a').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
  ok(!hrefs.includes('booking.html') || (await page.locator('a[href="booking.html"]').first().innerText()).includes('Live map'), 'every link to booking.html is labelled Live map');
  ok((await page.locator('a').allInnerTexts()).every((t) => !/^\s*booking\s*$/i.test(t)), 'no "Booking" button remains on the home page');
  ok(hrefs.includes('find_parking.php') && hrefs.includes('register.php') && hrefs.includes('index.php?login=true') && hrefs.includes('login1.php'), 'find parking, register, user login and admin login are reachable');
  await page.selectOption('#city', 'Ajara'); await page.fill('#q', 'bus');
  await Promise.all([page.waitForURL(/find_parking\.php/), page.click('.finder button')]);
  ok(/city=Ajara/.test(page.url()) && /q=bus/.test(page.url()), 'hero search goes to Find parking with the chosen city and text');
  await ctx.close();
}
console.log('\nhome.html on a phone');
{
  const { ctx, page } = await open('/home.html', {}, { width: 375, height: 800 });
  ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'no horizontal scrolling at 375px');
  await page.screenshot({ path: path.join(tmp, 'home-mobile.png'), fullPage: true });
  await ctx.close();
}
{ const { ctx, page } = await open('/home.html'); await page.screenshot({ path: process.env.SHOT_DIR ? path.join(process.env.SHOT_DIR, 'home.png') : path.join(tmp, 'home.png'), fullPage: true }); await ctx.close(); }

console.log('\nbooking.html (Live map)');
{
  const { ctx, page, errors, bad } = await open('/booking.html', { geolocation: { latitude: 16.705, longitude: 74.2433, accuracy: 25 }, permissions: ['geolocation'] });
  ok(errors.length === 0 && bad.length === 0, 'loads with no JS errors / failed local requests ' + bad.join());
  const html = await page.content();
  ok(!/id="bookingForm"|action="book\.php"|Confirm Booking|parkingHours|totalPrice|costPerHour/.test(html), 'old booking form, hours and fake price are gone');
  ok(await page.locator('form').count() === 0, 'the page has no form at all');
  ok(/Showing your location/.test(await page.locator('#status').innerText()), 'location shown after permission');
  ok((await page.locator('#nearby li').count()) === 8, 'nearby list shows the 8 closest places');
  const first = page.locator('#nearby li').first();
  ok(/about \d+ m away|about [\d.]+ km away/.test(await first.innerText()), 'nearby entries show an approximate distance');
  const h = await first.locator('a').first().getAttribute('href');
  ok(h.includes('origin=16.705%2C74.2433') && h.includes('destination=') && h.endsWith('travelmode=driving'), 'directions use the real position as origin');
  await page.click('.chip[data-city="Gadhinglaj"]');
  const cities = await page.locator('#nearby li .meta').allInnerTexts();
  ok(cities.length > 0 && cities.every((c) => c.startsWith('Gadhinglaj')), 'choosing Gadhinglaj lists only Gadhinglaj places');
  ok(await page.locator('.chip[data-city="Gadhinglaj"]').getAttribute('aria-pressed') === 'true', 'selected city chip is marked');
  const j = await page.evaluate(() => fetch('parking_points.php').then((r) => r.json()));
  ok(j.free.length > 100 && Array.isArray(j.bookable) && !JSON.stringify(j).match(/password|owner|email/i), 'parking_points.php returns public data only');
  await page.screenshot({ path: process.env.SHOT_DIR ? path.join(process.env.SHOT_DIR, 'map.png') : path.join(tmp, 'map.png') });
  await ctx.close();
}
{
  const ctx = await browser.newContext(); await ctx.addInitScript(() => { navigator.geolocation.getCurrentPosition = (o, e) => e({ code: 1 }); });
  const page = await ctx.newPage(); await page.route(/unpkg\.com\/leaflet.*\.js/, (r) => r.fulfill({ contentType: 'application/javascript', body: stub }));
  await page.route(/(unpkg|fonts)/, (r) => r.request().url().endsWith('.js') ? r.fallback() : r.fulfill({ contentType: 'text/css', body: '' }));
  await page.goto(base + '/booking.html'); await page.waitForTimeout(500);
  ok(/denied/i.test(await page.locator('#status').innerText()), 'denied permission: helpful message');
  ok(await page.locator('#nearby li').count() === 0 && /Allow your location or choose a city/.test(await page.locator('#near-note').innerText()), 'no fake location: list waits for a city or permission');
  await page.click('.chip[data-city="Ajara"]');
  ok(await page.locator('#nearby li').count() === 8 && /Ajara centre/.test(await page.locator('#near-note').innerText()), 'picking a city lists places measured from that city centre, and says so');
  await ctx.close();
}

console.log('\nregister, user login and admin login share the home page design');
{
  const shot = (n) => process.env.SHOT_DIR ? path.join(process.env.SHOT_DIR, n) : path.join(tmp, n);
  const pagesList = [['/register.php', 'register-user'], ['/register.php?type=admin', 'register-admin'], ['/index.php?login=true', 'login-user'], ['/login1.php', 'login-admin'], ['/index.php', 'index-register']];
  const home = await open('/home.html'); const homeFont = await home.page.evaluate(() => getComputedStyle(document.body).fontFamily); const brand = await home.page.locator('.brand').innerHTML(); await home.ctx.close();
  for (const [url, name] of pagesList) {
    const { ctx, page, errors, bad } = await open(url);
    ok(errors.length === 0 && bad.length === 0, `${url}: loads without errors ${bad.join()}`);
    ok((await page.locator('.brand').innerHTML()) === brand && (await page.evaluate(() => getComputedStyle(document.body).fontFamily)) === homeFont, `${url}: same header and fonts as the home page`);
    ok((await page.evaluate(() => getComputedStyle(document.querySelector('.site-head')).backgroundColor)) === 'rgb(29, 35, 39)', `${url}: same dark header colour as home`);
    await page.screenshot({ path: shot(name + '.png'), fullPage: true });
    await ctx.close();
    const m = await open(url, {}, { width: 375, height: 800 });
    ok(await m.page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), `${url}: no sideways scrolling at 375px`);
    await m.ctx.close();
  }
  // real round trip through the forms: register a driver, log in, land on My dashboard
  const { ctx, page } = await open('/register.php');
  await page.fill('#first_name', 'Test'); await page.fill('#last_name', 'Driver'); await page.fill('#email', 'drv@example.com'); await page.fill('#car_number', 'MH09AB1234'); await page.fill('#password', 'Passw0rd!');
  await page.click('input[type=submit]'); await page.waitForTimeout(400);
  ok(/Registered successfully/.test(await page.locator('.msg.success').innerText()), 'registration through the new form works');
  await page.goto(base + '/index.php?login=true'); await page.fill('#l_email', 'drv@example.com'); await page.fill('#l_password', 'Passw0rd!');
  await Promise.all([page.waitForURL(/my_dashboard\.php/), page.click('input[name=login]')]);
  ok(/my_dashboard\.php/.test(page.url()), 'user login through the new form lands on My dashboard');
  await ctx.close();
  const a = await open('/login1.php'); await a.page.fill('#username', 'nobody'); await a.page.fill('#password', 'wrong'); await a.page.click('button[name=login]'); await a.page.waitForTimeout(300);
  ok(/Invalid credentials/.test(await a.page.locator('.msg.error').innerText()), 'wrong admin login shows the styled error');
  await a.ctx.close();
}
await browser.close(); server.kill(); 
if (!process.env.SHOT_DIR) fs.rmSync(tmp, { recursive: true, force: true });
console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
