# Steps 1–4 end to end: Codex test prompt

Paste the block below into Codex from the root of the MYOattendanceV2 repo. It tells Codex to test only and not change app code. It covers the four steps built on 2026-11-10 and 2026-11-11:

1. Industry presets and staff roles
2. Open hours (drop-in) mode and membership valid-until dates
3. Employee hours: night shifts, late, undertime, overtime, breaks, rest days, and the Timesheet with CSVs
4. Past records stay the same when people move groups, schedules are edited, or people are archived

## What the code does today (checked before writing this prompt)

- **Roles** (`App\Models\User::ROLE_*`): `owner`, `admin`, `staff`.
  - `staff` can only scan. The `manager` middleware (`App\Http\Middleware\EnsureCanManageOrganization`) sends staff from any page to `/scanner`, and answers any API call with 403 "Staff accounts can only scan attendance."
  - Only `/scanner` and `POST /api/attendance/scan` are open to staff.
  - The staff scanner has no menu: it shows "Signed in as {name}" and a "Log Out" button.
- **Team page** `/settings/team` (`App\Http\Controllers\TeamController`, `GET|POST /api/team`, `PUT|DELETE /api/team/{id}`).
  - The owner can add Admin or Staff. An admin can add Staff only (role admin → 422).
  - Nobody can edit or remove the owner or themselves (403). Only the owner can change or remove admins.
  - Staff sign in with a username; email is optional (`users.email` is nullable). The username is lowercased and trimmed.
- **Industry** (`PUT /api/organization/industry {type}`, also the "Industry" dropdown on Manage Labels):
  - Allowed types are `OrganizationTypes::VALUES` (school, company, construction, training_center, warehouse, staffing, sports_academy, other). Anything else → 422.
  - Switching clears custom labels and applies that industry's wording:
    - construction = Worker / Project / Crew
    - warehouse = Worker / Department / Shift
    - staffing = Worker / Client / Site
    - school = Student / Grade Level / Section
    - company = Employee / Department / Team
    - training_center = Trainee / Program / Batch
    - sports_academy = Athlete / Sport / Team
    - other = Person / Group / Subgroup
- **Employers**: `OrganizationTypes::WORKFORCE` = company, construction, warehouse, staffing, and `Organization::tracksWorkHours()`. Only these get:
  - the Timesheet menu item
  - rest-day scans
  - extra in/out scans after the last period (lunch)
  - the "Unpaid break (minutes)" field on the schedule forms
- **Open hours** (`groups.attendance_mode` = `scheduled` | `open`, plus `groups.scan_out`), set on the group create/edit form ("How do … check in?").
  - Open group: no schedule is needed. Every scan is a visit, saved as `check_in` with `meta.mode=open`, and it's never late.
  - A repeat scan within 30 minutes of a time in (`ProcessAttendanceScan::OPEN_REPEAT_MINUTES`) → `DUPLICATE_SCAN` "Already checked in at 6:30 AM."
  - With `scan_out`, scans alternate in and out.
  - `ScheduleResolver` returns no schedule for open groups, so their members are never expected or absent.
  - Reports:
    - Daily: lists open members only on days they came; the row has `visits`.
    - Monthly: shows every day (weekends included) as "Visited", "Visited (2)" or "-", plus `total_visits` and `open_hours: true`; the CSV adds a "Total Visits" column.
    - History: `person.open_hours`, `summary.visits`, `summary.days_visited`.
    - Needs attention: flags open members only for not_seen.
    - Timesheet: leaves open-hours visits out.
- **Membership** (`persons.membership_valid_until`, date):
  - Scanning after that date → `MEMBERSHIP_EXPIRED` "Membership expired on Nov 1, 2026." Nothing is saved. This applies to any group.
  - A successful scan includes `data.membership {valid_until, days_left}`; the scanner shows "Ends in N days" when 7 or fewer.
  - The field is hidden on person forms and in the import help for school-type organizations.
  - CSV import and export include a `membership_valid_until` column (YYYY-MM-DD; blank clears it on upsert). The people export never includes `qr_token`.
