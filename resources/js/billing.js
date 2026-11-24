import { get, post, upload } from './services/api';

const els = {
    loading: document.getElementById('billing-loading'),
    content: document.getElementById('billing-content'),
    rejectedBanner: document.getElementById('billing-rejected-banner'),
    rejectedReason: document.getElementById('billing-rejected-reason'),
    currentPlanName: document.getElementById('billing-current-plan-name'),
    currentPlanStatus: document.getElementById('billing-current-plan-status'),
    currentPlanStatusText: document.getElementById('billing-current-plan-status-text'),
    currentBase: document.getElementById('billing-current-base'),
    currentSms: document.getElementById('billing-current-sms'),
    currentPending: document.getElementById('billing-current-pending'),
    changeSummary: document.getElementById('billing-change-summary'),
    paidBadgeText: document.getElementById('billing-paid-badge-text'),
    renewalChoice: document.getElementById('billing-renewal-choice'),
    typeButtons: [...document.querySelectorAll('[data-bill-type]')],
    monthButtons: [...document.querySelectorAll('[data-months]')],
    renewalChoiceStatus: document.getElementById('billing-renewal-choice-status'),
    paymentCardTitle: document.getElementById('billing-payment-card-title'),
    paymentCardSub: document.getElementById('billing-payment-card-sub'),
    paidSummary: document.getElementById('billing-paid-summary'),
    paidItems: document.getElementById('billing-paid-items'),
    payEarlyBtn: document.getElementById('billing-pay-early-btn'),
    payEarlyNote: document.getElementById('billing-pay-early-note'),
    formWrap: document.getElementById('billing-payment-form-wrap'),
    scopeSummary: document.getElementById('billing-scope-summary'),
    scopeTitle: document.getElementById('billing-scope-title'),
    scopeItems: document.getElementById('billing-scope-items'),
    activationSummary: document.getElementById('billing-activation-summary'),
    amountDisplay: document.getElementById('billing-amount-display'),
    form: document.getElementById('billing-payment-form'),
    formError: document.getElementById('billing-form-error'),
    formSuccess: document.getElementById('billing-form-success'),
    errorBanner: document.getElementById('billing-error-banner'),
    errorBannerBody: document.getElementById('billing-error-banner-body'),
    paymentSuccess: document.getElementById('billing-payment-success'),
    amount: document.getElementById('billing-amount'),
    billId: document.getElementById('billing-bill-id'),
    reference: document.getElementById('billing-reference'),
    referenceError: document.getElementById('billing-reference-error'),
    paidAt: document.getElementById('billing-paid-at'),
    paidAtError: document.getElementById('billing-paid-at-error'),
    notes: document.getElementById('billing-notes'),
    proofInput: document.getElementById('billing-proof-input'),
    uploadTrigger: document.getElementById('billing-upload-trigger'),
    proofLabel: document.getElementById('billing-proof-label'),
    proofError: document.getElementById('billing-proof-error'),
    submitBtn: document.getElementById('billing-submit-btn'),
    smsRequest: document.getElementById('billing-sms-request'),
    smsEstimate: document.getElementById('billing-sms-estimate'),
    smsNow: document.getElementById('billing-sms-now'),
    smsNowTitle: document.getElementById('billing-sms-now-title'),
    smsNowText: document.getElementById('billing-sms-now-text'),
    smsNowBtn: document.getElementById('billing-sms-now-btn'),
    smsNowBtnTitle: document.getElementById('billing-sms-now-btn-title'),
    smsNowBtnCaption: document.getElementById('billing-sms-now-btn-caption'),
    monthsTitle: document.getElementById('billing-months-title'),
    monthsButtons: document.getElementById('billing-months-buttons'),
    smsChoiceTemplate: document.getElementById('billing-sms-choice-template'),
    history: document.getElementById('billing-payment-history'),
    rowTemplate: document.getElementById('billing-payment-row-template'),
};

let selectedProof = null;
let payEarlyRevealed = false;
let selectedType = null;
let draftPackage = false;
let selectedMonths = null;

const PLAN_LABELS = { base: 'Base', package: 'Base + SMS', sms_now: 'SMS for the rest of this period', sms: 'SMS add-on (old plan)' };
const planLabel = (type) => PLAN_LABELS[type] || 'Base';

function nextDay(value) {
    const [year, month, day] = String(value).slice(0, 10).split('-').map(Number);
    return new Date(Date.UTC(year, month - 1, day + 1)).toISOString().slice(0, 10);
}

/**
 * SMS-now bills store the last full day; SMS itself runs until the day the Base period ends.
 */
function billPeriodText(bill) {
    return bill.bill_type === 'sms_now'
        ? `${formatDate(bill.billing_period_start)} until ${formatDate(nextDay(bill.billing_period_end))}`
        : `${formatDate(bill.billing_period_start)} – ${formatDate(bill.billing_period_end)}`;
}

const monthsLabel = (months) => `${months} ${months === 1 ? 'month' : 'months'}`;

