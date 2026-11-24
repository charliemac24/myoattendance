import React, { useEffect, useState } from 'react';
import { del, get, post, put } from '../services/api';
import { logout } from '../auth/auth';

const h = React.createElement;
const api = (path) => '/api/super-admin' + path;
const formatDate = (value) => value ? new Date(value).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
const formatMoney = (minor, currency) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: currency || 'PHP' }).format((minor || 0) / 100);

const svg = {
    home: (c) => `<svg width="18" height="18" viewBox="0 0 22 22" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M13.75 19.2493V11.9159C13.75 11.6728 13.6534 11.4397 13.4815 11.2678C13.3096 11.0958 13.0764 10.9993 12.8333 10.9993H9.16667C8.92355 10.9993 8.69039 11.0958 8.51849 11.2678C8.34658 11.4397 8.25 11.6728 8.25 11.9159V19.2493M2.75 9.16637C2.74994 8.89968 2.80806 8.63619 2.92031 8.39428C3.03255 8.15237 3.19623 7.93785 3.39992 7.76571L9.81658 2.26571C10.1475 1.98604 10.5667 1.8326 11 1.8326C11.4333 1.8326 11.8525 1.98604 12.1834 2.26571L18.6001 7.76571C18.8038 7.93785 18.9674 8.15237 19.0797 8.39428C19.1919 8.63619 19.2501 8.89968 19.25 9.16637V17.4164C19.25 17.9026 19.0568 18.3689 18.713 18.7127C18.3692 19.0566 17.9029 19.2497 17.4167 19.2497H4.58333C4.0971 19.2497 3.63079 19.0566 3.28697 18.7127C2.94315 18.3689 2.75 17.9026 2.75 17.4164V9.16637Z" stroke="${c}" stroke-width="2" stroke-linecap="round"/></svg>`,
    grid: (c) => `<svg width="18" height="18" viewBox="0 0 15.0012 15.0012" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1.87515 5.62545H13.1261M1.87515 9.37575H13.1261M5.62545 1.87515V13.1261M9.37575 1.87515V13.1261M3.12525 1.87515H11.876C12.5664 1.87515 13.1261 2.43484 13.1261 3.12525V11.876C13.1261 12.5664 12.5664 13.1261 11.876 13.1261H3.12525C2.43484 13.1261 1.87515 12.5664 1.87515 11.876V3.12525C1.87515 2.43484 2.43484 1.87515 3.12525 1.87515Z" stroke="${c}" stroke-width="2" stroke-linecap="round"/></svg>`,
    mail: (c) => `<svg width="18" height="18" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M36.668 11.6675L21.6818 21.2115C21.1732 21.5069 20.5956 21.6624 20.0075 21.6624C19.4194 21.6624 18.8417 21.5069 18.3332 21.2115L3.332 11.6675M6.6656 6.668H33.3344C35.1755 6.668 36.668 8.16023 36.668 10.001V29.999C36.668 31.8398 35.1755 33.332 33.3344 33.332H6.6656C4.8245 33.332 3.332 31.8398 3.332 29.999V10.001C3.332 8.16023 4.8245 6.668 6.6656 6.668Z" stroke="${c}" stroke-width="2.4" stroke-linecap="round"/></svg>`,
    card: (c) => `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="1" y="4" width="22" height="16" rx="2.5" stroke="${c}" stroke-width="2"/><path d="M1 10H23" stroke="${c}" stroke-width="2"/></svg>`,
    check: (c) => `<svg width="21" height="21" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18.1662 8.33323C18.5468 10.2009 18.2755 12.1426 17.3978 13.8345C16.52 15.5264 15.0887 16.8663 13.3427 17.6306C11.5966 18.395 9.64124 18.5376 7.80271 18.0348C5.96418 17.532 4.3536 16.4141 3.23955 14.8675C2.1255 13.3209 1.57532 11.4391 1.68077 9.53601C1.78622 7.63287 2.54091 5.82341 3.819 4.40937C5.09709 2.99533 6.82132 2.06218 8.70415 1.76555C10.587 1.46891 12.5146 1.82672 14.1655 2.77929M7.49947 9.16613L9.99936 11.666L18.3324 3.33304" stroke="${c}" stroke-width="2" stroke-linecap="round"/></svg>`,
    clock: (c) => `<svg width="21" height="21" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 4.9996V10L13.3336 11.6668M18.334 10C18.334 14.6027 14.6027 18.334 10 18.334C5.39726 18.334 1.666 14.6027 1.666 10C1.666 5.39726 5.39726 1.666 10 1.666C14.6027 1.666 18.334 5.39726 18.334 10Z" stroke="${c}" stroke-width="2" stroke-linecap="round"/></svg>`,
    logout: (c) => `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" stroke="${c}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 17l5-5-5-5" stroke="${c}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12H9" stroke="${c}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
    search: (c) => `<svg width="15" height="15" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7.11133 1C10.4862 1.00015 13.2217 3.73638 13.2217 7.11133C13.2215 10.4861 10.4861 13.2215 7.11133 13.2217C3.73638 13.2217 1.00015 10.4862 1 7.11133C1 3.73629 3.73629 1 7.11133 1Z" stroke="${c}" stroke-width="2" stroke-linecap="round"/><path d="M14 14L11.5 11.5" stroke="${c}" stroke-width="2" stroke-linecap="round"/></svg>`,
    x: (c) => `<svg width="14" height="14" viewBox="0 0 12.6 12.6" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9.45 3.15L3.15 9.45M3.15 3.15L9.45 9.45" stroke="${c}" stroke-width="2" stroke-linecap="round"/></svg>`,
    plus: (c) => `<svg width="14" height="14" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2.4996 6H9.5004M6 2.4996V9.5004" stroke="${c}" stroke-width="2.5" stroke-linecap="round"/></svg>`,
    chevronRight: (c) => `<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7.5 15L12.5 10L7.5 5" stroke="${c}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
    refresh: (c) => `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="${c}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>`,
};

const activationLabel = (mode) => ({ immediate: 'Immediately (ends trial)', after_trial: 'After trial', next_payment: 'With next payment' }[mode] || 'With next payment');
const DAYS_LABEL = { weekdays: 'weekdays', mon_sat: 'Mon–Sat', every_day: 'every day' };
const estimateText = (estimate) => estimate ? `${estimate.people} people · ${estimate.texts_per_day} text${estimate.texts_per_day === 1 ? '' : 's'}/day · ${DAYS_LABEL[estimate.days] || estimate.days}` : '';
const billTypeLabel = (bill) => { if (bill?.bill_type === 'sms_now') return 'Add SMS · days left in paid Base (one-time)'; if (bill?.bill_type === 'sms') return 'SMS add-on (old plan)'; const plan = bill?.bill_type === 'package' || (!bill?.bill_type && bill?.items?.some((item) => item.type === 'sms_addon')) ? 'Base + SMS' : 'Base'; const months = bill?.months || 1; return `${plan} · ${months} ${months === 1 ? 'month' : 'months'}`; };

