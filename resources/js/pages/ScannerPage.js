import React, { useEffect, useRef, useState } from 'react';
import QrScanner from 'qr-scanner';
import { phaseTwoApi } from '../auth/auth';

const h = React.createElement;

const requestId = () => globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;

export function ScannerPage({ user, onLogout }) {
    const videoRef = useRef(null);
    const scannerRef = useRef(null);
    const lockedRef = useRef(false);
    const [state, setState] = useState({ status: 'starting', message: 'Starting camera…', result: null });
    const [manualPayload, setManualPayload] = useState('');

    const submit = async (payload) => {
        if (lockedRef.current || !payload) return;
        lockedRef.current = true;
        scannerRef.current?.pause();
        setState({ status: 'processing', message: 'Processing scan…', result: null });
        try {
            const result = await phaseTwoApi.scan(payload, requestId());
            setState({ status: result.success ? 'success' : 'notice', message: result.message, result });
        } catch (error) {
            setState({ status: 'notice', message: error.message, result: { code: error.code || 'REQUEST_FAILED' } });
        } finally {
            window.setTimeout(() => {
                lockedRef.current = false;
                scannerRef.current?.start().catch(() => {});
                setState((current) => ({ ...current, status: 'ready', message: 'Ready for next scan.' }));
            }, 1800);
        }
    };

    useEffect(() => {
        const video = videoRef.current;
        if (!video) return undefined;
        const scanner = new QrScanner(video, ({ data }) => submit(data), {
            preferredCamera: 'environment',
            highlightScanRegion: true,
            highlightCodeOutline: true,
            returnDetailedScanResult: true,
        });
        scannerRef.current = scanner;
        scanner.start().then(() => setState({ status: 'ready', message: 'Ready to scan.', result: null })).catch((error) => setState({ status: 'error', message: `Camera unavailable: ${error.message || 'allow camera access and try again.'}`, result: null }));
        return () => { scanner.stop(); scanner.destroy(); scannerRef.current = null; };
    }, []);

    const result = state.result?.data;
    const person = result?.person;
    return h('main', { className: 'app-shell' },
        h('header', { className: 'app-header' }, h('strong', null, 'MYO Attendance'), h('nav', null, [h('a', { href: '/app' }, 'Home'), h('button', { type: 'button', onClick: onLogout }, 'Log out')])),
        h('section', { className: 'app-card' },
            h('h1', null, 'Scanner'),
            h('p', null, state.message),
            h('video', { ref: videoRef, muted: true, playsInline: true, style: { width: '100%', maxWidth: '520px' } }),
            person && h('section', { role: 'status' }, h('h2', null, state.result.success ? '✓ Logged' : state.result.code), h('p', null, `${person.first_name} ${person.last_name}`), h('p', null, person.person_number), result.direction && h('p', null, `${result.direction} · ${result.local_time}${result.attendance_status ? ` · ${result.attendance_status}` : ''}`)),
            import.meta.env.DEV && h('form', { onSubmit: (event) => { event.preventDefault(); submit(manualPayload.trim()); } }, h('label', null, 'Development manual QR payload', h('input', { value: manualPayload, onChange: (event) => setManualPayload(event.target.value) })), h('button', { type: 'submit' }, 'Submit')),
        ),
    );
}
