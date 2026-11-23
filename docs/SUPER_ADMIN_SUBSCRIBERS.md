# Super Admin subscriber management backend

This phase adds operational backend support for platform-level Super Admins to manage organizations, manually recorded GCash payments, and SMS feature entitlements. It does not implement SMS templates, Semaphore, sending, plans, credits, or automated payment verification.

## Authorization and security

Super Admin access requires an authenticated, verified user whose role is super_admin and whose organization_id is null. The super_admin middleware is applied server-side to every route in this namespace. Tenant roles remain organization-scoped and cannot access these endpoints.

## Routes

All routes are JSON endpoints under /api/super-admin:

- GET /dashboard
- GET /organizations?search=&filter=&per_page=
- GET /organizations/{organization}
- GET /payments?organization_id=&from=&to=&reference=&per_page=
- POST /organizations/{organization}/payments
- POST /organizations/{organization}/sms/enable
- POST /organizations/{organization}/sms/extend
- POST /organizations/{organization}/sms/disable

The organization list includes owner, user/people counts, and computed SMS status. Details include the entitlement and payment history. Payment and organization lists are paginated server-side and use eager loading for row relationships.

## Payments

The payments table records money received; it does not grant access. Current payments use payment_method=gcash and currency=PHP. Amounts are accepted as a decimal input but stored as integer PHP minor units in amount_minor. GCash references are strings and preserve leading zeroes/alphanumeric values. Coverage dates are optional and are not inferred from paid_at. recorded_by is always taken from the authenticated Super Admin.

The intended workflow is: record payment, then explicitly enable or extend SMS. There is no gateway/API integration, uniqueness requirement, or automatic activation.

## Entitlements

The feature_entitlements table is the generalized organization/feature record. SMS uses the stable key sms. It stores inclusive start/end dates, enabled state, the enabling actor, and disabling actor/time. Payment and entitlement records are separate.

FeatureEntitlementService::canUseSms($organization) is the one authoritative future SMS access check. It returns true only when an SMS entitlement exists, is enabled, its start date is reached, and its expiration date has not passed. Start and expiration dates are inclusive calendar dates, so access is valid throughout the expiration day and becomes unavailable the following day. No scheduled job is required for security.

Enabling replaces/upserts the SMS entitlement. Extending changes only its expiration date and re-enables it. Disabling leaves attendance/core tenant data untouched and records who/when disabled it.

## Future SMS contract

Before exposing SMS settings or sending, call FeatureEntitlementService::canUseSms($organization). Future SMS code must not duplicate date, enabled-state, payment, or subscription checks.

## Current-day attendance deletion

Super Admins can permanently delete the current organization-local day's attendance from an organization detail drawer. The action deletes `attendance_events` and same-day `attendance_scan_idempotencies` within a transaction. Because `sms_messages.attendance_event_id` uses cascade-on-delete, queued or historical SMS rows linked to those attendance events are deleted too. Organizations, persons, groups, schedules, templates, and other days remain unchanged.
