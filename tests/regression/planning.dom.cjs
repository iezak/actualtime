/** Real timer JS + jQuery/Bootstrap DOM and PHP actions; no browser process.
 * Run: node tests/regression/planning.dom.cjs (requires jsdom and shared fixture dependencies).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const assert = require('node:assert/strict');
const {JSDOM, VirtualConsole} = require('jsdom');
const {server, backend, calls} = require('./planning-server.cjs');
const pages = [];
const errors = [];
async function until(test, description) {
    const deadline = Date.now() + 8000;
    while (!test()) {
        if (Date.now() > deadline) throw new Error('Timed out: ' + description + '; errors: ' + errors.join('; '));
        await new Promise(resolve => setTimeout(resolve, 25));
    }
}
const button = (page, id, number = 1) => page.window.document.querySelector('[id^="actualtime_button_PlanningExternalEvent_' + id + '_' + number + '_"]');
const counter = (page, id) => page.window.document.querySelector('[id^="actualtime_timer_PlanningExternalEvent_' + id + '_"]');
async function load(url) {
    const virtualConsole = new VirtualConsole();
    virtualConsole.on('jsdomError', error => errors.push(error.message));
    const page = await JSDOM.fromURL(url, {runScripts: 'dangerously', resources: 'usable', pretendToBeVisual: true, virtualConsole});
    pages.push(page);
    await until(() => page.window.actualTime && counter(page, 11), 'load timer JS and event form');
    return page;
}
async function refresh(page) {
    page.window.actualTime.refresh();
    await until(() => !Array.from(page.window.document.querySelectorAll('[id^="actualtime_button_"]')).some(x => x.getAttribute('action') === null), 'read action attributes');
}
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const url = 'http://127.0.0.1:' + server.address().port;
    try {
        const first = await load(url);
        await until(() => !button(first, 10).disabled, 'initial start enabled');
        first.window.actualTime.pressedButton(10, 'PlanningExternalEvent', 'start');
        first.window.actualTime.pressedButton(10, 'PlanningExternalEvent', 'start');
        await until(() => button(first, 10).getAttribute('action') === 'pause', 'start updates DOM');
        assert.equal(calls.filter(x => x.action === 'start' && x.task_id === '10').length, 1, 'Duplicate click sends one request');
        assert.equal(first.window.injected, undefined, 'Event title stays data');
        await until(() => first.window.document.querySelector('#toast_body').textContent.includes('<script>'), 'popup renders escaped title');
        assert.match(first.window.document.querySelector('#toast_body').textContent, /<script>/, 'Popup escapes title');
        await until(() => /[1-9]/.test(counter(first, 10).textContent), 'elapsed display ticks');
        const second = await load(url);
        await until(() => button(second, 10).getAttribute('action') === 'pause', 'new tab recovers running time');
        button(second, 11).click();
        await until(() => button(second, 11).getAttribute('action') === 'pause', 'second event starts');
        // JSDOM windows have isolated localStorage; trigger the existing storage listener explicitly.
        first.window.dispatchEvent(new first.window.StorageEvent('storage', {key: 'actualtime:changed', newValue: 'test'}));
        await until(() => button(first, 11).getAttribute('action') === 'pause', 'other tab reconciles after storage event');
        assert.equal(backend('footer').active.length, 2, 'Two events run together');
        button(second, 10).click();
        await until(() => button(second, 10).getAttribute('action') === 'start', 'pause affects selected event');
        await refresh(first);
        await until(() => button(first, 10).getAttribute('action') === 'start', 'paused counter reconciles');
        assert.equal(button(first, 11).getAttribute('action'), 'pause', 'Other event remains active');
        button(first, 10).click();
        await until(() => button(first, 10).getAttribute('action') === 'pause', 'resume starts next period');
        await refresh(second);
        await until(() => button(second, 10).getAttribute('action') === 'pause', 'resume reconciles');
        button(first, 10, 2).click();
        await until(() => first.window.document.querySelector('form select[name="state"]').value === '2', 'completion updates selected form');
        await refresh(second);
        await until(() => second.window.document.querySelector('form select[name="state"]').value === '2', 'completion updates other tab without reload');
        assert.equal(button(second, 10).disabled, true, 'Completed event controls disabled');
        assert.equal(second.window.document.querySelector('form select[name="state"]').getAttribute('data-track-changes'), 'keep', 'Form tracking preserved');
        assert.equal(button(first, 11).getAttribute('action'), 'pause', 'Other timer remains active after completion');
        assert.ok(first.window.refreshes >= 3, 'Timer actions refresh the planner');
        assert.equal(backend('dump').records.PlanningExternalEvent[10].begin, '2025-01-10 08:00:00', 'Scheduled dates preserved');
        first.window.actualTime.showTaskForm(null, 11, 'PlanningExternalEvent');
        await until(() => first.window.document.querySelector('#actualtime_modal_content [id^="actualtime_timer_PlanningExternalEvent_11_"]'), 'popup loads standalone event');
        assert.equal(first.window.document.querySelectorAll('#actualtime_modal_content form').length, 1, 'Popup has one event form');
        const guest = await load(url + '/?user=99');
        await until(() => guest.window.actualTime, 'guest page');
        assert.equal(guest.window.document.querySelectorAll('[id^="actualtime_button_"]').length, 0, 'Guest reads time without timer controls');
        assert.deepEqual(errors, [], 'No DOM script errors');
        console.log('OK: DOM/AJAX planning controls; elapsed display, duplicate click, two events/windows, storage listener, pause/resume/completion, popup and guest.');
    } finally {for (const page of pages) page.window.close();await new Promise(resolve => server.close(resolve));}
})().catch(error => {console.error(error);process.exitCode = 1;});
