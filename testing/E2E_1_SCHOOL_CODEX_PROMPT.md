# E2E test 1 of 3: School / Education (Codex prompt)

Paste the block below into Codex from the root of the MYOattendanceV2 repo. Run the three prompts in order: 1 School, 2 Company, 3 Sports Academy. This one tests the paying customer's workflow, with a strong focus on regressions (history stays intact). Codex tests only and must not change app code.

## What the code does today for schools (checked before writing this prompt)

- **Org types offered at sign-up** (`OrganizationTypes::selectableLabels()`): School / Education, Company, Sports Academy, Other. Construction, Warehouse, Staffing and Training Center are hidden from dropdowns, but `POST /api/auth/register` and `PUT /api/organization/industry` still accept every value in `OrganizationTypes::VALUES`.
- **School labels**: Student / Students, Grade Level / Grade Levels, Section / Sections (`OrganizationTerminology::defaults('school')`). Custom labels are editable on `/settings/terminology`; switching industry clears them.
- **School is not an employer** (`OrganizationTypes::WORKFORCE` excludes it, `Organization::tracksWorkHours()` is false):
  - no Timesheet menu link, no "Unpaid break (minutes)" field on schedule forms
  - no rest-day scans: a Saturday scan for a weekday-only student → `NO_SCHEDULE` "No attendance schedule is assigned for today."
  - once all periods' in/out slots are used → `ATTENDANCE_COMPLETE` "Attendance for today is already complete."
  - holidays mark the day "Holiday" (not Absent); a Late scan on a holiday stays Late for schools