const MAX_UPLOAD_BYTES = 4.5 * 1024 * 1024;
const COMPRESS_ABOVE_BYTES = 1.5 * 1024 * 1024;

function loadImage(file) {
    if (window.createImageBitmap) {
        return createImageBitmap(file);
    }
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => { URL.revokeObjectURL(url); resolve(image); };
        image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('unreadable')); };
        image.src = url;
    });
}

/**
 * Shrinks large photos/screenshots and converts HEIC/WEBP to JPEG so receipts stay
 * well under the server's upload limits. PDFs and small JPG/PNG files are sent as-is.
 */
async function prepareProof(file) {
    if (!file || !file.type.startsWith('image/')) return file;
    const isAccepted = ['image/jpeg', 'image/png'].includes(file.type);
    if (isAccepted && file.size <= COMPRESS_ABOVE_BYTES) return file;

    let image;
    try {
        image = await loadImage(file);
    } catch {
        return file;
    }
    const scale = Math.min(1, 1600 / Math.max(image.width, image.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(image.width * scale);
    canvas.height = Math.round(image.height * scale);
    const context = canvas.getContext('2d');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(image, 0, 0, canvas.width, canvas.height);

    for (const quality of [0.85, 0.7, 0.55]) {
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        if (blob && blob.size <= MAX_UPLOAD_BYTES) {
            const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
            return new File([blob], name, { type: 'image/jpeg' });
        }
    }
    return file;
}

function formatDate(value) {
    if (!value) return '';
    const [datePart] = value.split('T');
    const [year, month, day] = datePart.split('-').map(Number);
    if (!year || !month || !day) return '';
    return new Intl.DateTimeFormat('en-US', { month: 'short', day: '2-digit', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, day)));
}

function formatMoney(minor, currency) {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: currency || 'PHP' }).format((minor || 0) / 100);
}

function scopeRow(item, currency) {
    const row = document.createElement('div');
    row.className = 'billing-scope-item';
    row.innerHTML = `<span>${item.description}</span><strong>${formatMoney(item.amount_minor, currency)}</strong>`;
    return row;
}

function renderBill(billing) {
    const bill = billing.current_bill;
    const subscription = billing.subscription;
    if (!bill) return;
    const amountMinor = bill.total_minor ?? 0;
    els.amountDisplay.textContent = formatMoney(amountMinor, bill.currency);
    els.amount.value = (amountMinor / 100).toFixed(2);
    els.billId.value = bill.id;

    const items = bill.items || [];
    const paidBill = billing.paid_bill;
    // When access ends within a week, show the payment form straight away instead of the "already paid" summary.
    const accessEnds = billing.current_plan?.base_paid_through;
    const endsSoon = accessEnds ? daysUntil(accessEnds) <= 7 : false;
    const showPaid = !!paidBill && !payEarlyRevealed && !endsSoon && bill.status !== 'payment_pending';

    els.paidSummary.hidden = !showPaid;
    els.formWrap.hidden = showPaid;

    if (showPaid) {
        els.paymentCardTitle.textContent = 'Payment';
        els.paymentCardSub.textContent = 'Your current period is paid. You can pay for the next one now or later.';
        els.paidBadgeText.textContent = `Paid: ${planLabel(paidBill.bill_type)}, ${billPeriodText(paidBill)}`;

        els.paidItems.innerHTML = '';
        (paidBill.items || []).forEach((item) => els.paidItems.appendChild(scopeRow(item, paidBill.currency)));
        // Same last paid day as the badge above, so both dates agree.
        const paidThrough = paidBill.billing_period_end ? `Your ${planLabel(paidBill.bill_type)} is paid through ${formatDate(paidBill.billing_period_end)}. ` : '';
        els.payEarlyNote.textContent = `${paidThrough}Pay now for ${formatDate(bill.billing_period_start)} to ${formatDate(bill.billing_period_end)}.`;
        els.scopeSummary.hidden = true;
        return;
    }

    els.paymentCardTitle.textContent = bill.bill_type === 'sms_now'
        ? 'Pay for Add SMS (the days left in your Base time)'
        : `Pay for ${planLabel(bill.bill_type)}, ${monthsLabel(bill.months || 1)}`;
    els.paymentCardSub.textContent = endsSoon && bill.bill_type !== 'sms_now'
        ? `Your current period ends on ${formatDate(accessEnds)}. Pay now so scanning does not stop. We check each GCash payment by hand, and your plan updates once it is approved.`
        : 'Pay by GCash, then send the details below. We check each payment by hand, and your plan updates once it is approved.';
    els.scopeSummary.hidden = false;
    const period = bill.billing_period_start ? ` (${billPeriodText(bill)})` : '';
    els.scopeTitle.textContent = `Breakdown${period}`;
    els.scopeItems.innerHTML = '';
    items.forEach((item) => els.scopeItems.appendChild(scopeRow(item, bill.currency)));

    const underReview = bill.status === 'payment_pending' || billing.payment_under_review;
    els.submitBtn.disabled = underReview;
    els.submitBtn.dataset.underReview = underReview ? '1' : '';
    if (underReview) els.submitBtn.textContent = 'Payment Under Review';
}

