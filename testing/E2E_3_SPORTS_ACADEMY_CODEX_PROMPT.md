# E2E test 3 of 3: Sports Academy (Codex prompt)

Paste the block below into Codex from the root of the MYOattendanceV2 repo, after prompts 1 (School) and 2 (Company). It tests a sports academy or gym the way the front desk uses it: walk-in check-ins any time, membership dates, lost cards, visit counts, "not seen in 7 days", and the exports the owner reviews. Codex tests only and must not change app code.

## What the code does today for sports academies (checked before writing this prompt)

- **Labels**: Athlete(s), Sport(s), Team(s). Not an employer (`tracksWorkHours()` false), so no Timesheet, no break field and no rest-day scans. Apart from labels it behaves exactly like Other.
- **Group mode** (`groups.attendance_mode`, `groups.scan_out`, partial `groups/partials/mode-fields.blade.php`): "How do athletes check in?" with "On a schedule" or "Anytime (open hours)"; "scan out" only for open groups (saving a scheduled group with scan_out true stores false).
- **Open hours** (`ProcessAttendanceScan::openVisit`): any day, any time, no schedule needed, never Late or Absent. Each scan is a visit (`check_in`, `meta.mode=open`): "Checked in." then "Checked in. Visit 2 today."; a repeat within 30 minutes (`OPEN_REPEAT_MINUTES`) → `DUPLICATE_SCAN` "Already checked in at 6:30 AM." (not saved); within 5 seconds → "Already scanned. Please wait before scanning again.". With scan_out, scans alternate in/out ("Checked out.").
- **Membership** (`persons.membership_valid_until`): a scan after that date → `MEMBERSHIP_EXPIRED` "Membership expired on Nov 1, 2026." and nothing is saved (any group mode). A successful scan returns `data.membership {valid_until, days_left}`; the scanner shows "Membership: Ends today / tomorrow / in N days (date)" when 7 or fewer days remain. `/people/{id}` shows the date and "Expired". Import/export column `membership_valid_until` (YYYY-MM-DD; blank clears it on upsert).
- **Reports for open groups**: daily lists open members only on days they came (`visits`); monthly shows every day as "Visited", "Visited (2)" or "-", with `total_visits`, `open_hours: true` and a "Total Visits" CSV column; history has `summary.visits`, `summary.days_visited`, `attendance_rate` null; Needs Attention flags open members only for not_seen ("Not seen in N days" / "Never scanned"; options 3, 7, 14, 30; default 7); holidays do not apply to open groups.
- **Not in the code (observations, not bugs)**: an athlete in two teams/sports at once, a coach account limited to their own team (Staff = scanner only; Admin sees everything), an "expiring soon / expired" list, a log of refused (expired) scans, arrival-only scanning for scheduled groups.
- Existing coverage to build on, not duplicate: `OpenHoursAndMembershipTest`, `NeedsAttentionTest`, `StepsOneToFourEndToEndTest` (step 2), `MonthlyAttendanceReportTest`, `PersonAttendanceHistoryTest`.

### Known bugs to confirm (report pass/fail, do not fix)

- **B1** Saving an archived athlete's profile un-archives them (`PersonController::validated` defaults `is_active` to true).
- **B2** Deleting a sport (group) erases former members' visit history (`person_assignments.group_id` cascades).
- **B3** Changing a group's mode (open ↔ scheduled) or the org industry rewrites past reports (`groupOn()` reads the group's current mode). Any admin can switch.
- **B4 (limitation)** In a scheduled group, scanning only on arrival records the second session's arrival as the first session's time out.

---

## Prompt

```
You are working in a Laravel 12 / PHP 8.4 app (MYO Attendance). Tests use PHPUnit with SQLite in memory: run them ON THE HOST with `php artisan test --compact <path or --filter>`. NEVER run php artisan test inside Docker: Docker's env points RefreshDatabase at the real MySQL and wipes it. Format PHP with `vendor/bin/pint --dirty --format agent`.

GOAL
End-to-end test of the SPORTS ACADEMY organization type, used the way a gym or sports club runs its front desk: sign-up, sports and teams, members with membership end dates, check-in at any time, renewals, lost cards, members who stop coming, and the owner's monthly exports. Include one scheduled training squad to show where scheduled mode fits and where it does not. Focus on workflows and features; reuse existing helpers.

DO NOT change application code (app/, resources/, routes/, database/, config/). Only add tests and report. If a test exposes a bug, leave the failing test in place, mark it `// KNOWN BUG Bx` or `// NEW BUG`, and report it. Only make a non-app change if trivially needed to run tests (say what and why).

