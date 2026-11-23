import { get } from './services/api';

const config = window.MyoMonthlyReport || { organizationTimezone: 'UTC', terminology: {} };
const terminology = config.terminology;

function localMonth(timeZone) {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit' }).formatToParts(new Date());
    const year = parts.find((part) => part.type === 'year').value;
    const month = parts.find((part) => part.type === 'month').value;
    return `${year}-${month}`;
}

function formatMonthLabel(value) {
    const [year, month] = value.split('-').map(Number);
    return new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, 1)));
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}

function memberSuffix(count) {
    const label = count === 1 ? terminology.person_singular : terminology.person_plural;
    return `${count} ${label}`;
}

const state = {
    month: localMonth(config.organizationTimezone),
    groupId: '',
    subgroupId: '',
    groups: [],
    subgroups: [],
    report: null,
    search: '',
    loading: false,
};

const els = {
    monthInput: document.getElementById('report-month'),
    groupTrigger: document.getElementById('report-group-trigger'),
    groupOverlay: document.getElementById('report-group-overlay'),
    groupList: document.getElementById('report-group-list'),
    groupTitle: document.getElementById('report-group-title'),
    groupSub: document.getElementById('report-group-sub'),
    groupIdInput: document.getElementById('report-group-id'),
    subgroupTrigger: document.getElementById('report-subgroup-trigger'),
    subgroupOverlay: document.getElementById('report-subgroup-overlay'),
    subgroupList: document.getElementById('report-subgroup-list'),
    subgroupTitle: document.getElementById('report-subgroup-title'),
    subgroupSub: document.getElementById('report-subgroup-sub'),
    subgroupIdInput: document.getElementById('report-subgroup-id'),
    generateBtn: document.getElementById('report-generate-btn'),
    formError: document.getElementById('report-form-error'),
    hint: document.getElementById('report-hint'),
    result: document.getElementById('report-result'),
    summaryMonth: document.getElementById('report-summary-month'),
    summaryScope: document.getElementById('report-summary-scope'),
    summaryStats: document.getElementById('report-summary-stats'),
    search: document.getElementById('report-search'),
    searchClear: document.getElementById('report-search-clear'),
    downloadBtn: document.getElementById('report-download-btn'),
    personList: document.getElementById('report-person-list'),
    empty: document.getElementById('report-empty'),
    emptyTitle: document.getElementById('report-empty-title'),
    emptyText: document.getElementById('report-empty-text'),
    emptyEdit: document.getElementById('report-empty-edit'),
    error: document.getElementById('report-error'),
    errorText: document.getElementById('report-error-text'),
    retryBtn: document.getElementById('report-retry-btn'),
};

els.monthInput.value = state.month;

function formErrorMessage(message) {
    els.formError.textContent = message;
    els.formError.classList.add('visible');
}

function clearFormError() {
    els.formError.textContent = '';
    els.formError.classList.remove('visible');
}

async function loadGroups() {
    try {
        const response = await get('/api/groups?per_page=100');
        state.groups = response.data || [];
        els.groupList.innerHTML = state.groups.map((group) => `
            <button type="button" class="picker-item" data-group-id="${group.id}">
                <span class="picker-item-title">${escapeHtml(group.name)}</span>
                <span class="picker-item-sub">${escapeHtml(memberSuffix(group.persons_count ?? 0))}</span>
            </button>
        `).join('');
        els.groupList.querySelectorAll('[data-group-id]').forEach((button) => {
            button.addEventListener('click', () => {
                const group = state.groups.find((item) => String(item.id) === button.getAttribute('data-group-id'));
                if (group) selectGroup(group);
            });
        });
    } catch (error) {
        // Group options are a progressive enhancement over the base form.
    }
}

function selectGroup(group) {
    state.groupId = group.id;
    state.subgroupId = '';
    els.groupIdInput.value = group.id;
    els.groupTitle.textContent = group.name;
    els.groupTitle.classList.remove('is-placeholder');
    els.groupSub.textContent = memberSuffix(group.persons_count ?? 0);
    els.groupOverlay.hidden = true;

    els.subgroupIdInput.value = '';
    els.subgroupTrigger.disabled = false;
    els.subgroupTrigger.classList.remove('is-disabled');
    els.subgroupTitle.textContent = `All ${terminology.subgroup_plural}`;
    els.subgroupTitle.classList.remove('is-placeholder');
    els.subgroupSub.textContent = 'No sub-filter selected';

    loadSubgroups(group.id);
}

