# Legacy database review

Source reviewed read-only: `/Users/charlieanchetamacaraeg/Downloads/nssgsjhj_myo_attendance.sql` (18 tables, 43,257 lines, 13,306,262 bytes). The original file is not copied, modified, or used as the V2 schema.

## Tables discovered

Core/domain: `schools`, `users`, `grade_levels`, `sections`, `students`, `attendance_events`, `daily_attendances`.

Operational support: `kiosk_locations`, `kiosk_scan_idempotency`, `teacher_sections`, `school_holidays`, `__drizzle_migrations`.

SMS/configuration intentionally outside Phase 0 core: `global_sms_settings`, `grade_sms_policies`, `section_sms_policies`, `school_notification_settings`, `sms_logs`, `sms_notifications`, `sms_templates`.

## Mapping

| Legacy | V2 | Decision |
|---|---|---|
| schools | organizations | Reuse name/timezone; omit school-only/SMS settings |
| students | persons | `student_no` → `person_number`; omit guardian/photo fields |
| grade_levels | groups | Generic terminology |
| sections | subgroups | Preserve group relationship |
| school_id | organization_id | Tenant key |
| student_id | person_id | Generic person key |
| attendance_events | attendance_events | Remap identifiers and event names |
| daily_attendances | none | Derive from events; do not duplicate mutable summary |
| users | users | Map `full_name` → `name`; password compatibility must be verified |
| QR `students.qr_token` | `persons.qr_token` | Retain opaque legacy tokens where unique |
| kiosk tables | none in Phase 0 | Revisit with scanning workflow |
| teacher_sections | none in Phase 0 | Revisit only if authorization needs it |
| holidays/SMS tables/settings | none | Explicitly deferred |
| __drizzle_migrations | none | Tool metadata, not application data |

Legacy attendance types observed include `check_in`, `late_check_in`, `out_final`, and `scan_ignored`. V2 maps `out_final` to `check_out`; `scan_ignored` is diagnostic and is not imported into canonical attendance history by default.

## Import strategy

No legacy rows were imported in Phase 0. The safe path is a future idempotent import service/command that connects to a copy of the legacy database (or uses a reviewed, temporary staging database), imports organizations → groups → subgroups → persons → users, preserves opaque QR tokens when collision-free, then maps daily attendance events. It must validate tenant relationships and maintain an old-ID mapping during the transaction. Do not parse the production dump blindly or run it against the V2 schema.

The dump contains 1 school, 17 grade levels, 6 sections, 157 students, 5 users, and substantial attendance/SMS history. Exact row-level import is pending because Phase 0 explicitly prioritizes the canonical foundation over a large data migration.

Legacy bcrypt hashes use the `$2b$` prefix. Laravel's compatibility should be proven against real credentials before enabling imported login; otherwise imported users need a password reset/activation flow. Core V1 registration, verification, session login, logout, and password reset apply only to canonical V2 users; legacy import remains optional and is not required for a fresh installation.
