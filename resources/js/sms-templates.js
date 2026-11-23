import { get, post, put } from './services/api';

const config = window.MyoSmsTemplates || { userRole: '' };
const canEdit = ['owner', 'admin'].includes(config.userRole);

const TEMPLATE_META = {
    time_in: { label: 'Time In', desc: 'Sent when a check-in is recorded' },
    time_out: { label: 'Time Out', desc: 'Sent when a check-out is recorded' },
    late: { label: 'Late Arrival', desc: 'Sent when someone arrives past the threshold' },
    absent: { label: 'Absent', desc: 'Sent when someone is marked absent' },
};
const KNOWN_VARIABLES = ['name', 'time', 'date'];
const PREVIEW_SAMPLE = { name: 'Maria Santos', time: '8:05 AM', date: 'October 1, 2026' };

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}

function formatDate(value) {
    if (!value) return '';
    const [datePart] = value.split('T');
    const [year, month, day] = datePart.split('-').map(Number);
    if (!year || !month || !day) return '';
    return new Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, day)));
}

function renderPreview(body) {
    let text = body || '';
    KNOWN_VARIABLES.forEach((name) => {
        text = text.replaceAll(`{{${name}}}`, PREVIEW_SAMPLE[name]);
    });
    return text;
}

function validateBody(body) {
    const openCount = (body.match(/\{\{/g) || []).length;
    const closeCount = (body.match(/\}\}/g) || []).length;
    if (openCount !== closeCount) {
        return 'Warning: Ensure all variable tags are formatted with double braces.';
    }
    const usedVariables = Array.from(body.matchAll(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g)).map((match) => match[1]);
    const unknown = usedVariables.find((name) => !KNOWN_VARIABLES.includes(name));
    if (unknown) {
        return `Warning: "{{${unknown}}}" is not a supported variable.`;
    }
    return null;
}

const state = { access: null, templates: [], selectedKey: null };

const els = {
    loading: document.getElementById('sms-loading'),
    locked: document.getElementById('sms-locked'),
    lockedTitle: document.getElementById('sms-locked-title'),
    lockedText: document.getElementById('sms-locked-text'),
    requestHelp: document.getElementById('sms-request-help'),
    requestActions: document.getElementById('sms-request-actions'),
    requestAfterTrial: document.getElementById('sms-request-after-trial'),
    requestImmediate: document.getElementById('sms-request-immediate'),
    active: document.getElementById('sms-active'),
    activeBadge: document.getElementById('sms-active-badge'),
    activeBadgeText: document.getElementById('sms-active-badge-text'),
    list: document.getElementById('sms-template-list'),
    cardTemplate: document.getElementById('sms-template-card-template'),
    editOverlay: document.getElementById('sms-edit-overlay'),
    editName: document.getElementById('sms-edit-name'),
    editEnabled: document.getElementById('sms-edit-enabled'),
    editError: document.getElementById('sms-edit-error'),
    editSuccess: document.getElementById('sms-edit-success'),
    editBody: document.getElementById('sms-edit-body'),
    editCount: document.getElementById('sms-edit-count'),
    editWarning: document.getElementById('sms-edit-warning'),
    editWarningText: document.getElementById('sms-edit-warning-text'),
    editPreview: document.getElementById('sms-edit-preview'),
    editSave: document.getElementById('sms-edit-save'),
    editCancel: document.getElementById('sms-edit-cancel'),
};

function openSheet() { els.editOverlay.hidden = false; }
function closeSheet() { els.editOverlay.hidden = true; }

