<?php
/** Standalone planning timers: real plugin methods with GLPI service adapters.
 * Run: php tests/regression/planning-events.php
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
define('ACTUALTIME_FIXTURE_ONLY', true);
define('PLUGIN_ACTUALTIME_NAME', 'ActualTime');
require __DIR__ . '/manualtimer.php';
require GLPI_ROOT . '/inc/planning.class.php';
// The modal integration test loads GLPI's official Ajax.php. Other PHP tests
// only exercise the surrounding form and do not emulate the native helper.
if (getenv('ACTUALTIME_GLPI_AJAX')) {
    require getenv('ACTUALTIME_GLPI_AJAX');
} else {
    class Ajax { public static function createIframeModalWindow($id, $url, $options = []) { return ''; } }
}


class Planning { public const INFO = 0; public const TODO = 1; public const DONE = 2; }
class PlanningExternalEvent extends CommonDBTM {
    public static $edit_others = false;
    public function can($id, $right) {
        return parent::can($id, $right) && ($right !== UPDATE || (int)$this->fields['users_id'] === Session::getLoginUserID() || self::$edit_others);
    }
    public static $view_all = false;
    public static function getVisibilityCriteria(): array {
        return self::$view_all ? [] : ['OR' => ['users_id' => Session::getLoginUserID(), 'guest_id' => Session::getLoginUserID()]];
    }
    public function getItilObjectItemType() { throw new LogicException('Planning events have no ITIL parent'); }
    public function getForeignKeyField() { throw new LogicException('No parent foreign key exists on a standalone event'); }
    public static function getFormURLWithID($id) { return '/front/planningexternalevent.form.php?id=' . $id; }
    public function update($input) {
        if (isset($input['effective_duration']) || isset($input['actiontime'])) { throw new LogicException('No duration column on planning events'); }
        $this->input = $input;
        PluginActualtimeTask::preUpdate($this);
        return $this->input ? parent::update($this->input) : false;
    }
}
function planningFixture(): void {
    resetFixture(); $GLOBALS['CFG_GLPI']['root_doc'] = ''; Session::$user = 42; PlanningExternalEvent::$view_all = false; PlanningExternalEvent::$edit_others = false;
    CommonDBTM::$records[PluginActualtimeConfig::class][1] += ['showtimerpopup' => 1, 'showtimerinbox' => 1, 'autoopenrunning' => 0, 'displayinfofor' => 0];
    foreach ([10, 11] as $id) {
        CommonDBTM::$records[PlanningExternalEvent::class][$id] = [
            'id' => $id, 'users_id' => 42, 'guest_id' => 99, 'entities_id' => 1,
            'name' => 'Planejamento "A" <script>window.injected=1</script>',
            'state' => Planning::INFO, 'background' => 0, 'rrule' => '',
            'begin' => '2025-01-10 08:00:00', 'end' => '2025-01-10 09:00:00',
        ];
    }
}
function planningItem(int $id = 10): PlanningExternalEvent {
    $item = new PlanningExternalEvent(); $item->getFromDB($id); return $item;
}
function planningAction($action, $id = 10): array {
    $method = ['start' => 'startTimer', 'pause' => 'pauseTimer', 'end' => 'stopTimer'][$action];
    return PluginActualtimeTask::$method($id, PlanningExternalEvent::class, PluginActualtimeTask::WEB);
}
function planningForm(int $id = 10): string {
    $item = planningItem($id);
    ob_start();
    echo "<form action='/front/planningexternalevent.form.php'><input name='id' value='$id'><select name='state' data-track-changes='keep'>";
    foreach ([0, 1, 2] as $state) { echo "<option value='$state'" . ($state === (int)$item->fields['state'] ? ' selected' : '') . ">$state</option>"; }
    echo '</select>';
    PluginActualtimeTask::postForm(['item' => $item]);
    echo '</form>';
    return ob_get_clean();
}
function planningSnapshot(): array { global $DB; return [$DB->timers, $DB->sources, CommonDBTM::$records, Log::$entries]; }

planningFixture();
if (($argv[1] ?? '') === '--fixture') {
    $mode = $argv[2] ?? 'footer'; $input = json_decode($argv[3] ?? '{}', true);
    if (!empty($argv[4])) { [$DB, CommonDBTM::$records, Log::$entries] = unserialize(base64_decode($argv[4]), ['allowed_classes' => [MemoryDB::class]]); }
    Session::$user = (int)($input['user'] ?? 42);
    switch ($mode) {
        case 'action': $response = planningAction($input['action'], (int)$input['task_id']); break;
        case 'footer': $response = PluginActualtimeRuntime::footerData($input['watch'] ?? []); break;
        case 'form': $response = planningForm((int)$input['task_id']); break;
        case 'segments':
            PluginActualtimeRuntime::taskContext(PlanningExternalEvent::class, (int)$input['task_id']);
            $response = ['segment' => PluginActualtimeTask::getSegment((int)$input['task_id'], PlanningExternalEvent::class)]; break;
        case 'editor':
            ob_start();
            echo "<!doctype html><html><head><meta charset='utf-8'><script src='/assets/jquery.js'></script></head><body>";
            (new PluginActualtimeSourcetimer())->modalForm(PlanningExternalEvent::class, (int)$input['task_id']);
            echo '</body></html>';
            $response = ob_get_clean(); break;
        case 'edit':
            $response = PluginActualtimeManualtimer::edit(PlanningExternalEvent::class, (int)$input['task_id'], $input['values']); break;
        case 'page': $response = "<!doctype html><html><head><meta charset='utf-8'><script src='/assets/jquery.js'></script></head><body>" . planningForm(10) . planningForm(11) . "<script src='/assets/bootstrap-adapter.js'></script><script src='/assets/runtime.js'></script></body></html>"; break;
        case 'dump': $response = ['timers' => $DB->timers, 'records' => CommonDBTM::$records]; break;
        default: throw new RuntimeException('Invalid fixture');
    }
    echo json_encode(['response' => $response, 'state' => base64_encode(serialize([$DB, CommonDBTM::$records, Log::$entries]))]); exit;
}

$result = planningAction('start');
check($result['type'] === 'info', 'Default informational planning event can start');
check($result['runtime']['parent_type'] === PlanningExternalEvent::class && $result['runtime']['parent_id'] === 10, 'Event is its own context');
check(str_contains($result['runtime']['link'], 'planningexternalevent.form.php?id=10'), 'Popup opens the event itself');
check($result['runtime']['name'] === planningItem()->fields['name'], 'Title remains data in JSON');
$before = planningSnapshot(); check(planningAction('start')['type'] === 'warning' && planningSnapshot() === $before, 'Duplicate start is serialized and rejected');
check(planningAction('start', 11)['type'] === 'info', 'Two standalone events can run together');
check(PluginActualtimeTask::startTimer(10, TicketTask::class, PluginActualtimeTask::WEB)['type'] === 'info', 'Equal numeric ID in an ITIL task stays separate');
$DB->timers[0]['actual_begin'] = date('Y-m-d H:i:s', time() - 125);
$other = array_slice($DB->timers, 1);
$result = planningAction('pause');
check($result['type'] === 'info' && $result['time'] >= 125 && $result['time'] < 130, 'Pause stores real elapsed seconds');
check(array_slice($DB->timers, 1) === $other && planningItem()->fields['state'] === Planning::INFO, 'Pause preserves another event, equal-ID task and event status');
check(planningAction('start')['type'] === 'info' && count($DB->timers) === 4, 'Resume appends a new period');
$result = planningAction('end');
check($result['type'] === 'info' && $result['runtime']['done'] && planningItem()->fields['state'] === Planning::DONE, 'End closes the period and completes the event');
check(planningItem()->fields['begin'] === '2025-01-10 08:00:00' && planningItem()->fields['end'] === '2025-01-10 09:00:00', 'Calendar range preserved after real work');
check(!array_key_exists('effective_duration', planningItem()->fields) && !array_key_exists('actiontime', planningItem()->fields), 'No nonexistent duration column written');
check(planningAction('start')['type'] === 'warning', 'Completed event cannot restart until reopened');
$footer = PluginActualtimeRuntime::footerData(['PlanningExternalEvent:10', 'PlanningExternalEvent:11', 'TicketTask:10']);
check(count($footer['active']) === 2 && count($footer['states']) === 3, 'Footer reconciles events and tasks independently');
$event = planningItem(); $event->update(['id' => 10, 'state' => Planning::TODO]);
check(planningAction('start')['type'] === 'info', 'Reopening event permits a new timer');
$event = planningItem(); $event->update(['id' => 10, 'state' => Planning::DONE]);
check(!PluginActualtimeTask::checkTimerActive(10, PlanningExternalEvent::class), 'Native completion also closes running time');

foreach (['guest', 'outsider', 'read only', 'background', 'future', 'done'] as $case) {
    planningFixture();
    if ($case === 'guest') { Session::$user = 99; }
    if ($case === 'outsider') { Session::$user = 123; PlanningExternalEvent::$view_all = false; }
    if ($case === 'read only') { CommonDBTM::$deniedUpdate = ['PlanningExternalEvent:10']; }
    if ($case === 'background') { CommonDBTM::$records[PlanningExternalEvent::class][10]['background'] = 1; }
    if ($case === 'future') { CommonDBTM::$records[PluginActualtimeConfig::class][1]['planned_task'] = 1; CommonDBTM::$records[PlanningExternalEvent::class][10]['begin'] = '2099-01-01 08:00:00'; }
    if ($case === 'done') { CommonDBTM::$records[PlanningExternalEvent::class][10]['state'] = Planning::DONE; }
    $before = planningSnapshot();
    check(planningAction('start')['type'] === 'warning' && planningSnapshot() === $before, 'No unauthorized or disabled start: ' . $case);
}
planningFixture(); planningAction('start');
Session::$user = 99;
$state = PluginActualtimeRuntime::taskState(PlanningExternalEvent::class, 10);
check($state['active'] && !$state['can_control'], 'Guest may read the total without controlling it');
$before = planningSnapshot();
check(planningAction('pause')['type'] === 'warning' && planningAction('end')['type'] === 'warning' && planningSnapshot() === $before, 'Guest cannot change timer directly');
Session::$user = 123; rejects(fn() => PluginActualtimeRuntime::taskState(PlanningExternalEvent::class, 10), 'Planner visibility enforced on direct ID');
check(PluginActualtimeRuntime::runningTimers() === [], 'Other users timers are not leaked');
planningFixture(); Session::$right = false;
check(planningAction('start')['type'] === 'info', 'Owner start does not require timer correction permission');
check(!PluginActualtimeSourcetimer::canModify(PlanningExternalEvent::class, 10), 'Correction still needs its separate profile right');

planningFixture(); planningAction('start');
$event = planningItem(); $event->update(['id' => 10, 'users_id' => 99]);
check(!PluginActualtimeTask::checkTimerActive(10, PlanningExternalEvent::class) && $DB->timers[0]['users_id'] === 42, 'Reassignment pauses old owner while preserving attribution');
Session::$user = 99; check(planningAction('start')['type'] === 'info', 'New owner can start a new period');
$event = planningItem(); $event->update(['id' => 10, 'background' => 1]);
check(!PluginActualtimeTask::checkTimerActive(10, PlanningExternalEvent::class), 'Background conversion pauses timer');

foreach (['insert', 'pause', 'complete', 'commit'] as $failure) {
    planningFixture(); if ($failure !== 'insert') { planningAction('start'); }
    $before = planningSnapshot();
    if ($failure === 'insert') { $DB->failInsert = true; $result = planningAction('start'); }
    if ($failure === 'pause') { $DB->failTimerUpdate = true; $result = planningAction('pause'); }
    if ($failure === 'complete') { CommonDBTM::$failUpdate = true; $result = planningAction('end'); }
    if ($failure === 'commit') { $DB->failCommit = true; $result = planningAction('end'); }
    check($result['type'] === 'warning' && planningSnapshot() === $before, 'Atomic event timer operation: ' . $failure);
}
planningFixture();
$id = PluginActualtimeManualtimer::create(PlanningExternalEvent::class, 10, '2025-01-10 08:00:00', '2025-01-10 08:30:00');
check($DB->timers[0]['users_id'] === 42 && Log::$entries[0]['type'] === PlanningExternalEvent::class && Log::$entries[0]['id'] === 10, 'Manual periods belong and audit to the event');
$input = ['actual_begin' => [$id => '2025-01-10 08:10:00'], 'actual_end' => [$id => '2025-01-10 08:30:00'], 'original_begin' => [$id => '2025-01-10 08:00:00'], 'original_end' => [$id => '2025-01-10 08:30:00']];
PluginActualtimeManualtimer::edit(PlanningExternalEvent::class, 10, $input);
check($DB->timers[0]['actual_actiontime'] === 1200 && count($DB->sources) === 1, 'Event correction preserves original source and recalculates total');
$DB->insert(PluginActualtimeTask::getTable(), ['itemtype' => TicketTask::class, 'items_id' => 10, 'users_id' => 42]);
PluginActualtimePlanning::purge(planningItem());
check(count($DB->timers) === 1 && $DB->timers[0]['itemtype'] === TicketTask::class && $DB->sources === [], 'Purge removes only the event and its correction sources');

planningFixture();
$html = planningForm();
check(str_contains($html, 'data-actualtime-task=\'PlanningExternalEvent:10\'') && str_contains($html, "action='start'"), 'Timer renders in native event form');
check(substr_count($html, '<form') === 1, 'No nested form injected into the planner');
Session::$user = 99;
$html = planningForm(); check(str_contains($html, 'actualtime_timer_PlanningExternalEvent_10_') && !preg_match('/<button[^>]*id=\x27actualtime_button_/', $html), 'Guest sees time without action controls');
CommonDBTM::$records[PlanningExternalEvent::class][10]['background'] = 1;
check(!str_contains(planningForm(), 'actualtime_timer_'), 'Background events have no timer');
planningFixture(); CommonDBTM::$records[PlanningExternalEvent::class][10]['rrule'] = '{"freq":"weekly"}';
check(str_contains(planningForm(), 'whole series'), 'Recurring-event total and completion scope explained');

planningFixture();
$event = planningItem(); $event->fields['id'] = -1;
ob_start(); PluginActualtimePlanning::postForm(['item' => $event]); $new_html = ob_get_clean();
check(str_contains($new_html, 'Save the event') && !str_contains($new_html, 'actualtime_timer_'), 'Unsaved event explains when the timer becomes available');
planningFixture(); planningAction('start'); $DB->failTimerUpdate = true;
if (!defined('ERROR')) { define('ERROR', 0); }
$event = planningItem(); $before = planningSnapshot();
check(!$event->update(['id' => 10, 'state' => Planning::DONE]) && planningSnapshot() === $before, 'Native completion is cancelled when pausing fails');
check(Session::$messages !== [], 'Native failure displays the timer message');
planningFixture();
PluginActualtimeManualtimer::create(PlanningExternalEvent::class, 10, '2025-01-10 08:00:00', '2025-01-10 08:30:00');
$events = PluginActualtimeTask::populatePlanning(['begin' => '2025-01-10 00:00:00', 'end' => '2025-01-11 00:00:00', 'who' => 42, 'whogroup' => 0]);
check(count($events) === 1 && str_contains(current($events)['ajaxurl'], 'itemtype=PlanningExternalEvent'), 'Calendar actual-work entry opens its own event');
Session::$user = 123;
check(PluginActualtimeTask::populatePlanning(['begin' => '2025-01-10 00:00:00', 'end' => '2025-01-11 00:00:00', 'who' => 42, 'whogroup' => 0]) === [], 'Calendar actual-work entries enforce planner visibility');

planningFixture();
$id = PluginActualtimeManualtimer::create(PlanningExternalEvent::class, 10, '2025-01-10 08:00:00', '2025-01-10 08:30:00');
$preview = PluginActualtimeManualtimer::deletionPreview(PlanningExternalEvent::class, 10, ['timer_id' => (string)$id, 'original_begin' => '2025-01-10 08:00:00', 'original_end' => '2025-01-10 08:30:00', 'original_actiontime' => '1800', 'original_users_id' => '42']);
check($preview['timer']['id'] === $id, 'Correction editor can preview a planning timer deletion');

printf("OK: %d planning-event regression checks (real plugin methods; service adapters).\n", $checks);
