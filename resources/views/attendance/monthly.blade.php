<x-layouts.app
    :user="$user"
    active-tab="attendance"
    :page-title="'Attendance Report · '.config('app.name')"
>
    <div class="report-page">
        <p class="form-error" id="report-form-error" role="alert"></p>

        <div class="report-form-card">
            <div class="plain-field">
                <label class="plain-field-label" for="report-month">Month</label>
                <input type="month" id="report-month" class="report-month-input">
            </div>

            <div class="plain-field">
                <label class="plain-field-label" for="report-group-trigger">{{ $terminology['group_singular'] }}</label>
                <button type="button" class="selector-box" id="report-group-trigger">
                    <div class="selector-box-text">
                        <span class="selector-box-title is-placeholder" id="report-group-title">Select a {{ strtolower($terminology['group_singular']) }}&hellip;</span>
                        <span class="selector-box-sub" id="report-group-sub"></span>
                    </div>
                    <x-icons.chevron-right />
                </button>
                <input type="hidden" id="report-group-id">
            </div>

            <div class="plain-field">
                <label class="plain-field-label" for="report-subgroup-trigger">{{ $terminology['subgroup_singular'] }}</label>
                <button type="button" class="selector-box is-disabled" id="report-subgroup-trigger" disabled>
                    <div class="selector-box-text">
                        <span class="selector-box-title is-placeholder" id="report-subgroup-title">Select a {{ strtolower($terminology['group_singular']) }} first</span>
                        <span class="selector-box-sub" id="report-subgroup-sub"></span>
                    </div>
                    <x-icons.chevron-right />
                </button>
                <input type="hidden" id="report-subgroup-id">
            </div>

            <button type="button" class="btn-primary" id="report-generate-btn">Generate Report</button>
        </div>

        <p class="report-hint" id="report-hint">Select a month and {{ strtolower($terminology['group_singular']) }} to generate an attendance report.</p>

        <div id="report-result" hidden>
            <div class="report-summary-card">
                <p class="report-summary-month" id="report-summary-month"></p>
                <p class="report-summary-scope" id="report-summary-scope"></p>
                <div class="report-summary-stats" id="report-summary-stats"></div>
            </div>

            <label class="logs-search-box" style="margin-top:16px;">
                <x-icons.search />
                <input type="text" id="report-search" placeholder="Search {{ strtolower($terminology['person_singular']) }}...">
                <button type="button" class="logs-search-clear" id="report-search-clear" aria-label="Clear search" hidden>
                    <x-icons.x-circle />
                </button>
            </label>

            <button type="button" class="btn-download" id="report-download-btn" style="margin-top:16px;">
                <x-icons.download />
                <span>Download CSV</span>
            </button>

            <div class="report-person-list" id="report-person-list" style="margin-top:16px;"></div>

            <div class="people-empty-card" id="report-empty" hidden>
                <div class="people-empty-icon-wrap"><x-icons.search stroke="#9CA3AF" /></div>
                <div class="people-empty-text-wrap">
                    <p class="people-empty-title" id="report-empty-title">No {{ $terminology['person_plural'] }} Found</p>
                    <p class="people-empty-body" id="report-empty-text"></p>
                </div>
                <button type="button" class="btn-primary" id="report-empty-edit">Edit Filters</button>
            </div>
        </div>

        <div class="people-empty-card" id="report-error" hidden>
            <div class="people-empty-icon-wrap"><x-icons.alert-circle stroke="#EF4444" /></div>
            <div class="people-empty-text-wrap">
                <p class="people-empty-title">Unable to generate report</p>
                <p class="people-empty-body" id="report-error-text">Please try again.</p>
            </div>
            <button type="button" class="btn-primary" id="report-retry-btn">Try Again</button>
        </div>
    </div>

    <script>
        window.MyoMonthlyReport = {
            organizationTimezone: @json($user->organization->timezone),
            terminology: @json($terminology),
        };
    </script>

    <x-slot:scripts>
        <div class="sheet-overlay" id="report-group-overlay" hidden>
            <div class="sheet-panel" role="dialog" aria-label="Select {{ strtolower($terminology['group_singular']) }}">
                <div class="sheet-handle"></div>
                <p class="sheet-title">Select {{ $terminology['group_singular'] }}</p>
                <div class="picker-list" id="report-group-list"></div>
            </div>
        </div>

        <div class="sheet-overlay" id="report-subgroup-overlay" hidden>
            <div class="sheet-panel" role="dialog" aria-label="Select {{ strtolower($terminology['subgroup_singular']) }}">
                <div class="sheet-handle"></div>
                <p class="sheet-title">Select {{ $terminology['subgroup_singular'] }}</p>
                <div class="picker-list" id="report-subgroup-list"></div>
            </div>
        </div>

        @vite(['resources/js/attendance-monthly.js'])
    </x-slot:scripts>
</x-layouts.app>
