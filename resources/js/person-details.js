import QRCode from 'qrcode';
import { get, post } from './services/api';
import { downloadCsv } from './services/download';

const config = window.MyoPersonDetails || {};
const personId = config.personId;

const els = {
    viewQrBtn: document.getElementById('view-qr-btn'),
    qrOverlay: document.getElementById('qr-overlay'),
    qrTitle: document.getElementById('qr-title'),
    qrImage: document.getElementById('qr-image'),
    qrPayload: document.getElementById('qr-payload'),
    qrRegenerate: document.getElementById('qr-regenerate'),
    qrClose: document.getElementById('qr-close'),
};

function openSheet(overlay) { overlay.hidden = false; }
function closeSheet(overlay) { overlay.hidden = true; }

async function showQr(payload) {
    els.qrTitle.textContent = 'QR Card';
    els.qrImage.src = await QRCode.toDataURL(payload);
    els.qrPayload.textContent = payload;
    openSheet(els.qrOverlay);
}

els.viewQrBtn.addEventListener('click', async () => {
    const result = await get(`/api/people/${personId}/qr`);
    await showQr(result.qr_payload);
});

els.qrRegenerate.addEventListener('click', async () => {
    if (!window.confirm('Regenerate this QR code? The previous code will stop working.')) return;
    const result = await post(`/api/people/${personId}/regenerate-qr`);
    await showQr(result.qr_payload);
});

els.qrClose.addEventListener('click', () => closeSheet(els.qrOverlay));
els.qrOverlay.addEventListener('click', (event) => { if (event.target === els.qrOverlay) closeSheet(els.qrOverlay); });

/* Attendance history */
const history = {
    from: document.getElementById('history-from'),
    to: document.getElementById('history-to'),
    presets: document.querySelectorAll('.history-preset'),
    error: document.getElementById('history-error'),
    rate: document.getElementById('history-rate'),
    rateSub: document.getElementById('history-rate-sub'),
    late: document.getElementById('history-late'),
    absent: document.getElementById('history-absent'),
    lastSeen: document.getElementById('history-last-seen'),
    lastSeenSub: document.getElementById('history-last-seen-sub'),
    days: document.getElementById('history-days'),
    empty: document.getElementById('history-empty'),
    download: document.getElementById('history-download'),
};

const STATUS = {
    present: { label: 'Present', tag: 'attendance-log-status-tag--present' },
    late: { label: 'Late', tag: 'attendance-log-status-tag--late' },
    absent: { label: 'Absent', tag: 'attendance-log-status-tag--absent' },
    not_yet: { label: 'Not yet', tag: 'attendance-log-status-tag--waiting' },
    holiday: { label: 'Holiday', tag: 'attendance-log-status-tag--neutral' },
};

const isoDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const prettyDate = (value) => new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
const rangeQuery = () => (history.from.value && history.to.value ? new URLSearchParams({ from: history.from.value, to: history.to.value }).toString() : '');
const daysAgo = (days) => (days === 0 ? 'today' : days === 1 ? 'yesterday' : `${days} days ago`);
let organizationToday = null;

function setPreset(days) {
    const today = organizationToday ? new Date(`${organizationToday}T00:00:00`) : new Date();
    const start = new Date(today);
    start.setDate(today.getDate() - (days - 1));
    history.from.value = isoDate(start);
    history.to.value = isoDate(today);
    history.presets.forEach((button) => button.classList.toggle('is-active', Number(button.dataset.days) === days));
}

const formatMinutes = (minutes) => `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`;

