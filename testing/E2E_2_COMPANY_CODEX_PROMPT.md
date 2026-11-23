# E2E test 2 of 3: Company (Codex prompt)

Paste the block below into Codex from the root of the MYOattendanceV2 repo, after prompt 1 (School). It tests a company running employee attendance and payroll cut-offs: shifts, late, undertime, overtime, breaks, rest days, holidays, night shifts and the Timesheet. Codex tests only and must not change app code.

## What the code does today for companies (checked before writing this prompt)

- **Company labels**: Employee(s), Department(s), Team(s). Company is in `OrganizationTypes::WORKFORCE`, so `Organization::tracksWorkHours()` is true. Construction, Warehouse and Staffing behave exactly like Company but are hidden from the dropdowns.
- **Employer-only features**:
  - "Timesheet" menu link and page `/attendance/timesheet`; `GET /api/attendance/timesheet?from&to&group_id&subgroup_id&person_id`, `GET /api/attendance/timesheet/export?…&format=daily|summary`. Range at most 62 days; no dates = 1st–15th or 16th–end of the current month.
  - "Unpaid break (minutes)" on schedule forms (`break_minutes`).
  - Rest-day scans: no schedule today but a schedule on other days → allowed, `data.rest_day: true`, "Checked in on a rest day (no schedule today)." / "Checked out. Rest day hours recorded."; no schedule at all → `NO_SCHEDULE`.
  - Holidays: a scan on a holiday is saved as holiday hours: "Checked in on {holiday}. Counted as holiday hours." / "Checked out. Counted as holiday hours ({holiday})."; a late scan on a holiday is not counted Late; timesheet status "Holiday".
  - Extra scans after the last period's slots are used (lunch out/in) alternate in and out (`meta.extra`) instead of `ATTENDANCE_COMPLETE`.
- **Hours** (`CalculateWorkHours`, whole minutes): regular time only inside periods; late from period start once after "late after"; undertime from time out to period end; overtime after the last period end, only when 30 minutes or more; break subtracted once when 300+ minutes worked; open time in after shift end or a time out with no time in → "Incomplete", 0 hours; no-schedule days → rest day minutes.
- **Night shifts**: end before start = ends next day; `meta.attendance_date` = shift start date; a scan belongs to last night's shift while it runs or up to 4 hours after (`OVERNIGHT_GRACE_HOURS`) if still checked in.
- **Timesheet day statuses**: Present, Late, Absent, Incomplete, In progress, Not yet, Rest day, Holiday. Archived employees still appear for days they worked; open-hours visits are left out.
- **CSV headers** (`TimesheetController`): summary `ID Number, Full Name, {Department}, {Team}, Days Worked, Absences, Late Days, Incomplete Days, Regular Hours, Late Minutes, Undertime Minutes, Overtime Hours, Rest Day Hours`; daily `ID Number, Full Name, Date, Day, Shift, Time In, Time Out, Status, Regular Hours, Late Minutes, Undertime Minutes, Overtime Hours, Rest Day Hours, Unpaid Break Minutes, Note`.
- **Membership valid until** is shown on company person forms (only schools hide it) and is enforced at the scanner.
- **Roles**: owner, admin, staff (scan only). A guard kiosk is a Staff account.
- Existing coverage to build on, not duplicate: `EmployeeHoursTest`, `StepsOneToFourEndToEndTest` (step 3), `HolidaysTest`, `AttendanceHistoryAccuracyTest`, `TeamAndIndustryTest`.

### Known bugs to confirm (report pass/fail, do not fix)

- **B1** Saving an archived employee's profile un-archives them (`PersonController::validated` defaults `is_active` to true; the edit page does not send it).
- **B2** Deleting a department (group) erases former members' history (`person_assignments.group_id` cascades); deleting a team (subgroup) deletes its ended schedule versions.
- **B3** Changing a group's mode or the org industry rewrites past reports. Here the big one: switching Company → Other (or School) changes rest-day, holiday and Late handling for past days and hides the Timesheet. Any admin can switch.

---

## Prompt

