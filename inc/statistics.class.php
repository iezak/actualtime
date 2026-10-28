<?php

/**
 * Consistent live statistics and optional Reports integration.
 * Based on ActualTime, Copyright (C) 2018-2025 TICGAL Team.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class PluginActualtimeStatistics
{
    /** Capture one instant so parent and technician totals include the same time. */
    public static function parentTotals(CommonITILObject $parent, ?int $now = null): array
    {
        global $DB;
        $now = $now ?? time();
        $type = $parent->getTaskClass();
        $totals = ['actual_total' => 0, 'technicians' => []];
        foreach ($DB->request([
            'FROM' => $type::getTable(),
            'WHERE' => [$parent->getForeignKeyField() => $parent->getID()],
        ]) as $task) {
            $assigned = (int)$task['users_id_tech'];
            $totals['technicians'][$assigned] = $totals['technicians'][$assigned] ?? ['total' => 0, 'actual_total' => 0];
            $totals['technicians'][$assigned]['total'] += (int)$task['actiontime'];
            foreach ($DB->request([
                'FROM' => PluginActualtimeTask::getTable(),
                'WHERE' => ['itemtype' => $type, 'items_id' => (int)$task['id'], 'NOT' => ['actual_begin' => null]],
            ]) as $timer) {
                $user = (int)$timer['users_id'];
                $seconds = $timer['actual_end'] === null
                    ? max(0, $now - strtotime($timer['actual_begin']))
                    : max(0, (int)$timer['actual_actiontime']);
                $totals['technicians'][$user] = $totals['technicians'][$user] ?? ['total' => 0, 'actual_total' => 0];
                $totals['technicians'][$user]['actual_total'] += $seconds;
                $totals['actual_total'] += $seconds;
            }
        }
        ksort($totals['technicians']);
        return $totals;
    }

    private static function differencePercentSql(string $planned, string $actual): string
    {
        return "CONCAT(CASE WHEN $planned > 0 THEN ROUND(100.0 * ($planned - $actual) / $planned, 2) ELSE 0 END, '%')";
    }

    /** Aggregate timer rows before joining tasks: planned duration is counted once. */
    private static function ticketTotalsSql(): string
    {
        $timers = PluginActualtimeTask::getTable();
        return "SELECT glpi_tickettasks.tickets_id,
            COALESCE(SUM(glpi_tickettasks.actiontime), 0) AS duration,
            COALESCE(SUM(timer_totals.totalduration), 0) AS totalduration
        FROM glpi_tickettasks
        LEFT JOIN (
            SELECT items_id, SUM(actual_actiontime) AS totalduration
            FROM $timers
            WHERE itemtype = 'TicketTask' AND actual_begin IS NOT NULL AND actual_end IS NOT NULL
            GROUP BY items_id
        ) timer_totals ON timer_totals.items_id = glpi_tickettasks.id
        GROUP BY glpi_tickettasks.tickets_id";
    }

    /** Scope and criteria come only from GLPI and the Reports criteria classes. */
    public static function ticketReportSql(string $scope, string $criteria): string
    {
        $totals = self::ticketTotalsSql();
        $percent = self::differencePercentSql('task_totals.duration', 'task_totals.totalduration');
        return "SELECT DISTINCT glpi_tickets.id AS tickets_id,
            task_totals.duration, task_totals.totalduration,
            task_totals.duration - task_totals.totalduration AS diff,
            $percent AS diffpercent
        FROM glpi_tickets
        INNER JOIN ($totals) task_totals ON task_totals.tickets_id = glpi_tickets.id
        LEFT JOIN (
            SELECT DISTINCT tickets_id, users_id FROM glpi_tickets_users WHERE type = 1
        ) glpi_tickets_users ON glpi_tickets_users.tickets_id = glpi_tickets.id
        WHERE glpi_tickets.status = 6 AND glpi_tickets.is_deleted = 0
        $scope $criteria
        ORDER BY glpi_tickets.id";
    }

    public static function userReportSql(bool $by_requester, string $scope, string $criteria): string
    {
        if ($by_requester) {
            $totals = self::ticketTotalsSql();
            $planned = 'SUM(task_totals.duration)';
            $actual = 'SUM(task_totals.totalduration)';
            $percent = self::differencePercentSql($planned, $actual);
            return "SELECT requesters.users_id AS user_id,
                $planned AS duration, $actual AS totalduration,
                $planned - $actual AS diff, $percent AS diffpercent
            FROM glpi_tickets
            INNER JOIN ($totals) task_totals ON task_totals.tickets_id = glpi_tickets.id
            INNER JOIN (
                SELECT DISTINCT tickets_id, users_id FROM glpi_tickets_users WHERE type = 1
            ) requesters ON requesters.tickets_id = glpi_tickets.id
            WHERE glpi_tickets.status = 6 AND glpi_tickets.is_deleted = 0
            $scope $criteria
            GROUP BY requesters.users_id
            ORDER BY requesters.users_id";
        }
        $timers = PluginActualtimeTask::getTable();
        $planned = 'SUM(attributions.duration)';
        $actual = 'SUM(attributions.totalduration)';
        $percent = self::differencePercentSql($planned, $actual);
        return "SELECT attributions.user_id, $planned AS duration, $actual AS totalduration,
            $planned - $actual AS diff, $percent AS diffpercent
        FROM (
            SELECT glpi_tickettasks.users_id_tech AS user_id,
                glpi_tickettasks.actiontime AS duration, 0 AS totalduration
            FROM glpi_tickettasks
            INNER JOIN glpi_tickets ON glpi_tickets.id = glpi_tickettasks.tickets_id
            WHERE glpi_tickets.status = 6 AND glpi_tickets.is_deleted = 0
            $scope $criteria
            UNION ALL
            SELECT recorded.users_id AS user_id, 0 AS duration, recorded.actual_actiontime AS totalduration
            FROM $timers recorded
            INNER JOIN glpi_tickettasks ON glpi_tickettasks.id = recorded.items_id
            INNER JOIN glpi_tickets ON glpi_tickets.id = glpi_tickettasks.tickets_id
            WHERE recorded.itemtype = 'TicketTask' AND recorded.actual_begin IS NOT NULL AND recorded.actual_end IS NOT NULL
                AND glpi_tickets.status = 6 AND glpi_tickets.is_deleted = 0
            $scope $criteria
        ) attributions
        GROUP BY attributions.user_id
        ORDER BY attributions.user_id";
    }
}
