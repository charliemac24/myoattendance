# Billing end to end: Codex test prompt

Paste the block below into Codex from the root of the MYOattendanceV2 repo. It tells Codex to test only and not change app code. It replaces `testing/TRIAL_SMS_CHOICE_CODEX_PROMPT.md` (everything in that file is covered here).

## What the code does today (checked before writing this prompt)

- **Plans:** Base (`bill_type = base`, ₱2,999/month) and Base + SMS (`package`, ₱2,999 + the school's SMS price). There is also a one-time "Start SMS now" bill (`sms_now`) for paid Base schools. Lengths are 1, 3, 6 or 12 months. Every payment is made against a bill, and the amount must equal the bill total.
- **Trial start choice:** a school on its unpaid free trial answers "When should your paid plan start?" with "After my free trial ends" (`after_trial`, the default) or "Right away" (`immediate`). The choice is saved on its own via `POST /api/billing/start-choice {choice}` and stored in `base_subscriptions.start_choice`. It applies to Base and to Base + SMS. **Request SMS** is a separate button; the SMS request follows the same choice. Changing the choice re-dates any open or under-review bill; the amount does not change.
- **SMS price estimate (new):** schools answer 3 optional questions: people to text, which texts (time_in, time_out, late, where Late replaces Time In), and which days (weekdays = 22, mon_sat = 26, every_day = 30 days/month). They answer at registration (`sms_estimate_people`, `sms_estimate_texts[]`, `sms_estimate_days`) or on Billing (`POST /api/billing/sms-estimate {people, texts[], days}`).
  - **Formula:** `App\Services\SmsPriceEstimator`: people × texts/day × days × price per text, rounded UP to the step. Settings come from `config('services.sms_estimate')` (`SMS_ESTIMATE_PRICE_PER_TEXT`, default 0.50; `SMS_ESTIMATE_ROUND_TO`, default 50). Example: 350 people, Time In + Time Out, weekdays = ₱7,700/month.
  - **Price record:** saving answers creates an SMS price with `source = estimate`, which unlocks Base + SMS.
  - **Admin always wins:** a price set by Super Admin (`source = admin`) is never replaced by the school's answers; the answers are still saved.
  - **API fields:** `GET /api/billing` returns `sms_estimate {people, texts, days, amount_minor, price_source, settings}`. The admin payment review returns `sms_price_source` and `sms_estimate {people, texts_per_day, days}`.
  - **Billing page:** Base + SMS is always selectable. Picking it without a price shows "Get your SMS price" with the 3 questions, a live estimate that is saved automatically (no button) and a "Prefer a custom price? Request SMS instead" link. The payment amount shows "—" and submit is disabled until a price exists.
- **Bill start dates:** `after_trial` or no choice means the trial end date. `immediate` means today (Asia/Manila). For a paid school, the bill starts at the paid-through date. For an expired school, it starts today.
- **Approval** (`POST /api/super-admin/billing/payments/{id}/approve`) follows the bill. If the bill starts today, paid coverage starts now (a trial ends early). If it starts in the future, coverage starts then. Base + SMS turns SMS on for exactly the same dates and approves the SMS request. Base never turns SMS on. Approving twice returns 409.
- **Admin actions:**
  - **Reject** (`.../reject {reason}`, reason required): the bill goes back to unpaid, the school sees "Payment not accepted", and it can pay again; a rejected GCash reference can be reused.
  - **Request new proof** (`.../request-proof {reason}`): the payment becomes `proof_required`. The school answers with `POST /api/billing/payments/{id}/corrected-proof` (file) or `.../proof-unavailable`, and either returns it to `pending`. Approving while `proof_required` returns 409.
  - **Set or change SMS price** (`PUT /api/super-admin/organizations/{id}/sms-pricing {sms_monthly_price, effective_from?}`): this unlocks Base + SMS. It never changes a paid bill or a payment under review.
  - **Payment review data:** `GET /api/super-admin/billing/payments/{id}` returns `current_plan`, `plan_change` (admin wording "the school's…"), `sms_activation_request` and `sms_price_set`.
- **School guards:** these return 422 with plain messages:
  - wrong amount: "The amount must match the bill total…"
  - a second payment while one is under review
  - a GCash reference that is pending or approved elsewhere
  - paying a paid or cancelled bill
  - receipts over 5 MB or not JPG/PNG/PDF
- **Expiry:** after the trial or paid period ends with no payment, the status is `expired`. `/scanner` redirects to `/billing`, and `POST /api/attendance/scan` returns 403 `SUBSCRIPTION_REQUIRED`. SMS stops after its end date. An expired school that last paid Base + SMS is offered Base + SMS by default.
- **Renewal:** this extends from the current paid-through date with no gap or overlap. Choosing Base when SMS is on keeps SMS until its paid end, then stops (no refund). Paid Base schools with an SMS price can use "Start SMS now", a prorated one-time charge until the Base end, offered only with 3 or more days left.
- **Seeded logins** (`database/seeders/LocalTestAccountsSeeder.php`, all password `password`): `trial` (Trial Demo School), `base` (Base Demo School), `admin` (MYO Demo Organization), `super` (Super Admin).
- **Reset a test school** (local Docker only):
  ```
  docker compose exec app php artisan tinker --execute "\$school='Trial Demo School'; \$state='trial'; require 'testing/reset_test_school.php';"
  ```
  The states are `trial`, `trial_ending`, `trial_ended`, `base_active`, `sms_active` (₱500 SMS price), `base_expired` and `sms_expired`.

**Expected result:** every case below passes. Any failure is a finding.

---

## Prompt

```
You are working in a Laravel 12 / PHP 8.4 app (MYO Attendance V2). Tests use PHPUnit with SQLite in memory: run them ON THE HOST with `php artisan test --compact <path or --filter>` (never inside Docker). Format PHP with `vendor/bin/pint --dirty --format agent`.

GOAL
Test the whole billing flow end to end, school side and Super Admin side, for 8 scenarios. DO NOT change application code (app/, resources/, routes/, database/migrations). Only add tests and report what you find.

Read first, and summarize each in one or two sentences:
- docs/BILLING_AND_SUBSCRIPTIONS.md
- App\Services\BillingService (currentPlan, trialStartChoice, setTrialStartChoice, nextPeriodStart, currentBill, describeChange, smsNowQuote)
- App\Services\BaseSubscriptionService (statusFor, canUseScanner, activateOneMonth)
- App\Services\FeatureEntitlementService (canUseSms, coverSmsThrough)
- App\Http\Controllers\BillingController (show, startChoice, submit, correctedProof, proofUnavailable)
- App\Http\Controllers\SuperAdminBillingController (show, approve, reject, requestProof)
- App\Http\Controllers\SuperAdminOrganizationController::updateSmsPricing
- App\Services\SmsAccessRequestService::request
- tests/Feature/BillingPackagesTest.php helpers owner(), superAdmin(), billFor(), pay(), approve(). Reuse this style.

Rules:
- $this->travelTo('2026-11-10 02:00:00') at the start (10:00 Asia/Manila). owner() gives a trial ending 2026-11-20; owner('active') is paid until 2026-12-05; owner('expired') expired yesterday. Pass smsPricing: false unless the case needs a price.
- Never send real SMS: Http::fake() at the start of every test, Http::assertNothingSent() at the end.
- Storage::fake('local') for receipts. Use UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf') (no GD needed).
- After any approval, call $owner->refresh() before reading plan state through HTTP.
- Assert exact dates (toDateString) and exact messages where this prompt gives them.

Create tests/Feature/BillingEndToEndTest.php with one test method per numbered step below (or one method per scenario with clear assertion messages). For every scenario also assert what the school sees (GET /api/billing: current_plan.label, current_plan.status, current_plan.sms.state, bill_type, current_bill dates and total, plan_change.message) and what Super Admin sees (GET /api/super-admin/billing/payments/{id}: plan_change.message, sms_activation_request, sms_price_set).

S0 SMS PRICE ESTIMATE (new)
 1. Register with no SMS answers -> no SMS price, sms_estimate.people null, available_types.package false.
 2. Register with sms_estimate_people 350, texts [time_in,time_out], days weekdays -> one active SMS price ₱7,700.00 with source 'estimate'; GET /api/billing?type=package total ₱10,699.00.
 3. Invalid answers (people 0, texts ['weekly'], days 'sundays') -> 422 validation errors on registration and on POST /api/billing/sms-estimate.
 4. On Billing, save answers, then save different answers (400 people) -> new estimate ₱8,800.00 replaces the old one (2 rows in history); saving the same answers again adds no row.
 5. Super Admin sets ₱5,000.00 -> source 'admin'. School saves new answers -> response applied false, message 'Saved. Your SMS price is set by MYO Attendance.', price stays ₱5,000.00.
 6. Pay Base + SMS at the estimated price; admin review shows sms_price_source 'estimate' and sms_estimate {people, texts_per_day, days}. Approving works like any Base + SMS payment.
 7. Change config services.sms_estimate.price_per_text to '0.75' and round_to to '100' -> 350 × 2 × 22 × ₱0.75 = ₱11,600.00 (rounded up).

S1 TRIAL USER (owner(), no price)
 1. GET /api/billing: label 'Free trial', status 'trialing', in_trial true, start_choice null, sms.state 'off', bill_type 'base', bill starts 2026-11-20, plan_change.message starts 'Starts paid Base on Nov 20, 2026, when your free trial ends'.
 2. GET /scanner is 200 and canUseScanner is true. GET /api/organization/sms/access: next_step 'choose_trial_timing', in_trial true.
 3. POST /api/billing/start-choice {choice:'immediate'} -> 200; bill now starts 2026-11-10. {choice:'after_trial'} -> back to 2026-11-20. {choice:'tomorrow'} -> 422 validation error.
 4. POST /api/organization/sms/access/request (no body) -> 201 with activation_mode = the saved start choice; next_step 'waiting_for_price'. Requesting again returns the same request (200).

S2 TRIAL ENDS WITH NO PAYMENT
 1. travelTo('2026-11-21 02:00:00'), refresh: status 'expired', label 'Expired', GET /scanner redirects to /billing, POST /api/attendance/scan returns 403 SUBSCRIPTION_REQUIRED (use a person with a schedule; see tests/Feature/PhaseThreeAttendanceTest.php).
 2. POST /api/billing/start-choice -> 422 'Only schools on their free trial can choose when their paid plan starts.' The base bill now starts today (2026-11-21), and plan_change has no "you chose" text.
 3. Pay Base and approve -> coverage starts at approval time and lasts one calendar month; scanner works again.

S3 BASE, START AFTER TRIAL (owner(), no price, start_choice after_trial)
 1. Bill: base, 2026-11-20 to 2026-12-19, ₱2,999.00; message 'Starts paid Base on Nov 20, 2026, when your free trial ends (you chose "After my free trial ends"). Covers Nov 20, 2026 to Dec 19, 2026 (1 month).'
 2. Submit payment without a receipt -> 201 pending; current_plan.pending_payment shows Base, 1 month; a second payment on the same bill -> 422 'A payment for this bill is already under review.'
 3. Admin review: plan_change.message = same sentence with "the school's free trial" / "the school chose".
 4. Request new proof (reason required; missing reason -> 422) -> status proof_required; approve now -> 409; school uploads corrected PDF -> pending.
 5. Approve -> subscription_starts_at = trial end (2026-11-20), expires 2026-12-20; still canUseScanner today; no SMS entitlement; approving again -> 409.

S4 BASE, START RIGHT AWAY (owner(), no price, start_choice immediate)
 1. Bill starts 2026-11-10; message 'Starts paid Base as soon as this payment is approved, which ends your free trial early (you chose "Right away"). Covers Nov 10, 2026 to Dec 9, 2026 (1 month).'
 2. Wrong amount ('2000.00') -> 422 'The amount must match the bill total of PHP 2,999.00. Refresh the page and try again.'; nothing saved.
 3. Pay correctly, then Admin REJECTS with reason 'Reference not found' (missing reason -> 422): bill back to unpaid, GET /api/billing latest_payment.status 'rejected' with the reason; the same GCash reference can be used again -> 201.
 4. Switch the choice to after_trial while the second payment is pending -> the same bill moves to 2026-11-20 (same total); switch back to immediate -> 2026-11-10.
 5. Approve -> subscription_starts_at = now (2026-11-10 02:00 UTC), expires 2026-12-10; label 'Base', status 'active'; trial is over (in_trial false); no SMS.

S5 BASE + SMS, START AFTER TRIAL (owner(), no price)
 1. Choose after_trial, Request SMS -> waiting_for_price; package not available (GET ?type=package -> 422 'SMS pricing has not been set…').
 2. Admin sets price ₱499 (PUT .../sms-pricing). Invalid price 'abc' -> 422; non-admin -> 403.
 3. School: available_types.package true, sms.state 'price_ready', default bill_type 'package', bill 2026-11-20..2026-12-19 total ₱3,498.00, message 'Adds SMS. SMS and paid Base start on Nov 20, 2026, when your free trial ends (you chose "After my free trial ends")…'.
 4. Admin changes price to ₱600 BEFORE the school pays -> unpaid package bill total becomes ₱3,599.00. Pay it. Admin changes price to ₱700 -> the pending bill stays ₱3,599.00.
 5. Approve -> Base and SMS both start 2026-11-20 and end 2026-12-20; canUseSms false today and true on 2026-11-21; SMS request status 'approved'.

S6 BASE + SMS, START RIGHT AWAY (owner(), no price)
 1. Choose immediate, Request SMS, admin sets ₱499.
 2. Package bill starts 2026-11-10; message contains 'SMS and paid Base start as soon as this payment is approved, which ends your free trial early (you chose "Right away")'.
 3. While it is pending, a Base payment -> 422 'Another payment covering the same plan is already under review…'.
 4. Approve -> Base and SMS start 2026-11-10 (Base at approval time), canUseSms true today, trial over, request approved. GET /api/billing: label 'Base + SMS', sms.state 'on'.

S7 PLAN EXPIRED (two sub-cases)
 a. Without SMS: owner('expired', smsPricing: false) -> label 'Expired', scanner blocked, available_types {base:true, package:false, sms_now:false}, bill starts today; pay Base and approve -> starts at approval time.
 b. With SMS: owner('active') paid Base + SMS (approve a package bill), then travel past both end dates -> label 'Expired', sms.state 'available', canUseSms false, default bill_type 'package' (last paid plan), plan_change kind 'add_sms'. Renew with Base + SMS -> both start at approval time and end together. Then repeat with Base only -> SMS stays off.

S8 RENEWAL (two sub-cases)
 a. Without SMS: owner('active', smsPricing: false). Pay Base early -> the next bill starts the day after the current period (2026-12-05); approve -> expires 2027-01-05. Pay 3 months -> expires 2027-04-05. A paid bill cannot be paid again (422 'This bill has already been paid…').
 b. With SMS: owner('active') pays Base + SMS (approve) -> SMS through the Base end. Renew Base + SMS -> both extend together and SMS keeps its start date. Then choose Base for the next period -> plan_change 'Removes SMS. SMS stays on until …'; after approval SMS ends on its paid date and Base continues; no refund (payments table unchanged except the new one).
 c. Start SMS now: owner('active') with a price, SMS off -> sms_now.available true, amount = price × days left / days in that month (₱499 with 25 of 30 days = ₱415.83); pay + approve -> SMS on today until the Base end; Base unchanged. With 2 days left -> not available ('too_few_days').

ADMIN COVERAGE CHECKLIST (each must be exercised at least once above; add a small test if not):
- approve, approve twice (409), approve with amount mismatch after a manual bill total change (409)
- reject with and without reason; resubmit after reject; reuse of a rejected GCash reference
- request new proof; corrected-proof upload; proof-unavailable; reject from proof_required
- set SMS price, change SMS price (unpaid bill updates, pending/paid bill does not), price history in GET /api/super-admin/organizations/{id} (organization.sms_pricing_history)
- non-super-admin cannot approve/reject/set price (403)
- payment list GET /api/super-admin/billing/payments shows the new payments with bill.bill_type and months

OPTIONAL BROWSER PASS (only if a browser is available; local Docker app at http://localhost:8000):
- Reset the schools with testing/reset_test_school.php (see the command at the top of this file).
- As trial/password on /billing (fresh trial, no answers): Base + SMS is selectable; picking it shows "Get your SMS price"; typing 350 shows "Estimated SMS price: ₱7,700.00/month"; after a moment it saves by itself ("✓ Saved") -> Base + SMS ₱10,699.00/month (estimated) and the payment form amount updates. Also confirm "When should your paid plan start?" with the two options, the selected one, the summary line changing when you switch, and Request SMS as a separate button.
- As super/password on /super-admin -> Payments -> Review: confirm the "On approval" box, the tag ("School wants to start now (ends trial early)" / "School wants to start after its trial" / "SMS requested · price not set"), and the Reject / Ask for new receipt / Approve buttons in one row.
- Do not approve or reject real data unless asked.

Run the new file and then the full suite. Report pass/fail per scenario step, the exact failing assertion and one-line likely cause for any failure. tests/Feature/ExampleTest.php was already failing (GET / redirects to /login); mention it but do not fix it.
```
