import { get } from './services/api';

const config = window.MyoAttendance || { organizationTimezone: 'UTC', terminology: {} };
const terminology = config.terminology;

function localToday(timeZone) {
    return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

function formatDateLong(dateStr) {
    const [year, month, day] = dateStr.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));
    return new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(date);
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}

const urlParams = new URLSearchParams(window.location.search);
const initialStatus = urlParams.get('status');
const initialDate = urlParams.get('date');
const initialFlag = urlParams.get('flag');

const state = {
    date: (initialDate && /^\d{4}-\d{2}-\d{2}$/.test(initialDate)) ? initialDate : localToday(config.organizationTimezone),
    search: '',
    status: ['PRESENT', 'LATE', 'ABSENT'].includes(initialStatus) ? initialStatus : '',
    flag: ['NO_TIME_IN', 'NO_TIME_OUT'].includes(initialFlag) ? initialFlag : '',
    groupId: '',
    subgroupId: '',
    groups: [],
    subgroups: [],
    report: null,
};

const els = {
    dateInput: document.getElementById('logs-date-input'),
    ribbon: document.getElementById('metric-ribbon'),
    search: document.getElementById('logs-search'),
    searchClear: document.getElementById('logs-search-clear'),
    groupFilter: document.getElementById('logs-group-filter'),
    subgroupFilter: document.getElementById('logs-subgroup-filter'),
    activeFilters: document.getElementById('logs-active-filters'),
    filterSummaryText: document.getElementById('logs-filter-summary-text'),
    activeChips: document.getElementById('logs-active-chips'),
    clearFilters: document.getElementById('logs-clear-filters'),
    resultsHeader: document.getElementById('logs-results-header'),
    list: document.getElementById('logs-list'),
    empty: document.getElementById('logs-empty'),
    printBtn: document.getElementById('logs-print-btn'),
    printReport: document.getElementById('print-report'),
    refused: document.getElementById('logs-refused'),
    refusedCount: document.getElementById('logs-refused-count'),
    refusedList: document.getElementById('logs-refused-list'),
};

const REFUSED_REASONS = { MEMBERSHIP_EXPIRED: 'Membership expired', PERSON_INACTIVE: 'Archived' };

/** Turned-away scans for the day. They never count as attendance; the front desk uses this to follow up. */
function renderRefused(report) {
    if (!els.refused) return;
    const refused = report.refused || [];
    els.refused.hidden = refused.length === 0;
    els.refusedCount.textContent = String(refused.length);
    els.refusedList.innerHTML = refused.map((row) => {
        const reason = REFUSED_REASONS[row.reason] || 'Refused';
        const detail = row.reason === 'MEMBERSHIP_EXPIRED' && row.membership_valid_until ? ` on ${escapeHtml(row.membership_valid_until)}` : '';
        return `<div><strong>${escapeHtml(row.time)}</strong> · <a href="/people/${row.person.id}">${escapeHtml(row.person.first_name)} ${escapeHtml(row.person.last_name)}</a> (#${escapeHtml(row.person.person_number)}) · ${reason}${detail}</div>`;
    }).join('');
}

els.dateInput.value = state.date;

function statusTagClass(status) {
    return { PRESENT: 'attendance-log-status-tag--present', LATE: 'attendance-log-status-tag--late', ABSENT: 'attendance-log-status-tag--absent' }[status] || 'attendance-log-status-tag--absent';
}

const FLAG_LABELS = { NO_TIME_IN: 'No time in', NO_TIME_OUT: 'No time out' };
const FLAG_HINTS = {
    NO_TIME_IN: 'Scanned only when leaving. Their arrival was not recorded, so they are counted as Late.',
    NO_TIME_OUT: 'Scanned when arriving but not when leaving.',
};

function rowFlags(row) {
    const flags = [];
    if (row.no_time_in) flags.push('NO_TIME_IN');
    if (row.no_time_out) flags.push('NO_TIME_OUT');
    return flags;
}

function flagTagsHtml(row) {
    return rowFlags(row).map((flag) => `<span class="attendance-log-status-tag attendance-log-status-tag--flag" title="${escapeHtml(FLAG_HINTS[flag])}">${escapeHtml(FLAG_LABELS[flag])}</span>`).join('');
}

function logRowHtml(row) {
    const time = row.check_out_time || row.check_in_time || '--:--';
    const direction = row.check_out_time ? 'OUT' : (row.check_in_time ? 'IN' : '—');
    const groupLabel = row.group?.name ? (row.subgroup?.name ? `${row.group.name} (${row.subgroup.name})` : row.group.name) : 'Unassigned';

    return `
        <div class="attendance-log-row">
            <div class="attendance-log-left">
                <div class="attendance-log-time-block">
                    <span class="time">${escapeHtml(time)}</span>
                    <span class="direction">${escapeHtml(direction)}</span>
                </div>
                <div class="attendance-log-divider"></div>
                <div class="attendance-log-info-block">
                    <p class="name">${escapeHtml(row.person.first_name)} ${escapeHtml(row.person.last_name)}</p>
                    <p class="meta">ID: ${escapeHtml(row.person.person_number)} · ${escapeHtml(groupLabel)}</p>
                </div>
            </div>
            <div class="attendance-log-tags">
                ${flagTagsHtml(row)}
                <span class="attendance-log-status-tag ${statusTagClass(row.status)}">${escapeHtml(row.status)}</span>
            </div>
        </div>
    `;
}

