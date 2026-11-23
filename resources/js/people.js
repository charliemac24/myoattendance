import QRCode from 'qrcode';
import { get, post } from './services/api';

const config = window.MyoPeople || { organizationTimezone: 'UTC', terminology: {} };
const terminology = config.terminology;

function localToday(timeZone) {
    return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

function initials(firstName, lastName) {
    return `${(firstName || '?').charAt(0)}${(lastName || '').charAt(0)}`.toUpperCase() || '?';
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}

const state = {
    search: '',
    groupId: '',
    subgroupId: '',
    membership: '',
    page: 1,
    groups: [],
    subgroups: [],
    statusByPersonId: {},
    people: [],
    meta: null,
};

const els = {
    search: document.getElementById('people-search'),
    searchShell: document.getElementById('people-search-shell'),
    searchClear: document.getElementById('people-search-clear'),
    filterGroup: document.getElementById('filter-group'),
    filterSubgroup: document.getElementById('filter-subgroup'),
    filterMembership: document.getElementById('filter-membership'),
    exportLink: document.getElementById('people-export-link'),
    activeFilters: document.getElementById('active-filters'),
    filterSummaryText: document.getElementById('filter-summary-text'),
    activeChips: document.getElementById('active-chips'),
    clearFiltersBtn: document.getElementById('clear-filters-btn'),
    list: document.getElementById('people-list'),
    empty: document.getElementById('people-empty'),
    noResults: document.getElementById('people-no-results'),
    noResultsText: document.getElementById('people-no-results-text'),
    resetSearchBtn: document.getElementById('people-reset-search'),
    loadMoreBtn: document.getElementById('load-more-btn'),
    addPersonBtn: document.getElementById('add-person-btn'),
    addPersonOverlay: document.getElementById('add-person-overlay'),
    addPersonCancel: document.getElementById('add-person-cancel'),
    addPersonForm: document.getElementById('add-person-form'),
    addPersonError: document.getElementById('add-person-error'),
    apGroup: document.getElementById('ap-group'),
    apSubgroup: document.getElementById('ap-subgroup'),
    detailsOverlay: document.getElementById('person-details-overlay'),
    detailsError: document.getElementById('pd-error'),
    detailsBody: document.getElementById('pd-body'),
    detailsInitials: document.getElementById('pd-initials'),
    detailsName: document.getElementById('pd-name'),
    detailsNumber: document.getElementById('pd-number'),
    detailsStatus: document.getElementById('pd-status'),
    detailsGroup: document.getElementById('pd-group'),
    detailsSubgroup: document.getElementById('pd-subgroup'),
    detailsContact: document.getElementById('pd-contact'),
    detailsEdit: document.getElementById('pd-edit'),
    detailsViewQr: document.getElementById('pd-view-qr'),
    detailsClose: document.getElementById('pd-close'),
    detailsCloseX: document.getElementById('pd-close-x'),
    qrOverlay: document.getElementById('qr-overlay'),
    qrImage: document.getElementById('qr-image'),
    qrPayload: document.getElementById('qr-payload'),
    qrRegenerate: document.getElementById('qr-regenerate'),
    qrClose: document.getElementById('qr-close'),
};

const LIST_URL_STORAGE_KEY = 'myo.peopleListUrl';

function openSheet(overlay) { overlay.hidden = false; }
function closeSheet(overlay) { overlay.hidden = true; }

function statusFor(person) {
    if (!person.is_active) return 'INACTIVE';
    return state.statusByPersonId[person.id] || 'ABSENT';
}

function statusClass(status) {
    return { PRESENT: 'status-pill--present', LATE: 'status-pill--late', ABSENT: 'status-pill--absent', INACTIVE: 'status-pill--inactive' }[status] || 'status-pill--absent';
}

function metaLine(person) {
    const parts = [];
    if (person.group?.name) parts.push(person.subgroup?.name ? `${person.group.name} · ${person.subgroup.name}` : person.group.name);
    if (state.membership && person.membership_valid_until) {
        const expired = person.membership_valid_until < localToday(config.organizationTimezone);
        parts.push(`${expired ? 'Expired' : 'Valid until'} ${person.membership_valid_until}`);
    }
    return parts.join(' · ');
}

const CHEVRON_SVG = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7.5 15L12.5 10L7.5 5" stroke="#4B5563" stroke-width="2" stroke-linecap="round"/></svg>';

function personRowHtml(person) {
    const status = statusFor(person);
    const meta = metaLine(person);
    return `
        <a href="/people/${person.id}" class="person-row" data-person-id="${person.id}">
            <div class="person-left">
                <div class="person-avatar">${escapeHtml(initials(person.first_name, person.last_name))}</div>
                <div class="person-details">
                    <p class="person-name">${escapeHtml(person.first_name)} ${escapeHtml(person.last_name)}</p>
                    <div class="person-meta">
                        <span class="person-number">#${escapeHtml(person.person_number)}</span>
                        ${meta ? '<span class="dot"></span><span>' + escapeHtml(meta) + '</span>' : ''}
                    </div>
                </div>
            </div>
            <div class="person-right">
                <span class="status-pill ${statusClass(status)}"><span class="dot"></span>${status}</span>
                ${CHEVRON_SVG}
            </div>
        </a>
    `;
}

function renderPeople(append) {
    if (!append) els.list.innerHTML = '';
    els.list.insertAdjacentHTML('beforeend', state.people.map(personRowHtml).join(''));

    const hasResults = state.people.length > 0;
    const hasActiveQuery = Boolean(state.search || state.groupId || state.subgroupId || state.membership);

    els.empty.hidden = hasResults || hasActiveQuery;
    els.noResults.hidden = hasResults || !hasActiveQuery;
    if (!hasResults && hasActiveQuery) {
        els.noResultsText.textContent = state.search
            ? `We couldn't find anyone registered under "${state.search}" in this list. Try resetting your search terms or filters.`
            : `We couldn't find anyone matching the current filters. Try resetting your search terms or filters.`;
    }

    els.loadMoreBtn.hidden = !state.meta || state.meta.current_page >= state.meta.last_page;
}

function renderFilters() {
    const hasFilter = state.groupId || state.subgroupId || state.membership;
    els.activeFilters.hidden = !hasFilter;
    if (!hasFilter) return;

    const group = state.groups.find((item) => String(item.id) === String(state.groupId));
    const subgroup = state.subgroups.find((item) => String(item.id) === String(state.subgroupId));
    const parts = [];
    if (group) parts.push(`${terminology.group_singular}: ${group.name}`);
    if (subgroup) parts.push(`${terminology.subgroup_singular}: ${subgroup.name}`);
    const membershipLabel = state.membership && els.filterMembership ? els.filterMembership.options[els.filterMembership.selectedIndex]?.text : '';
    if (membershipLabel) parts.push(`Membership: ${membershipLabel}`);
    els.filterSummaryText.textContent = parts.join(' · ');

    const chips = [];
    if (group) chips.push(`<span class="filter-chip">${escapeHtml(group.name)}</span>`);
    if (subgroup) chips.push(`<span class="filter-chip">${escapeHtml(subgroup.name)}</span>`);
    if (membershipLabel) chips.push(`<span class="filter-chip">${escapeHtml(membershipLabel)}</span>`);
    els.activeChips.innerHTML = chips.join('');
}

async function loadReportStatuses() {
    try {
        const date = localToday(config.organizationTimezone);
        const report = await get(`/api/attendance/report?date=${encodeURIComponent(date)}`);
        state.statusByPersonId = {};
        (report.people || []).forEach((row) => { state.statusByPersonId[row.person.id] = row.status; });
    } catch (error) {
        // Attendance status is a display enhancement; the roster still functions without it.
    }
}

async function loadOptions() {
    try {
        const [groupData, subgroupData] = await Promise.all([get('/api/groups/options'), get('/api/subgroups/options')]);
        state.groups = groupData.data || [];
        state.subgroups = subgroupData.data || [];

        const groupOptions = state.groups.map((group) => `<option value="${group.id}">${escapeHtml(group.name)}</option>`).join('');
        els.filterGroup.innerHTML = `<option value="">All ${terminology.group_plural.toLowerCase()}</option>${groupOptions}`;
        els.apGroup.innerHTML = `<option value="">No ${terminology.group_singular.toLowerCase()}</option>${groupOptions}`;
    } catch (error) {
        // Filter/add-person options are progressive enhancements over the base roster.
    }
}

function updateSubgroupOptions(selectEl, groupId, placeholder) {
    const filtered = state.subgroups.filter((subgroup) => String(subgroup.group_id) === String(groupId));
    selectEl.disabled = !groupId;
    selectEl.innerHTML = `<option value="">${!groupId ? placeholder : `No ${terminology.subgroup_singular.toLowerCase()}`}</option>${filtered.map((subgroup) => `<option value="${subgroup.id}">${escapeHtml(subgroup.name)}</option>`).join('')}`;
}

async function loadPeople(append = false) {
    const params = new URLSearchParams({ page: String(state.page), per_page: '20' });
    if (state.search) params.set('search', state.search);
    if (state.groupId) params.set('group_id', state.groupId);
    if (state.subgroupId) params.set('subgroup_id', state.subgroupId);
    if (state.membership) params.set('membership', state.membership);

    const response = await get(`/api/people?${params.toString()}`);
    const newPeople = response.data || [];
    state.people = append ? [...state.people, ...newPeople] : newPeople;
    state.meta = response;

    renderPeople(append);
}

function listUrl() {
    const params = new URLSearchParams();
    if (state.search) params.set('search', state.search);
    if (state.groupId) params.set('group_id', state.groupId);
    if (state.subgroupId) params.set('subgroup_id', state.subgroupId);
    if (state.membership) params.set('membership', state.membership);
    const query = params.toString();

    return query ? `/people?${query}` : '/people';
}

/**
 * Keep the search and filters in the address bar so a reload, or returning from
 * the edit page, lands on the same list the admin was looking at.
 */
function syncListUrl() {
    const url = listUrl();
    const personParam = new URLSearchParams(window.location.search).get('person');
    const fullUrl = personParam ? `${url}${url.includes('?') ? '&' : '?'}person=${encodeURIComponent(personParam)}` : url;
    window.history.replaceState(window.history.state, '', fullUrl);
    try { window.sessionStorage.setItem(LIST_URL_STORAGE_KEY, url); } catch (error) { /* storage unavailable */ }
}

/** The CSV export follows the membership filter, so "Expired" downloads just the expired members. */
function syncExportLink() {
    if (!els.exportLink) return;
    els.exportLink.href = state.membership ? `/api/people/export?membership=${encodeURIComponent(state.membership)}` : '/api/people/export';
}

async function refresh() {
    state.page = 1;
    syncListUrl();
    syncExportLink();
    await Promise.all([loadReportStatuses(), loadPeople(false)]);
}

function updateSearchShellState() {
    const hasValue = els.search.value.length > 0;
    els.searchShell.classList.toggle('is-active', hasValue);
    els.searchClear.hidden = !hasValue;
}

let searchTimer = null;
els.search.addEventListener('input', () => {
    updateSearchShellState();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        state.search = els.search.value.trim();
        refresh();
    }, 300);
});

