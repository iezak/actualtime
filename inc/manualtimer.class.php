<?php

/**
 * Timer management for the London fork of ActualTime.
 * Based on ActualTime, Copyright (C) 2018-2025 TICGAL Team.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class PluginActualtimeManualtimer
{
    /** Accept GLPI timestamps and the local ISO format of browser date controls. */
    public static function normalizeDateTime($value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/D', $value)) {
            $value = str_replace('T', ' ', $value);
            if (strlen($value) === 16) {
                $value .= ':00';
            }
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
            throw new InvalidArgumentException(__('Enter valid start and end dates.', 'actualtime'));
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value || $date->getTimestamp() < 1) {
            throw new InvalidArgumentException(__('Enter valid start and end dates.', 'actualtime'));
        }
        return $value;
    }

    /** Validate the interval in the same local timezone used by GLPI/MySQL. */
    public static function validateInterval($begin, $end, ?DateTimeImmutable $now = null): array
    {
        $begin = self::normalizeDateTime($begin);
        $end = self::normalizeDateTime($end);
        $dates = [new DateTimeImmutable($begin), new DateTimeImmutable($end)];

        $seconds = $dates[1]->getTimestamp() - $dates[0]->getTimestamp();
        if ($seconds <= 0 || $seconds > 2147483647) {
            throw new InvalidArgumentException(__('The end must be later than the start.', 'actualtime'));
        }
        if ($dates[1] > ($now ?? new DateTimeImmutable())) {
            throw new InvalidArgumentException(__('A completed timer cannot end in the future.', 'actualtime'));
        }

        return ['actual_begin' => $begin, 'actual_end' => $end, 'actual_actiontime' => $seconds];
    }

    /** No posted user ID can change the person to whom time is attributed. */
    public static function getTechnician(CommonDBTM $task): int
    {
        return (int)(($task->fields['users_id_tech'] ?? 0) ?: Session::getLoginUserID());
    }

    public static function getEditableTask($itemtype, $items_id): CommonDBTM
    {
        if (!(new Plugin())->isActivated('actualtime') || !PluginActualtimeSourcetimer::canModify($itemtype, $items_id)) {
            throw new RuntimeException(__('You do not have permission to modify these timers.', 'actualtime'));
        }
        $task = new $itemtype();
        if (!$task->getFromDB((int)$items_id)) {
            throw new RuntimeException(__('You do not have permission to modify these timers.', 'actualtime'));
        }
        return $task;
    }

    /** Shared task lock for edits and native start/pause/stop operations. */
    public static function withTaskLock($itemtype, $items_id, callable $operation)
    {
        global $DB;
        if (
            !in_array($itemtype, [TicketTask::class, ChangeTask::class, ProblemTask::class, ProjectTask::class], true)
            || filter_var($items_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
        ) {
            throw new InvalidArgumentException(__('Invalid timer task.', 'actualtime'));
        }
        $connection = $DB;
        $owns_transaction = !$connection->inTransaction();
        if ($owns_transaction && !$connection->beginTransaction()) {
            throw new RuntimeException(__('Could not save the timer changes.', 'actualtime'));
        }
        try {
            if (!$connection->query(sprintf('SELECT id FROM %s WHERE id = %d FOR UPDATE', $connection->quoteName($itemtype::getTable()), (int)$items_id))) {
                throw new RuntimeException(__('Could not save the timer changes.', 'actualtime'));
            }
            $result = $operation();
            if ($owns_transaction && !$connection->commit()) {
                throw new RuntimeException(__('Could not save the timer changes.', 'actualtime'));
            }
            return $result;
        } catch (Throwable $error) {
            if ($owns_transaction) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    private static function updateDuration(CommonDBTM $task): void
    {
        global $CFG_GLPI;
        if (!(new PluginActualtimeConfig())->autoUpdateDuration()) {
            return;
        }
        $step = max(1, (int)($CFG_GLPI['time_step'] ?? 1)) * MINUTE_TIMESTAMP;
        $total = PluginActualtimeTask::totalEndTime($task->getID(), $task->getType());
        $field = array_key_exists('actiontime', $task->fields) ? 'actiontime' : 'effective_duration';
        if (!$task->update([
            'id' => $task->getID(),
            $field => (int)(ceil($total / $step) * $step),
            '_disablenotif' => true,
        ])) {
            throw new RuntimeException(__('Could not update the task duration.', 'actualtime'));
        }
    }

    /** Validate the whole batch before changing any timer. */
    public static function edit($itemtype, $items_id, array $input): int
    {
        global $DB;
        self::getEditableTask($itemtype, $items_id);
        foreach (['actual_begin', 'actual_end', 'original_begin', 'original_end'] as $field) {
            if (!isset($input[$field]) || !is_array($input[$field])) {
                throw new InvalidArgumentException(__('Close and reopen the timer editor before saving.', 'actualtime'));
            }
        }
        if (!$input['actual_begin']) {
            return 0;
        }
        return self::withTaskLock($itemtype, $items_id, static function () use ($itemtype, $items_id, $input, $DB) {
            $task = self::getEditableTask($itemtype, $items_id);
            $rows = [];
            foreach ($DB->request([
                'FROM' => PluginActualtimeTask::getTable(),
                'WHERE' => ['itemtype' => $itemtype, 'items_id' => (int)$items_id, 'NOT' => ['actual_begin' => null]],
                'ORDER' => ['id ASC'],
            ]) as $row) {
                $rows[(int)$row['id']] = $row;
            }
            $pending = $rows;
            $changes = [];
            $now = new DateTimeImmutable();
            foreach ($input['actual_begin'] as $id => $value) {
                if (
                    filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                    || !isset($rows[(int)$id])
                    || !array_key_exists($id, $input['actual_end'])
                    || !array_key_exists($id, $input['original_begin'])
                    || !array_key_exists($id, $input['original_end'])
                ) {
                    throw new InvalidArgumentException(__('One of the timers is unavailable. Reopen the editor.', 'actualtime'));
                }
                $id = (int)$id;
                $before = $rows[$id];
                $begin = self::normalizeDateTime($value);
                $end_value = $input['actual_end'][$id];
                $end = $end_value === '' ? null : self::normalizeDateTime($end_value);
                $original_begin = $input['original_begin'][$id];
                $original_end = $input['original_end'][$id];
                if (!is_string($original_begin) || !is_string($original_end)) {
                    throw new InvalidArgumentException(__('Close and reopen the timer editor before saving.', 'actualtime'));
                }
                // Untouched inputs must not overwrite changes made in another tab.
                if ($begin === $original_begin && (string)$end === $original_end) {
                    continue;
                }
                if ($original_begin !== (string)$before['actual_begin'] || $original_end !== (string)$before['actual_end']) {
                    throw new UnexpectedValueException(__('A timer changed while the editor was open. Review its latest values.', 'actualtime'));
                }
                if ($before['actual_end'] === null) {
                    if ($end !== null) {
                        throw new InvalidArgumentException(__('Pause or stop the running timer before changing its end.', 'actualtime'));
                    }
                    if (new DateTimeImmutable($begin) > $now) {
                        throw new InvalidArgumentException(__('A running timer cannot start in the future.', 'actualtime'));
                    }
                    $interval = ['actual_begin' => $begin, 'actual_end' => null, 'actual_actiontime' => 0];
                } else {
                    $interval = self::validateInterval($begin, $end, $now);
                }
                $pending[$id] = array_merge($before, $interval);
                $changes[$id] = ['before' => $before, 'interval' => $interval];
            }
            foreach ($changes as $id => $change) {
                $candidate = $pending[$id];
                foreach ($pending as $other_id => $other) {
                    if (
                        $id !== $other_id
                        && ($other['actual_end'] === null || $candidate['actual_begin'] < $other['actual_end'])
                        && ($candidate['actual_end'] === null || $other['actual_begin'] < $candidate['actual_end'])
                    ) {
                        throw new InvalidArgumentException(__('The interval overlaps an existing timer for this task.', 'actualtime'));
                    }
                }
            }
            foreach ($changes as $id => $change) {
                $before = $change['before'];
                $source = $DB->request([
                    'FROM' => PluginActualtimeSourcetimer::getTable(),
                    'WHERE' => ['plugin_actualtime_tasks_id' => $id],
                    'LIMIT' => 1,
                ]);
                if (!$source->current() && !$DB->insert(PluginActualtimeSourcetimer::getTable(), [
                    'plugin_actualtime_tasks_id' => $id,
                    'users_id' => Session::getLoginUserID(),
                    'source_end' => $before['actual_end'],
                    'source_actiontime' => $before['actual_end'] === null
                        ? max(0, $now->getTimestamp() - strtotime($before['actual_begin']))
                        : $before['actual_actiontime'],
                    'date_creation' => date('Y-m-d H:i:s'),
                ])) {
                    throw new RuntimeException(__('Could not save the timer changes.', 'actualtime'));
                }
                if (!$DB->update(PluginActualtimeTask::getTable(), $change['interval'] + ['is_modified' => 1], [
                    'id' => $id, 'itemtype' => $itemtype, 'items_id' => (int)$items_id,
                    'actual_begin' => $before['actual_begin'], 'actual_end' => $before['actual_end'],
                ]) || $DB->affectedRows() !== 1) {
                    throw new UnexpectedValueException(__('A timer changed while the editor was open. Review its latest values.', 'actualtime'));
                }
                $parent_type = $itemtype === ProjectTask::class ? Project::class : $task->getItilObjectItemType();
                $parent = new $parent_type();
                $message = sprintf(
                    __('Timer #%1$d, task #%2$d: %3$s / %4$s -> %5$s / %6$s.', 'actualtime'),
                    $id, $items_id, $before['actual_begin'], $before['actual_end'] ?? __('In progress'),
                    $change['interval']['actual_begin'], $change['interval']['actual_end'] ?? __('In progress')
                );
                if (!Log::history((int)$task->fields[$parent->getForeignKeyField()], $parent_type,
                    [0, '', $message], '', Log::HISTORY_LOG_SIMPLE_MESSAGE)) {
                    throw new RuntimeException(__('Could not save the timer changes.', 'actualtime'));
                }
            }
            if ($changes) {
                self::updateDuration($task);
            }
            return count($changes);
        });
    }

    /** Preserve only canonical dates and positive timer IDs after an error. */
    public static function formValues(array $input): array
    {
        $out = [];
        foreach (['actual_begin', 'actual_end', 'original_begin', 'original_end'] as $field) {
            $out[$field] = [];
            foreach (is_array($input[$field] ?? null) ? $input[$field] : [] as $id => $value) {
                if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    continue;
                }
                try {
                    $out[$field][(int)$id] = $value === '' ? '' : self::normalizeDateTime($value);
                } catch (InvalidArgumentException $error) {
                    $out[$field][(int)$id] = '';
                }
            }
        }
        return $out;
    }

    /** Create an ended segment without starting, pausing or completing the task. */
    public static function create($itemtype, $items_id, $begin, $end): int
    {
        global $DB, $CFG_GLPI;

        $task = self::getEditableTask($itemtype, $items_id);
        $interval = self::validateInterval($begin, $end);
        $items_id = (int)$items_id;

        // Serialize manual submissions for this task (including a retried POST).
        if ($DB->inTransaction() || !$DB->beginTransaction()) {
            throw new RuntimeException(__('Could not save the manual timer.', 'actualtime'));
        }

        try {
            if (!$DB->query(sprintf('SELECT `id` FROM `%s` WHERE `id` = %d FOR UPDATE', $task::getTable(), $items_id))) {
                throw new RuntimeException(__('Could not save the manual timer.', 'actualtime'));
            }
            // Reload after acquiring the lock: assignment/status may have changed.
            $task = self::getEditableTask($itemtype, $items_id);
            $query = [
                'FROM' => PluginActualtimeTask::getTable(),
                'WHERE' => [
                    'itemtype' => $itemtype,
                    'items_id' => $items_id,
                    'actual_begin' => ['<', $interval['actual_end']],
                    'OR' => [
                        'actual_end' => null,
                        ['actual_end' => ['>', $interval['actual_begin']]],
                    ],
                ],
                'LIMIT' => 1,
            ];
            foreach ($DB->request($query) as $overlap) {
                throw new InvalidArgumentException(__('The interval overlaps an existing timer for this task.', 'actualtime'));
            }

            $technician = self::getTechnician($task);
            if ($technician <= 0 || !$DB->insert(PluginActualtimeTask::getTable(), $interval + [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'users_id' => $technician,
                'origin_start' => PluginActualtimeTask::WEB,
                'origin_end' => PluginActualtimeTask::WEB,
            ])) {
                throw new RuntimeException(__('Could not save the manual timer.', 'actualtime'));
            }
            $timer_id = (int)$DB->insertId();

            $config = new PluginActualtimeConfig();
            if ($config->autoUpdateDuration()) {
                $step = max(1, (int)($CFG_GLPI['time_step'] ?? 1)) * MINUTE_TIMESTAMP;
                $total = PluginActualtimeTask::totalEndTime($items_id, $itemtype);
                $field = array_key_exists('actiontime', $task->fields) ? 'actiontime' : 'effective_duration';
                if (!$task->update([
                    'id' => $items_id,
                    $field => (int)(ceil($total / $step) * $step),
                    '_disablenotif' => true,
                ])) {
                    throw new RuntimeException(__('Could not update the task duration.', 'actualtime'));
                }
            }

            $parent_type = $itemtype === ProjectTask::class ? Project::class : $task->getItilObjectItemType();
            $parent = new $parent_type();
            $message = sprintf(
                __('Manual timer #%1$d, task #%2$d, technician #%3$d: %4$s - %5$s (%6$d seconds).', 'actualtime'),
                $timer_id,
                $items_id,
                $technician,
                $interval['actual_begin'],
                $interval['actual_end'],
                $interval['actual_actiontime']
            );
            if (!Log::history(
                (int)$task->fields[$parent->getForeignKeyField()],
                $parent_type,
                [0, '', $message],
                '',
                Log::HISTORY_LOG_SIMPLE_MESSAGE
            ) || !$DB->commit()) {
                throw new RuntimeException(__('Could not save the manual timer.', 'actualtime'));
            }
            return $timer_id;
        } catch (Throwable $error) {
            $DB->rollBack();
            throw $error;
        }
    }
}