function dayDetails(day, openHours) {
    const parts = [];
    if (openHours && day.visits > 1) parts.push(`${day.visits} visits`);
    if (day.time_in) parts.push(`In ${day.time_in}`);
    if (day.time_out) parts.push(`Out ${day.time_out}`);
    if (day.minutes_late) parts.push(`${day.minutes_late} min late`);
    const work = (window.MyoPersonDetails || {}).tracksWorkHours ? day.work : null;
    if (work) {
        const worked = work.regular_minutes || work.rest_day_minutes;
        if (worked) parts.push(`Worked ${formatMinutes(worked)}${work.rest_day_minutes ? ' (rest day)' : ''}`);
        if (work.overtime_minutes) parts.push(`OT ${formatMinutes(work.overtime_minutes)}`);
        if (work.undertime_minutes) parts.push(`${work.undertime_minutes} min undertime`);
        if (work.incomplete) parts.push('Hours not counted: missing scan');
    }
    if (day.holiday) parts.push(`Holiday: ${day.holiday}${day.status === 'holiday' ? '' : ' (worked)'}`);
    if (day.no_time_in) parts.push('No time in');
    if (day.no_time_out) parts.push('No time out');
    if (!day.scheduled && !openHours && !day.holiday) parts.push('Not a scheduled day');
    if (day.status === 'absent') parts.push('No scan');
    if (day.status === 'not_yet') parts.push('Today is not over yet');

    return parts.join(' · ');
}

function render(data) {
    const { summary } = data;
    const openHours = data.person.open_hours;
    // Open-hours members are never late or absent: show their visits instead.
    history.rate.previousElementSibling.textContent = openHours ? 'Visits' : 'Attendance rate';
    history.late.previousElementSibling.textContent = openHours ? 'Days visited' : 'Late';
    history.late.nextElementSibling.textContent = openHours ? 'in this range' : 'days';
    history.absent.parentElement.hidden = openHours;
    history.late.parentElement.classList.toggle('history-stat--late', !openHours);
    if (openHours) {
        history.rate.textContent = summary.visits;
        history.rateSub.textContent = 'in this range';
        history.late.textContent = summary.days_visited;
    } else {
        history.rate.textContent = summary.attendance_rate === null ? '–' : `${summary.attendance_rate}%`;
        history.rateSub.textContent = summary.scheduled_days ? `${summary.attended} of ${summary.scheduled_days} days` : 'No scheduled days';
        history.late.textContent = summary.late;
        history.absent.textContent = summary.absent;
    }
    history.lastSeen.textContent = summary.last_seen_date ? new Date(`${summary.last_seen_date}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : 'Never';
    history.lastSeenSub.textContent = summary.last_seen_date ? `${summary.last_seen_time} · ${daysAgo(summary.days_since_last_seen)}` : 'No scans yet';
    organizationToday = data.today;
    history.from.value = data.from;
    history.to.value = data.to;

    history.days.innerHTML = data.days.map((day) => {
        const status = openHours && day.status === 'present' ? { label: 'Visited', tag: STATUS.present.tag } : STATUS[day.status];

        return `<div class="history-day">
            <div class="history-day-copy">
                <p class="history-day-date">${escapeHtml(prettyDate(day.date))}</p>
                <p class="history-day-meta">${escapeHtml(dayDetails(day, openHours))}</p>
            </div>
            <span class="attendance-log-status-tag ${status.tag}">${status.label}</span>
        </div>`;
    }).join('');
    history.empty.hidden = data.days.length > 0;
}

async function loadHistory() {
    history.error.classList.remove('visible');
    history.error.textContent = '';
    try {
        render(await get(`/api/people/${personId}/attendance?${rangeQuery()}`));
    } catch (error) {
        history.error.textContent = Object.values(error.errors || {})[0]?.[0] || error.message;
        history.error.classList.add('visible');
    }
}

history.presets.forEach((button) => button.addEventListener('click', () => {
    setPreset(Number(button.dataset.days));
    loadHistory();
}));
[history.from, history.to].forEach((input) => input.addEventListener('change', () => {
    history.presets.forEach((button) => button.classList.remove('is-active'));
    if (history.from.value && history.to.value) loadHistory();
}));
history.download.addEventListener('click', async () => {
    history.download.disabled = true;
    try {
        await downloadCsv(`/api/people/${personId}/attendance/export?${rangeQuery()}`, 'attendance.csv');
    } catch (error) {
        history.error.textContent = 'Unable to download CSV. Please try again.';
        history.error.classList.add('visible');
    } finally {
        history.download.disabled = false;
    }
});

loadHistory();
