<?php

/**
 * Edit completed/running timers and exercise native controls after correction.
 * Run: php tests/regression/edit-timers.php
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

define('ACTUALTIME_EXTENDED_TEST', true);
require __DIR__ . '/manualtimer.php';

function editPeriod(array $input, $type = TicketTask::class): int {
    return PluginActualtimeManualtimer::edit($type, 10, $input);
}
function persistedState(): array {
    global $DB;
    return [$DB->timers, $DB->sources, CommonDBTM::$records, Log::$entries];
}
function expectEditRejection(array $input, string $message): void {
    $before = persistedState();
    rejects(fn() => editPeriod($input), $message);
    check(persistedState() === $before, 'No partial changes: ' . $message);
}

resetFixture();
addPeriod();
$input = editFixtureInput();
$input['actual_begin'][1] = '2025-01-10 07:45:00';
check(editPeriod($input) === 1, 'Start correction saved');
check($DB->timers[0]['actual_actiontime'] === 2700, 'Start correction produces 45 minutes');
check(CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 2700, 'Task duration recalculated');
check(CommonDBTM::$records[TicketTask::class][10]['state'] === 1 && $DB->timers[0]['users_id'] === 42, 'Editing preserves owner and task state');
check($DB->timers[0]['is_modified'] === 1 && count($DB->sources) === 1, 'Native modification marker and snapshot retained');
$firstSource = $DB->sources[0];
check($firstSource['source_end'] === '2025-01-10 08:30:00' && $firstSource['source_actiontime'] === 1800, 'Snapshot preserves original completed interval');
$history = end(Log::$entries);
check($history['actor'] === 7 && $history['id'] === 1, 'Edit audited against parent and current operator');
check(str_contains($history['changes'][2], '2025-01-10 08:00:00 / 2025-01-10 08:30:00 -> 2025-01-10 07:45:00 / 2025-01-10 08:30:00'), 'Audit records before and after timestamps');
$input = editFixtureInput();
$input['actual_end'][1] = '2025-01-10 08:45:00';
check(editPeriod($input) === 1 && $DB->timers[0]['actual_actiontime'] === 3600, 'End correction produces one hour');
check($DB->sources === [$firstSource], 'Repeated edits keep the first native snapshot');
$before = persistedState();
check(editPeriod(editFixtureInput()) === 0 && persistedState() === $before, 'Unchanged form has no writes or duplicate history');

resetFixture();
addPeriod();
addPeriod('2025-01-10 09:00:00', '2025-01-10 09:30:00');
$input = editFixtureInput();
$input['actual_begin'][1] = '2025-01-10 08:15:00';
$input['actual_end'][1] = '2025-01-10 09:15:00';
expectEditRejection($input, 'Overlap rejects the whole batch');
$input['actual_begin'][2] = '2025-01-10 09:15:00';
check(editPeriod($input) === 2, 'Batch validates the final intervals, allowing adjacent boundaries');
check(PluginActualtimeTask::totalEndTime(10, TicketTask::class) === 4500, 'Batch totals all corrected segments');

foreach (['reversed', 'empty start', 'empty end', 'future', 'invalid date', 'other task', 'other type', 'unknown timer', 'old form'] as $case) {
    resetFixture();
    addPeriod();
    $input = editFixtureInput();
    $input['actual_begin'][1] = '2025-01-10 07:45:00';
    if ($case === 'reversed') { $input['actual_end'][1] = '2025-01-10 07:00:00'; }
    if ($case === 'empty start') { $input['actual_begin'][1] = ''; }
    if ($case === 'empty end') { $input['actual_end'][1] = ''; }
    if ($case === 'future') { $input['actual_end'][1] = '2099-01-10 08:30:00'; }
    if ($case === 'invalid date') { $input['actual_begin'][1] = '2025-02-30 08:00:00'; }
    if ($case === 'other task') { $DB->timers[0]['items_id'] = 11; }
    if ($case === 'other type') { $DB->timers[0]['itemtype'] = ProjectTask::class; }
    if ($case === 'unknown timer') { $input['actual_begin'][99] = '2025-01-10 08:00:00'; }
    if ($case === 'old form') { unset($input['original_begin']); }
    expectEditRejection($input, $case);
}

resetFixture();
addPeriod();
addPeriod('2025-01-10 09:00:00', '2025-01-10 09:30:00');
$input = editFixtureInput();
$input['actual_begin'][1] = '2025-01-10 07:45:00';
$input['actual_end'][2] = '2025-01-10 08:00:00';
expectEditRejection($input, 'Invalid second row also rolls back the valid first row');

resetFixture();
addPeriod();
$input = editFixtureInput();
$DB->timers[0]['actual_begin'] = '2025-01-10 07:30:00';
$DB->timers[0]['actual_actiontime'] = 3600;
$input['actual_begin'][1] = '2025-01-10 07:45:00';
expectEditRejection($input, 'Changed row cannot overwrite another tab');
$input['actual_begin'][1] = $input['original_begin'][1];
$before = persistedState();
check(editPeriod($input) === 0 && persistedState() === $before, 'Untouched stale row leaves the latest saved interval intact');

resetFixture();
addPeriod();
addPeriod('2025-01-10 09:00:00', '2025-01-10 09:30:00');
$input = editFixtureInput();
$DB->timers[1]['actual_begin'] = '2025-01-10 08:45:00';
$input['actual_end'][1] = '2025-01-10 08:50:00';
expectEditRejection($input, 'Overlap uses the latest values of an untouched stale row');

resetFixture();
seedRunningTimer(date('Y-m-d H:i:s', time() - 1200));
$input = editFixtureInput();
$corrected = date('Y-m-d H:i:s', time() - 1800);
$input['actual_begin'][1] = $corrected;
check(editPeriod($input) === 1, 'Active timer start can be corrected');
check($DB->timers[0]['actual_begin'] === $corrected && $DB->timers[0]['actual_end'] === null && $DB->timers[0]['actual_actiontime'] === 0, 'Correction keeps the timer running');
check(CommonDBTM::$records[TicketTask::class][10]['state'] === 1 && CommonDBTM::$records[TicketTask::class][10]['actiontime'] >= 1800, 'Native task total reflects corrected elapsed time without completing the task');
check($DB->sources[0]['source_end'] === null && $DB->sources[0]['source_actiontime'] >= 1200, 'Snapshot records that the timer was running');
$input = editFixtureInput();
$input['actual_begin'][1] = '2099-01-10 08:00:00';
expectEditRejection($input, 'Active start cannot be in the future');
$input = editFixtureInput();
$input['actual_end'][1] = date('Y-m-d H:i:s');
expectEditRejection($input, 'Editor cannot end a running timer');

resetFixture();
addPeriod();
seedRunningTimer('2025-01-10 09:00:00');
$input = editFixtureInput();
$input['actual_begin'][2] = '2025-01-10 08:15:00';
check(editPeriod($input) === 2 && $DB->timers[0]['actual_end'] === '2025-01-10 08:15:00', 'Active start shortens the earlier closed period');
$input = editFixtureInput();
$input['actual_begin'][2] = '2025-01-10 08:30:00';
check(editPeriod($input) === 1 && $DB->timers[1]['actual_end'] === null, 'Active start may touch the end of the previous period');

foreach (['source', 'timer update', 'duration', 'history', 'lock'] as $failure) {
    resetFixture();
    addPeriod();
    $input = editFixtureInput();
    $input['actual_begin'][1] = '2025-01-10 07:45:00';
    if ($failure === 'source') { $DB->failSource = true; }
    if ($failure === 'timer update') { $DB->failTimerUpdate = true; }
    if ($failure === 'duration') { CommonDBTM::$failUpdate = true; }
    if ($failure === 'history') { Log::$fail = true; }
    if ($failure === 'lock') { $DB->failLock = true; }
    expectEditRejection($input, 'Storage failure: ' . $failure);
    check(!$DB->inTransaction(), 'Failed edit releases its transaction');
}

foreach (['profile', 'task access', 'parent access', 'closed parent', 'inactive plugin'] as $case) {
    resetFixture();
    addPeriod();
    $input = editFixtureInput();
    $input['actual_begin'][1] = '2025-01-10 07:45:00';
    if ($case === 'profile') { Session::$right = false; }
    if ($case === 'task access') { CommonDBTM::$denied = ['TicketTask:10']; }
    if ($case === 'parent access') { CommonDBTM::$denied = ['Ticket:1']; }
    if ($case === 'closed parent') { CommonDBTM::$records[Ticket::class][1]['status'] = 6; }
    if ($case === 'inactive plugin') { Plugin::$active = false; }
    expectEditRejection($input, 'Authorization: ' . $case);
}

resetFixture();
addPeriod('2025-01-10 08:00:00', '2025-01-10 08:30:00', ProjectTask::class);
$input = editFixtureInput();
$input['actual_end'][1] = '2025-01-10 08:31:01';
editPeriod($input, ProjectTask::class);
check($DB->timers[0]['actual_actiontime'] === 1861 && CommonDBTM::$records[ProjectTask::class][10]['effective_duration'] === 2700, 'Project duration respects seconds and GLPI rounding');
check(end(Log::$entries)['type'] === Project::class, 'Project edit audited on project');
resetFixture();
addPeriod();
CommonDBTM::$records[PluginActualtimeConfig::class][1]['autoupdate_duration'] = false;
$input = editFixtureInput();
$input['actual_end'][1] = '2025-01-10 08:45:00';
editPeriod($input);
check(CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 1800 && $DB->timers[0]['actual_actiontime'] === 2700, 'Disabled automatic duration is respected on edit');

resetFixture();
addPeriod();
$input = editFixtureInput();
$input['actual_begin'][1] = '2025-01-10 07:45:00';
$_SESSION['plugin_actualtime_edittimers'][TicketTask::class][10] = PluginActualtimeManualtimer::formValues($input);
ob_start();
(new PluginActualtimeSourcetimer())->modalForm(TicketTask::class, 10);
$html = ob_get_clean();
check(str_contains($html, '2025-01-10 07:45:00') && str_contains($html, 'original_begin[1]'), 'Failed form retains proposed dates and original snapshot');
check(!isset($_SESSION['plugin_actualtime_edittimers'][TicketTask::class][10]), 'Edit form state consumed once');
$_SESSION['plugin_actualtime_edittimers'][TicketTask::class][10] = PluginActualtimeManualtimer::formValues($input);
$DB->timers[0]['actual_begin'] = '2025-01-10 07:30:00';
ob_start();
(new PluginActualtimeSourcetimer())->modalForm(TicketTask::class, 10);
$html = ob_get_clean();
check(str_contains($html, 'This timer changed.') && str_contains($html, '2025-01-10 07:30:00') && !str_contains($html, '2025-01-10 07:45:00'), 'Stale form displays current dates with warning');
$clean = PluginActualtimeManualtimer::formValues(['actual_begin' => [1 => [], '<script>' => 'x', -1 => 'x', 2 => '2025-01-10T08:00']]);
check($clean['actual_begin'] === [1 => '', 2 => '2025-01-10 08:00:00'], 'Only valid date values and positive IDs are retained');
resetFixture();
seedRunningTimer();
ob_start();
(new PluginActualtimeSourcetimer())->modalForm(TicketTask::class, 10);
$html = ob_get_clean();
check(substr_count($html, 'data-input') === 3 && str_contains($html, 'In progress') && str_contains($html, 'original_end[1]'), 'Running row has one editable date, original calendar and empty end snapshot');

// Native control rendering is covered here with lightweight Html adapters.
// The browser test separately uses the real GLPI Html class and calendar scripts.
if (!getenv('ACTUALTIME_GLPI_CORE')) {
    resetFixture();
    seedRunningTimer(date('Y-m-d H:i:s', time() - 1200));
    $input = editFixtureInput();
    $input['actual_begin'][1] = date('Y-m-d H:i:s', time() - 1800);
    editPeriod($input);
    Session::$user = 42;
    $locks = count($DB->lockQueries);
    $result = PluginActualtimeTask::pauseTimer(10, TicketTask::class, PluginActualtimeTask::WEB);
    check($result['type'] === 'info' && count($DB->lockQueries) === $locks + 1, 'Native pause takes the shared task lock');
    check($DB->timers[0]['actual_actiontime'] >= 1800 && $DB->timers[0]['actual_actiontime'] < 1810, 'Pause uses the corrected active start');
    check(CommonDBTM::$records[TicketTask::class][10]['state'] === 1, 'Pause preserves the task state after a correction');
    $result = PluginActualtimeTask::startTimer(10, TicketTask::class, PluginActualtimeTask::WEB);
    check($result['type'] === 'info' && count($DB->timers) === 2 && $DB->timers[1]['actual_end'] === null, 'Native resume starts a fresh segment');
    $result = PluginActualtimeTask::stopTimer(10, TicketTask::class, PluginActualtimeTask::WEB);
    check($result['type'] === 'info' && CommonDBTM::$records[TicketTask::class][10]['state'] === 2, 'Native stop still completes the task');
    check($DB->timers[1]['actual_end'] !== null && !$DB->inTransaction(), 'Native stop persists segment and releases transaction');
    $DB->failLock = true;
    $before = persistedState();
    $result = PluginActualtimeTask::startTimer(10, TicketTask::class, PluginActualtimeTask::WEB);
    check($result['type'] === 'warning' && persistedState() === $before, 'Lock failure leaves native controls unchanged');
}

resetFixture();
$DB->beginTransaction();
$commits = $DB->commitCount;
check(PluginActualtimeManualtimer::withTaskLock(TicketTask::class, 10, fn() => 123) === 123, 'Task lock can participate in a caller transaction');
check($DB->inTransaction() && $DB->commitCount === $commits && $DB->beginCount === 1, 'Nested lock does not commit or reopen the caller transaction');
$DB->rollBack();

echo "OK: $checks regression checks (creation, editing and native controls; no real database).\n";