DATA SAFETY
- Never edit, delete or scan for any organization you did not create in this run. Do not touch the real school's data or the seeded demo orgs (including the "Open Gym" demo group in MYO Demo Organization).
- Before the browser pass: docker compose exec -T mysql mysqldump -umyo -pmyo_secret --no-tablespaces --single-transaction myo_attendance > storage/app/simulation-backups/before-codex-e2e-sports.sql
- Create one fresh org for the browser pass: "E2E Sports Academy <YYYYMMDD-HHMM>". Restore the backup if anything goes wrong.

READ FIRST (summarize each in one or two sentences)
- App\Support\OrganizationTypes, OrganizationTerminology, Group model (isOpen, MODE_*), GroupController (store, update, mode, destroy), groups/partials/mode-fields.blade.php
- ProcessAttendanceScan (handle membership check, openVisit, membershipData, cooldown), resources/js/scanner.js (membership and visits rows)
- PersonController (validated, archive, regenerateQr, qr, export), PersonImportController (membership_valid_until, upsert), people/index, people/edit, people/show
- BuildDailyAttendanceReport, BuildMonthlyAttendanceReport (open-hours branch), BuildPersonAttendanceHistory, BuildNeedsAttentionReport, AttendanceTimeline, BuildHomeDashboard, NeedsAttentionController, AttendanceReportController (monthly export)
- Tests: OpenHoursAndMembershipTest, NeedsAttentionTest, StepsOneToFourEndToEndTest, MonthlyAttendanceReportTest

TEST RULES
- Create tests/Feature/E2eSportsAcademyWorkflowTest.php (split into E2eSportsAcademy*Test.php if long). One method per numbered case: test_a<part>_<case>_...
- Each test builds its own org (type 'sports_academy', Asia/Manila), owner, sports, teams and athletes.
- Freeze time per action with Carbon::setTestNow(Carbon::parse('<local>', 'Asia/Manila')); reset in tearDown. 2026-11-02 is a Monday.
- Scan through POST /api/attendance/scan. Write events directly only for older history.
- Http::fake() at the start; Http::assertNothingSent() at the end.
- Assert exact codes, messages, statuses, counts and CSV headers.

Standard setup ("the academy"):
 - Sport "Open Gym": Anytime (open), no scan out. Team "Monthly Members".
 - Sport "Boxing": Anytime (open) WITH scan out. Team "Boxing Class".
 - Sport "Swimming": On a schedule, Tue and Thu 16:00 start / 16:15 late after / 18:00 end. Team "Swim Squad".
 Athletes: A-01 Gina (Open Gym, membership to 2026-11-30), A-02 Ex (Open Gym, expired 2026-11-01), A-03 Rio (Open Gym, ends 2026-11-05), A-04 Max (Boxing, to 2026-12-31), A-05 Lia (Swimming, to 2026-12-31), A-06 Nil (Open Gym, no membership date), A-07 Quiet (Open Gym, never scans).

PART 1: SIGN-UP AND SETUP
 1. Register with organization_type 'sports_academy' → labels Athlete/Sport/Team on /people, /groups, /subgroups, /attendance/monthly; no "Timesheet" link on /app; no "Unpaid break" on /schedules/create; person forms DO show "Membership valid until".
 2. Group form: /groups/create shows "How do athletes check in?" with "On a schedule" and "Anytime (open hours)"; POST /api/groups with attendance_mode open + scan_out true saves both; scheduled + scan_out true stores scan_out false; PUT without attendance_mode keeps the mode; bad mode → 422.
 3. Add athletes by form (with membership dates) and by CSV import with a membership_valid_until column; bad date "2026-13-01" → 422 on the form and a failed row on import.
 4. Team: owner adds a Staff "frontdesk" (scanner only) and an Admin "coach". Observation: the coach admin can see every sport and team (no team-scoped access); report it, do not fail.
 5. Parity: switch the org to 'other' and back → only labels change; repeat case 2.1 under 'other' and assert identical scan results (data provider).