function renderTemplateList() {
    els.list.innerHTML = '';
    state.templates.forEach((template) => {
        const fragment = els.cardTemplate.content.cloneNode(true);
        const card = fragment.querySelector('[data-key]');
        const meta = TEMPLATE_META[template.template_key] || { label: template.name, desc: '' };
        card.setAttribute('data-key', template.template_key);
        card.classList.toggle('is-disabled-trigger', !template.enabled);
        fragment.querySelector('[data-dot]').classList.toggle('is-off', !template.enabled);
        fragment.querySelector('[data-name]').textContent = meta.label;
        const enabledLabel = fragment.querySelector('[data-enabled-label]');
        enabledLabel.textContent = template.enabled ? 'Enabled' : 'Disabled';
        enabledLabel.classList.toggle('is-off', !template.enabled);
        fragment.querySelector('[data-desc]').textContent = meta.desc;
        fragment.querySelector('[data-preview]').textContent = template.body;
        card.addEventListener('click', () => openEditor(template.template_key));
        els.list.appendChild(fragment);
    });
}

function openEditor(key) {
    const template = state.templates.find((item) => item.template_key === key);
    if (!template) return;
    state.selectedKey = key;

    const meta = TEMPLATE_META[key] || { label: template.name };
    els.editName.textContent = meta.label;
    els.editEnabled.checked = template.enabled;
    els.editEnabled.disabled = !canEdit;
    els.editBody.value = template.body;
    els.editBody.disabled = !canEdit;
    els.editSave.hidden = !canEdit;
    els.editError.classList.remove('visible');
    els.editSuccess.classList.remove('visible');
    updateEditorDerived();

    openSheet();
}

function updateEditorDerived() {
    const body = els.editBody.value;
    els.editCount.textContent = `${body.length} / 1000 characters`;
    els.editPreview.textContent = renderPreview(body) || 'Your message preview will appear here.';

    const warning = validateBody(body);
    els.editWarning.hidden = !warning;
    if (warning) els.editWarningText.textContent = warning;
}

els.editBody.addEventListener('input', updateEditorDerived);
els.editCancel.addEventListener('click', closeSheet);
els.editOverlay.addEventListener('click', (event) => { if (event.target === els.editOverlay) closeSheet(); });

els.editSave.addEventListener('click', async () => {
    if (!state.selectedKey) return;
    els.editError.classList.remove('visible');
    els.editSuccess.classList.remove('visible');
    els.editSave.disabled = true;
    els.editSave.textContent = 'Saving…';

    try {
        const response = await put(`/api/organization/sms/templates/${state.selectedKey}`, {
            body: els.editBody.value,
            enabled: els.editEnabled.checked,
        });
        state.templates = state.templates.map((item) => (item.template_key === state.selectedKey ? response.template : item));
        renderTemplateList();
        els.editSuccess.textContent = 'Template saved successfully.';
        els.editSuccess.classList.add('visible');
    } catch (error) {
        const messages = Object.values(error.errors || {}).flat();
        els.editError.textContent = messages[0] || error.message || 'Unable to save this template.';
        els.editError.classList.add('visible');
    } finally {
        els.editSave.disabled = false;
        els.editSave.textContent = 'Save Template';
    }
});

