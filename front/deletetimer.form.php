<?php

/**
 * Confirm and delete a single completed timer in the London fork of ActualTime.
 * Based on ActualTime, Copyright (C) 2018-2025 TICGAL Team.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// GLPI validates CSRF before either POST reaches the confirmation or deletion.
include("../../../inc/includes.php");
Session::checkLoginUser();
Html::header_nocache();

$itemtype = $_POST['itemtype'] ?? null;
$task_id = $_POST['items_id'] ?? null;
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    || (!isset($_POST['preview_delete']) && !isset($_POST['confirm_delete']))
    || !(new Plugin())->isActivated('actualtime')
    || !PluginActualtimeSourcetimer::canModify($itemtype, $task_id)
) {
    Html::displayRightError();
}

$task_id = (int)$task_id;
$editor_url = Plugin::getWebDir('actualtime') . '/ajax/changetimer.php?itemtype=' . rawurlencode($itemtype) . '&task_id=' . $task_id;
try {
    if (isset($_POST['confirm_delete'])) {
        PluginActualtimeManualtimer::deleteTimer($itemtype, $task_id, $_POST);
        unset($_SESSION['plugin_actualtime_edittimers'][$itemtype][$task_id]);
        Session::addMessageAfterRedirect(__('Timer deleted.', 'actualtime'), false, INFO);
        Html::redirect($editor_url);
        exit;
    }
    $preview = PluginActualtimeManualtimer::deletionPreview($itemtype, $task_id, $_POST);
} catch (Throwable $error) {
    $expected = $error instanceof InvalidArgumentException || $error instanceof UnexpectedValueException;
    Session::addMessageAfterRedirect($expected ? $error->getMessage()
        : __('Could not delete the timer. No changes were saved.', 'actualtime'), false, ERROR);
    if (!$expected) {
        Toolbox::logInFile('php-errors', 'ActualTime timer deletion: ' . $error->getMessage() . "\n");
    }
    Html::redirect($editor_url);
    exit;
}

Html::popHeader(__('Delete timer', 'actualtime'), $_SERVER['PHP_SELF'], false, '', '', PluginActualtimeSourcetimer::getType());
PluginActualtimeSourcetimer::deletionForm($itemtype, $task_id, $preview['timer']);
Html::popFooter();
