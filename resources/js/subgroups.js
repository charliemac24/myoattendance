import { get } from './services/api';

const config = window.MyoSubgroups || { terminology: {} };
const terminology = config.terminology;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}

const state = { search: '', groupId: '', page: 1, groups: [], subgroups: [], meta: null };

const els = {
    filterGroup: document.getElementById('filter-group'),
    search: document.getElementById('subgroups-search'),
    list: document.getElementById('subgroups-list'),
    empty: document.getElementById('subgroups-empty'),
    loadMoreBtn: document.getElementById('load-more-btn'),
    addBtn: document.getElementById('add-subgroup-btn'),
};

function memberSuffix(count) {
    return `${count} ${count === 1 ? terminology.person_singular.toLowerCase() : terminology.person_plural.toLowerCase()}`;
}

const CHEVRON_SVG = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7.5 15L12.5 10L7.5 5" stroke="#4B5563" stroke-width="2" stroke-linecap="round"/></svg>';

function subgroupRowHtml(subgroup) {
    return `
        <div class="roster-row" data-subgroup-id="${subgroup.id}">
            <div class="roster-details">
                <p class="roster-name">${escapeHtml(subgroup.name)}</p>
                <div class="parent-badge">
                    <span>Parent:</span>
                    <span>${escapeHtml(subgroup.group?.name || '—')}</span>
                </div>
            </div>
            <div class="roster-row-right">
                <span class="roster-count">${escapeHtml(memberSuffix(subgroup.persons_count ?? 0))}</span>
                ${CHEVRON_SVG}
            </div>
        </div>
    `;
}

function renderSubgroups(append) {
    if (!append) els.list.innerHTML = '';
    els.list.insertAdjacentHTML('beforeend', state.subgroups.map(subgroupRowHtml).join(''));
    els.empty.hidden = state.subgroups.length > 0;
    els.loadMoreBtn.hidden = !state.meta || state.meta.current_page >= state.meta.last_page;

    els.list.querySelectorAll('[data-subgroup-id]').forEach((row) => {
        row.addEventListener('click', () => {
            window.location.href = `/subgroups/${row.getAttribute('data-subgroup-id')}/edit`;
        });
    });
}

function updateAddLink() {
    els.addBtn.href = state.groupId ? `/subgroups/create?group_id=${encodeURIComponent(state.groupId)}` : '/subgroups/create';
}

async function loadOptions() {
    try {
        const groupData = await get('/api/groups/options');
        state.groups = groupData.data || [];
        const options = state.groups.map((group) => `<option value="${group.id}">${escapeHtml(group.name)}</option>`).join('');
        els.filterGroup.innerHTML = `<option value="">All ${terminology.group_plural.toLowerCase()}</option>${options}`;
    } catch (error) {
        // Group options are a progressive enhancement over the base roster.
    }
}

async function loadSubgroups(append = false) {
    const params = new URLSearchParams({ page: String(state.page), per_page: '20' });
    if (state.search) params.set('search', state.search);
    if (state.groupId) params.set('group_id', state.groupId);

    const response = await get(`/api/subgroups?${params.toString()}`);
    const newSubgroups = response.data || [];
    state.subgroups = append ? [...state.subgroups, ...newSubgroups] : newSubgroups;
    state.meta = response;

    renderSubgroups(append);
}

async function refresh() {
    state.page = 1;
    await loadSubgroups(false);
}

let searchTimer = null;
els.search.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        state.search = els.search.value.trim();
        refresh();
    }, 300);
});

els.filterGroup.addEventListener('change', () => {
    state.groupId = els.filterGroup.value;
    updateAddLink();
    refresh();
});

els.loadMoreBtn.addEventListener('click', () => {
    state.page += 1;
    loadSubgroups(true);
});

loadOptions().then(refresh);