const STATUS_LABELS = { PRESENT: 'Present', LATE: 'Late', ABSENT: 'Absent' };

function renderRibbon(summary, holiday) {
    const isActive = (status) => (state.status === status ? ' is-active' : '');
    const holidayNote = holiday ? `<p style="flex-basis:100%;margin:0 0 8px;padding:8px 12px;border-radius:8px;background:#eff6ff;color:#1d4ed8;font-size:13px;font-weight:700;">Holiday: ${escapeHtml(holiday)}. Nobody is marked absent or late today.</p>` : '';
    els.ribbon.innerHTML = holidayNote + `
        <button type="button" class="metric-chip${isActive('')}" data-status="">Expected <strong>${summary.expected_total}</strong></button>
        <button type="button" class="metric-chip metric-chip--present${isActive('PRESENT')}" data-status="PRESENT">Present <strong>${summary.present}</strong></button>
        <button type="button" class="metric-chip metric-chip--late${isActive('LATE')}" data-status="LATE">Late <strong>${summary.late}</strong></button>
        <button type="button" class="metric-chip metric-chip--absent${isActive('ABSENT')}" data-status="ABSENT">Absent <strong>${summary.absent}</strong></button>
        <button type="button" class="metric-chip metric-chip--flag${state.flag === 'NO_TIME_IN' ? ' is-active' : ''}" data-flag="NO_TIME_IN" title="${escapeHtml(FLAG_HINTS.NO_TIME_IN)}">No time in <strong>${summary.no_time_in ?? 0}</strong></button>
        <button type="button" class="metric-chip metric-chip--flag${state.flag === 'NO_TIME_OUT' ? ' is-active' : ''}" data-flag="NO_TIME_OUT" title="${escapeHtml(FLAG_HINTS.NO_TIME_OUT)}">No time out <strong>${summary.no_time_out ?? 0}</strong></button>
    `;
}

function renderResults(report) {
    const people = report.people || [];
    els.list.innerHTML = people.map(logRowHtml).join('');
    els.empty.hidden = people.length > 0;

    const parts = [];
    if (state.status) parts.push(STATUS_LABELS[state.status]);
    if (state.flag) parts.push(`with ${FLAG_LABELS[state.flag]}`);
    if (state.search) parts.push(`results for "${state.search}"`);
    const header = parts.length
        ? `Showing ${people.length} ${parts.join(' ')}`
        : `Showing ${people.length} of ${report.summary.expected_total} expected · most recent first`;
    els.resultsHeader.textContent = state.flag ? `${header}. ${FLAG_HINTS[state.flag]}` : header;
}

function renderGroupFilters() {
    els.groupFilter.innerHTML = `<option value="">All ${escapeHtml(terminology.group_plural || 'groups')}</option>${state.groups.map((group) => `<option value="${group.id}">${escapeHtml(group.name)}</option>`).join('')}`;
    els.groupFilter.value = state.groupId;
    const options = state.subgroups.filter((subgroup) => !state.groupId || String(subgroup.group_id) === String(state.groupId));
    els.subgroupFilter.disabled = !state.groupId;
    els.subgroupFilter.innerHTML = `<option value="">${state.groupId ? `All ${escapeHtml(terminology.subgroup_plural || 'subgroups')}` : `Select a ${escapeHtml(terminology.group_singular || 'group')} first`}</option>${options.map((subgroup) => `<option value="${subgroup.id}">${escapeHtml(subgroup.name)}</option>`).join('')}`;
    els.subgroupFilter.value = state.subgroupId;
}

function renderActiveFilters() {
    const hasFilter = state.groupId || state.subgroupId;
    els.activeFilters.hidden = !hasFilter;
    if (!hasFilter) return;

    const group = state.groups.find((item) => String(item.id) === String(state.groupId));
    const subgroup = state.subgroups.find((item) => String(item.id) === String(state.subgroupId));
    const parts = [];
    if (group) parts.push(`${terminology.group_singular}: ${group.name}`);
    if (subgroup) parts.push(`${terminology.subgroup_singular}: ${subgroup.name}`);
    els.filterSummaryText.textContent = parts.join(' · ');

    const chips = [];
    if (group) chips.push(`<span class="filter-chip">${escapeHtml(group.name)}</span>`);
    if (subgroup) chips.push(`<span class="filter-chip">${escapeHtml(subgroup.name)}</span>`);
    els.activeChips.innerHTML = chips.join('');
}

