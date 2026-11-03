/** Standalone planning UI with actual PHP actions, AJAX, timers and two tabs.
 * Uses Playwright/Chromium and jQuery; PHP_BINARY selects the PHP CLI.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const {server, backend, calls} = require('./planning-server.cjs');
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({executablePath: process.env.BROWSER_EXECUTABLE_PATH || undefined, args: ['--no-sandbox', '--disable-dev-shm-usage']});
        const context = await browser.newContext({locale: 'pt-BR', timezoneId: 'America/Sao_Paulo'});
        const first = await context.newPage();const errors = [];
        const track = page => page.on('pageerror', error => errors.push(error.message));track(first);
        const url = 'http://127.0.0.1:' + server.address().port;
        const toggle = (page, id) => page.locator('[id^="actualtime_button_PlanningExternalEvent_' + id + '_1_"]').first();
        const stop = (page, id) => page.locator('[id^="actualtime_button_PlanningExternalEvent_' + id + '_2_"]').first();
        const waitAction = (page, id, action) => page.waitForFunction(({id, action}) => document.querySelector('[id^="actualtime_button_PlanningExternalEvent_' + id + '_1_"]')?.getAttribute('action') === action, {id, action});
        await first.goto(url);
        await first.waitForFunction(() => window.actualTime && !document.querySelector('[id^="actualtime_button_PlanningExternalEvent_10_1_"]').disabled);
        await first.evaluate(() => {window.actualTime.pressedButton(10, 'PlanningExternalEvent', 'start');window.actualTime.pressedButton(10, 'PlanningExternalEvent', 'start');});
        await waitAction(first, 10, 'pause');
        assert.equal(calls.filter(x => x.action === 'start' && x.task_id === '10').length, 1, 'Duplicate click sends one start');
        assert.equal(await first.evaluate(() => window.injected), undefined, 'Event title cannot execute in the popup');
        await first.waitForFunction(() => document.querySelector('#toast_body').textContent.includes('<script>'));
        assert.match(await first.locator('#toast_body').innerText(), /<script>/, 'Title is literal text');
        await first.waitForFunction(() => /[1-9]/.test(document.querySelector('[id^="actualtime_timer_PlanningExternalEvent_10_"]').textContent));
        const second = await context.newPage();track(second);await second.goto(url);await waitAction(second, 10, 'pause');
        await toggle(second, 11).click();await waitAction(first, 11, 'pause');
        assert.equal(backend('footer').active.length, 2, 'Two planning timers run independently');
        await toggle(second, 10).click();await waitAction(first, 10, 'start');
        assert.equal(await toggle(first, 11).getAttribute('action'), 'pause', 'Pausing one event preserves the other');
        await toggle(first, 10).click();await waitAction(second, 10, 'pause');
        await stop(first, 10).click();
        await first.waitForFunction(() => document.querySelector('form select[name="state"]').value === '2');
        await second.waitForFunction(() => document.querySelector('form select[name="state"]').value === '2');
        assert.equal(await toggle(second, 10).isDisabled(), true, 'Completed event disabled in another tab without reload');
        assert.equal(await second.locator('form select[name="state"]').first().getAttribute('data-track-changes'), 'keep', 'Form tracking is preserved');
        assert.equal(await toggle(first, 11).getAttribute('action'), 'pause', 'Completion preserves second event timer');
        assert.ok(await first.evaluate(() => window.refreshes) >= 3, 'Planner refreshed after timer actions');
        const saved = backend('dump');
        assert.equal(saved.records.PlanningExternalEvent[10].begin, '2025-01-10 08:00:00', 'Scheduled start retained');
        await first.evaluate(() => window.actualTime.showTaskForm(null, 11, 'PlanningExternalEvent'));
        await first.waitForSelector('#actualtime_modal_content [id^="actualtime_timer_PlanningExternalEvent_11_"]');
        assert.equal(await first.locator('#actualtime_modal_content form').count(), 1, 'Popup opens a standalone event with one form');
        const guest = await context.newPage();track(guest);await guest.goto(url + '/?user=99');
        await guest.waitForSelector('[id^="actualtime_timer_PlanningExternalEvent_11_"]');
        assert.equal(await guest.locator('[id^="actualtime_button_"]').count(), 0, 'Guest has no timer controls');
        assert.deepEqual(errors, [], 'No browser JavaScript errors');
        if (process.env.ACTUALTIME_SCREENSHOT) await first.screenshot({path: process.env.ACTUALTIME_SCREENSHOT, fullPage: true});
        console.log('OK: standalone planning UI; elapsed count, double click, two events, two tabs, completion, popup and read-only guest.');
    } finally {if (browser) await browser.close();await new Promise(resolve => server.close(resolve));}
})().catch(error => {console.error(error);process.exitCode = 1;});
