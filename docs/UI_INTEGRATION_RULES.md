# UI integration rules — Core V1 handoff

The UI may be redesigned without changing backend business logic. Adapt the UI to the stable API contracts in `docs/UI_API_CONTRACTS.md`.

## A. Safe for UI modification

React visual components, page layouts, CSS/Tailwind classes, icons, typography, spacing, responsive behavior, labels, and visual feedback are safe areas. Accessibility improvements such as labels, meaningful button text, keyboard usability, and understandable errors are encouraged.

## B. Functionally sensitive

Modify auth hooks, the API client, router guards, scanner lifecycle, form submission handlers, and shared frontend state only while preserving session cookies, CSRF headers, request/response shapes, loading/error behavior, and authentication-expiry handling. The scanner must pause during submission, send the opaque `qr_payload` and optional UUID `scan_request_id`, show the server result, and resume automatically.

## C. Backend / do not modify for UI work

Do not change migrations, models, policies, middleware, controllers, request validation, services, tenant scoping, schedule resolution, QR generation/resolution, `ProcessAttendanceScan`, duplicate suppression, idempotency, database locking, Present/Late calculation, `BuildDailyAttendanceReport`, report semantics, or API contracts to accommodate a visual redesign.

## Core V1 boundaries

The authenticated user's `organization_id` is the only tenant authority. Person numbers remain strings. A schedule may contain ordered periods and a person may have one IN/OUT pair per configured period for an organization-local attendance date. Attendance events are historical records; there is no manual correction or generic attendance CRUD endpoint. Future UI work must not add SMS, billing, offline sync, payroll, leave/holiday engines, exports, or PWA behavior.

After UI changes, run the complete backend test suite, Pint, and the production frontend build.
