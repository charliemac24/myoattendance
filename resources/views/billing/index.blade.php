<x-layouts.app
    :user="$user"
    active-tab="more"
    page-title="Billing · {{ config('app.name') }}"
>
    <div class="billing-page">
        <div class="empty-state" id="billing-loading">
            <p>Loading&hellip;</p>
        </div>

        <div id="billing-content" hidden class="billing-page-content">
            <div class="billing-page-heading">
                <p class="billing-page-title">Billing</p>
                <p class="billing-page-sub">Manage your MYO Attendance subscription and payment submissions.</p>
            </div>

            <div class="billing-rejected-banner" id="billing-rejected-banner" role="alert" hidden>
                <x-icons.x-circle stroke="#B91C1C" />
                <div class="billing-rejected-banner-text">
                    <p class="billing-rejected-banner-title">Payment not accepted</p>
                    <p class="billing-rejected-banner-body"><span id="billing-rejected-reason"></span></p>
                </div>
            </div>

            <div class="billing-card billing-current-plan" id="billing-current-plan">
                <div class="billing-plan-row">
                    <div>
                        <p class="billing-card-sub">Your current plan</p>
                        <p class="billing-plan-name" id="billing-current-plan-name"></p>
                    </div>
                    <span class="status-pill billing-uppercase-pill" id="billing-current-plan-status"><span id="billing-current-plan-status-text"></span></span>
                </div>
                <div class="billing-scope-items">
                    <div class="billing-scope-item"><span>Base (scanning and attendance)</span><strong id="billing-current-base"></strong></div>
                    <div class="billing-scope-item"><span>SMS Notifications</span><strong id="billing-current-sms"></strong></div>
                </div>
                <p class="billing-activation-summary" id="billing-current-pending" hidden></p>
            </div>

            <div class="billing-renewal-choice" id="billing-renewal-choice" hidden>
                <p class="billing-renewal-choice-title">Renew or change your plan</p>
                <p class="billing-renewal-choice-sub">Pick a plan and how many months. Nothing changes until your payment is approved.</p>
                <div class="billing-renewal-choice-buttons">
                    <button type="button" class="billing-renewal-choice-btn" data-bill-type="base">
                        <span class="billing-renewal-choice-text">
                            <span class="billing-renewal-choice-btn-title">Base</span>
                            <span class="billing-renewal-choice-btn-caption" data-bill-type-caption="base">₱2,999/month</span>
                        </span>
                        <span class="billing-renewal-choice-check" aria-hidden="true">✓</span>
                    </button>
                    <button type="button" class="billing-renewal-choice-btn" data-bill-type="package">
                        <span class="billing-renewal-choice-text">
                            <span class="billing-renewal-choice-btn-title">Base + SMS</span>
                            <span class="billing-renewal-choice-btn-caption" data-bill-type-caption="package">₱2,999.00 + SMS · pick this to see your SMS price</span>
                        </span>
                        <span class="billing-renewal-choice-check" aria-hidden="true">✓</span>
                    </button>
                </div>
                <div class="billing-sms-estimate" id="billing-sms-estimate" hidden></div>
                <div class="billing-sms-request" id="billing-sms-request" hidden></div>
                <div class="billing-sms-now" id="billing-sms-now" hidden>
                    <p class="billing-renewal-choice-title" id="billing-sms-now-title"></p>
                    <p class="billing-renewal-choice-status" id="billing-sms-now-text"></p>
                    <button type="button" class="billing-renewal-choice-btn" id="billing-sms-now-btn" data-sms-now>
                        <span class="billing-renewal-choice-text">
                            <span class="billing-renewal-choice-btn-title" id="billing-sms-now-btn-title">Start SMS now</span>
                            <span class="billing-renewal-choice-btn-caption" id="billing-sms-now-btn-caption"></span>
                        </span>
                        <span class="billing-renewal-choice-check" aria-hidden="true">✓</span>
                    </button>
                </div>
                <p class="billing-renewal-choice-title" id="billing-months-title">How many months?</p>
                <div class="billing-renewal-choice-buttons billing-months-buttons" id="billing-months-buttons">
                    @foreach (\App\Models\Bill::MONTH_OPTIONS as $monthOption)
                        <button type="button" class="billing-renewal-choice-btn" data-months="{{ $monthOption }}">
                            <span class="billing-renewal-choice-btn-title">{{ $monthOption }} {{ $monthOption === 1 ? 'month' : 'months' }}</span>
                            <span class="billing-renewal-choice-check" aria-hidden="true">✓</span>
                        </button>
                    @endforeach
                </div>
                <p class="billing-renewal-choice-title" id="billing-change-summary" role="status"></p>
                <p class="billing-renewal-choice-status" id="billing-renewal-choice-status"></p>
            </div>

            <div class="billing-card">
                <div class="billing-card-heading">
                    <p class="billing-card-title" id="billing-payment-card-title">Submit GCash Payment</p>
                    <p class="billing-card-sub" id="billing-payment-card-sub">Pay by GCash, then send the details below. We check each payment by hand, and your plan updates once it is approved.</p>
                </div>

                <div class="billing-paid-summary" id="billing-paid-summary" hidden>
                    <div class="billing-paid-badge">
                        <x-icons.check-circle stroke="#15803D" />
                        <span id="billing-paid-badge-text">Already paid</span>
                    </div>
                    <div class="billing-scope-items" id="billing-paid-items"></div>
                    <button type="button" class="billing-link-btn" id="billing-pay-early-btn">Pay for the following period now</button>
                </div>

                <div id="billing-payment-form-wrap">
                    <div class="billing-scope-summary" id="billing-scope-summary" hidden>
                        <p class="billing-scope-summary-title" id="billing-scope-title"></p>
                        <div class="billing-scope-items" id="billing-scope-items"></div>
                        <p class="billing-activation-summary" id="billing-activation-summary" hidden></p>
                    </div>

                    <p class="form-error" id="billing-form-error" role="alert"></p>
                    <p class="form-success" id="billing-form-success" role="status"></p>

                    <div class="billing-error-banner" id="billing-error-banner" role="alert" hidden>
                        <x-icons.alert-triangle stroke="#EF4444" />
                        <div class="billing-error-banner-text">
                            <p class="billing-error-banner-title" id="billing-error-banner-title">Submission failed</p>
                            <p class="billing-error-banner-body" id="billing-error-banner-body">Please resolve the errors highlighted below and try again.</p>
                        </div>
                    </div>

                    <div class="payment-success-alert" id="billing-payment-success" role="status" hidden>
                        <x-icons.alert-circle stroke="#15803D" />
                        <div class="payment-success-alert-text">
                            <p class="payment-success-alert-title">Payment Submitted Successfully</p>
                            <p class="payment-success-alert-body">Your submission is now under manual verification. This usually takes 1-2 business hours.</p>
                        </div>
                    </div>

                    <form id="billing-payment-form" class="billing-form">
                        <div class="billing-field">
                            <label class="billing-field-label">Amount</label>
                            <div class="billing-field-static" id="billing-amount-display">₱2,999.00</div>
                            <input type="hidden" id="billing-amount" value="2999">
                            <input type="hidden" id="billing-bill-id">
                        </div>

                        <div class="billing-field">
                            <label class="billing-field-label" for="billing-reference">GCash Reference Number</label>
                            <input type="text" id="billing-reference" class="billing-field-input" placeholder="Enter 13-digit Reference No." required>
                            <p class="billing-field-error" id="billing-reference-error" hidden></p>
                        </div>

                        <div class="billing-field-row">
                            <div class="billing-field">
                                <label class="billing-field-label" for="billing-paid-at">Payment Date</label>
                                <input type="date" id="billing-paid-at" class="billing-field-input" required>
                                <p class="billing-field-error" id="billing-paid-at-error" hidden></p>
                            </div>
                            <div class="billing-field">
                                <label class="billing-field-label">Upload Receipt</label>
                                <label class="billing-upload-trigger" for="billing-proof-input" id="billing-upload-trigger">
                                    <x-icons.upload />
                                    <span id="billing-proof-label">Attach receipt (optional)</span>
                                </label>
                                <input type="file" id="billing-proof-input" accept="image/*,.pdf" hidden>
                                <p class="billing-field-error" id="billing-proof-error" hidden></p>
                            </div>
                        </div>

                        <div class="billing-field">
                            <label class="billing-field-label" for="billing-notes">Optional Note</label>
                            <input type="text" id="billing-notes" class="billing-field-input" placeholder="Any message or reference name...">
                        </div>

                        <button type="submit" class="btn-primary billing-submit-btn" id="billing-submit-btn">Submit Payment for Review</button>
                    </form>
                </div>
            </div>

            <div class="billing-card">
                <p class="billing-card-title">Payment History</p>
                <div id="billing-payment-history" class="billing-history-empty">
                    <p>No payments recorded yet.</p>
                </div>
            </div>
        </div>
    </div>

    <x-slot:scripts>
        <template id="billing-sms-choice-template">
            <div class="billing-sms-choice">
                <button type="button" class="billing-sms-choice-btn billing-sms-choice-btn--primary" data-mode="after_trial">
                    <span class="billing-sms-choice-icon"><x-icons.clock stroke="#2563EB" /></span>
                    <span class="billing-sms-choice-text">
                        <span class="billing-sms-choice-title">Request SMS · start my paid plan after the trial</span>
                        <span class="billing-sms-choice-caption">Recommended &middot; you keep your remaining free days</span>
                    </span>
                    <span class="billing-sms-choice-chevron"><x-icons.chevron-right stroke="#2563EB" /></span>
                </button>
                <button type="button" class="billing-sms-choice-btn billing-sms-choice-btn--warning" data-mode="immediate">
                    <span class="billing-sms-choice-icon"><x-icons.zap stroke="#B45309" /></span>
                    <span class="billing-sms-choice-text">
                        <span class="billing-sms-choice-title">Request SMS · start my paid plan right away</span>
                        <span class="billing-sms-choice-caption">Your free trial ends early, on the day your payment is approved</span>
                    </span>
                    <span class="billing-sms-choice-chevron"><x-icons.chevron-right stroke="#B45309" /></span>
                </button>
            </div>
        </template>

        <template id="billing-payment-row-template">
            <div class="billing-payment-row">
                <div class="billing-payment-row-head">
                    <p class="billing-payment-row-amount" data-amount></p>
                    <span class="status-pill billing-uppercase-pill" data-status-pill><span data-status-text></span></span>
                </div>
                <div class="billing-payment-row-details">
                    <p data-plan-row hidden>For: <strong data-plan></strong></p>
                    <p>Ref: <strong data-ref></strong></p>
                    <p>Paid: <em data-paid></em></p>
                    <p data-reviewed-row>Reviewed: <em data-reviewed></em></p>
                    <p class="billing-payment-row-reason" data-reason-row hidden>Reason: <span data-reason></span></p>
                </div>
            </div>
        </template>

        @vite(['resources/js/billing.js'])
    </x-slot:scripts>
</x-layouts.app>