function printReportHtml(report) {
    const people = report.people || [];
    const parts = [];
    if (state.status) parts.push(STATUS_LABELS[state.status]);
    if (state.flag) parts.push(FLAG_LABELS[state.flag]);
    const group = state.groups.find((item) => String(item.id) === String(state.groupId));
    const subgroup = state.subgroups.find((item) => String(item.id) === String(state.subgroupId));
    if (group) parts.push(`${terminology.group_singular}: ${group.name}`);
    if (subgroup) parts.push(`${terminology.subgroup_singular}: ${subgroup.name}`);
    if (state.search) parts.push(`Search: "${state.search}"`);

    const rows = people.map((row) => {
        const time = row.check_out_time || row.check_in_time || '--:--';
        const direction = row.check_out_time ? 'OUT' : (row.check_in_time ? 'IN' : '—');
        const groupLabel = row.group?.name ? (row.subgroup?.name ? `${row.group.name} (${row.subgroup.name})` : row.group.name) : 'Unassigned';
        return `
            <tr>
                <td>${escapeHtml(time)} ${escapeHtml(direction)}</td>
                <td>${escapeHtml(row.person.first_name)} ${escapeHtml(row.person.last_name)}</td>
                <td>${escapeHtml(row.person.person_number)}</td>
                <td>${escapeHtml(groupLabel)}</td>
                <td>${escapeHtml(row.status)}</td>
                <td>${escapeHtml(rowFlags(row).map((flag) => FLAG_LABELS[flag]).join(', '))}</td>
            </tr>
        `;
    }).join('');

    return `
        <p class="print-report-title">${escapeHtml(config.organizationName || 'Daily Logs')}</p>
        <p class="print-report-subtitle">Daily Logs &middot; ${escapeHtml(formatDateLong(state.date))}${parts.length ? ' &middot; ' + escapeHtml(parts.join(', ')) : ''}</p>
        <div class="print-report-summary">
            <span>Expected: ${report.summary.expected_total}</span>
            <span>Present: ${report.summary.present}</span>
            <span>Late: ${report.summary.late}</span>
            <span>Absent: ${report.summary.absent}</span>
            <span>No time in: ${report.summary.no_time_in ?? 0}</span>
            <span>No time out: ${report.summary.no_time_out ?? 0}</span>
        </div>
        <p class="print-report-note">No time in: scanned only when leaving, counted as Late. No time out: scanned when arriving but not when leaving.</p>
        <table class="print-report-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Name</th>
                    <th>ID</th>
                    <th>${terminology.group_singular || 'Group'}</th>
                    <th>Status</th>
                    <th>Missing scan</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
    `;
}

async function refresh() {
    els.searchClear.hidden = !state.search;

    const params = new URLSearchParams({ date: state.date });
    if (state.search) params.set('search', state.search);
    if (state.status) params.set('status', state.status);
    if (state.flag) params.set('flag', state.flag);
    if (state.groupId) params.set('group_id', state.groupId);
    if (state.subgroupId) params.set('subgroup_id', state.subgroupId);

    const report = await get(`/api/attendance/report?${params.toString()}`);
    state.report = report;
    renderRibbon(report.summary, report.holiday);
    renderResults(report);
    renderRefused(report);
    renderGroupFilters();
    renderActiveFilters();
}

async function loadFilterOptions() {
    const [groups, subgroups] = await Promise.all([get('/api/groups/options'), get('/api/subgroups/options')]);
    state.groups = groups.data || [];
    state.subgroups = subgroups.data || [];
    renderGroupFilters();
}

els.dateInput.addEventListener('change', () => {
    if (!els.dateInput.value) return;
    state.date = els.dateInput.value;
    refresh();
});

let searchTimer = null;
els.search.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        state.search = els.search.value.trim();
        refresh();
    }, 300);
});

els.searchClear.addEventListener('click', () => {
    els.search.value = '';
    state.search = '';
    refresh();
});

els.groupFilter.addEventListener('change', () => {
    state.groupId = els.groupFilter.value;
    state.subgroupId = '';
    refresh();
});

els.subgroupFilter.addEventListener('change', () => {
    state.subgroupId = els.subgroupFilter.value;
    refresh();
});

els.clearFilters.addEventListener('click', () => {
    state.groupId = '';
    state.subgroupId = '';
    refresh();
});

els.printBtn.addEventListener('click', () => {
    if (!state.report) return;
    els.printReport.innerHTML = printReportHtml(state.report);
    window.print();
});

els.ribbon.addEventListener('click', (event) => {
    const flagChip = event.target.closest('[data-flag]');
    if (flagChip) {
        const flag = flagChip.getAttribute('data-flag');
        state.flag = state.flag === flag ? '' : flag;
        refresh();
        return;
    }
    const chip = event.target.closest('[data-status]');
    if (!chip) return;
    const status = chip.getAttribute('data-status');
    state.status = state.status === status ? '' : status;
    refresh();
});

loadFilterOptions().then(refresh).catch(refresh);
