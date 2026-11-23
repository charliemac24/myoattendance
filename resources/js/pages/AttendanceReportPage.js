import React, { useEffect, useState } from 'react';
import { getAttendanceReport, logout, phaseTwoApi } from '../auth/auth';

const h = React.createElement;
const localToday = (timeZone) => new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());

export function AttendanceReportPage({ user, terminology, onLogout }) {
    const [date, setDate] = useState(localToday(user.organization?.timezone || 'UTC'));
    const [filters, setFilters] = useState({ status: '', search: '', group_id: '', subgroup_id: '' });
    const [report, setReport] = useState(null);
    const [groups, setGroups] = useState([]);
    const [subgroups, setSubgroups] = useState([]);
    const [error, setError] = useState('');

    const load = async () => {
        try {
            setError('');
            setReport(await getAttendanceReport({ date, ...filters }));
        } catch (nextError) { setError(nextError.message); }
    };
    useEffect(() => {
        Promise.all([phaseTwoApi.get('/api/groups/options'), phaseTwoApi.get('/api/subgroups/options')])
            .then(([groupData, subgroupData]) => { setGroups(groupData.data || []); setSubgroups(subgroupData.data || []); })
            .catch(() => {});
        load();
    }, []);

    const update = (key) => (event) => setFilters((current) => ({ ...current, [key]: event.target.value, ...(key === 'group_id' ? { subgroup_id: '' } : {}) }));
    const clearFilters = async () => { const emptyFilters = { status: '', search: '', group_id: '', subgroup_id: '' }; setFilters(emptyFilters); try { setError(''); setReport(await getAttendanceReport({ date, ...emptyFilters })); } catch (nextError) { setError(nextError.message); } };
    const selectedGroup = groups.find((group) => String(group.id) === String(filters.group_id));
    const selectedSubgroup = subgroups.find((subgroup) => String(subgroup.id) === String(filters.subgroup_id));
    return h('main', { className: 'app-shell' },
        h('header', { className: 'app-header' }, h('strong', null, 'Daily Attendance'), h('nav', null, h('a', { href: '/app' }, 'Home'), h('button', { type: 'button', onClick: async () => { await logout(); onLogout(); } }, 'Log out'))),
        h('section', null, h('label', null, 'Date ', h('input', { type: 'date', value: date, onChange: (event) => setDate(event.target.value) })), h('button', { type: 'button', onClick: load }, 'Load report')),
        h('section', { 'aria-label': 'Attendance filters' }, h('label', null, `Search ${terminology.person_plural.toLowerCase()}`, h('input', { placeholder: `Search ${terminology.person_plural.toLowerCase()}`, value: filters.search, onChange: update('search') })), h('label', null, 'Status', h('select', { value: filters.status, onChange: update('status') }, h('option', { value: '' }, 'All statuses'), h('option', { value: 'PRESENT' }, 'Present'), h('option', { value: 'LATE' }, 'Late'), h('option', { value: 'ABSENT' }, 'Absent'))), h('label', null, terminology.group_singular, h('select', { value: filters.group_id, onChange: update('group_id') }, h('option', { value: '' }, `All ${terminology.group_plural.toLowerCase()}`), groups.map((group) => h('option', { key: group.id, value: group.id }, group.name)))), h('label', null, terminology.subgroup_singular, h('select', { value: filters.subgroup_id, disabled: !filters.group_id, onChange: update('subgroup_id') }, h('option', { value: '' }, filters.group_id ? `All ${terminology.subgroup_plural.toLowerCase()}` : `Select a ${terminology.group_singular.toLowerCase()} first`), subgroups.filter((subgroup) => !filters.group_id || String(subgroup.group_id) === String(filters.group_id)).map((subgroup) => h('option', { key: subgroup.id, value: subgroup.id }, subgroup.name)))), h('button', { type: 'button', onClick: load }, 'Apply filters'), h('button', { type: 'button', onClick: clearFilters, disabled: !Object.values(filters).some(Boolean) }, 'Clear filters')),
        (selectedGroup || selectedSubgroup || filters.status || filters.search) && h('p', { role: 'status' }, ['Showing', selectedGroup?.name, selectedSubgroup?.name, filters.status || null, filters.search ? `matching “${filters.search}”` : null].filter(Boolean).join(' · ')),
        error && h('p', { role: 'alert' }, error),
        report && h(React.Fragment, null, h('p', null, `Selected date: ${report.date}`), h('dl', null, h('dt', null, 'Total'), h('dd', null, report.summary.expected_total), h('dt', null, 'Present'), h('dd', null, report.summary.present), h('dt', null, 'Late'), h('dd', null, report.summary.late), h('dt', null, 'Absent'), h('dd', null, report.summary.absent)), h('table', null, h('thead', null, h('tr', null, ['Person', 'Group', 'Subgroup', 'Status', 'IN', 'OUT'].map((heading) => h('th', { key: heading }, heading)))), h('tbody', null, report.people.map((row) => h('tr', { key: row.person.id }, h('td', null, `${row.person.first_name} ${row.person.last_name} (${row.person.person_number})`), h('td', null, row.group?.name || '—'), h('td', null, row.subgroup?.name || '—'), h('td', null, row.status), h('td', null, row.check_in_time || '—'), h('td', null, row.check_out_time || '—'))))))
    );
}