- **Night shifts and shift date**:
  - A schedule whose end is earlier than its start ends the next day (`Schedule::windows()`, `isOvernight()`).
  - Only the last period may cross midnight. A shift must be under 24 hours. A break that is equal to or longer than the shift → 422.
  - Every scan stores `meta.attendance_date` = the date its shift started. `AttendanceEvent::attendanceDate()` falls back to the local date for old scans. Daily, monthly, timeline and timesheet group scans by it.
  - A scan belongs to last night's shift while that shift is still running, or up to 4 hours after it ends (`OVERNIGHT_GRACE_HOURS`) if the person is still checked in.
- **Hours** (`App\Services\CalculateWorkHours`, whole minutes, no rounding). Scans are read as time-in to time-out stretches, whatever period the scanner filed them under.
  - Regular time counts only inside the periods: early arrival isn't paid, and neither is the gap between periods.
  - Late is counted from the period start, once the person arrives after "late after".
  - Undertime runs from the time out to the period end. A missed period on a day the person came is all undertime.
  - Overtime is time after the last period's end, and only counts when it's 30 minutes or more.
  - The schedule's `break_minutes` is subtracted once, only when the person worked 300 minutes or more, and minus any time already scanned out during the shift.
  - A time in still open after the shift ends, or a time out with no time in, is "Incomplete" with 0 hours.
  - On a day with no schedule, the hours count as `rest_day_minutes`.
  - Employers may scan out and back in after the last period's slots are used (`meta.extra`). Schools still get `ATTENDANCE_COMPLETE`.
  - Rest-day scans are allowed only for employers, and only for people whose group has a schedule on some other day. Anyone else gets `NO_SCHEDULE`.
- **Timesheet** (`App\Services\BuildTimesheet`, `GET /api/attendance/timesheet?from&to&group_id&subgroup_id&person_id`, `GET /api/attendance/timesheet/export?…&format=daily|summary`, page `/attendance/timesheet`).
  - Ranges are at most 62 days. With no dates, it defaults to the 1st–15th or the 16th–end of the current month.
  - Day status is one of: Present, Late, Absent, Incomplete, In progress, Not yet, Rest day.
  - Archived people are included for the days they worked.
  - Summary CSV header: `ID Number, Full Name, {group term}, {subgroup term}, Days Worked, Absences, Late Days, Incomplete Days, Regular Hours, Late Minutes, Undertime Minutes, Overtime Hours, Rest Day Hours`. Hours have 2 decimals.
  - Daily CSV header: `ID Number, Full Name, Date, Day, Shift, Time In, Time Out, Status, Regular Hours, Late Minutes, Undertime Minutes, Overtime Hours, Rest Day Hours, Unpaid Break Minutes, Note`.
- **History accuracy**:
  - `person_assignments` stores a group and subgroup with `starts_on`/`ends_on`. A move takes effect today (org timezone): the old row ends yesterday and a new row starts today. Two moves on the same day update today's row. Saving without a group change adds no row.
  - `persons.archived_at` is set on archive and cleared on reactivate. A person created as archived gets 2000-01-01, meaning never active.
  - `schedules.effective_from` / `effective_to`:
    - Editing a schedule already in use ends the old version yesterday and starts a new one today.
    - Editing a version that started today updates it in place.
    - Deleting a schedule that was in use ends it yesterday; one never used is deleted.
    - `GET /api/schedules` lists current versions only.
    - A group or subgroup with only ended versions can be deleted.
  - `ScheduleResolver::scopeOn()` / `groupOn()` resolve by date. Daily, monthly, history, Needs attention and the timesheet use the group and schedule the person had on that date.
  - Monthly lists everyone who was in the group during the month and shows "-" for days they were elsewhere or archived.
  - The migration `2026_11_11_070522_add_attendance_history_tables` rebuilds past moves from `meta.group_id` saved on scans.
  - The person page lists "Earlier {groups} … until {date}".
