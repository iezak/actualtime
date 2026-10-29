<?php

/**
 * Native timer lifecycle notifications, independent of consumers.
 * php tests/regression/timer-events.php
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

define('ACTUALTIME_FIXTURE_ONLY', true);
require __DIR__ . '/manualtimer.php';

function eventFixture(): void {
    resetFixture();
    Session::$user = 42;
    CommonDBTM::$records[PluginActualtimeConfig::class][1] += [
        'showtimerpopup' => 1, 'showtimerinbox' => 1, 'autoopenrunning' => 0, 'displayinfofor' => 0,
    ];
}
function nativeAction(string $action): array {
    $method = $action . 'Timer';
    return PluginActualtimeTask::$method(10, TicketTask::class, PluginActualtimeTask::WEB);
}

eventFixture();
$result = nativeAction('start');
check($result['type'] === 'info' && count(Plugin::$events) === 1, 'One event for a successful start');
check(Plugin::$events[0] === [
    'name' => 'actualtime_timer_state_changed',
    'data' => ['action' => 'start', 'itemtype' => 'TicketTask', 'items_id' => 10,
        'timer_id' => $result['timer_id'], 'origin' => PluginActualtimeTask::WEB],
    'in_transaction' => false,
], 'Exact task, session and origin delivered after the owned transaction commits');
nativeAction('start');
check(count(Plugin::$events) === 1, 'Duplicate start emits no event');
PluginActualtimeRuntime::footerData(['TicketTask:10']);
PluginActualtimeTask::totalEndTime(10, TicketTask::class);
check(count(Plugin::$events) === 1, 'Read-only snapshots and counters emit no events');
nativeAction('pause');
check(Plugin::$events[1]['data']['action'] === 'pause' && $DB->timers[0]['actual_end'] !== null, 'Pause is emitted with the saved closed period');
nativeAction('pause');
check(count(Plugin::$events) === 2, 'Invalid second pause emits no event');
nativeAction('start');
check(Plugin::$events[2]['data']['action'] === 'start' && count($DB->timers) === 2, 'Resume emits start for its new segment');
nativeAction('stop');
check(Plugin::$events[3]['data']['action'] === 'stop' && CommonDBTM::$records[TicketTask::class][10]['state'] === 2, 'Completion event follows the saved task completion');

foreach (['insert', 'pause write', 'duration', 'commit', 'lock', 'permission'] as $failure) {
    eventFixture();
    if ($failure !== 'insert') { nativeAction('start'); }
    $before = count(Plugin::$events);
    if ($failure === 'insert') { $DB->failInsert = true; $action = 'start'; }
    if ($failure === 'pause write') { $DB->failTimerUpdate = true; $action = 'pause'; }
    if ($failure === 'duration') { CommonDBTM::$failUpdate = true; $action = 'stop'; }
    if ($failure === 'commit') { $DB->failCommit = true; $action = 'stop'; }
    if ($failure === 'lock') { $DB->failLock = true; $action = 'pause'; }
    if ($failure === 'permission') { Session::$user = 99; $action = 'pause'; }
    check(nativeAction($action)['type'] === 'warning' && count(Plugin::$events) === $before,
        'Rejected or rolled-back operation emits no event: ' . $failure);
}

eventFixture();
Plugin::$listener = static function () { throw new RuntimeException('Unavailable optional listener'); };
check(nativeAction('start')['type'] === 'info' && PluginActualtimeTask::checkTimerActive(10, TicketTask::class), 'Subscriber failure keeps a committed start successful');
check(nativeAction('pause')['type'] === 'info' && !PluginActualtimeTask::checkTimerActive(10, TicketTask::class), 'Subscriber failure keeps a committed pause successful');
eventFixture();
nativeAction('start');
Session::$user = 99;
PluginActualtimeTask::pauseTimer(10, TicketTask::class, PluginActualtimeTask::AUTO);
check(Plugin::$events[1]['data']['origin'] === PluginActualtimeTask::AUTO, 'Trusted automatic pause also notifies subscribers');
eventFixture();
PluginActualtimeManualtimer::create(TicketTask::class, 10, '2025-01-10 08:00:00', '2025-01-10 08:30:00');
check(Plugin::$events === [], 'Historical entries do not simulate a live timer event');

echo "OK: $checks timer event checks.\n";
