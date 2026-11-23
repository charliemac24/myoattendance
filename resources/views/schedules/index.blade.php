<x-layouts.app
    :user="$user"
    active-tab="more"
    page-title="Schedules · {{ config('app.name') }}"
>
    <div class="people-controls">
        <label class="search-shell">
            <x-icons.search />
            <input type="text" id="schedules-search" placeholder="Search schedules, groups...">
        </label>

        <div class="people-actions-row">
            <a href="/schedules/create" class="people-action people-action--primary">
                <x-icons.plus />
                <span>Add Schedule</span>
            </a>
        </div>
    </div>

    <div class="people-roster">
        <div id="schedules-list"></div>

        <div class="empty-state" id="schedules-empty" hidden>
            <p>No schedules found.</p>
        </div>
    </div>

    <script>
        window.MyoSchedules = {
            terminology: @json($terminology),
        };
    </script>

    <x-slot:scripts>
        <div class="sheet-overlay" id="edit-schedule-overlay" hidden>
            <div class="sheet-panel" role="dialog" aria-label="Edit Schedule">
                <div class="sheet-handle"></div>
                <p class="sheet-title">Edit Schedule</p>
                <p class="form-error" id="edit-schedule-error" role="alert"></p>
                <form id="edit-schedule-form" novalidate>
                    <div style="display:flex;flex-direction:column;gap:16px;">
                        <x-auth.field>
                            <label class="field-label" for="es-name">Schedule Name</label>
                            <x-auth.input-shell icon="tag">
                                <input type="text" id="es-name" name="name" required>
                            </x-auth.input-shell>
                        </x-auth.field>
                        <x-auth.field>
                            <label class="field-label" for="es-group">{{ $terminology['group_singular'] }}</label>
                            <x-auth.input-shell icon="layers">
                                <select id="es-group" required><option value="">Select a {{ strtolower($terminology['group_singular']) }}</option></select>
                                <x-slot:trailing><span class="select-chevron"><x-icons.chevron-down /></span></x-slot:trailing>
                            </x-auth.input-shell>
                        </x-auth.field>
                        <x-auth.field>
                            <label class="field-label" for="es-subgroup">{{ $terminology['subgroup_singular'] }} (optional)</label>
                            <x-auth.input-shell icon="folder">
                                <select id="es-subgroup" disabled><option value="">Entire {{ strtolower($terminology['group_singular']) }}</option></select>
                                <x-slot:trailing><span class="select-chevron"><x-icons.chevron-down /></span></x-slot:trailing>
                            </x-auth.input-shell>
                        </x-auth.field>
                        <x-auth.field>
                            <label class="field-label">Active Days</label>
                            <div class="weekday-chip-group" id="es-days">
                                <button type="button" class="weekday-chip" data-day="1">Mon</button>
                                <button type="button" class="weekday-chip" data-day="2">Tue</button>
                                <button type="button" class="weekday-chip" data-day="3">Wed</button>
                                <button type="button" class="weekday-chip" data-day="4">Thu</button>
                                <button type="button" class="weekday-chip" data-day="5">Fri</button>
                                <button type="button" class="weekday-chip" data-day="6">Sat</button>
                                <button type="button" class="weekday-chip" data-day="7">Sun</button>
                            </div>
                        </x-auth.field>
                        <x-auth.field>
                            <label class="field-label">Time Windows</label>
                            <div id="es-periods"></div>
                            <button type="button" class="btn-add-period" id="es-add-period">
                                <x-icons.plus />
                                <span>Add Another Time Period</span>
                            </button>
                            <p class="field-helper" style="margin:6px 0 0;">Night shift? Enter an end time earlier than the start (e.g. 10:00 PM to 6:00 AM). It ends the next morning.</p>
                        </x-auth.field>
                        @if ($user->organization->tracksWorkHours())
                        <x-auth.field>
                            <label class="field-label" for="es-break">Unpaid break (minutes)</label>
                            <input type="number" id="es-break" class="plain-input" min="0" max="480" step="5">
                        </x-auth.field>
                        @endif
                    </div>
                    <div style="display:flex;flex-direction:column;gap:12px;margin-top:20px;">
                        <button type="submit" class="btn-primary" id="edit-schedule-submit">Save Changes</button>
                        <button type="button" class="btn-danger-full" id="edit-schedule-delete">Delete Schedule</button>
                        <button type="button" class="people-action" id="edit-schedule-cancel">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <template id="es-period-card-template">
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

        <div class="sheet-overlay" id="delete-schedule-overlay" hidden>
            <div class="sheet-panel" role="dialog" aria-label="Delete Schedule confirmation">
                <div class="sheet-handle"></div>
                <div class="alert-sheet-header">
                    <div class="alert-icon-seal"><x-icons.trash /></div>
                    <p class="alert-sheet-title">Delete Schedule?</p>
                </div>
                <div class="alert-sheet-body">
                    <p class="alert-sheet-text">This schedule will no longer determine attendance logging rules for:</p>
                    <p class="alert-target-badge" id="delete-schedule-target"></p>
                    <p class="alert-sheet-warning">Warning: Active members under this scope will fall back to default global organization check-in configurations.</p>
                </div>
                <p class="form-error" id="delete-schedule-error" role="alert"></p>
                <div style="display:flex;flex-direction:column;gap:8px;width:100%;">
                    <button type="button" class="btn-danger-solid" id="delete-schedule-confirm">Delete Schedule</button>
                    <button type="button" class="btn-cancel-outline" id="delete-schedule-cancel">Cancel</button>
                </div>
            </div>
        </div>

        @vite(['resources/js/schedules.js'])
    </x-slot:scripts>
</x-layouts.app>