function renderRejectedNotice(latestPayment) {
    const rejected = latestPayment && latestPayment.status === 'rejected';
    els.rejectedBanner.hidden = !rejected;
    const what = latestPayment?.bill ? `Your ${planLabel(latestPayment.bill.bill_type)} payment of ${formatMoney(latestPayment.amount_minor, latestPayment.currency)} was not accepted. ` : '';
    els.rejectedReason.textContent = rejected ? `${what}Reason: ${latestPayment.rejection_reason || 'No reason provided.'} You can submit a new payment below.` : '';
}

function daysUntil(value) {
    const end = new Date(value);
    const today = new Date();
    const startOf = (date) => new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
    return Math.round((startOf(end) - startOf(today)) / 86400000);
}

const STATUS_TONE = { trialing: 'info', active: 'success', expired: 'danger' };
const STATUS_TEXT = { trialing: 'Free trial', active: 'Active', expired: 'Expired' };

function smsStatusText(sms) {
    switch (sms.state) {
        case 'on': return sms.ends_at ? `On until ${formatDate(sms.ends_at)}` : 'On';
        case 'scheduled': return `Starts ${formatDate(sms.starts_at)}, on until ${formatDate(sms.ends_at)}`;
        case 'price_ready': return 'Off · price ready, pay Base + SMS to turn on';
        case 'available': return 'Off · pay for Base + SMS to turn on';
        case 'requested': return 'Off · requested, waiting for price';
        default: return 'Off';
    }
}

/**
 * What will happen once the payment under review is approved, in plain words.
 */
function pendingOutcome(plan, pending) {
    if (pending.plan === 'sms_now') return 'SMS turns on once it is approved.';
    if (pending.plan === 'base' && ['on', 'scheduled'].includes(plan.sms.state)) {
        return `Once it is approved, SMS stays on until ${formatDate(plan.sms.ends_at)}, then you continue on Base from ${formatDate(pending.period_start)}.`;
    }
    return `Once it is approved, it covers ${formatDate(pending.period_start)} – ${formatDate(pending.period_end)}.`;
}

function renderCurrentPlan(billing) {
    const plan = billing.current_plan;
    const subscription = billing.subscription;
    els.submitBtn.textContent = 'Submit Payment for Review';
    if (!plan) return;

    els.currentPlanName.textContent = plan.label;
    els.currentPlanStatus.className = `status-pill billing-uppercase-pill status-pill--${STATUS_TONE[plan.status] || 'neutral'}`;
    els.currentPlanStatusText.textContent = STATUS_TEXT[plan.status] || plan.status;

    // Warn when access ends within a week so the school renews before scanning stops.
    const accessEnds = plan.status === 'trialing' && !plan.base_paid_through ? plan.trial_ends_at : plan.base_paid_through;
    const daysLeft = accessEnds && plan.status !== 'expired' ? daysUntil(accessEnds) : null;
    if (daysLeft !== null && daysLeft <= 7 && !plan.pending_payment) {
        els.currentPlanStatus.className = 'status-pill billing-uppercase-pill status-pill--warning';
        els.currentPlanStatusText.textContent = daysLeft <= 0 ? 'Ends today' : daysLeft === 1 ? 'Ends tomorrow' : `Ends in ${daysLeft} days`;
    }

    if (plan.status === 'expired') {
        els.currentBase.textContent = 'Expired · renew below to keep scanning';
    } else if (plan.base_paid_through) {
        els.currentBase.textContent = plan.status === 'trialing'
            ? `Free trial until ${formatDate(plan.trial_ends_at)}, then paid through ${formatDate(plan.base_paid_through)}`
            : `Paid through ${formatDate(plan.base_paid_through)}`;
    } else if (plan.status === 'trialing') {
        els.currentBase.textContent = `Free trial until ${formatDate(plan.trial_ends_at)} (${subscription.days_remaining} day${subscription.days_remaining === 1 ? '' : 's'} left)`;
    } else {
        els.currentBase.textContent = 'Active';
    }
    els.currentSms.textContent = plan.pending_payment?.plan === 'sms_now' && !['on', 'scheduled'].includes(plan.sms.state)
        ? 'Off · turns on once your SMS payment is approved'
        : plan.pending_payment?.plan === 'package' && !['on', 'scheduled'].includes(plan.sms.state)
        ? (plan.pending_payment.period_start > new Date().toISOString().slice(0, 10)
            ? `Off · starts ${formatDate(plan.pending_payment.period_start)} once your Base + SMS payment is approved`
            : 'Off · turns on once your Base + SMS payment is approved')
        : plan.sms.state === 'available' && billing.sms_now?.available
            ? 'Off · add it today with "Add SMS" below'
            : plan.sms.state === 'on' && plan.sms.ends_at && plan.base_paid_through && plan.sms.ends_at < String(plan.base_paid_through).slice(0, 10)
            ? `On until ${formatDate(plan.sms.ends_at)}, then off (you chose Base after that)`
            : smsStatusText(plan.sms);

    const pending = plan.pending_payment;
    els.currentPending.hidden = !pending;
    if (pending) {
        const what = pending.plan === 'sms_now'
            ? `${pending.plan_label}, ${formatMoney(pending.amount_minor, 'PHP')}`
            : `${pending.plan_label}, ${monthsLabel(pending.months)}, ${formatMoney(pending.amount_minor, 'PHP')}`;
        els.currentPending.textContent = pending.status === 'proof_required'
            ? `Payment needs a new receipt: ${what}. See your payment history below.`
            : `Payment under review: ${what}, submitted ${formatDate(pending.submitted_at)}. ${pendingOutcome(plan, pending)}`;
    }
}