- **Membership valid until** is hidden on the add form (`people/index.blade.php`), the edit form (`people/edit.blade.php`) and the import help for school orgs. But `ProcessAttendanceScan` refuses any scan after `membership_valid_until` for every org type, and the import still reads a `membership_valid_until` column.
- **Scanning** (`POST /api/attendance/scan {qr_payload, scan_request_id?}`): `INVALID_QR`, `PERSON_INACTIVE` "This person is inactive.", 5-second cooldown → `DUPLICATE_SCAN` "Already scanned. Please wait before scanning again.". Scheduled mode alternates IN/OUT per period (1 to 8 periods). A first scan within 30 minutes before a period's end (`EXIT_WINDOW_MINUTES`) is saved as a time out with no time in, counted Late: "Attendance checked out. No time in was recorded today.". The same `scan_request_id` replays the first answer (`idempotent_replay: true`).
- **QR**: `GET /api/people/{id}/qr` returns `qr_payload`; `POST /api/people/{id}/regenerate-qr` replaces the token only. Events point to `person_id`. The people export never includes `qr_token`.
- **Archive**: `POST /api/people/{id}/archive` sets `is_active=false` and `archived_at`. Button "Archive Profile" on `/people/{id}/edit`.
- **History**: `person_assignments` (group/subgroup with `starts_on`/`ends_on`), versioned `schedules` (`effective_from/to`), `archived_at`. Reports use the group, schedule and active state on each date. `/people/{id}` lists "Earlier grade levels … until {date}".
- **Reports**: Daily `/attendance` (`GET /api/attendance/report?date&search&status=PRESENT|LATE|ABSENT&flag=NO_TIME_IN|NO_TIME_OUT&group_id&subgroup_id`; today's not-yet-scanned students count as Absent by design), Monthly `/attendance/monthly` (`group_id` required, optional `subgroup_id`, CSV `/api/attendance/monthly/export`), person history `/people/{id}` (`GET /api/people/{id}/attendance?from&to`, max 366 days, CSV `/export`), Needs Attention `/attendance/needs-attention` (`not_seen_days` 3|7|14|30, default 7; 3+ absences in a row; under 75% over 30 days with at least 5 scheduled days; CSV), home dashboard `/app` (today summary, recent activity, Needs Attention preview of 3).
- **Holidays** `/settings/holidays`: `POST /api/holidays {name, starts_on, ends_on?, group_id?}`, `POST /api/holidays/common {year}` adds the common Philippine holidays, `DELETE /api/holidays/{id}`.
- **Import** `/people/import` → `POST /api/people/import` (CSV, required `person_number, first_name, last_name`; optional `group, subgroup, contact_number, membership_valid_until, qr_token`; `upsert`; groups created automatically; summary `imported, updated, skipped_duplicates, failed, errors`). Export `/people/export`.
- **Team** `/settings/team`: owner adds Admin or Staff; Staff only sees `/scanner`.
- **SMS**: scanning never depends on SMS; a new trial org is not entitled, so no SMS is queued.

### Known bugs to confirm (report pass/fail, do not fix)

- **B1** Saving an archived student's profile un-archives them. `PersonController::validated` sets `is_active = $data['is_active'] ?? true`, and the edit page does not send `is_active`.
- **B2** Deleting a group erases history. `person_assignments.group_id` cascades on delete; `GroupController::destroy` only checks current members, subgroups and current schedules. `SubgroupController::destroy` also deletes ended schedule versions.
- **B3** Changing a group's mode (scheduled ↔ open) or the org industry rewrites past reports. Any admin, not only the owner, can change the industry.

---

## Prompt

```
You are working in a Laravel 12 / PHP 8.4 app (MYO Attendance). Tests use PHPUnit with SQLite in memory: run them ON THE HOST with `php artisan test --compact <path or --filter>`. NEVER run php artisan test inside Docker: Docker's env points RefreshDatabase at the real MySQL and wipes it. Format PHP with `vendor/bin/pint --dirty --format agent`.

GOAL
End-to-end test of the SCHOOL / EDUCATION organization type, as the paying school uses it day to day: sign-up, setup, enrolling students, daily scanning at the gate, reports for teachers and the principal, and the changes a school makes during the year (moving sections, lost ID cards, students leaving). Focus on workflows and features, with a strong regression focus: past records must never change.

DO NOT change application code (app/, resources/, routes/, database/, config/). Only add tests and report. If a test exposes a bug, leave the failing test in place, mark it with a `// KNOWN BUG Bx` or `// NEW BUG` comment, and report it. Only make a non-app change if it is trivially needed to run the tests (say what and why).

DATA SAFETY
- Never edit, delete or scan for any organization you did not create in this run. The local database may hold a copy of the real school; do not open its people, reports or settings beyond reading the organization list.
- Before the browser pass: docker compose exec -T mysql mysqldump -umyo -pmyo_secret --no-tablespaces --single-transaction myo_attendance > storage/app/simulation-backups/before-codex-e2e-school.sql
- Create one fresh school for the browser pass, named "E2E School <YYYYMMDD-HHMM>". Restore the backup if anything goes wrong.

READ FIRST (summarize each in one or two sentences before writing tests)
- routes/web.php (all page and /api routes), App\Support\OrganizationTypes, OrganizationTerminology, Organization::tracksWorkHours
- AuthController::register, TurnstileService::validate (no secret = allowed in local/testing), VerificationController
- ProcessAttendanceScan (handle, shiftFor, isExitScan, membership check), ScheduleController, Schedule::windows, ScheduleResolver
- PersonController (validated, archive, regenerateQr, qr, export), PersonImportController, Person model hooks, PersonAssignment
- GroupController (destroy, mode), SubgroupController::destroy, HolidayController, HolidayCalendar
- BuildDailyAttendanceReport, BuildMonthlyAttendanceReport, BuildPersonAttendanceHistory, BuildNeedsAttentionReport, AttendanceTimeline, BuildHomeDashboard
- Views: people/index, people/edit, people/show, people/import, schedules/create, groups/create, settings/holidays, settings/team, attendance/*, scanner/index, resources/js/scanner.js
- Existing tests, to reuse their helpers and style without duplicating them: PhaseThreeAttendanceTest, PhaseFourAttendanceReportTest, MonthlyAttendanceReportTest, PersonAttendanceHistoryTest, NeedsAttentionTest, HolidaysTest, MissingScanFlagsTest, AttendanceHistoryAccuracyTest, TeamAndIndustryTest, CodexRouteAndIndustryRegressionTest, StepsOneToFourEndToEndTest, ScanLogOrderingTest

TEST RULES
- Create tests/Feature/E2eSchoolWorkflowTest.php (split into E2eSchool*Test.php files if long). One method per numbered case, named test_s<part>_<case>_...
- Each test builds its own school org (type 'school', timezone Asia/Manila), owner, grade levels, sections, schedules and students. Never rely on seeded data.
- Freeze time per action with Carbon::setTestNow(Carbon::parse('<local time>', 'Asia/Manila')); reset in tearDown. 2026-11-02 is a Monday.
- Scan through POST /api/attendance/scan {qr_payload: $student->qr_token}. Write AttendanceEvent rows directly only to set up history older than the test.
- Http::fake() at the start of every test; Http::assertNothingSent() at the end (no real SMS).
- When you change the org type inside a test, change it through $user->organization.
- Assert exact statuses, codes, messages, dates and CSV headers.

Standard setup ("the school"): Grade Levels "Grade 7" (sections "Rizal", "Bonifacio") and "Grade 8" (section "Mabini"). Grade 7 schedule Mon–Fri, two periods: 07:30 start / 07:45 late after / 12:00 end, and 13:00 / 13:15 / 16:30. Grade 8 schedule Mon–Fri one period 07:30 / 07:45 / 16:00. Section Bonifacio has a subgroup override Mon–Fri 08:00 / 08:15 / 15:00. Students S7-001..S7-006 in Grade 7, S8-001..S8-003 in Grade 8.

PART 1: SIGN-UP AND SETUP
 1. POST /api/auth/register with organization_type 'school' (and Turnstile unset) creates the org, the owner (role owner), a 30-day trial; the owner must verify email before /app (redirect to /verify-email). After verification GET /app 200.
 2. GET /register lists exactly School / Education, Company, Sports Academy, Other, in that order. Report (do not fail) whether the API still accepts a hidden type like 'construction'.
 3. Labels: /people, /groups, /subgroups, /attendance/monthly show Student(s), Grade Level(s), Section(s). Rename "Section" to "Strand" on /api/organization/terminology → pages show "Strand"; reset restores Section.
 4. School-only UI: /app has no "Timesheet" link; GET /attendance/timesheet HTML and /api/attendance/timesheet — report what they return for a school (link hidden is the requirement; the URL may still load); /schedules/create has no "Unpaid break"; /people and /people/{id}/edit have no "Membership valid until"; /people/import help does not mention membership.
 5. Groups, sections and schedules: create the standard setup through the API. Duplicate grade level name (case-insensitive) → 422. A section must belong to the chosen grade level when adding a student → 422 otherwise. Overlapping periods → 422. GET /api/people/{id}/schedule for a Bonifacio student returns the section override; a Rizal student gets the grade-level schedule.
 6. Team: owner adds an Admin (teacher) and a Staff (gate guard, username "guard1", no email). The guard signs in with identifier "guard1", lands on /scanner, every other page redirects to /scanner, every other API returns 403 "Staff accounts can only scan attendance.". The guard's scans are saved with performed_by_user_id = guard.

PART 2: ENROLLING STUDENTS
 1. Add a student via POST /api/people (no membership field) → 201, a 64-character qr_token; duplicate person_number → 422 "That person number already exists in this organization."
 2. Import a CSV of the 9 students with group/subgroup names (one new grade level name auto-creates the group) → imported 9. Re-import the same file without upsert → skipped_duplicates 9. With upsert and a changed section for one student → updated 1 and a person_assignments history row.
 3. Import with a bad row (missing last_name) → failed 1 with a row error; the rest import. Import of 1001 rows → the first 1,000 import and row 1001 is counted failed with "The import is limited to 1,000 data rows."
 4. Export /people/export: header person_number, first_name, last_name, group, subgroup, contact_number, membership_valid_until; never qr_token.
 5. OBSERVATION (report, do not fail): import a school student with membership_valid_until yesterday, then scan → today the scan is refused with MEMBERSHIP_EXPIRED even though schools cannot see or edit that field. Report the result and whether the student page shows it.

PART 3: A SCHOOL DAY AT THE GATE (Mon 2026-11-02 unless noted)
 1. Grade 7 student scans 07:20 → CHECKED_IN PRESENT; 07:20:03 → DUPLICATE_SCAN cooldown; 12:01 → CHECKED_OUT; 13:05 → CHECKED_IN PRESENT; 16:35 → CHECKED_OUT; 16:40 → ATTENDANCE_COMPLETE.
 2. Late: 07:50 → CHECKED_IN LATE (event late_check_in); afternoon 13:20 → LATE.
 3. No time in: first scan 11:40 (inside the 30-minute exit window before 12:00) → CHECKED_OUT "Attendance checked out. No time in was recorded today." and counted late; daily flag NO_TIME_IN.
 4. No time out: scans 07:30 only → after the day, daily flag NO_TIME_OUT.
 5. Section override: a Bonifacio student at 08:10 → PRESENT (override late after 08:15), a Rizal student at 08:10 → LATE.
 6. Saturday 2026-11-07 09:00 scan → NO_SCHEDULE (no rest-day scans for schools).
 7. Unknown code → INVALID_QR; archived student → PERSON_INACTIVE "This person is inactive."; another school's student QR → INVALID_QR (tenant isolation).
 8. Same scan_request_id twice → same response with idempotent_replay true and one event only.
 9. Holiday: POST /api/holidays {name "Foundation Day", starts_on 2026-11-04} → Wed daily/monthly/history show Holiday, not Absent, for everyone; a Grade 8-only holiday on 2026-11-05 affects Grade 8 only; a student who scans late on a holiday still shows as Late (school rule). POST /api/holidays/common {year 2026} adds the common holidays once (second call adds 0).

PART 4: REPORTS FOR TEACHERS AND THE PRINCIPAL (scans for the week of 2026-11-02, today = 2026-11-09 10:00)
 1. Daily Nov 2: totals present/late/absent match the scans; filters status=LATE, flag=NO_TIME_OUT, group_id, subgroup_id and search by name/number each narrow correctly. Daily for today: students not yet scanned show Absent (by design, documented in docs/REPORTING.md).
 2. Monthly Nov 2026 for Grade 7 and for Grade 7 / Rizal: per-day statuses match the daily report day by day (compare programmatically, every student, every day); weekends and the holiday are not counted as Absent. CSV header and one row asserted exactly.
 3. Person history Nov 1–30: entries, attendance rate, late count match; CSV export matches; range over 366 days → 422.
 4. Needs Attention: a student absent Wed–Fri (3 in a row) → "3 absences in a row"; a student never scanned → "Never scanned"; not_seen_days 3|7|14|30 accepted, 5 → 422; CSV export matches the screen; archived students never listed.
 5. Home dashboard /app: today summary counts and recent activity (latest 3 scans, newest first) and the Needs Attention preview (max 3) match the reports.

PART 5: CHANGES DURING THE SCHOOL YEAR (REGRESSION FOCUS)
 For each case, snapshot the daily reports for Nov 2–6, the monthly report for Grade 7 and Grade 8, and each affected student's history BEFORE the change; after the change, assert the past snapshots are byte-identical unless the case says otherwise.
 1. Section move on 2026-11-09: S7-001 Rizal → Bonifacio (PUT /api/people/{id}). Past days keep Rizal; from Nov 9 the Bonifacio override applies; /people/{id} shows "Earlier grade levels" with "Grade 7 · Rizal · until Nov 8, 2026". Grade move Grade 7 → Grade 8 likewise: Grade 7 monthly keeps Nov 2–6 and shows "-" after; Grade 8 monthly starts Nov 9.
 2. Lost ID card: regenerate S7-002's QR. The old qr_token → INVALID_QR; the new one → CHECKED_IN; event count unchanged; history, monthly and Needs Attention unchanged; GET /api/people/{id}/qr returns the new token.
 3. Schedule change on 2026-11-09 (Grade 7 late after 07:45 → 08:00): Nov 2 07:50 scan stays LATE; a Nov 9 07:50 scan is PRESENT; GET /api/schedules lists only the current version.
 4. Student leaves: archive S7-003 on 2026-11-05 15:00 → scans refused (PERSON_INACTIVE); Nov 2–4 kept; not expected from Nov 5; not in Needs Attention; still in the monthly for days before.
 5. KNOWN BUG B1: open /people/{id}/edit for the archived S7-003 and save it the way the page does (PUT /api/people/{id} without is_active). EXPECTED: still archived (is_active false, archived_at unchanged), scan still PERSON_INACTIVE. Report PASS/FAIL. Also check the browser: does the edit page show the student as archived?
 6. KNOWN BUG B2: move every Grade 8 student to Grade 7, end Grade 8's schedules (DELETE /api/schedules/{id}), delete Mabini, then DELETE /api/groups/{grade 8}. EXPECTED: either the delete is refused (409) because past records refer to it, or every past day the students spent in Grade 8 is still in their history and the daily reports. Report what happens to person_assignments rows, the Nov 2–6 daily rows, and the students' histories.
 7. KNOWN BUG B2 (subgroup): same for a section (Bonifacio) whose students moved out: EXPECTED past rows still name Bonifacio and the override schedule still explains past Late/Present.
 8. KNOWN BUG B3 (group mode): switch Grade 7 to attendance_mode open, then back to scheduled. EXPECTED: Nov 2–6 monthly/daily/history identical to the snapshot after each switch. Report the diff.
 9. KNOWN BUG B3 (industry): PUT /api/organization/industry {type: company} then back to school, as an ADMIN (not owner). EXPECTED: past Nov 2–6 school reports identical; the custom label "Strand" is lost on switch (report it as a data-loss observation); report that an admin was allowed to switch.
 10. Teacher edits a student's name and contact number → no history change, no new person_assignments row.

PART 6: BROWSER PASS ON THE RUNNING APP (http://localhost:8000, Docker compose; Mailpit http://localhost:8025)
 Do this only after Parts 1–5 run. Real time only, so time-dependent cases stay in PHPUnit.
 1. Register "E2E School <timestamp>" at /register as School / Education. Verify the email from Mailpit. Land on /app with the trial banner.
 2. Through the UI: create Grade 7 (Rizal, Bonifacio) and Grade 8 (Mabini), the schedules, 6 students (2 added by hand, 4 by importing a CSV you write to storage/app/e2e/school.csv). Confirm no membership field and no break field anywhere.
 3. Scan: open /people/{id}, read the token from GET /api/people/{id}/qr, then on /scanner run in the console:
    fetch('/api/attendance/scan',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({qr_payload:'<token>'})}).then(r=>r.json()).then(console.log)
    Scan 4 students once and one twice within 5 seconds. Check /app recent activity and /attendance (today) show the same scans in time order.
 4. Add a staff guard on /settings/team, sign in as the guard in a private window: only the scanner and "Log Out".
 5. Regenerate one student's QR on /people/{id}; the old token is refused, the new one works, the student's history is unchanged.
 6. Archive one student, then open Edit and click Save (B1 in the real UI). Record whether the badge returns to ACTIVE.
 7. Download the people export, monthly CSV, person history CSV and Needs Attention CSV; check headers; open one in a text editor and confirm no qr_token.
 8. As super/password, /super-admin lists the new school with type School and a trial; change nothing.
 Screenshots of each step to storage/app/e2e/school/ (phone 390x844 and desktop 1280x800 for /app, /attendance, /people/{id}).

FINISH
Run the new test file(s), then the full suite on the host. Report in this format:

| Part.case | Result | Detail |
|---|---|---|
| 1.1 | PASS | |
| 5.5 | FAIL (KNOWN B1) | archived_at null after PUT without is_active |

Then list: total tests, passed, failed; each known bug (B1, B2, B3) as CONFIRMED or NOT REPRODUCED with the exact assertion; any NEW bug with steps, expected, actual and a one-line likely cause (file:line); observations (not bugs) such as the hidden-type API and membership on imported students; anything you could not test and why; the path of the DB backup and whether you restored it.
```