async function loadSubgroups(groupId) {
    try {
        const response = await get(`/api/subgroups?group_id=${groupId}&per_page=100`);
        state.subgroups = response.data || [];

        const entireItem = `
            <button type="button" class="picker-item" data-subgroup-id="">
                <span class="picker-item-title">All ${escapeHtml(terminology.subgroup_plural)}</span>
                <span class="picker-item-sub">No sub-filter selected</span>
            </button>
        `;
        const subgroupItems = state.subgroups.map((subgroup) => `
            <button type="button" class="picker-item" data-subgroup-id="${subgroup.id}">
                <span class="picker-item-title">${escapeHtml(subgroup.name)}</span>
                <span class="picker-item-sub">${escapeHtml(memberSuffix(subgroup.persons_count ?? 0))}</span>
            </button>
        `).join('');
        els.subgroupList.innerHTML = entireItem + subgroupItems;

        els.subgroupList.querySelectorAll('[data-subgroup-id]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-subgroup-id');
                selectSubgroup(id ? state.subgroups.find((item) => String(item.id) === id) : null);
            });
        });
    } catch (error) {
        // Subgroup options are a progressive enhancement over the base form.
    }
}

function selectSubgroup(subgroup) {
    if (subgroup) {
        state.subgroupId = subgroup.id;
        els.subgroupIdInput.value = subgroup.id;
        els.subgroupTitle.textContent = subgroup.name;
        els.subgroupSub.textContent = memberSuffix(subgroup.persons_count ?? 0);
    } else {
        state.subgroupId = '';
        els.subgroupIdInput.value = '';
        els.subgroupTitle.textContent = `All ${terminology.subgroup_plural}`;
        els.subgroupSub.textContent = 'No sub-filter selected';
    }
    els.subgroupTitle.classList.remove('is-placeholder');
    els.subgroupOverlay.hidden = true;
}

els.groupTrigger.addEventListener('click', () => { els.groupOverlay.hidden = false; });
els.groupOverlay.addEventListener('click', (event) => { if (event.target === els.groupOverlay) els.groupOverlay.hidden = true; });
els.subgroupTrigger.addEventListener('click', () => {
    if (els.subgroupTrigger.disabled) return;
    els.subgroupOverlay.hidden = false;
});
els.subgroupOverlay.addEventListener('click', (event) => { if (event.target === els.subgroupOverlay) els.subgroupOverlay.hidden = true; });

els.monthInput.addEventListener('change', () => {
    if (els.monthInput.value) state.month = els.monthInput.value;
});

function buildQuery() {
    const params = new URLSearchParams({ month: state.month, group_id: String(state.groupId) });
    if (state.subgroupId) params.set('subgroup_id', String(state.subgroupId));
    return params.toString();
}

function statusTagClass(status) {
    // Values can carry a missing-scan note, e.g. "Late (No time in)" or "Present (No time out)".
    const base = String(status || '').split(' (')[0];
    return { Present: 'attendance-log-status-tag--present', Visited: 'attendance-log-status-tag--present', Late: 'attendance-log-status-tag--late', Absent: 'attendance-log-status-tag--absent' }[base] || 'attendance-log-status-tag--neutral';
}

const CHEVRON_SVG = '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7.5 15L12.5 10L7.5 5" stroke="#2563EB" stroke-width="2" stroke-linecap="round"/></svg>';

function personCardHtml(person, dates) {
    const rows = dates.map((date) => `
        <div class="report-date-row">
            <span>${escapeHtml(date.label)}</span>
            <span class="attendance-log-status-tag ${statusTagClass(person.attendance[date.date])}">${escapeHtml(person.attendance[date.date])}</span>
        </div>
    `).join('');

    return `
        <div class="report-person-card" data-person-number="${escapeHtml(person.person_number)}" data-person-name="${escapeHtml(person.full_name.toLowerCase())}">
            <button type="button" class="report-person-header">
                <div class="report-person-left">
                    <p class="report-person-number">${escapeHtml(person.person_number)}</p>
                    <p class="report-person-name">${escapeHtml(person.full_name)}</p>
                    ${person.total_visits !== undefined ? `<p class="report-person-number">${person.total_visits} ${person.total_visits === 1 ? 'visit' : 'visits'} this month</p>` : ''}
                </div>
                <span class="report-person-toggle"><span>View attendance</span>${CHEVRON_SVG}</span>
            </button>
            <div class="report-person-body">${rows}</div>
        </div>
    `;
}