PART 2: THE FRONT DESK
 1. Gina, Mon 2026-11-02 06:30 → CHECKED_IN PRESENT "Checked in.", mode open, visits_today 1, membership {valid_until 2026-11-30, days_left 28}; 06:30:03 → "Already scanned. Please wait before scanning again."; 06:45 → DUPLICATE_SCAN "Already checked in at 6:30 AM." and no event saved; 18:00 → "Checked in. Visit 2 today.", visits_today 2.
 2. Gina on Sat 11/07 and Sun 11/08 at 21:30 → accepted (any day, any time); never Late.
 3. Ex (expired 11/01) on 11/02 → MEMBERSHIP_EXPIRED "Membership expired on Nov 1, 2026." with no event saved; the refused scan does not appear in the daily report, home recent activity or history (observation: refused scans leave no trace).
 4. Rio (ends 11/05): on 11/02 days_left 3 (scanner would show "Ends in 3 days"); on 11/04 days_left 1 ("tomorrow"); on 11/05 days_left 0 ("today") and allowed; on 11/06 MEMBERSHIP_EXPIRED. Nil (no date) → no membership key in data.
 5. Renewal: on 11/06 the desk renews Rio to 2026-12-05 via PUT /api/people/{id} → next scan accepted; PUT without the field keeps the date; renew in bulk via CSV upsert (membership_valid_until column) → updated count; a blank cell clears the date.
 6. Boxing with scan out: Max 17:00 in → CHECKED_IN; 18:30 → CHECKED_OUT "Checked out."; 19:00 → CHECKED_IN (visits_today 2).
 7. Lost card: regenerate Gina's QR. Old token → INVALID_QR; new token → CHECKED_IN; her visit count and history unchanged; GET /api/people/{id}/qr returns the new token; the people export never includes qr_token.
 8. Member leaves: archive Nil → PERSON_INACTIVE "This person is inactive."; past visits kept; not in Needs Attention.
 9. Another academy's athlete token → INVALID_QR. Same scan_request_id twice → one event, idempotent_replay true.

PART 3: SCHEDULED SWIM SQUAD
 1. Lia Tue 11/03 15:55 in → PRESENT; 18:05 out → CHECKED_OUT; 18:10 → ATTENDANCE_COMPLETE. Thu 11/05 16:20 → LATE. Tue 11/10 no scans → Absent after the day.
 2. Lia Sat 11/07 → NO_SCHEDULE (not an employer, no rest-day scans).
 3. KNOWN LIMITATION B4: a squad with two sessions on Tue (16:00–17:00 and 17:30–18:30) and arrival-only scanning at 15:58 and 17:28 → report how the 17:28 scan is saved (expected per code: time out of session 1) and how daily/monthly show the day.
 4. Holidays: a holiday on Thu 11/12 marks Lia's day Holiday, not Absent; open members are unaffected and can still check in that day.

PART 4: OWNER'S REPORTS (visits for Nov 1–20 2026; today = 2026-11-21 10:00)
 Seed a realistic month with scans: Gina 12 visit days (2 of them with 2 visits), Max 8 days, Rio 5 days, A-07 Quiet none, plus Lia's squad days.
 1. Daily for a busy day: lists only open members who came (visits count), absent 0 for open members, plus Lia if scheduled; filters group_id/subgroup_id/search work.
 2. Monthly Open Gym Nov 2026: 30 date columns including weekends; "Visited", "Visited (2)", "-"; total_visits per member equals the seeded count; open_hours true. CSV has "Total Visits"; assert the header and Gina's row exactly. Monthly Swimming stays the normal Present/Late/Absent grid.
 3. Person history Nov 1–20 for Gina: summary.visits, summary.days_visited, attendance_rate null; CSV export matches.
 4. Needs Attention (default 7): Quiet → "Never scanned"; a member whose last visit was 11/12 → "Not seen in 9 days"; open members never get "absences in a row" or "% attendance"; not_seen_days 3/14/30 change the list as expected; 5 → 422; CSV matches the screen; an expired member (Ex) — report whether they are listed (observation: the desk may want expired members separated).
 5. Home dashboard /app: today summary, recent activity (newest 3) and the Needs Attention preview match the reports.
 6. People export: membership_valid_until column for every athlete; owner can sort it in a spreadsheet to find expiring members (observation: no built-in expiring/expired list).

