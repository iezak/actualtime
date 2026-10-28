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

$USEDBREPLICATE = 1;
$DBCONNECTION_REQUIRED = 0;

include("../../../../inc/includes.php");
Session::checkLoginUser();
if (!(new Plugin())->isActivated('actualtime')) {
    Html::displayRightError();
}

$report = new PluginReportsAutoReport(__('ActualTimeTotal'));
//Filtro fecha
new PluginReportsDateIntervalCriteria(
    $report,
    'glpi_tickets.closedate',
    __("Close date")
);
//Filtro usuario
new PluginReportsDropdownCriteria(
    $report,
    "glpi_tickets_users.users_id",
    "glpi_users",
    __("Requester")
);

$report->displayCriteriasForm();
$report->setColumns([
    new PluginReportsColumnLink(
        'tickets_id',
        __('Ticket'),
        'Ticket',
        [
            'with_navigate' => true
        ]
    ),
    new PluginReportsColumnTimestamp(
        'duration',
        __("Total duration")
    ),
    new PluginReportsColumnTimestamp(
        'totalduration',
        "ActualTime - " . __("Total duration")
    ),
    new PluginReportsColumnTimestamp(
        'diff',
        __("Duration Diff", "actiontime")
    ),
    new PluginReportsColumn(
        'diffpercent',
        __("Duration Diff", "actiontime") . " (%)"
    )
]);
$query = PluginActualtimeStatistics::ticketReportSql(
    getEntitiesRestrictRequest('AND', 'glpi_tickets'),
    $report->addSqlCriteriasRestriction()
);
$report->setSqlRequest($query);
$report->execute();
