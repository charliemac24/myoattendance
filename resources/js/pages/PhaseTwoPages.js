import React, { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { phaseTwoApi } from '../auth/auth';

const h = React.createElement;
const api = phaseTwoApi;
const link = (label, path) => h('a', { href: path, onClick: (event) => { event.preventDefault(); window.history.pushState({}, '', path); window.dispatchEvent(new PopStateEvent('popstate')); } }, label);

function ErrorText({ error }) {
    if (!error) return null;
    return h('div', { className: 'auth-error', role: 'alert' }, [...Object.values(error.errors || {}).flat(), error.message].filter(Boolean).map((message, i) => h('p', { key: `${message}-${i}` }, message)));
}

function Field({ label, name, value, onChange, type = 'text', required = false }) {
    return h('label', null, label, h('input', { name, type, value: value ?? '', required, onChange: (event) => onChange(event.target.value) }));
}

function SelectField({ label, value, onChange, options, placeholder, required = false, disabled = false }) {
    return h('label', null, label, h('select', { value: value ?? '', required, disabled, onChange: (event) => onChange(event.target.value) }, h('option', { value: '' }, placeholder), options.map((option) => h('option', { key: option.id, value: option.id }, option.name))));
}

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

function DaySelector({ selected, onChange, disabled = false, editing = false }) {
    return h('fieldset', { 'aria-label': 'Schedule days' }, h('legend', null, editing ? 'Day' : 'Days'), ...DAYS.map((day, index) => h('label', { key: day }, h('input', { type: editing ? 'radio' : 'checkbox', name: editing ? 'schedule-day' : `schedule-day-${day}`, value: index + 1, checked: selected.includes(index + 1), disabled, onChange: () => onChange(index + 1) }), day)));
}

function Shell({ title, children, user, terminology, onLogout }) {
    return h('main', { className: 'app-shell' },
        h('header', { className: 'app-header' }, h('strong', null, 'MYO Attendance'), h('nav', null, [link(terminology.person_plural, '/people'), link(terminology.group_plural, '/groups'), link(terminology.subgroup_plural, '/subgroups'), link('Schedules', '/schedules'), link('Labels', '/settings/terminology'), link('Account', '/settings/account'), h('button', { type: 'button', onClick: onLogout }, 'Log out')])),
        h('section', { className: 'app-card' }, h('h1', null, title), h('p', null, `Organization: ${user.organization?.name || ''}`), children),
    );
}

function useCollection(path) {
    const [state, setState] = useState({ data: [], loading: true });
    const load = () => api.get(path).then(setState);
    useEffect(() => { load().catch(() => setState({ data: [], loading: false })); }, [path]);
    return [state, load];
}

export function ResourcePage({ title, path, fields, user, terminology, onLogout }) {
    const scheduleMode = path === '/api/schedules';
    const [search, setSearch] = useState(''); const [collection, reload] = useCollection(`${path}?search=${encodeURIComponent(search)}`); const [values, setValues] = useState({}); const [editing, setEditing] = useState(null); const [error, setError] = useState(null); const [groups, setGroups] = useState([]); const [subgroups, setSubgroups] = useState([]); const [optionsLoading, setOptionsLoading] = useState(path === '/api/subgroups' || scheduleMode); const [periods, setPeriods] = useState([{ start_time: '', late_after: '', end_time: '' }]); const [days, setDays] = useState([]);
    useEffect(() => { if (!optionsLoading) return; Promise.all([api.get('/api/groups/options'), api.get('/api/subgroups/options')]).then(([groupData, subgroupData]) => { setGroups(groupData.data || []); setSubgroups(subgroupData.data || []); }).catch(() => setError({ message: 'Unable to load group options.' })).finally(() => setOptionsLoading(false)); }, [optionsLoading]);
    const setValue = (name, value) => setValues((current) => ({ ...current, [name]: value, ...(name === 'group_id' ? { subgroup_id: '' } : {}) }));
    const submit = async (event) => { event.preventDefault(); setError(null); try { if (scheduleMode && !days.length) { setError({ message: 'Select at least one day.' }); return; } const payload = scheduleMode ? { ...values, day_of_week: days[0], ...(editing ? {} : { days }), start_time: periods[0].start_time, late_after: periods[0].late_after, end_time: periods[0].end_time || null, periods } : values; if (editing) await api.put(`${path}/${editing}`, payload); else await api.post(path, payload); setValues({}); setDays([]); setPeriods([{ start_time: '', late_after: '', end_time: '' }]); setEditing(null); await reload(); } catch (caught) { setError(caught); } };
    const remove = async (id) => { if (!window.confirm('Delete this record?')) return; try { await api.del(`${path}/${id}`); await reload(); } catch (caught) { setError(caught); } };
    const formChildren = fields.filter((field) => !scheduleMode || !['start_time', 'late_after', 'end_time', 'day_of_week'].includes(field.name)).map((field) => field.name === 'group_id' ? h(SelectField, { ...field, key: field.name, label: terminology.group_singular, value: values.group_id || '', options: groups, placeholder: optionsLoading ? `Loading ${terminology.group_plural.toLowerCase()}…` : `Select ${terminology.group_singular.toLowerCase()}…`, disabled: optionsLoading, onChange: (value) => setValue('group_id', value) }) : field.name === 'subgroup_id' ? h(SelectField, { ...field, key: field.name, label: terminology.subgroup_singular, value: values.subgroup_id || '', options: subgroups.filter((item) => String(item.group_id) === String(values.group_id)), placeholder: !values.group_id ? `Select a ${terminology.group_singular.toLowerCase()} first` : 'Entire group / no subgroup', disabled: optionsLoading || !values.group_id, onChange: (value) => setValue('subgroup_id', value) }) : h(Field, { ...field, key: field.name, value: values[field.name] || '', onChange: (value) => setValue(field.name, value) }));
    if (scheduleMode) {
        formChildren.push(h(DaySelector, { key: 'days', selected: days, editing: Boolean(editing), disabled: optionsLoading, onChange: (day) => setDays((current) => editing ? [day] : current.includes(day) ? current.filter((item) => item !== day) : [...current, day].sort((a, b) => a - b)) }));
        formChildren.push(h('fieldset', { key: 'periods' }, h('legend', null, 'Time periods'), ...periods.map((period, index) => h('div', { key: index }, h(Field, { label: `Period ${index + 1} start`, name: `period-${index}-start`, type: 'time', value: period.start_time, required: true, onChange: (value) => setPeriods((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, start_time: value } : item)) }), h(Field, { label: `Period ${index + 1} late after`, name: `period-${index}-late`, type: 'time', value: period.late_after, required: true, onChange: (value) => setPeriods((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, late_after: value } : item)) }), h(Field, { label: `Period ${index + 1} end`, name: `period-${index}-end`, type: 'time', value: period.end_time, onChange: (value) => setPeriods((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, end_time: value } : item)) }), periods.length > 1 && h('button', { type: 'button', onClick: () => setPeriods((current) => current.filter((_, itemIndex) => itemIndex !== index)) }, 'Remove period')))), h('button', { type: 'button', onClick: () => setPeriods((current) => [...current, { start_time: '', late_after: '', end_time: '' }]) }, 'Add time period'));
    }
    formChildren.push(h('button', { type: 'submit', key: 'submit' }, editing ? 'Save' : 'Add'));
    if (editing) formChildren.push(h('button', { type: 'button', key: 'cancel', onClick: () => { setEditing(null); setValues({}); setDays([]); setPeriods([{ start_time: '', late_after: '', end_time: '' }]); } }, 'Cancel'));
    formChildren.push(h(ErrorText, { error, key: 'error' }));
    const form = h('form', { onSubmit: submit }, formChildren);
    const items = collection.data.map((item) => h('li', { key: item.id }, [h('span', { key: 'name' }, item.name), h('button', { key: 'edit', type: 'button', onClick: () => { setEditing(item.id); setValues({ name: item.name, group_id: item.group_id, subgroup_id: item.subgroup_id, day_of_week: item.day_of_week }); if (scheduleMode) { setDays([item.day_of_week]); setPeriods(item.periods || [{ start_time: item.start_time, late_after: item.late_after, end_time: item.end_time || '' }]); } } }, 'Edit'), h('button', { key: 'delete', type: 'button', onClick: () => remove(item.id) }, 'Delete')]));
    const list = h('section', null, [h('input', { key: 'search', value: search, placeholder: 'Search', onChange: (event) => setSearch(event.target.value) }), h('p', { key: 'count' }, collection.loading ? 'Loading…' : `${collection.data.length} records`), h('ul', { key: 'items' }, items), h(ErrorText, { error, key: 'error' })]);
    return h(Shell, { title, user, terminology, onLogout }, h('div', { className: 'two-column' }, form, list));
}

