<?php

/**
 * -------------------------------------------------------------------------
 * ActualTime plugin for GLPI
 * Copyright (C) 2018-2025 by the TICGAL Team.
 * https://www.tic.gal/
 * -------------------------------------------------------------------------
 * LICENSE
 * This file is part of the ActualTime plugin.
 * ActualTime plugin is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 * ActualTime plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along withOneTimeSecret. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @package   ActualTime
 * @author    the TICGAL team
 * @copyright Copyright (c) 2018-2025 TICGAL team
 * @license   AGPL License 3.0 or (at your option) any later version
 *            http://www.gnu.org/licenses/agpl-3.0-standalone.html
 * @link      https://www.tic.gal/
 * @since     2018
 * -------------------------------------------------------------------------
 */

include("../../../inc/includes.php");
Html::header_nocache();
Session::checkLoginUser();
if (!(new Plugin())->isActivated('actualtime')) {
    Html::displayRightError();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header("Content-Type: application/json; charset=UTF-8");
    $type = $_POST['itemtype'] ?? null;
    $task_id = $_POST['task_id'] ?? null;
    switch ($_POST['action']) {
        case 'start':
            $result = PluginActualtimeTask::startTimer($task_id, $type, PluginActualtimeTask::WEB);
            break;
        case 'end':
            $result = PluginActualtimeTask::stopTimer($task_id, $type, PluginActualtimeTask::WEB);
            break;
        case 'pause':
            $result = PluginActualtimeTask::pauseTimer($task_id, $type, PluginActualtimeTask::WEB);
            break;
        case 'count':
            try {
                PluginActualtimeRuntime::taskContext($type, $task_id);
                $result = max(0, PluginActualtimeTask::totalEndTime($task_id, $type));
            } catch (InvalidArgumentException | RuntimeException $error) {
                Html::displayRightError();
            }
            break;
        default:
            http_response_code(400);
            $result = ['type' => 'warning', 'message' => __('Invalid timer task.', 'actualtime')];
    }
    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['footer'])) {
    header("Content-Type: application/json; charset=UTF-8");
    $raw = $_GET['watch'] ?? '';
    $watch = is_string($raw) && strlen($raw) <= 16384 ? json_decode($raw, true) : [];
    echo json_encode(PluginActualtimeRuntime::footerData(is_array($watch) ? $watch : []));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_GET['showform'])) {
    Html::displayRightError();
}
$itemtype = $_GET['itemtype'] ?? null;
$task_id = $_GET['task_id'] ?? null;
try {
    ['task' => $item, 'parent' => $parent] = PluginActualtimeRuntime::taskContext($itemtype, $task_id);
} catch (InvalidArgumentException | RuntimeException $error) {
    Html::displayRightError();
}
header("Content-Type: text/html; charset=UTF-8");
echo "<div class='modal-header'><h4 class='modal-title'>" . __('Update of a task') . " #" . (int)$task_id . "</h4>";
echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . __s('Close') . "'></button></div>";
echo "<div class='modal-body'><div class='center'>";
echo "<a class='btn btn-outline-secondary' href='" . htmlspecialchars($parent->getFormURLWithID($parent->getID()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "'>";
echo "<i class='ti ti-eye'></i><span>" . __s("View this item in its context") . "</span></a></div><hr>";
$item->showForm((int)$task_id, ['parent' => $parent]);
echo "</div>";
