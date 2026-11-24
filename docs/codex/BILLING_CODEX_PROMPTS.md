# Billing: Codex prompts

These prompts are ready to paste into Codex, one at a time, from the root of this repository. Each one is self-contained. Run them in order: later prompts assume earlier tests exist. Every prompt tells Codex never to call the real Semaphore SMS API.

Many of these cases are already covered in `tests/Feature/BillingPackagesTest.php`, `tests/Unit/BillTypeTest.php`, and `tests/Unit/BillingPeriodTest.php`. Codex should extend those files rather than duplicate them.

---

## 0. Shared context (paste first in any new Codex session)

```
You are working in a Laravel 12 / PHP 8.4 app (MYO Attendance) with a Blade + vanilla JS billing page (resources/js/billing.js) and a React super-admin page (resources/js/pages/SuperAdminPage.js). Tests use PHPUnit with SQLite in memory: run them with `php artisan test --compact <path or --filter>`. Format PHP with `vendor/bin/pint --dirty --format agent`.

Billing rules (source of truth: docs/BILLING_AND_SUBSCRIPTIONS.md):
- There are exactly two plans: Base (bill_type `base`) and Base + SMS (bill_type `package`). There is no SMS-only plan; GET /api/billing?type=sms must be a validation error.
- A school picks a plan and a length in months (Bill::MONTH_OPTIONS = 1, 3, 6, 12). Bill lines have quantity = months; total = months x (PHP 2,999 [+ agreed SMS monthly price]). No discounts.
- App\Services\BillingService::currentBill($organization, $type, $months) returns the payable bill for the next period. The period starts at the later of trial end, paid subscription end, and today (organization timezone); BillingService::periodEnd($start, $months) is start + months - 1 day. Base + SMS needs an active sms_pricing_agreements row; otherwise it returns 422 'SMS pricing has not been set...'.
- Picking a different plan or length, or another payment moving the paid-through date, cancels the old unpaid bill (status cancelled).
- Tenant owner/admin submits POST /api/billing/payments (amount, bill_id, reference_number, paid_at, optional notes, payment_time, proof). The receipt is optional. Amount must equal the bill total. Only one payment may be under review at a time, because both plans include Base.
- Super admin approves via POST /api/super-admin/billing/payments/{payment}/approve. Both plans call BaseSubscriptionService::activateOneMonth(..., months: $bill->months). Base + SMS also calls FeatureEntitlementService::coverSmsThrough so SMS expires_at equals the base paid-through date, keeps an already-active SMS start date, never shortens SMS, and approves the pending SMS access request.
- Adding SMS = pay Base + SMS (SMS turns on only after approval). Removing SMS = pay Base next time; SMS stays on until its paid period ends. No refunds or credits.
- Reject: POST .../reject (reason required) returns the bill to unpaid. Request new proof: POST .../request-proof. The tenant answers with POST /api/billing/payments/{payment}/corrected-proof or .../proof-unavailable.

Hard rules: never send real SMS or call the Semaphore API (use Http::fake() if anything could reach it); never change dependencies; follow the existing style in tests/Feature/BillingPackagesTest.php; use travelTo() for any date-dependent assertion; keep tests deterministic.
```

---

## 1. Unit tests: plans and period math

```
Extend tests/Unit/BillTypeTest.php and tests/Unit/BillingPeriodTest.php. Cover:
- Bill::TYPES is exactly [base, package] and Bill::MONTH_OPTIONS is [1, 3, 6, 12].
- Bill::featuresFor and Bill::overlappingTypes for both plans; 'sms' and unknown plans throw InvalidArgumentException.
- BillingService::periodEnd for 1, 3, 6, and 12 months; month-end starts (Jan 31 -> Feb 27 in a non-leap year, Jan 31 2028 -> Feb 28 2028); Aug 31 + 6 months; Dec 31 crossing the year boundary.
Do not touch the database in these tests. Run them and make sure they pass.
```

## 2. Feature tests: plans and lengths

