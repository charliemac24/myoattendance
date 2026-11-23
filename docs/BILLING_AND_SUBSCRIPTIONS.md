# Billing and subscriptions

## Base plan

New organizations receive a 30-day free trial. The MYO Attendance base subscription is **₱2,999 PHP per month** after the trial. Trial access ends at the exact `trial_ends_at` instant; paid access uses calendar-month arithmetic rather than a fixed 30-day period.

Legacy organizations are backfilled with active base access so existing people, attendance history, QR tokens, SMS messages, and SMS entitlements are not changed by the billing migration.

## Payment workflow

Owner/Admin users submit a GCash amount, reference number, payment date, optional note, and private proof file. New submissions are `pending` and do not activate access. Super Admin reviews the organization, payment purpose, amount, reference, submitted-by information, proof, and current subscription state.

There are two plans: **Base** (`bill_type = base`) and **Base + SMS** (`bill_type = package`). There is no SMS-only plan, because SMS texts are only sent when a scan is recorded and scanning requires Base. A school picks a plan and a length of 1, 3, 6, or 12 months. Each bill line has `quantity` = months, so a 3-month Base + SMS bill is 3 × (₱2,999 + agreed SMS price). There is no multi-month or package discount. Base + SMS is only offered once Super Admin has set an SMS price for the school (through the SMS access request flow). Every payment is submitted against a specific bill, and the submitted amount must equal the bill total.

Each bill covers the period after the base paid-through date: it starts at the later of the trial end, the current paid subscription end, and today, and runs for the chosen number of calendar months (inclusive end date). After a bill is paid, the next bill is for the following period, which is how paying early works. When the school picks another plan or length, or another payment moves the paid-through date, any unpaid bill for the old period or length is marked `cancelled` and replaced. Because both plans include Base, only one payment can be under review at a time.

Approval effects: both plans extend the base subscription by the bill's months. Base + SMS also turns SMS on for exactly the same period (SMS `expires_at` = the base paid-through date); SMS access that is already active keeps its start date and is only ever lengthened. Pending SMS access requests are approved when a Base + SMS bill is approved, and the request's activation mode still decides whether paid coverage starts after the trial or immediately. Organizations without a subscription row (legacy) get one on their first approved payment.

Moving between plans needs no request or admin action:
- **Adding SMS:** request SMS, wait for the price, then pay Base + SMS. SMS turns on only when the payment is approved, and starts with the next base period (or immediately during a trial when the school chose to start its paid plan "Right away").
- **Removing SMS:** pay Base at the next renewal. SMS stays on until the end of the Base + SMS period already paid for, then stops. No refunds or prorated credits are calculated.

The Billing page separates what the school has from what it is paying for. **Your current plan** (`current_plan` in `GET /api/billing`, from `BillingService::currentPlan`) shows the plan name (Free trial, Base, Base + SMS, or Expired), status, the Base paid-through date, the SMS state (on, scheduled, off, requested, or price ready) with its end date, and any payment under review. A payment under review does not change the current plan until it is approved. **Renew or change your plan** defaults to the current plan (or to Base + SMS once a requested SMS price is ready), marks the current plan, and shows `plan_change`, a one-line summary of what the payment does (adds SMS, removes SMS after a date, or renews). Super Admin sees the same current plan in the Organizations list and on each payment review, under "What approving this does".

## SMS price estimate

Schools can answer three optional questions, at registration or on the Billing page under Base + SMS: how many people will receive SMS, which texts (Time In, Time Out, Late; Late replaces Time In), and which days (weekdays, Mon–Sat, every day = 22, 26, 30 days a month). `SmsPriceEstimator` turns them into a monthly price: people × texts per day × days × price per text, rounded up to the nearest step. The price per text and the rounding step are settings (`SMS_ESTIMATE_PRICE_PER_TEXT`, default ₱0.50; `SMS_ESTIMATE_ROUND_TO`, default ₱50) and are placeholders until the business sets them. Saving answers creates an SMS price with source `estimate`, which unlocks Base + SMS right away. A price set by Super Admin (source `admin`) always wins: later answers are saved but do not replace it. Super Admin sees the school's numbers next to an estimated price in Organizations and on the payment review. Schools that skip the questions can still use "Request SMS" for a custom price.

## Trial start choice

A school still on its unpaid free trial chooses when its paid plan starts: "Start after my free trial" (`after_trial`) or "Start right away" (`immediate`). The choice is stored on its pending SMS request and can be changed until approval. Base and Base + SMS bills start on the trial end date for `after_trial`, or today for `immediate` (`BillingService::nextPeriodStart`). Changing the choice re-dates any open or under-review bill without changing its amount. Approval follows the bill: a bill dated today during the trial starts paid coverage now and ends the trial early; a bill dated at the trial end starts then. The school's billing page and the admin payment review both state which choice applies.

## Start SMS now (one-time SMS charge)

A school on a paid Base period with an agreed SMS price and SMS off can start SMS today instead of waiting for its next Base + SMS period. This is a one-time bill (`bill_type = sms_now`), not a plan. Its amount is `BillingService::proratedSmsCharge`: whole calendar months left are charged at the monthly SMS price, and the remaining days are charged as a share of the calendar month they fall in, rounded to the nearest centavo (for example ₱1,000/month with 25 of 30 days left = ₱833.33). The price used is the SMS price in effect today.

