// Responsive check: renders pages at phone / tablet / desktop widths with device emulation,
// saves screenshots and fails if any page overflows horizontally or logs console errors.
//
// Usage: node responsive.mjs [outDir] [path ...]
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.WAIMART_URL || 'http://localhost/ecommerce';
const EDGE = [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
].find((p) => fs.existsSync(p));

const outDir = process.argv[2] || path.resolve('screenshots');
const paths = process.argv.slice(3).length ? process.argv.slice(3) : ['/', '/categories', '/help', '/how-to-pay', '/delivery-pickup', '/nope-404'];
const devices = [
    { name: 'phone', width: 375, height: 812, mobile: true, scale: 2 },
    { name: 'tablet', width: 768, height: 1024, mobile: true, scale: 1 },
    { name: 'desktop', width: 1366, height: 900, mobile: false, scale: 1 },
];

fs.mkdirSync(outDir, { recursive: true });
const failures = [];

for (const d of devices) {
    // Fresh browser per device keeps long runs stable.
    const browser = await puppeteer.launch({ executablePath: EDGE, headless: true, args: ['--no-first-run', '--disable-gpu'] });
    const page = await browser.newPage();
    await page.setViewport({ width: d.width, height: d.height, isMobile: d.mobile, hasTouch: d.mobile, deviceScaleFactor: d.scale });
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));

    // Optional: put a product in the cart first (WAIMART_CART_PRODUCT=slug) to render cart/checkout.
    if (process.env.WAIMART_CART_PRODUCT) {
        await page.goto(BASE + '/product/' + process.env.WAIMART_CART_PRODUCT, { waitUntil: 'networkidle0' });
        await page.click('[data-add-btn]');
        await page.waitForSelector('.toast', { timeout: 5000 });
    }

    // Optional staff login for admin pages (WAIMART_LOGIN_EMAIL / WAIMART_LOGIN_PASSWORD).
    if (process.env.WAIMART_LOGIN_EMAIL) {
        await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle0' });
        await page.type('#email', process.env.WAIMART_LOGIN_EMAIL);
        await page.type('#password', process.env.WAIMART_LOGIN_PASSWORD || '');
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type="submit"]')]);
    }

    for (const p of paths) {
        errors.length = 0;
        const res = await page.goto(BASE + p, { waitUntil: 'networkidle0' });
        const expected404 = p.includes('404');
        if (expected404) {
            // The document's own 404 is expected; any other failed resource is still an error.
            const docErrors = errors.filter((e) => e.includes('404 (Not Found)'));
            if (res.status() === 404 && docErrors.length === 1) errors.splice(errors.indexOf(docErrors[0]), 1);
        }
        const metrics = await page.evaluate(() => {
            const vw = document.documentElement.clientWidth;
            const offenders = [...document.querySelectorAll('body *')]
                .filter((el) => {
                    const r = el.getBoundingClientRect();
                    // ignore content inside intentional horizontal scrollers and off-canvas panels
                    return r.right > vw + 1 && !el.closest('.wm-rail, .wm-quick-cats, .offcanvas, .carousel-inner, .visually-hidden, .skip-link');
                })
                .slice(0, 5)
                .map((el) => el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : ''));
            return { scrollWidth: document.documentElement.scrollWidth, vw, offenders };
        });
        const name = `${d.name}${p.replace(/[^a-z0-9]+/gi, '_')}.png`;
        await page.screenshot({ path: path.join(outDir, name), fullPage: true });
        const ok = metrics.scrollWidth <= metrics.vw && errors.length === 0;
        console.log(`${ok ? 'PASS' : 'FAIL'} ${d.name.padEnd(7)} ${String(res.status()).padEnd(3)} ${p}` +
            (metrics.scrollWidth > metrics.vw ? `  overflow ${metrics.scrollWidth}>${metrics.vw} ${metrics.offenders.join(', ')}` : '') +
            (errors.length ? `  console: ${errors.join(' | ')}` : ''));
        if (!ok) failures.push(`${d.name} ${p}`);
    }
    await browser.close();
}
if (failures.length) {
    console.error(`\n${failures.length} responsive check(s) failed.`);
    process.exit(1);
}
console.log('\nAll responsive checks passed.');
