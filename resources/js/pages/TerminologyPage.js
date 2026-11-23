import React, { useState } from 'react';
import { phaseTwoApi } from '../auth/auth';
import { terminologyFor } from '../terminology';

const h = React.createElement;
const fields = [
    ['person_singular', 'Person (singular)', 'The label for one person record.'], ['person_plural', 'People (plural)', 'The label used for lists and navigation.'],
    ['group_singular', 'Group (singular)', 'The label for one group.'], ['group_plural', 'Groups (plural)', 'The label used for lists and navigation.'],
    ['subgroup_singular', 'Subgroup (singular)', 'The label for one subgroup.'], ['subgroup_plural', 'Subgroups (plural)', 'The label used for lists and navigation.'],
];

export function TerminologyPage({ user, onSave, onLogout }) {
    const [values, setValues] = useState(terminologyFor(user));
    const [error, setError] = useState(null);
    const [message, setMessage] = useState('');
    const set = (key) => (event) => setValues((current) => ({ ...current, [key]: event.target.value }));
    const save = async (event) => {
        event.preventDefault(); setError(null); setMessage('');
        try { const result = await phaseTwoApi.put('/api/organization/terminology', values); setValues(result.terminology); onSave(result.terminology); setMessage('Labels saved.'); } catch (caught) { setError(caught); }
    };
    const reset = async () => {
        setError(null); setMessage('');
        try { const result = await phaseTwoApi.post('/api/organization/terminology/reset'); setValues(result.terminology); onSave(result.terminology); setMessage('Default labels restored.'); } catch (caught) { setError(caught); }
    };
    const formFields = fields.map(([key, label, hint]) => h('label', { key }, label, h('small', null, hint), h('input', { value: values[key], onChange: set(key), maxLength: 40, required: true })));
    formFields.push(h('button', { type: 'submit', key: 'save' }, 'Save labels'), h('button', { type: 'button', key: 'reset', onClick: reset }, 'Restore defaults'));
    return h('main', { className: 'app-shell' }, h('header', { className: 'app-header' }, h('strong', null, 'MYO Attendance'), h('nav', null, h('a', { href: '/app' }, 'Home'), h('button', { type: 'button', onClick: onLogout }, 'Log out'))), h('section', { className: 'app-card' }, h('h1', null, 'Organization labels'), h('p', null, 'Choose the words your team sees. This changes labels only; your records and workflows stay the same.'), h('form', { onSubmit: save }, formFields), message && h('p', { role: 'status' }, message), error && h('p', { role: 'alert' }, error.message)));
}