function Icon({ name, color = 'currentColor', className }) {
    return h('span', { className: 'sa-icon' + (className ? ' ' + className : ''), dangerouslySetInnerHTML: { __html: svg[name](color) } });
}

function Status({ value, tone }) {
    return h('span', { className: 'sa-status sa-status--' + (tone || value) }, value);
}

const STAT_ICON = { blue: 'grid', green: 'check', amber: 'clock', red: 'x' };

// A dashboard card; with onClick it opens the Organizations list already filtered to the organizations it counts.
function Stat({ label, value, tone, hint, onClick }) {
    const body = [h('div', { key: 'icon', className: 'sa-stat-icon sa-stat-icon--' + tone }, h(Icon, { name: STAT_ICON[tone] || 'grid' })), h('div', { key: 'text' }, h('p', { className: 'sa-stat-label' }, label), h('p', { className: 'sa-stat-value' }, value), hint && h('p', { className: 'sa-stat-hint' }, hint))];
    return onClick ? h('button', { type: 'button', className: 'sa-stat sa-stat--link', onClick, title: 'Show these organizations' }, body) : h('div', { className: 'sa-stat' }, body);
}

const planStatusText = (plan) => ({ trialing: 'Free trial', active: 'Active', expired: 'Expired' })[plan?.status] || (plan?.status || 'Unknown');
const planDateText = (plan) => !plan ? ''
    : plan.status === 'expired' ? 'Not paid'
    : plan.base_paid_through ? 'Paid through ' + formatDate(plan.base_paid_through)
    : plan.trial_ends_at ? 'Trial ends ' + formatDate(plan.trial_ends_at) : '';
const smsStatusText = (sms) => sms?.state === 'on' ? 'On' : sms?.state === 'scheduled' ? 'Scheduled' : 'Off';
const smsDateText = (sms, resumableUntil) => sms?.state === 'on' ? (sms.ends_at ? 'Until ' + formatDate(sms.ends_at) : 'No end date')
    : sms?.state === 'scheduled' ? 'Starts ' + formatDate(sms.starts_at) + (sms.ends_at ? ', until ' + formatDate(sms.ends_at) : '')
    : resumableUntil ? 'Turned off, paid until ' + formatDate(resumableUntil) : 'Not paid';

function planDetail(plan) {
    if (!plan) return '';
    if (plan.pending_payment) return `Payment under review: ${plan.pending_payment.plan_label}${plan.pending_payment.plan === 'sms_now' ? '' : ', ' + plan.pending_payment.months + ' mo'}`;
    if (plan.status === 'expired') return 'Needs renewal';
    if (plan.base_paid_through) return 'Paid through ' + formatDate(plan.base_paid_through);
    if (plan.status === 'trialing') return 'Trial ends ' + formatDate(plan.trial_ends_at);
    return '';
}

// Organizations list filters: Scan plan status, SMS status and industry, combined with the search box.
const ORG_FILTERS = [
    ['plan', 'Scan: all', [['trialing', 'Free trial'], ['active', 'Active'], ['expiring_soon', 'Ends within 7 days'], ['expired', 'Expired'], ['expired_with_sms', 'Expired, had SMS'], ['expired_base_only', 'Expired, Base only'], ['under_review', 'Payment under review']], 'Scan'],
    ['sms', 'SMS: all', [['on', 'On'], ['scheduled', 'Scheduled'], ['off', 'Off'], ['price_requested', 'Custom price requested']], 'SMS'],
    ['type', 'All industries', [['school', 'School'], ['fitness', 'Fitness / Gym'], ['other', 'Other']], 'Industry'],
];
const orgFilterSelects = (filters, setFilters) => ORG_FILTERS.map(([key, allLabel, options, prefix]) => h('select', { key, 'aria-label': prefix, value: filters[key], onChange: (event) => setFilters((current) => ({ ...current, [key]: event.target.value })) },
    h('option', { value: '' }, allLabel), options.map(([value, label]) => h('option', { key: value, value }, (key === 'type' ? '' : prefix + ': ') + label))));

function OrganizationTable({ rows, loading, onSelect }) {
    const headings = ['Organization', 'Owner', 'People', 'Scan plan', 'SMS', 'SMS dates', ''];
    const body = loading
        ? h('tr', null, h('td', { colSpan: 7, className: 'sa-empty' }, 'Loading organizations…'))
        : rows.length
            ? rows.map((row) => h('tr', { className: 'sa-row', key: row.id, onClick: () => onSelect(row.id) },
                h('td', null, h('strong', null, row.name), h('small', null, (row.type || 'Organization') + ' · #' + row.id)),
                h('td', null, row.owner ? h('div', null, h('strong', null, row.owner.name), h('small', null, row.owner.email)) : '—'),
                h('td', null, row.people_count || 0),
                h('td', null, h('strong', null, row.current_plan?.label || '—'), h('small', null, planDetail(row.current_plan))),
                h('td', null, h(Status, { value: smsStatusText(row.current_plan?.sms), tone: { on: 'active', scheduled: 'scheduled' }[row.current_plan?.sms?.state] || 'disabled' })),
                h('td', null, smsDateText(row.current_plan?.sms, row.sms_resumable_until)), h('td', { className: 'sa-arrow' }, h(Icon, { name: 'chevronRight' }))))
            : h('tr', null, h('td', { colSpan: 7, className: 'sa-empty' }, 'No organizations match your search.'));
    return h('div', { className: 'sa-table-wrap' }, h('table', { className: 'sa-table' },
        h('thead', null, h('tr', null, headings.map((heading) => h('th', { key: heading }, heading)))),
        h('tbody', null, body)));
}

const paymentMethods = [['cash', 'Cash'], ['gcash', 'GCash'], ['bank_transfer', 'Bank transfer']];
const methodLabel = (method) => (paymentMethods.find(([value]) => value === method) || [null, 'GCash'])[1];
// Payments typed in with "Record payment" say how they were paid and who recorded them; submitted ones show their reference.
const paymentSource = (payment) => payment.manual_entry
    ? `${methodLabel(payment.payment_method)} · recorded by ${payment.recorded_by?.name || payment.reviewed_by?.name || 'Super Admin'}${payment.reference_number ? ' · Ref ' + payment.reference_number : ''}${payment.notes ? ' · ' + payment.notes : ''}`
    : payment.reference_number || 'No reference';

function PaymentRows({ payments, onEditCash }) {
    return payments?.length ? payments.map((payment) => h('div', { className: 'sa-payment', key: payment.id },
        h('div', null, h('strong', null, payment.organization?.name || 'Organization'), h('small', null, formatDate(payment.paid_at) + ' · ' + paymentSource(payment))),
        h('div', { style: { display: 'flex', alignItems: 'center', gap: '12px' } },
            onEditCash && payment.manual_entry && h('button', { type: 'button', className: 'sa-link-button', onClick: () => onEditCash(payment) }, 'Edit'),
            h('strong', null, formatMoney(payment.amount_minor, payment.currency))))) : h('p', { className: 'sa-muted' }, 'No payments recorded yet.');
}

