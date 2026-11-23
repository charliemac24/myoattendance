import { get, post, put, del } from './services/api';

const config = window.MyoSchedules || { terminology: {} };
const terminology = config.terminology;

const WEEKDAYS = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}

function formatTime12h(value) {
    if (!value) return '';
    const [hourStr, minuteStr] = value.split(':');
    const hour = Number(hourStr);
    const period = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;
    return `${String(hour12).padStart(2, '0')}:${minuteStr} ${period}`;
}

const state = { search: '', groups: [], subgroups: [], schedules: [], cards: [] };

const els = {
    search: document.getElementById('schedules-search'),
    list: document.getElementById('schedules-list'),
    empty: document.getElementById('schedules-empty'),
    editOverlay: document.getElementById('edit-schedule-overlay'),
    editForm: document.getElementById('edit-schedule-form'),
    editError: document.getElementById('edit-schedule-error'),
    editCancel: document.getElementById('edit-schedule-cancel'),
    editDelete: document.getElementById('edit-schedule-delete'),
    esGroup: document.getElementById('es-group'),
    esSubgroup: document.getElementById('es-subgroup'),
    esDays: document.getElementById('es-days'),
    esPeriods: document.getElementById('es-periods'),
    esAddPeriod: document.getElementById('es-add-period'),
    esPeriodTemplate: document.getElementById('es-period-card-template'),
    deleteOverlay: document.getElementById('delete-schedule-overlay'),
    deleteTarget: document.getElementById('delete-schedule-target'),
    deleteError: document.getElementById('delete-schedule-error'),
    deleteConfirm: document.getElementById('delete-schedule-confirm'),
    deleteCancel: document.getElementById('delete-schedule-cancel'),
};

let activeGroup = null;

function openSheet(overlay) { overlay.hidden = false; }
function closeSheet(overlay) { overlay.hidden = true; }

const CLOCK_SVG = '<svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="7.5" stroke="#111827" stroke-width="1.5"/><path d="M10 5.83v4.17l2.92 1.67" stroke="#111827" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

function groupSchedules(schedules) {
    const groups = new Map();
    schedules.forEach((schedule) => {
        const key = `${schedule.name}|${schedule.group_id}|${schedule.subgroup_id ?? ''}|${schedule.break_minutes ?? 0}`;
        if (!groups.has(key)) {
            groups.set(key, { ...schedule, key, days: [], rows: [] });
        }
        const group = groups.get(key);
        group.days.push(schedule.day_of_week);
        group.rows.push(schedule);
    });
    return Array.from(groups.values());
}

function fullScopeLabel(schedule) {
    const groupName = state.groups.find((group) => String(group.id) === String(schedule.group_id))?.name || schedule.group?.name || '';
    return schedule.subgroup?.name ? `${groupName} • ${schedule.subgroup.name}` : (groupName || `Entire ${terminology.group_singular}`);
}

function scheduleCardHtml(schedule) {
    const days = [...schedule.days].sort((a, b) => a - b).map((day) => WEEKDAYS[day]).join(', ');
    const scopeLabel = schedule.subgroup?.name || `Entire ${terminology.group_singular}`;
    const periods = (schedule.periods && schedule.periods.length ? schedule.periods : [{ start_time: schedule.start_time, late_after: schedule.late_after, end_time: schedule.end_time }]);
    const periodsHtml = periods.map((period) => `
        <div class="schedule-period-row">
            <span class="schedule-period-time">${CLOCK_SVG}${escapeHtml(formatTime12h(period.start_time))}${period.end_time ? ' – ' + escapeHtml(formatTime12h(period.end_time)) + (period.end_time.slice(0, 5) <= period.start_time.slice(0, 5) ? ' (next day)' : '') : ''}</span>
            <span class="schedule-period-late">Late after ${escapeHtml(formatTime12h(period.late_after))}</span>
        </div>
    `).join('') + (schedule.break_minutes ? `<div class="schedule-period-row"><span class="schedule-period-late">Unpaid break: ${escapeHtml(schedule.break_minutes)} min</span></div>` : '');

    return `
        <div class="schedule-card" data-schedule-key="${escapeHtml(schedule.key)}">
            <div class="schedule-card-header">
                <div>
                    <p class="schedule-card-title">${escapeHtml(schedule.name)}</p>
                    <p class="schedule-card-scope">Applies to: <strong>${escapeHtml(scopeLabel)}</strong></p>
                </div>
                <span class="badge-pill ${schedule.is_active ? 'badge-pill--success' : 'badge-pill--neutral'}">${schedule.is_active ? 'ACTIVE' : 'INACTIVE'}</span>
            </div>
            <div class="schedule-section">
                <p class="schedule-section-label">Active Days</p>
                <p class="schedule-days-value">${escapeHtml(days)}</p>
            </div>
            <div class="schedule-section">
                <p class="schedule-section-label">Shifts &amp; Windows</p>
                <div class="schedule-periods">${periodsHtml}</div>
            </div>
        </div>
    `;
}

