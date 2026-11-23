<x-layouts.app
    :user="$user"
    active-tab="more"
    page-title="Team · {{ config('app.name') }}"
>
    <x-slot:head>
        <style>
            .team-list { display: flex; flex-direction: column; gap: 10px; width: 100%; }
            .team-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; }
            .team-row-text { flex: 1; min-width: 0; }
            .team-row-name { margin: 0; font-size: 14px; font-weight: 700; color: #111827; }
            .team-row-sub { margin: 2px 0 0; font-size: 12px; color: #6b7280; overflow-wrap: anywhere; }
            .team-role { padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; background: #e5e7eb; color: #374151; }
            .team-role--owner { background: #dbeafe; color: #1d4ed8; }
            .team-role--admin { background: #ede9fe; color: #6d28d9; }
            .team-row-actions { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
            .team-link { border: 0; background: none; padding: 4px; font: inherit; font-size: 12px; font-weight: 700; color: #2563eb; cursor: pointer; }
            .team-link--danger { color: #dc2626; }
            .team-roles { display: flex; flex-direction: column; gap: 8px; }
            .team-role-option { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; cursor: pointer; }
            .team-role-option input { margin-top: 3px; }
            .team-role-option strong { display: block; font-size: 14px; color: #111827; }
            .team-role-option span { font-size: 12px; color: #6b7280; }
        </style>
    </x-slot:head>

    <div class="people-roster" style="padding:16px;gap:20px;">
        <div class="terminology-explain-card">
            <p>Add the people who scan attendance. Staff can only use the scanner. Admins can do everything the owner can, except add other admins.</p>
        </div>

        <p class="form-error" id="team-error" role="alert" style="width:100%;"></p>
        <p class="form-success" id="team-success" role="status" style="width:100%;"></p>

        <div class="terminology-section">
            <p class="terminology-section-label">Your team</p>
            <div class="team-list" id="team-list"><p class="team-row-sub">Loading&hellip;</p></div>
        </div>

        <form id="team-form" novalidate class="terminology-section">
            <p class="terminology-section-label">Add a team member</p>
            <div class="terminology-field">
                <label class="terminology-field-label" for="team-name">Name</label>
                <input type="text" id="team-name" class="terminology-input" maxlength="255" autocomplete="off" required>
            </div>
            <div class="terminology-row">
                <div class="terminology-field">
                    <label class="terminology-field-label" for="team-username">Username (for signing in)</label>
                    <input type="text" id="team-username" class="terminology-input" maxlength="50" autocomplete="off" autocapitalize="none" required>
                </div>
                <div class="terminology-field">
                    <label class="terminology-field-label" for="team-password">Password</label>
                    <input type="text" id="team-password" class="terminology-input" autocomplete="new-password" required>
                </div>
            </div>
            <div class="team-roles" id="team-roles">
                <label class="team-role-option"><input type="radio" name="team-role" value="staff" checked><div><strong>Staff</strong><span>Can only scan attendance.</span></div></label>
                <label class="team-role-option" id="team-role-admin" hidden><input type="radio" name="team-role" value="admin"><div><strong>Admin</strong><span>Can manage people, schedules, reports and settings.</span></div></label>
            </div>
            <button type="submit" class="btn-primary" id="team-save">Add Team Member</button>
        </form>
    </div>

    <x-slot:scripts>
        <script>
            (function () {
                const list = document.getElementById('team-list');
                const form = document.getElementById('team-form');
                const errorBox = document.getElementById('team-error');
                const successBox = document.getElementById('team-success');
                const saveBtn = document.getElementById('team-save');
                const fields = { name: document.getElementById('team-name'), username: document.getElementById('team-username'), password: document.getElementById('team-password') };
                let canAddAdmins = false;

                const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));

                function showMessage(box, text) {
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

                function render(members) {
                    list.innerHTML = members.map((member) => {
                        const login = member.username ? '@' + member.username : (member.email || '');
                        const actions = member.can_manage ? `
                            <div class="team-row-actions">
                                ${canAddAdmins ? `<button type="button" class="team-link" data-action="role" data-id="${member.id}" data-role="${member.role}" data-name="${escapeHtml(member.name)}">${member.role === 'staff' ? 'Make admin' : 'Make staff'}</button>` : ''}
                                <button type="button" class="team-link" data-action="password" data-id="${member.id}" data-role="${member.role}" data-name="${escapeHtml(member.name)}">Reset password</button>
                                <button type="button" class="team-link team-link--danger" data-action="remove" data-id="${member.id}" data-name="${escapeHtml(member.name)}">Remove</button>
                            </div>` : '';
                        return `<div class="team-row">
                            <div class="team-row-text">
                                <p class="team-row-name">${escapeHtml(member.name)}${member.is_you ? ' (you)' : ''}</p>
                                <p class="team-row-sub">${escapeHtml(login)}</p>
                            </div>
                            <span class="team-role team-role--${escapeHtml(member.role)}">${escapeHtml(member.role_label)}</span>
                            ${actions}
                        </div>`;
                    }).join('');
                }

                async function load() {
                    try {
                        const result = await call('/api/team', 'GET');
                        canAddAdmins = result.can_add_admins;
                        document.getElementById('team-role-admin').hidden = !canAddAdmins;
                        render(result.members);
                    } catch (error) {
                        showMessage(errorBox, window.MyoAuth.firstErrorMessage(error));
                    }
                }

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    showMessage(null);
                    saveBtn.disabled = true;
                    const role = form.querySelector('input[name="team-role"]:checked').value;
                    try {
                        await call('/api/team', 'POST', { name: fields.name.value, username: fields.username.value, password: fields.password.value, role });
                        showMessage(successBox, `${fields.name.value} can now sign in with username "${fields.username.value.trim().toLowerCase()}".`);
                        Object.values(fields).forEach((input) => { input.value = ''; });
                        await load();
                    } catch (error) {
                        showMessage(errorBox, window.MyoAuth.firstErrorMessage(error));
                    } finally {
                        saveBtn.disabled = false;
                    }
                });

                list.addEventListener('click', async (event) => {
                    const button = event.target.closest('[data-action]');
                    if (!button) return;
                    const { action, id, name, role } = button.dataset;
                    try {
                        if (action === 'remove') {
                            if (!window.confirm(`Remove ${name}? They will no longer be able to sign in.`)) return;
                            await call(`/api/team/${id}`, 'DELETE');
                            showMessage(successBox, `${name} was removed.`);
                        } else if (action === 'password') {
                            const password = window.prompt(`New password for ${name}:`);
                            if (!password) return;
                            await call(`/api/team/${id}`, 'PUT', { name, role, password });
                            showMessage(successBox, `Password updated for ${name}.`);
                        } else if (action === 'role') {
                            const newRole = role === 'staff' ? 'admin' : 'staff';
                            await call(`/api/team/${id}`, 'PUT', { name, role: newRole });
                            showMessage(successBox, `${name} is now ${newRole === 'admin' ? 'an Admin' : 'Staff'}.`);
                        }
                        await load();
                    } catch (error) {
                        showMessage(errorBox, window.MyoAuth.firstErrorMessage(error));
                    }
                });

                load();
            })();
        </script>
    </x-slot:scripts>
</x-layouts.app>