/**
 * Short, scannable lines saying exactly what approving a payment does (mirrors the server's approval rules).
 */
function approvalLines(detail) {
    const bill = detail.payment?.bill;
    if (!bill) return [];
    const plan = detail.current_plan || {};
    const today = new Date().toISOString().slice(0, 10);
    const start = String(bill.billing_period_start).slice(0, 10);
    const nextDay = (value) => { const [y, m, d] = String(value).slice(0, 10).split('-').map(Number); return new Date(Date.UTC(y, m - 1, d + 1)).toISOString().slice(0, 10); };
    const startsNow = start <= today;
    const startText = startsNow ? 'today' : formatDate(start);
    const until = formatDate(nextDay(bill.billing_period_end));
    if (bill.bill_type === 'sms_now') return [`SMS on from today until ${until}`, 'Base unchanged'];
    const name = bill.bill_type === 'package' ? 'Base + SMS' : 'Base';
    const lines = [`${name} starts ${startText}, through ${formatDate(bill.billing_period_end)}`];
    if (plan.status === 'trialing' && !plan.base_paid_through) lines.push(startsNow ? 'Ends the free trial today' : 'Starts when the free trial ends');
    if (bill.bill_type === 'package' && !['on', 'scheduled'].includes(plan.sms?.state)) lines.push(`SMS turns on ${startText}`);
    if (bill.bill_type === 'base' && ['on', 'scheduled'].includes(plan.sms?.state) && plan.sms.ends_at) lines.push(`SMS stops after ${formatDate(plan.sms.ends_at)}`);
    return lines;
}

function PaymentReview({ paymentId, onClose, onDone }) {
    const [detail, setDetail] = useState(null); const [reason, setReason] = useState(''); const [error, setError] = useState(null); const [saving, setSaving] = useState(false);
    const load = async () => { try { setDetail(await get(api('/billing/payments/' + paymentId))); } catch (caught) { setError(caught.message); } };
    useEffect(() => { load(); }, [paymentId]);
    if (!detail) return h('aside', { className: 'sa-drawer' }, h('div', { className: 'sa-drawer-head' }, h('h2', null, 'Review payment'), h('button', { className: 'sa-icon-button', onClick: onClose }, h(Icon, { name: 'x' }))), h('p', { className: 'sa-muted' }, error || 'Loading payment details…'));
    const { payment, subscription } = detail; const bill = payment.bill; const includesSms = bill?.items?.some((item) => item.type === 'sms_addon'); const paymentScope = bill ? billTypeLabel(bill) : payment.payment_type === 'base_subscription' ? 'Base subscription only' : 'SMS add-on'; const activationMode = includesSms ? (detail.sms_activation_request?.status === 'pending' ? activationLabel(detail.sms_activation_request.activation_mode) : 'With this payment') : 'Not applicable'; const previousAttempts = bill?.payments?.filter((attempt) => attempt.id !== payment.id) || []; const expected = bill?.total_minor ?? detail.expected_base_amount_minor; const mismatch = payment.amount_minor !== expected;
    const onApproval = approvalLines(detail);
    const activatesLabel = detail.activates_label || 'Scan only';
    const activate = async () => { if (!window.confirm(`Activate ${activatesLabel} for ${payment.organization?.name}?\n\n${formatMoney(payment.amount_minor, payment.currency)} received.\n${onApproval.join('\n')}`)) return; setSaving(true); setError(null); try { await post(api('/billing/payments/' + payment.id + '/activate'), {}); await onDone(); } catch (caught) { setError(caught.message); } finally { setSaving(false); } };
    const reject = async () => { if (!reason.trim()) { setError('A rejection reason is required.'); return; } if (!window.confirm('Reject this payment?')) return; setSaving(true); setError(null); try { await post(api('/billing/payments/' + payment.id + '/reject'), { reason }); await onDone(); } catch (caught) { setError(caught.message); } finally { setSaving(false); } };
    const requestProof = async () => { if (!reason.trim()) { setError('A reason is required to request new proof.'); return; } setSaving(true); setError(null); try { await post(api('/billing/payments/' + payment.id + '/request-proof'), { reason }); await onDone(); } catch (caught) { setError(caught.message); } finally { setSaving(false); } };
    const plan = detail.current_plan || {};
    const smsRequest = detail.sms_activation_request?.status === 'pending' ? detail.sms_activation_request : null;
    const startChoice = plan.in_trial ? plan.start_choice : null;
    const fact = (label, value, sub) => h('div', null, h('small', null, label), h('strong', null, value), sub ? h('span', null, sub) : null);
    return h('aside', { className: 'sa-drawer sa-review' },
        h('div', { className: 'sa-drawer-head' }, h('div', null, h('p', { className: 'sa-eyebrow' }, 'PAYMENT REVIEW'), h('h2', null, payment.organization?.name || 'Payment')), h('button', { className: 'sa-icon-button', onClick: onClose, 'aria-label': 'Close' }, h(Icon, { name: 'x' }))),
        error && h('div', { className: 'sa-error' }, error),
        h('div', { className: 'sa-review-summary' },
            h('div', { className: 'sa-review-amount' }, h('strong', null, formatMoney(payment.amount_minor, payment.currency)), h(Status, { value: payment.status })),
            h('p', null, paymentScope),
            h('p', { className: 'sa-activates' }, h('small', null, payment.status === 'approved' ? 'Activated' : 'Activate turns on'), ' ', h('strong', null, activatesLabel)),
            h('p', { className: 'sa-muted' }, `GCash ${payment.reference_number || '—'} · paid ${formatDate(payment.paid_at)} · by ${payment.submitted_by?.name || '—'}`)),
        mismatch && h('div', { className: 'sa-error' }, `Amount should be ${formatMoney(expected, payment.currency)}.`),
        onApproval.length > 0 && payment.status === 'pending' && h('div', { className: 'sa-review-box' }, h('small', null, 'When you press Activate'), ...onApproval.map((line) => h('p', { key: line }, line))),
        h('div', { className: 'sa-detail-grid' },
            fact('Current plan', plan.label || subscription.status, planDetail({ ...plan, pending_payment: null })),
            fact('Covers', bill ? `${formatDate(bill.billing_period_start)} – ${formatDate(bill.billing_period_end)}` : '—', bill && bill.items.length > 1 ? bill.items.map((item) => `${item.description.replace(/ Subscription| Notifications.*/, '')} ${formatMoney(item.amount_minor, bill.currency)}`).join(' + ') : null)),
        (startChoice || smsRequest || (includesSms && detail.sms_price_source === 'estimate')) && h('div', { className: 'sa-review-tags' },
            includesSms && detail.sms_price_source === 'estimate' && h('span', { className: 'sa-tag' }, `Estimated SMS price: ${estimateText(detail.sms_estimate)}`),
            startChoice && h('span', { className: 'sa-tag' }, startChoice === 'immediate' ? 'School wants to start now (ends trial early)' : 'School wants to start after its trial'),
            smsRequest && !includesSms && h('span', { className: 'sa-tag sa-tag--warning' }, detail.sms_price_set ? 'SMS requested · not in this payment' : 'SMS requested · price not set')),
        payment.notes && h('p', { className: 'sa-review-note' }, `Note: ${payment.notes}`),
        payment.status === 'rejected' && payment.rejection_reason && h('div', { className: 'sa-error' }, `Rejected: ${payment.rejection_reason}`),
        h('div', { className: 'sa-section' }, payment.proof_path
            ? h(React.Fragment, null, h('img', { className: 'sa-proof-preview', src: api('/billing/payments/' + payment.id + '/proof/view'), alt: 'Uploaded payment proof', onError: (event) => { event.currentTarget.style.display = 'none'; } }), h('a', { className: 'sa-link-button', href: api('/billing/payments/' + payment.id + '/proof'), target: '_blank', rel: 'noreferrer' }, 'Open receipt'))
            : h('p', { className: 'sa-muted' }, 'No receipt attached.')),
        previousAttempts.length > 0 && h('details', { className: 'sa-review-history' }, h('summary', null, `Earlier attempts (${previousAttempts.length})`), ...previousAttempts.map((attempt) => h('p', { key: attempt.id }, `${formatDate(attempt.paid_at)} · ${formatMoney(attempt.amount_minor, attempt.currency)} · ${attempt.reference_number || 'No reference'} · ${attempt.status}${attempt.rejection_reason ? ' — ' + attempt.rejection_reason : ''}`))),
        payment.status === 'pending' && h('div', { className: 'sa-review-actions-wrap' },
            h('label', { className: 'sa-review-reason' }, 'Reason (needed to reject or ask for a new receipt)', h('textarea', { value: reason, onChange: (event) => setReason(event.target.value), rows: 2 })),
            h('div', { className: 'sa-review-actions' },
                h('button', { className: 'sa-button sa-button--danger', disabled: saving, onClick: reject }, 'Reject'),
                h('button', { className: 'sa-button sa-button--ghost', disabled: saving, onClick: requestProof }, 'Ask for new receipt'),
                h('button', { className: 'sa-button', disabled: saving, onClick: activate, title: 'Turns on ' + activatesLabel }, saving ? 'Activating…' : 'Activate'))));
}