```
Extend tests/Feature/BillingPackagesTest.php for:
1. GET /api/billing?type=base and ?type=package return only the expected line items, with quantity = months and the right total_minor for months 1, 3, 6, and 12.
2. GET /api/billing with no type defaults to the school's current plan (Base + SMS while SMS is on or scheduled, or once a requested SMS price is ready; otherwise Base), and keeps the plan and length of a bill that has a payment under review.
3. available_types is {base:true, package:false} without pricing and {base:true, package:true} with pricing, including for an expired school. month_options is [1, 3, 6, 12].
4. type=sms, type=premium, and months=2 / months=24 are validation errors (422 with errors.type or errors.months).
5. The legacy include_sms=1 / include_sms=0 query parameter still maps to package / base.
6. Switching from 1 to 12 months, or from base to package, cancels the old unpaid bill. Switching back reopens or recreates a bill for the same period without violating the unique index.
7. A new SMS price updates an UNPAID Base + SMS bill (for every month in a multi-month bill) but never a payment_pending or paid bill.
8. current_plan reports label/status/base_paid_through/sms.state/pending_payment correctly for: a trial school, a school that requested SMS (requested vs price_ready), a pending Base + SMS payment (plan unchanged until approval), after approval (Base + SMS, sms on), and after the SMS period ends following a downgrade (Base, sms off).
9. plan_change.kind is add_sms, remove_sms, or renew with the exact message for each, and GET /api/billing with no type defaults to the current plan.
10. GET /api/super-admin/organizations rows include current_plan, and GET /api/super-admin/billing/payments/{id} includes current_plan and plan_change.
Run only this file and make it pass.
```

## 2b. Unit and feature tests: Start SMS now

```
Extend tests/Unit/SmsProrationTest.php and tests/Feature/BillingPackagesTest.php for the "Start SMS now" one-time charge (bill_type sms_now; see docs/BILLING_AND_SUBSCRIPTIONS.md, section "Start SMS now"):
- BillingService::proratedSmsCharge: 25 of 30 days, exactly one month left, one month + partial days, the last day, 0 days left, month-end and leap-year periods, and rounding to the nearest centavo (price 49900 with 1 of 31 days = 1610).
- The offer: available only for a running paid Base period with an SMS price and SMS off; reasons not_paid_base (trial, expired), no_price, sms_already_on, and too_few_days (fewer than BillingService::SMS_NOW_MIN_DAYS left). GET ?type=sms_now returns 422 with the exact message for each unavailable case.
- The bill: one sms_addon line described "SMS Notifications for the rest of this period (N days)", total = the prorated amount, rebuilt the next day with a new amount; paying yesterday's bill returns 422 "This SMS amount was for an earlier day...".
- Approval: SMS starts today and ends on the Base end date, Base is unchanged, coverage dates are stored, Http::assertNothingSent(). A later Base + SMS renewal keeps the SMS start date and moves SMS and Base to the same end date.
- Blocking: a pending sms_now payment blocks Base + SMS and a second sms_now payment; a pending Base payment does not block sms_now.
- Price changes: the SMS price in effect today is used.
- Rejection: latest_payment shows the rejected sms_now payment, and the school can pay again the same day.
- Super admin GET /api/super-admin/billing/payments/{id} returns plan_change.kind 'sms_now' with the admin wording.
```

## 3. Feature tests: payment submission (including the school's failure cases)

```
Add or extend feature tests for POST /api/billing/payments:
- No proof file -> 201, status pending, proof_path null.
- JPG, PNG, and PDF receipts under 5 MB are accepted and stored under payment-proofs/.
- A 6 MB file -> 422 errors.proof[0] = 'The receipt must be 5 MB or smaller.'
- HEIC, WEBP, and GIF -> 422 'The receipt must be a JPG, PNG, or PDF file.'
- A failed PHP upload (UPLOAD_ERR_INI_SIZE, test mode) -> 'The receipt could not be uploaded...'.
- CONTENT_LENGTH above post_max_size -> JSON 413 'upload is too large'.
- Amount different from the bill total -> 422, no payment row. '10494', '10494.0', and '10494.00' are accepted for a 3-month Base + SMS bill (sms price 499).
- A second payment on the same bill while one is pending -> 422 'already under review'. A pending Base payment blocks a Base + SMS payment and vice versa.
- Paying a paid bill -> 422 'already been paid. Refresh the page...'; paying a cancelled bill -> 422 'no longer current'.
- A GCash reference that is pending or approved anywhere is refused; a reference from a rejected payment can be reused.
- Another organization's bill_id -> 404; scanner/supervisor roles -> 403.
```

## 4. Feature tests: super admin approval, rejection, and proof requests

