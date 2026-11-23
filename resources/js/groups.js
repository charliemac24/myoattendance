import { get } from './services/api';

const config = window.MyoGroups || { terminology: {} };
const terminology = config.terminology;

const PALETTE = [
    { bg: 'rgba(99,102,241,0.08)', color: '#6366f1' },
    { bg: 'rgba(59,130,246,0.08)', color: '#3b82f6' },
    { bg: 'rgba(16,185,129,0.08)', color: '#10b981' },
    { bg: 'rgba(245,158,11,0.08)', color: '#f59e0b' },
    { bg: 'rgba(239,68,68,0.08)', color: '#ef4444' },
    { bg: 'rgba(139,92,246,0.08)', color: '#8b5cf6' },
];

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}

const state = { search: '', page: 1, groups: [], meta: null };

const els = {
    search: document.getElementById('groups-search'),
    list: document.getElementById('groups-list'),
    empty: document.getElementById('groups-empty'),
    loadMoreBtn: document.getElementById('load-more-btn'),
};

function memberSuffix(count) {
    return `${count} ${count === 1 ? terminology.person_singular : terminology.person_plural}`;
}

const CHEVRON_SVG = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7.5 15L12.5 10L7.5 5" stroke="#4B5563" stroke-width="2" stroke-linecap="round"/></svg>';

function groupRowHtml(group, index) {
    const palette = PALETTE[index % PALETTE.length];
    const initial = (group.name || '?').charAt(0).toUpperCase();

    return `
        <div class="roster-row" data-group-id="${group.id}">
            <div class="roster-row-left">
                <div class="roster-avatar" style="background:${palette.bg};color:${palette.color};">${escapeHtml(initial)}</div>
                <div class="roster-details">
                    <p class="roster-name">${escapeHtml(group.name)}</p>
                    <p class="roster-meta">${escapeHtml(memberSuffix(group.persons_count ?? 0))}${group.attendance_mode === 'open' ? ' · Anytime' : ''}</p>
                </div>
            </div>
            <div class="roster-row-right">
                <span class="badge-pill badge-pill--success">ACTIVE</span>
                ${CHEVRON_SVG}
            </div>
        </div>
    `;
}

function renderGroups(append) {
    if (!append) els.list.innerHTML = '';
    els.list.insertAdjacentHTML('beforeend', state.groups.map((group, index) => groupRowHtml(group, index)).join(''));
    els.empty.hidden = state.groups.length > 0;
    els.loadMoreBtn.hidden = !state.meta || state.meta.current_page >= state.meta.last_page;

    els.list.querySelectorAll('[data-group-id]').forEach((row) => {
        row.addEventListener('click', () => {
            const id = row.getAttribute('data-group-id');
            window.location.href = `/groups/${id}/edit`;
        });
    });
}

async function loadGroups(append = false) {
    const params = new URLSearchParams({ page: String(state.page), per_page: '20' });
    if (state.search) params.set('search', state.search);

    const response = await get(`/api/groups?${params.toString()}`);
    const newGroups = response.data || [];
    state.groups = append ? [...state.groups, ...newGroups] : newGroups;
    state.meta = response;

    renderGroups(append);
}

async function refresh() {
    state.page = 1;
    await loadGroups(false);
}

let searchTimer = null;
els.search.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        state.search = els.search.value.trim();
        refresh();
    }, 300);
});

els.loadMoreBtn.addEventListener('click', () => {
    state.page += 1;
    loadGroups(true);
});

refresh();