export function PeoplePage({ user, terminology, onLogout }) {
    const [search, setSearch] = useState(''); const [collection, reload] = useCollection(`/api/people?search=${encodeURIComponent(search)}`); const [groups, setGroups] = useState([]); const [subgroups, setSubgroups] = useState([]); const [values, setValues] = useState({ person_number: '', first_name: '', last_name: '', group_id: '', subgroup_id: '', contact_number: '' }); const [error, setError] = useState(null); const [qr, setQr] = useState(null); const [file, setFile] = useState(null); const [summary, setSummary] = useState(null); const [optionsLoading, setOptionsLoading] = useState(true);
    useEffect(() => { Promise.all([api.get('/api/groups/options'), api.get('/api/subgroups/options')]).then(([groupData, subgroupData]) => { setGroups(groupData.data || []); setSubgroups(subgroupData.data || []); }).catch(() => setError({ message: 'Unable to load group options.' })).finally(() => setOptionsLoading(false)); }, []);
    const set = (name) => (value) => setValues((current) => ({ ...current, [name]: value, ...(name === 'group_id' ? { subgroup_id: '' } : {}) }));
    const submit = async (event) => { event.preventDefault(); setError(null); try { await api.post('/api/people', { ...values, group_id: values.group_id || null, subgroup_id: values.subgroup_id || null }); setValues({ person_number: '', first_name: '', last_name: '', group_id: '', subgroup_id: '', contact_number: '' }); await reload(); } catch (caught) { setError(caught); } };
    const importCsv = async () => { if (!file) return; try { setSummary(await api.upload('/api/people/import', file)); await reload(); } catch (caught) { setError(caught); } };
    const showQr = async (person) => { const result = await api.get(`/api/people/${person.id}/qr`); setQr({ person, image: await QRCode.toDataURL(result.qr_payload), payload: result.qr_payload }); };
    const regenerate = async (person) => { const result = await api.post(`/api/people/${person.id}/regenerate-qr`); setQr({ person: result.person, image: await QRCode.toDataURL(result.qr_payload), payload: result.qr_payload }); };
    const archive = async (person) => { await api.post(`/api/people/${person.id}/archive`); await reload(); };
    const form = h('form', { onSubmit: submit }, [h(Field, { label: 'Person number', name: 'person_number', value: values.person_number, onChange: set('person_number'), required: true }), h(Field, { label: 'First name', name: 'first_name', value: values.first_name, onChange: set('first_name'), required: true }), h(Field, { label: 'Last name', name: 'last_name', value: values.last_name, onChange: set('last_name'), required: true }), h(Field, { label: 'Contact number', name: 'contact_number', value: values.contact_number, onChange: set('contact_number') }), h(SelectField, { label: terminology.group_singular, value: values.group_id, options: groups, placeholder: optionsLoading ? `Loading ${terminology.group_plural.toLowerCase()}…` : `No ${terminology.group_singular.toLowerCase()}`, disabled: optionsLoading, onChange: set('group_id') }), h(SelectField, { label: terminology.subgroup_singular, value: values.subgroup_id, options: subgroups.filter((item) => String(item.group_id) === String(values.group_id)), placeholder: !values.group_id ? `Select a ${terminology.group_singular.toLowerCase()} first` : 'No subgroup', disabled: optionsLoading || !values.group_id, onChange: set('subgroup_id') }), h('button', { type: 'submit', key: 'add', disabled: optionsLoading }, `Add ${terminology.person_singular.toLowerCase()}`), h(ErrorText, { error, key: 'error' }), h('hr', { key: 'hr' }), h('label', { key: 'csv' }, 'CSV import', h('input', { type: 'file', accept: '.csv,text/csv', onChange: (event) => setFile(event.target.files[0]) })), h('button', { type: 'button', key: 'import', onClick: importCsv }, 'Import CSV'), summary && h('pre', { key: 'summary' }, JSON.stringify(summary, null, 2))]);
    const people = collection.data.map((person) => h('article', { className: 'list-card', key: person.id }, [h('strong', { key: 'name' }, `${person.person_number} — ${person.first_name} ${person.last_name}`), h('span', { key: 'details' }, `${person.group?.name || 'No group'}${person.subgroup?.name ? ` / ${person.subgroup.name}` : ''} · ${person.is_active ? 'Active' : 'Archived'}`), h('button', { key: 'view', type: 'button', onClick: () => showQr(person) }, 'View QR'), h('button', { key: 'regenerate', type: 'button', onClick: () => regenerate(person) }, 'Regenerate QR'), person.is_active && h('button', { key: 'archive', type: 'button', onClick: () => archive(person) }, 'Archive')]));
    const qrCard = qr && h('section', { className: 'qr-card', key: 'qr' }, [h('h2', { key: 'heading' }, `QR: ${qr.person.person_number}`), h('img', { key: 'image', src: qr.image, alt: 'Person QR code' }), h('code', { key: 'payload' }, qr.payload), h('button', { key: 'close', type: 'button', onClick: () => setQr(null) }, 'Close')]);
    const list = h('section', null, [h('input', { key: 'search', value: search, placeholder: 'Search people', onChange: (event) => setSearch(event.target.value) }), ...people, qrCard]);
    return h(Shell, { title: terminology.person_plural, user, terminology, onLogout }, h('div', { className: 'two-column' }, form, list));
}
