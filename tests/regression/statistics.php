<?php

/**
 * Parent/technician totals, reassignment and combined timer workflow.
 * Run: php tests/regression/statistics.php
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
define('ACTUALTIME_FIXTURE_ONLY', true);
require __DIR__ . '/manualtimer.php';
require GLPI_ROOT . '/inc/statistics.class.php';

if (($argv[1] ?? '') === '--sql') {
    $scope = 'AND glpi_tickets.entities_id IN (1)';
    $date = "AND glpi_tickets.closedate >= '2025-01-01 00:00:00' AND glpi_tickets.closedate <= '2025-01-31 23:59:59'";
    echo json_encode([
        'tickets' => PluginActualtimeStatistics::ticketReportSql($scope, ''),
        'technicians' => PluginActualtimeStatistics::userReportSql(false, $scope, ''),
        'requesters' => PluginActualtimeStatistics::userReportSql(true, $scope, ''),
        'date' => PluginActualtimeStatistics::ticketReportSql($scope, $date),
        'requester' => PluginActualtimeStatistics::ticketReportSql($scope, 'AND glpi_tickets_users.users_id = 100'),
        'no_entity' => PluginActualtimeStatistics::ticketReportSql('AND glpi_tickets.entities_id IN (99)', ''),
        'tech_date' => PluginActualtimeStatistics::userReportSql(false, $scope, $date),
        'requester_date' => PluginActualtimeStatistics::userReportSql(true, $scope, $date),
    ]);
    exit;
}

resetFixture();
$now = strtotime('2025-01-10 10:00:00');
CommonDBTM::$records[Ticket::class][1]['actiontime'] = 3600;
CommonDBTM::$records[TicketTask::class][10]['users_id_tech'] = 99;
CommonDBTM::$records[TicketTask::class][10]['actiontime'] = 2700;
CommonDBTM::$records[TicketTask::class][11] = ['id' => 11, 'tickets_id' => 1, 'users_id_tech' => 42, 'state' => 1, 'actiontime' => 900];
CommonDBTM::$records[TicketTask::class][12] = ['id' => 12, 'tickets_id' => 2, 'users_id_tech' => 42, 'state' => 1, 'actiontime' => 600];
foreach ([
    [10, 42, '2025-01-10 08:00:00', '2025-01-10 08:15:00', 900],
    [10, 42, '2025-01-10 08:15:00', '2025-01-10 08:30:00', 900],
    [10, 99, '2025-01-10 09:00:00', '2025-01-10 09:05:00', 300],
    [11, 42, '2025-01-10 09:30:00', '2025-01-10 09:40:00', 600],
    [11, 42, '2025-01-10 09:50:00', null, 0],
    [12, 42, '2025-01-10 08:00:00', '2025-01-10 09:00:00', 3600],
] as [$task, $user, $begin, $end, $seconds]) {
    $DB->insert(PluginActualtimeTask::getTable(), ['itemtype' => TicketTask::class, 'items_id' => $task,
        'users_id' => $user, 'actual_begin' => $begin, 'actual_end' => $end, 'actual_actiontime' => $seconds]);
}
$DB->insert(PluginActualtimeTask::getTable(), ['itemtype' => ProjectTask::class, 'items_id' => 10,
    'users_id' => 99, 'actual_begin' => '2025-01-10 08:00:00', 'actual_end' => '2025-01-10 09:00:00', 'actual_actiontime' => 3600]);
$parent = new Ticket();
$parent->getFromDB(1);
$totals = PluginActualtimeStatistics::parentTotals($parent, $now);
check($totals['actual_total'] === 3300, 'Parent includes ended periods and running elapsed seconds, excluding other parents/types');
check($totals['technicians'][42]['actual_total'] === 3000 && $totals['technicians'][99]['actual_total'] === 300, 'Actual duration stays with the period technician after reassignment');
check($totals['technicians'][42]['total'] === 900 && $totals['technicians'][99]['total'] === 2700, 'Task duration follows current task assignment and is counted once per task');
check(array_sum(array_column($totals['technicians'], 'actual_total')) === $totals['actual_total'], 'Technician total equals the parent total at the same instant');
check(PluginActualtimeStatistics::parentTotals($parent, $now + 60)['actual_total'] === 3360, 'Running time advances in both summary levels');
$GLOBALS['actualtime_test_user_name'] = 'Técnico "A" <img src=x onerror="alert(1)"> & B';
if (($argv[1] ?? '') === '--render') {
    echo '<!doctype html><html><head><meta charset="utf-8"><script src="/jquery.js"></script></head><body><div class="dates_timelines"></div>';
    PluginActualtimeTask::showStats($parent);
    echo '</body></html>';
    exit;
}
ob_start();
PluginActualtimeTask::showStats($parent);
$html = ob_get_clean();
check(!str_contains($html, '<img') && str_contains($html, '\\u0026lt;img'), 'Statistics escape technician names before embedding HTML in JavaScript');
check(str_contains($html, '.append("\\u003Ctable') && !str_contains($html, '.append("<table'), 'Statistics HTML is encoded as a JSON string');
unset($GLOBALS['actualtime_test_user_name']);

// Combine the delivered functions while another task remains active.
resetFixture();
Session::$user = 42;
CommonDBTM::$records[Ticket::class][1]['actiontime'] = 0;
CommonDBTM::$records[TicketTask::class][11] = ['id' => 11, 'tickets_id' => 1, 'users_id_tech' => 42, 'state' => 1, 'actiontime' => 0];
PluginActualtimeTask::startTimer(11, TicketTask::class, PluginActualtimeTask::WEB);
$running = $DB->timers[0];
$id = addPeriod('2025-01-10 08:00:00', '2025-01-10 08:30:00');
$edit = ['actual_begin' => [$id => '2025-01-10 07:45:00'], 'actual_end' => [$id => '2025-01-10 08:30:00'],
    'original_begin' => [$id => '2025-01-10 08:00:00'], 'original_end' => [$id => '2025-01-10 08:30:00']];
PluginActualtimeManualtimer::edit(TicketTask::class, 10, $edit);
check($DB->timers[0] === $running && PluginActualtimeTask::totalEndTime(10, TicketTask::class) === 2700, 'Manual creation and editing preserve another running task');
PluginActualtimeManualtimer::deleteTimer(TicketTask::class, 10, deleteFixtureInput($id));
check($DB->timers === [$running] && $DB->sources === [], 'Deletion removes only the chosen period and its snapshot');
check(CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 0 && CommonDBTM::$records[TicketTask::class][11]['state'] === 1, 'Deleted task total resets while the other task remains open');
check(count(Log::$entries) === 3 && str_contains(end(Log::$entries)['changes'][2], '2700 seconds'), 'Combined workflow retains creation, edit and deletion history');
CommonDBTM::$denied = ['TicketTask:11'];
check(PluginActualtimeRuntime::runningTimers() === [], 'A private task cannot leak through the active-task list');
CommonDBTM::$denied = [];
Session::$right = false;
$before = [$DB->timers, Log::$entries];
rejects(fn() => addPeriod(), 'Combined workflow requires Modify timers for manual changes');
check([$DB->timers, Log::$entries] === $before, 'Denied manual changes preserve active work and history');
foreach ([ChangeTask::class => Change::class, ProblemTask::class => Problem::class] as $type => $parentType) {
    resetFixture();
    CommonDBTM::$records[$parentType][1] = ['id' => 1, 'status' => 2, 'actiontime' => 600];
    CommonDBTM::$records[$type][10] = ['id' => 10, strtolower($parentType) . 's_id' => 1, 'users_id_tech' => 99, 'actiontime' => 600];
    $DB->insert(PluginActualtimeTask::getTable(), ['itemtype' => $type, 'items_id' => 10, 'users_id' => 42,
        'actual_begin' => '2025-01-10 08:00:00', 'actual_end' => '2025-01-10 08:05:00', 'actual_actiontime' => 300]);
    $item = new $parentType();
    $item->getFromDB(1);
    $summary = PluginActualtimeStatistics::parentTotals($item, $now);
    check($summary['actual_total'] === 300 && $summary['technicians'][42]['actual_total'] === 300
        && $summary['technicians'][99]['total'] === 600, 'Technician attribution also works for ' . $type);
}
echo "OK: $checks statistics and combined-workflow checks (GLPI adapters, no real database).\n";
