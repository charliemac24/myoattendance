<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Add Schedule · {{ config('app.name') }}</title>
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
                <a href="/schedules" class="back-trigger">
                    <x-icons.chevron-left />
                    <span>Schedules</span>
                </a>
                <div class="back-trigger-title-group">
                    <h1>Add Schedule</h1>
                    <p>Configure custom work timelines</p>
                </div>
            </div>
        </header>

        <div class="create-form-body">
            <p class="form-error" id="form-error" role="alert"></p>

            <form id="create-schedule-form" novalidate>
                <div class="form-section">
                    <p class="form-section-label">1. Targets (Who)</p>

                    <div class="plain-field">
                        <label class="plain-field-label" for="group-picker-trigger">Select Target {{ $terminology['group_singular'] }}</label>
                        <button type="button" class="selector-box" id="group-picker-trigger">
                            <div class="selector-box-text">
                                <span class="selector-box-title is-placeholder" id="selected-group-title">Select a {{ strtolower($terminology['group_singular']) }}…</span>
                                <span class="selector-box-sub" id="selected-group-sub"></span>
                            </div>
                            <x-icons.chevron-right />
                        </button>
                        <input type="hidden" id="group-id" required>
                    </div>

                    <div class="plain-field">
                        <label class="plain-field-label" for="subgroup-picker-trigger">Select Target {{ $terminology['subgroup_singular'] }}</label>
                        <button type="button" class="selector-box is-disabled" id="subgroup-picker-trigger" disabled>
                            <div class="selector-box-text">
                                <span class="selector-box-title is-placeholder" id="selected-subgroup-title">Select a {{ strtolower($terminology['group_singular']) }} first</span>
                                <span class="selector-box-sub" id="selected-subgroup-sub"></span>
                            </div>
                            <x-icons.chevron-right />
                        </button>
                        <input type="hidden" id="subgroup-id">
                    </div>

                    <div class="override-notice">
                        <p><strong>Note:</strong> {{ $terminology['group_plural'] }} set to "Anytime (open hours)" are not listed, because they do not use schedules.</p>
                        <p><strong>Note:</strong> Selecting a specific {{ $terminology['subgroup_singular'] }} overrides the parent {{ strtolower($terminology['group_singular']) }}'s default scheduling rules for those specific members.</p>
                    </div>
                </div>

                <hr class="form-section-divider">

                <div class="form-section">
                    <p class="form-section-label">2. Days (When)</p>
                    <div class="day-pill-row" id="days-row">
                        <button type="button" class="day-pill" data-day="1" aria-label="Monday">M</button>
                        <button type="button" class="day-pill" data-day="2" aria-label="Tuesday">T</button>
                        <button type="button" class="day-pill" data-day="3" aria-label="Wednesday">W</button>
                        <button type="button" class="day-pill" data-day="4" aria-label="Thursday">T</button>
                        <button type="button" class="day-pill" data-day="5" aria-label="Friday">F</button>
                        <button type="button" class="day-pill" data-day="6" aria-label="Saturday">S</button>
                        <button type="button" class="day-pill" data-day="7" aria-label="Sunday">S</button>
                    </div>
                </div>

                <hr class="form-section-divider">

                <div class="form-section">
                    <p class="form-section-label">3. Time Windows</p>
                    <div id="periods-list"></div>
                    <button type="button" class="btn-add-period" id="add-period-btn">
                        <x-icons.plus />
                        <span>Add Another Time Period</span>
                    </button>
                    <p class="field-helper" style="margin:6px 0 0;">Night shift? Enter an end time earlier than the start (e.g. 10:00 PM to 6:00 AM). It ends the next morning.</p>
                </div>
                @if ($user->organization->tracksWorkHours())
                <hr class="form-section-divider">
                <div class="form-section">
                    <p class="form-section-label">4. Unpaid Break</p>
                    <label class="plain-field">
                        <span class="plain-field-label">Unpaid break (minutes)</span>
                        <input type="number" id="break-minutes" class="plain-input" min="0" max="480" step="5" value="60">
                        <span class="field-helper">Taken out of hours worked once a day, when someone works 5 hours or more. Use 0 for none. Tip: for employees, use one time window plus this break. Two time windows mean they must scan in and out for each one.</span>
                    </label>
                </div>
                @endif
            </form>
        </div>

        <div class="action-sticky" style="background:#fff;border-top:1px solid #e5e7eb;">
            <button type="submit" form="create-schedule-form" class="btn-primary" id="create-schedule-submit">Save Operational Schedule</button>
        </div>
    </div>

    <div class="sheet-overlay" id="group-picker-overlay" hidden>
        <div class="sheet-panel" role="dialog" aria-label="Select {{ strtolower($terminology['group_singular']) }}">
            <div class="sheet-handle"></div>
            <p class="sheet-title">Select {{ $terminology['group_singular'] }}</p>
            <div class="picker-list" id="group-picker-list"></div>
        </div>
    </div>

    <div class="sheet-overlay" id="subgroup-picker-overlay" hidden>
        <div class="sheet-panel" role="dialog" aria-label="Select {{ strtolower($terminology['subgroup_singular']) }}">
            <div class="sheet-handle"></div>
            <p class="sheet-title">Select {{ $terminology['subgroup_singular'] }}</p>
            <div class="picker-list" id="subgroup-picker-list"></div>
        </div>
    </div>

    <template id="period-card-template">
        <div class="period-card" data-period>
            <div class="period-card-header">
                <p class="period-card-label" data-period-label></p>
                <button type="button" class="period-remove" data-period-remove>Remove</button>
            </div>
            <div class="period-inputs">
                <div class="period-col">
                    <p class="period-col-label">Start Time</p>
                    <input type="time" class="time-box" data-period-start required>
                </div>
                <div class="period-col">
                    <p class="period-col-label">Late After</p>
                    <input type="time" class="time-box" data-period-late required>
                </div>
                <div class="period-col">
                    <p class="period-col-label">End Time</p>
                    <input type="time" class="time-box" data-period-end>
                </div>
            </div>
        </div>
    </template>

    <script src="{{ asset('js/auth-shared.js') }}"></script>
    <script>
        (function () {
            const terminology = @json($terminology);
            const form = document.getElementById('create-schedule-form');
            const errorBox = document.getElementById('form-error');
            const submitBtn = document.getElementById('create-schedule-submit');

            const groupIdInput = document.getElementById('group-id');
            const groupTrigger = document.getElementById('group-picker-trigger');
            const groupOverlay = document.getElementById('group-picker-overlay');
            const groupList = document.getElementById('group-picker-list');
            const groupTitle = document.getElementById('selected-group-title');
            const groupSub = document.getElementById('selected-group-sub');

            const subgroupIdInput = document.getElementById('subgroup-id');
            const subgroupTrigger = document.getElementById('subgroup-picker-trigger');
            const subgroupOverlay = document.getElementById('subgroup-picker-overlay');
            const subgroupList = document.getElementById('subgroup-picker-list');
            const subgroupTitle = document.getElementById('selected-subgroup-title');
            const subgroupSub = document.getElementById('selected-subgroup-sub');

            const periodsList = document.getElementById('periods-list');
            const periodTemplate = document.getElementById('period-card-template');
            const addPeriodBtn = document.getElementById('add-period-btn');

            let selectedGroup = null;
            let selectedSubgroup = null;
            let periodCount = 0;

            function escapeHtml(value) {
                return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
            }

            function memberSuffix(count) {
                const label = count === 1 ? terminology.person_singular : terminology.person_plural;
                return `${count} ${label}`;
            }

            function renumberPeriods() {
                const cards = periodsList.querySelectorAll('[data-period]');
                cards.forEach((card, index) => {
                    card.querySelector('[data-period-label]').textContent = `Period #${index + 1}`;
                    card.querySelector('[data-period-remove]').hidden = cards.length <= 1;
                });
            }

            function addPeriod() {
                periodCount += 1;
                const fragment = periodTemplate.content.cloneNode(true);
                const card = fragment.querySelector('[data-period]');
                card.querySelector('[data-period-remove]').addEventListener('click', () => {
                    card.remove();
                    renumberPeriods();
                });
                periodsList.appendChild(fragment);
                renumberPeriods();
            }

            addPeriodBtn.addEventListener('click', () => {
                if (periodsList.querySelectorAll('[data-period]').length >= 8) return;
                addPeriod();
            });

            // Target group picker
            function selectGroup(group) {
                selectedGroup = group;
                selectedSubgroup = null;
                groupIdInput.value = group.id;
                groupTitle.textContent = group.name;
                groupTitle.classList.remove('is-placeholder');
                groupSub.textContent = memberSuffix(group.persons_count ?? 0);
                groupOverlay.hidden = true;

                subgroupIdInput.value = '';
                subgroupTrigger.disabled = false;
                subgroupTrigger.classList.remove('is-disabled');
                subgroupTitle.textContent = `Entire {{ $terminology['group_singular'] }}`;
                subgroupTitle.classList.remove('is-placeholder');
                subgroupSub.textContent = 'No sub-filter selected';

                loadSubgroups(group.id);
            }

            async function loadGroups() {
                const response = await fetch('/api/groups?per_page=100', {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json();
                // Anytime (open hours) groups never use a schedule, so they are not offered here.
                const groups = (payload.data || []).filter((group) => group.attendance_mode !== 'open');

                groupList.innerHTML = groups.map((group) => `
                    <button type="button" class="picker-item" data-group-id="${group.id}">
                        <span class="picker-item-title">${escapeHtml(group.name)}</span>
                        <span class="picker-item-sub">${escapeHtml(memberSuffix(group.persons_count ?? 0))}</span>
                    </button>
                `).join('');

                groupList.querySelectorAll('[data-group-id]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const group = groups.find((item) => String(item.id) === button.getAttribute('data-group-id'));
                        if (group) selectGroup(group);
                    });
                });
            }

            function selectSubgroup(subgroup) {
                selectedSubgroup = subgroup;
                if (subgroup) {
                    subgroupIdInput.value = subgroup.id;
                    subgroupTitle.textContent = subgroup.name;
                    subgroupSub.textContent = memberSuffix(subgroup.persons_count ?? 0);
                } else {
                    subgroupIdInput.value = '';
                    subgroupTitle.textContent = `Entire {{ $terminology['group_singular'] }}`;
                    subgroupSub.textContent = 'No sub-filter selected';
                }
                subgroupTitle.classList.remove('is-placeholder');
                subgroupOverlay.hidden = true;
            }

            async function loadSubgroups(groupId) {
                const response = await fetch(`/api/subgroups?group_id=${groupId}&per_page=100`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json();
                const subgroups = payload.data || [];

                const entireItem = `
                    <button type="button" class="picker-item" data-subgroup-id="">
                        <span class="picker-item-title">Entire {{ $terminology['group_singular'] }}</span>
                        <span class="picker-item-sub">No sub-filter selected</span>
                    </button>
                `;
                const subgroupItems = subgroups.map((subgroup) => `
                    <button type="button" class="picker-item" data-subgroup-id="${subgroup.id}">
                        <span class="picker-item-title">${escapeHtml(subgroup.name)}</span>
                        <span class="picker-item-sub">${escapeHtml(memberSuffix(subgroup.persons_count ?? 0))}</span>
                    </button>
                `).join('');
                subgroupList.innerHTML = entireItem + subgroupItems;

                subgroupList.querySelectorAll('[data-subgroup-id]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const id = button.getAttribute('data-subgroup-id');
                        selectSubgroup(id ? subgroups.find((item) => String(item.id) === id) : null);
                    });
                });
            }

            groupTrigger.addEventListener('click', () => { groupOverlay.hidden = false; });
            groupOverlay.addEventListener('click', (event) => { if (event.target === groupOverlay) groupOverlay.hidden = true; });

            subgroupTrigger.addEventListener('click', () => {
                if (subgroupTrigger.disabled) return;
                subgroupOverlay.hidden = false;
            });
            subgroupOverlay.addEventListener('click', (event) => { if (event.target === subgroupOverlay) subgroupOverlay.hidden = true; });

            document.getElementById('days-row').querySelectorAll('.day-pill').forEach((pill) => {
                pill.addEventListener('click', () => pill.classList.toggle('is-selected'));
            });

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                errorBox.textContent = '';
                errorBox.classList.remove('visible');

                if (!groupIdInput.value) {
                    errorBox.textContent = `Please select a target {{ strtolower($terminology['group_singular']) }}.`;
                    errorBox.classList.add('visible');
                    return;
                }

                const days = Array.from(document.querySelectorAll('.day-pill.is-selected')).map((pill) => Number(pill.getAttribute('data-day')));
                if (!days.length) {
                    errorBox.textContent = 'Select at least one active day.';
                    errorBox.classList.add('visible');
                    return;
                }

                const periods = Array.from(periodsList.querySelectorAll('[data-period]')).map((card) => ({
                    start_time: card.querySelector('[data-period-start]').value,
                    late_after: card.querySelector('[data-period-late]').value,
                    end_time: card.querySelector('[data-period-end]').value || null,
                }));
                if (!periods.length || periods.some((period) => !period.start_time || !period.late_after)) {
                    errorBox.textContent = 'Each time window needs a start time and a late-after threshold.';
                    errorBox.classList.add('visible');
                    return;
                }

                const groupName = selectedGroup?.name || '';
                const scopeName = selectedSubgroup?.name ? `${groupName} • ${selectedSubgroup.name}` : groupName;
                const name = `${scopeName} Schedule`;

                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving…';

                try {
                    await window.MyoAuth.submitJson('/api/schedules', {
                        name,
                        group_id: groupIdInput.value,
                        subgroup_id: subgroupIdInput.value || null,
                        days,
                        start_time: periods[0].start_time,
                        late_after: periods[0].late_after,
                        end_time: periods[0].end_time,
                        periods,
                        ...(document.getElementById('break-minutes') ? { break_minutes: Number(document.getElementById('break-minutes').value || 0) } : {}),
                    });
                    window.location.href = '/schedules';
                } catch (error) {
                    errorBox.textContent = window.MyoAuth.firstErrorMessage(error);
                    errorBox.classList.add('visible');
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Save Operational Schedule';
                }
            });

            addPeriod();
            loadGroups();
        })();
    </script>
</body>
</html>
