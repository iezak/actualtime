<?php

/**
 * Isolated regressions: execute the real plugin classes with GLPI adapters.
 * Run: php tests/regression/manualtimer.php
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

define('GLPI_ROOT', dirname(__DIR__, 2));
define('READ', 1);
define('UPDATE', 2);
define('MINUTE_TIMESTAMP', 60);
define('GLPI_USE_CSRF_CHECK', true);
date_default_timezone_set('America/Sao_Paulo');
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function __($message, $domain = null) { return $message; }
function __s($message, $domain = null) { return htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); }
function _x($context, $message) { return $message; }
function _n($singular, $plural, $count, $domain = null) { return $count == 1 ? $singular : $plural; }
function getUserName($id) { return $GLOBALS['actualtime_test_user_name'] ?? ('Technician ' . $id); }
function getItemForItemtype($type) { return new $type(); }
function getForeignKeyFieldForItemType($type) { return strtolower($type) . 's_id'; }

class Session
{
    public static $user = 7;
    public static $right = true;
    public static function getLoginUserID() { return self::$user; }
    public static function haveRight($name, $right) { return self::$right; }
    public static function getNewCSRFToken() { return 'test-token'; }
}
class Plugin
{
    public static $active = true;
    public function isActivated($name) { return $name === 'actualtime' && self::$active; }
    public static function getWebDir($name) { return '/plugins/' . $name; }
}
class Toolbox
{
    public static function getDateFormat($format) { return 'd/m/Y'; }
    public static function logInFile($name, $message) {}
    public static function getTimestampTimeUnits($time) {
        return ['day' => intdiv((int)$time, 86400), 'hour' => intdiv((int)$time % 86400, 3600),
            'minute' => intdiv((int)$time % 3600, 60), 'second' => (int)$time % 60];
    }
}
if (getenv('ACTUALTIME_GLPI_CORE')) {
    // Use GLPI's real HTML helpers without bootstrapping a database/session.
    require rtrim(getenv('ACTUALTIME_GLPI_CORE'), '/') . '/src/Html.php';
} else {
    class Html
    {
        public static function cleanId($value) { return str_replace(['[', ']'], '_', $value); }
        public static function hidden($name, $options) {
            return '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                . '" value="' . htmlspecialchars((string)$options['value'], ENT_QUOTES, 'UTF-8') . '">';
        }
        public static function showDateTimeField($name, $options) {
            echo '<div class="input-group flex-grow-1 flatpickr"><input type="text" data-input name="'
                . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="'
                . htmlspecialchars($options['value'], ENT_QUOTES, 'UTF-8') . '"'
                . ($options['required'] ? ' required' : '') . '></div>';
        }
        public static function closeForm() {
            echo '<input name="_glpi_csrf_token" value="test-token"></form>';
        }
        public static function timestampToString($seconds) { return (string)$seconds; }
        public static function showToolTip($content, $options = []) { return ''; }
    }
}
class CommonDBTM
{
    public $fields = [];
    public static $records = [];
    public static $denied = [];
    public static $failUpdate = false;
    public function getFromDB($id) {
        $this->fields = self::$records[static::class][(int)$id] ?? [];
        return !empty($this->fields);
    }
    public function getFromDBByCrit($criteria) {
        global $DB;
        $this->fields = $DB->request(['FROM' => static::getTable(), 'WHERE' => $criteria])->current() ?: [];
        return !empty($this->fields);
    }
    public function can($id, $right) {
        return $this->getFromDB($id) && !in_array(static::class . ':' . $id, self::$denied, true);
    }
    public function getField($name) { return $this->fields[$name] ?? null; }
    public function getID() { return $this->fields['id']; }
    public static function getType() { return static::class; }
    public static function getTypeName($nb = 0) { return static::class; }
    public function canUpdateItem() { return true; }
    public static function getTable() {
        return static::class === PluginActualtimeTask::class
            ? 'glpi_plugin_actualtime_tasks'
            : 'glpi_' . strtolower(static::class) . 's';
    }
    public function getForeignKeyField() { return strtolower(static::class) . 's_id'; }
    public function getItilObjectItemType() { return str_replace('Task', '', static::class); }
    public function getFormURL() { return '/plugins/actualtime/front/sourcetimer.form.php'; }
    public static function getFormURLWithID($id) { return '/front/task.form.php?id=' . $id; }
    public function update($input) {
        if (self::$failUpdate) {
            return false;
        }
        self::$records[static::class][$input['id']] = array_merge($this->fields, $input);
        $this->fields = self::$records[static::class][$input['id']];
        return true;
    }
}
class CommonITILObject extends CommonDBTM { public const SOLVED = 5; }
class Ticket extends CommonITILObject {}
class Change extends CommonITILObject {}
class Problem extends CommonITILObject {}
class Project extends CommonDBTM {}
class TicketTask extends CommonDBTM {}
class ChangeTask extends CommonDBTM {}
class ProblemTask extends CommonDBTM {}
class ProjectTask extends CommonDBTM {}
class ProjectState extends CommonDBTM {}
class Log
{
    public const HISTORY_LOG_SIMPLE_MESSAGE = 12;
    public static $entries = [];
    public static $fail = false;
    public static function history($id, $type, $changes, $link = '', $action = 0) {
        if (self::$fail) { return false; }
        self::$entries[] = compact('id', 'type', 'changes', 'action') + ['actor' => Session::$user];
        return true;
    }
}

class MemoryDB
{
    public $timers = [];
    public $sources = [];
    public $failInsert = false;
    public $failSource = false;
    public $failTimerUpdate = false;
    public $failTimerDelete = false;
    public $failSourceDelete = false;
    public $skipTimerDelete = false;
    public $failCommit = false;
    public $failLock = false;
    public $transaction = false;
    public $beginCount = 0;
    public $commitCount = 0;
    public $lockQueries = [];
    private $snapshot;
    private $lastId = 0;
    private $affected = 0;
    public function inTransaction() { return $this->transaction; }
    public function beginTransaction() {
        ++$this->beginCount;
        $this->snapshot = [$this->timers, $this->sources, CommonDBTM::$records, Log::$entries];
        return $this->transaction = true;
    }
    public function commit() {
        ++$this->commitCount;
        if ($this->failCommit) { return false; }
        $this->transaction = false;
        return true;
    }
    public function rollBack() {
        [$this->timers, $this->sources, CommonDBTM::$records, Log::$entries] = $this->snapshot;
        $this->transaction = false;
        return true;
    }
    public function query($sql) { $this->lockQueries[] = $sql; return !$this->failLock; }
    public function quoteName($name) { return $name; }
    public function affectedRows() { return $this->affected; }
    public function insertId() { return $this->lastId; }
    public function tableExists($table) { return true; }
    public function insert($table, $row) {
        if ($this->failInsert) { return false; }
        if ($table === PluginActualtimeSourcetimer::getTable()) {
            if ($this->failSource) { return false; }
            $row['id'] = count($this->sources) + 1;
            $this->sources[] = $row;
            return true;
        }
        $row['id'] = ++$this->lastId;
        $this->timers[] = $row + ['actual_begin' => null, 'actual_end' => null, 'actual_actiontime' => 0, 'is_modified' => 0, 'origin_end' => 0];
        return true;
    }
    public function update($table, $values, $where) {
        $this->affected = 0;
        if ($this->failTimerUpdate) { return false; }
        foreach ($this->timers as &$row) {
            if ($this->matches($row, $where)) {
                $row = array_merge($row, $values);
                ++$this->affected;
            }
        }
        unset($row);
        return true;
    }
    public function delete($table, $where) {
        $this->affected = 0;
        if ($table === PluginActualtimeSourcetimer::getTable()) {
            if ($this->failSourceDelete) { return false; }
            $property = 'sources';
        } else {
            if ($this->failTimerDelete) { return false; }
            if ($this->skipTimerDelete) { return true; }
            $property = 'timers';
        }
        $before = count($this->$property);
        $this->$property = array_values(array_filter($this->$property, fn($row) => !$this->matches($row, $where)));
        $this->affected = $before - count($this->$property);
        return true;
    }
    private function matches($row, $conditions) {
        foreach ($conditions as $field => $value) {
            if (is_int($field)) {
                if (!$this->matches($row, $value)) { return false; }
            } elseif ($field === 'NOT') {
                if ($this->matches($row, $value)) { return false; }
            } elseif ($field === 'OR') {
                $matches = false;
                foreach ($value as $key => $candidate) {
                    $matches = $matches || $this->matches($row, is_int($key) ? $candidate : [$key => $candidate]);
                }
                if (!$matches) { return false; }
            } elseif (is_array($value)) {
                $actual = $row[$field] ?? null;
                if ($actual === null) { return false; }
                if ($value[0] === '<' && !($actual < $value[1])) { return false; }
                if ($value[0] === '>' && !($actual > $value[1])) { return false; }
            } elseif (($row[$field] ?? null) != $value) {
                return false;
            }
        }
        return true;
    }
    public function request($query) {
        $rows = $query['FROM'] === ProjectState::getTable() ? [['id' => 9, 'is_finished' => 1]]
            : ($query['FROM'] === PluginActualtimeSourcetimer::getTable() ? $this->sources : $this->timers);
        $rows = array_values(array_filter($rows, fn($row) => $this->matches($row, $query['WHERE'] ?? [])));
        if (isset($query['LIMIT'])) { $rows = array_slice($rows, 0, $query['LIMIT']); }
        return new ArrayIterator($rows);
    }
}

// Load the real classes. Only configuration storage is adapted.
require GLPI_ROOT . '/inc/task.class.php';
require GLPI_ROOT . '/inc/sourcetimer.class.php';
require GLPI_ROOT . '/inc/manualtimer.class.php';
$checks = 0;
function check($condition, $message) {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function rejects($callback, $message) {
    try { $callback(); } catch (InvalidArgumentException | RuntimeException $error) {
        check(true, $message);
        return;
    }
    check(false, $message);
}
function resetFixture() {
    global $DB, $CFG_GLPI;
    $DB = new MemoryDB();
    $CFG_GLPI = ['time_step' => 15];
    $_SESSION = ['glpilanguage' => 'pt_BR', 'glpidate_format' => 0];
    unset($GLOBALS['actualtime_test_user_name']);
    Session::$right = true;
    Session::$user = 7;
    Plugin::$active = true;
    CommonDBTM::$denied = [];
    CommonDBTM::$failUpdate = false;
    Log::$entries = [];
    Log::$fail = false;
    CommonDBTM::$records = [
        PluginActualtimeConfig::class => [1 => ['id' => 1, 'autoupdate_duration' => true, 'planned_task' => 0, 'multiple_day' => 0]],
        Ticket::class => [1 => ['id' => 1, 'status' => 2, 'is_deleted' => 0]],
        TicketTask::class => [10 => ['id' => 10, 'tickets_id' => 1, 'users_id_tech' => 42, 'state' => 1, 'actiontime' => 0]],
        Project::class => [1 => ['id' => 1, 'is_deleted' => 0]],
        ProjectTask::class => [10 => ['id' => 10, 'projects_id' => 1, 'projectstates_id' => 1, 'effective_duration' => 0]],
    ];
}
function addPeriod($begin = '2025-01-10 08:00:00', $end = '2025-01-10 08:30:00', $type = TicketTask::class) {
    return PluginActualtimeManualtimer::create($type, 10, $begin, $end);
}
function seedRunningTimer($begin = '2025-01-10 08:00:00') {
    global $DB;
    $DB->insert(PluginActualtimeTask::getTable(), [
        'itemtype' => TicketTask::class, 'items_id' => 10, 'users_id' => 42,
        'actual_begin' => $begin, 'actual_end' => null, 'actual_actiontime' => 0,
    ]);
}
function editFixtureInput(): array {
    global $DB;
    $input = ['actual_begin' => [], 'actual_end' => [], 'original_begin' => [], 'original_end' => []];
    foreach ($DB->timers as $row) {
        foreach (['actual_begin' => 'original_begin', 'actual_end' => 'original_end'] as $field => $snapshot) {
            $input[$field][$row['id']] = (string)$row[$field];
            $input[$snapshot][$row['id']] = (string)$row[$field];
        }
    }
    return $input;
}
function deleteFixtureInput($id = 1): array {
    global $DB;
    foreach ($DB->timers as $row) {
        if ($row['id'] != $id) { continue; }
        return ['timer_id' => (string)$id, 'original_begin' => (string)$row['actual_begin'],
            'original_end' => (string)$row['actual_end'], 'original_actiontime' => (string)$row['actual_actiontime'],
            'original_users_id' => (string)$row['users_id'], 'confirm_delete' => '1'];
    }
    throw new RuntimeException('Fixture timer not found');
}

if (($argv[1] ?? '') === '--render-delete-fixture') {
    resetFixture();
    addPeriod();
    parse_str($argv[2] ?? '', $input);
    $preview = PluginActualtimeManualtimer::deletionPreview(TicketTask::class, 10, $input);
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"></head><body>';
    PluginActualtimeSourcetimer::deletionForm(TicketTask::class, 10, $preview['timer']);
    echo '</body></html>';
    exit;
}
if (($argv[1] ?? '') === '--apply-delete-fixture') {
    resetFixture();
    addPeriod();
    parse_str($argv[2] ?? '', $input);
    $deleted = PluginActualtimeManualtimer::deleteTimer(TicketTask::class, 10, $input);
    echo json_encode(['deleted' => $deleted, 'timers' => $DB->timers, 'sources' => $DB->sources, 'history' => Log::$entries,
        'task' => CommonDBTM::$records[TicketTask::class][10]]);
    exit;
}

if (($argv[1] ?? '') === '--render-calendar-fixture') {
    resetFixture();
    if (($argv[2] ?? '') === 'active') { seedRunningTimer(); } else { addPeriod(); }
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<link rel="stylesheet" href="/assets/flatpickr.css">';
    echo '<style>body{font:16px sans-serif;padding:16px}label{display:block}input{padding:8px;max-width:100%}.mb-3{margin-bottom:12px}[data-toggle]{display:inline-block;min-width:24px;min-height:24px;cursor:pointer}[data-toggle]::before{content:"▦"}</style>';
    echo '<script src="/assets/jquery.js"></script>';
    echo '</head><body>';
    (new PluginActualtimeSourcetimer())->modalForm(TicketTask::class, 10);
    // Match the popup lifecycle: deferred calendar dependencies load in the footer.
    echo '<script src="/assets/flatpickr.js"></script><script src="/assets/pt.js"></script>';
    echo '<script src="/assets/glpi-calendar.js"></script>';
    echo '</body></html>';
    exit;
}

if (($argv[1] ?? '') === '--apply-edit-fixture') {
    resetFixture();
    if (($argv[2] ?? '') === 'active') { seedRunningTimer(); } else { addPeriod(); }
    parse_str($argv[3] ?? '', $input);
    $changed = PluginActualtimeManualtimer::edit(TicketTask::class, 10, $input);
    echo json_encode(['changed' => $changed, 'timers' => $DB->timers, 'sources' => $DB->sources, 'history' => Log::$entries,
        'task' => CommonDBTM::$records[TicketTask::class][10]]);
    exit;
}

if (($argv[1] ?? '') === '--validate-calendar-values') {
    echo json_encode(PluginActualtimeManualtimer::validateInterval($argv[2] ?? '', $argv[3] ?? ''));
    exit;
}

resetFixture();
check(!function_exists('htmlescape'), 'Do not invent a global helper unavailable in GLPI 10');
$interval = PluginActualtimeManualtimer::validateInterval('2024-02-29 23:45:00', '2024-03-01 00:15:00');
check($interval['actual_actiontime'] === 1800, 'Leap day and midnight duration');
check(PluginActualtimeManualtimer::validateInterval('2025-01-10 08:00:01', '2025-01-10 08:00:02')['actual_actiontime'] === 1, 'Seconds retained');
check(PluginActualtimeManualtimer::normalizeDateTime('2025-01-10T08:30') === '2025-01-10 08:30:00', 'Native minutes normalize to SQL seconds');
check(PluginActualtimeManualtimer::normalizeDateTime('2025-01-10T08:30:45') === '2025-01-10 08:30:45', 'Native seconds retained');
check(PluginActualtimeManualtimer::validateInterval('2024-02-29T23:45', '2024-03-01T00:15')['actual_actiontime'] === 1800, 'Native interval across midnight');
check(PluginActualtimeManualtimer::validateInterval('2025-01-10T08:00', '2025-01-10 08:30:00')['actual_end'] === '2025-01-10 08:30:00', 'Native and GLPI inputs remain compatible');
foreach ([
    ['', '2025-01-10 08:30:00'],
    [[], '2025-01-10 08:30:00'],
    ['2025-02-29 08:00:00', '2025-03-01 08:30:00'],
    ['2025-01-10 25:00:00', '2025-01-11 08:30:00'],
    ['2025-01-10 08:00:00 trailing', '2025-01-10 08:30:00'],
    ['2025-01-10 08:00:00', '2025-01-10 08:00:00'],
    ['2025-01-10 08:30:00', '2025-01-10 08:00:00'],
    ['1960-01-10 08:00:00', '1960-01-10 08:30:00'],
    ['2099-01-10 08:00:00', '2099-01-10 08:30:00'],
    ['2025-02-29T08:00', '2025-03-01T08:30'],
    ['2025-01-10T08:00:00Z', '2025-01-10T08:30:00Z'],
    ['2025-01-10T08:00-03:00', '2025-01-10T08:30-03:00'],
] as [$begin, $end]) {
    rejects(fn() => PluginActualtimeManualtimer::validateInterval($begin, $end), 'Reject invalid date/range');
}

addPeriod();
check(count($DB->timers) === 1 && $DB->timers[0]['actual_actiontime'] === 1800, 'Manual period persisted');
check($DB->timers[0]['users_id'] === 42, 'Assigned technician receives time');
check(CommonDBTM::$records[TicketTask::class][10]['state'] === 1, 'Task state retained');
check(CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 1800, 'Native task duration updated');
check(Log::$entries[0]['actor'] === 7 && Log::$entries[0]['id'] === 1, 'Operator audited on parent');
check(str_contains(Log::$entries[0]['changes'][2], 'technician #42'), 'Technician audited');
rejects(fn() => addPeriod(), 'Duplicate rejected');
rejects(fn() => addPeriod('2025-01-10 08:15:00', '2025-01-10 08:35:00'), 'Overlapping interval rejected');
check(count($DB->timers) === 1 && count(Log::$entries) === 1, 'Rejected requests leave no writes');
addPeriod('2025-01-10 08:30:00', '2025-01-10 08:45:00');
check(PluginActualtimeTask::totalEndTime(10, TicketTask::class) === 2700, 'Adjacent periods total 45 minutes');
check(CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 2700, 'Accumulated duration updated');

resetFixture();
addPeriod('2025-01-10T08:00', '2025-01-10T08:30');
check($DB->timers[0]['actual_begin'] === '2025-01-10 08:00:00' && $DB->timers[0]['actual_end'] === '2025-01-10 08:30:00', 'Native values stored in canonical format');
check($DB->timers[0]['actual_actiontime'] === 1800 && CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 1800, 'Native inputs update duration');

resetFixture();
addPeriod('2025-01-10 08:00:00', '2025-01-10 08:07:00');
check($DB->timers[0]['actual_actiontime'] === 420, 'Actual duration stays seven minutes');
check(CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 900, 'Task duration follows 15 minute step');
resetFixture();
CommonDBTM::$records[PluginActualtimeConfig::class][1]['autoupdate_duration'] = false;
addPeriod();
check(CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 0, 'Auto update disabled is respected');
resetFixture();
$CFG_GLPI['time_step'] = 0;
addPeriod();
check(CommonDBTM::$records[TicketTask::class][10]['actiontime'] === 1800, 'Invalid step does not divide by zero');

foreach (['permission', 'private task', 'other entity', 'solved', 'closed', 'deleted', 'no login', 'inactive plugin'] as $case) {
    resetFixture();
    if ($case === 'permission') { Session::$right = false; }
    if ($case === 'private task') { CommonDBTM::$denied = ['TicketTask:10']; }
    if ($case === 'other entity') { CommonDBTM::$denied = ['Ticket:1']; }
    if ($case === 'solved') { CommonDBTM::$records[Ticket::class][1]['status'] = 5; }
    if ($case === 'closed') { CommonDBTM::$records[Ticket::class][1]['status'] = 6; }
    if ($case === 'deleted') { CommonDBTM::$records[Ticket::class][1]['is_deleted'] = 1; }
    if ($case === 'no login') { Session::$user = 0; }
    if ($case === 'inactive plugin') { Plugin::$active = false; }
    rejects(fn() => addPeriod(), 'Authorization: ' . $case);
    check($DB->timers === [], 'Denied action has no writes');
}
resetFixture();
foreach ([['UnexpectedClass', 10], [[], 10], [TicketTask::class, []], [TicketTask::class, -1], [TicketTask::class, 99]] as [$type, $id]) {
    check(!PluginActualtimeSourcetimer::canModify($type, $id), 'Reject unsupported object or identifier');
}
resetFixture();
addPeriod('2025-01-10 08:00:00', '2025-01-10 08:30:00', ProjectTask::class);
check($DB->timers[0]['users_id'] === 7, 'Project uses current user');
check(CommonDBTM::$records[ProjectTask::class][10]['effective_duration'] === 1800, 'Project duration updated');
check(Log::$entries[0]['type'] === Project::class, 'Project receives audit');
CommonDBTM::$records[ProjectTask::class][10]['projectstates_id'] = 9;
rejects(fn() => addPeriod('2025-01-10 09:00:00', '2025-01-10 09:30:00', ProjectTask::class), 'Finished project task rejected');
resetFixture();
CommonDBTM::$records[TicketTask::class][10]['users_id_tech'] = 0;
addPeriod();
check($DB->timers[0]['users_id'] === 7, 'Unassigned task uses current user');

foreach (['insert', 'duration', 'history', 'lock'] as $failure) {
    resetFixture();
    $before = CommonDBTM::$records;
    if ($failure === 'insert') { $DB->failInsert = true; }
    if ($failure === 'duration') { CommonDBTM::$failUpdate = true; }
    if ($failure === 'history') { Log::$fail = true; }
    if ($failure === 'lock') { $DB->failLock = true; }
    rejects(fn() => addPeriod(), 'Failure: ' . $failure);
    check($DB->timers === [] && Log::$entries === [] && CommonDBTM::$records === $before, 'Rollback: ' . $failure);
    check(!$DB->inTransaction(), 'Transaction closed after error');
}
resetFixture();
$DB->timers[] = [
    'id' => 100, 'itemtype' => TicketTask::class, 'items_id' => 10, 'users_id' => 42,
    'actual_begin' => '2025-01-10 09:00:00', 'actual_end' => null, 'actual_actiontime' => 0,
];
CommonDBTM::$records[PluginActualtimeConfig::class][1]['autoupdate_duration'] = false;
rejects(fn() => addPeriod('2025-01-10 09:15:00', '2025-01-10 09:30:00'), 'Overlap with active timer rejected');
addPeriod('2025-01-10 08:00:00', '2025-01-10 08:30:00');
check($DB->timers[0]['actual_end'] === null, 'Past manual timer does not stop active timer');

resetFixture();
ob_start();
(new PluginActualtimeSourcetimer())->modalForm(TicketTask::class, 10);
$html = ob_get_clean();
check(str_contains($html, 'manual_begin') && str_contains($html, 'manual_end'), 'Creation form exists without old timers');
check(str_contains($html, "name='add_manual'"), 'Rendering reaches the submit button');
check(substr_count($html, 'data-input') === 2 && !str_contains($html, 'type="datetime-local"'), 'Creation uses the original GLPI calendar');
check(substr_count($html, ' required') === 2, 'Creation calendars keep required validation');
check(str_contains($html, '_glpi_csrf_token') && str_contains($html, 'data-submit-once'), 'CSRF and submit-once markup');
check(substr_count($html, '<form ') === substr_count($html, '</form>'), 'Balanced forms without timers');
addPeriod();
ob_start();
(new PluginActualtimeSourcetimer())->modalForm(TicketTask::class, 10);
$html = ob_get_clean();
check(str_contains($html, 'actual_end['), 'Existing timer editor retained');
check(substr_count($html, 'data-input') === 4 && str_contains($html, '2025-01-10 08:30:00'), 'Existing start and end dates use the original GLPI calendar');
check(substr_count($html, '<form ') === 3 && substr_count($html, '</form>') === 3, 'Creation, editing and deletion have separate balanced forms');
check(substr_count($html, '<div') === substr_count($html, '</div>'), 'Balanced layout containers');

resetFixture();
$_SESSION['plugin_actualtime_manualtimer'][TicketTask::class][10] = [
    'manual_begin' => '2025-01-10 08:30:00',
    'manual_end' => '2025-01-10 08:00:00',
];
ob_start();
(new PluginActualtimeSourcetimer())->modalForm(TicketTask::class, 10);
$html = ob_get_clean();
check(str_contains($html, '2025-01-10 08:30:00') && str_contains($html, '2025-01-10 08:00:00'), 'Rejected dates repopulate GLPI calendars');
check(!isset($_SESSION['plugin_actualtime_manualtimer'][TicketTask::class][10]), 'Form state is consumed per task');
resetFixture();
$GLOBALS['actualtime_test_user_name'] = '<img src=x onerror="alert(1)"> & Técnico';
ob_start();
(new PluginActualtimeSourcetimer())->modalForm(TicketTask::class, 10);
$html = ob_get_clean();
check(str_contains($html, '&lt;img') && str_contains($html, '&amp; Técnico') && !str_contains($html, '<img'), 'Technician name remains escaped without framework globals');
foreach ([ChangeTask::class => Change::class, ProblemTask::class => Problem::class] as $taskType => $parentType) {
    resetFixture();
    CommonDBTM::$records[$parentType] = [1 => ['id' => 1, 'status' => 2]];
    CommonDBTM::$records[$taskType] = [10 => [
        'id' => 10,
        strtolower($parentType) . 's_id' => 1,
        'users_id_tech' => 42,
        'state' => 1,
        'actiontime' => 0,
    ]];
    addPeriod('2025-01-10 08:00:00', '2025-01-10 08:30:00', $taskType);
    check(Log::$entries[0]['type'] === $parentType && $DB->timers[0]['actual_actiontime'] === 1800, 'Additional task type: ' . $taskType);
}

if (!defined('ACTUALTIME_EXTENDED_TEST')) {
    echo "OK: $checks regression checks (GLPI adapters, no real database).\n";
}