```
You are working in a Laravel 12 / PHP 8.4 app (MYO Attendance V2). Tests use PHPUnit with SQLite in memory: run them ON THE HOST with `php artisan test --compact <path or --filter>`. NEVER run php artisan test inside Docker: Docker's env points RefreshDatabase at the real MySQL and wipes it. Format PHP with `vendor/bin/pint --dirty --format agent`.

GOAL
End-to-end test of the COMPANY organization type, the way an HR officer and a guard use it: sign-up, departments and shifts, enrolling employees, the guard kiosk, a full payroll cut-off (Nov 1–15, 2026) with day and night shifts, lunch breaks, overtime, rest days and holidays, then the Timesheet and its CSVs for payroll. Then the changes an HR officer makes mid-cut-off. Focus on workflows and features; reuse existing helpers.

DO NOT change application code (app/, resources/, routes/, database/, config/). Only add tests and report. If a test exposes a bug, leave the failing test in place, mark it `// KNOWN BUG Bx` or `// NEW BUG`, and report it. Only make a non-app change if trivially needed to run tests (say what and why).

DATA SAFETY
- Never edit, delete or scan for any organization you did not create in this run. Do not touch the real school's data or the seeded demo orgs (MYO Demo Organization, Trial Demo School, Base Demo School).
- Before the browser pass: docker compose exec -T mysql mysqldump -umyo -pmyo_secret --no-tablespaces --single-transaction myo_attendance > storage/app/simulation-backups/before-codex-e2e-company.sql
- Create one fresh company for the browser pass: "E2E Company <YYYYMMDD-HHMM>". Restore the backup if anything goes wrong.

READ FIRST (summarize each in one or two sentences)
- App\Support\OrganizationTypes (WORKFORCE, HIDDEN), Organization::tracksWorkHours, OrganizationTerminology
- ProcessAttendanceScan (shiftFor, shiftEvents, restDayScan, extraShiftScan, holiday branch, isExitScan), HolidayCalendar, HolidayController
- Schedule (windows, isOvernight, scheduledMinutes, effectivePeriods), ScheduleController (store, update, destroy, validatePeriods), ScheduleResolver
- CalculateWorkHours, BuildTimesheet, TimesheetController, AttendanceTimeline, BuildDailyAttendanceReport, BuildMonthlyAttendanceReport, BuildPersonAttendanceHistory, BuildNeedsAttentionReport
- PersonController, PersonImportController, GroupController, SubgroupController, TeamController, EnsureCanManageOrganization
- Views: attendance/timesheet, schedules/create, people/index, people/edit, people/show, settings/holidays, settings/team, scanner/index
- Tests: EmployeeHoursTest, StepsOneToFourEndToEndTest, HolidaysTest, AttendanceHistoryAccuracyTest, TeamAndIndustryTest

