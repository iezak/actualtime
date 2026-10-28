# Actualtime

## [3.2.5-london.1] - 2026-10-28

### Fixed

- Include the same running elapsed time in parent and technician statistics.
  Attribute actual time to the technician saved on each period; task duration
  remains attributed to the current task assignee.
- Safely escape technician names and JSON-encode the statistics HTML embedded
  in JavaScript, including names containing quotes or markup.
- Repair the optional Reports integration for the items_id/itemtype schema,
  replacing the removed tasks_id reference.
- Aggregate timer periods before joining tasks and deduplicate requesters so
  task duration is counted once. Keep tasks without periods and zero totals.
- Apply active-entity scope to both reports, preserve close-date filters and
  native Reports permissions, and align percentage differences with task duration.
- Add combined-workflow, statistics-rendering and relational report regressions.

## [3.2.4-london.1] - 2026-10-27

### Added

- London stage 4: allow a technician to run different tasks simultaneously while
  keeping the shared task lock and one active timer per task.
- Track each counter by task type and ID; pausing/ending one leaves others
  running, including equal numeric IDs across different task types.
- List all readable current-user timers in the popup and open the selected task
  explicitly. Reconcile state after reload, focus, cross-tab actions and periodic
  refresh, including when the popup setting is disabled.
- Enforce task update and parent read access for web controls; retain assignment,
  planning and existing integration checks. Native write failures now roll back.

### Fixed

- Resolve each start's parent from the selected task rather than the user's first
  active timer, including the existing Gapp push payload.
- Scope completion form updates to the selected task, preserve unrelated forms,
  and reject stale client snapshots after actions.
- Keep project-specific state fields and return its effective duration.
- Pause only the reassigned task's old timer during the trusted assignment hook,
  retaining its technician in the existing push payload.
- Namespace timer/calendar modal IDs by task type and retain the original GLPI
  calendar, manual creation, editing, deletion and database schema.

## [3.2.3-london.1] - 2026-10-27

### Added

- London stage 3: delete a completed or paused timer from Modify timers, with
  a separate confirmation showing the saved dates, technician and duration.
- Recalculate totals when configured, keep the task state and other segments,
  and retain the deleted interval, technician and operator in parent history.
- Atomically remove the selected timer and its native modification snapshot,
  sharing the existing task lock and rejecting stale confirmation values.
- Require pausing an active timer before deletion. Confirmation and deletion
  keep profile/entity/task access checks and GLPI CSRF validation.
- Portuguese translations and regression/browser coverage for canceling,
  confirming, conflicting changes, totals, permission denial and rollback.

## [3.2.2-london.1] - 2026-10-27

### Added

- London stage 2: edit start/end timestamps of completed timers and correct the
  start of a running timer without pausing it, using the original GLPI calendar.
- Validate the full edit batch, reject invalid/future/overlapping intervals and
  stale changed rows, preserve submitted dates on errors, and record each
  before/after pair and operator in the parent item's history.
- Recalculate task duration when configured and retain the native first-change
  snapshot and modification indicator, without database schema changes.
- Share the task-row lock with native start/pause/stop so an active correction
  and timer controls use the current timestamps.
- Portuguese translations, edit/storage-failure regressions and iframe browser
  coverage using the real GLPI 10.0.26 calendar.

## [3.2.1-london.4] - 2026-10-27

### Changed

- Restore Html::showDateTimeField(), the original GLPI calendar used by
  ActualTime 3.2.0, for manual periods and existing end-date editing.
- Keep the GLPI 10 HTML-escaping fix and backwards compatibility with values
  submitted by cached forms.

## [3.2.1-london.3] - 2026-10-27

### Fixed

- Replace calls to the unavailable global htmlescape() with PHP's
  htmlspecialchars(), keeping attribute and technician-name escaping.
- Remove the test-only global that masked the GLPI 10 rendering error. Add a
  mode that uses the real Html class from GLPI 10 without bootstrapping a database.

## [3.2.1-london.2] - 2026-10-27

### Fixed

- Use browser-native date/time controls in the timer editor, including the
  existing end-date field, so selection does not depend on Flatpickr in an iframe.
- Normalize browser-local ISO values to GLPI timestamps before validating,
  comparing or saving, preserving seconds and the current server timezone.
- Preserve submitted native date values after a validation error.

## [3.2.1-london.1] - 2026-10-27

### Added

- London stage 1: create completed manual timers from Modify timers, including
  saved tasks without previous timers.
- Validate dates, prevent same-task overlaps and duplicate submissions, retain
  form values on invalid intervals, audit the operator and assigned technician,
  and update task duration atomically when configured.
- Portuguese UI translations and isolated regression tests.

### Fixed

- Require access to the task and parent item when opening or submitting the
  timer editor, and restrict existing-timer edits to the posted task.
- Balance the timer editor's forms and containers.

### Scope

- Based on upstream 3.2.0 for GLPI 10.0.x. Start-time editing, deletion and
  concurrent timers remain separate subsequent stages.

## [3.2.0] - 2025/02/27
### Added
- Actualtimes for Problem tasks, contributed by Gambware

### Fixed
- Task in progress message redirecting to the correct ticket

## [3.1.3] - 2025/01/27
### Fixed
- Conflict with pending reasons with glpi solutions
- Fix ChangeTasks foreign key and integration
- Better control over null values

## 3.1.2 - 06/11/2024
### Bugfixes
- Fix active task alert showing and redirecting to the correct page

## 3.1.1 - 10/10/2024
### Bugfixes
- Fix change tech
- Fix warning
- Fix solution check
- Fix tam check

## 3.1.0 - 30/04/2024
### Features
- Modify the end date of timers
### Bugfixes
- Fix permissions check of the timers running

## 3.0.1 - 15/04/2024
### Features
- Use global functions
### Bugfixes
- Fix field update

## 3.0.0 - 16/02/2024
### Features
- Add compatibility with project task
- Add compatibility with change task
### Bugfixes
- Fix autostart css
- Fix disabled button

## 2.2.0 -15/09/2023
### Features
- Add block timer on different day
- Add block timer on planned task
- Permission to view activated timers
- Filter activated timers by entities
- Show location and assets in activated timers
### Bugfixes
- Fix warning string
- Fix update actiontime

## 2.1.2 - Internal - 05/09/2023
### Features
- Add block timer on different day
- Add block timer on planned task
### Bugfixes
- Fix warning string

## 2.1.1 - Internal - 12/07/2023
### Features
- Permission to view activated timers
- Filter activated timers by entities
- Show location and assets in activated timers
### Bugfixes
- Fix update actiontime

## 2.1.0 - 15/03/2023
### Features
- Show ticket and task id in planning
- Stop timer when ticket delete
- Change pop-up
- Add button with task closed
### Bugfixes
- Fix delete timer when purge task
- Check user login
- Fix widget sql

## 2.0.0 - 12/08/2022
### Features
- Update the time of the task by marking it as done
- UI enhancements
- Drop auto open new task option
### Bugfixes
- Fix sql ticket statistics
