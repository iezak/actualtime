<?php

/**
 * Earlier start takes precedence over previous closed periods in the same task.
 * Run: php tests/regression/overlap-edit.php
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
define('ACTUALTIME_FIXTURE_ONLY', true);
require __DIR__ . '/manualtimer.php';

function overlapInput($type = TicketTask::class): array {
    global $DB;
    $all = editFixtureInput();
    foreach ($DB->timers as $row) {
        if ($row['itemtype'] !== $type || (int)$row['items_id'] !== 10) {
            foreach ($all as &$values) { unset($values[$row['id']]); }
            unset($values);
        }
    }
    return $all;
}
function periodById(int $id): array {
    global $DB;
    foreach ($DB->timers as $row) { if ((int)$row['id'] === $id) { return $row; } }
    throw new RuntimeException('Period missing');
}
function overlapState(): array {
    global $DB;
    return [$DB->timers, $DB->sources, CommonDBTM::$records, Log::$entries];
}
function expectOverlapRejection(array $input, string $message): void {
    global $DB;
    $before = overlapState();
    rejects(fn() => PluginActualtimeManualtimer::edit(TicketTask::class, 10, $input), $message);
    check(overlapState() === $before && !$DB->inTransaction(), 'Atomic rejection: ' . $message);
}
function twoPeriods(): void {
    resetFixture();
    addPeriod();
    addPeriod('2025-01-10 08:30:00', '2025-01-10 09:00:00');
}

twoPeriods();
$input = overlapInput();
$input['actual_begin'][2] = '2025-01-10 08:15:00';
check(PluginActualtimeManualtimer::edit(TicketTask::class, 10, $input) === 2, 'Partial overlap changes both periods');
check(periodById(1)['actual_end'] === '2025-01-10 08:15:00' && periodById(1)['actual_actiontime'] === 900, 'Previous period ends at the new start');
check(periodById(2)['actual_begin'] === '2025-01-10 08:15:00' && periodById(2)['actual_actiontime'] === 2700, 'Edited period keeps its ID and end and takes the overwritten time');
check(PluginActualtimeTask::totalEndTime(10, TicketTask::class) === 3600 && CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 3600, 'Totals do not double count the overlap');
check(count($DB->sources) === 2 && periodById(1)['users_id'] === 42 && periodById(2)['users_id'] === 42, 'First-change snapshots and owners preserved');
check(str_contains(end(Log::$entries)['changes'][2], 'Automatically adjusted to meet timer #2.') && end(Log::$entries)['actor'] === 7, 'History identifies automatic shortening and actor');
$before = overlapState();
check(PluginActualtimeManualtimer::edit(TicketTask::class, 10, overlapInput()) === 0 && overlapState() === $before, 'Unchanged resubmission does not apply the adjustment twice');
expectOverlapRejection($input, 'Old submitted form cannot overwrite the corrected periods');

twoPeriods();
$input = overlapInput();
$input['actual_begin'][2] = '2025-01-10 08:00:00';
check(PluginActualtimeManualtimer::edit(TicketTask::class, 10, $input) === 2 && count($DB->timers) === 1, 'Fully covered period deleted');
check(periodById(2)['actual_actiontime'] === 3600 && count($DB->sources) === 1 && $DB->sources[0]['plugin_actualtime_tasks_id'] === 2, 'Selected period and its native snapshot remain');
check(str_contains(Log::$entries[2]['changes'][2], 'Timer #1 was deleted because it was covered by timer #2:') && str_contains(Log::$entries[2]['changes'][2], '1800 seconds'), 'Deletion history retains removed interval and cause');
check(CommonDBTM::$records[TicketTask::class][10]['state'] === 1, 'Automatic deletion does not complete the task');

resetFixture();
addPeriod('2025-01-10 07:00:00', '2025-01-10 08:00:00');
addPeriod('2025-01-10 08:00:00', '2025-01-10 08:30:00');
addPeriod('2025-01-10 08:30:00', '2025-01-10 09:00:00');
$input = overlapInput();
$input['actual_begin'][3] = '2025-01-10 07:30:00';
check(PluginActualtimeManualtimer::edit(TicketTask::class, 10, $input) === 3 && count($DB->timers) === 2, 'One save can delete a covered period and shorten another');
check(periodById(1)['actual_end'] === '2025-01-10 07:30:00' && periodById(3)['actual_begin'] === '2025-01-10 07:30:00', 'Remaining periods meet exactly');
check(PluginActualtimeTask::totalEndTime(10, TicketTask::class) === 7200, 'Multi-period replacement preserves the union duration');

twoPeriods();
$firstEdit = overlapInput();
$firstEdit['actual_begin'][1] = '2025-01-10 07:45:00';
PluginActualtimeManualtimer::edit(TicketTask::class, 10, $firstEdit);
$input = overlapInput();
$input['actual_begin'][2] = '2025-01-10 07:30:00';
PluginActualtimeManualtimer::edit(TicketTask::class, 10, $input);
check(count($DB->sources) === 1 && $DB->sources[0]['plugin_actualtime_tasks_id'] === 2, 'Deleting an already edited period also removes its snapshot');
check(str_contains(Log::$entries[3]['changes'][2], '2700 seconds'), 'Deletion records the latest saved duration of an edited period');

twoPeriods();
$input = overlapInput();
$input['actual_begin'][2] = '2025-01-10 08:15:00';
PluginActualtimeManualtimer::edit(TicketTask::class, 10, $input);
$firstSources = $DB->sources;
$input = overlapInput();
$input['actual_begin'][2] = '2025-01-10 08:05:00';
PluginActualtimeManualtimer::edit(TicketTask::class, 10, $input);
check(periodById(1)['actual_actiontime'] === 300 && $DB->sources === $firstSources, 'Repeated shortening preserves first-change snapshots');
resetFixture();
addPeriod('2025-01-10 23:30:00', '2025-01-11 00:00:00');
addPeriod('2025-01-11 00:00:00', '2025-01-11 00:30:00');
$input = overlapInput();
$input['actual_begin'][2] = '2025-01-10 23:45:00';
PluginActualtimeManualtimer::edit(TicketTask::class, 10, $input);
check(periodById(1)['actual_end'] === '2025-01-10 23:45:00' && periodById(2)['actual_actiontime'] === 2700, 'Earlier-start replacement works across midnight');

foreach (['changed previous', 'unseen previous', 'two explicit changes', 'future overlap', 'end extension', 'future date', 'permission'] as $case) {
    twoPeriods();
    $input = overlapInput();
    $input['actual_begin'][2] = '2025-01-10 08:15:00';
    if ($case === 'changed previous') { $DB->timers[0]['actual_begin'] = '2025-01-10 07:45:00'; }
    if ($case === 'unseen previous') {
        foreach ($input as &$values) { unset($values[1]); } unset($values);
    }
    if ($case === 'two explicit changes') { $input['actual_begin'][1] = '2025-01-10 07:45:00'; }
    if ($case === 'future overlap') {
        addPeriod('2025-01-10 09:00:00', '2025-01-10 09:30:00');
        $input['actual_end'][2] = '2025-01-10 09:15:00';
    }
    if ($case === 'end extension') {
        $input = overlapInput();
        $input['actual_end'][1] = '2025-01-10 08:45:00';
    }
    if ($case === 'future date') { $input['actual_end'][2] = '2099-01-10 09:00:00'; }
    if ($case === 'permission') { Session::$right = false; }
    expectOverlapRejection($input, $case);
}

foreach (['delete', 'source delete', 'snapshot', 'update', 'history', 'duration', 'commit', 'lock'] as $failure) {
    resetFixture();
    addPeriod('2025-01-10 07:00:00', '2025-01-10 08:00:00');
    addPeriod('2025-01-10 08:00:00', '2025-01-10 08:30:00');
    addPeriod('2025-01-10 08:30:00', '2025-01-10 09:00:00');
    $input = overlapInput();
    $input['actual_begin'][3] = '2025-01-10 07:30:00';
    if ($failure === 'delete') { $DB->failTimerDelete = true; }
    if ($failure === 'source delete') { $DB->failSourceDelete = true; }
    if ($failure === 'snapshot') { $DB->failSource = true; }
    if ($failure === 'update') { $DB->failTimerUpdate = true; }
    if ($failure === 'history') { Log::$fail = true; }
    if ($failure === 'duration') { CommonDBTM::$failUpdate = true; }
    if ($failure === 'commit') { $DB->failCommit = true; }
    if ($failure === 'lock') { $DB->failLock = true; }
    expectOverlapRejection($input, 'Storage failure: ' . $failure);
}

twoPeriods();
$DB->insert(PluginActualtimeTask::getTable(), ['itemtype' => TicketTask::class, 'items_id' => 11, 'users_id' => 42,
    'actual_begin' => '2025-01-10 08:00:00', 'actual_end' => null, 'actual_actiontime' => 0]);
$DB->insert(PluginActualtimeTask::getTable(), ['itemtype' => ProjectTask::class, 'items_id' => 10, 'users_id' => 42,
    'actual_begin' => '2025-01-10 08:00:00', 'actual_end' => '2025-01-10 09:00:00', 'actual_actiontime' => 3600]);
$other = [periodById(3), periodById(4)];
$input = overlapInput();
$input['actual_begin'][2] = '2025-01-10 08:00:00';
PluginActualtimeManualtimer::edit(TicketTask::class, 10, $input);
check([periodById(3), periodById(4)] === $other, 'Other task, running timer and item type remain untouched');

resetFixture();
addPeriod();
seedRunningTimer('2025-01-10 08:30:00');
$input = overlapInput();
$input['actual_begin'][2] = '2025-01-10 08:00:00';
PluginActualtimeManualtimer::edit(TicketTask::class, 10, $input);
check(count($DB->timers) === 1 && periodById(2)['actual_end'] === null && periodById(2)['actual_actiontime'] === 0, 'Active selected timer remains running after covering the previous period');

twoPeriods();
$DB->timers[0]['users_id'] = 99;
CommonDBTM::$records[PluginActualtimeConfig::class][1]['autoupdate_duration'] = false;
$planned = CommonDBTM::$records[TicketTask::class][10]['actiontime'];
$input = overlapInput();
$input['actual_begin'][2] = '2025-01-10 08:15:00';
PluginActualtimeManualtimer::edit(TicketTask::class, 10, $input);
check(periodById(1)['users_id'] === 99 && periodById(2)['users_id'] === 42, 'Partial shortening keeps each period technician');
check(CommonDBTM::$records[TicketTask::class][10]['actiontime'] === $planned, 'Disabled automatic task duration is respected');
rejects(fn() => addPeriod('2025-01-10 08:00:00', '2025-01-10 09:00:00'), 'Manual creation still rejects duplicates');

foreach ([ChangeTask::class => Change::class, ProblemTask::class => Problem::class, ProjectTask::class => Project::class] as $type => $parentType) {
    resetFixture();
    if ($type !== ProjectTask::class) {
        CommonDBTM::$records[$parentType][1] = ['id' => 1, 'status' => 2, 'is_deleted' => 0];
        CommonDBTM::$records[$type][10] = ['id' => 10, strtolower($parentType) . 's_id' => 1, 'users_id_tech' => 42, 'state' => 1, 'actiontime' => 0];
    }
    addPeriod('2025-01-10 08:00:00', '2025-01-10 08:30:00', $type);
    addPeriod('2025-01-10 08:30:00', '2025-01-10 09:00:00', $type);
    $input = overlapInput($type);
    $input['actual_begin'][2] = '2025-01-10 08:00:00';
    PluginActualtimeManualtimer::edit($type, 10, $input);
    check(count($DB->timers) === 1 && periodById(2)['actual_actiontime'] === 3600
        && end(Log::$entries)['type'] === $parentType, 'Replacement and audit work for ' . $type);
}
echo "OK: $checks earlier-start overwrite checks (GLPI adapters, no real database).\n";