function renderLocked(access) {
    els.active.hidden = true;
    els.locked.hidden = false;
    const step = access.next_step;
    const showActions = (visible) => {
        els.requestHelp.hidden = !visible;
        els.requestActions.hidden = !visible;
    };
    const afterTrialTitle = els.requestAfterTrial.querySelector('.billing-sms-choice-title');
    const afterTrialCaption = els.requestAfterTrial.querySelector('.billing-sms-choice-caption');
    afterTrialTitle.dataset.original ??= afterTrialTitle.textContent;
    afterTrialCaption.dataset.original ??= afterTrialCaption.textContent;
    afterTrialTitle.textContent = afterTrialTitle.dataset.original;
    afterTrialCaption.textContent = afterTrialCaption.dataset.original;
    els.requestImmediate.hidden = false;
    els.requestAfterTrial.dataset.mode = 'after_trial';

    if (step === 'waiting_for_price' || access.request_status === 'pending') {
        els.lockedTitle.textContent = 'SMS Access Requested';
        els.lockedText.textContent = 'Your request is waiting for MYO Attendance to set your SMS price. Then pay for Base + SMS on the Billing page.';
        showActions(false);
    } else if (step === 'pay_package' || step === 'renew_with_package') {
        els.lockedTitle.textContent = 'SMS Notifications Not Active';
        els.lockedText.innerHTML = step === 'renew_with_package'
            ? 'Your subscription has expired. <a href="/billing">Renew with Base + SMS on the Billing page</a> to turn SMS back on.'
            : 'Your SMS price is already set. <a href="/billing">Choose Base + SMS on the Billing page</a> to turn SMS on.';
        showActions(false);
    } else if (step === 'renew_first') {
        els.lockedTitle.textContent = access.access === 'expired' ? 'SMS Access Expired' : 'SMS Notifications Not Active';
        els.lockedText.textContent = 'Renew your MYO Attendance subscription on the Billing page before requesting SMS Notifications.';
        showActions(false);
    } else if (step === 'request') {
        els.lockedTitle.textContent = access.access === 'expired' ? 'SMS Access Expired' : 'SMS Notifications Not Active';
        els.lockedText.textContent = 'Send a request and MYO Attendance will set your SMS price. Then choose Base + SMS for your next payment.';
        afterTrialTitle.textContent = 'Request SMS Notifications';
        afterTrialCaption.textContent = 'SMS starts with your next Base + SMS payment';
        els.requestAfterTrial.dataset.mode = 'next_payment';
        els.requestImmediate.hidden = true;
        showActions(canEdit);
        els.requestHelp.hidden = true;
    } else {
        // Only schools still on their free trial choose when their paid plan starts.
        els.lockedTitle.textContent = 'SMS Notifications Not Active';
        els.lockedText.textContent = 'This organization does not currently have SMS notifications enabled.';
        showActions(canEdit);
    }
}

async function requestAccess(activationMode) {
    if (activationMode === 'immediate' && !window.confirm('Your free trial will end early, on the day your payment is approved. Continue?')) return;

    els.requestAfterTrial.disabled = true;
    els.requestImmediate.disabled = true;
    const selectedButton = activationMode === 'immediate' ? els.requestImmediate : els.requestAfterTrial;
    const titleEl = selectedButton.querySelector('.billing-sms-choice-title');
    const originalTitle = titleEl.textContent;
    titleEl.textContent = 'Submitting…';
    try {
        await post('/api/organization/sms/access/request', { activation_mode: activationMode });
        await load();
    } catch (error) {
        // Surface nothing destructive; the locked state will simply remain and can be retried.
        titleEl.textContent = error.message || 'Unable to submit request';
    } finally {
        els.requestAfterTrial.disabled = false;
        els.requestImmediate.disabled = false;
        if (!state.access || state.access.request_status !== 'pending') titleEl.textContent = originalTitle;
    }
}

els.requestAfterTrial.addEventListener('click', () => requestAccess(els.requestAfterTrial.dataset.mode || 'after_trial'));
els.requestImmediate.addEventListener('click', () => requestAccess('immediate'));

async function load() {
    els.loading.hidden = false;
    els.locked.hidden = true;
    els.active.hidden = true;

    try {
        const accessResponse = await get('/api/organization/sms/access');
        state.access = accessResponse.sms;

        if (state.access.access === 'active') {
            const templatesResponse = await get('/api/organization/sms/templates');
            state.templates = templatesResponse.templates || [];
            els.active.hidden = false;
            if (state.access.expires_at) {
                els.activeBadge.hidden = false;
                els.activeBadgeText.textContent = `Active through ${formatDate(state.access.expires_at)}`;
            }
            renderTemplateList();
        } else {
            renderLocked(state.access);
        }
    } catch (error) {
        renderLocked({ access: 'inactive' });
    } finally {
        els.loading.hidden = true;
    }
}

load();
