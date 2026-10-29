/**
 * Actual PHP task controls/runtime + actual browser AJAX and counters.
 * Bootstrap lifecycle is adapted; ACTUALTIME_GLPI_CORE selects the real Html.
 * Requires PHP, Playwright/Chromium, jQuery and Select2 4.x.
 * ACTUALTIME_STATUS_GLPI_CORE optionally loads GLPI's real unsaved-form tracker.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright');
const core = process.env.ACTUALTIME_STATUS_GLPI_CORE;
const common = core ? fs.readFileSync(path.join(core, 'js/common.js'), 'utf8') : '';
const tracking = common.match(/window\.glpiUnsavedFormChanges = false;\n\$\(document\)\.ready\(function\(\) \{[\s\S]*?\n\}\);/);
if (core) assert.ok(tracking, 'GLPI unsaved-form tracker not found');
const php = process.env.PHP_BINARY || 'php';
const fixture = path.join(__dirname, 'concurrent-timers.php');
let state = '';
const calls = [];
let holdNextFooter = false;
let heldFooter;
let onHeld;
function backend(mode, data = {}) {
    const result = JSON.parse(execFileSync(php, [fixture, '--fixture', mode, JSON.stringify(data), state], {encoding: 'utf8'}));
    state = result.state;
    return result.response;
}
const assets = {
    '/assets/jquery.js': fs.readFileSync(require.resolve('jquery/dist/jquery.min.js')),
    '/assets/runtime.js': fs.readFileSync(path.join(__dirname, '../../js/actualtime.js')),
    '/assets/select2.js': fs.readFileSync(require.resolve('select2/dist/js/select2.full.min.js')),
    '/assets/bootstrap-adapter.js': [
        "window.__ = message => message;",
        "window._n = (one, many, n) => n === 1 ? one : many;",
        "window.CFG_GLPI = {root_doc: ''};",
        "window.GLPI_PLUGINS_PATH = {actualtime: 'plugins/actualtime'};",
        "window.bootstrap = {",
        "Toast: class { constructor(root) { this.root = root; } show() { this.root.classList.add('show'); } hide() { this.root.classList.remove('show'); } },",
        "Modal: class { constructor(root) { this.root = root; } show() { this.root.classList.add('show'); } hide() { this.root.classList.remove('show'); } }",
        "};",
        tracking ? tracking[0] : [
            "window.glpiUnsavedFormChanges = false;",
            "$(document).on('input change', 'form[data-track-changes=true] :input', () => { window.glpiUnsavedFormChanges = true; });"
        ].join('\n'),
        "$('#dropdown_status_main').select2();"
    ].join('\n'),
};
const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    try {
        if (assets[url.pathname]) {
            res.writeHead(200, {'Content-Type': 'application/javascript'});
            res.end(assets[url.pathname]);
            return;
        }
        if (url.pathname.endsWith('/ajax/timer.php')) {
            let result;
            if (req.method === 'POST') {
                let body = '';
                for await (const chunk of req) body += chunk;
                const input = Object.fromEntries(new URLSearchParams(body));
                calls.push(input);
                result = backend('action', input);
            } else if (url.searchParams.has('showform')) {
                const input = Object.fromEntries(url.searchParams);
                calls.push(input);
                res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
                res.end(backend('form', input));
                return;
            } else {
                result = backend('footer', {watch: JSON.parse(url.searchParams.get('watch') || '[]')});
                if (holdNextFooter) {
                    holdNextFooter = false;
                    heldFooter = {res, result};
                    onHeld();
                    return;
                }
            }
            res.writeHead(200, {'Content-Type': 'application/json'});
            res.end(JSON.stringify(result));
            return;
        }
        res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
        const html = backend('page', {readonly: url.pathname === '/readonly'});
        res.end(html.replace('<script src="/assets/bootstrap-adapter.js">', '<script src="/assets/select2.js"></script><script src="/assets/bootstrap-adapter.js">'));
    } catch (error) {
        res.writeHead(500, {'Content-Type': 'text/plain'});
        res.end(String(error));
    }
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({
            executablePath: process.env.BROWSER_EXECUTABLE_PATH || undefined,
            args: ['--no-sandbox', '--disable-dev-shm-usage'],
        });
        const context = await browser.newContext({locale: 'pt-BR', timezoneId: 'America/Sao_Paulo'});
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const url = 'http://127.0.0.1:' + server.address().port;
        const toggle = (surface, type, id) => surface.locator('[id^="actualtime_button_' + type + '_' + id + '_1_"]').first();
        const counter = (surface, type, id) => surface.locator('[id^="actualtime_timer_' + type + '_' + id + '_"]').first();
        await page.goto(url);
        await page.waitForFunction(() => window.actualTime && document.querySelector('[id^="actualtime_button_TicketTask_1_1_"]')?.disabled === false);
        assert.equal(await page.locator('#dropdown_status_main').inputValue(), '4');
        await page.evaluate(() => {
            window.actualTime.pressedButton(1, 'TicketTask', 'start');
            window.actualTime.pressedButton(1, 'TicketTask', 'start');
        });
        await page.waitForFunction(() => document.querySelector('[id^="actualtime_button_TicketTask_1_1_"]')?.getAttribute('action') === 'pause');
        assert.equal(await page.locator('#dropdown_status_main').inputValue(), '2', 'Start immediately shows the saved ticket status');
        assert.match(await page.locator('#select2-dropdown_status_main-container').innerText(), /Processing/);
        assert.equal(await page.evaluate(() => window.glpiUnsavedFormChanges), false, 'Automatic status update does not mark the form dirty');
        await page.locator('#ticket-subject').fill('Unsaved subject');
        await page.locator('#ticket-draft').fill('Unsaved description');
        assert.equal(calls.filter(call => call.action === 'start' && call.itemtype === 'TicketTask' && call.task_id === '1').length, 1, 'Double click sends one start for the task');
        await toggle(page, 'TicketTask', 2).click();
        await page.waitForFunction(() => document.querySelector('[id^="actualtime_button_TicketTask_2_1_"]')?.getAttribute('action') === 'pause');
        await toggle(page, 'ProjectTask', 1).click();
        await page.waitForFunction(() => document.querySelectorAll('#toast_body a').length === 3);
        assert.equal(await toggle(page, 'TicketTask', 1).getAttribute('action'), 'pause');
        assert.equal(await toggle(page, 'ProjectTask', 1).getAttribute('action'), 'pause');
        const initialB = await counter(page, 'TicketTask', 2).innerText();
        await page.waitForFunction(value => document.querySelector('[id^="actualtime_timer_TicketTask_2_"]')?.textContent !== value, initialB);

        const held = new Promise(resolve => { onHeld = resolve; });
        holdNextFooter = true;
        await page.evaluate(() => window.actualTime.refresh());
        await held;
        await toggle(page, 'TicketTask', 1).click();
        await page.waitForFunction(() => document.querySelector('[id^="actualtime_button_TicketTask_1_1_"]')?.getAttribute('action') === 'start');
        assert.equal(await page.locator('#dropdown_status_main').inputValue(), '4', 'Pause immediately shows Pending without a page reload');
        assert.match(await page.locator('#select2-dropdown_status_main-container').innerText(), /Pending/);
        assert.equal(await page.locator('#ticket-subject').inputValue(), 'Unsaved subject');
        assert.equal(await page.locator('#ticket-draft').inputValue(), 'Unsaved description');
        assert.equal(await page.evaluate(() => window.glpiUnsavedFormChanges), true, 'Existing unsaved edits remain tracked');
        const frozenA = await counter(page, 'TicketTask', 1).innerText();
        heldFooter.res.writeHead(200, {'Content-Type': 'application/json'});
        heldFooter.res.end(JSON.stringify(heldFooter.result));
        heldFooter = null;
        await page.waitForFunction(() => document.querySelectorAll('#toast_body a').length === 2);
        assert.equal(await page.locator('#dropdown_status_main').inputValue(), '4', 'Stale snapshots cannot restore the old ticket status');
        const beforeB = await counter(page, 'TicketTask', 2).innerText();
        await page.waitForFunction(value => document.querySelector('[id^="actualtime_timer_TicketTask_2_"]')?.textContent !== value, beforeB);
        assert.equal(await counter(page, 'TicketTask', 1).innerText(), frozenA, 'Stale footer cannot restart a paused counter');
        assert.equal(await toggle(page, 'ProjectTask', 1).getAttribute('action'), 'pause', 'Equal-ID project remains active');

        await page.locator('#toast_body a').filter({hasText: 'Ticket #2 / Task #2'}).click();
        await page.locator('#actualtime_modal_content [id^="actualtime_button_TicketTask_2_2_"]').waitFor();
        assert.equal(calls.filter(call => call.showform).at(-1).task_id, '2', 'Popup opens the selected task');
        await page.locator('#actualtime_modal_content [id^="actualtime_button_TicketTask_2_2_"]').click();
        await page.waitForFunction(() => document.querySelector('div[data-itemtype="TicketTask"][data-items-id="2"] select[name="state"]')?.value === '2');
        assert.equal(await page.locator('div[data-itemtype="TicketTask"][data-items-id="1"] select[name="state"]').inputValue(), '1');
        assert.equal(await page.locator('div[data-itemtype="ProjectTask"][data-items-id="1"] select[name="projectstates_id"]').inputValue(), '1');
        assert.equal(await page.locator('#unrelated select').inputValue(), '9');
        assert.equal(await page.locator('#unrelated select').getAttribute('data-track-changes'), 'keep');
        assert.equal(await toggle(page, 'ProjectTask', 1).getAttribute('action'), 'pause');

        await toggle(page, 'TicketTask', 1).click();
        await page.waitForFunction(() => document.querySelectorAll('#toast_body a').length === 2);
        assert.equal(await page.locator('#dropdown_status_main').inputValue(), '2', 'Resume shows Processing immediately');
        const other = await context.newPage();
        other.on('pageerror', error => errors.push(error.message));
        await other.goto(url);
        await other.waitForFunction(() => document.querySelectorAll('#toast_body a').length === 2);
        await toggle(other, 'TicketTask', 1).click();
        await other.waitForFunction(() => document.querySelector('[id^="actualtime_button_TicketTask_1_1_"]')?.getAttribute('action') === 'start');
        await page.waitForFunction(() => document.querySelector('[id^="actualtime_button_TicketTask_1_1_"]')?.getAttribute('action') === 'start');
        assert.equal(await page.locator('#dropdown_status_main').inputValue(), '4', 'Other-tab pause synchronizes the displayed parent status');
        assert.equal(await toggle(page, 'ProjectTask', 1).getAttribute('action'), 'pause', 'Other tab pauses only the selected timer');
        await page.evaluate(() => { window.glpiUnsavedFormChanges = false; });
        await page.reload();
        await page.waitForFunction(() => document.querySelectorAll('#toast_body a').length === 1);
        assert.match(await page.locator('#toast_body a').innerText(), /Project #1 \/ Task #1/);
        assert.equal(await toggle(page, 'TicketTask', 1).getAttribute('action'), 'start');
        const prior = await counter(page, 'ProjectTask', 1).innerText();
        backend('config', {show_popup: 0});
        await page.evaluate(() => window.actualTime.refresh());
        await page.waitForFunction(() => !document.querySelector('#toast_actualtime .toast').classList.contains('show'));
        await page.waitForFunction(value => document.querySelector('[id^="actualtime_timer_ProjectTask_1_"]')?.textContent !== value, prior);
        const dump = backend('dump');
        assert.equal(dump.timers.filter(timer => timer.actual_end === null).length, 1);
        assert.equal(dump.timers.find(timer => timer.itemtype === 'ProjectTask').actual_end, null);

        const readonly = await context.newPage();
        readonly.on('pageerror', error => errors.push(error.message));
        await readonly.goto(url + '/readonly');
        await readonly.waitForFunction(() => document.querySelector('[id^="actualtime_button_TicketTask_1_1_"]')?.disabled === false);
        await toggle(readonly, 'TicketTask', 1).click();
        await readonly.waitForFunction(() => document.querySelector('[data-actualtime-parent-status]')?.textContent.includes('Processing'));
        await toggle(readonly, 'TicketTask', 1).click();
        await readonly.waitForFunction(() => document.querySelector('[data-actualtime-parent-status]')?.textContent.includes('Pending'));
        assert.equal(await readonly.locator('#reopen-link').count(), 1, 'Read-only status retains GLPI controls');
        assert.equal(await readonly.locator('[data-actualtime-parent-status]').count(), 1, 'Read-only status updates in place');
        await readonly.close();

        await page.locator('#dropdown_status_main').selectOption('3');
        await toggle(page, 'TicketTask', 1).click();
        await page.waitForFunction(() => document.querySelector('[id^="actualtime_button_TicketTask_1_1_"]')?.getAttribute('action') === 'pause');
        assert.equal(await page.locator('#dropdown_status_main').inputValue(), '3', 'A manually selected unsaved status is preserved');
        assert.deepEqual(errors, []);
        console.log('OK: immediate ticket status/Select2, read-only status, preserved drafts/manual status, stale snapshots, cross-tab pause and simultaneous counters.');
    } finally {
        if (heldFooter) heldFooter.res.end(JSON.stringify(heldFooter.result));
        if (browser) await browser.close();
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