function renderPlanPicker(billing, sms) {
    const available = billing.available_types || { base: true, package: false };
    els.renewalChoice.hidden = false;
    selectedType = billing.bill_type || 'base';
    selectedMonths = billing.months || 1;

    const smsPrice = billing.sms_pricing?.sms_monthly_price_minor;
    const base = billing.subscription.monthly_price_minor;
    // A Base + SMS payment already under review keeps showing its own plan.
    const addSmsMode = !!billing.sms_now?.available && !['on', 'scheduled'].includes(billing.current_plan?.sms?.state) && !(billing.bill_type === 'package' && billing.current_bill?.status === 'payment_pending');
    const captions = {
        base: `${formatMoney(base, 'PHP')}/month`,
        package: available.package && smsPrice
            ? `${formatMoney(base + smsPrice, 'PHP')}/month (Base ${formatMoney(base, 'PHP')} + SMS ${formatMoney(smsPrice, 'PHP')}${billing.sms_estimate?.price_source === 'estimate' ? ', estimated' : ''})`
            : sms?.next_step === 'waiting_for_price' ? 'Requested · we will send your SMS price soon' : `${formatMoney(base, 'PHP')} + SMS · pick this to see your SMS price`,
    };
    els.typeButtons.forEach((button) => {
        const type = button.dataset.billType;
        const caption = button.querySelector('[data-bill-type-caption]');
        if (caption) caption.textContent = captions[type];
        button.dataset.unavailable = available[type] ? '' : '1';
        // Paid Base with days left: SMS is added with the single "Add SMS" button, so Base + SMS is not offered.
        button.hidden = type === 'package' && addSmsMode;
        const selected = type === (draftPackage ? 'package' : selectedType);
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        const title = button.querySelector('.billing-renewal-choice-btn-title');
        if (title) {
            title.dataset.name ??= title.textContent;
            const isCurrent = billing.current_plan?.status !== 'expired' && billing.current_plan?.plan === type;
            title.textContent = isCurrent ? `${title.dataset.name} (current plan)` : title.dataset.name;
        }
    });
    renderSmsNow(billing);
    renderSmsEstimate(billing, sms);
    const isSmsNow = selectedType === 'sms_now';
    els.monthsTitle.hidden = isSmsNow;
    els.monthsButtons.hidden = isSmsNow;
    els.monthsButtons.style.display = isSmsNow ? 'none' : '';
    els.changeSummary.textContent = draftPackage ? 'Answer the SMS questions above to see your Base + SMS price.' : (billing.plan_change?.message || '');
    els.monthButtons.forEach((button) => {
        const selected = Number(button.dataset.months) === selectedMonths;
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });

    els.renewalChoiceStatus.textContent = '';
}

/**
 * The SMS questions (people, texts, days) and the estimated price. Shown only while Base + SMS is picked.
 */