els.searchClear.addEventListener('click', () => {
    els.search.value = '';
    state.search = '';
    updateSearchShellState();
    refresh();
});

els.resetSearchBtn.addEventListener('click', () => {
    els.search.value = '';
    state.search = '';
    state.groupId = '';
    state.subgroupId = '';
    state.membership = '';
    if (els.filterMembership) els.filterMembership.value = '';
    els.filterGroup.value = '';
    els.filterSubgroup.value = '';
    updateSubgroupOptions(els.filterSubgroup, '', `All ${terminology.subgroup_plural.toLowerCase()}`);
    updateSearchShellState();
    renderFilters();
    refresh();
});

els.filterGroup.addEventListener('change', () => {
    state.groupId = els.filterGroup.value;
    state.subgroupId = '';
    els.filterSubgroup.value = '';
    updateSubgroupOptions(els.filterSubgroup, state.groupId, `All ${terminology.subgroup_plural.toLowerCase()}`);
    renderFilters();
    refresh();
});

els.filterSubgroup.addEventListener('change', () => {
    state.subgroupId = els.filterSubgroup.value;
    renderFilters();
    refresh();
});

els.filterMembership?.addEventListener('change', () => {
    state.membership = els.filterMembership.value;
    renderFilters();
    refresh();
});

els.clearFiltersBtn.addEventListener('click', () => {
    state.groupId = '';
    state.subgroupId = '';
    state.membership = '';
    if (els.filterMembership) els.filterMembership.value = '';
    els.filterGroup.value = '';
    els.filterSubgroup.value = '';
    updateSubgroupOptions(els.filterSubgroup, '', `All ${terminology.subgroup_plural.toLowerCase()}`);
    renderFilters();
    refresh();
});

