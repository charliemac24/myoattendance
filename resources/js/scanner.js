import QrScanner from 'qr-scanner';
import { post } from './services/api';

const requestId = () => globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}

const els = {
    video: document.getElementById('scanner-video'),
    frame: document.getElementById('scanner-frame'),
    backBtn: document.getElementById('scanner-back'),
    flashBtn: document.getElementById('scanner-flash'),
    guidance: document.getElementById('scanner-guidance'),
    statusDot: document.getElementById('scanner-status-dot'),
    statusTitle: document.getElementById('scanner-status-title'),
    statusMessage: document.getElementById('scanner-status-message'),
    sessionCount: document.getElementById('scanner-session-count'),
    loaderBox: document.getElementById('scanner-loader-box'),
    resultOverlay: document.getElementById('scanner-result-overlay'),
    resultSeal: document.getElementById('scanner-result-seal'),
    resultName: document.getElementById('scanner-result-name'),
    resultId: document.getElementById('scanner-result-id'),
    resultMeta: document.getElementById('scanner-result-meta'),
    notification: document.getElementById('scanner-notification'),
    notificationIcon: document.getElementById('scanner-notification-icon'),
    notificationTitle: document.getElementById('scanner-notification-title'),
    notificationMessage: document.getElementById('scanner-notification-message'),
    notificationClose: document.getElementById('scanner-notification-close'),
};

let scanner = null;
let locked = false;
let sessionScans = 0;
let notificationTimer = null;

function showNotification(kind, title, message, duration = 5000) {
    window.clearTimeout(notificationTimer);
    els.notification.className = `scanner-notification scanner-notification--${kind}`;
    els.notificationIcon.textContent = kind === 'success' ? '✓' : kind === 'error' ? '!' : 'i';
    els.notificationTitle.textContent = title;
    els.notificationMessage.textContent = message;
    els.notification.hidden = false;
    notificationTimer = window.setTimeout(hideNotification, duration);
}

function hideNotification() {
    window.clearTimeout(notificationTimer);
    els.notification.hidden = true;
}

function setStatus(kind, title, message) {
    els.statusDot.className = `scanner-status-dot scanner-status-dot--${kind}`;
    els.statusTitle.textContent = title;
    els.statusMessage.textContent = message;
    els.frame.classList.toggle('is-error', kind === 'error');
    els.frame.classList.toggle('is-notice', kind === 'notice');
    els.frame.classList.toggle('is-processing', kind === 'processing');
    els.loaderBox.hidden = kind !== 'processing';
}

function bumpSessionCount() {
    sessionScans += 1;
    els.sessionCount.textContent = `${sessionScans} Total Scan${sessionScans === 1 ? '' : 's'}`;
}

function metaRow(label, valueHtml) {
    return `<div class="scanner-meta-row"><p class="scanner-meta-label">${label}</p>${valueHtml}</div>`;
}

