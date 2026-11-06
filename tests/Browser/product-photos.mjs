// Product photos check: the new-product photo picker (previews, main badge, reorder, remove, large-photo shrinking)
// and the product page gallery (arrows, thumbnails, full-screen viewer with keyboard, share buttons, copy link).
// Run against a server using the test database, e.g. WAIMART_URL=http://127.0.0.1:8093 node product-photos.mjs
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const B = process.env.WAIMART_URL || 'http://127.0.0.1:8093';
const SLUG = process.env.WAIMART_SLUG || 'browser-gallery-charger';
const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find((p) => fs.existsSync(p));
const browser = await puppeteer.launch({ executablePath: CHROME, headless: true });
await browser.defaultBrowserContext().overridePermissions(B, ['clipboard-read', 'clipboard-write', 'clipboard-sanitized-write']).catch(() => {});
const page = await browser.newPage();
await page.setViewport({ width: 1280, height: 900 });
const errors = [];
const checks = [];
const ok = (name, cond, extra = '') => { checks.push([name, !!cond]); console.log((cond ? '  PASS ' : '  FAIL ') + name + (extra ? ' — ' + extra : '')); };
page.on('pageerror', (e) => errors.push(String(e)));
page.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text()); } });

// ---------- Photo picker ----------
await page.goto(B + '/admin/login', { waitUntil: 'load' });
await page.type('#login', process.env.WAIMART_LOGIN_EMAIL || 'browser-manager@waimart.test');
await page.type('#password', process.env.WAIMART_LOGIN_PASSWORD || 'Secret123');
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.$eval('#password', (el) => el.form.requestSubmit())]);
await page.goto(B + '/admin/products/new', { waitUntil: 'load' });

// Two small photos and one big "phone" photo (4000x3000 noisy JPEG, several MB) made in the page itself.
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'wmpp-'));
const make = async (w, h, color, noisy, type) => page.evaluate(async (w, h, color, noisy, type) => {
    const c = document.createElement('canvas'); c.width = w; c.height = h;
    const x = c.getContext('2d'); x.fillStyle = color; x.fillRect(0, 0, w, h);
    if (noisy) { const d = x.getImageData(0, 0, w, h); for (let i = 0; i < d.data.length; i += 4) { d.data[i] = Math.random() * 255; d.data[i + 1] = Math.random() * 255; } x.putImageData(d, 0, 0); }
    const blob = await new Promise((r) => c.toBlob(r, type, 0.98));
    return Array.from(new Uint8Array(await blob.arrayBuffer()));
}, w, h, color, noisy, type);
const files = [['red.png', 300, 300, '#c33', false, 'image/png'], ['green.png', 300, 300, '#3a3', false, 'image/png'], ['big-phone.jpg', 4000, 3000, '#33c', true, 'image/jpeg']];
const paths = [];
for (const [name, w, h, color, noisy, type] of files) {
    const p = path.join(dir, name); fs.writeFileSync(p, Buffer.from(await make(w, h, color, noisy, type))); paths.push(p);
}
const bigBytes = fs.statSync(paths[2]).size;
const input = await page.$('#images');
await input.uploadFile(...paths);
await page.waitForFunction(() => document.querySelectorAll('[data-photo-tile]').length === 3 && !/Preparing/.test(document.querySelector('[data-photo-status]')?.textContent || ''), { timeout: 30000 });
const state = () => page.evaluate(() => ({
    tiles: [...document.querySelectorAll('[data-photo-tile] img')].map((i) => i.alt),
    main: document.querySelector('[data-photo-tile] .wm-photo-main') ? document.querySelector('[data-photo-tile] img').alt : null,
    inputFiles: [...document.getElementById('images').files].map((f) => f.name + ':' + f.size),
    status: document.querySelector('[data-photo-status]')?.textContent || '',
}));
let s = await state();
ok('three previews shown', s.tiles.length === 3, s.status);
ok('first photo marked Main', /red\.png/.test(s.main || ''));
const big = s.inputFiles.find((f) => f.startsWith('big-phone'));
ok('big phone photo shrunk before upload', big && parseInt(big.split(':')[1], 10) < bigBytes && parseInt(big.split(':')[1], 10) < 5 * 1048576, `original ${(bigBytes / 1048576).toFixed(1)} MB -> ${big}`);
await page.click('[data-photo-tile]:nth-child(2) [aria-label^="Move photo 2 earlier"]');
s = await state();
ok('reorder makes green the main photo', /green\.png/.test(s.main || '') && s.inputFiles[0].startsWith('green'), s.inputFiles.join(', '));
await page.click('[data-photo-tile]:nth-child(3) [aria-label^="Remove photo 3"]');
s = await state();
ok('remove leaves two photos in the form', s.inputFiles.length === 2 && s.tiles.length === 2, s.inputFiles.join(', '));
await (await page.$('#photos')).screenshot({ path: 'screenshots/photo-picker.png' });