els.loadMoreBtn.addEventListener('click', () => {
    state.page += 1;
    loadPeople(true);
});

// Person details modal
let openPersonId = null;

function fillPersonDetails(person) {
    const status = statusFor(person);
    els.detailsInitials.textContent = initials(person.first_name, person.last_name);
    els.detailsName.textContent = `${person.first_name ?? ''} ${person.last_name ?? ''}`.trim();
    els.detailsNumber.textContent = `ID: #${person.person_number ?? ''}`;
    els.detailsStatus.textContent = person.is_active ? 'ACTIVE' : 'INACTIVE';
    els.detailsStatus.className = `badge-pill ${person.is_active ? 'badge-pill--success' : 'badge-pill--neutral'}`;
    els.detailsStatus.title = `Today: ${status}`;
    els.detailsGroup.textContent = person.group?.name || 'Not assigned';
    els.detailsSubgroup.textContent = person.subgroup?.name || 'Not assigned';
    els.detailsContact.textContent = person.contact_number || 'Not provided';
    els.detailsEdit.href = `/people/${person.id}/edit`;
}

async function openPersonDetails(personId, { pushHistory = true } = {}) {
    openPersonId = String(personId);
    els.detailsError.classList.remove('visible');
    els.detailsBody.hidden = false;

    const cached = state.people.find((person) => String(person.id) === openPersonId);
    if (cached) fillPersonDetails(cached);
    else els.detailsBody.hidden = true;
    openSheet(els.detailsOverlay);

    if (pushHistory) {
        const url = listUrl();
        window.history.pushState({ personId: openPersonId }, '', `${url}${url.includes('?') ? '&' : '?'}person=${encodeURIComponent(openPersonId)}`);
    }

    try {
        const response = await get(`/api/people/${openPersonId}`);
        if (openPersonId !== String(personId)) return;
        fillPersonDetails(response.person);
        els.detailsBody.hidden = false;
    } catch (error) {
        if (openPersonId !== String(personId)) return;
        if (!cached) {
            els.detailsError.textContent = error.message || `Unable to load this ${terminology.person_singular?.toLowerCase() || 'person'}.`;
            els.detailsError.classList.add('visible');
        }
    }
}

