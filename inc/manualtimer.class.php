<?php

/**
 * Manual timer creation for the London fork of ActualTime.
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
