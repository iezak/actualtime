<?php

/**
 * Manual timer creation for the London fork of ActualTime.
 * Based on ActualTime, Copyright (C) 2018-2025 TICGAL Team.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// GLPI validates the form's CSRF token in this bootstrap, before any write.
include("../../../inc/includes.php");

Session::checkLoginUser();

$itemtype = $_POST['itemtype'] ?? null;
$task_id = $_POST['items_id'] ?? null;
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_POST['add_manual'])
    || !(new Plugin())->isActivated('actualtime')
    || !PluginActualtimeSourcetimer::canModify($itemtype, $task_id)
) {
    Html::displayRightError();
}

$task_id = (int)$task_id;
$values = [
    'manual_begin' => $_POST['manual_begin'] ?? '',
    'manual_end' => $_POST['manual_end'] ?? '',
];
try {
    PluginActualtimeManualtimer::create($itemtype, $task_id, $values['manual_begin'], $values['manual_end']);
    unset($_SESSION['plugin_actualtime_manualtimer'][$itemtype][$task_id]);
    Session::addMessageAfterRedirect(__('Manual timer added.', 'actualtime'), false, INFO);
} catch (Throwable $error) {
    // Keep canonical dates only: GLPI's widget expects a date and a time part.
    foreach ($values as &$value) {
        $value = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) ? $value : '';
    }
    unset($value);
    $_SESSION['plugin_actualtime_manualtimer'][$itemtype][$task_id] = $values;
    $message = $error instanceof InvalidArgumentException
        ? $error->getMessage()
        : __('Could not save the manual timer. No changes were saved.', 'actualtime');
    Session::addMessageAfterRedirect($message, false, ERROR);
    if (!($error instanceof InvalidArgumentException)) {
        Toolbox::logInFile('php-errors', "ActualTime manual timer: " . $error->getMessage() . "\n");
    }
}

Html::redirect(Plugin::getWebDir('actualtime') . '/ajax/changetimer.php?itemtype=' . rawurlencode($itemtype) . '&task_id=' . $task_id);