function hidePersonDetails() {
    openPersonId = null;
    closeSheet(els.qrOverlay);
    closeSheet(els.detailsOverlay);
}

function closePersonDetails() {
    if (!openPersonId) return;
    if (window.history.state?.personId) {
        window.history.back();
        return;
    }
    hidePersonDetails();
    window.history.replaceState(null, '', listUrl());
}

els.list.addEventListener('click', (event) => {
    const row = event.target.closest('.person-row');
    if (!row || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
    event.preventDefault();
    openPersonDetails(row.dataset.personId);
});

els.detailsClose.addEventListener('click', closePersonDetails);
els.detailsCloseX.addEventListener('click', closePersonDetails);
els.detailsOverlay.addEventListener('click', (event) => { if (event.target === els.detailsOverlay) closePersonDetails(); });

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (!els.qrOverlay.hidden) closeSheet(els.qrOverlay);
    else if (!els.detailsOverlay.hidden) closePersonDetails();
});

window.addEventListener('popstate', () => {
    const personId = new URLSearchParams(window.location.search).get('person');
    if (personId) openPersonDetails(personId, { pushHistory: false });
    else hidePersonDetails();
});

async function showQr(payload) {
    els.qrImage.src = await QRCode.toDataURL(payload);
    els.qrPayload.textContent = payload;
    openSheet(els.qrOverlay);
}