TEST RULES
- Create tests/Feature/E2eCompanyWorkflowTest.php (split into E2eCompany*Test.php if long). One method per numbered case: test_c<part>_<case>_...
- Each test builds its own company org (type 'company', Asia/Manila), owner, departments, teams, schedules and employees.
- Freeze time per action with Carbon::setTestNow(Carbon::parse('<local>', 'Asia/Manila')); reset in tearDown. 2026-11-02 is a Monday; 2026-11-01 (All Saints' Day) is a Sunday.
- Scan through POST /api/attendance/scan. Write events directly only for older history.
- Http::fake() at the start; Http::assertNothingSent() at the end.
- Assert exact minutes, statuses, codes, messages, CSV headers and rows.

Standard setup ("the company"): Departments "Operations" (teams "Day Crew", "Night Crew") and "Admin Office" (team "Finance").
 - Operations schedule Mon–Fri 08:00 start / 08:15 late after / 17:00 end, break 60.
 - Night Crew override Mon–Fri 22:00 / 22:15 / 06:00 (next day), break 60.
 - Admin Office Mon–Sat split: 08:00/08:10/12:00 and 13:00/13:10/17:00, break 0.
 Employees: E-01 Ana, E-02 Ben (Day Crew), E-03 Cara, E-04 Dan (Night Crew), E-05 Eli (Finance), E-06 Fe (no department, never scheduled).

PART 1: SIGN-UP AND SETUP
 1. Register with organization_type 'company' → labels Employee/Department/Team on /people, /groups, /subgroups, /attendance/timesheet; /app shows a "Timesheet" link.
 2. Schedule forms: /schedules/create shows "Unpaid break (minutes)". Validation: night 22:00/22:15/06:00 + break 60 → 201, isOvernight, scheduledMinutes 420; break ≥ shift → 422; first of two periods crossing midnight → 422; overlapping periods → 422; late_after before start without overnight → 422.
 3. Team: owner adds an Admin (HR officer) and a Staff (guard "gate1"); the guard sees only /scanner; HR admin cannot add an Admin (422) and cannot edit/remove the owner (403).
 4. Employees: add by form and by CSV import (with group/subgroup names). Person forms show "Membership valid until" for a company — report as an observation whether that is wanted for employees.
 5. Report (observation): PUT /api/organization/industry {type: construction} is accepted although hidden; labels become Worker/Project/Crew and the Timesheet stays.

PART 2: A PAYROLL CUT-OFF, NOV 1–15, 2026 (today = 2026-11-16 09:00 for the reports)
 Day Crew:
 1. Ana Mon 11/02: 07:50 in, 17:40 out → Present, regular 480, late 0, undertime 0, OT 40, break 60.
 2. Ana Tue 11/03: 08:20 in, 16:30 out → Late, regular 430, late 20, undertime 30, OT 0.
 3. Ana Wed 11/04: 08:00 in, 17:20 out → OT 0 (under the 30-minute minimum).
 4. Ana Thu 11/05 lunch: 07:58 in, 12:00 out, 13:02 in, 17:05 out → the 3rd and 4th scans are accepted (not ATTENDANCE_COMPLETE, meta.extra), day = regular 478 (08:00–12:00 + 13:02–17:00), late 0, undertime 0, OT 0 (5 minutes is under the minimum), break 0 (the 62-minute scanned-out lunch already covers the 60-minute break, so it is not subtracted twice).
 5. Ana Fri 11/06: 08:05 in, no out → "In progress" at 12:00 that day; "Incomplete", 0 regular, no_time_out next day.
 6. Ben Mon 11/02: no scans → Absent. Ben Tue 11/03 08:00–12:00 → regular 240, undertime 300, break 0.
 7. Ben Sat 11/07 09:00 in, 13:30 out (rest day) → CHECKED_IN rest_day true, "Checked in on a rest day (no schedule today).", then "Checked out. Rest day hours recorded."; timesheet "Rest day", rest day minutes 270. Fe (no schedule anywhere) Sat → NO_SCHEDULE.
 Night Crew:
 8. Cara Mon 21:55 in, Tue 06:10 out → both attendance_date 2026-11-02; Mon Present, regular 420; shift label "10:00 PM – 6:00 AM (next day)"; Tue daily report does not show the 06:10 out.
 9. Cara Tue 21:58 in (a new shift, not ATTENDANCE_COMPLETE), Wed 06:45 out → Tue OT 45.
 10. Dan Thu 00:30 in (late, belongs to Wed), Thu 06:00 out → Wed Late, late 150, regular 270.
 11. Dan Thu 21:50 in, Fri 09:30 out (within the 4-hour grace) → Thu regular 420, OT 210. A scan more than 4 hours after shift end with no open time in → today's shift.
 Split shift (Admin Office):
 12. Eli Mon 07:40 in, 16:40 out only → regular 480, undertime 20, late 0. Eli Sat 11/07 08:00–12:00 is a scheduled Saturday (Mon–Sat), not a rest day.
 Holidays:
 13. POST /api/holidays {name "Bonifacio Day", starts_on 2026-11-30} and a test holiday "Company Anniversary" on Wed 2026-11-11 for the whole org. Ana scans 08:30 in and 17:00 out on 11/11 → "Checked in on Company Anniversary. Counted as holiday hours.", "Checked out. Counted as holiday hours (Company Anniversary)."; timesheet status "Holiday", not Late, minutes land in the holiday/rest-day column (assert the exact column). Ben does not come on 11/11 → Holiday, not Absent.
 14. A department-only holiday (group_id Admin Office) on 11/12 affects Eli only; Operations still expected.
 15. All Saints' Day (Sun 11/01) from POST /api/holidays/common {year 2026} does not change anyone's Sunday status.

PART 3: TIMESHEET AND PAYROLL CSVs (reports at 2026-11-16 09:00)
 1. GET /api/attendance/timesheet?from=2026-11-01&to=2026-11-15 → each employee's daily statuses and totals {days_worked, absences, incomplete_days, late_days, regular_minutes, late_minutes, undertime_minutes, overtime_minutes, rest_day_minutes} equal the sum of Part 2 cases. Write the expected totals in the test.
 2. Filters group_id, subgroup_id, person_id narrow correctly; a group_id of another org → 404.
 3. No dates on 2026-11-16 → range 2026-11-16..2026-11-30; on 2026-11-10 → 2026-11-01..2026-11-15. Range over 62 days → 422; from after to → 422.
 4. Summary CSV header exactly as above with "Department" and "Team"; hours with 2 decimals ("7.67", "1.00"). Daily CSV header exactly as above; assert Ana's 11/03 row and Cara's 11/02 row in full. Filenames start timesheet-summary- / timesheet-daily-.
 5. Daily, monthly (Operations, Operations/Night Crew), history and Needs Attention agree with the timesheet day by day (programmatic comparison for every employee and date). Night shifts sit on their start date everywhere.
 6. An archived employee (archive Ben on 11/10) still appears for days worked, not expected after 11/10.
 7. Staff guard: GET /api/attendance/timesheet → 403 "Staff accounts can only scan attendance."; /attendance/timesheet redirects to /scanner.

PART 4: MID-CUT-OFF CHANGES (REGRESSION FOCUS)
 Snapshot the timesheet (both CSVs) for Nov 1–8 and each affected employee's history before every change; assert the snapshot is identical afterwards unless the case says otherwise.
 1. Transfer: on 11/09 move Ana Day Crew → Night Crew. Nov 1–8 keep the day schedule; from 11/09 the night schedule; timesheet with subgroup_id Day Crew lists Ana only through 11/08; /people/{id} shows "Earlier departments" with "Operations · Day Crew · until Nov 8, 2026".
 2. Shift change on 11/09: Operations end 17:00 → 18:00. Nov 2 OT stays 40; a Nov 9 07:50–18:40 day → OT 40 against the new end. Editing the new version again the same day updates it (no third row).
 3. Lost ID: regenerate Cara's QR; old token → INVALID_QR; new token works mid-shift (the time out closes last night's shift); hours unchanged.
 4. Resignation: archive Dan on 11/10 15:00 → PERSON_INACTIVE; worked days kept; Needs Attention excludes him.
 5. KNOWN BUG B1: PUT /api/people/{Dan} without is_active (as the edit page does). EXPECTED still archived. Report PASS/FAIL.
 6. KNOWN BUG B2: move Eli out of Admin Office, end its schedules, delete Finance, then delete Admin Office. EXPECTED: refused, or Eli's Nov 1–8 timesheet and history unchanged. Report the person_assignments rows and the timesheet diff.
 7. KNOWN BUG B3 (industry): as the HR ADMIN, switch Company → Other and back to Company. EXPECTED: Nov 1–8 timesheet identical after returning, and while on Other the past rest-day and holiday days still read as worked. Report: whether the admin was allowed, what Ben's Sat 11/07 and Ana's 11/11 show while on Other, and whether anything differs after switching back.
 8. KNOWN BUG B3 (mode): switch Operations to open and back. EXPECTED identical past timesheet; report the diff (open visits are left out of the timesheet, so watch for missing days).
 9. Construction/Warehouse/Staffing parity: run case 2.1–2.7 for an org of type warehouse via a data provider → identical minutes and statuses.

