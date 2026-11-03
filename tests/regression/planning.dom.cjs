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
        if (Date.now() > deadline) throw new Error('Timed out: ' + description + '; errors: ' + errors.join('; ') + '; calls: ' + JSON.stringify(calls));
        await new Promise(resolve => setTimeout(resolve, 25));
    }
}
const button = (page, id, number = 1) => page.window.document.querySelector('[id^="actualtime_button_PlanningExternalEvent_' + id + '_' + number + '_"]');
const counter = (page, id) => page.window.document.querySelector('[id^="actualtime_timer_PlanningExternalEvent_' + id + '_"]');
async function clickTimer(page, id, number = 1) {
    await until(() => !button(page, id, number).disabled && page.window.jQuery._data(button(page, id, number), 'events')?.click?.length, 'timer button ready: ' + id + '/' + number);
    button(page, id, number).click();
}
async function load(url) {
    const virtualConsole = new VirtualConsole();
    virtualConsole.on('jsdomError', error => errors.push(error.message));
    const page = await JSDOM.fromURL(url, {runScripts: 'dangerously', resources: 'usable', pretendToBeVisual: true, virtualConsole});
    pages.push(page);
    await until(() => page.window.actualTime?.ajax_url && counter(page, 11), 'load timer JS and event form');
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
        await clickTimer(second, 11);
        await until(() => button(second, 11).getAttribute('action') === 'pause', 'second event starts');
        // JSDOM windows have isolated localStorage; trigger the existing storage listener explicitly.
        first.window.dispatchEvent(new first.window.StorageEvent('storage', {key: 'actualtime:changed', newValue: 'test'}));
        await until(() => button(first, 11).getAttribute('action') === 'pause', 'other tab reconciles after storage event');
        assert.equal(backend('footer').active.length, 2, 'Two events run together');
        await clickTimer(second, 10);
        await until(() => button(second, 10).getAttribute('action') === 'start', 'pause affects selected event');
        await refresh(first);
        await until(() => button(first, 10).getAttribute('action') === 'start', 'paused counter reconciles');
        assert.equal(button(first, 11).getAttribute('action'), 'pause', 'Other event remains active');
        await clickTimer(first, 10);
        await until(() => button(first, 10).getAttribute('action') === 'pause', 'resume starts next period');
        await refresh(second);
        await until(() => button(second, 10).getAttribute('action') === 'pause', 'resume reconciles');
        await clickTimer(first, 10, 2);
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
        if (process.env.ACTUALTIME_GLPI_AJAX) {
            // Native GLPI dialog + official iframe helper + Bootstrap, preserving unsaved fields.
            first.window.actualTime.pressedButton(11, 'PlanningExternalEvent', 'pause');
            await until(() => backend('footer').active.length === 0, 'pause event before correction');
            const location = first.window.location.href;
            first.window.glpi_html_dialog({id: 'planning_editor_parent', title: 'Planning', body: backend('form', {task_id: 11})});
            await until(() => first.window.document.querySelector('#planning_editor_parent [id^="actualtime_planning_modify_"]'), 'native planning dialog');
            const parent = first.window.document.getElementById('planning_editor_parent');
            const trigger = parent.querySelector('[id^="actualtime_planning_modify_"]');
            const editorId = trigger.getAttribute('aria-controls');
            await until(() => first.window.bootstrap.Modal.getInstance(first.window.document.getElementById(editorId)), 'native iframe helper initialized');
            await new Promise(resolve => setTimeout(resolve, 350));
            const unsaved = first.window.document.createElement('input');unsaved.name = 'name';unsaved.value = 'Texto ainda não salvo';
            parent.querySelector('form').appendChild(unsaved);
            trigger.click();
            const editor = first.window.document.getElementById(editorId);
            const iframe = editor.querySelector('iframe');
            let editForm, begin;
            await until(() => {
                if (iframe.contentDocument?.readyState !== 'complete') return false;
                editForm = iframe.contentDocument.querySelector('form#form');
                begin = editForm && Array.from(editForm.elements).find(field => field.name.startsWith('actual_begin['));
                return begin;
            }, 'correction form loads inside iframe');
            assert.ok(parent.isConnected && parent.classList.contains('show'), 'Opening correction preserves the native planning dialog');
            assert.equal(first.window.location.href, location, 'Top window does not navigate');
            assert.ok(Number(editor.style.zIndex) > (parseInt(first.window.getComputedStyle(parent).zIndex, 10) || 1055), 'Editor is layered above the planner');
            assert.equal(editForm.ownerDocument, iframe.contentDocument, 'Save form belongs to the iframe');
            assert.notEqual(editForm.target, '_top', 'Editor does not submit into the parent window');
            const date = new Date(begin.value.replace(' ', 'T') + 'Z');date.setUTCMinutes(date.getUTCMinutes() - 2);
            const adjustedBegin = date.toISOString().slice(0, 19).replace('T', ' ');begin.value = adjustedBegin;
            const values = new URLSearchParams();
            for (const [name, value] of new iframe.contentWindow.FormData(editForm)) values.append(name, value);
            values.append('update', '1');
            const response = await fetch(new URL(editForm.getAttribute('action'), location), {method: 'POST', body: values});
            assert.equal(response.status, 200, 'Correction saves and redirects inside the editor route');
            await response.text();
            // JSDOM does not implement native form navigation; reload only the iframe after the POST.
            iframe.src = iframe.src + '&test_reload=1';
            await until(() => parent.querySelector('[id^="actualtime_segment_"]').textContent.includes(adjustedBegin), 'Saved correction refreshes the parent history');
            await until(() => counter(first, 11).textContent.includes('2min'), 'Saved correction refreshes the elapsed total');
            assert.equal(unsaved.value, 'Texto ainda não salvo', 'Unsubmitted planning fields survive correction');
            await until(() => editor.classList.contains('show'), 'editor shown');
            await new Promise(resolve => setTimeout(resolve, 350));
            editor.querySelector('.btn-close').click();
            await until(() => !editor.classList.contains('show') && first.window.document.activeElement === trigger, 'Close returns focus to the planning button');
            assert.ok(parent.isConnected && parent.classList.contains('show') && first.window.document.body.classList.contains('modal-open'), 'Close preserves the parent dialog and scrolling state');
            assert.equal(first.window.location.href, location, 'Closing does not reload/navigate the page');
            trigger.click();
            await until(() => editor.classList.contains('show'), 'Editor can reopen');
            assert.equal(first.window.document.querySelectorAll('#' + editorId).length, 1, 'Reopening does not duplicate the editor');
        }
        assert.deepEqual(errors, [], 'No DOM script errors');
        console.log('OK: DOM/AJAX planning controls; elapsed display, duplicate click, two events/windows, storage listener, pause/resume/completion, popup and guest.' + (process.env.ACTUALTIME_GLPI_AJAX ? ' Native GLPI modal: open, save, iframe reload, history/total refresh, close, unsaved fields and reopen.' : ''));
    } finally {for (const page of pages) page.window.close();await new Promise(resolve => server.close(resolve));}
})().catch(error => {console.error(error);process.exitCode = 1;});