function showSuccessDialog(result) {
    const person = result.data?.person;
    const direction = result.data?.direction === 'OUT' ? 'OUT' : 'IN';
    const status = result.data?.attendance_status;

    els.resultName.textContent = person ? `${person.first_name} ${person.last_name}` : 'Attendance recorded';
    els.resultId.textContent = person ? `ID: ${person.person_number}` : '';

    const rows = [
        metaRow('Direction', `<span class="scanner-badge scanner-badge--direction">${escapeHtml(direction)}</span>`),
        metaRow('Time Registered', `<p class="scanner-meta-value">${escapeHtml(result.data?.local_time || '')}</p>`),
    ];
    if (status) {
        const badgeClass = status === 'LATE' ? 'scanner-badge--late' : 'scanner-badge--present';
        rows.push(metaRow('Assigned Status', `<span class="scanner-badge ${badgeClass}">${escapeHtml(status)}</span>`));
    }
    if (result.data?.holiday) {
        rows.push(metaRow('Holiday', `<p class="scanner-meta-value">${escapeHtml(result.data.holiday)}${result.data.rest_day ? ' · counted as holiday hours' : ''}</p>`));
    }
    if (result.data?.visits_today > 1) {
        rows.push(metaRow('Visits Today', `<p class="scanner-meta-value">${escapeHtml(result.data.visits_today)}</p>`));
    }
    const membership = result.data?.membership;
    if (membership && membership.days_left <= 7) {
        const when = membership.days_left === 0 ? 'today' : membership.days_left === 1 ? 'tomorrow' : `in ${membership.days_left} days`;
        rows.push(metaRow('Membership', `<p class="scanner-meta-value">Ends ${escapeHtml(when)} (${escapeHtml(membership.valid_until)})</p>`));
    }
    if (result.data?.no_time_in) {
        rows.push(metaRow('Note', `<p class="scanner-meta-value">No time in today. Saved as time out.</p>`));
    }
    els.resultMeta.innerHTML = rows.join('');
    els.resultSeal.classList.remove('is-notice', 'is-error');

    els.frame.classList.remove('is-processing');
    els.loaderBox.hidden = true;
    els.guidance.hidden = true;
    els.resultOverlay.hidden = false;

    showNotification(
        'success',
        'Attendance recorded',
        `${els.resultName.textContent}${result.data?.local_time ? ` · ${result.data.local_time}` : ''}`,
    );
}

function hideResultDialog() {
    els.resultOverlay.hidden = true;
    els.guidance.hidden = false;
}

async function submit(payload) {
    if (locked || !payload) return;
    locked = true;
    scanner?.pause();
    hideResultDialog();
    setStatus('processing', 'Processing Scan...', 'Checking profile identity. Hold device steady to prevent duplicates.');

    try {
        const result = await post('/api/attendance/scan', { qr_payload: payload, scan_request_id: requestId() });
        bumpSessionCount();

        if (result.success) {
            showSuccessDialog(result);
        } else {
            const message = result.message || 'Scan not recorded.';
            const title = result.code === 'MEMBERSHIP_EXPIRED' ? 'Membership expired' : 'Scan not recorded';
            setStatus('notice', title, personSummary(result));
            showNotification('notice', title, result.code === 'MEMBERSHIP_EXPIRED' ? `${personSummary(result)}. ${message}` : message);
        }
    } catch (error) {
        setStatus('error', 'Scan failed', error.message || 'Unable to reach the server. Try again.');
        showNotification('error', 'Scan failed', error.message || 'Unable to reach the server. Try again.');
    } finally {
        window.setTimeout(() => {
            locked = false;
            scanner?.start().catch(() => {});
            hideResultDialog();
            setStatus('ready', 'Ready to Scan', 'Align a barcode or QR code inside the frame to register attendance.');
        }, 1800);
    }
}

function personSummary(result) {
    const person = result.data?.person;
    if (!person) return 'Try scanning again.';
    return `${person.first_name} ${person.last_name} · #${person.person_number}`;
}

els.backBtn.addEventListener('click', () => {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        window.location.href = '/app';
    }
});

async function init() {
    scanner = new QrScanner(els.video, ({ data }) => submit(data), {
        preferredCamera: 'environment',
        highlightScanRegion: false,
        highlightCodeOutline: false,
        returnDetailedScanResult: true,
    });

    try {
        await scanner.start();
        setStatus('ready', 'Ready to Scan', 'Align a barcode or QR code inside the frame to register attendance.');

        const hasFlash = await scanner.hasFlash().catch(() => false);
        if (hasFlash) {
            els.flashBtn.hidden = false;
            els.flashBtn.addEventListener('click', async () => {
                await scanner.toggleFlash();
                els.flashBtn.classList.toggle('is-active', scanner.isFlashOn());
            });
        }
    } catch (error) {
        const message = `Allow camera access in your browser settings and reload. ${error?.message || ''}`.trim();
        setStatus('error', 'Camera unavailable', message);
        showNotification('error', 'Camera unavailable', message, 5000);
    }
}

els.notificationClose.addEventListener('click', hideNotification);

window.addEventListener('beforeunload', () => {
    scanner?.stop();
    scanner?.destroy();
});

init();
