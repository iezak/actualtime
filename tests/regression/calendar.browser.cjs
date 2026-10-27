/**
 * GLPI 10.0.26 calendar regression inside the timer iframe.
 * Requires PHP with intl, Playwright/Chromium, jquery 3.6.0, flatpickr 4.6.13.
 * ACTUALTIME_GLPI_CORE selects GLPI source; PHP_BINARY/BROWSER_EXECUTABLE_PATH
 * can select installed binaries.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright');

const php = process.env.PHP_BINARY || 'php';
const glpi = process.env.ACTUALTIME_GLPI_CORE;
assert.ok(glpi, 'Set ACTUALTIME_GLPI_CORE to a GLPI 10.0.26 source directory');
const fixture = path.join(__dirname, 'manualtimer.php');
const html = execFileSync(php, [fixture, '--render-calendar-fixture'], { encoding: 'utf8' });
const activeHtml = execFileSync(php, [fixture, '--render-calendar-fixture', 'active'], { encoding: 'utf8' });
const common = fs.readFileSync(path.join(glpi, 'js/common.js'), 'utf8');
const localeHelper = common.match(/function getFlatPickerLocale\(language, region\) \{[\s\S]*?\n\}/);
assert.ok(localeHelper, 'GLPI locale helper not found');
const assets = {
    '/assets/jquery.js': fs.readFileSync(require.resolve('jquery/dist/jquery.min.js')),
    '/assets/flatpickr.js': fs.readFileSync(require.resolve('flatpickr/dist/flatpickr.min.js')),
    '/assets/flatpickr.css': fs.readFileSync(require.resolve('flatpickr/dist/flatpickr.min.css')),
    '/assets/pt.js': fs.readFileSync(require.resolve('flatpickr/dist/l10n/pt.js')),
    '/assets/glpi-calendar.js': 'window.__ = message => message;\n' + localeHelper[0] + '\n'
        + fs.readFileSync(path.join(glpi, 'js/flatpickr_buttons_plugin.js'), 'utf8'),
};
const submitted = [];
const server = http.createServer(async (req, res) => {
    if (assets[req.url]) {
        res.writeHead(200, { 'Content-Type': req.url.endsWith('.css') ? 'text/css' : 'application/javascript' });
        res.end(assets[req.url]);
        return;
    }
    if (req.method === 'POST') {
        let body = '';
        for await (const chunk of req) body += chunk;
        const fields = Object.fromEntries(new URLSearchParams(body));
        try {
            assert.equal(fields._glpi_csrf_token, 'test-token');
            const result = fields.add_manual !== undefined
                ? JSON.parse(execFileSync(php, [
                    fixture, '--validate-calendar-values', fields.manual_begin, fields.manual_end,
                ], { encoding: 'utf8' }))
                : JSON.parse(execFileSync(php, [
                    fixture, '--apply-edit-fixture', fields['original_end[1]'] === '' ? 'active' : 'closed', body,
                ], { encoding: 'utf8' }));
            submitted.push({ fields, result, url: req.url });
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            res.end('<p>Saved fixture</p>');
        } catch (error) {
            res.writeHead(400);
            res.end(String(error));
        }
        return;
    }
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(req.url.startsWith('/editor')
        ? (req.url.includes('active=1') ? activeHtml : html)
        : '<!doctype html><html lang="pt-BR"><body><iframe title="Timer editor" src="/editor'
            + (req.url === '/active' ? '?active=1' : '') + '" style="width:100%;height:600px"></iframe></body></html>');
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
        const start = editor.locator('[name="manual_begin"]');
        let startGroup = start.locator('..');
        const startVisible = startGroup.locator('input[type="text"]');
        await startVisible.waitFor({ state: 'visible' });
        assert.equal(await editor.locator('input[type="datetime-local"]').count(), 0);
        assert.equal(await startVisible.evaluate(input => input.checkValidity()), false);
        await startGroup.locator('[data-toggle]').click();
        await editor.locator('.flatpickr-calendar.open').waitFor({ state: 'visible' });
        assert.equal(await editor.locator('.flatpickr-calendar.open .flatpickr-second').count(), 1);
        await startVisible.fill('10/01/2025 08:00:05');
        await startVisible.press('Enter');
        assert.equal(await start.inputValue(), '2025-01-10 08:00:05');
        const endNew = editor.locator('[name="manual_end"]');
        const endVisible = endNew.locator('..').locator('input[type="text"]');
        await endVisible.fill('10/01/2025 08:30:10');
        await endVisible.press('Enter');
        assert.equal(await endNew.inputValue(), '2025-01-10 08:30:10');
        await editor.locator('button[name="add_manual"]').click();
        await page.waitForFunction(() => document.querySelector('iframe')?.contentDocument?.body?.textContent?.includes('Saved fixture'));
        assert.equal(submitted.length, 1);
        assert.equal(submitted[0].result.actual_actiontime, 1805);

        await page.goto(url);
        editor = page.frameLocator('iframe');
        const begin = editor.locator('[name="actual_begin[1]"]');
        const beginVisible = begin.locator('..').locator('input[type="text"]');
        await beginVisible.waitFor({ state: 'visible' });
        await begin.locator('..').locator('[data-toggle]').click();
        await editor.locator('.flatpickr-calendar.open').waitFor({ state: 'visible' });
        await beginVisible.fill('10/01/2025 07:45:00');
        await beginVisible.press('Enter');
        assert.equal(await begin.inputValue(), '2025-01-10 07:45:00');
        const end = editor.locator('[name="actual_end[1]"]');
        const endOldVisible = end.locator('..').locator('input[type="text"]');
        await endOldVisible.waitFor({ state: 'visible' });
        assert.equal(await end.inputValue(), '2025-01-10 08:30:00');
        await end.locator('..').locator('[data-toggle]').click();
        await editor.locator('.flatpickr-calendar.open').waitFor({ state: 'visible' });
        await endOldVisible.fill('10/01/2025 08:45:00');
        await endOldVisible.press('Enter');
        await editor.locator('button[name="update"]').click();
        await page.waitForFunction(() => document.querySelector('iframe')?.contentDocument?.body?.textContent?.includes('Saved fixture'));
        assert.equal(submitted.length, 2);
        assert.equal(submitted[1].fields['original_begin[1]'], '2025-01-10 08:00:00');
        assert.equal(submitted[1].fields['original_end[1]'], '2025-01-10 08:30:00');
        assert.equal(submitted[1].result.changed, 1);
        assert.equal(submitted[1].result.timers[0].actual_begin, '2025-01-10 07:45:00');
        assert.equal(submitted[1].result.timers[0].actual_end, '2025-01-10 08:45:00');
        assert.equal(submitted[1].result.timers[0].actual_actiontime, 3600);
        assert.equal(submitted[1].result.timers[0].users_id, 42);
        assert.equal(submitted[1].result.task.state, 1);
        assert.equal(submitted[1].result.task.actiontime, 3600);

        await page.goto(url + '/active');
        editor = page.frameLocator('iframe');
        const runningBegin = editor.locator('[name="actual_begin[1]"]');
        const runningVisible = runningBegin.locator('..').locator('input[type="text"]');
        await runningVisible.waitFor({ state: 'visible' });
        assert.equal(await editor.locator('[name="actual_end[1]"]').getAttribute('type'), 'hidden');
        await runningBegin.locator('..').locator('[data-toggle]').click();
        await editor.locator('.flatpickr-calendar.open').waitFor({ state: 'visible' });
        await runningVisible.fill('10/01/2025 07:45:00');
        await runningVisible.press('Enter');
        await editor.locator('button[name="update"]').click();
        await page.waitForFunction(() => document.querySelector('iframe')?.contentDocument?.body?.textContent?.includes('Saved fixture'));
        assert.equal(submitted.length, 3);
        assert.equal(submitted[2].fields['original_end[1]'], '');
        assert.equal(submitted[2].result.changed, 1);
        assert.equal(submitted[2].result.timers[0].actual_begin, '2025-01-10 07:45:00');
        assert.equal(submitted[2].result.timers[0].actual_end, null);
        assert.equal(submitted[2].result.timers[0].actual_actiontime, 0);
        assert.equal(submitted[2].result.task.state, 1);
        assert.match(submitted[2].result.history.at(-1).changes[2], /2025-01-10 08:00:00 \/ In progress -> 2025-01-10 07:45:00 \/ In progress/);
        assert.deepEqual(errors, []);
        console.log('OK: original GLPI calendars submit creation dates and save completed/running edits through the plugin PHP methods inside an iframe.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
