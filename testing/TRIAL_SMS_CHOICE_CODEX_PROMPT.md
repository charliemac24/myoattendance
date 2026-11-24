# Trial start choice: Codex test prompt

Paste the block below into Codex from the root of the MYOattendanceV2 repo. It tells Codex to test only and not change app code.

## What the code does today (checked before writing this prompt)

- **Where the choice lives:** a school on its unpaid free trial answers "When should your paid plan start?" on the Billing page (`/billing`, `resources/js/billing.js`, `renderStartChoice`). It has two radio options, "After my free trial ends" (`after_trial`, the default) and "Right away" (`immediate`), saved immediately via `POST /api/billing/start-choice {choice}` into `base_subscriptions.start_choice`. It applies to Base and to Base + SMS. **Request SMS** is a separate button; a pending SMS request's `activation_mode` follows the same choice. The school can change the choice until approval; changing it re-dates any open or under-review Base / Base + SMS bill (`BillingService::redateTrialBills`), and amounts do not change. Only schools on their unpaid trial can set it (otherwise 422).
- **How the choice affects bills** (`BillingService::nextPeriodStart`, `trialStartChoice`):
  - `immediate`: Base and Base + SMS bills start **today** (organization timezone, Asia/Manila).
  - `after_trial` (or no choice): bills start on the **trial end date**.
- **What the school and admin read:** `plan_change.message` in `GET /api/billing` (school) and `GET /api/super-admin/billing/payments/{id}` (admin, worded "the school's…"). The admin review also returns `sms_activation_request` and `sms_price_set` and shows an "SMS request" section.
- **Approval** (`SuperAdminBillingController::approve`): follows the bill the school paid. A bill dated today during the trial starts paid coverage **now** (the trial ends early). A bill dated at the trial end starts paid coverage **at the trial end**. A Base + SMS bill also turns SMS on for the same dates and approves the SMS request. A Base-only bill never turns SMS on.
- **SMS needs a price:** until Super Admin sets one (`PUT /api/super-admin/organizations/{id}/sms-pricing`), the school can only pay **Base**, so the choice then only affects when paid Base starts.
- **Seeded local accounts** (`database/seeders/LocalTestAccountsSeeder.php`, all password `password`): `trial` (Trial Demo School, on its free trial), `super` (Super Admin), `base` (paid Base school), `admin` (demo school).

**Expected result:** all checks below pass. If any fail, that is the finding.

---

## Prompt