function renderSmsEstimate(billing, sms) {
    const box = els.smsEstimate;
    const show = draftPackage || selectedType === 'package';
    box.hidden = !show;
    box.innerHTML = '';
    if (!show) return;

    const estimate = billing.sms_estimate || {};
    const price = billing.sms_pricing?.sms_monthly_price_minor;
    if (estimate.price_source === 'admin' && price) {
        const p = document.createElement('p');
        p.className = 'billing-renewal-choice-status';
        p.textContent = `Your SMS price is ${formatMoney(price, 'PHP')}/month, set by MYO Attendance. If your numbers change, contact us to update it.`;
        box.appendChild(p);
        return;
    }

    const settings = estimate.settings || { price_per_text_minor: 50, round_to_minor: 5000, days_per_month: { weekdays: 22, mon_sat: 26, every_day: 30 } };
    const texts = estimate.texts?.length ? estimate.texts : ['time_in', 'time_out'];
    const days = estimate.days || 'weekdays';
    box.innerHTML = `
        <p class="billing-renewal-choice-title">Get your SMS price</p>
        <p class="billing-renewal-choice-status">SMS texts a person's contact number when their Time In, Late, or Time Out is recorded. Tell us how you'll use it and we'll show your monthly price right away.</p>
        <label class="billing-estimate-field">How many people will receive SMS?
            <input type="number" min="1" max="100000" data-estimate="people" placeholder="e.g. 350">
            <span class="billing-estimate-hint">Usually your number of students or employees.</span>
        </label>
        <div class="billing-estimate-group"><span class="billing-renewal-choice-title">Which texts?</span>
            <label><input type="checkbox" data-estimate="text" value="time_in"> Time In</label>
            <label><input type="checkbox" data-estimate="text" value="time_out"> Time Out</label>
            <label><input type="checkbox" data-estimate="text" value="late"> Late (replaces Time In)</label>
        </div>
        <div class="billing-estimate-group"><span class="billing-renewal-choice-title">Which days?</span>
            <label><input type="radio" name="billing-estimate-days" data-estimate="days" value="weekdays"> Weekdays</label>
            <label><input type="radio" name="billing-estimate-days" data-estimate="days" value="mon_sat"> Mon–Sat</label>
            <label><input type="radio" name="billing-estimate-days" data-estimate="days" value="every_day"> Every day</label>
        </div>
        <div class="billing-estimate-result" data-estimate="result"></div>
        <p class="billing-estimate-saved" data-estimate="status" role="status"></p>
        <p class="billing-renewal-choice-status" data-estimate="note">Your price updates automatically as you answer. It is an estimate; MYO Attendance may adjust it if your numbers change.</p>`;
    const people = box.querySelector('[data-estimate="people"]');
    people.value = estimate.people || '';
    box.querySelectorAll('[data-estimate="text"]').forEach((input) => { input.checked = texts.includes(input.value); });
    box.querySelectorAll('[data-estimate="days"]').forEach((input) => { input.checked = input.value === days; });

    const canRequest = !sms?.request_status || sms.request_status !== 'pending';
    if (canRequest) {
        const link = document.createElement('button');
        link.type = 'button';
        link.className = 'billing-link-btn';
        link.textContent = 'Prefer a custom price? Request SMS instead';
        link.addEventListener('click', () => requestSms(billing.current_plan?.in_trial ? undefined : 'next_payment', [link]));
        box.querySelector('[data-estimate="note"]').append(' ', link);
    }

    const read = () => ({
        people: Number(people.value) || 0,
        texts: [...box.querySelectorAll('[data-estimate="text"]:checked')].map((input) => input.value),
        days: box.querySelector('[data-estimate="days"]:checked')?.value || 'weekdays',
    });
    const compute = ({ people: count, texts: chosen, days: dayKey }) => {
        const perDay = (chosen.includes('time_in') || chosen.includes('late') ? 1 : 0) + (chosen.includes('time_out') ? 1 : 0);
        const raw = count * perDay * (settings.days_per_month[dayKey] || 0) * settings.price_per_text_minor;
        const step = settings.round_to_minor;
        return { perDay, amount: step > 0 ? Math.ceil(raw / step) * step : raw };
    };
    const result = box.querySelector('[data-estimate="result"]');
    const status = box.querySelector('[data-estimate="status"]');
    let timer = null;
    const isSaved = (answers, amount) => price && estimate.price_source === 'estimate' && amount === price && answers.people === estimate.people
        && answers.days === estimate.days && answers.texts.slice().sort().join() === (estimate.texts || []).slice().sort().join();

    // Answers are saved automatically a moment after the school stops typing; the Base + SMS price then updates.
    const save = async () => {
        const answers = read();
        status.textContent = 'Updating your Base + SMS price…';
        try {
            await post('/api/billing/sms-estimate', answers);
            draftPackage = false;
            const focused = document.activeElement?.dataset?.estimate;
            await load('package', selectedMonths);
            if (focused === 'people') {
                const input = els.smsEstimate.querySelector('[data-estimate="people"]');
                input?.focus();
                input?.setSelectionRange?.(input.value.length, input.value.length);
            }
        } catch (error) {
            status.textContent = error.message || 'Unable to save your SMS answers.';
        }
    };
    const refresh = (schedule) => {
        const answers = read();
        const { perDay, amount } = compute(answers);
        const ready = answers.people > 0 && perDay > 0;
        result.innerHTML = ready
            ? `Estimated SMS price: <strong>${formatMoney(amount, 'PHP')}/month</strong> · ${answers.people} people × ${perDay} text${perDay === 1 ? '' : 's'}/day × ${settings.days_per_month[answers.days]} days × ${formatMoney(settings.price_per_text_minor, 'PHP')}`
            : 'Estimated SMS price: <strong>—</strong> · enter the number of people and pick at least one text';
        clearTimeout(timer);
        if (!ready) {
            status.textContent = '';
            return;
        }
        if (isSaved(answers, amount)) {
            status.textContent = '✓ Saved. Your Base + SMS price above uses this estimate.';
            return;
        }
        status.textContent = '';
        if (schedule) timer = setTimeout(save, 800);
    };
    box.querySelectorAll('input').forEach((input) => input.addEventListener('input', () => refresh(true)));
    refresh(false);
}

/**
 * Base + SMS picked but no SMS price yet: the payment form shows no amount and cannot be submitted.
 */