The offer (`sms_now` in `GET /api/billing`) is shown only when: the paid Base period has started and not ended, an SMS price is set, SMS is not on or scheduled, and at least `BillingService::SMS_NOW_MIN_DAYS` (3) days are left. Trial and expired schools use their own flows instead. The bill is rebuilt each day so the amount always matches the days left; a payment submitted against an earlier day's bill is refused with "This SMS amount was for an earlier day…".

Approving an `sms_now` payment turns SMS on from the approval day (or the bill's first day, if later) until the day the Base period ends, and does not change the Base subscription. Renewing with Base + SMS afterwards keeps the SMS start date and extends SMS with Base, so both stay on the same dates. An `sms_now` payment under review blocks a Base + SMS payment and vice versa; a Base payment under review does not block it. A rejected `sms_now` payment shows in the "Payment not accepted" banner, and the school can pay again the same day.

## Receipt uploads

Receipts are optional. The Billing page converts HEIC/WEBP images and shrinks images above 1.5 MB to a JPEG of at most 1600 px before upload, so typical phone photos and screenshots arrive well under the 5 MB server limit. The server still validates JPG/PNG/PDF up to 5 MB with plain-language errors, and an oversized request body returns a JSON 413 message instead of a CSRF/`request could not be completed` error. The Docker image raises PHP's `upload_max_filesize` to 10M and `post_max_size` to 12M; production PHP (and nginx `client_max_body_size`, default 1M) must allow at least 6 MB.

A bill can have many immutable payment attempts. A rejected attempt remains in payment history with its original amount, reference, proof, reviewer, review time, and rejection reason; it never gets edited or changed back to pending. Rejection returns an unpaid bill to a payable state, allowing the owner to submit a new attempt against the same bill. The tenant Billing page shows the latest rejection and a `Submit Payment Again` action, while a pending attempt shows `Payment Under Review` and blocks another submission.

The same GCash reference may be reused after rejection when no pending or approved payment is using it. References belonging to pending or approved payments are blocked to prevent duplicate active submissions or settling another bill with the same transaction. A paid bill cannot accept another normal payment attempt.

Payment proof is optional. Amount, GCash reference, and payment date are required; approximate payment time and notes are optional. A payment may be submitted without an image and remains `pending` for manual verification.

When evidence needs correction, Super Admin uses `Request New Proof`, which changes the payment to `proof_required` while leaving the bill `payment_pending`. The owner can upload corrected evidence for the same payment, or confirm that no proof image is available; either response returns the payment to `pending`. Proof corrections do not create a new financial payment or alter the amount, date, or GCash reference. Original and corrected proof paths are preserved privately in payment evidence history.

`proof_required` is distinct from `rejected`: it means the transaction may be valid and must not prompt the owner to pay again. Final rejection still requires a rejection reason and returns the bill to `unpaid`. Super Admin can approve a pending payment without an image after independently verifying the reference and payment details.

SMS pricing is set by Super Admin from the organization detail view. A new agreement supersedes the prior active agreement from its effective date; existing bills and bill items are never repriced. This keeps historical charges auditable while allowing future pricing changes.

For legacy base payments, the standard expected amount is ₱2,999. A mismatch is shown as a warning but does not force rejection. For bill-linked payments, approval requires the declared amount to equal the immutable bill total. Super Admin approval is manual and transactional. The payment is locked, changed to `approved`, stamped with reviewer/time, and the base subscription is activated or extended in the same transaction. If the bill contains an SMS line, SMS access is enabled for the same coverage period. The resulting `coverage_start` and `coverage_end` are stored on the payment for traceability, and the bill is marked `paid`.

Approval is idempotent: only pending base or bill payments can be approved, so a retry cannot grant a second month. Rejection returns a bill to `unpaid` and preserves the rejected payment attempt. A payment during a valid trial starts paid coverage after the trial boundary; a payment during active paid access extends from the existing paid-through date; an expired organization starts a new month at approval time. Calendar-month arithmetic handles month-end dates through Carbon.

When a later attempt is approved, the earlier rejected attempts remain unchanged. The bill is settled once, and base/SMS entitlement effects are applied once. Payment history is newest-first and keeps current-bill information visually distinct from historical attempts.

Rejection requires a reason. The payment remains in history with `rejected`, reviewer, review time, and rejection reason. It does not modify the subscription or SMS entitlement.

## Access and security

`BaseSubscriptionService` is the authority for subscription status and `canUseScanner`. Scanner access is enforced server-side. Approval restores scanner capability naturally through subscription state; it does not set a separate unlock flag.

Only Super Admin can approve or reject payments. Tenant Owner/Admin users can submit and view their own organization’s payments. Proof files remain on private storage and are served only through authenticated, organization-scoped or Super Admin-authorized endpoints. Organization IDs are derived server-side.

## SMS separation

SMS Notifications remain an optional paid add-on with separately negotiated pricing. Approving a ₱2,999 base payment does not enable, extend, approve, or otherwise modify SMS entitlements, sender names, Semaphore settings, or SMS credits.

The system does not implement automatic billing, GCash APIs, payment gateways, auto-charge, OCR, invoices, coupons, or tax calculations.