function PaymentReviewTable({ payments, loading, onReview }) {
    if (loading) return h('div', { className: 'sa-empty' }, 'Loading payments…');
    if (!payments.length) return h('div', { className: 'sa-empty' }, 'No payments match this view.');
    return h('div', { className: 'sa-table-wrap' }, h('table', { className: 'sa-table' }, h('thead', null, h('tr', null, ['Organization', 'Payment for', 'Amount', 'Payment date', 'Reference', 'Status', 'Action'].map((label) => h('th', { key: label }, label)))), h('tbody', null, payments.map((payment) => h('tr', { className: 'sa-row', key: payment.id }, h('td', null, h('strong', null, payment.organization?.name || 'Organization')), h('td', null, h('strong', null, payment.activates_label || 'Scan only'), h('small', null, payment.bill ? billTypeLabel(payment.bill) : 'Base only')), h('td', null, formatMoney(payment.amount_minor, payment.currency)), h('td', null, formatDate(payment.paid_at)), h('td', null, payment.manual_entry ? paymentSource(payment) : methodLabel(payment.payment_method) + ' ' + (payment.reference_number || '—')), h('td', null, h(Status, { value: payment.status })), h('td', null, h('button', { className: 'sa-button sa-button--small', onClick: () => onReview(payment.id) }, payment.status === 'pending' ? 'Review' : 'View')))))));
}


