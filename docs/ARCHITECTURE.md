# MYO Attendance V2 — Core V1 Architecture

This is one Laravel application. Laravel owns the database, validation, tenant boundaries, authentication, attendance/schedule/QR business rules, and reporting. React is a Vite-mounted SPA-style presentation layer.

## Authentication

Phase 1 uses Laravel's first-party session/cookie guard, not JWT or a custom token system. Login regenerates the session; logout invalidates it and regenerates the CSRF token. State-changing requests use Laravel's CSRF middleware and the `X-CSRF-TOKEN` header. Session cookies are HTTP-only and same-site lax for local same-origin use.

Registration validates server-side and creates Organization plus owner User inside one database transaction. Users implement Laravel's email verification and password reset contracts. Verification uses Laravel's signed route; resets use Laravel's password broker and `password_reset_tokens` table. Local SMTP is Mailpit only.

The protected `/app` boundary requires both `auth` and `verified`. Unverified users can access only verification state/resend/logout. The authenticated tenant is always `$request->user()->organization`; no frontend organization identifier is accepted as authority.

## Domain

The fixed V1 hierarchy is `Organization → Group → optional Subgroup → Person`. Every tenant-owned table carries `organization_id`. Composite foreign keys enforce that group, subgroup, person, and schedule references cannot cross organizations; future authenticated queries must also begin from the authenticated user's organization scope.

## Attendance

`attendance_events` is the normalized source of truth. It stores `check_in`, `late_check_in`, and `check_out` (plus future-compatible event values), with JSON metadata for non-core context. Present and late are derived in reporting; late is a subset of present. Diagnostic `scan_ignored` records from legacy are not part of the canonical history unless a later migration explicitly preserves them.

`POST /api/attendance/scan` is handled by `ProcessAttendanceScan`, keeping the controller thin. It resolves QR directly under `organization_id`, validates active people, calls `ScheduleResolver`, and calculates the local attendance date from the organization's timezone. Stored event timestamps are UTC. The first accepted event is IN, the second is OUT, and a third returns `ATTENDANCE_COMPLETE` without inserting an event. Request IDs are tenant-scoped for idempotency, a five-second cooldown suppresses repeated scans, and the service locks the Person in a transaction before reading and inserting events to prevent concurrent IN/OUT races.

The legacy `daily_attendances` table is not reproduced. Its mutable daily status/check-in/check-out fields duplicate event history. V2 reports derive daily results from events and active people/schedules; this keeps one authoritative history and avoids synchronization drift. A schedule may contain ordered periods; the attendance state alternates IN and OUT through those periods and completes after all configured periods are used.

## Groups, Subgroups, and People

Phase 2 keeps the generic tenant hierarchy `Organization → Group → optional Subgroup → Person`. Resource controllers begin every query from `$request->user()->organization` and use scoped `findOrFail` calls, so cross-tenant IDs resolve as safe 404 responses. Relationship IDs are checked against the same organization and, for Subgroups, the selected Group. Person numbers are strings and unique per organization. People are deactivated with `is_active=false`; Group/Subgroup deletion is refused when dependencies exist.

## Schedules

Schedules belong to a group and optionally a subgroup. `day_of_week`, `start_time`, `late_after`, `end_time`, and `is_active` are deliberately simple. A subgroup schedule is the future override of its group's schedule for that day.

`App\Services\ScheduleResolver` is authoritative. Given a Person and date, it checks the active Subgroup schedule for the ISO weekday first, then the active Group schedule, and returns null when neither applies. React never implements this rule.

## QR identity

Every Person receives a 64-character cryptographically secure random token generated server-side with Laravel's `Str::random`, protected by a unique database index. Tokens contain no personal data or sequential ID. Legacy tokens can be retained during an explicit import because they are already opaque; see `docs/LEGACY_DATABASE.md`.

Phase 2 exposes tenant-protected QR payload and regeneration operations. Regeneration writes a new unique token, invalidating the old token. The React layer uses a QR rendering dependency only to display the server-returned opaque payload; it does not generate or validate credentials.

## CSV import

The CSV importer validates file type, required headers, size, and a 1,000-row limit. Each row is processed transactionally. Valid rows are imported, duplicate organization-local person numbers are skipped, and invalid rows are reported without discarding other valid rows. Group and Subgroup matching is trimmed and case-insensitive within the authenticated organization.

## Frontend boundary

The shell is mounted through `resources/js/app.js` and Laravel Vite. `resources/js/services/api.js` centralizes HTTP/CSRF handling and `resources/js/auth/auth.js` centralizes authentication calls. React pages render state and trigger actions; tenant isolation, validation, verification, password resets, QR validation, schedule resolution, and attendance classification remain backend responsibilities.