```
You are working in a Laravel 12 / PHP 8.4 app (MYO Attendance). Tests use PHPUnit with SQLite in memory: run them with `php artisan test --compact <path or --filter>`. Format PHP with `vendor/bin/pint --dirty --format agent`. Run tests on the host (not inside Docker).

GOAL
Prove that a trial school's start choice ("After my free trial ends" = after_trial, "Right away" = immediate) is reflected everywhere and that Super Admin approval does exactly what was chosen. DO NOT change application code (app/, resources/, routes/, database/migrations). Only add tests and report what you find.

Read first, and summarize in one sentence each:
- App\Services\BillingService: trialStartChoice, setTrialStartChoice, nextPeriodStart, redateTrialBills, describeChange, currentPlan (start_choice, sms.requested_start, in_trial).
- App\Http\Controllers\BillingController::startChoice (POST /api/billing/start-choice).
- App\Services\SmsAccessRequestService::request (how a pending request's activation_mode is changed during the trial).
- App\Http\Controllers\SuperAdminBillingController::show and ::approve.
- tests/Feature/BillingPackagesTest.php helpers owner(), superAdmin(), billFor(), pay(), approve(). Reuse this style.

Rules:
- Use $this->travelTo('2026-11-10 02:00:00') (10:00 Asia/Manila). A trial owner from owner() has a trial ending 2026-11-20.
- Never send real SMS: call Http::fake() at the start of each test and Http::assertNothingSent() at the end.
- Create the school with owner(smsPricing: false), request SMS with the chosen activation_mode, and set a price only when the test needs Base + SMS: PUT /api/super-admin/organizations/{id}/sms-pricing {"sms_monthly_price":"499"}.

Create tests/Feature/TrialStartChoiceTest.php with these cases. Name each test after what it checks.

C1 choice_is_saved_and_shown: POST /api/billing/start-choice {choice: immediate} with NO SMS request -> GET /api/billing has current_plan.start_choice = immediate and the Base bill starts today. Then request SMS with no activation_mode -> the request's activation_mode is immediate. Switch to after_trial via start-choice -> the request's activation_mode and the bill follow. A paid (active) school gets 422 from start-choice; choice=tomorrow is a validation error.
C2 paid_school_cannot_pick_trial_timing: owner('active', smsPricing: false) requests with immediate -> activation_mode is next_payment, and changing it is ignored.

Right away (immediate):
C3 base_bill_starts_today: GET /api/billing?type=base -> current_bill.billing_period_start 2026-11-10, plan_change.message starts with 'Starts paid Base as soon as this payment is approved, which ends your free trial early (you chose "Right away")' and contains 'This payment is for Base only.'
C4 admin_sees_the_choice: pay that bill; GET /api/super-admin/billing/payments/{id} -> sms_activation_request.activation_mode = immediate, sms_price_set = false, plan_change.message contains "the school's free trial early (the school chose \"Right away\")".
C5 approval_starts_base_now: approve -> base_subscriptions.subscription_starts_at = 2026-11-10 02:00:00 UTC, subscription_expires_at = 2026-12-10, current plan label 'Base' and status 'active', no SMS entitlement, SMS request still pending.
C6 base_plus_sms_starts_today: with a price set (it may be set before or after the choice), GET /api/billing?type=package -> period starts 2026-11-10, message mentions 'SMS and paid Base start as soon as this payment is approved'. Pay + approve -> Base and SMS both start 2026-11-10, canUseSms true today, SMS request approved.

After my free trial ends (after_trial):
C7 base_bill_starts_after_trial: billing_period_start 2026-11-20, message starts with 'Starts paid Base on Nov 20, 2026, when your free trial ends (you chose "After my free trial ends")'.
C8 admin_sees_after_trial: admin review plan_change.message contains 'when the school's free trial ends' and sms_activation_request.activation_mode = after_trial.
C9 approval_waits_for_trial_end: approve the Base payment -> subscription_starts_at = trial end (2026-11-20), canUseScanner true today (still trial), no SMS entitlement.
C10 base_plus_sms_after_trial: with a price set, package bill starts 2026-11-20. Approve -> Base starts 2026-11-20, SMS entitlement starts 2026-11-20 and canUseSms is false today and true on 2026-11-21 (travelTo).

Changing the choice after paying:
C11 switch_after_paying_moves_the_bill: after_trial, pay Base (bill starts 11-20), then switch to immediate -> the same bill now starts 2026-11-10 with the same total; the admin review shows the "Start right away" wording; approval starts Base now. Then repeat the opposite way (immediate -> after_trial -> starts 11-20).
C12 choice_has_no_effect_after_trial: travel past the trial end (2026-11-21) with no payment; the choice no longer changes bill dates (bills start "today" because the school is expired, and plan_change has no "you chose" text).

Also check the page in a real browser if available (optional): log in at http://localhost:8000 as trial / password, open /billing, confirm "When should your paid plan start?" shows two radio options with the saved one selected, that switching updates the summary line, and that Request SMS is a separate button; log in as super / password, open Payments -> Review on the Trial Demo School payment, and confirm the "On approval" box and the tag ("School wants to start now (ends trial early)" or "School wants to start after its trial") match the choice. Do not approve or reject real data unless asked.

Run the new test file and the full suite. Report: pass/fail per case, the exact failing assertion for any failure, and one line on the likely cause. tests/Feature/ExampleTest.php was already failing (GET / redirects to /login); mention it but do not fix it.
```
