<x-layouts.app
    :user="$user"
    active-tab="attendance"
    page-title="Daily Logs · {{ config('app.name') }}"
>
    <label class="logs-date-nav">
        <x-icons.clock stroke="#2563EB" />
        <span>Change date:</span>
        <input type="date" id="logs-date-input">
    </label>

    <div class="metric-ribbon" id="metric-ribbon"></div>

    <div class="logs-search-container logs-search-row">
        <label class="logs-search-box">
            <x-icons.search />
            <input type="text" id="logs-search" placeholder="Search {{ strtolower($terminology['person_plural']) }}...">
            <button type="button" class="logs-search-clear" id="logs-search-clear" aria-label="Clear search" hidden>
                <x-icons.x-circle />
            </button>
        </label>
        <button type="button" class="logs-print-btn" id="logs-print-btn">
            <x-icons.printer />
            <span>Print</span>
        </button>
    </div>

    <div class="logs-filter-container">
        <div class="filter-row">
            <select id="logs-group-filter"><option value="">All {{ strtolower($terminology['group_plural']) }}</option></select>
            <select id="logs-subgroup-filter" disabled><option value="">All {{ strtolower($terminology['subgroup_plural']) }}</option></select>
        </div>

        <div class="active-filters" id="logs-active-filters" hidden>
            <div class="filter-summary">
                <x-icons.layers stroke="#1D4ED8" />
                <p id="logs-filter-summary-text"></p>
                <button type="button" id="logs-clear-filters">Clear</button>
            </div>
            <div class="active-chips" id="logs-active-chips"></div>
        </div>
    </div>

    <p class="logs-results-header" id="logs-results-header"></p>

    <div class="logs-list" id="logs-list"></div>

    {{-- Scans the scanner turned away (archived, expired membership). Shown apart; they are not attendance. --}}
    <section id="logs-refused" hidden style="margin:16px 16px 0;padding:12px 14px;border:1px solid #fecaca;border-radius:12px;background:#fef2f2;">
        <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#b91c1c;">Turned away at the scanner (<span id="logs-refused-count">0</span>)</p>
        <div id="logs-refused-list" style="display:flex;flex-direction:column;gap:6px;font-size:13px;color:#374151;"></div>
    </section>

    <div class="empty-state" id="logs-empty" hidden>
        <p>No logs found.</p>
    </div>

    <div id="print-report" class="print-report"></div>

    <script>
        window.MyoAttendance = {
            organizationTimezone: @json($user->organization->timezone),
            organizationName: @json($user->organization->name),
            terminology: @json($terminology),
        };
    </script>

    <x-slot:scripts>
        @vite(['resources/js/attendance.js'])
    </x-slot:scripts>
</x-layouts.app>
