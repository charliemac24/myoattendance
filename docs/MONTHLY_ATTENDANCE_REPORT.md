# Monthly Attendance Matrix Report

## Endpoints

Both endpoints require an authenticated, verified user and derive the tenant from the session:

- `GET /api/attendance/monthly?month=YYYY-MM&group_id={id}&subgroup_id={id?}` returns the structured matrix.
- `GET /api/attendance/monthly/export?month=YYYY-MM&group_id={id}&subgroup_id={id?}` downloads the same matrix as CSV.

`month` must be exactly `YYYY-MM`. `group_id` is required and must belong to the authenticated organization. `subgroup_id` is optional, but when supplied it must belong to the selected group and organization. Invalid cross-tenant or mismatched relationships return a scoped 404.

## Response

The preview response contains `month`, `group`, optional `subgroup`, `dates` (every weekday, plus any Saturday or Sunday on which someone in the list has a schedule), and sorted `persons`. Each person contains the original string `person_number`, `full_name`, and an `attendance` object keyed by ISO date.

Cell values are `Present`, `Late`, `Absent`, or `-`. A dash means no applicable active schedule, a future date, a date before the person started being counted, or today while the day is still open. Active people are sorted by last name, first name, then person number. Archived people are excluded, matching the Daily Attendance report; historical membership is not reconstructed because the current model has no membership history.

## Classification

Schedule resolution uses the existing subgroup override, then group fallback rule. Weekdays without an applicable schedule are neutral, not absent. For expected dates, an accepted on-time check-in produces `Present`, any accepted late check-in produces `Late`, and no accepted check-in produces `Absent`. Multiple IN/OUT periods remain one cell per date and OUT events do not determine the classification. The shared classifier is also used by the Daily Attendance report.

Dates after the organization's current local date are neutral (`-`), never absent. Past expected dates may be Present, Late, or Absent. A Saturday or Sunday is included only when at least one listed person has a schedule that day.

A person is counted from their start date: the day they were added (`persons.created_at`, in the organization's timezone) or their first scan, whichever is earlier. Scheduled days before that are `-`. Today with no scan stays `-` until the day is over: until the latest period end time when every period has one, otherwise until midnight. A scan today is shown right away.

## CSV

CSV headers are `ID Number,Full Name` followed by the date labels such as `Sep 1,Sep 2`. The export uses the same report service as the preview, streams the output, escapes names and values with standard CSV quoting, and preserves person numbers as strings. The filename is `monthly-attendance-YYYY-MM-group[-subgroup].csv`; spreadsheet programs may still auto-format CSV values independently.