PART 5: BROWSER PASS (http://localhost:8000 via Docker compose; Mailpit http://localhost:8025)
 Real time only; time-dependent cases stay in PHPUnit.
 1. Register "E2E Company <timestamp>" as Company, verify the email in Mailpit.
 2. In the UI: departments, teams, the day and night schedules with a 60-minute break, 4 employees (2 by hand, 2 by CSV import).
 3. Add the guard on /settings/team; sign in as the guard in a private window and scan with the console snippet on /scanner:
    fetch('/api/attendance/scan',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({qr_payload:'<token from GET /api/people/{id}/qr as HR>'})}).then(r=>r.json()).then(console.log)
    Scan one employee in and (after 5 seconds) out; scan once for an employee whose department has no schedule today if today is a weekend.
 4. As HR: /attendance/timesheet shows today's scans; download the summary and daily CSVs and check headers; open /people/{id} for the employee and check the worked hours line.
 5. Add a holiday for today on /settings/holidays, scan a new employee, read the scanner message (holiday hours), then delete the holiday.
 6. Archive an employee and click Save on its edit page (B1 in the real UI).
 7. As super/password, /super-admin lists the company with type Company; change nothing.
 Screenshots to storage/app/e2e/company/ (desktop 1280x800 and phone 390x844 for /attendance/timesheet and /people/{id}).

FINISH
Run the new file(s), then the full suite on the host. Report:

| Part.case | Result | Detail |
|---|---|---|
| 2.1 | PASS | |
| 4.7 | FAIL (KNOWN B3) | Ben 11/07 rest day disappears while type = other |

Then: total tests, passed, failed; B1, B2, B3 each CONFIRMED or NOT REPRODUCED with the exact assertion; NEW bugs with steps, expected, actual and likely cause (file:line); observations (hidden types accepted, membership on employees); what you could not test and why; the DB backup path and whether you restored it.
```