els.detailsViewQr.addEventListener('click', async () => {
    const result = await get(`/api/people/${openPersonId}/qr`);
    await showQr(result.qr_payload);
});

els.qrRegenerate.addEventListener('click', async () => {
    if (!window.confirm('Regenerate this QR code? The previous code will stop working.')) return;
    const result = await post(`/api/people/${openPersonId}/regenerate-qr`);
    await showQr(result.qr_payload);
});

els.qrClose.addEventListener('click', () => closeSheet(els.qrOverlay));
els.qrOverlay.addEventListener('click', (event) => { if (event.target === els.qrOverlay) closeSheet(els.qrOverlay); });

// Add person sheet
els.addPersonBtn.addEventListener('click', () => {
    els.addPersonForm.reset();
    els.addPersonError.classList.remove('visible');
    updateSubgroupOptions(els.apSubgroup, '', `Select a ${terminology.group_singular.toLowerCase()} first`);
    openSheet(els.addPersonOverlay);
});

// /people/create opens the people list with the add sheet already open.
if (config.openAddSheet) {
    els.addPersonBtn.click();
}
els.addPersonCancel.addEventListener('click', () => closeSheet(els.addPersonOverlay));
els.addPersonOverlay.addEventListener('click', (event) => { if (event.target === els.addPersonOverlay) closeSheet(els.addPersonOverlay); });

els.apGroup.addEventListener('change', () => {
    updateSubgroupOptions(els.apSubgroup, els.apGroup.value, `Select a ${terminology.group_singular.toLowerCase()} first`);
});

els.addPersonForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    els.addPersonError.classList.remove('visible');
    const submitBtn = document.getElementById('add-person-submit');
    submitBtn.disabled = true;

    try {
        await post('/api/people', {
            person_number: document.getElementById('ap-person-number').value,
            first_name: document.getElementById('ap-first-name').value,
            last_name: document.getElementById('ap-last-name').value,
            contact_number: document.getElementById('ap-contact-number').value || null,
            ...(document.getElementById('ap-membership') ? { membership_valid_until: document.getElementById('ap-membership').value || null } : {}),
            group_id: els.apGroup.value || null,
            subgroup_id: els.apSubgroup.value || null,
        });
        closeSheet(els.addPersonOverlay);
        await refresh();
    } catch (error) {
        const messages = Object.values(error.errors || {}).flat();
        els.addPersonError.textContent = messages[0] || error.message || 'Unable to add this person.';
        els.addPersonError.classList.add('visible');
    } finally {
        submitBtn.disabled = false;
    }
});

async function init() {
    await loadOptions();

    const params = new URLSearchParams(window.location.search);
    const search = params.get('search') || '';
    if (search) {
        state.search = search;
        els.search.value = search;
    }
    const subgroupId = params.get('subgroup_id') || '';
    const groupId = params.get('group_id') || (subgroupId ? String(state.subgroups.find((item) => String(item.id) === subgroupId)?.group_id ?? '') : '');

    if (groupId) {
        state.groupId = groupId;
        els.filterGroup.value = groupId;
        updateSubgroupOptions(els.filterSubgroup, groupId, `All ${terminology.subgroup_plural.toLowerCase()}`);
    }
    if (subgroupId) {
        state.subgroupId = subgroupId;
        els.filterSubgroup.value = subgroupId;
    }
    const membership = params.get('membership') || '';
    if (membership && els.filterMembership) {
        state.membership = membership;
        els.filterMembership.value = membership;
    }
    renderFilters();
    updateSearchShellState();

    await refresh();

    const personId = params.get('person');
    if (personId) openPersonDetails(personId, { pushHistory: false });
}

init();
