<?php

/**
 * Native timer notifications and optional integration with the real AutoStatus.
 * php tests/regression/timer-events.php
 * AUTOSTATUS_PLUGIN_DIR=../autostatus php tests/regression/timer-events.php
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

$autostatus = getenv('AUTOSTATUS_PLUGIN_DIR');
if ($autostatus) {
    require rtrim($autostatus, '/') . '/hook.php';
    require rtrim($autostatus, '/') . '/setup.php';
    class Config {
        public static $values = [];
        public static function getConfigurationValues($context) { return self::$values; }
        public static function setConfigurationValues($context, $values) { self::$values = array_merge(self::$values, $values); }
    }
    class AutoStatusMemoryDB extends MemoryDB {
        public function request($query) {
            if (!isset($query['INNER JOIN']['glpi_tickettasks'])) { return parent::request($query); }
            check($query['INNER JOIN']['glpi_tickettasks']['ON'] === ['glpi_plugin_actualtime_tasks' => 'items_id', 'glpi_tickettasks' => 'id'], 'Timer query joins the task foreign key');
            $matches = static function ($row, $conditions) use (&$matches) {
                foreach ($conditions as $field => $value) {
                    if ($field === 'NOT') { if ($matches($row, $value)) { return false; } }
                    elseif (($row[$field] ?? null) !== $value) { return false; }
                }
                return true;
            };
            $rows = [];
            foreach ($this->timers as $timer) {
                $task = CommonDBTM::$records[TicketTask::class][$timer['items_id']] ?? null;
                if ($task === null) { continue; }
                $row = [];
                foreach ($timer as $key => $value) { $row['glpi_plugin_actualtime_tasks.' . $key] = $value; }
                foreach ($task as $key => $value) { $row['glpi_tickettasks.' . $key] = $value; }
                if ($matches($row, $query['WHERE'])) { $rows[] = $row; }
            }
            return new ArrayIterator(array_slice($rows, 0, $query['LIMIT'] ?? count($rows)));
        }
    }
    function integrationFixture(array $config = []): void {
        global $DB, $PLUGIN_HOOKS;
        eventFixture();
        $DB = new AutoStatusMemoryDB();
        Config::$values = $config + ['actualtime_enabled' => 1];
        CommonDBTM::$records[Ticket::class][1]['status'] = 4;
        CommonDBTM::$records[Ticket::class][2] = ['id' => 2, 'status' => 4, 'is_deleted' => 0];
        CommonDBTM::$records[TicketTask::class][11] = CommonDBTM::$records[TicketTask::class][10];
        CommonDBTM::$records[TicketTask::class][11]['id'] = 11;
        CommonDBTM::$records[TicketTask::class][12] = CommonDBTM::$records[TicketTask::class][10];
        CommonDBTM::$records[TicketTask::class][12]['id'] = 12;
        CommonDBTM::$records[TicketTask::class][12]['tickets_id'] = 2;
        plugin_init_autostatus();
        Plugin::$listener = static function ($name, $data) use (&$PLUGIN_HOOKS) {
            foreach ($PLUGIN_HOOKS[$name] ?? [] as $callback) { $callback($data); }
        };
    }
    function ticketStatus(int $id = 1): int { return CommonDBTM::$records[Ticket::class][$id]['status']; }

    integrationFixture();
    check(nativeAction('start')['type'] === 'info' && ticketStatus() === 2, 'Real ActualTime start changes the real AutoStatus ticket status');
    PluginActualtimeTask::startTimer(11, TicketTask::class, PluginActualtimeTask::WEB);
    nativeAction('pause');
    check(ticketStatus() === 2, 'Pausing one of two tasks on the same ticket keeps it in progress');
    PluginActualtimeTask::pauseTimer(11, TicketTask::class, PluginActualtimeTask::WEB);
    check(ticketStatus() === 4, 'Pausing the last task sets the ticket pending');
    nativeAction('start');
    check(ticketStatus() === 2, 'Resume restores in progress');
    PluginActualtimeTask::startTimer(12, TicketTask::class, PluginActualtimeTask::WEB);
    PluginActualtimeTask::startTimer(10, ProjectTask::class, PluginActualtimeTask::WEB);
    nativeAction('stop');
    check(ticketStatus() === 4 && ticketStatus(2) === 2, 'Another ticket and a same-ID project timer do not block the selected ticket pause status');
    check(CommonDBTM::$records[TicketTask::class][10]['state'] === 2 && PluginActualtimeTask::checkTimerActive(12, TicketTask::class), 'Native completion and the other active timer are preserved');

    integrationFixture();
    nativeAction('start');
    CommonDBTM::$records[TicketTask::class][11]['users_id_tech'] = 7;
    Session::$user = 7;
    PluginActualtimeTask::startTimer(11, TicketTask::class, PluginActualtimeTask::WEB);
    Session::$user = 42;
    nativeAction('pause');
    check(ticketStatus() === 2, 'Another technician on this ticket keeps it in progress');
    Session::$user = 7;
    PluginActualtimeTask::stopTimer(11, TicketTask::class, PluginActualtimeTask::WEB);
    check(ticketStatus() === 4, 'Last technician completion applies pending');

    foreach ([5, 6] as $status) {
        integrationFixture();
        nativeAction('start');
        CommonDBTM::$records[Ticket::class][1]['status'] = $status;
        nativeAction('pause');
        check(ticketStatus() === $status, 'Closing a timer does not reopen solved/closed ticket ' . $status);
    }
    integrationFixture(['actualtime_enabled' => 0]);
    nativeAction('start');
    check(ticketStatus() === 4, 'Disabled integration leaves ticket status alone');
    integrationFixture(['actualtime_allowed_statuses_start' => '1']);
    nativeAction('start');
    check(ticketStatus() === 4, 'Start filter is independent of task creation');
    integrationFixture(['actualtime_status_running' => 3, 'actualtime_status_stopped' => 1]);
    nativeAction('start');
    check(ticketStatus() === 3, 'Custom running status applied');
    nativeAction('pause');
    check(ticketStatus() === 1, 'Custom stopped status applied');
    integrationFixture();
    $DB->failCommit = true;
    nativeAction('start');
    check(ticketStatus() === 4 && $DB->timers === [], 'Failed commit changes neither timers nor ticket status');
    echo "OK: real ActualTime + AutoStatus integration (GLPI adapters, no live database).\n";
}
echo "OK: $checks timer event checks.\n";