function Drawer({ organization, onClose, onRefresh }) {
    const [mode, setMode] = useState(null); const [error, setError] = useState(null); const [saving, setSaving] = useState(false);
    const [values, setValues] = useState({ amount: '', reference_number: '', paid_at: new Date().toISOString().slice(0, 16), coverage_start: '', coverage_end: '', notes: '', sender_name: organization.sms_sender_name || '', sms_monthly_price: organization.sms_pricing ? (organization.sms_pricing.sms_monthly_price_minor / 100).toFixed(2) : '', pricing_effective_from: '', pricing_notes: organization.sms_pricing?.notes || '' });
    const update = (key) => (event) => setValues((current) => ({ ...current, [key]: event.target.value }));
    const openMode = setMode;
    // Record payment (cash, GCash or bank transfer the school didn't submit): the plan picks the school's real bill.
    const [cash, setCash] = useState({ payment_method: 'cash', reference_number: '', plan: null, months: 1, received_at: new Date().toISOString().slice(0, 10), notes: '', amount: '', start_sms_today: true });
    const [editing, setEditing] = useState(null);
    const editCash = (payment) => { setEditing({ id: payment.id, payment_method: payment.payment_method, reference_number: payment.reference_number || '', amount: (payment.amount_minor / 100).toFixed(2), received_at: String(payment.paid_at).slice(0, 10), notes: payment.notes || '' }); setMode('edit_cash'); };
    const [cashQuote, setCashQuote] = useState(null);
    useEffect(() => {
        if (mode !== 'cash') return;
        let cancelled = false;
        setCashQuote(null);
        get(api('/organizations/' + organization.id + '/recorded-payments/quote?' + new URLSearchParams({ ...(cash.plan ? { plan: cash.plan } : {}), months: cash.months })))
            .then((quote) => { if (!cancelled) { setCashQuote(quote); setCash((current) => ({ ...current, plan: current.plan || quote.plan, amount: quote.bill ? (quote.bill.total_minor / 100).toFixed(2) : current.amount })); } })
            .catch((caught) => { if (!cancelled) setCashQuote({ error: caught.message }); });
        return () => { cancelled = true; };
    }, [mode, cash.plan, cash.months]);
    const submit = async (event) => {
        event.preventDefault(); setSaving(true); setError(null);
        try {
            if (mode === 'cash') await post(api('/organizations/' + organization.id + '/recorded-payments'), { payment_method: cash.payment_method, reference_number: cash.reference_number || null, plan: cash.plan, months: cash.months, received_at: cash.received_at, notes: cash.notes || null, amount: cash.amount, start_sms_today: !!(cashQuote?.sms_can_start_today && cash.start_sms_today) });
            if (mode === 'disable') await post(api('/organizations/' + organization.id + '/sms/disable'), {});
            if (mode === 'edit_cash') await put(api('/recorded-payments/' + editing.id), { payment_method: editing.payment_method, reference_number: editing.reference_number || null, amount: editing.amount, received_at: editing.received_at, notes: editing.notes || null });
            if (mode === 'resume') await post(api('/organizations/' + organization.id + '/sms/resume'), {});
            if (mode === 'sender_name') await put(api('/organizations/' + organization.id + '/sms-sender-name'), { sender_name: values.sender_name || null });
            if (mode === 'sms_pricing') await put(api('/organizations/' + organization.id + '/sms-pricing'), { sms_monthly_price: values.sms_monthly_price, effective_from: values.pricing_effective_from || null, notes: values.pricing_notes || null });
            if (mode === 'send_quote') {
                const request = organization.sms_requests?.find((item) => item.status === 'pending');
                if (!request) throw new Error('No pending SMS request found.');
                await post(api('/sms-requests/' + request.id + '/send-quote'), {});
            }
            if (mode === 'delete_attendance') await del(api('/organizations/' + organization.id + '/attendance/current-day'));
            setMode(null); await onRefresh();
        } catch (caught) { setError(caught.message); } finally { setSaving(false); }
    };
    const field = (label, key, type, required) => h('label', { key }, label, h('input', { type: type || 'text', value: values[key], onChange: update(key), required: !!required }));
    const cashPlans = [['base', 'Base (Scan only)'], ['package', 'Base + SMS (Scan + SMS)'], ['sms_now', 'Add SMS (days left in paid Base)']];
    const cashAmountMinor = Math.round(Number(cash.amount) * 100);
    const cashAmountValid = /^\d+(\.\d{1,2})?$/.test(cash.amount || '') && cashAmountMinor > 0;
    const cashBlocked = !cashQuote || cashQuote.error || cashQuote.under_review || !cashAmountValid;
    const editForm = editing && h('form', { className: 'sa-form', onSubmit: submit },
            h('h3', null, 'Edit recorded payment'),
            h('p', null, 'Fix how it was paid, the reference, amount, date received or note. The plan and the dates it turned on do not change. If the wrong plan was recorded, tell the school\'s owner and record the right plan as a new payment.'),
            h('label', null, 'Paid by', h('select', { value: editing.payment_method, onChange: (event) => setEditing((current) => ({ ...current, payment_method: event.target.value })) }, paymentMethods.map(([value, label]) => h('option', { key: value, value }, label)))),
            h('label', null, editing.payment_method === 'cash' ? 'Reference (optional, e.g. OR number)' : 'Reference number (optional)', h('input', { type: 'text', maxLength: 128, value: editing.reference_number, onChange: (event) => setEditing((current) => ({ ...current, reference_number: event.target.value })) })),
            h('label', null, 'Amount received (PHP)', h('input', { type: 'number', min: '0.01', step: '0.01', required: true, value: editing.amount, onChange: (event) => setEditing((current) => ({ ...current, amount: event.target.value })) })),
            h('label', null, 'Date received', h('input', { type: 'date', required: true, max: new Date().toISOString().slice(0, 10), value: editing.received_at, onChange: (event) => setEditing((current) => ({ ...current, received_at: event.target.value })) })),
            h('label', null, 'Note (optional)', h('input', { type: 'text', maxLength: 1000, value: editing.notes, onChange: (event) => setEditing((current) => ({ ...current, notes: event.target.value })) })),
            h('div', { className: 'sa-form-actions' }, h('button', { type: 'button', className: 'sa-button sa-button--ghost', onClick: () => setMode(null) }, 'Cancel'), h('button', { className: 'sa-button', disabled: saving || !(Number(editing.amount) > 0) }, saving ? 'Saving…' : 'Save changes')));
    const form = mode === 'edit_cash' && editForm ? editForm : mode === 'cash' ? h('form', { className: 'sa-form', onSubmit: submit },
            h('h3', null, 'Record payment'),
            h('p', null, 'Use this when the school paid you directly (cash, GCash or bank transfer) and did not submit it on their Billing page. Saving records the payment and turns on what it pays for right away, the same as Activate.'),
            h('label', null, 'Paid by', h('select', { value: cash.payment_method, onChange: (event) => setCash((current) => ({ ...current, payment_method: event.target.value })) }, paymentMethods.map(([value, label]) => h('option', { key: value, value }, label)))),
            h('label', null, cash.payment_method === 'cash' ? 'Reference (optional, e.g. OR number)' : (cash.payment_method === 'gcash' ? 'GCash reference number' : 'Bank reference number') + ' (optional)', h('input', { type: 'text', maxLength: 128, value: cash.reference_number, onChange: (event) => setCash((current) => ({ ...current, reference_number: event.target.value })) })),
            h('label', null, 'Plan', h('select', { value: cash.plan || '', onChange: (event) => setCash((current) => ({ ...current, plan: event.target.value })) },
                cashPlans.map(([value, label]) => h('option', { key: value, value, disabled: cashQuote?.available_plans && !cashQuote.available_plans[value] }, label + (cashQuote?.available_plans && !cashQuote.available_plans[value] ? ' · not available' : ''))))),
            cash.plan !== 'sms_now' && h('label', null, 'Months', h('select', { value: cash.months, onChange: (event) => setCash((current) => ({ ...current, months: Number(event.target.value) })) }, [1, 3, 6, 12].map((months) => h('option', { key: months, value: months }, months + (months === 1 ? ' month' : ' months'))))),
            h('label', null, 'Date received', h('input', { type: 'date', value: cash.received_at, max: new Date().toISOString().slice(0, 10), required: true, onChange: (event) => setCash((current) => ({ ...current, received_at: event.target.value })) })),
            h('label', null, 'Amount received (PHP)',
                h('input', { type: 'number', min: '0.01', step: '0.01', required: true, value: cash.amount, onChange: (event) => setCash((current) => ({ ...current, amount: event.target.value })) }),
                cashQuote?.bill && cashAmountValid && cashAmountMinor !== cashQuote.bill.total_minor && h('small', { className: 'sa-muted' }, `List price ${formatMoney(cashQuote.bill.total_minor, cashQuote.bill.currency)}. The full plan is still turned on.`),
                cash.amount !== '' && !cashAmountValid && h('small', { className: 'sa-muted' }, 'Enter an amount above zero.')),
            h('label', null, 'Note (optional)', h('input', { type: 'text', value: cash.notes, maxLength: 1000, onChange: (event) => setCash((current) => ({ ...current, notes: event.target.value })) })),
            h('div', { className: 'sa-review-box' },
                !cashQuote ? h('p', null, 'Loading the amount…')
                    : cashQuote.error ? h('p', null, cashQuote.error)
                    : h(React.Fragment, null,
                        h('p', null, h('strong', null, `List price ${formatMoney(cashQuote.bill.total_minor, cashQuote.bill.currency)}`), ` · turns on ${cashQuote.activates_label}`),
                        h('p', null, `Covers ${formatDate(cashQuote.bill.billing_period_start)} – ${formatDate(cashQuote.bill.billing_period_end)}`),
                        cashQuote.starts_on > new Date().toISOString().slice(0, 10) && h('p', null, `Starts ${formatDate(cashQuote.starts_on)}, when the school's current paid Base ends.`),
                        cashQuote.sms_can_start_today && h('label', { style: { display: 'flex', gap: '8px', alignItems: 'flex-start', fontWeight: 600 } },
                            h('input', { type: 'checkbox', checked: cash.start_sms_today, onChange: (event) => setCash((current) => ({ ...current, start_sms_today: event.target.checked })) }),
                            `Also start SMS today (free until ${formatDate(cashQuote.starts_on)}). Untick to start SMS on ${formatDate(cashQuote.starts_on)}. To charge for SMS days now, choose Add SMS instead.`),
                        cashQuote.under_review && h('p', null, 'This school already sent a payment for this plan. Activate or reject it in Payments first.'))),
            h('div', { className: 'sa-form-actions' }, h('button', { type: 'button', className: 'sa-button sa-button--ghost', onClick: () => setMode(null) }, 'Cancel'), h('button', { className: 'sa-button', disabled: saving || cashBlocked }, saving ? 'Saving…' : 'Record payment and activate'))) :
        mode === 'sender_name' ? h('form', { className: 'sa-form', onSubmit: submit }, h('h3', null, 'SMS sender name'), h('p', null, 'Use 1–11 letters or numbers. Leave blank to use the .env default.'), field('Sender name', 'sender_name', 'text'), h('div', { className: 'sa-form-actions' }, h('button', { type: 'button', className: 'sa-button sa-button--ghost', onClick: () => setMode(null) }, 'Cancel'), h('button', { className: 'sa-button', disabled: saving }, saving ? 'Saving…' : 'Save sender name'))) :
        mode === 'sms_pricing' ? h('form', { className: 'sa-form', onSubmit: submit }, h('h3', null, organization.sms_pricing ? 'Change SMS price' : 'Set SMS price'), h('p', null, `${organization.active_people_count || 0} active people. The new price applies to bills that start on or after the date below (leave it blank for today). Periods already paid, and payments under review, keep their old price. The school sees the new price on its Billing page.`), organization.sms_pricing && h('p', { className: 'sa-muted' }, `Current price: ${formatMoney(organization.sms_pricing.sms_monthly_price_minor, organization.sms_pricing.currency)}/month`), field('New monthly SMS price (PHP)', 'sms_monthly_price', 'number', true), field('Applies from', 'pricing_effective_from', 'date', false), h('label', null, 'Agreement notes', h('textarea', { value: values.pricing_notes, onChange: update('pricing_notes'), rows: 3 })), h('div', { className: 'sa-form-actions' }, h('button', { type: 'button', className: 'sa-button sa-button--ghost', onClick: () => setMode(null) }, 'Cancel'), h('button', { className: 'sa-button', disabled: saving }, saving ? 'Saving…' : 'Save pricing'))) :
        mode === 'send_quote' ? h('form', { className: 'sa-form', onSubmit: submit }, h('h3', null, 'Send payment instructions'), h('p', null, 'The agreed SMS price is already visible in the owner billing screen. This marks the quote as ready so the owner can submit the combined base subscription + SMS payment.'), h('div', { className: 'sa-form-actions' }, h('button', { type: 'button', className: 'sa-button sa-button--ghost', onClick: () => setMode(null) }, 'Cancel'), h('button', { className: 'sa-button', disabled: saving }, saving ? 'Sending…' : 'Send payment instructions'))) :
        mode === 'delete_attendance' ? h('form', { className: 'sa-form', onSubmit: submit }, h('h3', null, 'Delete today’s attendance?'), h('p', null, 'This permanently deletes this organization’s attendance events for the current organization-local day, related scan request records, and queued SMS records linked to those events.'), h('div', { className: 'sa-form-actions' }, h('button', { type: 'button', className: 'sa-button sa-button--ghost', onClick: () => setMode(null) }, 'Cancel'), h('button', { className: 'sa-button sa-button--danger', disabled: saving }, saving ? 'Deleting…' : 'Delete today’s attendance'))) :
        mode === 'resume' ? h('form', { className: 'sa-form', onSubmit: submit }, h('h3', null, 'Turn SMS back on?'), h('p', null, `SMS turns on now until ${formatDate(organization.sms_resumable_until)}, the end of the SMS time the school already paid for. No new payment is needed.`), h('div', { className: 'sa-form-actions' }, h('button', { type: 'button', className: 'sa-button sa-button--ghost', onClick: () => setMode(null) }, 'Cancel'), h('button', { className: 'sa-button', disabled: saving }, saving ? 'Turning on…' : 'Turn SMS back on'))) :
        h('form', { className: 'sa-form', onSubmit: submit }, h('h3', null, 'Turn SMS off now?'), h('p', null, `SMS texts to parents stop right away${organization.sms_expires_at ? ' (it was paid through ' + formatDate(organization.sms_expires_at) + ')' : ''}. Scan and attendance keep working. Payments are not changed or refunded. To turn SMS back on, press Activate on a new SMS payment in Payments.`), h('div', { className: 'sa-form-actions' }, h('button', { type: 'button', className: 'sa-button sa-button--ghost', onClick: () => setMode(null) }, 'Cancel'), h('button', { className: 'sa-button sa-button--danger' }, 'Disable SMS')));
    const details = h(React.Fragment, null,
        // Scan (Base) and SMS are separate: show each one's real state and end date.
        h('div', { className: 'sa-detail-status', style: { justifyContent: 'flex-start', gap: '10px', marginBottom: '8px' } }, h('strong', { style: { width: '44px', color: '#374151' } }, 'Scan'), h(Status, { value: planStatusText(organization.current_plan), tone: { trialing: 'scheduled', active: 'active' }[organization.current_plan?.status] || 'expired' }), h('span', null, planDateText(organization.current_plan))),
        h('div', { className: 'sa-detail-status', style: { justifyContent: 'flex-start', gap: '10px', marginBottom: '20px' } }, h('strong', { style: { width: '44px', color: '#374151' } }, 'SMS'), h(Status, { value: smsStatusText(organization.current_plan?.sms), tone: { on: 'active', scheduled: 'scheduled' }[organization.current_plan?.sms?.state] || 'disabled' }), h('span', null, smsDateText(organization.current_plan?.sms, organization.sms_resumable_until))),
        h('div', { className: 'sa-detail-grid' },
            h('div', null, h('small', null, 'Owner'), h('strong', null, organization.owner?.name || 'Not assigned'), h('span', null, organization.owner?.email || '')),
            h('div', null, h('small', null, 'Users'), h('strong', null, organization.users_count || 0)),
            h('div', null, h('small', null, 'People'), h('strong', null, organization.people_count || 0)),
            h('div', null, h('small', null, 'Created'), h('strong', null, formatDate(organization.created_at)))),
        h('div', { className: 'sa-action-row' },
            // Scan and SMS are turned on only by the Activate button on a submitted payment (Payments tab).
            organization.sms_access_status !== 'active' && organization.sms_requests?.some((request) => request.status === 'pending') ? organization.sms_pricing ? h('button', { className: 'sa-button', onClick: () => openMode('send_quote') }, 'Send payment instructions') : h('button', { className: 'sa-button', onClick: () => openMode('sms_pricing') }, 'Set SMS price') : h('span', { className: 'sa-muted' }, organization.sms_access_status === 'active'
                ? `SMS is on${organization.sms_expires_at ? ' until ' + formatDate(organization.sms_expires_at) : ''}. "Disable SMS" turns it off now; Scan is not affected. To extend SMS, press Activate on this organization's payment in Payments.`
                : organization.sms_resumable_until
                    ? `SMS is off. The school paid for SMS until ${formatDate(organization.sms_resumable_until)}. Turn it back on, or ask the school to pay for SMS again.`
                    : 'SMS is off. It turns on when you press Activate on this organization\'s Scan + SMS or Add SMS payment in Payments.'),
            organization.sms_access_status !== 'active' && organization.sms_resumable_until && h('button', { className: 'sa-button', onClick: () => setMode('resume') }, 'Turn SMS back on'),
            organization.sms_access_status === 'active' && h('button', { className: 'sa-button sa-button--danger-outline', onClick: () => setMode('disable') }, 'Disable SMS')),
        h('div', { className: 'sa-section' }, h('div', { className: 'sa-section-title' }, h('h3', null, 'SMS pricing'), h('button', { className: 'sa-link-button', onClick: () => setMode('sms_pricing') }, organization.sms_pricing ? 'Change price' : 'Set price')), h('p', { className: 'sa-muted' }, organization.sms_pricing ? `${formatMoney(organization.sms_pricing.sms_monthly_price_minor, organization.sms_pricing.currency)}/month from ${formatDate(organization.sms_pricing.effective_from)} · ${organization.sms_pricing.source === 'estimate' ? 'estimated from the school\'s answers (' + estimateText(organization.sms_estimate) + ')' : 'set by admin'} · ${organization.active_people_count || 0} active people now` : organization.sms_estimate ? `No SMS price yet · school's answers: ${estimateText(organization.sms_estimate)}` : 'No SMS price yet.'), (organization.sms_pricing_history || []).length > 1 && h('div', null, h('small', null, 'Price history'), ...organization.sms_pricing_history.map((item) => h('p', { key: item.id, className: 'sa-muted' }, `${formatMoney(item.sms_monthly_price_minor, item.currency)}/month${item.source === 'estimate' ? ' (estimate)' : ''} · ${formatDate(item.effective_from)} – ${item.effective_until ? formatDate(item.effective_until) : 'now'} · ${item.person_count_snapshot} people${item.created_by?.name ? ' · set by ' + item.created_by.name : ''}${item.notes ? ' · ' + item.notes : ''}`)))),
        h('div', { className: 'sa-section' }, h('div', { className: 'sa-section-title' }, h('h3', null, 'SMS sender name'), h('button', { className: 'sa-link-button', onClick: () => setMode('sender_name') }, 'Edit')), h('p', { className: 'sa-muted' }, organization.sms_sender_name ? organization.sms_sender_name + ' (organization override)' : (organization.sms_sender_name_effective || 'Not configured') + ' (system default)')),
        h('div', { className: 'sa-section' }, h('div', { className: 'sa-section-title' }, h('h3', null, 'Attendance data'), h('button', { className: 'sa-link-button sa-link-button--danger', onClick: () => setMode('delete_attendance') }, 'Delete today’s attendance'))),
        h('div', { className: 'sa-section' }, h('div', { className: 'sa-section-title' }, h('h3', null, 'Payment history'), h('button', { className: 'sa-link-button', onClick: () => setMode('cash') }, h(Icon, { name: 'plus' }), 'Record payment')), h(PaymentRows, { payments: organization.payments, onEditCash: editCash })));
    return h('aside', { className: 'sa-drawer' }, h('div', { className: 'sa-drawer-head' }, h('div', null, h('p', { className: 'sa-eyebrow' }, 'ORGANIZATION DETAILS'), h('h2', null, organization.name)), h('button', { className: 'sa-icon-button', onClick: onClose }, h(Icon, { name: 'x' }))), error && h('div', { className: 'sa-error' }, error), mode ? form : details);
}

