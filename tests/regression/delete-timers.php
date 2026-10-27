<?php

/**
 * Confirmed single-entry deletion, totals, audit and failure recovery.
 * Run: php tests/regression/delete-timers.php
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

define('ACTUALTIME_EXTENDED_TEST', true);
require __DIR__ . '/manualtimer.php';

function deletionState(): array {
    global $DB;
    return [$DB->timers, $DB->sources, CommonDBTM::$records, Log::$entries];
}
function expectDeleteRejection(array $input, string $message): void {
    global $DB;
    $before = deletionState();
    rejects(fn() => PluginActualtimeManualtimer::deleteTimer(TicketTask::class, 10, $input), $message);
    check(deletionState() === $before, 'No changes: ' . $message);
    check(!$DB->inTransaction(), 'Transaction released: ' . $message);
}

resetFixture();
addPeriod();
addPeriod('2025-01-10 08:30:00', '2025-01-10 08:45:00');
$input = deleteFixtureInput();
$before = deletionState();
$preview = PluginActualtimeManualtimer::deletionPreview(TicketTask::class, 10, $input);
check($preview['timer']['id'] === 1 && $preview['timer']['actual_actiontime'] === 1800, 'Confirmation loads the persisted period');
check(deletionState() === $before && $DB->beginCount === 2, 'Opening/canceling confirmation does not write or open a transaction');
unset($input['confirm_delete']);
expectDeleteRejection($input, 'Explicit confirmation required');
$input['confirm_delete'] = '0';
expectDeleteRejection($input, 'Invalid confirmation is not accepted');
$input['confirm_delete'] = '1';
$remaining = $DB->timers[1];
check(PluginActualtimeManualtimer::deleteTimer(TicketTask::class, 10, $input) === 1, 'Selected period deleted');
check($DB->timers === [$remaining], 'Other period stays unchanged');
check(PluginActualtimeTask::totalEndTime(10, TicketTask::class) === 900 && CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 900, 'Real and task totals recalculated to 15 minutes');
check(CommonDBTM::$records[TicketTask::class][10]['state'] === 1 && $remaining['users_id'] === 42, 'Task state and other timer owner preserved');
$audit = end(Log::$entries);
check(count(Log::$entries) === 3 && $audit['actor'] === 7 && $audit['id'] === 1 && $audit['type'] === Ticket::class, 'Existing history retained and deletion attributed to current operator');
check(str_contains($audit['changes'][2], 'Deleted timer #1, task #10, technician #42: 2025-01-10 08:00:00 - 2025-01-10 08:30:00 (1800 seconds).'), 'Audit retains timer, task, technician, dates and exact duration');
expectDeleteRejection($input, 'Repeated delete cannot remove another row or duplicate audit');
$input = deleteFixtureInput(2);
PluginActualtimeManualtimer::deleteTimer(TicketTask::class, 10, $input);
check($DB->timers === [] && CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 0, 'Deleting last period resets the automatic total');
addPeriod();
check(count($DB->timers) === 1 && $DB->timers[0]['id'] !== 1 && $DB->timers[0]['actual_actiontime'] === 1800, 'Manual creation still works after deleting the last period');

resetFixture();
addPeriod();
addPeriod('2025-01-10 09:00:00', '2025-01-10 09:30:00');
$edit = editFixtureInput();
$edit['actual_begin'][1] = '2025-01-10 07:45:00';
$edit['actual_begin'][2] = '2025-01-10 08:45:00';
PluginActualtimeManualtimer::edit(TicketTask::class, 10, $edit);
$otherSource = $DB->sources[1];
$input = deleteFixtureInput();
PluginActualtimeManualtimer::deleteTimer(TicketTask::class, 10, $input);
check($DB->sources === [$otherSource], 'Only the selected timer native modification snapshot is removed');
check(count(Log::$entries) === 5 && str_contains(end(Log::$entries)['changes'][2], '(2700 seconds)'), 'Edited period deletion preserves prior audits and logs its current duration');

foreach (['missing id', 'array id', 'unknown id', 'other task', 'other type', 'missing snapshot', 'changed start', 'changed end', 'changed duration', 'changed owner'] as $case) {
    resetFixture();
    addPeriod();
    $input = deleteFixtureInput();
    if ($case === 'missing id') { unset($input['timer_id']); }
    if ($case === 'array id') { $input['timer_id'] = [1]; }
    if ($case === 'unknown id') { $input['timer_id'] = '99'; }
    if ($case === 'other task') { $DB->timers[0]['items_id'] = 11; }
    if ($case === 'other type') { $DB->timers[0]['itemtype'] = ProjectTask::class; }
    if ($case === 'missing snapshot') { unset($input['original_end']); }
    if ($case === 'changed start') { $DB->timers[0]['actual_begin'] = '2025-01-10 07:45:00'; }
    if ($case === 'changed end') { $DB->timers[0]['actual_end'] = '2025-01-10 08:45:00'; }
    if ($case === 'changed duration') { $DB->timers[0]['actual_actiontime'] = 2700; }
    if ($case === 'changed owner') { $DB->timers[0]['users_id'] = 99; }
    expectDeleteRejection($input, $case);
}

foreach (['profile', 'task access', 'parent access', 'solved', 'closed', 'deleted parent', 'no login', 'inactive plugin'] as $case) {
    resetFixture();
    addPeriod();
    $input = deleteFixtureInput();
    if ($case === 'profile') { Session::$right = false; }
    if ($case === 'task access') { CommonDBTM::$denied = ['TicketTask:10']; }
    if ($case === 'parent access') { CommonDBTM::$denied = ['Ticket:1']; }
    if ($case === 'solved') { CommonDBTM::$records[Ticket::class][1]['status'] = 5; }
    if ($case === 'closed') { CommonDBTM::$records[Ticket::class][1]['status'] = 6; }
    if ($case === 'deleted parent') { CommonDBTM::$records[Ticket::class][1]['is_deleted'] = 1; }
    if ($case === 'no login') { Session::$user = 0; }
    if ($case === 'inactive plugin') { Plugin::$active = false; }
    expectDeleteRejection($input, 'Authorization: ' . $case);
    rejects(fn() => PluginActualtimeManualtimer::deletionPreview(TicketTask::class, 10, $input), 'Preview authorization: ' . $case);
}

foreach (['timer', 'snapshot', 'duration', 'history', 'lock', 'commit', 'row changed during delete'] as $failure) {
    resetFixture();
    addPeriod();
    $edit = editFixtureInput();
    $edit['actual_begin'][1] = '2025-01-10 07:45:00';
    PluginActualtimeManualtimer::edit(TicketTask::class, 10, $edit);
    $input = deleteFixtureInput();
    if ($failure === 'timer') { $DB->failTimerDelete = true; }
    if ($failure === 'snapshot') { $DB->failSourceDelete = true; }
    if ($failure === 'duration') { CommonDBTM::$failUpdate = true; }
    if ($failure === 'history') { Log::$fail = true; }
    if ($failure === 'lock') { $DB->failLock = true; }
    if ($failure === 'commit') { $DB->failCommit = true; }
    if ($failure === 'row changed during delete') { $DB->skipTimerDelete = true; }
    expectDeleteRejection($input, 'Rollback: ' . $failure);
}

resetFixture();
addPeriod();
CommonDBTM::$records[PluginActualtimeConfig::class][1]['autoupdate_duration'] = false;
PluginActualtimeManualtimer::deleteTimer(TicketTask::class, 10, deleteFixtureInput());
check($DB->timers === [] && CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 1800, 'Manual task duration retained when automatic update is disabled');

foreach ([ProjectTask::class => Project::class, ChangeTask::class => Change::class, ProblemTask::class => Problem::class] as $type => $parentType) {
    resetFixture();
    if ($type !== ProjectTask::class) {
        CommonDBTM::$records[$parentType] = [1 => ['id' => 1, 'status' => 2]];
        CommonDBTM::$records[$type] = [10 => ['id' => 10, strtolower($parentType) . 's_id' => 1, 'users_id_tech' => 42, 'state' => 1, 'actiontime' => 0]];
    }
    addPeriod('2025-01-10 08:00:00', '2025-01-10 08:30:00', $type);
    $input = deleteFixtureInput();
    PluginActualtimeManualtimer::deleteTimer($type, 10, $input);
    $field = $type === ProjectTask::class ? 'effective_duration' : 'actiontime';
    check($DB->timers === [] && CommonDBTM::$records[$type][10][$field] === 0 && end(Log::$entries)['type'] === $parentType, 'Deletion and audit for ' . $type);
}

resetFixture();
seedRunningTimer(date('Y-m-d H:i:s', time() - 600));
$input = deleteFixtureInput();
expectDeleteRejection($input, 'Active timer must be paused before deleting');
ob_start();
(new PluginActualtimeSourcetimer())->modalForm(TicketTask::class, 10);
$html = ob_get_clean();
check(!str_contains($html, "name='preview_delete'") && str_contains($html, 'Pause the running timer'), 'Running entry explains how to enable deletion');
addPeriod();
$active = $DB->timers[0];
PluginActualtimeManualtimer::deleteTimer(TicketTask::class, 10, deleteFixtureInput(2));
check($DB->timers === [$active] && CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 900, 'Deleting a past segment preserves the running timer and recalculates native elapsed total');

if (!getenv('ACTUALTIME_GLPI_CORE')) {
    Session::$user = 42;
    $result = PluginActualtimeTask::pauseTimer(10, TicketTask::class, PluginActualtimeTask::WEB);
    check($result['type'] === 'info', 'Timer pauses normally after another segment deletion');
    PluginActualtimeManualtimer::deleteTimer(TicketTask::class, 10, deleteFixtureInput(1));
    check($DB->timers === [] && CommonDBTM::$records[TicketTask::class][10]['state'] === 1, 'Paused segment can be deleted without completing the task');
    $result = PluginActualtimeTask::startTimer(10, TicketTask::class, PluginActualtimeTask::WEB);
    check($result['type'] === 'info' && count($DB->timers) === 1 && $DB->timers[0]['actual_end'] === null, 'New native timer starts after all prior segments are deleted');
}

resetFixture();
addPeriod();
$preview = PluginActualtimeManualtimer::deletionPreview(TicketTask::class, 10, deleteFixtureInput());
$GLOBALS['actualtime_test_user_name'] = '<img src=x onerror="alert(1)"> & Técnico';
ob_start();
PluginActualtimeSourcetimer::deletionForm(TicketTask::class, 10, $preview['timer']);
$html = ob_get_clean();
check(str_contains($html, '2025-01-10 08:00:00') && str_contains($html, '2025-01-10 08:30:00') && str_contains($html, 'data-actualtime-delete-cancel'), 'Confirmation shows saved dates and provides cancel');
check(str_contains($html, 'original_actiontime') && str_contains($html, '_glpi_csrf_token') && str_contains($html, "name='confirm_delete'"), 'Confirmation submits snapshot, token and explicit action');
check(!str_contains($html, '<img') && str_contains($html, '&lt;img') && str_contains($html, '&amp; Técnico'), 'Confirmation escapes technician data');
check(substr_count($html, '<form ') === 1 && substr_count($html, '</form>') === 1, 'Confirmation form is balanced');
unset($GLOBALS['actualtime_test_user_name']);

echo "OK: $checks regression checks (creation and confirmed deletion; no real database).\n";