function renderPersonList() {
    if (!state.report) return;
    const search = state.search.trim().toLowerCase();
    const persons = state.report.persons.filter((person) => !search || person.person_number.toLowerCase().includes(search) || person.full_name.toLowerCase().includes(search));

    els.personList.innerHTML = persons.map((person) => personCardHtml(person, state.report.dates)).join('');
    els.personList.hidden = persons.length === 0;
    els.empty.hidden = persons.length > 0;

    if (persons.length === 0) {
        const scope = state.report.subgroup ? `${state.report.group.name} and ${state.report.subgroup.name}` : state.report.group.name;
        els.emptyTitle.textContent = search ? `No matching ${terminology.person_plural.toLowerCase()}` : `No ${terminology.person_plural} Found`;
        els.emptyText.textContent = search
            ? `No ${terminology.person_plural.toLowerCase()} match "${state.search.trim()}".`
            : `There are no ${terminology.person_plural.toLowerCase()} in the selected ${scope}.`;
    }

    els.personList.querySelectorAll('.report-person-header').forEach((header) => {
        header.addEventListener('click', () => {
            header.closest('.report-person-card').classList.toggle('is-expanded');
        });
    });
}

function renderReport() {
    const report = state.report;
    els.summaryMonth.textContent = formatMonthLabel(report.month);
    els.summaryScope.innerHTML = report.subgroup
        ? `<strong>${escapeHtml(report.group.name)}</strong> &middot; ${escapeHtml(report.subgroup.name)}`
        : `<strong>${escapeHtml(report.group.name)}</strong> &middot; All ${escapeHtml(terminology.subgroup_plural)}`;
    els.summaryStats.innerHTML = `<span>${escapeHtml(memberSuffix(report.persons.length))}</span><span class="dot"></span><span>${report.dates.length} ${report.open_hours ? 'Days' : 'Weekdays'}</span>`;

    renderPersonList();

    els.result.hidden = false;
    els.error.hidden = true;
    els.hint.hidden = true;
}

els.search.addEventListener('input', () => {
    state.search = els.search.value;
    els.searchClear.hidden = !state.search;
    renderPersonList();
});
els.searchClear.addEventListener('click', () => {
    els.search.value = '';
    state.search = '';
    els.searchClear.hidden = true;
    renderPersonList();
});

async function generateReport() {
    clearFormError();
    if (!state.groupId) {
        formErrorMessage(`Select a ${terminology.group_singular.toLowerCase()} to generate a report.`);
        return;
    }
    if (!state.month) {
        formErrorMessage('Select a month to generate a report.');
        return;
    }
    if (state.loading) return;

    state.loading = true;
    els.generateBtn.disabled = true;
    els.generateBtn.textContent = 'Generating report…';
    els.error.hidden = true;

    try {
        const report = await get(`/api/attendance/monthly?${buildQuery()}`);
        state.report = report;
        renderReport();
    } catch (error) {
        state.report = null;
        els.result.hidden = true;
        els.error.hidden = false;
        els.errorText.textContent = error.status === 422
            ? (Object.values(error.errors || {}).flat()[0] || 'Unable to generate report.')
            : 'Unable to generate report. Please try again.';
    } finally {
        state.loading = false;
        els.generateBtn.disabled = false;
        els.generateBtn.textContent = 'Generate Report';
    }
}

els.generateBtn.addEventListener('click', generateReport);
els.retryBtn.addEventListener('click', generateReport);
els.emptyEdit.addEventListener('click', () => {
    els.result.hidden = true;
    els.hint.hidden = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

els.downloadBtn.addEventListener('click', async () => {
    if (!state.report) return;
    els.downloadBtn.disabled = true;
    const originalHtml = els.downloadBtn.innerHTML;
    els.downloadBtn.innerHTML = '<span>Preparing download&hellip;</span>';

    try {
        const response = await fetch(`/api/attendance/monthly/export?${buildQuery()}`, {
            credentials: 'same-origin',
            headers: { Accept: 'text/csv' },
        });
        if (!response.ok) throw new Error('Download failed');

        const disposition = response.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="?([^";]+)"?/);
        const filename = match ? match[1] : `monthly-attendance-${state.report.month}.csv`;

        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    } catch (error) {
        els.formError.textContent = 'Unable to download CSV. Please try again.';
        els.formError.classList.add('visible');
    } finally {
        els.downloadBtn.disabled = false;
        els.downloadBtn.innerHTML = originalHtml;
    }
});

loadGroups();
