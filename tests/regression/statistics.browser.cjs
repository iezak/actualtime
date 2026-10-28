/**
 * Render the real statistics script/HTML with punctuation in technician names.
 * Requires PHP, jQuery and Playwright as in calendar.browser.cjs.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const html = execFileSync(process.env.PHP_BINARY || 'php',
    [path.join(__dirname, 'statistics.php'), '--render'], {encoding: 'utf8'});
const jquery = fs.readFileSync(require.resolve('jquery/dist/jquery.min.js'));
const server = http.createServer((req, res) => {
    res.writeHead(200, {'Content-Type': req.url === '/jquery.js' ? 'application/javascript' : 'text/html; charset=utf-8'});
    res.end(req.url === '/jquery.js' ? jquery : html);
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({executablePath: process.env.BROWSER_EXECUTABLE_PATH || undefined,
            args: ['--no-sandbox', '--disable-dev-shm-usage']});
        const page = await browser.newPage();
        const errors = [];
        let dialogs = 0;
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', async dialog => { ++dialogs; await dialog.dismiss(); });
        await page.goto('http://127.0.0.1:' + server.address().port);
        await page.locator('.dates_timelines table').nth(1).waitFor();
        assert.equal(await page.locator('.dates_timelines table').count(), 2);
        assert.equal(await page.locator('.dates_timelines img, .dates_timelines script').count(), 0);
        assert.match(await page.locator('.dates_timelines').innerText(), /Técnico "A" <img src=x onerror="alert\(1\)"> & B/);
        assert.equal(await page.locator('.dates_timelines p').count(), 1);
        assert.equal(dialogs, 0);
        assert.deepEqual(errors, []);
        console.log('OK: statistics render both tables and preserve technician text without JavaScript errors or HTML injection.');
    } finally {
        if (browser) await browser.close();
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
