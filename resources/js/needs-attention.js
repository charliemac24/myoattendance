import { get } from './services/api';
import { downloadCsv } from './services/download';

const config = window.MyoNeedsAttention || {};
const els = {
    days: document.getElementById('attention-days'),
    download: document.getElementById('attention-download'),
    error: document.getElementById('attention-error'),
    count: document.getElementById('attention-count'),
    list: document.getElementById('attention-list'),
    empty: document.getElementById('attention-empty'),
};

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const query = () => new URLSearchParams({ not_seen_days: els.days.value }).toString();
const lastSeenText = (person) => {
    if (!person.last_seen_date) return 'Never scanned';
    const days = person.days_since_last_seen;
    const date = new Date(`${person.last_seen_date}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });

    return `Last seen ${date}${days === 0 ? ' (today)' : days === 1 ? ' (yesterday)' : ` (${days} days ago)`}`;
};

function showError(message) {
    els.error.textContent = message;
}

function render(data) {
    const label = data.total === 1 ? config.personSingular : config.personPlural;
    els.count.textContent = `${data.total} ${label} need${data.total === 1 ? 's' : ''} attention`;
    els.list.innerHTML = data.people.map((person) => {
        const scope = [person.group, person.subgroup].filter(Boolean).join(' · ');
        const meta = [`#${person.person_number}`, scope, person.contact_number].filter(Boolean).join(' · ');
        const rate = person.attendance_rate === null ? '' : ` · attended ${person.attended_days} of ${person.scheduled_days} scheduled days in the last 30 days`;

        return `<a class="attention-row" href="/people/${person.id}">
            <div>
                <p class="attention-name">${escapeHtml(person.full_name)}</p>
                <p class="attention-meta">${escapeHtml(meta)}</p>
            </div>
            <div class="attention-reasons">${person.reasons.map((reason) => `<span class="attention-reason attention-reason--${reason.type}">${escapeHtml(reason.label)}</span>`).join('')}</div>
            <p class="attention-meta">${escapeHtml(lastSeenText(person))}${escapeHtml(rate)}</p>
        </a>`;
    }).join('');
    els.empty.hidden = data.total > 0;
}

async function load() {
    showError('');
    try {
        render(await get(`/api/attendance/needs-attention?${query()}`));
    } catch (error) {
        showError(error.message);
    }
}

els.days.addEventListener('change', load);
els.download.addEventListener('click', async () => {
    els.download.disabled = true;
    try {
        await downloadCsv(`/api/attendance/needs-attention/export?${query()}`, 'needs-attention.csv');
    } catch (error) {
        showError('Unable to download CSV. Please try again.');
    } finally {
        els.download.disabled = false;
    }
});

load();
