<?php

/**
 * Task-scoped runtime state for simultaneous ActualTime timers.
 * Based on ActualTime, Copyright (C) 2018-2025 TICGAL Team.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class PluginActualtimeRuntime
{
    public const TASK_TYPES = [TicketTask::class, ChangeTask::class, ProblemTask::class, ProjectTask::class];

    /** Read/update permission here is independent of the Modify timers right. */
    public static function taskContext($itemtype, $items_id, int $right = READ): array
    {
        if (
            !Session::getLoginUserID()
            || !in_array($itemtype, self::TASK_TYPES, true)
            || filter_var($items_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
        ) {
            throw new InvalidArgumentException(__('Invalid timer task.', 'actualtime'));
        }
        $task = new $itemtype();
        if (!$task->can((int)$items_id, $right) || !empty($task->fields['is_deleted'])) {
            throw new RuntimeException(__('You do not have permission to control this timer.', 'actualtime'));
        }
        $parent_type = $itemtype === ProjectTask::class ? Project::class : $task->getItilObjectItemType();
        $parent = getItemForItemtype($parent_type);
        if (
            !$parent || !$parent->can((int)$task->fields[$parent->getForeignKeyField()], READ)
            || !empty($parent->fields['is_deleted'])
        ) {
            throw new RuntimeException(__('You do not have permission to control this timer.', 'actualtime'));
        }
        return ['task' => $task, 'parent' => $parent];
    }

    public static function canControl(CommonDBTM $task): bool
    {
        return $task->can($task->getID(), UPDATE)
            && (isset($task->fields['users_id_tech'])
                ? (int)$task->fields['users_id_tech'] === (int)Session::getLoginUserID()
                : $task->canUpdateItem());
    }

    private static function statusFields(Ticket $ticket): array
    {
        $status = (int)$ticket->fields['status'];
        return [
            'value' => $status,
            'label' => $ticket::getStatus($status),
            'icon' => $ticket::getStatusClass($status),
            'options' => $ticket::getAllowedStatusArray($status),
        ];
    }

    /** Read the open ticket independently of task access or running timers. */
    public static function ticketState($tickets_id): array
    {
        if (!Session::getLoginUserID()
            || filter_var($tickets_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException(__('Invalid timer task.', 'actualtime'));
        }
        $ticket = new Ticket();
        if (!$ticket->can((int)$tickets_id, READ) || !empty($ticket->fields['is_deleted'])) {
            throw new RuntimeException(__('You do not have permission to control this timer.', 'actualtime'));
        }
        return [
            'parent_id' => (int)$ticket->getID(),
            'parent_type' => Ticket::class,
            'parent_status' => self::statusFields($ticket),
        ];
    }

    /** Each response identifies the selected task and its actual parent. */
    public static function taskState($itemtype, $items_id): array
    {
        ['task' => $task, 'parent' => $parent] = self::taskContext($itemtype, $items_id);
        $active = PluginActualtimeTask::checkTimerActive($items_id, $itemtype);
        $own_active = PluginActualtimeTask::checkUser($items_id, $itemtype, Session::getLoginUserID());
        $done = isset($task->fields['state']) ? (int)$task->fields['state'] !== 1 : false;
        if ($itemtype === ProjectTask::class) {
            global $DB;
            foreach ($DB->request(['FROM' => ProjectState::getTable(), 'WHERE' => ['is_finished' => 1]]) as $state) {
                if ((int)$state['id'] === (int)$task->fields['projectstates_id']) {
                    $done = true;
                }
            }
        }
        $disable = PluginActualtimeTask::disableButton($task);
        $state = [
            'task_id' => (int)$items_id, 'itemtype' => $itemtype,
            'parent_id' => (int)$parent->getID(), 'parent_type' => $parent->getType(),
            'name' => $parent->getTypeName(1), 'link' => $parent->getFormURLWithID($parent->getID()),
            'time' => max(0, PluginActualtimeTask::totalEndTime($items_id, $itemtype)),
            'active' => $active, 'own_active' => $own_active, 'done' => $done,
            'can_control' => self::canControl($task) && (!$active || $own_active),
            'can_start' => !$done && !$disable['disable']
                && (!($parent instanceof CommonITILObject) || (int)$parent->fields['status'] < CommonITILObject::SOLVED),
            'task_time' => (int)($task->fields['actiontime'] ?? $task->fields['effective_duration'] ?? 0),
        ];
        if ($parent instanceof Ticket) {
            $state['parent_status'] = self::statusFields($parent);
        }
        return $state;
    }

    /** All current-user timers the active profile is allowed to read. */
    public static function runningTimers(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request([
            'FROM' => PluginActualtimeTask::getTable(),
            'WHERE' => ['users_id' => Session::getLoginUserID(), 'actual_end' => null, 'NOT' => ['actual_begin' => null]],
            'ORDER' => ['actual_begin ASC', 'id ASC'],
        ]) as $row) {
            try {
                $state = self::taskState($row['itemtype'], $row['items_id']);
                if (!$state['own_active']) {
                    continue;
                }
                $state['timer_id'] = (int)$row['id'];
                $out[] = $state;
            } catch (InvalidArgumentException | RuntimeException $error) {
                // A private task or a different active entity must not leak here.
            }
        }
        return $out;
    }

    /** Read-only snapshots also reconcile timers changed in another tab. */
    public static function footerData(array $watch = [], $tickets_id = null): array
    {
        $out = ['rand' => mt_rand(), 'show_popup' => (new PluginActualtimeConfig())->showTimerPopup()];
        foreach (['d' => ['day', 'days'], 'h' => ['hour', 'hours'], 'min' => ['minute', 'minutes'], 's' => ['second', 'seconds']] as $short => [$single, $plural]) {
            $out['symb_' . $short] = __('%d' . $short, 'actualtime');
            $out['symb_' . $single] = _n('%d ' . $single, '%d ' . $plural, 1);
            $out['symb_' . $plural] = _n('%d ' . $single, '%d ' . $plural, 2);
        }
        $out += [
            'text_warning' => __('Warning'), 'text_pause' => __('Pause timer', 'actualtime'),
            'text_start' => __('Start timer', 'actualtime'), 'text_restart' => __('Resume timer', 'actualtime'),
            'text_stop' => __('Stop timer', 'actualtime'), 'text_done' => __('Done'),
            'text_running' => __('Running tasks (%d)', 'actualtime'),
            'text_task' => __('Task #%d', 'actualtime'),
            'text_error' => __('Could not update the timer. Refresh the task and try again.', 'actualtime'),
        ];
        $out['active'] = self::runningTimers();
        $states = [];
        foreach ($out['active'] as $state) {
            $states[$state['itemtype'] . ':' . $state['task_id']] = $state;
        }
        foreach (array_slice($watch, 0, 100) as $key) {
            if (!is_string($key) || !preg_match('/^(TicketTask|ChangeTask|ProblemTask|ProjectTask):([1-9][0-9]*)$/D', $key, $parts) || isset($states[$key])) {
                continue;
            }
            try {
                $states[$key] = self::taskState($parts[1], $parts[2]);
            } catch (InvalidArgumentException | RuntimeException $error) {
                // Permission can change while the page remains open.
            }
        }
        $out['states'] = array_values($states);
        if ($tickets_id !== null) {
            try {
                $out['ticket'] = self::ticketState($tickets_id);
            } catch (InvalidArgumentException | RuntimeException $error) {
                // Invalid, deleted and inaccessible tickets expose no status.
            }
        }
        return $out;
    }
}
