<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Add {{ $terminology['subgroup_singular'] }} · {{ config('app.name') }}</title>
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
        .native-header { padding: 12px 20px; }
    </style>
</head>
<body>
    <div class="app-screen">
        <header class="native-header">
            <div class="back-trigger-header">
                <a href="/subgroups" class="back-trigger">
                    <x-icons.chevron-left />
                    <span>{{ $terminology['subgroup_plural'] }}</span>
                </a>
                <div class="back-trigger-title-group">
                    <h1>Add {{ $terminology['subgroup_singular'] }}</h1>
                    <p>Create a focused operational unit</p>
                </div>
            </div>
        </header>

        <div class="create-form-body">
            <p class="form-error" id="form-error" role="alert"></p>

            <form id="create-subgroup-form" novalidate>
                <div class="plain-field">
                    <label class="plain-field-label" for="group-picker-trigger">Parent {{ $terminology['group_singular'] }}</label>
                    <button type="button" class="selector-box" id="group-picker-trigger">
                        <div class="selector-box-text">
                            <span class="selector-box-title is-placeholder" id="selected-group-title">Select a {{ strtolower($terminology['group_singular']) }}…</span>
                            <span class="selector-box-sub" id="selected-group-sub"></span>
                        </div>
                        <x-icons.chevron-right />
                    </button>
                    <input type="hidden" id="group-id" required>
                </div>

                <div class="plain-field" style="margin-top: 20px;">
                    <label class="plain-field-label" for="subgroup-name">{{ $terminology['subgroup_singular'] }} Name</label>
                    <input type="text" id="subgroup-name" name="name" class="plain-input" placeholder="e.g. Section A, Evening Shift" required>
                </div>

                <div class="guidance-card" style="margin-top: 20px;">
                    <div class="guidance-headline">
                        <span>{{ $terminology['subgroup_singular'] }} Organization</span>
                    </div>
                    <p class="guidance-body">Assign {{ strtolower($terminology['person_plural']) }} to this {{ strtolower($terminology['subgroup_singular']) }} for targeted roster views and tailored scheduling rules that override the parent {{ strtolower($terminology['group_singular']) }}'s default settings.</p>
                </div>
            </form>
        </div>

        <div class="action-sticky" style="background:#fff;border-top:1px solid #e5e7eb;">
            <button type="submit" form="create-subgroup-form" class="btn-primary" id="create-subgroup-submit">Create {{ $terminology['subgroup_singular'] }}</button>
        </div>
    </div>

    <div class="sheet-overlay" id="group-picker-overlay" hidden>
        <div class="sheet-panel" role="dialog" aria-label="Select {{ strtolower($terminology['group_singular']) }}">
            <div class="sheet-handle"></div>
            <p class="sheet-title">Select {{ $terminology['group_singular'] }}</p>
            <div class="picker-list" id="group-picker-list"></div>
        </div>
    </div>

    <script src="{{ asset('js/auth-shared.js') }}"></script>
    <script>
        (function () {
            const form = document.getElementById('create-subgroup-form');
            const nameInput = document.getElementById('subgroup-name');
            const groupIdInput = document.getElementById('group-id');
            const errorBox = document.getElementById('form-error');
            const submitBtn = document.getElementById('create-subgroup-submit');
            const pickerTrigger = document.getElementById('group-picker-trigger');
            const pickerOverlay = document.getElementById('group-picker-overlay');
            const pickerList = document.getElementById('group-picker-list');
            const selectedTitle = document.getElementById('selected-group-title');
            const selectedSub = document.getElementById('selected-group-sub');

            const preselectGroupId = new URLSearchParams(window.location.search).get('group_id') || '';
            let groups = [];

            function escapeHtml(value) {
                return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
            }

            function memberSuffix(count) {
                const label = count === 1 ? '{{ $terminology['person_singular'] }}' : '{{ $terminology['person_plural'] }}';
                return `${count} ${label}`;
            }

            function selectGroup(group) {
                groupIdInput.value = group.id;
                selectedTitle.textContent = group.name;
                selectedTitle.classList.remove('is-placeholder');
                selectedSub.textContent = memberSuffix(group.persons_count ?? 0);
                pickerOverlay.hidden = true;
            }

            async function loadGroups() {
                const response = await fetch('/api/groups?per_page=100', {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json();
                groups = payload.data || [];

                pickerList.innerHTML = groups.map((group) => `
                    <button type="button" class="picker-item" data-group-id="${group.id}">
                        <span class="picker-item-title">${escapeHtml(group.name)}</span>
                        <span class="picker-item-sub">${escapeHtml(memberSuffix(group.persons_count ?? 0))}</span>
                    </button>
                `).join('');

                pickerList.querySelectorAll('[data-group-id]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const group = groups.find((item) => String(item.id) === button.getAttribute('data-group-id'));
                        if (group) selectGroup(group);
                    });
                });

                if (preselectGroupId) {
                    const preselected = groups.find((item) => String(item.id) === preselectGroupId);
                    if (preselected) selectGroup(preselected);
                }
            }

            pickerTrigger.addEventListener('click', () => { pickerOverlay.hidden = false; });
            pickerOverlay.addEventListener('click', (event) => { if (event.target === pickerOverlay) pickerOverlay.hidden = true; });

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                errorBox.textContent = '';
                errorBox.classList.remove('visible');

                if (!groupIdInput.value) {
                    errorBox.textContent = 'Please select a parent {{ strtolower($terminology['group_singular']) }}.';
                    errorBox.classList.add('visible');
                    return;
                }

                submitBtn.disabled = true;
                submitBtn.textContent = 'Creating…';

                try {
                    await window.MyoAuth.submitJson('/api/subgroups', {
                        group_id: groupIdInput.value,
                        name: nameInput.value,
                    });
                    window.location.href = '/subgroups';
                } catch (error) {
                    errorBox.textContent = window.MyoAuth.firstErrorMessage(error);
                    errorBox.classList.add('visible');
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Create {{ $terminology['subgroup_singular'] }}';
                }
            });

            loadGroups();
        })();
    </script>
</body>
</html>
