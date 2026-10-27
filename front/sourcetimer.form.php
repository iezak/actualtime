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

// GLPI validates the CSRF token in this bootstrap.
include("../../../inc/includes.php");

Session::checkLoginUser();
$itemtype = $_POST['itemtype'] ?? null;
$task_id = $_POST['items_id'] ?? null;
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_POST['update'])
    || !(new Plugin())->isActivated('actualtime')
    || !PluginActualtimeSourcetimer::canModify($itemtype, $task_id)
) {
    Html::displayRightError();
}

$task_id = (int)$task_id;
try {
    $changed = PluginActualtimeManualtimer::edit($itemtype, $task_id, $_POST);
    unset($_SESSION['plugin_actualtime_edittimers'][$itemtype][$task_id]);
    Session::addMessageAfterRedirect($changed
        ? __('Timer changes saved.', 'actualtime')
        : __('No timer changes to save.', 'actualtime'), false, INFO);
} catch (Throwable $error) {
    $_SESSION['plugin_actualtime_edittimers'][$itemtype][$task_id] = PluginActualtimeManualtimer::formValues($_POST);
    $expected = $error instanceof InvalidArgumentException || $error instanceof UnexpectedValueException;
    Session::addMessageAfterRedirect($expected ? $error->getMessage()
        : __('Could not save the timer changes. No changes were saved.', 'actualtime'), false, ERROR);
    if (!$expected) {
        Toolbox::logInFile('php-errors', 'ActualTime timer edit: ' . $error->getMessage() . "\n");
    }
}

Html::redirect(Plugin::getWebDir('actualtime') . '/ajax/changetimer.php?itemtype=' . rawurlencode($itemtype) . '&task_id=' . $task_id);
