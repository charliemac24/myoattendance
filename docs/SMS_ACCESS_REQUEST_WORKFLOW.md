# SMS access request workflow

This phase adds a request-and-review workflow on top of the existing organization, payment, and feature entitlement architecture. It simulates a successful GCash payment; it does not call GCash, Semaphore, send SMS, manage templates, plans, or credits.

## Lifecycle and authorization

An organization owner or admin can submit a request for the sms feature. The organization is always taken from the authenticated tenant user; no organization ID is accepted from tenant input. Supervisor and scanner roles cannot request paid features.

Requests have pending, approved, and rejected states. A pending request is idempotent: repeated submissions return the same request rather than creating duplicates. A valid active entitlement returns a conflict instead of creating a request. Expired access and rejected history can be requested again; historical records are retained.

Tenant state is available at GET /api/organization/sms/access:

    { "sms": { "access": "inactive|active|expired", "starts_at": "...", "expires_at": "...", "request_status": "pending|approved|rejected|null", "requested_at": "...", "request_reason": "..." } }

The tenant may create a request with POST /api/organization/sms/access/request. It cannot approve, reject, record payments, or modify entitlements.

## Super Admin review

Super Admin endpoints are protected by the existing authenticated, verified super_admin middleware:

- GET /api/super-admin/sms-requests?status=pending
- GET /api/super-admin/sms-requests/{featureRequest}
- POST /api/super-admin/sms-requests/{featureRequest}/approve
- POST /api/super-admin/sms-requests/{featureRequest}/reject

Approval accepts amount, GCash reference, payment date, access start/expiration, optional coverage dates, and notes. It manually confirms payment and performs one transaction: lock/revalidate the pending request, create the PHP GCash payment, enable the existing SMS entitlement, and mark the request approved with reviewer and timestamp. Any failure rolls back all related writes. Repeated approvals return a conflict and cannot create duplicate payments.

Rejection requires a short reason. It records reviewer, timestamp, and reason; it creates no payment and grants no entitlement. A later tenant request creates a new pending record.

Entitlement dates retain the existing inclusive calendar-date rule: access is valid through the expiration date and unavailable the following day. A future start can be approved and paid while FeatureEntitlementService::canUseSms($organization) remains false until that date.

## Future SMS contract

The SMS Template Manager should call FeatureEntitlementService::canUseSms($organization) for permission and may use the tenant state endpoint for display. It must not duplicate request, payment, start-date, or expiration logic.

## Template Manager

The tenant UI is available at /sms/templates. It reads GET /api/organization/sms/templates and updates a template with PUT /api/organization/sms/templates/{template}. The backend creates the four initial organization-local templates (time_in, time_out, late, absent) on first authorized access. Only owner and admin roles can edit; all reads and writes require currently valid SMS entitlement. Supported display variables are {{name}}, {{time}}, and {{date}}. This phase stores and previews templates only; it does not send messages or integrate Semaphore.
