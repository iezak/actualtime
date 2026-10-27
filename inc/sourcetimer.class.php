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

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class PluginActualtimeSourcetimer extends CommonDBTM
{
    public static $rightname = 'plugin_actualtime_sourcetimer';
    public const TICKET     = 1024;
    public const CHANGE     = 2048;
    public const PROJECT    = 4096;
    public const PROBLEM    = 8192;

    /**
     * getRights
     *
     * @param  mixed $interface
     * @return array
     */
    public function getRights($interface = 'central'): array
    {
        if ($interface == 'central') {
            return [
                self::TICKET    => __('Modify tickets', 'actualtime'),
                self::CHANGE    => __('Modify changes', 'actualtime'),
                self::PROJECT   => __('Modify projects', 'actualtime'),
                self::PROBLEM   => __('Modify problems', 'actualtime'),
            ];
        }
        return [];
    }

    /**
     * checkItemtypeRight
     *
     * @param  mixed $itemtype
     * @return bool
     */
    public static function checkItemtypeRight($itemtype): bool
    {
        switch ($itemtype) {
            case 'TicketTask':
                return Session::haveRight(self::$rightname, self::TICKET);
            case 'ChangeTask':
                return Session::haveRight(self::$rightname, self::CHANGE);
            case 'ProblemTask':
                return Session::haveRight(self::$rightname, self::PROBLEM);
            case 'ProjectTask':
                return Session::haveRight(self::$rightname, self::PROJECT);
            default:
                return false;
        }
    }

    /**
     * canModify
     *
     * @param  mixed $itemtype
     * @param  mixed $items_id
     * @return bool
     */
    public static function canModify($itemtype, $items_id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (
            !Session::getLoginUserID()
            || !is_string($itemtype)
            || filter_var($items_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || !self::checkItemtypeRight($itemtype)
        ) {
            return false;
        }

        $task = new $itemtype();
        if (!$task->can((int)$items_id, READ) || !empty($task->fields['is_deleted'])) {
            return false;
        }

        $parent_type = $itemtype === ProjectTask::class ? Project::class : $task->getItilObjectItemType();
        $parent = getItemForItemtype($parent_type);
        if (
            !$parent
            || !$parent->can((int)$task->fields[$parent->getForeignKeyField()], READ)
            || !empty($parent->fields['is_deleted'])
        ) {
            return false;
        }

        if ($itemtype !== ProjectTask::class) {
            return (int)$parent->fields['status'] < CommonITILObject::SOLVED;
        }
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => ProjectState::getTable(),
            'WHERE' => ['is_finished' => 1],
        ]) as $state) {
            if ((int)$task->getField('projectstates_id') === (int)$state['id']) {
                return false;
            }
        }
        return true;
    }

    /**
     * postShowItem
     *
     * @param  mixed $params
     * @return void
     */
    public static function postShowItem($params): void
    {
        $item = $params['item'];
        if (!is_object($item) || !method_exists($item, 'getType')) {
           // Sometimes, params['item'] is just an array, like 'Solution'
            return;
        }
        $itemtype = $item->getType();
        if (!self::checkItemtypeRight($itemtype)) {
            return;
        }
        if (!self::canModify($itemtype, $item->getID())) {
            return;
        }

        $task_id = $item->getID();

        $html = "<div class='dropdown ms-2'>";
        $html .= "<a href='#' data-bs-toggle='modal' data-bs-target='#add_time_{$task_id}'>";
        $html .= "<span class='fas fa-calendar-plus control_item' title='" . __("Modify timers", "actualtime");
        $html .= "'></span>";
        $html .= "</a></div>";
        $script = <<<JAVASCRIPT
$(document).ready(function() {
	$("div[data-itemtype='{$itemtype}'][data-items-id='{$task_id}'] div.timeline-item-buttons").prepend("{$html}");
});
JAVASCRIPT;
        echo Html::scriptBlock($script);
        echo Ajax::createIframeModalWindow(
            'add_time_' . $task_id,
            Plugin::getWebDir('actualtime') . "/ajax/changetimer.php?itemtype=" . $itemtype . "&task_id=" . $task_id,
            [
                'reloadonclose' => true,
                'dialog_class'  => 'modal-xl',
                'title'         => __('Modify timers', 'actualtime')
            ]
        );
    }

    /**
     * Native controls avoid a dependency on Flatpickr initialization in the iframe.
     * The server normalizes their local ISO values before validating or saving.
     */
    public static function showDateTimeField(string $name, $value = '', bool $required = false, bool $past_only = false): void
    {
        $display_value = '';
        if ($value !== '') {
            try {
                $display_value = str_replace(' ', 'T', PluginActualtimeManualtimer::normalizeDateTime($value));
            } catch (InvalidArgumentException $error) {
                // Leave invalid dates empty; never pass unvalidated attributes.
            }
        }
        $id = 'actualtime_datetime_' . Html::cleanId($name);
        echo '<input type="datetime-local" class="form-control" step="1"';
        echo ' id="' . htmlescape($id) . '" name="' . htmlescape($name) . '"';
        echo ' value="' . htmlescape($display_value) . '"';
        if ($required) {
            echo ' required';
        }
        if ($past_only) {
            echo ' max="' . htmlescape(date('Y-m-d\TH:i:s')) . '"';
        }
        echo '>';
    }

    /**
     * modalForm
     *
     * @param  mixed $itemtype
     * @param  mixed $items_id
     * @return void
     */
    public function modalForm($itemtype, $items_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $task = PluginActualtimeManualtimer::getEditableTask($itemtype, $items_id);
        $items_id = (int)$items_id;
        $saved = $_SESSION['plugin_actualtime_manualtimer'][$itemtype][$items_id] ?? [];
        unset($_SESSION['plugin_actualtime_manualtimer'][$itemtype][$items_id]);

        echo "<div class='card-body'>";
        echo "<h3>" . __('Add a manual timer', 'actualtime') . "</h3>";
        echo "<p class='text-muted'>" . __('Enter the start and end of the period worked.', 'actualtime') . "</p>";
        echo "<form method='post' action='" . Plugin::getWebDir('actualtime') . "/front/manualtimer.form.php' data-submit-once>";
        echo Html::hidden('itemtype', ['value' => $itemtype]);
        echo Html::hidden('items_id', ['value' => $items_id]);
        echo "<div class='mb-3'><label class='form-label' for='actualtime_datetime_manual_begin'>" . __('Start date') . "</label>";
        self::showDateTimeField('manual_begin', $saved['manual_begin'] ?? '', true, true);
        echo "</div><div class='mb-3'><label class='form-label' for='actualtime_datetime_manual_end'>" . __('End date') . "</label>";
        self::showDateTimeField('manual_end', $saved['manual_end'] ?? '', true, true);
        echo "</div><p>" . __('Technician') . ": ";
        echo htmlescape(getUserName(PluginActualtimeManualtimer::getTechnician($task))) . "</p>";
        echo "<button class='btn btn-primary' type='submit' name='add_manual' value='1'>";
        echo "<i class='fas fa-plus me-1'></i>" . __('Add a manual timer', 'actualtime') . "</button>";
        Html::closeForm();
        echo "</div>";

        $query = [
            'FROM' => PluginActualtimeTask::getTable(),
            'WHERE' => [
                'items_id' => $items_id,
                'itemtype' => $itemtype,
                'NOT' => ['actual_end' => null],
            ],
            'ORDER' => ['actual_begin ASC', 'id ASC'],
        ];

        $timers = $DB->request($query);
        if (count($timers) === 0) {
            return;
        }

        echo "<div class='card-body border-top'>";
        echo "<h3>" . __('Existing timers', 'actualtime') . "</h3>";
        echo "<form name='form' id='form' method='post' action='" . $this->getFormURL() . "' data-submit-once>";
        echo Html::hidden('itemtype', ['value' => $itemtype]);
        echo Html::hidden('items_id', ['value' => $items_id]);
        foreach ($timers as $data) {
            echo "<div class='card-body row'>";

            echo "<div class='form-field row col-12 mb-2'>";
            echo "<label class='col-form-label col-2 text-xxl-end'>" . __('Start date') . "</label>";
            echo "<label class='col-form-label col-2'>" . htmlescape($data['actual_begin']) . "</label>";
            $field_id = 'actualtime_datetime_' . Html::cleanId('actual_end[' . $data['id'] . ']');
            echo "<label class='col-form-label col-2 text-xxl-end' for='" . htmlescape($field_id) . "'>" . __('End date') . "</label>";
            echo "<div class='col-6  field-container'>";
            self::showDateTimeField('actual_end[' . $data['id'] . ']', $data['actual_end']);
            echo "</div>";

            echo "</div>";
            echo "</div>";
        }

        echo "<div class='card-body mx-n2 mb-4 border-top d-flex flex-row-reverse align-items-start flex-wrap'>";
        echo "<button class='btn btn-primary me-2' type='submit' name='update' value='1'>";
        echo "<i class='far fa-save'></i>";
        echo "<span>" . _x('button', 'Save') . "</span>";
        echo "</button>";
        echo "</div>";
        Html::closeForm();
        echo "</div>";
    }

    /**
     * install
     *
     * @param  Migration $migration
     * @return void
     */
    public static function install(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");

            $query = "CREATE TABLE IF NOT EXISTS $table (
                `id` int {$default_key_sign} NOT NULL auto_increment,
                `plugin_actualtime_tasks_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `users_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `source_end` TIMESTAMP NULL DEFAULT NULL,
                `source_actiontime` int {$default_key_sign} NOT NULL DEFAULT 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `plugin_actualtime_tasks_id` (`plugin_actualtime_tasks_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB  DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
            $DB->doQueryOrDie($query, $DB->error());
        }
    }
}
