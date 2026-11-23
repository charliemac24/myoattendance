# Core V1 reporting semantics

The daily report uses the organization's timezone to select the local date and resolve the active schedule. An active person is `EXPECTED` only when a subgroup override or group fallback schedule applies for that date and the date is on or after their start date (the day they were added, or their first scan if earlier). A person with no applicable schedule is excluded from the report.

An accepted `check_in` produces `PRESENT`; an accepted `late_check_in` produces `LATE`, which is a subset of Present. A person may have multiple period IN/OUT events, but the first accepted IN determines arrival status. An expected person with no accepted check-in is `ABSENT`. Check-out never changes arrival status; the report displays the first check-in and latest check-out. The report derives its rows from immutable attendance events and returns `expected_total`, `present`, `late`, and `absent`; therefore `present + absent = expected_total` and `late <= present`.

The `PRESENT` filter includes `LATE`. Search, group, and subgroup filters apply to returned rows while summary counts remain the full selected-day summary. Core V1 treats today with no accepted check-in as `ABSENT` (“no accepted check-in yet”); no future-date behavior is promised beyond the documented date query contract.


The live daily report keeps this behavior for today because it answers "who has not scanned yet". The monthly report, person history and "Needs attention" do not count today as Absent until the day is over (see below).

# Person attendance history

`GET /api/people/{person}/attendance?from=YYYY-MM-DD&to=YYYY-MM-DD` (both optional; default the last 30 days ending today in the organization's timezone; at most 366 days) returns `person`, `from`, `to`, `today`, `summary` and `days` (newest first). `GET /api/people/{person}/attendance/export` takes the same range and downloads a CSV (`ID Number, Full Name, Date, Day, Status, Time In, Time Out, Minutes Late, Note`). Both are scoped to the user's organization (404 otherwise) and include archived people.

A day is listed when the person had a schedule or scanned that day, from their start date up to today. Each day has `status` (`present`, `late`, `absent`, `not_yet`), `scheduled`, `time_in` (first time in), `time_out` (last time out), `minutes_late` (minutes after the late-after time of the period the late scan fell in), and the `no_time_in` / `no_time_out` flags. `not_yet` is used for today with no scan while the day is open. Scans on an unscheduled day are listed with `scheduled = false`.

`summary.attendance_rate` = attended scheduled days ÷ scheduled days (present + late + absent), excluding today while it is `not_yet` and excluding unscheduled scan days. `summary.last_seen` is the latest scan up to now, even outside the range. The shared logic lives in `App\Services\AttendanceTimeline`; the person page shows it under "Attendance" with 7/30/90-day shortcuts and From/To dates.

# Needs attention

`GET /api/attendance/needs-attention?not_seen_days=7` (3, 7, 14 or 30; default 7) and `/api/attendance/needs-attention/export` (CSV) list active people with at least one reason:

- **Not seen in N days:** the last scan was N or more days ago (or never, for someone added N or more days ago), and the person had a scheduled day in the last N days. People with no schedule are never flagged.
- **Absences in a row:** absent on their last 3 or more scheduled days (today is skipped while it is still open).
- **Low attendance:** below 75% of scheduled days attended in the last 30 days, with at least 5 scheduled days.

Rows show last seen, days since, absence streak, 30-day rate (attended / scheduled days), group, subgroup and contact number, sorted by longest unseen first (never scanned first). The thresholds are constants on `App\Services\BuildNeedsAttentionReport`. Home shows the first three with a link to the full page (`/attendance/needs-attention`, also in the menu).

# People CSV

The People export no longer includes `qr_token`, because anyone holding a QR value can scan in as that person. Re-importing an export with "Update existing persons" keeps each person's current QR code.
