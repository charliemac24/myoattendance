import React, { useEffect, useState } from 'react';
import { get, post, put } from '../services/api';

const h = React.createElement;
const accessUrl = '/api/organization/sms/access';
const templatesUrl = '/api/organization/sms/templates';
const preview = (body) => (body || '').replaceAll('{{name}}', 'Maria Santos').replaceAll('{{time}}', '8:05 AM').replaceAll('{{date}}', 'October 1, 2026');

export function SmsTemplatePage({ user, onLogout }) {
    const [state, setState] = useState(null); const [templates, setTemplates] = useState([]); const [selected, setSelected] = useState(null);
    const [loading, setLoading] = useState(true); const [error, setError] = useState(null); const [message, setMessage] = useState('');
    const canEdit = ['owner', 'admin'].includes(user.role);
    const load = async () => {
        setLoading(true); setError(null);
        try {
            const access = await get(accessUrl); setState(access.sms);
            if (access.sms.access === 'active') { const result = await get(templatesUrl); setTemplates(result.templates || []); }
        } catch (caught) { setError(caught); } finally { setLoading(false); }
    };
    useEffect(() => { load(); }, []);
    const requestAccess = async () => { setError(null); try { await post('/api/organization/sms/access/request', {}); setMessage('Your SMS access request has been submitted.'); await load(); } catch (caught) { setError(caught); } };
    const save = async () => {
        setError(null); setMessage('');
        try { const result = await put(templatesUrl + '/' + selected.template_key, { body: selected.body, enabled: selected.enabled }); setTemplates((current) => current.map((item) => item.template_key === selected.template_key ? result.template : item)); setMessage('Template saved.'); }
        catch (caught) { setError(caught); }
    };
    const nav = h('header', { className: 'app-header' }, h('strong', null, 'MYO Attendance'), h('nav', null, h('a', { href: '/app' }, 'Home'), h('button', { type: 'button', onClick: onLogout }, 'Log out')));
    if (loading) return h('main', { className: 'app-shell' }, nav, h('section', { className: 'app-card' }, 'Loading SMS settings…'));
    if (!state || state.access !== 'active') return h('main', { className: 'app-shell' }, nav, h('section', { className: 'sms-locked app-card' }, h('div', { className: 'sms-locked-icon' }, '✦'), h('p', { className: 'sms-kicker' }, 'SMS NOTIFICATIONS'), h('h1', null, state?.request_status === 'pending' ? 'Request submitted' : state?.access === 'expired' ? 'Subscription expired' : 'SMS is not enabled'), h('p', null, state?.request_status === 'pending' ? 'Your SMS access request is waiting for Super Admin approval.' : state?.access === 'expired' ? 'Request access again to renew SMS notifications.' : 'Send automatic attendance notifications through SMS.'), state?.requested_at && h('small', null, 'Requested ' + new Date(state.requested_at).toLocaleDateString()), state?.request_status !== 'pending' && canEdit && h('button', { className: 'sms-primary-button', onClick: requestAccess }, 'Request SMS access'), error && h('p', { className: 'sms-error' }, error.message)));
    const heading = h('div', { className: 'sms-heading' },
        h('div', null, h('p', { className: 'sms-kicker' }, 'SMS NOTIFICATIONS'), h('h1', null, 'Message templates'), h('p', null, 'Customize the attendance messages your organization sends.'), state.expires_at && h('span', { className: 'sms-active-pill' }, 'Active through ' + new Date(state.expires_at).toLocaleDateString())),
        h('span', { className: 'sms-help' }, 'No messages are sent from this page.'));
    const list = h('section', { className: 'sms-list app-card' },
        h('h2', null, 'Templates'), h('p', null, 'Use these variables: {{name}}, {{time}}, {{date}}.'),
        templates.map((template) => h('button', { className: 'sms-template-item ' + (selected?.template_key === template.template_key ? 'is-selected' : ''), key: template.template_key, onClick: () => setSelected({ ...template }) }, h('strong', null, template.name), h('span', null, template.enabled ? 'Enabled' : 'Disabled'), h('small', null, template.body))));
    const editor = selected && h('section', { className: 'sms-editor app-card' },
        h('div', { className: 'sms-editor-head' }, h('div', null, h('p', { className: 'sms-kicker' }, 'EDIT TEMPLATE'), h('h2', null, selected.name)), h('label', { className: 'sms-toggle' }, h('input', { type: 'checkbox', checked: selected.enabled, onChange: (event) => setSelected({ ...selected, enabled: event.target.checked }) }), 'Enabled')),
        h('label', { className: 'sms-body-label' }, 'Message body', h('textarea', { value: selected.body, maxLength: 1000, rows: 7, onChange: (event) => setSelected({ ...selected, body: event.target.value }) })),
        h('div', { className: 'sms-counter' }, selected.body.length + ' / 1000 characters'),
        h('div', { className: 'sms-preview' }, h('small', null, 'PREVIEW'), h('p', null, preview(selected.body))),
        canEdit && h('button', { className: 'sms-primary-button', onClick: save }, 'Save template'));
    return h('main', { className: 'app-shell sms-page' }, nav, heading, error && h('p', { className: 'sms-error' }, error.message), message && h('p', { className: 'sms-success', role: 'status' }, message), h('div', { className: 'sms-layout' }, list, editor));
}
