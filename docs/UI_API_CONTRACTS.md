# Core V1 UI/API contracts

All API requests are same-origin session requests with `credentials: same-origin`, `Accept: application/json`, and the current `X-CSRF-TOKEN`. Protected Core V1 endpoints require an authenticated, verified user. Tenant ownership is derived server-side from that user.

## Authentication

- `POST /api/auth/register`: accepts `name`, `organization_name`, `organization_type`, `email`, `password`, `password_confirmation`; returns `201 { user, csrf_token }` and sends verification mail.
- `POST /api/auth/login`: accepts `email`, `password`; returns `{ user, csrf_token }`.
- `GET /api/auth/user`: returns `{ user }` without password or remember token; `401` means signed out.
- `POST /api/auth/logout`: invalidates the session and CSRF token.
- `POST /api/auth/verification/resend`, `POST /api/auth/forgot-password`, and `POST /api/auth/reset-password` preserve their Laravel validation/error responses. Reset accepts `token`, `email`, `password`, and `password_confirmation`.

## Resources

Person CSV import requires person_number, first_name, and last_name. Optional columns are group, subgroup, contact_number, and qr_token. The importer page has an opt-in “Update existing persons” mode that matches by organization and person_number; without it, existing rows remain skipped. In upsert mode, omitted optional columns preserve existing values, while supplied values update the matching person. New rows are still imported in either mode.

Person CSV export is GET /api/people/export and returns a tenant-scoped persons.csv file with the import-compatible columns person_number, first_name, last_name, group, subgroup, and contact_number. QR values (qr_token) are never exported, because anyone holding one can scan in as that person. To apply edits to existing exported rows, re-import the file with “Update existing persons” enabled; omitted qr_token keeps each person's current QR code.

`/api/groups`, `/api/subgroups`, `/api/schedules`, and `/api/people` provide JSON pagination (`data`, pagination metadata) plus scoped CRUD as exposed by the routes. `/api/groups/options` and `/api/subgroups/options` provide complete tenant-scoped selector lists. Validation failures are `422` with `{ message, errors }`; cross-tenant IDs are hidden as `404`. People use string `person_number` values. CSV import is `POST /api/people/import` with multipart field `file`, returning `{ imported, skipped_duplicates, failed, errors }`.

QR display is `GET /api/people/{person}/qr` → `{ person_id, qr_payload }`; regeneration is `POST /api/people/{person}/regenerate-qr` → `{ person, qr_payload }`. The payload is opaque and must be rendered, never interpreted by React.

## Attendance scanner

`POST /api/attendance/scan` accepts `{ qr_payload, scan_request_id? }`. Successful responses use code `CHECKED_IN` or `CHECKED_OUT` and include `data.person`, `data.direction`, `data.attendance_status` (for IN), `data.event_type`, UTC `occurred_at`, organization-local `local_time`, and `data.schedule`. Stable business codes are `INVALID_QR`, `PERSON_INACTIVE`, `NO_SCHEDULE`, `DUPLICATE_SCAN`, and `ATTENDANCE_COMPLETE`; validation uses `VALIDATION_ERROR` with HTTP `422`. Business results are JSON and may use HTTP `200` for rejected scans.

The server is authoritative for organization timezone, schedule, late boundary, cooldown, idempotency, and IN/OUT state. A repeated request ID returns the original result with `idempotent_replay: true` and does not create another event.

Schedules retain the existing first-period fields (`start_time`, `late_after`, `end_time`) and may also include ordered `periods`, each with `start_time`, `late_after`, and optional `end_time`. The scanner alternates IN and OUT through the configured periods; a two-period schedule permits `IN → OUT → IN → OUT` before `ATTENDANCE_COMPLETE`. The schedule editor submits both the first-period compatibility fields and the full `periods` array.

## Daily report

`GET /api/attendance/report?date=YYYY-MM-DD&search=&status=&group_id=&subgroup_id=` returns `{ date, summary, people }`. Summary keys are `expected_total`, `present`, `late`, and `absent`; `late` is included in `present`, and `present + absent = expected_total`. Each row contains person/group/subgroup, `status` (`PRESENT`, `LATE`, or `ABSENT`), `is_late`, `check_in_time`, and `check_out_time`. Filters affect `people`; summary remains the full selected-day summary. Only active people with an applicable active schedule are expected.