```
Add or extend feature tests for the super admin endpoints:
- Approving Base during a trial starts coverage at trial end; while active it extends from subscription_expires_at; while expired it starts now. A 3-month bill extends 3 calendar months.
- Approving Base never creates or changes the SMS feature_entitlements row.
- Approving Base + SMS sets SMS starts_at to the base coverage start and expires_at to the base paid-through date, for 1 and 12 months.
- Renewing Base + SMS while SMS is active keeps the original SMS starts_at and moves expires_at to the new base paid-through date.
- activation_mode 'immediate' starts base and SMS today during a trial; 'after_trial' starts both at trial end. The pending OrganizationFeatureRequest becomes approved.
- Approving twice -> 409, nothing extended twice. Approving when amount_minor != bill total -> 409.
- Reject requires a reason, sets the bill back to unpaid, keeps the rejected payment unchanged, and lets the owner pay the same bill again.
- request-proof -> proof_required; corrected-proof and proof-unavailable -> pending; reject works from proof_required; approving a proof_required payment -> 409.
- A legacy organization with no base_subscriptions row can be approved.
- A non-super-admin calling approve/reject -> 403.
Use Http::fake() and Http::assertNothingSent() in every SMS-bearing approval test.
```

## 5. Feature tests: moving between plans over time

```
Write feature tests with travelTo() that walk through real school scenarios:
- Base school adds SMS: the Base + SMS payment is pending -> canUseSms is false; after approval SMS starts with the next base period.
- Base + SMS school downgrades: it pays Base for the next period. canUseSms stays true through the last day of the Base + SMS period and is false the day after, while canUseScanner stays true.
- Base + SMS school pays 12 months, then later pays Base for 1 month: SMS ends after the 12 months and Base continues one more month.
- Pay early twice in a row: base ends up paid two periods ahead; bills never overlap (each start = previous end + 1 day).
- An expired school renews with Base + SMS directly.
- Organization timezone Asia/Manila near midnight UTC: billing_period_start uses the Manila calendar date.
```

## 6. Migration tests

```
Add tests for the two billing migrations:
- 2026_11_08_222240_add_bill_type_to_bills_table: bills with an sms_addon item become 'package', others 'base'; the unique index bills_type_period_unique replaces bills_period_unique; down() reverses it. (One test for this already exists; keep it passing.)
- 2026_11_09_014929_add_months_to_bills_table: existing bills get months = 1; unpaid or cancelled bills with bill_type 'sms' become cancelled; paid 'sms' bills are left alone; down() drops the column.
Call each migration's up()/down() directly after shaping the table, then leave the schema as the app expects.
```

## 7. Browser (end-to-end) checks with Playwright

```
Without adding packages to package.json, write a standalone Playwright script under scratch/ (not committed) that runs against http://localhost:8000, logs in as the seeded owner, and on /billing:
1. Sees exactly two plan buttons (Base, Base + SMS) and four length buttons (1, 3, 6, 12 months). With no SMS price, Base + SMS is greyed out and clicking it shows the 'Request SMS Notifications below' hint.
2. With an SMS price, picks Base + SMS and 3 months and checks the 'You are paying for: Base + SMS, 3 months (...)' title and the amount (3 x (2,999 + SMS price)).
3. Submits with NO receipt and expects 'Payment Submitted Successfully' and the button changing to 'Payment Under Review'. Switching to Base still shows 'Payment Under Review'.
4. Attaches a generated 3 MB JPEG and a 2.6 MB PNG screenshot (on a fresh bill) and checks the server received a file under 2 MB.
5. Clears cookies, submits, and expects 'Your session expired. Refresh the page and try again.'
Then log in as a super admin, approve the payment in /super-admin -> Payments, check that the "Payment for" column reads 'Base + SMS · 3 months', and confirm the school's billing page shows 'You're all set' with Base and SMS paid through the same date.
Ask me before starting Docker or seeding data.
```

## 8. Production upload check (no code changes)

```
Inspect the deployment configuration in this repo (Dockerfile, any nginx/apache/php.ini files, deployment docs) and tell me the effective upload_max_filesize, post_max_size, and nginx client_max_body_size for production. The app needs at least upload_max_filesize=6M and post_max_size=8M, and client_max_body_size of at least 8m. Do not change anything: list exactly which file and line to change, and how to verify on the server with `php -i | grep -E "upload_max_filesize|post_max_size"`.
```

## 9. Review prompt (after Codex writes tests)

```
Review the billing tests against docs/BILLING_AND_SUBSCRIPTIONS.md. For every rule in that document, name the test that covers it or say that it is missing. Remove any test that duplicates another or only checks framework behavior. Run `php artisan test --compact` and report the results. tests/Feature/ExampleTest.php was already failing before this work, because GET / redirects to /login; mention it, but do not fix it unless I ask.
```
