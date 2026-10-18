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

await page.goto(`${BASE}/product/${slug}`, { waitUntil: 'networkidle0' });
await page.click('[data-add-btn]');
await page.waitForSelector('.toast');
step('added to cart: ' + await page.$eval('.toast-body', (el) => el.textContent));

await page.goto(`${BASE}/checkout`, { waitUntil: 'networkidle0' });
await page.type('#customer_name', 'Browser Test');
await page.type('#customer_phone', '0711222333');
await page.click('label[for="fmPickup"]');
await page.click('input[name="pickup_point_id"]');
await new Promise((r) => setTimeout(r, 600));
step('quote shows delivery fee: ' + await page.$eval('[data-fee]', (el) => el.textContent));
await page.click('#pm_cod');
await page.screenshot({ path: path.join(out, 'checkout-filled.png'), fullPage: true });

// Double-click "Place order".
await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0' }),
    page.click('[data-place-order]').then(() => page.click('[data-place-order]').catch(() => {})),
]);
const url = page.url();
step('landed on: ' + url.replace(BASE, ''));
await page.screenshot({ path: path.join(out, 'confirmation.png'), fullPage: true });
const number = (url.match(/WM-[A-Z0-9]{8}/) || [])[0];
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
