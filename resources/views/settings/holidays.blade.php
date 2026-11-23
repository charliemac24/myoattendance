<x-layouts.app
    :user="$user"
    active-tab="more"
    page-title="Holidays · {{ config('app.name') }}"
>
    <x-slot:head>
        <style>
            .hol-list { display: flex; flex-direction: column; gap: 8px; width: 100%; }
            .hol-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; }
            .hol-row.is-past { opacity: .6; }
            .hol-text { flex: 1; min-width: 0; }
            .hol-name { margin: 0; font-size: 14px; font-weight: 700; color: #111827; }
            .hol-sub { margin: 2px 0 0; font-size: 12px; color: #6b7280; }
            .hol-remove { border: 0; background: none; padding: 4px; font: inherit; font-size: 12px; font-weight: 700; color: #dc2626; cursor: pointer; }
            .hol-common { display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: end; }
            .hol-input { width: 100%; padding: 10px 14px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; font: inherit; font-size: 14px; }
            .hol-empty { margin: 0; text-align: center; color: #6b7280; font-size: 14px; padding: 18px 0; }
        </style>
    </x-slot:head>

    <div class="people-roster" style="padding:16px;gap:20px;">
        <div class="terminology-explain-card">
            <p>On a holiday nobody is marked absent or late, and reports show "Holiday". Employees who work that day get holiday hours on the Timesheet.</p>
        </div>

        <p class="form-error" id="hol-error" role="alert" style="width:100%;"></p>
        <p class="form-success" id="hol-success" role="status" style="width:100%;"></p>

        <form id="hol-form" novalidate class="terminology-section">
            <p class="terminology-section-label">Add a holiday</p>
            <div class="terminology-field">
                <label class="terminology-field-label" for="hol-name">Name</label>
                <input type="text" id="hol-name" class="terminology-input" maxlength="120" placeholder="e.g. Christmas Day, Typhoon closure" required>
            </div>
            <div class="terminology-row">
                <div class="terminology-field">
                    <label class="terminology-field-label" for="hol-from">Date</label>
                    <input type="date" id="hol-from" class="terminology-input" required>
                </div>
                <div class="terminology-field">
                    <label class="terminology-field-label" for="hol-to">Until (optional)</label>
                    <input type="date" id="hol-to" class="terminology-input">
                </div>
            </div>
            <div class="terminology-field">
                <label class="terminology-field-label" for="hol-group">Applies to</label>
                <select id="hol-group" class="terminology-input"><option value="">Everyone</option></select>
            </div>
            <button type="submit" class="btn-primary" id="hol-save">Add Holiday</button>
        </form>

        <div class="terminology-section">
            <p class="terminology-section-label">Add common holidays</p>
            <div class="hol-common">
                <div class="terminology-field">
                    <label class="terminology-field-label" for="hol-year">Philippine holidays for</label>
                    <select id="hol-year" class="hol-input"></select>
                </div>
                <button type="button" class="btn-primary" id="hol-common-btn" style="width:auto;padding-left:20px;padding-right:20px;">Add</button>
            </div>
            <p class="hol-sub">Fixed-date holidays and National Heroes Day. Holy Week and Islamic holidays change every year, so add those by hand.</p>
        </div>

        <div class="terminology-section">
            <p class="terminology-section-label">Your holidays</p>
            <div class="hol-list" id="hol-list"><p class="hol-sub">Loading&hellip;</p></div>
        </div>
    </div>

    <x-slot:scripts>
        <script>
            (function () {
                const list = document.getElementById('hol-list');
                const form = document.getElementById('hol-form');
                const errorBox = document.getElementById('hol-error');
                const successBox = document.getElementById('hol-success');
                const fields = { name: document.getElementById('hol-name'), from: document.getElementById('hol-from'), to: document.getElementById('hol-to'), group: document.getElementById('hol-group') };
                const yearSelect = document.getElementById('hol-year');
                const today = new Date().toISOString().slice(0, 10);

                const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
                const pretty = (value) => new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });

                function show(box, text) {
                    errorBox.classList.remove('visible');
                    successBox.classList.remove('visible');
                    if (!box) return;
                    box.textContent = text;
                    box.classList.add('visible');
                }

                async function call(url, method, data) {
                    if (method === 'GET' || method === 'DELETE') {
                        const response = await fetch(url, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': window.MyoAuth.csrfToken() } });
                        const payload = await response.json().catch(() => ({}));
                        if (!response.ok) { const error = new Error(payload.message || 'Unable to complete the request.'); error.errors = payload.errors || {}; throw error; }
                        return payload;
                    }
                    return window.MyoAuth.submitJson(url, data, method);
                }

                function render(holidays) {
                    if (!holidays.length) {
                        list.innerHTML = '<p class="hol-empty">No holidays yet. Add one above, or add the common ones.</p>';
                        return;
                    }
                    const upcoming = holidays.filter((holiday) => holiday.ends_on >= today);
                    const past = holidays.filter((holiday) => holiday.ends_on < today).reverse();
                    list.innerHTML = [...upcoming, ...past].map((holiday) => {
                        const range = holiday.starts_on === holiday.ends_on ? pretty(holiday.starts_on) : `${pretty(holiday.starts_on)} to ${pretty(holiday.ends_on)}`;
                        const scope = holiday.group ? ` · ${escapeHtml(holiday.group)} only` : '';
                        return `<div class="hol-row ${holiday.ends_on < today ? 'is-past' : ''}">
                            <div class="hol-text"><p class="hol-name">${escapeHtml(holiday.name)}</p><p class="hol-sub">${escapeHtml(range)}${scope}</p></div>
                            <button type="button" class="hol-remove" data-id="${holiday.id}" data-name="${escapeHtml(holiday.name)}">Remove</button>
                        </div>`;
                    }).join('');
                }

                async function load() {
                    try {
                        render((await call('/api/holidays', 'GET')).holidays);
                    } catch (error) {
                        show(errorBox, window.MyoAuth.firstErrorMessage(error));
                    }
                }

                async function loadGroups() {
                    try {
                        const groups = (await call('/api/groups/options', 'GET')).data || [];
                        fields.group.insertAdjacentHTML('beforeend', groups.map((group) => `<option value="${group.id}">${escapeHtml(group.name)} only</option>`).join(''));
                    } catch (error) {
                        // Holidays still work for everyone without the group list.
                    }
                }

                const year = new Date().getFullYear();
                yearSelect.innerHTML = [year - 1, year, year + 1].map((value) => `<option value="${value}" ${value === year ? 'selected' : ''}>${value}</option>`).join('');

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    show(null);
                    try {
                        await call('/api/holidays', 'POST', { name: fields.name.value, starts_on: fields.from.value, ends_on: fields.to.value || null, group_id: fields.group.value || null });
                        show(successBox, `${fields.name.value.trim()} added.`);
                        fields.name.value = '';
                        fields.from.value = '';
                        fields.to.value = '';
                        await load();
                    } catch (error) {
                        show(errorBox, window.MyoAuth.firstErrorMessage(error));
                    }
                });

                document.getElementById('hol-common-btn').addEventListener('click', async () => {
                    show(null);
                    try {
                        const result = await call('/api/holidays/common', 'POST', { year: Number(yearSelect.value) });
                        show(successBox, result.message);
                        await load();
                    } catch (error) {
                        show(errorBox, window.MyoAuth.firstErrorMessage(error));
                    }
                });

                list.addEventListener('click', async (event) => {
                    const button = event.target.closest('.hol-remove');
                    if (!button) return;
                    if (!window.confirm(`Remove ${button.dataset.name}? Reports for that day will count absences and lateness again.`)) return;
                    try {
                        await call(`/api/holidays/${button.dataset.id}`, 'DELETE');
                        show(successBox, `${button.dataset.name} removed.`);
                        await load();
                    } catch (error) {
                        show(errorBox, window.MyoAuth.firstErrorMessage(error));
                    }
                });

                loadGroups();
                load();
            })();
        </script>
    </x-slot:scripts>
</x-layouts.app>