function renderDraftPayment() {
    els.paidSummary.hidden = true;
    els.formWrap.hidden = false;
    els.paymentCardTitle.textContent = 'Pay for Base + SMS, 1 month';
    els.scopeSummary.hidden = false;
    els.scopeTitle.textContent = 'Breakdown';
    els.scopeItems.innerHTML = '';
    els.amountDisplay.textContent = '— (answer the SMS questions first)';
    els.submitBtn.disabled = true;
    els.submitBtn.dataset.underReview = '1';
    els.submitBtn.textContent = 'Answer the SMS questions first';
}

/**
 * "Add SMS": a one-time SMS charge for the days left in the paid Base period. While it is offered, it replaces Base + SMS.
 */
function renderSmsNow(billing) {
    const quote = billing.sms_now;
    const smsOff = !['on', 'scheduled'].includes(billing.current_plan?.sms?.state);
    const show = quote && smsOff && (quote.available || quote.reason === 'too_few_days');
    els.smsNow.hidden = !show;
    if (!show) return;

    const smsEnds = formatDate(quote.sms_ends_at);
    if (!quote.available) {
        els.smsNowTitle.hidden = true;
        els.smsNowText.textContent = `Add SMS is not available in the last ${quote.min_days} days of your paid Base time. Your Base ends on ${smsEnds}, so choose Base + SMS for your next payment.`;
        els.smsNowBtn.hidden = true;
        return;
    }

    const price = formatMoney(quote.monthly_price_minor, 'PHP');
    const amount = formatMoney(quote.amount_minor, 'PHP');
    const daysLeft = `${quote.days_left} day${quote.days_left === 1 ? '' : 's'}`;
    const partialDays = `${quote.partial_days} of ${quote.partial_month_days} days`;
    const math = quote.full_months > 0
        ? `${monthsLabel(quote.full_months)} × ${price}${quote.partial_days ? ` + ${partialDays}` : ''}`
        : `${price}/month × ${partialDays}`;
    els.smsNowTitle.hidden = true;
    els.smsNowText.textContent = `Add SMS today and pay only for the ${daysLeft} left in your paid Base time (${math} = ${amount}). Your Base dates do not change. When you renew, Base + SMS renews together.`;
    els.smsNowBtn.hidden = false;
    els.smsNowBtnTitle.textContent = `Add SMS · ${amount}`;
    els.smsNowBtnCaption.textContent = `SMS from ${formatDate(quote.start)} until ${smsEnds}`;
    const selected = selectedType === 'sms_now';
    els.smsNowBtn.classList.toggle('is-selected', selected);
    els.smsNowBtn.setAttribute('aria-pressed', selected ? 'true' : 'false');
    const action = document.getElementById('billing-sms-now-action');
    if (action) action.textContent = selected ? '✓ Selected' : 'Select';
}

const ACTIVATION_TEXT = {
    immediate: 'You chose to start your paid plan right after this payment is approved. This ends your free trial early.',
    after_trial: 'You chose to start your paid plan when your free trial ends.',
};

function smsLine(text) {
    const p = document.createElement('p');
    p.className = 'billing-renewal-choice-status';
    p.textContent = text;
    els.smsRequest.appendChild(p);
}

async function requestSms(activationMode, buttons) {
    buttons.forEach((b) => { b.disabled = true; });
    try {
        await post('/api/organization/sms/access/request', activationMode ? { activation_mode: activationMode } : {});
        await load();
    } catch (error) {
        smsLine(error.message || 'Unable to send your SMS request.');
        buttons.forEach((b) => { b.disabled = false; });
    }
}

const SMS_CAPTIONS = {
    choose_trial_timing: 'Not set up yet · request it below',
    request: 'Not set up yet · request it below',
    waiting_for_price: 'Requested · we will send your price soon',
    renew_first: 'Renew Base first, then request SMS',
};

/**
 * SMS lives inside the plan picker: when Base + SMS is not available yet, the steps to unlock it
 * (request a price, and for trial schools, when the paid plan should start) appear right under the plans.
 */
function renderSmsRequest(sms) {
    els.smsRequest.innerHTML = '';
    const plan = lastBilling?.current_plan;
    const step = sms?.next_step;

    // 1. Trial schools choose when their paid plan (Base, plus SMS if added) starts. Shown for the whole trial.
    if (plan?.in_trial) renderStartChoice(plan);

    // 2. SMS questions and "Request SMS" now live under Base + SMS (renderSmsEstimate); only the request status shows here.
    if (step === 'waiting_for_price') {
        smsLine('SMS requested. We will send your SMS price soon.');
    }

    els.smsRequest.hidden = els.smsRequest.childElementCount === 0;
}

/**
 * "When should your paid plan start?" for a school on its free trial. Saved right away; it can be changed
 * until a payment is approved, and the bill dates follow it.
 */
