<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Edit {{ $terminology['person_singular'] }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700,800">
    <link rel="stylesheet" href="{{ asset('css/auth-shared.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app-shared.css') }}">
    <x-theme-head />
    <style>
        body { background: #f9fafb; display: flex; justify-content: center; }
        .app-screen { display: flex; flex-direction: column; min-height: 100dvh; }
    </style>
</head>
<body>
    <div class="app-screen">
        <header class="native-header">
            <div class="header-row">
                <a href="/people" class="back-button" aria-label="Back to {{ strtolower($terminology['person_plural']) }}">
                    <x-icons.chevron-left />
                </a>
                <div class="title-group">
                    <h1>Edit Operational Profile</h1>
                    <p>ID: #{{ $person->person_number }}</p>
                </div>
            </div>
        </header>

        <div class="create-form-body">
            <p class="form-error" id="form-error" role="alert"></p>

            <form id="edit-person-form" novalidate>
                <div style="display:flex;flex-direction:column;gap:16px;">
                    <x-auth.field>
                        <label class="field-label" for="ep-person-number">{{ $terminology['person_singular'] }} Number / ID</label>
                        <x-auth.input-shell icon="tag">
                            <input type="text" id="ep-person-number" name="person_number" value="{{ $person->person_number }}" required>
                        </x-auth.input-shell>
                    </x-auth.field>
                    <x-auth.field>
                        <label class="field-label" for="ep-first-name">First Name</label>
                        <x-auth.input-shell icon="user">
                            <input type="text" id="ep-first-name" name="first_name" value="{{ $person->first_name }}" required>
                        </x-auth.input-shell>
                    </x-auth.field>
                    <x-auth.field>
                        <label class="field-label" for="ep-last-name">Last Name</label>
                        <x-auth.input-shell icon="user">
                            <input type="text" id="ep-last-name" name="last_name" value="{{ $person->last_name }}" required>
                        </x-auth.input-shell>
                    </x-auth.field>
                    <x-auth.field>
                        <label class="field-label" for="ep-contact-number">Contact Number</label>
                        <x-auth.input-shell icon="phone">
                            <input type="text" id="ep-contact-number" name="contact_number" value="{{ $person->contact_number }}">
                        </x-auth.input-shell>
                    </x-auth.field>
                    @if ($user->organization->type !== 'school')
                    <x-auth.field>
                        <label class="field-label" for="ep-membership">Membership valid until (optional)</label>
                        <x-auth.input-shell icon="calendar">
                            <input type="date" id="ep-membership" name="membership_valid_until" value="{{ $person->membership_valid_until?->toDateString() }}">
                        </x-auth.input-shell>
                    </x-auth.field>
                    @endif
                    <x-auth.field>
                        <label class="field-label" for="ep-group">{{ $terminology['group_singular'] }}</label>
                        <x-auth.input-shell icon="folder">
                            <select id="ep-group">
                                <option value="">No {{ strtolower($terminology['group_singular']) }}</option>
                            </select>
                            <x-slot:trailing><span class="select-chevron"><x-icons.chevron-down /></span></x-slot:trailing>
                        </x-auth.input-shell>
                    </x-auth.field>
                    <x-auth.field>
                        <label class="field-label" for="ep-subgroup">{{ $terminology['subgroup_singular'] }}</label>
                        <x-auth.input-shell icon="git-branch">
                            <select id="ep-subgroup" disabled>
                                <option value="">Select a {{ strtolower($terminology['group_singular']) }} first</option>
                            </select>
                            <x-slot:trailing><span class="select-chevron"><x-icons.chevron-down /></span></x-slot:trailing>
                        </x-auth.input-shell>
                    </x-auth.field>
                </div>
            </form>
        </div>

        <div class="action-sticky" style="background:#fff;border-top:1px solid #e5e7eb;">
            <button type="submit" form="edit-person-form" class="btn-primary" id="save-person-submit">Save Changes</button>
            <button type="button" class="btn-danger-full" id="archive-person-btn" {{ ! $person->is_active ? 'disabled' : '' }}>{{ $person->is_active ? 'Archive Profile' : 'Already Archived' }}</button>
        </div>
    </div>

    <script src="{{ asset('js/auth-shared.js') }}"></script>
    <script>
        (function () {
            const personId = {{ $person->id }};
            const peopleListUrl = (() => {
                try {
                    const saved = window.sessionStorage.getItem('myo.peopleListUrl');
                    return saved && saved.startsWith('/people') ? saved : '/people';
                } catch (error) {
                    return '/people';
                }
            })();
            document.querySelectorAll('a.back-button[href="/people"]').forEach((link) => { link.href = peopleListUrl; });
            const currentGroupId = {{ $person->group_id ?? 'null' }};
            const currentSubgroupId = {{ $person->subgroup_id ?? 'null' }};
            const form = document.getElementById('edit-person-form');
            const errorBox = document.getElementById('form-error');
            const submitBtn = document.getElementById('save-person-submit');
            const archiveBtn = document.getElementById('archive-person-btn');
            const groupSelect = document.getElementById('ep-group');
            const subgroupSelect = document.getElementById('ep-subgroup');

            function showError(message) {
                errorBox.textContent = message;
                errorBox.classList.add('visible');
            }

            function clearError() {
                errorBox.textContent = '';
                errorBox.classList.remove('visible');
            }

            function escapeHtml(value) {
                return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
            }

            let subgroups = [];

            function updateSubgroupOptions(groupId, preselectId) {
                const filtered = subgroups.filter((subgroup) => String(subgroup.group_id) === String(groupId));
                subgroupSelect.disabled = !groupId;
                subgroupSelect.innerHTML = `<option value="">${!groupId ? 'Select a {{ strtolower($terminology['group_singular']) }} first' : 'No {{ strtolower($terminology['subgroup_singular']) }}'}</option>${filtered.map((subgroup) => `<option value="${subgroup.id}">${escapeHtml(subgroup.name)}</option>`).join('')}`;
                if (preselectId) subgroupSelect.value = String(preselectId);
            }

            async function loadOptions() {
                try {
                    const [groupResponse, subgroupResponse] = await Promise.all([
                        fetch('/api/groups/options', { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
                        fetch('/api/subgroups/options', { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
                    ]);
                    const groupPayload = await groupResponse.json();
                    const subgroupPayload = await subgroupResponse.json();
                    const groups = groupPayload.data || [];
                    subgroups = subgroupPayload.data || [];

                    const groupOptions = groups.map((group) => `<option value="${group.id}">${escapeHtml(group.name)}</option>`).join('');
                    groupSelect.innerHTML = `<option value="">No {{ strtolower($terminology['group_singular']) }}</option>${groupOptions}`;
                    if (currentGroupId) groupSelect.value = String(currentGroupId);
                    updateSubgroupOptions(currentGroupId, currentSubgroupId);
                } catch (error) {
                    // Group/subgroup options are a progressive enhancement over the base form.
                }
            }

            groupSelect.addEventListener('change', () => updateSubgroupOptions(groupSelect.value, null));

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                clearError();
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving…';

                try {
                    await window.MyoAuth.submitJson('/api/people/' + personId, {
                        person_number: document.getElementById('ep-person-number').value,
                        first_name: document.getElementById('ep-first-name').value,
                        last_name: document.getElementById('ep-last-name').value,
                        contact_number: document.getElementById('ep-contact-number').value || null,
                        ...(document.getElementById('ep-membership') ? { membership_valid_until: document.getElementById('ep-membership').value || null } : {}),
                        group_id: groupSelect.value || null,
                        subgroup_id: subgroupSelect.value || null,
                    }, 'PUT');
                    window.location.href = peopleListUrl;
                } catch (error) {
                    showError(window.MyoAuth.firstErrorMessage(error));
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Save Changes';
                }
            });

            archiveBtn.addEventListener('click', async () => {
                if (!window.confirm('Archive this {{ strtolower($terminology['person_singular']) }}? They will no longer be scanned for attendance.')) return;
                clearError();
                archiveBtn.disabled = true;

                try {
                    const response = await fetch('/api/people/' + personId + '/archive', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': window.MyoAuth.csrfToken(),
                        },
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        showError(payload.message || 'Unable to archive this {{ strtolower($terminology['person_singular']) }}.');
                        archiveBtn.disabled = false;
                        return;
                    }

                    window.location.href = peopleListUrl;
                } catch (error) {
                    showError('Unable to reach the server. Please check your connection and try again.');
                    archiveBtn.disabled = false;
                }
            });

            loadOptions();
        })();
    </script>
</body>
</html>