PART 5: CHANGES OVER TIME (REGRESSION FOCUS)
 Snapshot monthly (Open Gym, Boxing, Swimming), daily for 11/02–11/06 and each affected athlete's history before each change; assert identical afterwards unless stated.
 1. Plan change on 11/16: Max Boxing → Open Gym. Boxing monthly keeps Max's visits through 11/15 and "-" after; Open Gym starts 11/16; /people/{id} shows "Earlier sports" with "Boxing · Boxing Class · until Nov 15, 2026".
 2. KNOWN BUG B1: PUT /api/people/{Nil} without is_active (the edit page's save). EXPECTED still archived, scan still PERSON_INACTIVE. Report PASS/FAIL.
 3. KNOWN BUG B2: after case 1, Boxing has no members; delete team "Boxing Class", then DELETE /api/groups/{Boxing}. EXPECTED refused, or Max's Nov 1–15 Boxing visits still in his history and the daily reports. Report the person_assignments rows and the diff.
 4. KNOWN BUG B3 (mode): switch Open Gym to "On a schedule" (no schedules) and back to Anytime. EXPECTED Nov visits identical after each switch; report what the past month shows while scheduled (e.g. visits disappearing or days turning into "-"/Absent) and after switching back.
 5. KNOWN BUG B3 (industry): as the coach ADMIN, switch sports_academy → company and back. EXPECTED no past report changes; report whether the admin was allowed, whether the Timesheet appears while on company, and any diff.
 6. Editing an athlete's name, contact or membership date never changes past visits.

PART 6: BROWSER PASS (http://localhost:8000 via Docker compose; Mailpit http://localhost:8025)
 Real time only; time-dependent cases stay in PHPUnit.
 1. Register "E2E Sports Academy <timestamp>" as Sports Academy, verify in Mailpit.
 2. In the UI: create Open Gym (Anytime), Boxing (Anytime + scan out), Swimming (On a schedule) with a schedule; add 5 athletes with membership dates (one ending in 3 days, one expired yesterday, one with none), 2 by hand and 3 by CSV import.
 3. Sign in as the front desk Staff in a private window and scan on /scanner with:
    fetch('/api/attendance/scan',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({qr_payload:'<token from GET /api/people/{id}/qr as owner>'})}).then(r=>r.json()).then(console.log)
    Check: the expiring member's response has membership.days_left 3; the expired member is refused; a second scan within 30 minutes is refused with "Already checked in at …"; Boxing alternates in/out.
 4. As owner: /attendance (today) lists only who came; /attendance/monthly for Open Gym shows "Visited"; download the monthly CSV (Total Visits), the people export (membership column, no qr_token) and the Needs Attention CSV.
 5. Regenerate one athlete's QR on /people/{id}; the old token is refused and the new one works; the page still shows the same visits.
 6. Archive one athlete and click Save on its edit page (B1 in the real UI).
 7. As super/password, /super-admin lists the academy with type Sports Academy; change nothing.
 Screenshots to storage/app/e2e/sports/ (phone 390x844 for /scanner results and /people/{id}; desktop 1280x800 for /attendance/monthly and /attendance/needs-attention).

FINISH
Run the new file(s), then the full suite on the host. Report:

| Part.case | Result | Detail |
|---|---|---|
| 2.1 | PASS | |
| 5.4 | FAIL (KNOWN B3) | Gina's Nov visits shown as "-" while Open Gym is scheduled |

Then: total tests, passed, failed; B1, B2, B3 each CONFIRMED or NOT REPRODUCED with the exact assertion, and what B4 does; NEW bugs with steps, expected, actual and likely cause (file:line); observations for a gym pilot (no expiring list, refused scans not logged, no team-scoped coach, one sport per athlete); what you could not test and why; the DB backup path and whether you restored it.
```
