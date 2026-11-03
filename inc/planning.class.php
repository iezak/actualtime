<?php

/**
 * Timers for standalone GLPI PlanningExternalEvent items.
 * Based on ActualTime, Copyright (C) 2018-2025 TICGAL Team.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class PluginActualtimePlanning
{
    /** Match the planner's owner/guest/group visibility, as well as entity rights. */
    public static function canRead(CommonDBTM $event): bool
    {
        global $DB;
        return $event->can($event->getID(), READ)
            && (bool)$DB->request([
                'SELECT' => ['id'],
                'FROM' => PlanningExternalEvent::getTable(),
                'WHERE' => ['id' => $event->getID()] + PlanningExternalEvent::getVisibilityCriteria(),
                'LIMIT' => 1,
            ])->current();
    }

    /** Called from the native form, including the planner's AJAX edit dialog. */
    public static function postForm(array $params): void
    {
        $event = $params['item'];
        if ((int)$event->getID() <= 0) {
            echo "<p class='text-muted text-center'>" . __s('Save the event before starting a timer.', 'actualtime') . "</p>";
            return;
        }
        if (!empty($event->fields['background']) || !self::canRead($event)) {
            return;
        }
        $id = (int)$event->getID();
        $rand = mt_rand();
        $type = PlanningExternalEvent::class;
        $time = max(0, PluginActualtimeTask::totalEndTime($id, $type));
        $state = PluginActualtimeRuntime::taskState($type, $id);
        $toggle = $state['can_control'] && ($state['active'] || $state['can_start']) && !$state['done'];
        $stop = $state['can_control'] && !$state['done'] && ($state['active'] || $time > 0);
        $action = $state['active'] ? 'pause' : 'start';
        $title = $state['active'] ? __('Pause timer', 'actualtime') : ($time > 0 ? __('Resume timer', 'actualtime') : __('Start timer', 'actualtime'));
        $escape = static function ($text): string {
            return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        echo "<div class='card my-3 actualtime-planning'><div class='card-body'>";
        echo "<h4>ActualTime — " . __s('Actual Duration', 'actualtime') . "</h4>";
        echo "<div class='d-flex align-items-center justify-content-center gap-3 flex-wrap'>";
        echo "<strong id='actualtime_timer_{$type}_{$id}_{$rand}' data-actualtime-task='{$type}:{$id}' aria-live='off'>" . $escape(Html::timestampToString($time)) . "</strong>";
        if (PluginActualtimeRuntime::canControl($event)) {
            echo "<div class='btn-group'>";
            echo "<button type='button' class='btn btn-primary m-1' id='actualtime_button_{$type}_{$id}_1_{$rand}' action='{$action}' title='" . $escape($title) . "' aria-label='" . $escape($title) . "'" . ($toggle ? '' : ' disabled') . "><i class='fa-solid " . ($state['active'] ? 'fa-pause' : 'fa-play') . "'></i></button>";
            echo "<button type='button' class='btn btn-danger m-1' id='actualtime_button_{$type}_{$id}_2_{$rand}' action='end' title='" . __s('Stop timer', 'actualtime') . "' aria-label='" . __s('Stop timer', 'actualtime') . "'" . ($stop ? '' : ' disabled') . "><i class='fa-solid fa-stop'></i></button>";
            echo "</div>";
        }
        echo "</div>";
        if ($state['done']) {
            echo "<p class='text-muted text-center'>" . __s('Done') . "</p>";
        } elseif (!$state['can_start'] && !$state['active']) {
            $disable = PluginActualtimeTask::disableButton($event);
            if ($disable['message'] !== '') {
                echo "<p class='text-muted text-center'>" . $escape($disable['message']) . "</p>";
            }
        }
        if (!empty($event->fields['rrule'])) {
            echo "<p class='text-muted'>" . __s('Recurring events share the same accumulated time. Stopping completes the whole series.', 'actualtime') . "</p>";
        }
        if (PluginActualtimeSourcetimer::canModify($type, $id)) {
            $url = Plugin::getWebDir('actualtime') . '/ajax/changetimer.php?itemtype=' . $type . '&task_id=' . $id;
            $editor_id = "actualtime_planning_editor_{$id}_{$rand}";
            $button_id = "actualtime_planning_modify_{$id}_{$rand}";
            echo "<p><button type='button' class='btn btn-outline-secondary' id='{$button_id}' aria-haspopup='dialog' aria-controls='{$editor_id}'>" . __s('Modify timers', 'actualtime') . "</button></p>";
            echo Ajax::createIframeModalWindow($editor_id, $url, [
                'display' => false,
                'reloadonclose' => false,
                'dialog_class' => 'modal-xl',
                'title' => __s('Modify timers', 'actualtime'),
            ]);
            echo Html::scriptBlock(<<<JAVASCRIPT
$(function() {
    window.actualTime.bindPlanningEditor('{$button_id}', '{$editor_id}', {$id});
});
JAVASCRIPT
            );
        }
        echo "<div class='row text-center fw-bold'><div class='col-12 col-md-7'>" . __s('Start date') . "</div><div class='col-12 col-md-5'>" . __s('Partial actual duration', 'actualtime') . "</div></div>";
        echo "<div id='actualtime_segment_{$type}_{$id}_{$rand}'>" . PluginActualtimeTask::getSegment($id, $type) . "</div>";
        echo "</div></div>";
        echo Html::scriptBlock(<<<JAVASCRIPT
$(function() {
    $('#actualtime_button_{$type}_{$id}_1_{$rand}, #actualtime_button_{$type}_{$id}_2_{$rand}').on('click', function(event) {
        event.preventDefault();
        window.actualTime.pressedButton({$id}, '{$type}', $(this).attr('action'));
    });
    window.actualTime.fillCurrentTime({$id}, '{$type}', {$time});
    window.actualTime.refresh();
});
JAVASCRIPT
        );
    }

    /** Closing/reassigning/backgrounding the native event closes only its active period. */
    public static function preUpdate(CommonDBTM $event): CommonDBTM
    {
        if (isset($event->input['plugin_actualtime'])) {
            return $event;
        }
        $changed_owner = isset($event->input['users_id']) && (int)$event->input['users_id'] !== (int)$event->fields['users_id'];
        $done = isset($event->input['state']) && (int)$event->input['state'] === Planning::DONE;
        $background = !empty($event->input['background']);
        if (($changed_owner || $done || $background) && PluginActualtimeTask::checkTimerActive($event->getID(), $event->getType())) {
            $result = PluginActualtimeTask::pauseTimer($event->getID(), $event->getType(), PluginActualtimeTask::AUTO);
            if (($result['type'] ?? '') !== 'info') {
                $event->input = [];
                Session::addMessageAfterRedirect($result['message'], false, ERROR);
            }
        }
        return $event;
    }

    /** Purging an event removes its own periods and correction sources only. */
    public static function purge(CommonDBTM $event): void
    {
        global $DB;
        $where = ['itemtype' => PlanningExternalEvent::class, 'items_id' => (int)$event->getID()];
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => PluginActualtimeTask::getTable(), 'WHERE' => $where]) as $row) {
            $ids[] = (int)$row['id'];
        }
        if ($ids) {
            $DB->delete(PluginActualtimeSourcetimer::getTable(), ['plugin_actualtime_tasks_id' => $ids]);
        }
        $DB->delete(PluginActualtimeTask::getTable(), $where);
    }
}
