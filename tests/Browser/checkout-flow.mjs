// End-to-end guest checkout in a real browser (phone viewport): add to cart → checkout →
// pickup station → place order (double-clicked on purpose) → confirmation → private tracking link.
// Usage: node checkout-flow.mjs <outDir> [productSlug]
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.WAIMART_URL || 'http://localhost/ecommerce';
const EDGE = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find((p) => fs.existsSync(p));
const out = process.argv[2] || 'screenshots';
const slug = process.argv[3] || 'pwani-1-7l-electric-kettle';
fs.mkdirSync(out, { recursive: true });

const browser = await puppeteer.launch({ executablePath: EDGE, headless: true, args: ['--no-first-run', '--disable-gpu'] });
const page = await browser.newPage();
await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
const step = (s) => console.log('•', s);
if (process.env.DEBUG) {
    page.on('response', (r) => { if (r.request().method() === 'POST') { console.log('  POST', r.status(), r.url(), r.headers()['location'] || ''); } });
}
// Tap like a person on a phone: bring the element to the middle of the screen first (clear of the fixed bottom bars).
const tap = async (sel) => {
    await page.$eval(sel, (el) => el.scrollIntoView({ block: 'center' }));
    await page.click(sel);
};

await page.goto(`${BASE}/product/${slug}`, { waitUntil: 'networkidle0' });
// Answer the privacy banner first, as a visitor would (it is fixed at the bottom of the screen until answered).
const noThanks = await page.$('[data-privacy-banner] button[value="0"]');
if (noThanks) {
    await noThanks.click();
    await page.waitForSelector('[data-privacy-banner]', { hidden: true });
    step('privacy banner answered (No thanks)');
}
await tap('[data-add-btn]');
await page.waitForSelector('.toast');
step('added to cart: ' + await page.$eval('.toast-body', (el) => el.textContent));

await page.goto(`${BASE}/checkout`, { waitUntil: 'networkidle0' });
await page.type('#customer_name', 'Browser Test');
await page.type('#customer_phone', '0711222333');
await page.type('#customer_email', 'browser-test@example.com');
await tap('label[for="fmPickup"]');
await tap('[data-station]:not([hidden]) input[name="pickup_point_id"]');   // the list is pre-filtered to the customer's county
await new Promise((r) => setTimeout(r, 600));
step('quote shows delivery fee: ' + await page.$eval('[data-fee]', (el) => el.textContent));
await tap('#pm_cod');
await page.screenshot({ path: path.join(out, 'checkout-filled.png'), fullPage: true });

// Double-click "Place order".
await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0' }),
    page.$eval('[data-place-order]', (el) => el.scrollIntoView({ block: 'center' })).then(() => page.click('[data-place-order]')).then(() => page.click('[data-place-order]').catch(() => {})),
]);
const url = page.url();
step('landed on: ' + url.replace(BASE, ''));
if (process.env.DEBUG) { console.log('  messages:', await page.$$eval('.alert, .invalid-feedback', (a) => a.map((x) => x.textContent.trim().replace(/\s+/g, ' ')).filter(Boolean))); }
await page.screenshot({ path: path.join(out, 'confirmation.png'), fullPage: true });
const number = (url.match(/(?:WM|BM)-[A-Z0-9]{8}/) || [])[0];
const link = await page.$eval('[data-select-on-focus]', (el) => el.value).catch(() => null);
step('order ' + number + (link ? ', private link shown' : ', NO LINK'));

// Open the private link in a fresh browser context (like another device).
const ctx = await browser.createBrowserContext();
const p2 = await ctx.newPage();
await p2.setViewport({ width: 390, height: 844, isMobile: true, deviceScaleFactor: 2 });
await p2.goto(link.replace('http://localhost/ecommerce', BASE), { waitUntil: 'networkidle0' });
step('tracking page url: ' + p2.url().replace(BASE, '') + ' (token stripped: ' + !p2.url().includes('?t=') + ')');
await p2.screenshot({ path: path.join(out, 'tracking.png'), fullPage: true });

await browser.close();
console.log(JSON.stringify({ number, errors }));
if (errors.length || !number) { process.exit(1); }