- **Bugs already fixed** (must stay fixed):
  1. On a split schedule (7:30–12:00 and 1:00–5:00), one scan at 7:40 and one at 4:40 counts 480 min with 20 min undertime. It used to give 260 and 240.
  2. On a one-period shift with lunch scans (7:32, 12:00, 1:05, 4:55), hours were 268 min with 300 undertime. Now the 4th scan is accepted for employers and the day is 498 min with 5 min undertime.
  3. Monday Nov 2's 6:10 AM time out from a night shift must not appear on Tuesday's daily report.
- **Seeded logins** (all password `password`): `admin` (MYO Demo Organization, a workforce-type demo org), `trial` (Trial Demo School, school type), `base` (Base Demo School), `super` (Super Admin), `demoguard` (Staff, Trial Demo School).
- **Local demo data** (Docker only; each script replaces only its own rows and queues no SMS):
  - `docker compose exec app php artisan tinker --execute "require 'testing/seed_employee_hours_demo.php';"`: Day Crew (8:00 AM–5:00 PM, 60 min break) and Night Crew (10:00 PM–6:00 AM, 60 min break), with EMP-01 Ana (overtime), EMP-02 Ben (late, early out, missing time outs, a Saturday), EMP-03 Cara (nights) and EMP-04 Dan (nights with overtime, one missed night).
  - `testing/seed_needs_attention_demo.php`: NA-01..NA-08.
  - The "Open Gym" group with GYM-01 Gina (membership to 2026-11-13) and GYM-02 Ex (expired 2026-11-01) in MYO Demo Organization.
- **Backups:** `storage/app/simulation-backups/` (`before-staff-roles-and-presets.sql`, `before-open-hours-and-membership.sql`, `before-employee-hours.sql`, `before-history-accuracy.sql`).

**Expected result:** every case below passes. Any failure is a finding.

---

## Prompt