function renderSchedules() {
    const cards = groupSchedules(state.schedules);
    state.cards = cards;
    els.list.innerHTML = cards.map(scheduleCardHtml).join('');
    els.empty.hidden = cards.length > 0;

    els.list.querySelectorAll('[data-schedule-key]').forEach((card) => {
        card.addEventListener('click', () => openEditSheet(card.getAttribute('data-schedule-key')));
    });
}

async function loadOptions() {
    try {
        const groupData = await get('/api/groups/options');
        state.groups = groupData.data || [];
        const subgroupData = await get('/api/subgroups/options');
        state.subgroups = subgroupData.data || [];

        const options = state.groups.map((group) => `<option value="${group.id}">${escapeHtml(group.name)}</option>`).join('');
        els.esGroup.innerHTML = `<option value="">Select a ${terminology.group_singular.toLowerCase()}</option>${options}`;
    } catch (error) {
        // Group/subgroup options are a progressive enhancement over the base form.
    }
}

function updateSubgroupOptions(selectEl, groupId, preselectId) {
    const filtered = state.subgroups.filter((subgroup) => String(subgroup.group_id) === String(groupId));
    selectEl.disabled = !groupId;
    selectEl.innerHTML = `<option value="">Entire ${terminology.group_singular.toLowerCase()}</option>${filtered.map((subgroup) => `<option value="${subgroup.id}">${escapeHtml(subgroup.name)}</option>`).join('')}`;
    if (preselectId) selectEl.value = String(preselectId);
}

async function refresh() {
    const params = new URLSearchParams({ per_page: '100' });
    if (state.search) params.set('search', state.search);
    const response = await get(`/api/schedules?${params.toString()}`);
    state.schedules = response.data || [];
    renderSchedules();
}

let searchTimer = null;
els.search.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        state.search = els.search.value.trim();
        refresh();
    }, 300);
});

// Time period editor (shared by the edit sheet)
function renumberPeriods(container) {
    const cards = container.querySelectorAll('[data-period]');
    cards.forEach((card, index) => {
        card.querySelector('[data-period-label]').textContent = `Period #${index + 1}`;
        card.querySelector('[data-period-remove]').hidden = cards.length <= 1;
    });
}

function addPeriodCard(container, template, period) {
    const fragment = template.content.cloneNode(true);
    const card = fragment.querySelector('[data-period]');
    card.querySelector('[data-period-start]').value = period?.start_time?.slice(0, 5) || '';
    card.querySelector('[data-period-late]').value = period?.late_after?.slice(0, 5) || '';
    card.querySelector('[data-period-end]').value = period?.end_time ? period.end_time.slice(0, 5) : '';
    card.querySelector('[data-period-remove]').addEventListener('click', () => {
        card.remove();
        renumberPeriods(container);
    });
    container.appendChild(fragment);
    renumberPeriods(container);
}

function readPeriods(container) {
    return Array.from(container.querySelectorAll('[data-period]')).map((card) => ({
        start_time: card.querySelector('[data-period-start]').value,
        late_after: card.querySelector('[data-period-late]').value,
        end_time: card.querySelector('[data-period-end]').value || null,
    }));
}

els.esAddPeriod.addEventListener('click', () => {
    if (els.esPeriods.querySelectorAll('[data-period]').length >= 8) return;
    addPeriodCard(els.esPeriods, els.esPeriodTemplate, null);
});

