/**
 * Browser regression for date controls inside the timer iframe.
 * Requires PHP 8.1+, Playwright and its Chromium browser.
 * PHP_BINARY and BROWSER_EXECUTABLE_PATH can select installed binaries.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright');

const php = process.env.PHP_BINARY || 'php';
const fixture = path.join(__dirname, 'manualtimer.php');
const html = execFileSync(php, ['-n', fixture, '--render-calendar-fixture'], { encoding: 'utf8' });
const submitted = [];
const server = http.createServer(async (req, res) => {
    if (req.method === 'POST') {
        let body = '';
        for await (const chunk of req) body += chunk;
        const fields = Object.fromEntries(new URLSearchParams(body));
        try {
            assert.equal(fields._glpi_csrf_token, 'test-token');
            const begin = fields.manual_begin || '2025-01-10 08:00:00';
            const end = fields.manual_end || fields['actual_end[1]'];
            const normalized = JSON.parse(execFileSync(php, [
                '-n', fixture, '--validate-calendar-values', begin, end,
            ], { encoding: 'utf8' }));
            submitted.push({ fields, normalized, url: req.url });
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            res.end('<p>Saved fixture</p>');
        } catch (error) {
            res.writeHead(400);
            res.end(String(error));
        }
        return;
    }
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(req.url === '/editor'
        ? html
        : '<!doctype html><html lang="pt-BR"><body><iframe title="Timer editor" src="/editor" style="width:100%;height:600px"></iframe></body></html>');
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({
            executablePath: process.env.BROWSER_EXECUTABLE_PATH || undefined,
            args: ['--no-sandbox', '--disable-dev-shm-usage'],
        });
        const context = await browser.newContext({ locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const url = 'http://127.0.0.1:' + server.address().port;
        await page.goto(url);
        let editor = page.frameLocator('iframe');
        assert.equal(await editor.locator('input[type="datetime-local"]').count(), 3);
        const start = editor.locator('[name="manual_begin"]');
        assert.equal(await start.evaluate(input => input.checkValidity()), false);
        assert.equal(await start.evaluate(input => typeof input.showPicker), 'function');

        // Click the browser's own calendar icon, without adding widget scripts.
        const bounds = await start.boundingBox();
        await start.click({ position: { x: bounds.width - 14, y: bounds.height / 2 } });
        assert.equal(await start.evaluate(input => input.matches(':open')), true);
        await page.keyboard.press('Escape');
        await start.fill('2025-01-10T08:00:05');
        await editor.locator('[name="manual_end"]').fill('2025-01-10T08:30:10');
        assert.equal(await start.evaluate(input => input.checkValidity()), true);
        await editor.locator('button[name="add_manual"]').click();
        await page.waitForFunction(() => document.querySelector('iframe')?.contentDocument?.body?.textContent?.includes('Saved fixture'));
        assert.equal(submitted.length, 1);
        assert.equal(submitted[0].normalized.actual_actiontime, 1805);
        assert.equal(submitted[0].normalized.actual_begin, '2025-01-10 08:00:05');
        assert.equal(submitted[0].normalized.actual_end, '2025-01-10 08:30:10');

        await page.goto(url);
        editor = page.frameLocator('iframe');
        const end = editor.locator('[name="actual_end[1]"]');
        assert.equal(await end.inputValue(), '2025-01-10T08:30');
        await end.fill('2025-01-10T08:45');
        await editor.locator('button[name="update"]').click();
        await page.waitForFunction(() => document.querySelector('iframe')?.contentDocument?.body?.textContent?.includes('Saved fixture'));
        assert.equal(submitted.length, 2);
        assert.equal(submitted[1].normalized.actual_end, '2025-01-10 08:45:00');
        assert.equal(submitted[1].normalized.actual_actiontime, 2700);
        assert.deepEqual(errors, []);
        console.log('OK: native calendar opens, required fields validate, and both forms submit canonical dates (Chromium iframe fixture).');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
