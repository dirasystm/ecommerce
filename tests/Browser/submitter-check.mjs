// Verifies that admin.js's double-submit guard does NOT drop the clicked button's name/value
// (forms like return inspection send outcome=restock|write_off through the button).
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const adminJs = fs.readFileSync(path.join(here, '../../public/assets/js/admin.js'), 'utf8');
const EDGE = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find((p) => fs.existsSync(p));

const browser = await puppeteer.launch({ executablePath: EDGE, headless: true, args: ['--disable-gpu'] });
const page = await browser.newPage();
await page.setRequestInterception(true);
let posted = null;
const FORM = `<!doctype html><form method="post" action="http://localhost/ecommerce/__submit">
  <input type="hidden" name="action" value="inspect">
  <button type="submit" name="outcome" value="restock" id="pass">Pass</button>
  <button type="submit" name="outcome" value="write_off" id="fail">Fail</button>
</form>`;
page.on('request', (req) => {
    if (req.url().endsWith('/__form')) {
        // Served by the test itself on the shop origin (no CSP header), so admin.js can be injected.
        req.respond({ status: 200, contentType: 'text/html', body: FORM });
    } else if (req.method() === 'POST') {
        posted = req.postData();
        req.respond({ status: 200, contentType: 'text/html', body: '<p>ok</p>' });
    } else {
        req.continue();
    }
});
await page.goto('http://localhost/ecommerce/__form');
await page.addScriptTag({ content: adminJs });
await Promise.all([page.waitForNavigation(), page.click('#fail')]);
await browser.close();
console.log('POST body:', posted);
if (!posted || !posted.includes('outcome=write_off')) {
    console.error('FAIL: clicked button value missing');
    process.exit(1);
}
console.log('PASS: clicked button value submitted');
