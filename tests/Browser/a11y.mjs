// Accessibility audit (WCAG 2.1 A/AA rules via axe-core) for key pages.
// Fails on any "serious" or "critical" violation. Optional staff login: WAIMART_LOGIN_EMAIL / WAIMART_LOGIN_PASSWORD.
// Usage: node a11y.mjs [path ...]
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const axeSource = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const BASE = process.env.WAIMART_URL || 'http://localhost/ecommerce';
const EDGE = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find((p) => fs.existsSync(p));
const paths = process.argv.slice(2).length ? process.argv.slice(2) : [
    '/', '/category/phones-tablets', '/search?q=tv', '/product/nyota-x12-smartphone-6-6-dual-sim', '/cart', '/login', '/register',
    '/forgot-password', '/track-order', '/help', '/how-to-pay', '/pickup-stations', '/categories', '/flash-sales',
];

const browser = await puppeteer.launch({ executablePath: EDGE, headless: true, args: ['--disable-gpu'] });
const page = await browser.newPage();
await page.setViewport({ width: 1280, height: 900 });
if (process.env.WAIMART_LOGIN_EMAIL) {
    await page.goto(BASE + (process.env.WAIMART_LOGIN_PATH || '/admin/login'), { waitUntil: 'networkidle0' });
    await page.type('#email', process.env.WAIMART_LOGIN_EMAIL);
    await page.type('#password', process.env.WAIMART_LOGIN_PASSWORD || '');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.$eval('#password', (el) => el.form.requestSubmit())]);
}
let blocking = 0;
for (const p of paths) {
    await page.goto(BASE + p, { waitUntil: 'networkidle0' });
    await page.evaluate(axeSource);
    const res = await page.evaluate(async () => window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } }));
    const bad = res.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    const minor = res.violations.length - bad.length;
    blocking += bad.length;
    console.log(`${bad.length ? 'FAIL' : 'PASS'} ${p}  (${res.passes.length} rules passed${minor ? `, ${minor} minor/moderate` : ''})`);
    for (const v of res.violations) {
        console.log(`   [${v.impact}] ${v.id}: ${v.help} — e.g. ${v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')}`);
    }
}
await browser.close();
if (blocking) {
    console.error(`\n${blocking} serious/critical accessibility violation(s).`);
    process.exit(1);
}
console.log('\nNo serious or critical accessibility violations.');
