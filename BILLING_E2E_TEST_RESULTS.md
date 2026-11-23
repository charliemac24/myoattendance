# Billing E2E Test Handoff

## Test commands

```bash
php artisan test --compact tests/Feature/BillingEndToEndTest.php
php artisan test --compact
```

## New billing E2E test

`tests/Feature/BillingEndToEndTest.php` passes:

- 5 tests
- 58 assertions
- No application assertion failures

## Full-suite result

The full suite returned:

- 225 tests
- 187 passed
- 1 failed test
- 37 errors
- 1,129 assertions

## Failure to investigate

### `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response`

File: `tests/Feature/ExampleTest.php:17`

Failure:

```text
Expected response status code [200] but received 302.
Failed asserting that 302 is identical to 200.
```

The application redirects the request to `/login`. This was explicitly marked as a known failure and was not changed.

## Recurring errors to investigate

Most of the 37 errors are filesystem permission failures, not failed business assertions.

### Laravel log is not writable

```text
The stream or file "/Users/charlieanchetamacaraeg/Desktop/MYOattendanceV2/storage/logs/laravel.log" could not be opened in append mode: Failed to open stream: Operation not permitted
```

This occurred while tests were logging during billing, attendance/SMS, and subscription tests. Examples include:

- `tests/Feature/BaseSubscriptionTest.php:81`
- `tests/Feature/BaseSubscriptionTest.php:140`
- `tests/Feature/BillingPackagesTest.php:957`
- `tests/Feature/SmsOutboxTest.php`
- `tests/Feature/SmsProcessorTest.php`

### Fake local storage is not writable

```text
Unable to create a directory at "/Users/charlieanchetamacaraeg/Desktop/MYOattendanceV2/storage/framework/testing/disks/local/payment-proofs."
```

This affected receipt/proof upload tests, including:

- `tests/Feature/BillingPackagesTest.php::test_rejection_resets_bill_and_proof_workflow_can_return_to_pending`
- `tests/Feature/BillingPackagesTest.php::test_jpg_png_and_pdf_receipts_are_stored_under_payment_proofs`

The new E2E test uses `Storage::fake('local')`, but the Desktop workspace permissions prevent Laravel from creating the fake disk directories.

## Formatter issue

Running:

```bash
vendor/bin/pint --dirty --format agent tests/Feature/BillingEndToEndTest.php
```

also failed because the formatter could not write the test file:

```text
Cannot write to file "/Users/charlieanchetamacaraeg/Desktop/MYOattendanceV2/tests/Feature/BillingEndToEndTest.php" as it is not writable.
```

## Suggested checks

1. Ensure the repository, `storage/logs`, and `storage/framework/testing` are writable by the test process.
2. Remove or recreate stale test-storage directories if necessary.
3. Re-run the full suite after fixing permissions.
4. Decide whether `ExampleTest` should expect the public route to redirect to login or whether `/` should be public.
5. Run Pint again after write permissions are corrected.