// Submit a real product with the two photos.
await page.type('#name', 'Picker Upload Check ' + Date.now());
await page.select('#category_id', await page.$eval('#category_id option:nth-child(2)', (o) => o.value));
await page.type('#newsku', 'PUC-' + Date.now().toString().slice(-6));
await page.type('#newprice', 'KSh 2,500');
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.$eval('#images', (el) => el.form.requestSubmit())]);
const after = await page.evaluate(() => ({ url: location.pathname, text: document.body.innerText, tiles: document.querySelectorAll('.wm-img-tile').length }));
ok('product created with 2 photos', /\/admin\/products\/\d+$/.test(after.url) && /created with 2 photos/.test(after.text) && after.tiles === 2, after.url + ' tiles=' + after.tiles);

// ---------- Product page gallery ----------
await page.goto(B + '/product/' + SLUG, { waitUntil: 'load' });
const mainSrc = () => page.$eval('#wmMainImage', (i) => i.getAttribute('src'));
const first = await mainSrc();
await page.hover('.wm-gallery-main');
await page.click('.wm-gallery-arrow.next');
ok('next arrow changes the photo', (await mainSrc()) !== first);
ok('counter shows 2 / 3', (await page.$eval('[data-gallery-count]', (e) => e.textContent)) === '2 / 3');
await page.click('[data-gallery-index="2"]');
ok('thumbnail 3 selected', (await page.$eval('[data-gallery-index="2"]', (b) => b.classList.contains('active'))));
await page.click('[data-lb-open]');
await page.waitForSelector('#wmLightbox.show', { timeout: 5000 });
await new Promise((r) => setTimeout(r, 400));
ok('full-screen viewer opens on photo 3', (await page.$eval('[data-lb-count]', (e) => e.textContent)) === '3 / 3');
await page.screenshot({ path: 'screenshots/product-lightbox.png' });
await page.keyboard.press('ArrowRight');
ok('→ wraps to photo 1', (await page.$eval('[data-lb-count]', (e) => e.textContent)) === '1 / 3');
await page.keyboard.press('ArrowLeft');
ok('← goes back to photo 3', (await page.$eval('[data-lb-count]', (e) => e.textContent)) === '3 / 3');
await page.keyboard.press('Escape');
await page.waitForFunction(() => getComputedStyle(document.getElementById('wmLightbox')).display === 'none', { timeout: 5000 });
ok('Esc closes the viewer and the main photo matches', (await mainSrc()) === (await page.$eval('[data-gallery-index="2"]', (b) => b.getAttribute('data-gallery-src'))));
const share = await page.$$eval('.wm-share-btn', (els) => els.map((e) => (e.getAttribute('aria-label') || '') + '|' + (e.getAttribute('href') || '')));
ok('share buttons present', ['Facebook', 'X', 'WhatsApp', 'Telegram', 'Copy link'].every((n) => share.some((x) => x.includes(n))), share.length + ' buttons');
ok('share links point at the product', share.filter((x) => x.includes('http')).every((x) => x.includes(encodeURIComponent('/product/' + SLUG))));
await new Promise((r) => setTimeout(r, 1000));
const backdrops = await page.$$eval('.modal-backdrop', (e) => e.length); ok('no grey overlay left after closing the viewer', backdrops === 0, backdrops + ' left');
const hit = await page.$eval('[data-share-copy]', (b) => { const r = b.getBoundingClientRect(); const el = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2); return el ? el.className + ' / ' + (el.closest('[data-share-copy]') ? 'button' : 'other') : 'none'; }); console.log('  hit:', hit);
await page.click('[data-share-copy]');
await page.waitForFunction(() => document.querySelector('[data-share-status]').textContent !== '', { timeout: 2500 }).catch(() => {});
const copied = await page.$eval('[data-share-status]', (e) => e.textContent); ok('copy link reports back', /copied|http/.test(copied), JSON.stringify(copied));
await (await page.$('.col-md-5')).screenshot({ path: 'screenshots/product-gallery.png' });

// Phone: swipe changes the photo.
await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
await page.goto(B + '/product/' + SLUG, { waitUntil: 'load' });
const box = await (await page.$('.wm-gallery-main')).boundingBox();
const before = await mainSrc();
await page.touchscreen.touchStart(box.x + box.width * 0.8, box.y + box.height / 2);
await page.touchscreen.touchMove(box.x + box.width * 0.2, box.y + box.height / 2);
await page.touchscreen.touchEnd();
await new Promise((r) => setTimeout(r, 300));
ok('swipe left shows the next photo on a phone', (await mainSrc()) !== before);
ok('viewer did not open from the swipe', !(await page.$('#wmLightbox.show')));
const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
ok('no sideways scrolling on a phone', !overflow);
await page.screenshot({ path: 'screenshots/product-gallery-phone.png' });

ok('no JavaScript errors', errors.length === 0, errors.join(' | '));
console.log(JSON.stringify({ passed: checks.filter((c) => c[1]).length, failed: checks.filter((c) => !c[1]).map((c) => c[0]) }));
await browser.close();
fs.rmSync(dir, { recursive: true, force: true });
process.exit(checks.every((c) => c[1]) ? 0 : 1);