```
You are working in a Laravel 12 / PHP 8.4 app (MYO Attendance). Tests use PHPUnit with SQLite in memory: run them ON THE HOST with `php artisan test --compact <path or --filter>` (NEVER inside Docker: Docker's env would point RefreshDatabase at the real MySQL and wipe it). Format PHP with `vendor/bin/pint --dirty --format agent`.

GOAL
Test steps 1–4 end to end: staff roles and industry presets; open hours and membership dates; employee hours, night shifts and the Timesheet; and past records staying the same after moves, schedule edits and archiving. DO NOT change application code (app/, resources/, routes/, database/migrations). Only add tests and report what you find. If a test shows a bug, leave the failing test in place, mark it clearly, and report it; do not fix the app.

Read first, and summarize each in one or two sentences:
- App\Http\Middleware\EnsureCanManageOrganization, App\Http\Controllers\TeamController, OrganizationTerminologyController::updateIndustry, App\Support\OrganizationTerminology, App\Support\OrganizationTypes, Organization::tracksWorkHours
- App\Services\ProcessAttendanceScan (shiftFor, shiftEvents, openVisit, restDayScan, extraShiftScan, isExitScan, membershipData)
- App\Models\Schedule (windows, isOvernight, scheduledMinutes, scopeEffectiveOn, scopeCurrent), App\Models\AttendanceEvent::attendanceDate, App\Models\Person (booted hooks, wasActiveOn), App\Models\PersonAssignment::recordMove
- App\Services\ScheduleResolver (resolveMany, scopeOn, groupOn, preload), ClassifyDailyAttendance::flags, AttendanceTimeline (handle, dayIsOpen, shiftLabel)
- App\Services\CalculateWorkHours, BuildTimesheet, TimesheetController, BuildDailyAttendanceReport, BuildMonthlyAttendanceReport, BuildPersonAttendanceHistory, BuildNeedsAttentionReport
- App\Http\Controllers\ScheduleController (update, destroy, validatePeriods), GroupController (mode, destroy), SubgroupController::destroy, PersonController, PersonImportController
- database/migrations/2026_11_11_070522_add_attendance_history_tables.php (backfillAssignments)
- Existing tests to reuse in style: tests/Feature/TeamAndIndustryTest.php, OpenHoursAndMembershipTest.php, EmployeeHoursTest.php, AttendanceHistoryAccuracyTest.php. Do not duplicate them; extend coverage.

Rules:
- Time: freeze with Carbon::setTestNow(Carbon::parse('<local time>', 'Asia/Manila')) per action, and reset in tearDown. 2026-11-02 is a Monday.
- Scan through the real endpoint POST /api/attendance/scan {qr_payload: $person->qr_token} wherever the behavior is about scanning. Write AttendanceEvent rows directly only to set up history.
- Never send real SMS: Http::fake() at the start of every test, Http::assertNothingSent() at the end.
- When you change an organization's type inside a test, update it through $user->organization (the request user keeps its loaded relation).
- Assert exact minutes, statuses, dates and messages where this prompt gives them.

Create tests/Feature/StepsOneToFourEndToEndTest.php (split into several files if it gets long), one method per numbered case.

STEP 1: STAFF ROLES AND INDUSTRY PRESETS
 1. Owner adds Staff (name, username " Guard1 ", password) → 201, username "guard1", email null, role_label "Staff". The staff signs in with POST /api/auth/login {identifier:"guard1"}.
 2. Staff: GET /app, /people, /attendance, /attendance/timesheet, /settings/team, /billing all redirect to /scanner; GET /scanner 200 with "Log Out" and without the bottom menu; GET /api/people, /api/attendance/report?date=…, /api/attendance/timesheet, /api/team → 403 "Staff accounts can only scan attendance."; POST /api/attendance/scan works (INVALID_QR for an unknown code, CHECKED_IN for a real person with a schedule).
 3. Admin cannot add an Admin (422), can add Staff; cannot edit/remove the owner or themselves (403); can reset a staff password (PUT with password) and the staff can log in with the new one.
 4. Owner promotes Staff to Admin and back; admin cannot remove another admin (403); owner can (200). Duplicate username → 422.
 5. Tenant isolation: GET /api/team lists only own organization; DELETE /api/team/{other org user} → 404.
 6. PUT /api/organization/industry for each of the 8 types → the exact labels listed in this file; custom labels are cleared; 'gym' → 422. GET /attendance/timesheet as owner: visible in the menu only for company/construction/warehouse/staffing (assert the "Timesheet" link in the /app HTML).
 7. Schools unchanged: a school-type org shows no Timesheet link and no "Unpaid break" field on /schedules/create, and the person forms have no "Membership valid until".

STEP 2: OPEN HOURS AND MEMBERSHIP
 1. Group created with attendance_mode open (+ scan_out false); a member scans on Saturday 06:30 → CHECKED_IN PRESENT; 06:45 → DUPLICATE_SCAN "Already checked in at 6:30 AM."; 18:00 → CHECKED_IN, visits_today 2. Within 5 seconds → "Already scanned. Please wait before scanning again."
 2. scan_out true: in, out, in → CHECKED_IN, CHECKED_OUT, CHECKED_IN (visits_today 2).
 3. Saving a scheduled group with scan_out true stores scan_out false; PUT without attendance_mode keeps the mode.
 4. Reports for an open group over Nov 2026: daily lists only members who came (visits count, no_time_out false, absent 0); monthly 30 dates with "Visited (2)", "-", total_visits, CSV "Total Visits"; history visits/days_visited and attendance_rate null; Needs attention only not_seen ("Not seen in N days" / "Never scanned"); timesheet leaves the open member out.
 5. Membership: valid_until yesterday → MEMBERSHIP_EXPIRED "Membership expired on <M j, Y>." with no event saved (also for a scheduled group); valid_until today → success with membership {valid_until, days_left 0}; in 3 days → days_left 3; null → no membership key.
 6. Membership via forms and CSV: POST/PUT /api/people saves it; PUT without the field keeps it; import column sets it and a blank upsert clears it; the people export has the membership_valid_until column and never qr_token. Bad date "2026-13-01" → 422 (form) / counted as failed row (import).

STEP 3: EMPLOYEE HOURS AND TIMESHEET (use a warehouse or company org)
 Day shift 08:00 / late after 08:15 / end 17:00 / break 60, Mon–Fri:
 1. 07:50–17:40 → Present, regular 480, late 0, undertime 0, OT 40, break 60.
 2. 08:20–16:30 → Late, regular 430, late 20, undertime 30.
 3. 08:00–17:20 → OT 0 (under 30 min minimum).
 4. 08:00–12:00 → regular 240, undertime 300, break 0 (worked under 300).
 5. 08:05 with no time out → "In progress" at 12:00 the same day; "Incomplete", 0 regular, no_time_out true the next morning.
 6. A weekday with no scans after it ended → Absent; a weekday later today before 17:00 → "Not yet".
 7. Lunch scans on the one-period shift (07:32, 12:00, 13:05, 16:55; schedule 07:30/08:00/17:00/break 60): the 3rd scan is CHECKED_IN PRESENT (not ATTENDANCE_COMPLETE), day = regular 498, undertime 5, break 0. The same 3rd scan in a school-type org → ATTENDANCE_COMPLETE.
 8. Split schedule 07:30–12:00 + 13:00–17:00 with only 07:40 and 16:40 → regular 480, undertime 20, late 0 (regression for the "4 hours" bug).
 Night shift 22:00 / 22:15 / 06:00 / break 60:
 9. Mon 21:55 in, Tue 06:10 out → both attendance_date 2026-11-02; Mon = Present, regular 420, OT 0; shift label "10:00 PM – 6:00 AM (next day)".
 10. Tue 21:58 in (new shift, not ATTENDANCE_COMPLETE), Wed 06:45 out → Tue OT 45.
 11. Thu 00:30 in (late, belongs to Wed), Thu 06:00 out → Wed Late, late 150, regular 270.
 12. Running shift at 03:00 → "In progress", no_time_out false; time out at 09:30 (within the 4-hour grace) closes it with OT 210 (break 0 schedule).
 13. A scan more than 4 hours after the shift end with no open time in belongs to today's shift, not last night's.
 14. Daily report for Tue does not show Monday night's 06:10 out; monthly shows Mon/Tue/Wed statuses on their start dates.
 Rest days and setup:
 15. Saturday scans 09:00 and 13:30 for an employee whose group has weekday schedules → CHECKED_IN with data.rest_day true, then CHECKED_OUT; timesheet status "Rest day", rest_day_minutes 270; daily report lists them that day. A person with no group or no schedules at all → NO_SCHEDULE.
 16. Schedule validation: 22:00/22:15/06:00 + break 60 → 201, isOvernight true, scheduledMinutes 420; 23:50/00:05/08:00 → 201; late_after before start with no overnight end (08:00/07:00) → 422; break 540 on a 9h shift → 422; two periods where the first crosses midnight → 422; overlapping periods → 422.
 Timesheet API and CSV:
 17. Totals for one employee over two days (one late day with OT 60, one absent) = {days_worked 1, absences 1, incomplete_days 0, late_days 1, regular_minutes 460, late_minutes 20, undertime_minutes 0, overtime_minutes 60, rest_day_minutes 0}.
 18. Summary CSV header exactly as listed in this file with the org's group/subgroup terms; row hours like "7.67" and "1.00". Daily CSV row contains `E1,"Ana Test",2026-11-02,Monday,"8:00 AM – 5:00 PM","8:20 AM","6:00 PM",Late,7.67,20,0,1.00,0.00,60,` (adapt names). Filenames timesheet-daily-… / timesheet-summary-….
 19. Range over 62 days → 422; from after to → 422; default range with no dates is the 1st–15th or 16th–end of the current month; group_id of another org → 404; person_id filter works.
 20. Archived employee still appears for days worked; an open-hours member is left out.

STEP 4: PAST RECORDS STAY THE SAME
 1. Move mid-month: person in Morning with scans Nov 2–4; on Nov 9 move to Afternoon (PUT /api/people/{id}); on Nov 16 move back. person_assignments = [[null, 2026-11-08], [2026-11-09, 2026-11-15], [2026-11-16, null]]. Monthly Morning: Nov 2–4 Present, Nov 5 Absent, Nov 9–13 "-", Nov 16 Absent. Monthly Afternoon: Nov 6 "-", Nov 9 Absent, Nov 10 Present (with a scan), Nov 16 "-". Daily Nov 3 row group "Morning"; daily Nov 3 filtered by Afternoon → empty.
 2. Two moves on the same day → one row updated (no zero-length rows). A save with no group change → no new row. Moving via CSV import upsert also records history.
 3. Timesheet with group_id filter lists only the days the person was in that group; history entries carry group_id/subgroup_id of that date.
 4. Schedule edit: edit Monday's times on Nov 9 → old row effective_to 2026-11-08, new row effective_from 2026-11-09; Nov 2 history shift shows the OLD times, Nov 9 the NEW; editing the new row again on Nov 9 updates it (no third row). Delete Wednesday's schedule on Nov 9 → kept with effective_to 2026-11-08; Nov 4 still Absent; Nov 11 not scheduled ("-"); GET /api/schedules lists only current rows; you can create a new Wednesday schedule (no duplicate error from the ended one).
 5. Night shift whose schedule is edited: last night's shift still closes with last night's version.
 6. Archive on Nov 5 15:00 → archived_at set; daily Nov 2 Present, Nov 4 Absent, Nov 5 not listed; monthly Nov 5 onward "-"; history stops at Nov 4; Needs attention excludes them; timesheet still shows worked days; reactivate → archived_at null and expected again from then on. A person created archived (is_active false) is never expected.
 7. A group whose schedules were all deleted (ended) can be deleted (200), and its ended schedules go with it; a subgroup likewise. A group with a current schedule or members → 409.
 8. Backfill: set up scans whose meta.group_id is Morning, move the person to Afternoon with Person::withoutEvents, add an Afternoon scan on Nov 12, clear person_assignments, run `(fn () => $this->backfillAssignments())->call(require database_path('migrations/2026_11_11_070522_add_attendance_history_tables.php'))` → [[Morning, null, 2026-11-11], [Afternoon, 2026-11-12, null]]; a person who never moved → one row [group, null, null]; a scan whose group was deleted → row with group null.
 9. Schools unchanged: in a school-type org run a normal day (on-time, late, absent, no time out) through daily, monthly and history and assert the same results as before these steps (compare with tests/Feature/PhaseFourAttendanceReportTest.php and MonthlyAttendanceReportTest.php expectations).

OPTIONAL BROWSER PASS (only if a browser is available; local Docker app at http://localhost:8000; NEVER run php artisan test in Docker):
- Before anything, back up: docker compose exec -T mysql mysqldump -umyo -pmyo_secret --no-tablespaces --single-transaction myo_attendance > storage/app/simulation-backups/before-codex-steps-1-4.sql
- Load demo data: docker compose exec app php artisan tinker --execute "require 'testing/seed_employee_hours_demo.php';"
- As admin/password: Timesheet shows Ana, Ben, Cara, Dan; Ana's Day Crew days show "Worked 8h 00m" on her page; Ben has Incomplete days and a Rest day; Cara's days read "In 9:50 PM · Out 6:08 AM · Worked 7h 00m". Download both CSVs and check headers. Team page: add a staff, sign in as that staff in a private window, see only the scanner. Groups: create "Open Gym" as Anytime; person edit shows "Membership valid until".
- As trial/password (a school): no Timesheet, no break field, no membership field; Daily Logs and Monthly Report look as before.
- Do not approve, delete or edit real data beyond the demo rows unless asked. Restore the backup if anything goes wrong.

Run the new file(s) and then the full suite. Report in this format:

| Step.case | Result | Detail |
|---|---|---|
| 1.1 | PASS | |
| 3.8 | FAIL | Expected regular 480, got 260 (CalculateWorkHours pairs scans by period) |

Then list: total tests, passed, failed; any app bug found with the exact failing assertion and a one-line likely cause; anything you could not test and why. tests/Feature/ExampleTest.php is expected to pass now; mention it only if it fails.
```