function renderStartChoice(plan) {
    const selected = plan.start_choice || 'after_trial';
    const title = document.createElement('p');
    title.className = 'billing-renewal-choice-title';
    title.textContent = 'When should your paid plan start?';
    els.smsRequest.appendChild(title);
    smsLine('This applies to Base, and to SMS if you add it. You can change it until your payment is approved.');

    const options = [
        { choice: 'after_trial', title: `After my free trial ends (${formatDate(plan.trial_ends_at)})`, caption: 'Recommended · you keep your remaining free days' },
        { choice: 'immediate', title: 'Right away', caption: 'Your free trial ends early, on the day your payment is approved' },
    ];
    const list = document.createElement('div');
    list.className = 'billing-renewal-choice-buttons billing-sms-timing';
    list.setAttribute('role', 'radiogroup');
    list.setAttribute('aria-label', 'When should your paid plan start?');
    const buttons = [];
    options.forEach((option) => {
        const isSelected = option.choice === selected;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'billing-renewal-choice-btn' + (isSelected ? ' is-selected' : '');
        button.setAttribute('role', 'radio');
        button.setAttribute('aria-checked', isSelected ? 'true' : 'false');
        button.innerHTML = '<span class="billing-renewal-choice-text"><span class="billing-renewal-choice-btn-title"></span><span class="billing-renewal-choice-btn-caption"></span></span><span class="billing-renewal-choice-check" aria-hidden="true"></span>';
        button.querySelector('.billing-renewal-choice-btn-title').textContent = option.title;
        button.querySelector('.billing-renewal-choice-btn-caption').textContent = option.caption;
        button.addEventListener('click', async () => {
            if (isSelected) return;
            if (option.choice === 'immediate' && !window.confirm('Your free trial will end early, on the day your payment is approved. Continue?')) return;
            buttons.forEach((b) => { b.disabled = true; });
            try {
                await post('/api/billing/start-choice', { choice: option.choice });
                await load(selectedType, selectedMonths);
            } catch (error) {
                smsLine(error.message || 'Unable to save your choice.');
                buttons.forEach((b) => { b.disabled = false; });
            }
        });
        buttons.push(button);
        list.appendChild(button);
    });
    els.smsRequest.appendChild(list);
}

function renderHistory(payments) {
    if (!payments.length) {
        els.history.className = 'billing-history-empty';
        els.history.innerHTML = '<p>No payments recorded yet.</p>';
        return;
    }

    els.history.className = '';
    els.history.innerHTML = '';
    const tone = { pending: 'warning', approved: 'success', rejected: 'danger' };
    payments.forEach((payment) => {
        const fragment = els.rowTemplate.content.cloneNode(true);
        fragment.querySelector('[data-amount]').textContent = formatMoney(payment.amount_minor, payment.currency);
        const pill = fragment.querySelector('[data-status-pill]');
        pill.classList.add('status-pill--' + (tone[payment.status] || 'info'));
        fragment.querySelector('[data-status-text]').textContent = payment.status;

        fragment.querySelector('[data-ref]').textContent = payment.manual_entry
            ? `${({ cash: 'Cash', bank_transfer: 'Bank transfer' })[payment.payment_method] || 'GCash'} · recorded by Super Admin${payment.reference_number ? ' · ' + payment.reference_number : ''}`
            : payment.reference_number || '—';
        if (payment.bill) {
            fragment.querySelector('[data-plan-row]').hidden = false;
            const length = payment.bill.bill_type === 'sms_now' ? '' : `, ${monthsLabel(payment.bill.months || 1)}`;
            fragment.querySelector('[data-plan]').textContent = `${planLabel(payment.bill.bill_type)}${length} (${billPeriodText(payment.bill)})`;
        }
        fragment.querySelector('[data-paid]').textContent = formatDate(payment.paid_at) || '—';

        const reviewedRow = fragment.querySelector('[data-reviewed-row]');
        if (payment.reviewed_at) {
            fragment.querySelector('[data-reviewed]').textContent = formatDate(payment.reviewed_at);
        } else {
            reviewedRow.hidden = true;
        }

        if (payment.status === 'rejected' && payment.rejection_reason) {
            const reasonRow = fragment.querySelector('[data-reason-row]');
            fragment.querySelector('[data-reason]').textContent = payment.rejection_reason;
            reasonRow.hidden = false;
        }

        els.history.appendChild(fragment);
    });
}

function resetProofPicker() {
    selectedProof = null;
    els.proofInput.value = '';
    els.proofLabel.textContent = 'Attach receipt (optional)';
    els.uploadTrigger.classList.remove('has-file');
}

const FIELD_ERROR_MAP = {
    reference_number: { input: els.reference, error: els.referenceError },
    paid_at: { input: els.paidAt, error: els.paidAtError },
    proof: { input: null, error: els.proofError, upload: true },
};

function clearFormErrors() {
    els.formError.classList.remove('visible');
    els.errorBanner.hidden = true;
    Object.values(FIELD_ERROR_MAP).forEach(({ input, error, upload: isUpload }) => {
        error.hidden = true;
        error.textContent = '';
        if (input) input.classList.remove('has-error');
        if (isUpload) els.uploadTrigger.classList.remove('has-error');
    });
}

let lastBilling = null;
let lastAccess = null;

els.payEarlyBtn.addEventListener('click', () => {
    payEarlyRevealed = true;
    if (lastBilling) renderBill(lastBilling);
});