// Edit schedule sheet
function openEditSheet(key) {
    const group = state.cards.find((card) => card.key === key);
    if (!group) return;
    activeGroup = group;

    els.editError.classList.remove('visible');
    document.getElementById('es-name').value = group.name;
    els.esGroup.value = String(group.group_id);
    updateSubgroupOptions(els.esSubgroup, group.group_id, group.subgroup_id);
    els.esDays.querySelectorAll('.weekday-chip').forEach((chip) => {
        chip.classList.toggle('is-selected', group.days.includes(Number(chip.getAttribute('data-day'))));
    });
    const periods = (group.rows[0]?.periods && group.rows[0].periods.length)
        ? group.rows[0].periods
        : [{ start_time: group.start_time, late_after: group.late_after, end_time: group.end_time }];
    els.esPeriods.innerHTML = '';
    periods.forEach((period) => addPeriodCard(els.esPeriods, els.esPeriodTemplate, period));
    const breakInput = document.getElementById('es-break');
    if (breakInput) breakInput.value = group.rows[0]?.break_minutes ?? 0;

    openSheet(els.editOverlay);
}

els.editCancel.addEventListener('click', () => closeSheet(els.editOverlay));
els.editOverlay.addEventListener('click', (event) => { if (event.target === els.editOverlay) closeSheet(els.editOverlay); });

els.esGroup.addEventListener('change', () => updateSubgroupOptions(els.esSubgroup, els.esGroup.value));

els.esDays.querySelectorAll('.weekday-chip').forEach((chip) => {
    chip.addEventListener('click', () => chip.classList.toggle('is-selected'));
});

els.editForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!activeGroup) return;
    els.editError.classList.remove('visible');

    const newDays = Array.from(els.esDays.querySelectorAll('.weekday-chip.is-selected')).map((chip) => Number(chip.getAttribute('data-day')));
    if (!newDays.length) {
        els.editError.textContent = 'Select at least one active day.';
        els.editError.classList.add('visible');
        return;
    }

    const periods = readPeriods(els.esPeriods);
    if (!periods.length || periods.some((period) => !period.start_time || !period.late_after)) {
        els.editError.textContent = 'Each time window needs a start time and a late-after threshold.';
        els.editError.classList.add('visible');
        return;
    }

    const submitBtn = document.getElementById('edit-schedule-submit');
    submitBtn.disabled = true;

    const payload = {
        name: document.getElementById('es-name').value,
        group_id: els.esGroup.value,
        subgroup_id: els.esSubgroup.value || null,
        start_time: periods[0].start_time,
        late_after: periods[0].late_after,
        end_time: periods[0].end_time,
        periods,
        ...(document.getElementById('es-break') ? { break_minutes: Number(document.getElementById('es-break').value || 0) } : {}),
    };

    const originalDays = activeGroup.rows.map((row) => row.day_of_week);
    const rowsToUpdate = activeGroup.rows.filter((row) => newDays.includes(row.day_of_week));
    const rowsToDelete = activeGroup.rows.filter((row) => !newDays.includes(row.day_of_week));
    const daysToCreate = newDays.filter((day) => !originalDays.includes(day));

    try {
        for (const row of rowsToUpdate) {
            await put(`/api/schedules/${row.id}`, { ...payload, day_of_week: row.day_of_week });
        }
        if (daysToCreate.length) {
            await post('/api/schedules', { ...payload, days: daysToCreate });
        }
        for (const row of rowsToDelete) {
            await del(`/api/schedules/${row.id}`);
        }
        closeSheet(els.editOverlay);
        await refresh();
    } catch (error) {
        const messages = Object.values(error.errors || {}).flat();
        els.editError.textContent = messages[0] || error.message || 'Unable to save this schedule.';
        els.editError.classList.add('visible');
    } finally {
        submitBtn.disabled = false;
    }
});

els.editDelete.addEventListener('click', () => {
    if (!activeGroup) return;
    els.deleteError.classList.remove('visible');
    els.deleteTarget.textContent = fullScopeLabel(activeGroup);
    openSheet(els.deleteOverlay);
});

els.deleteCancel.addEventListener('click', () => closeSheet(els.deleteOverlay));
els.deleteOverlay.addEventListener('click', (event) => { if (event.target === els.deleteOverlay) closeSheet(els.deleteOverlay); });

els.deleteConfirm.addEventListener('click', async () => {
    if (!activeGroup) return;
    els.deleteError.classList.remove('visible');
    els.deleteConfirm.disabled = true;

    try {
        for (const row of activeGroup.rows) {
            await del(`/api/schedules/${row.id}`);
        }
        closeSheet(els.deleteOverlay);
        closeSheet(els.editOverlay);
        await refresh();
    } catch (error) {
        els.deleteError.textContent = error.message || 'Unable to delete this schedule.';
        els.deleteError.classList.add('visible');
    } finally {
        els.deleteConfirm.disabled = false;
    }
});

loadOptions().then(refresh);
