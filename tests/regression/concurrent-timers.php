<?php

/**
 * Simultaneous task controls, scoped runtime data and browser fixtures.
 * Run: php tests/regression/concurrent-timers.php
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

define('ACTUALTIME_EXTENDED_TEST', true);
if (($argv[1] ?? '') === '--fixture') {
    define('ACTUALTIME_FIXTURE_ONLY', true);
}
require __DIR__ . '/manualtimer.php';

function concurrentFixture(): void {
    resetFixture();
    Session::$user = 42;
    CommonDBTM::$records[PluginActualtimeConfig::class][1] += [
        'showtimerpopup' => 1, 'showtimerinbox' => 1, 'autoopenrunning' => 0, 'displayinfofor' => 0,
    ];
    CommonDBTM::$records[Ticket::class][2] = ['id' => 2, 'status' => 2, 'is_deleted' => 0];
    foreach ([1, 2] as $id) {
        CommonDBTM::$records[TicketTask::class][$id] = [
            'id' => $id, 'tickets_id' => $id, 'users_id_tech' => 42, 'state' => 1, 'actiontime' => 0,
        ];
    }
    foreach ([ChangeTask::class => Change::class, ProblemTask::class => Problem::class] as $type => $parent) {
        CommonDBTM::$records[$parent][1] = ['id' => 1, 'status' => 2, 'is_deleted' => 0];
        CommonDBTM::$records[$type][1] = ['id' => 1, strtolower($parent) . 's_id' => 1, 'users_id_tech' => 42, 'state' => 1, 'actiontime' => 0];
    }
    CommonDBTM::$records[ProjectTask::class][1] = ['id' => 1, 'projects_id' => 1, 'projectstates_id' => 1, 'effective_duration' => 0];
}
function timerRow($task, $type = TicketTask::class): array {
    global $DB;
    foreach (array_reverse($DB->timers) as $row) {
        if ($row['items_id'] == $task && $row['itemtype'] === $type) { return $row; }
    }
    throw new RuntimeException('Fixture timer missing');
}
function startTask($id = 1, $type = TicketTask::class): array {
    return PluginActualtimeTask::startTimer($id, $type, PluginActualtimeTask::WEB);
}
function concurrentState(): array {
    global $DB;
    return [$DB->timers, $DB->sources, CommonDBTM::$records, Log::$entries];
}
function renderRuntimeTask($type, $id): void {
    ['task' => $task] = PluginActualtimeRuntime::taskContext($type, $id);
    $stateField = $type === ProjectTask::class ? 'projectstates_id' : 'state';
    echo "<div data-itemtype='$type' data-items-id='$id'><form method='post' action='/front/" . strtolower($type) . ".form.php'>";
    echo "<input type='hidden' name='id' value='$id'><select name='$stateField' data-track-changes='true'>";
    foreach ([1, 2] as $value) {
        echo "<option value='$value'" . ($value == $task->fields[$stateField] ? ' selected' : '') . ">$value</option>";
    }
    echo "</select><select name='actiontime' data-track-changes='true'><option value='0'>0</option><option value='900'>900</option></select>";
    echo "<span class='state state_1'></span>";
    PluginActualtimeTask::postForm(['item' => $task]);
    echo "</form></div>";
}

if (($argv[1] ?? '') === '--fixture') {
    concurrentFixture();
    $mode = $argv[2] ?? 'footer';
    $input = json_decode($argv[3] ?? '{}', true);
    if (!empty($argv[4])) {
        [$DB, CommonDBTM::$records, Log::$entries] = unserialize(base64_decode($argv[4]), ['allowed_classes' => [MemoryDB::class]]);
    }
    switch ($mode) {
        case 'action':
            $method = ['start' => 'startTimer', 'pause' => 'pauseTimer', 'end' => 'stopTimer'][$input['action']];
            $response = PluginActualtimeTask::$method($input['task_id'], $input['itemtype'], PluginActualtimeTask::WEB);
            break;
        case 'footer':
            $response = PluginActualtimeRuntime::footerData($input['watch'] ?? []);
            break;
        case 'form':
            ob_start();
            renderRuntimeTask($input['itemtype'], (int)$input['task_id']);
            $response = ob_get_clean();
            break;
        case 'page':
            ob_start();
            echo '<!doctype html><html><head><meta charset="utf-8"><script src="/assets/jquery.js"></script></head><body>';
            foreach ([[TicketTask::class, 1], [TicketTask::class, 2], [ProjectTask::class, 1]] as [$type, $id]) {
                renderRuntimeTask($type, $id);
            }
            echo '<form id="unrelated"><select name="state" data-track-changes="keep"><option value="9">9</option></select></form>';
            echo '<script src="/assets/bootstrap-adapter.js"></script><script src="/assets/runtime.js"></script></body></html>';
            $response = ob_get_clean();
            break;
        case 'dump':
            $response = ['timers' => $DB->timers, 'records' => CommonDBTM::$records];
            break;
        case 'config':
            CommonDBTM::$records[PluginActualtimeConfig::class][1]['showtimerpopup'] = (int)$input['show_popup'];
            $response = true;
            break;
        default:
            throw new RuntimeException('Invalid fixture mode');
    }
    echo json_encode(['response' => $response, 'state' => base64_encode(serialize([$DB, CommonDBTM::$records, Log::$entries]))]);
    exit;
}

concurrentFixture();
$a = startTask(1);
$b = startTask(2);
check($a['type'] === 'info' && $b['type'] === 'info' && count($DB->timers) === 2, 'User starts two different tasks together');
check($a['parent_id'] === 1 && $b['parent_id'] === 2 && $b['runtime']['parent_id'] === 2, 'Each start reports the selected task parent');
$before = concurrentState();
check(startTask(1)['type'] === 'warning' && concurrentState() === $before, 'Duplicate start for the same task is rejected');
check(!PluginActualtimeTask::checkUserFree(42), 'Legacy busy lookup still reports active work without blocking another task');
$DB->timers[0]['actual_begin'] = date('Y-m-d H:i:s', time() - 600);
$DB->timers[1]['actual_begin'] = date('Y-m-d H:i:s', time() - 1200);
check(PluginActualtimeTask::totalEndTime(1, TicketTask::class) >= 600 && PluginActualtimeTask::totalEndTime(2, TicketTask::class) >= 1200, 'Each task has its own elapsed total');
$other = timerRow(2);
$result = PluginActualtimeTask::pauseTimer(1, TicketTask::class, PluginActualtimeTask::WEB);
check($result['type'] === 'info' && !PluginActualtimeTask::checkTimerActive(1, TicketTask::class), 'Pause affects the selected task');
check(timerRow(2) === $other && PluginActualtimeTask::checkTimerActive(2, TicketTask::class), 'Pause leaves the other timer unchanged');
check($result['time'] >= 600 && $result['time'] < 610 && CommonDBTM::$records[TicketTask::class][1]['state'] === 1, 'Pause duration and task state preserved');
check(startTask(1)['type'] === 'info' && count($DB->timers) === 3, 'Resume opens only a new segment for the paused task');
$other = timerRow(1);
$result = PluginActualtimeTask::stopTimer(2, TicketTask::class, PluginActualtimeTask::WEB);
check($result['type'] === 'info' && CommonDBTM::$records[TicketTask::class][2]['state'] === 2, 'End completes only the selected task');
check(timerRow(1) === $other && CommonDBTM::$records[TicketTask::class][1]['state'] === 1, 'End leaves the other task running and unfinished');
check(CommonDBTM::$records[TicketTask::class][2]['actiontime'] >= 1200 && CommonDBTM::$records[TicketTask::class][1]['actiontime'] === 0, 'Only selected duration updated on native end');
$footer = PluginActualtimeRuntime::footerData(['TicketTask:1', 'TicketTask:2']);
check(count($footer['active']) === 1 && count($footer['states']) === 2, 'Footer returns active work and watched paused/completed task states');
check($footer['active'][0]['task_id'] === 1 && $footer['states'][1]['done'], 'Footer retains the other timer and reports the completed task');

concurrentFixture();
foreach ([TicketTask::class, ProjectTask::class, ChangeTask::class, ProblemTask::class] as $type) {
    check(startTask(1, $type)['type'] === 'info', 'Concurrent type supported: ' . $type);
}
check(count(PluginActualtimeRuntime::runningTimers()) === 4, 'Equal numeric IDs in four types remain four separate timers');
$other = timerRow(1, ProjectTask::class);
PluginActualtimeTask::pauseTimer(1, TicketTask::class, PluginActualtimeTask::WEB);
check(timerRow(1, ProjectTask::class) === $other && count(PluginActualtimeRuntime::runningTimers()) === 3, 'Equal-ID task of another type stays active');
Session::$user = 99;
$before = concurrentState();
check(PluginActualtimeTask::pauseTimer(1, ProjectTask::class, PluginActualtimeTask::WEB)['type'] === 'warning', 'Another user cannot pause an active project timer');
check(PluginActualtimeTask::stopTimer(1, ProjectTask::class, PluginActualtimeTask::WEB)['type'] === 'warning' && concurrentState() === $before, 'Another user cannot end the timer or change its task');
check(PluginActualtimeRuntime::runningTimers() === [], 'Footer lists only the current user');
Session::$user = 42;
CommonDBTM::$denied = ['ProjectTask:1'];
check(count(PluginActualtimeRuntime::runningTimers()) === 2, 'Private/inaccessible task excluded from footer');
CommonDBTM::$denied = ['Change:1'];
check(count(PluginActualtimeRuntime::runningTimers()) === 2, 'Inaccessible parent entity excluded from footer');
CommonDBTM::$denied = [];
CommonDBTM::$deniedUpdate = ['ProblemTask:1'];
$state = PluginActualtimeRuntime::taskState(ProblemTask::class, 1);
check($state['active'] && !$state['can_control'], 'Read-only access can display time without granting controls');
$before = concurrentState();
check(PluginActualtimeTask::stopTimer(1, ProblemTask::class, PluginActualtimeTask::WEB)['type'] === 'warning' && concurrentState() === $before, 'Update right enforced on direct end request');
CommonDBTM::$deniedUpdate = [];
Session::$right = false;
check(startTask(2)['type'] === 'info', 'Starting own task does not require the separate Modify timers permission');

foreach (['assignment', 'task permission', 'parent permission', 'solved', 'deleted parent', 'scheduled'] as $case) {
    concurrentFixture();
    startTask(2);
    if ($case === 'assignment') { CommonDBTM::$records[TicketTask::class][1]['users_id_tech'] = 99; }
    if ($case === 'task permission') { CommonDBTM::$denied = ['TicketTask:1']; }
    if ($case === 'parent permission') { CommonDBTM::$denied = ['Ticket:1']; }
    if ($case === 'solved') { CommonDBTM::$records[Ticket::class][1]['status'] = 5; }
    if ($case === 'deleted parent') { CommonDBTM::$records[Ticket::class][1]['is_deleted'] = 1; }
    if ($case === 'scheduled') {
        CommonDBTM::$records[PluginActualtimeConfig::class][1]['planned_task'] = 1;
        CommonDBTM::$records[TicketTask::class][1]['begin'] = '2099-01-01 08:00:00';
    }
    $before = concurrentState();
    check(startTask(1)['type'] === 'warning' && concurrentState() === $before, 'Start validation with another timer active: ' . $case);
}

foreach (['insert', 'pause write', 'end write', 'duration', 'commit'] as $failure) {
    concurrentFixture();
    startTask(2);
    if ($failure !== 'insert') { startTask(1); }
    $before = concurrentState();
    if ($failure === 'insert') { $DB->failInsert = true; $result = startTask(1); }
    if ($failure === 'pause write') { $DB->failTimerUpdate = true; $result = PluginActualtimeTask::pauseTimer(1, TicketTask::class, PluginActualtimeTask::WEB); }
    if ($failure === 'end write') { $DB->failTimerUpdate = true; $result = PluginActualtimeTask::stopTimer(1, TicketTask::class, PluginActualtimeTask::WEB); }
    if ($failure === 'duration') { CommonDBTM::$failUpdate = true; $result = PluginActualtimeTask::stopTimer(1, TicketTask::class, PluginActualtimeTask::WEB); }
    if ($failure === 'commit') { $DB->failCommit = true; $result = PluginActualtimeTask::stopTimer(1, TicketTask::class, PluginActualtimeTask::WEB); }
    check($result['type'] === 'warning' && concurrentState() === $before && !$DB->inTransaction(), 'Atomic native action and other timer preserved: ' . $failure);
}

concurrentFixture();
startTask(1);
startTask(2);
$other = timerRow(2);
Session::$user = 99;
PluginActualtimeTask::stopTimer(1, TicketTask::class, PluginActualtimeTask::AUTO);
check(timerRow(2) === $other && CommonDBTM::$records[TicketTask::class][1]['state'] === 2, 'Trusted automatic completion remains scoped to one task');
concurrentFixture();
startTask(1);
startTask(2);
$other = timerRow(2);
Session::$user = 99;
$task = new TicketTask();
$task->getFromDB(1);
$task->input = ['id' => 1, 'users_id_tech' => 99];
PluginActualtimeTask::preUpdate($task);
$task->update($task->input);
check(timerRow(1)['actual_end'] !== null && timerRow(2) === $other, 'Reassignment hook pauses only the old technician selected timer');
Session::$user = 42;
check(count(PluginActualtimeRuntime::runningTimers()) === 1 && !PluginActualtimeRuntime::taskState(TicketTask::class, 1)['can_control'], 'Old technician sees remaining work and cannot control reassigned task');
concurrentFixture();
startTask(1);
CommonDBTM::$records[PluginActualtimeConfig::class][1]['showtimerpopup'] = 0;
$footer = PluginActualtimeRuntime::footerData(['TicketTask:2', 'Untrusted:1', ['x'], 'TicketTask:0']);
check(!$footer['show_popup'] && count($footer['active']) === 1 && count($footer['states']) === 2, 'Popup setting does not stop runtime snapshots or permit invalid watch targets');
CommonDBTM::$denied = ['TicketTask:1'];
check(PluginActualtimeRuntime::footerData(['TicketTask:1'])['active'] === [], 'Changed permissions remove active popup data');
concurrentFixture();
startTask(1);
startTask(1, ProjectTask::class);
$other = timerRow(1);
$result = PluginActualtimeTask::stopTimer(1, ProjectTask::class, PluginActualtimeTask::WEB);
check($result['type'] === 'info' && timerRow(1) === $other, 'Stopping a same-ID project timer leaves the ticket timer unchanged');
check(!isset(CommonDBTM::$records[ProjectTask::class][1]['state']) && CommonDBTM::$records[ProjectTask::class][1]['projectstates_id'] === 1, 'Project keeps its configured state without writing an ITIL-only state field');
concurrentFixture();
ob_start();
renderRuntimeTask(TicketTask::class, 1);
renderRuntimeTask(ProjectTask::class, 1);
$html = ob_get_clean();
check(str_contains($html, 'actualtime_timer_TicketTask_1_') && str_contains($html, 'actualtime_timer_ProjectTask_1_'), 'PHP renders type-scoped counter IDs');
check(str_contains($html, 'data-actualtime-task=') && str_contains($html, 'fillCurrentTime(1, "ProjectTask",'), 'Dynamic forms expose exact watch target and typed counter calls');
echo "OK: $checks regression checks (creation and simultaneous task controls; no real database).\n";
