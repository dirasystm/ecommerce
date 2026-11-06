// Page width check: on wide screens the shop uses the screen like Jumia (side margins about 4-5% of the width),
// with no sideways scrolling at any width. Screenshots go to screenshots/width-<page>-<w>.png.
// Usage: WAIMART_URL=http://localhost/ecommerce WAIMART_PRODUCT=<slug> node page-width.mjs [label]
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';

const B = process.env.WAIMART_URL || 'http://localhost/ecommerce';
const PRODUCT = process.env.WAIMART_PRODUCT || '';
const label = process.argv[2] || 'now';
const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe'].find((p) => fs.existsSync(p));
const browser = await puppeteer.launch({ executablePath: CHROME, headless: true });
const page = await browser.newPage();
const pages = { home: '/', product: PRODUCT ? '/product/' + PRODUCT : null, search: '/search?q=a', cart: '/cart', login: '/login', help: '/help' };
const widths = [1920, 1440, 1280, 1024, 768, 390];
const rows = [];
let bad = 0;
for (const [name, path] of Object.entries(pages)) {
    if (!path) { continue; }
    for (const w of widths) {
        await page.setViewport({ width: w, height: 1000, isMobile: w < 500, hasTouch: w < 500 });
        const r = await page.goto(B + path, { waitUntil: 'load' });
        const m = await page.evaluate(() => {
            const els = [...document.querySelectorAll('main .container-wm, .container-wm')].filter((e) => e.offsetWidth > 0);
            const main = els.sort((a, b) => b.offsetHeight - a.offsetHeight)[0];
            const box = main ? main.getBoundingClientRect() : null;
            const inner = main ? parseFloat(getComputedStyle(main).paddingLeft) : 0;
            return { left: box ? Math.round(box.left + inner) : null, overflow: document.documentElement.scrollWidth - window.innerWidth };
        });
        const pct = m.left === null ? null : +(m.left / w * 100).toFixed(1);
        rows.push({ page: name, width: w, status: r.status(), margin_px: m.left, margin_pct: pct, sideways_scroll: m.overflow > 1 ? m.overflow : 0 });
        if (m.overflow > 1) { bad++; }
        if ([1920, 1280, 390].includes(w)) { await page.screenshot({ path: `screenshots/width-${label}-${name}-${w}.png` }); }
    }
}
console.table(rows);
await browser.close();
process.exit(bad ? 1 : 0);