els.proofInput.addEventListener('change', () => {
    selectedProof = els.proofInput.files[0] || null;
    els.proofLabel.textContent = selectedProof ? selectedProof.name : 'Attach receipt (optional)';
    els.uploadTrigger.classList.toggle('has-file', !!selectedProof);
    if (selectedProof) {
        els.uploadTrigger.classList.remove('has-error');
        els.proofError.hidden = true;
    }
});

els.form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearFormErrors();
    els.formSuccess.classList.remove('visible');
    els.paymentSuccess.hidden = true;

    const submitLabel = els.submitBtn.textContent;
    els.submitBtn.disabled = true;
    els.submitBtn.textContent = 'Submitting…';

    try {
        const proof = await prepareProof(selectedProof);
        await upload('/api/billing/payments', proof, {
            amount: els.amount.value,
            bill_id: els.billId.value,
            reference_number: els.reference.value,
            paid_at: els.paidAt.value,
            notes: els.notes.value,
        }, 'proof');

        els.paymentSuccess.hidden = false;
        els.reference.value = '';
        els.notes.value = '';
        els.paidAt.value = new Date().toISOString().slice(0, 10);
        resetProofPicker();
        await load();
    } catch (error) {
        const fieldErrors = error.errors || {};
        const knownFieldKeys = Object.keys(fieldErrors).filter((key) => FIELD_ERROR_MAP[key]);

        if (knownFieldKeys.length) {
            els.errorBanner.hidden = false;
            knownFieldKeys.forEach((key) => {
                const { input, error: errorEl, upload: isUpload } = FIELD_ERROR_MAP[key];
                errorEl.textContent = fieldErrors[key][0];
                errorEl.hidden = false;
                if (input) input.classList.add('has-error');
                if (isUpload) {
                    els.uploadTrigger.classList.add('has-error');
                    els.proofLabel.textContent = 'Re-attach File';
                }
            });
        } else {
            const messages = Object.values(fieldErrors).flat();
            els.formError.textContent = messages[0] || error.message || 'Unable to submit this payment.';
            els.formError.classList.add('visible');
        }
    } finally {
        if (!els.submitBtn.dataset.underReview) {
            els.submitBtn.disabled = false;
            els.submitBtn.textContent = submitLabel;
        }
    }
});

async function load(type = null, months = null) {
    payEarlyRevealed = false;
    draftPackage = false;
    try {
        const params = new URLSearchParams();
        if (type) params.set('type', type);
        if (months) params.set('months', String(months));
        const billingPath = params.toString() ? `/api/billing?${params}` : '/api/billing';
        const [billing, access] = await Promise.all([get(billingPath), get('/api/organization/sms/access')]);
        lastBilling = billing;
        renderCurrentPlan(billing);
        renderPlanPicker(billing, access.sms);
        lastAccess = access.sms;
        renderSmsRequest(access.sms);
        const timing = access.sms.request_status === 'pending' && billing.current_bill?.bill_type === 'package' ? ACTIVATION_TEXT[access.sms.activation_mode] : null;
        els.activationSummary.hidden = !timing;
        els.activationSummary.textContent = timing || '';
        lastBilling = billing;
        renderBill(billing);
        renderRejectedNotice(billing.latest_payment);
        renderHistory(billing.payments || []);
        els.loading.hidden = true;
        els.content.hidden = false;
    } catch (error) {
        els.loading.querySelector('p').textContent = error.message || 'Unable to load billing information.';
    }
}

async function choosePlan(type, months) {
    const pickers = [...els.typeButtons, ...els.monthButtons];
    pickers.forEach((item) => { item.disabled = true; });
    els.renewalChoiceStatus.textContent = 'Updating your bill…';
    clearFormErrors();
    try {
        await load(type, months);
    } catch (error) {
        els.formError.textContent = error.message || 'Unable to update your bill.';
        els.formError.classList.add('visible');
    } finally {
        pickers.forEach((item) => { item.disabled = false; });
    }
}

els.typeButtons.forEach((button) => {
    button.addEventListener('click', () => {
        const type = button.dataset.billType;
        if (type === selectedType && !draftPackage) return;
        // Base + SMS without a price yet: show the SMS questions first; there is no bill to pay until a price exists.
        if (type === 'package' && button.dataset.unavailable) {
            draftPackage = true;
            if (lastBilling) {
                renderPlanPicker(lastBilling, lastAccess);
                renderDraftPayment();
            }
            els.smsEstimate.querySelector('input')?.focus();
            return;
        }
        draftPackage = false;
        if (type === selectedType && lastBilling) {
            renderPlanPicker(lastBilling, lastAccess);
            renderBill(lastBilling);
            return;
        }
        choosePlan(type, selectedMonths);
    });
});

els.smsNowBtn.addEventListener('click', () => {
    if (selectedType === 'sms_now') return;
    choosePlan('sms_now', 1);
});

els.monthButtons.forEach((button) => {
    button.addEventListener('click', () => {
        const months = Number(button.dataset.months);
        if (months === selectedMonths) return;
        choosePlan(selectedType, months);
    });
});

els.paidAt.value = new Date().toISOString().slice(0, 10);
load();
