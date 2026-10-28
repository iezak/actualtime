"""Execute the generated Reports SQL against relational fixtures in SQLite.

SQLite checks aggregation/filter semantics; full GLPI/MySQL remains a deployment
check. PHP_BINARY selects PHP. SPDX-License-Identifier: AGPL-3.0-or-later
"""
import json
import os
from pathlib import Path
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[2]
queries = json.loads(subprocess.check_output(
    [os.environ.get("PHP_BINARY", "php"), str(root / "tests/regression/statistics.php"), "--sql"],
    text=True,
))
db = sqlite3.connect(":memory:")
db.row_factory = sqlite3.Row
db.create_function("CONCAT", -1, lambda *args: None if None in args else "".join(map(str, args)))
db.executescript("""
CREATE TABLE glpi_tickets (id INTEGER, status INTEGER, entities_id INTEGER, is_deleted INTEGER, closedate TEXT);
CREATE TABLE glpi_tickettasks (id INTEGER, tickets_id INTEGER, users_id_tech INTEGER, actiontime INTEGER);
CREATE TABLE glpi_tickets_users (tickets_id INTEGER, users_id INTEGER, type INTEGER);
CREATE TABLE glpi_plugin_actualtime_tasks (id INTEGER, items_id INTEGER, itemtype TEXT, users_id INTEGER,
    actual_begin TEXT, actual_end TEXT, actual_actiontime INTEGER);
INSERT INTO glpi_tickets VALUES
 (1,6,1,0,'2025-01-31 23:59:59'),(2,6,1,0,'2025-02-01 00:00:00'),
 (3,6,2,0,'2025-01-31 12:00:00'),(4,2,1,0,NULL),(5,6,1,1,'2025-01-31 12:00:00'),
 (6,6,1,0,'2025-01-31 12:00:00'),(7,6,1,0,'2025-01-31 12:00:00');
INSERT INTO glpi_tickettasks VALUES
 (10,1,99,2700),(11,1,42,900),(20,2,42,600),(30,3,42,10000),
 (40,4,42,10000),(50,5,42,10000),(60,6,42,0),(70,7,42,900);
INSERT INTO glpi_tickets_users VALUES
 (1,100,1),(1,101,1),(1,100,1),(1,99,2),(1,200,3),
 (2,100,1),(3,100,1),(4,100,1),(5,100,1),(6,102,1),(7,103,1);
INSERT INTO glpi_plugin_actualtime_tasks VALUES
 (1,10,'TicketTask',42,'2025-01-10 08:00:00','2025-01-10 08:15:00',900),
 (2,10,'TicketTask',42,'2025-01-10 08:15:00','2025-01-10 08:30:00',900),
 (3,10,'TicketTask',99,'2025-01-10 09:00:00','2025-01-10 09:05:00',300),
 (4,11,'TicketTask',42,'2025-01-10 09:30:00','2025-01-10 09:40:00',600),
 (5,20,'TicketTask',42,'2025-02-01 08:00:00','2025-02-01 08:05:00',300),
 (6,30,'TicketTask',42,'2025-01-10 08:00:00','2025-01-10 09:00:00',3600),
 (7,40,'TicketTask',42,'2025-01-10 08:00:00','2025-01-10 09:00:00',3600),
 (8,50,'TicketTask',42,'2025-01-10 08:00:00','2025-01-10 09:00:00',3600),
 (9,10,'ProjectTask',42,'2025-01-10 08:00:00','2025-01-10 09:00:00',99999),
 (10,10,'TicketTask',42,'2025-01-10 10:00:00',NULL,0),
 (11,10,'TicketTask',42,NULL,NULL,0);
""")
checks = 0


def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)


def rows(name):
    return [dict(row) for row in db.execute(queries[name])]


tickets = {row["tickets_id"]: row for row in rows("tickets")}
check(set(tickets) == {1, 2, 6, 7}, "Closed, nondeleted tickets in the selected entity only")
check(tickets[1]["duration"] == 3600, "Planned duration counted once despite many timer rows/requesters")
check(tickets[1]["totalduration"] == 2700, "Completed TicketTask periods only, including previous technicians")
check(tickets[1]["diff"] == 900 and float(tickets[1]["diffpercent"].rstrip("%")) == 25, "Difference percentage uses planned duration")
check(tickets[6]["totalduration"] == 0 and float(tickets[6]["diffpercent"].rstrip("%")) == 0, "Zero duration has no null/division error")
check(tickets[7]["duration"] == 900 and tickets[7]["totalduration"] == 0, "Tasks without timers retained")
check({row["tickets_id"] for row in rows("date")} == {1, 6, 7}, "Close-date filter includes the whole final day")
check({row["tickets_id"] for row in rows("requester")} == {1, 2}, "Requester criterion uses the existing qualified column")
check(rows("no_entity") == [], "Entity restriction enforced")
techs = {row["user_id"]: row for row in rows("technicians")}
check(set(techs) == {42, 99}, "Technician mode does not depend on ticket actor membership")
check(techs[42]["duration"] == 2400 and techs[42]["totalduration"] == 2700, "Old technician retains recorded periods after reassignment")
check(techs[99]["duration"] == 2700 and techs[99]["totalduration"] == 300, "Current assignee planning does not absorb previous worker time")
check(sum(row["duration"] for row in techs.values()) == sum(row["duration"] for row in tickets.values()), "Technician planned totals equal ticket totals")
check(sum(row["totalduration"] for row in techs.values()) == sum(row["totalduration"] for row in tickets.values()), "Technician actual totals equal ticket totals")
requesters = {row["user_id"]: row for row in rows("requesters")}
check(set(requesters) == {100, 101, 102, 103}, "Requester mode excludes technician/observer roles")
check(requesters[100]["duration"] == 4200 and requesters[100]["totalduration"] == 3000, "Duplicate requester records do not multiply time")
check(requesters[101]["duration"] == 3600 and requesters[101]["totalduration"] == 2700, "Different requesters each see the whole ticket once")
dated = {row["user_id"]: row for row in rows("tech_date")}
check(dated[42]["duration"] == 1800 and dated[42]["totalduration"] == 2400, "Date scope applies to both technician aggregates")
check({row["user_id"]: row for row in rows("requester_date")}[100]["duration"] == 3600, "Date scope applies in requester mode")
db.execute("UPDATE glpi_plugin_actualtime_tasks SET actual_actiontime=1200 WHERE id=3")
check({row["tickets_id"]: row for row in rows("tickets")}[1]["totalduration"] == 3600, "Reports reflect edited duration")
db.execute("DELETE FROM glpi_plugin_actualtime_tasks WHERE id=1")
check({row["tickets_id"]: row for row in rows("tickets")}[1]["totalduration"] == 2700, "Reports reflect deletion without multiplying task duration")
print(f"OK: {checks} relational report checks (SQLite, generated PHP SQL).")
