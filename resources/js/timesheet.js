import { get } from './services/api';
import { downloadCsv } from './services/download';

const els = {
    from: document.getElementById('ts-from'),
    to: document.getElementById('ts-to'),
    group: document.getElementById('ts-group'),
    presets: document.querySelectorAll('[data-preset]'),
    downloads: document.querySelectorAll('[data-format]'),
    error: document.getElementById('ts-error'),
    count: document.getElementById('ts-count'),
    list: document.getElementById('ts-list'),
    empty: document.getElementById('ts-empty'),
};

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const hours = (minutes) => `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`;
const isoDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const prettyDate = (value) => new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
const query = () => {
    const params = new URLSearchParams();
    if (els.from.value && els.to.value) { params.set('from', els.from.value); params.set('to', els.to.value); }
    if (els.group.value) params.set('group_id', els.group.value);
    return params;
};

function setPreset(preset) {
    const base = els.from.value ? new Date(`${els.from.value}T00:00:00`) : new Date();
    const year = base.getFullYear();
    const month = base.getMonth();
    const lastDay = new Date(year, month + 1, 0).getDate();
    const [start, end] = { first: [1, 15], second: [16, lastDay], month: [1, lastDay] }[preset];
    els.from.value = isoDate(new Date(year, month, start));
    els.to.value = isoDate(new Date(year, month, end));
    load();
}

function stat(label, value, tone = '') {
    return `<div class="ts-stat ${tone}"><b>${escapeHtml(value)}</b><span>${escapeHtml(label)}</span></div>`;
}

function dayHtml(day) {
    const meta = [day.holiday && `Holiday: ${day.holiday}`, day.shift, day.time_in && `In ${day.time_in}`, day.time_out && `Out ${day.time_out}`, day.late_minutes && `${day.late_minutes} min late`, day.undertime_minutes && `${day.undertime_minutes} min undertime`, day.overtime_minutes && `OT ${hours(day.overtime_minutes)}`, day.no_time_out && 'No time out', day.no_time_in && 'No time in'].filter(Boolean).join(' · ');
    const worked = day.rest_day_minutes ? hours(day.rest_day_minutes) : day.regular_minutes ? hours(day.regular_minutes) : '–';

    return `<div class="ts-day">
        <div><p><b>${escapeHtml(prettyDate(day.date))}</b> · ${escapeHtml(day.status)}</p><p class="ts-day-meta">${escapeHtml(meta)}</p></div>
        <p class="ts-day-hours">${escapeHtml(worked)}</p>
    </div>`;
}

function cardHtml(row) {
    const totals = row.totals;
    const sub = [`#${row.person.person_number}`, row.person.group, row.person.subgroup, row.person.is_active ? null : 'Archived'].filter(Boolean).join(' · ');

    return `<div class="ts-card">
        <button type="button" class="ts-card-head">
            <div><p class="ts-name">${escapeHtml(row.person.full_name)}</p><p class="ts-sub">${escapeHtml(sub)}</p></div>
            <div class="ts-stats">
                ${stat('Regular', hours(totals.regular_minutes))}
                ${stat('Overtime', hours(totals.overtime_minutes))}
                ${stat('Late', `${totals.late_minutes} min`, totals.late_minutes ? 'ts-stat--warn' : '')}
                ${stat('Undertime', `${totals.undertime_minutes} min`, totals.undertime_minutes ? 'ts-stat--warn' : '')}
                ${stat('Days worked', totals.days_worked)}
                ${stat('Absent', totals.absences, totals.absences ? 'ts-stat--bad' : '')}
                ${stat('Incomplete', totals.incomplete_days, totals.incomplete_days ? 'ts-stat--bad' : '')}
                ${stat('Rest day', hours(totals.rest_day_minutes))}
            </div>
        </button>
        <div class="ts-days">${row.days.slice().reverse().map(dayHtml).join('') || '<p class="ts-empty">No shifts in this range.</p>'}</div>
    </div>`;
}

async function load() {
    els.error.classList.remove('visible');
    try {
        const data = await get(`/api/attendance/timesheet?${query()}`);
        els.from.value = data.from;
        els.to.value = data.to;
        els.count.textContent = `${data.people.length} ${data.people.length === 1 ? 'person' : 'people'} · ${prettyDate(data.from)} to ${prettyDate(data.to)} · tap a name for daily hours`;
        els.list.innerHTML = data.people.map(cardHtml).join('');
        els.empty.hidden = data.people.length > 0;
        els.list.querySelectorAll('.ts-card-head').forEach((head) => head.addEventListener('click', () => head.parentElement.classList.toggle('is-open')));
    } catch (error) {
        els.error.textContent = Object.values(error.errors || {})[0]?.[0] || error.message;
        els.error.classList.add('visible');
    }
}

async function loadGroups() {
    try {
        const groups = (await get('/api/groups/options')).data || [];
        els.group.insertAdjacentHTML('beforeend', groups.filter((group) => group.attendance_mode !== 'open').map((group) => `<option value="${group.id}">${escapeHtml(group.name)}</option>`).join(''));
    } catch (error) {
        // The filter is optional; the timesheet still loads for everyone.
    }
}

els.presets.forEach((button) => button.addEventListener('click', () => setPreset(button.dataset.preset)));
[els.from, els.to].forEach((input) => input.addEventListener('change', () => { if (els.from.value && els.to.value) load(); }));
els.group.addEventListener('change', load);
els.downloads.forEach((button) => button.addEventListener('click', async () => {
    const params = query();
    params.set('format', button.dataset.format);
    try {
        await downloadCsv(`/api/attendance/timesheet/export?${params}`, `timesheet-${button.dataset.format}.csv`);
    } catch (error) {
        els.error.textContent = 'Unable to download the CSV. Check the dates and try again.';
        els.error.classList.add('visible');
    }
}));

loadGroups();
load();