export function SuperAdminPage({ user, onAuthenticated }) {
    const [dashboard, setDashboard] = useState(null); const [rows, setRows] = useState([]); const [payments, setPayments] = useState([]); const [selected, setSelected] = useState(null); const [reviewPayment, setReviewPayment] = useState(null); const [tab, setTab] = useState('overview'); const [search, setSearch] = useState(''); const [filters, setFilters] = useState({ plan: '', sms: '', type: '' }); const [orgTotal, setOrgTotal] = useState(null); const [paymentStatus, setPaymentStatus] = useState('pending'); const [paymentSearch, setPaymentSearch] = useState(''); const [loading, setLoading] = useState(true); const [paymentsLoading, setPaymentsLoading] = useState(false); const [error, setError] = useState(null);
    const loadDashboard = async () => { try { setDashboard(await get(api('/dashboard'))); } catch (caught) { setError(caught.message); } };
    const loadOrganizations = async () => { setLoading(true); try { const result = await get(api('/organizations?' + new URLSearchParams({ search, ...filters, per_page: 100 }))); setRows(result.data || []); setOrgTotal(result.total ?? (result.data || []).length); } catch (caught) { setError(caught.message); } finally { setLoading(false); } };
    const select = async (id) => { try { setSelected((await get(api('/organizations/' + id))).organization); } catch (caught) { setError(caught.message); } };
    const loadPayments = async () => { setPaymentsLoading(true); try { const result = await get(api('/billing/payments?status=' + paymentStatus + '&search=' + encodeURIComponent(paymentSearch))); setPayments(result.data || []); } catch (caught) { setError(caught.message); } finally { setPaymentsLoading(false); } };
    const refresh = async () => { await Promise.all([loadDashboard(), loadOrganizations(), loadPayments()]); if (selected) await select(selected.id); };
    useEffect(() => { loadDashboard(); loadOrganizations(); loadPayments(); }, []);
    useEffect(() => { const timer = setTimeout(loadOrganizations, 250); return () => clearTimeout(timer); }, [search, filters.plan, filters.sms, filters.type]);
    useEffect(() => { const timer = setTimeout(loadPayments, 250); return () => clearTimeout(timer); }, [paymentStatus, paymentSearch]);
    const signOut = async () => { await logout(); onAuthenticated(null); };
    const navItems = [['overview', 'Overview', 'home'], ['organizations', 'Organizations', 'grid'], ['payments', 'Payments', 'card']];
    // Payments still waiting for Activate or Reject; shown as a badge on the Payments menu item.
    const pendingCount = dashboard?.pending_payment_reviews || 0;
    const showOrganizations = (plan, sms = '') => { setSearch(''); setFilters({ plan, sms, type: '' }); setTab('organizations'); };
    const refreshLabel = (label, onClick) => h('button', { className: 'sa-button sa-button--small sa-button--ghost', onClick }, h(Icon, { name: 'refresh' }), label);
    const paymentsPanel = h('div', { className: 'sa-card' },
        h('div', { className: 'sa-card-head' }, h('div', null, h('h2', null, 'Payments'), h('p', null, 'Review GCash submissions and press Activate to turn on Scan only or Scan + SMS')), refreshLabel('Refresh', () => Promise.all([loadPayments(), loadDashboard()]))),
        h('div', { className: 'sa-toolbar' },
            h('div', { className: 'sa-search-wrap' }, h(Icon, { name: 'search' }), h('input', { className: 'sa-search', placeholder: 'Search organization or GCash reference…', value: paymentSearch, onChange: (event) => setPaymentSearch(event.target.value) })),
            h('select', { value: paymentStatus, onChange: (event) => setPaymentStatus(event.target.value) }, h('option', { value: 'pending' }, 'Pending Review'), h('option', { value: '' }, 'All'), h('option', { value: 'approved' }, 'Approved'), h('option', { value: 'rejected' }, 'Rejected'))
        ),
        h(PaymentReviewTable, { payments, loading: paymentsLoading, onReview: setReviewPayment })
    );
    return h('main', { className: 'sa-shell' }, h('header', { className: 'sa-topbar' }, h('div', { className: 'sa-brand' }, h('div', { className: 'sa-brand-mark' }, 'M'), h('div', null, h('strong', null, 'MYO Systems'), h('span', null, 'Platform administration'))), h('div', { className: 'sa-user' }, h('div', null, h('strong', null, user.name), h('span', null, 'Super Admin')), h('button', { className: 'sa-link-button', onClick: signOut }, h(Icon, { name: 'logout' }), 'Sign out'))),
        h('div', { className: 'sa-layout' }, h('nav', { className: 'sa-sidebar' }, navItems.map(([value, label, icon]) => h('button', { className: 'sa-nav-item ' + (tab === value ? 'is-active' : ''), key: value, onClick: () => { setTab(value); } }, h(Icon, { name: icon }), label, value === 'payments' && pendingCount > 0 && h('span', { className: 'sa-count-badge', title: pendingCount + ' payments waiting for review' }, pendingCount > 99 ? '99+' : pendingCount)))), h('section', { className: 'sa-content' },
            h('div', { className: 'sa-page-heading' }, h('div', null, h('p', { className: 'sa-eyebrow' }, 'SUPER ADMIN'), h('h1', null, tab === 'overview' ? 'Good morning, ' + user.name.split(' ')[0] : tab === 'organizations' ? 'Organizations' : 'Payments'), h('p', null, 'Keep subscriber access and payment records up to date.')), h('span', { className: 'sa-date' }, new Date().toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' }))),
            error && h('div', { className: 'sa-error' }, error),
            tab === 'overview' && dashboard && h(React.Fragment, null,
            dashboard.sms_price_requests > 0 && h('button', { type: 'button', className: 'sa-notice', onClick: () => showOrganizations('', 'price_requested') }, h('strong', null, `Custom SMS price requested: ${dashboard.sms_price_requests}`), h('span', null, dashboard.sms_price_requests === 1 ? 'A school is waiting for you to set its SMS price. Show it' : 'Schools are waiting for you to set their SMS price. Show them'), h(Icon, { name: 'chevronRight' })),
            h('div', { className: 'sa-stats' },
                h(Stat, { label: 'Expiring soon', value: dashboard.expiring_soon, tone: 'amber', hint: 'Scan or SMS ends within 7 days', onClick: () => showOrganizations('expiring_soon') }),
                h(Stat, { label: 'Expired Base + SMS', value: dashboard.expired_with_sms, tone: 'red', hint: 'Scan expired, had SMS', onClick: () => showOrganizations('expired_with_sms') }),
                h(Stat, { label: 'Expired Base only', value: dashboard.expired_base_only, tone: 'red', hint: 'Scan expired, never had SMS', onClick: () => showOrganizations('expired_base_only') }),
                h(Stat, { label: 'Total organizations', value: dashboard.total_organizations, tone: 'blue', hint: 'All schools and gyms', onClick: () => showOrganizations('') })),
            h('div', { className: 'sa-card' }, h('div', { className: 'sa-card-head' }, h('div', null, h('h2', null, 'Recent payments', pendingCount > 0 && h('span', { className: 'sa-count-badge', style: { marginLeft: '8px' } }, pendingCount + ' to review')), h('p', null, 'Newest submitted first')), h('button', { className: 'sa-link-button', onClick: () => setTab('payments') }, 'View all ', h(Icon, { name: 'chevronRight' }))), h('div', { className: 'sa-recent-list' }, h(PaymentRows, { payments: dashboard.recent_payments })))),
            (tab === 'overview' || tab === 'organizations') && h('div', { className: 'sa-card' }, h('div', { className: 'sa-card-head' }, h('div', null, h('h2', null, 'Organizations'), h('p', null, 'Search and manage subscriber access')), refreshLabel('Refresh', refresh)), h('div', { className: 'sa-toolbar' }, h('div', { className: 'sa-search-wrap' }, h(Icon, { name: 'search' }), h('input', { className: 'sa-search', placeholder: 'Search organization, owner, or email…', value: search, onChange: (event) => setSearch(event.target.value) })), ...orgFilterSelects(filters, setFilters)), h('p', { className: 'sa-muted', style: { margin: '0 20px 12px' } }, loading || orgTotal === null ? '' : `${orgTotal} ${orgTotal === 1 ? 'organization' : 'organizations'}${filters.plan || filters.sms || filters.type || search ? ' match' : ''}`, (filters.plan || filters.sms || filters.type) && h('button', { type: 'button', className: 'sa-link-button', style: { marginLeft: '10px' }, onClick: () => setFilters({ plan: '', sms: '', type: '' }) }, 'Clear filters')), h(OrganizationTable, { rows, loading, onSelect: select })),
            tab === 'payments' && paymentsPanel,
        selected && h(Drawer, { key: selected.id, organization: selected, onClose: () => setSelected(null), onRefresh: refresh }), reviewPayment && h(PaymentReview, { paymentId: reviewPayment, onClose: () => setReviewPayment(null), onDone: async () => { setReviewPayment(null); await refresh(); } }))));
}
