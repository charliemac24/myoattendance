<x-layouts.app
    :user="$user"
    active-tab="people"
    :page-title="'Profile Details · '.config('app.name')"
>
    @php
        $initials = collect(preg_split('/\s+/', trim($person->first_name.' '.$person->last_name)))
            ->filter()
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');
    @endphp

    <div class="people-roster" style="padding:20px;gap:20px;">
        <div class="profile-summary-card">
            <div class="profile-avatar">{{ $initials }}</div>
            <div class="profile-summary-text">
                <h1>{{ $person->first_name }} {{ $person->last_name }}</h1>
                <div class="profile-status-row">
                    <span>ID: #{{ $person->person_number }}</span>
                    <span class="profile-status-dot"></span>
                    <span class="badge-pill {{ $person->is_active ? 'badge-pill--success' : 'badge-pill--neutral' }}">{{ $person->is_active ? 'ACTIVE' : 'INACTIVE' }}</span>
                </div>
            </div>
        </div>

        <div class="details-card">
            <div class="detail-row">
                <p class="detail-row-label">Operational {{ $terminology['group_singular'] }}</p>
                <p class="detail-row-value">{{ $person->group->name ?? 'Not assigned' }}</p>
            </div>
            <hr class="detail-divider">
            <div class="detail-row">
                <p class="detail-row-label">{{ $terminology['subgroup_singular'] }} Assignment</p>
                <p class="detail-row-value">{{ $person->subgroup->name ?? 'Not assigned' }}</p>
            </div>
            <hr class="detail-divider">
            <div class="detail-row">
                <p class="detail-row-label">Primary Contact</p>
                <p class="detail-row-value">{{ $person->contact_number ?? 'Not provided' }}</p>
            </div>
            @php
                $pastAssignments = $person->assignments()->with(['group:id,name', 'subgroup:id,name'])->whereNotNull('ends_on')->orderByDesc('ends_on')->get();
            @endphp
            @if ($pastAssignments->isNotEmpty())
            <hr class="detail-divider">
            <div class="detail-row">
                <p class="detail-row-label">Earlier {{ strtolower($terminology['group_plural']) }}</p>
                @foreach ($pastAssignments as $assignment)
                    <p class="detail-row-value" style="font-weight:500;">{{ $assignment->group?->name ?? 'No '.strtolower($terminology['group_singular']) }}{{ $assignment->subgroup ? ' · '.$assignment->subgroup->name : '' }} &middot; until {{ $assignment->ends_on->format('M j, Y') }}</p>
                @endforeach
            </div>
            @endif
            @if ($person->membership_valid_until)
            <hr class="detail-divider">
            <div class="detail-row">
                <p class="detail-row-label">Membership valid until</p>
                <p class="detail-row-value">{{ $person->membership_valid_until->format('M j, Y') }}@if ($person->membership_valid_until->toDateString() < now($user->organization->timezone)->toDateString()) &middot; Expired @endif</p>
            </div>
            @endif
        </div>

        <div class="detail-actions-row">
            <a href="/people/{{ $person->id }}/edit" class="btn-detail-action">
                <x-icons.settings />
                <span>Edit Profile</span>
            </a>
            <button type="button" class="btn-detail-action btn-detail-action--primary" id="view-qr-btn">
                <x-icons.qr-code stroke="#fff" />
                <span>View QR Card</span>
            </button>
        </div>

    <section class="history-section" id="person-history" aria-labelledby="person-history-title">
        <div class="history-head">
            <p class="history-title" id="person-history-title">Attendance</p>
            <div class="history-presets" role="group" aria-label="Date range">
                <button type="button" class="history-preset" data-days="7">7 days</button>
                <button type="button" class="history-preset is-active" data-days="30">30 days</button>
                <button type="button" class="history-preset" data-days="90">90 days</button>
            </div>
        </div>

        <div class="history-range">
            <label class="plain-field">
                <span class="plain-field-label">From</span>
                <input type="date" id="history-from" class="report-month-input">
            </label>
            <label class="plain-field">
                <span class="plain-field-label">To</span>
                <input type="date" id="history-to" class="report-month-input">
            </label>
        </div>
        <p class="form-error" id="history-error" role="alert"></p>

        <div class="history-stats" id="history-stats" aria-live="polite">
            <div class="history-stat"><p class="history-stat-label">Attendance rate</p><p class="history-stat-value" id="history-rate">&ndash;</p><p class="history-stat-sub" id="history-rate-sub"></p></div>
            <div class="history-stat history-stat--late"><p class="history-stat-label">Late</p><p class="history-stat-value" id="history-late">&ndash;</p><p class="history-stat-sub">days</p></div>
            <div class="history-stat history-stat--absent"><p class="history-stat-label">Absent</p><p class="history-stat-value" id="history-absent">&ndash;</p><p class="history-stat-sub">days</p></div>
            <div class="history-stat"><p class="history-stat-label">Last seen</p><p class="history-stat-value history-stat-value--small" id="history-last-seen">&ndash;</p><p class="history-stat-sub" id="history-last-seen-sub"></p></div>
        </div>

        <button type="button" class="btn-download" id="history-download">
            <x-icons.download />
            <span>Export CSV</span>
        </button>

        <div class="history-days" id="history-days"></div>
        <p class="history-empty" id="history-empty" hidden>No scheduled days or scans in this range.</p>
    </section>
    </div>

    <script>
        window.MyoPersonDetails = { personId: {{ $person->id }}, tracksWorkHours: @json($user->organization->tracksWorkHours()) };
    </script>

    <x-slot:scripts>
        <div class="sheet-overlay" id="qr-overlay" hidden>
            <div class="sheet-panel" role="dialog" aria-label="QR Code">
                <div class="sheet-handle"></div>
                <p class="sheet-title" id="qr-title"></p>
                <img class="qr-image" id="qr-image" alt="QR code">
                <p class="qr-payload" id="qr-payload"></p>
                <div style="display:flex;flex-direction:column;gap:12px;margin-top:16px;">
                    <button type="button" class="people-action" id="qr-regenerate">Regenerate QR Code</button>
                    <button type="button" class="btn-primary" id="qr-close">Done</button>
                </div>
            </div>
        </div>

        @vite(['resources/js/person-details.js'])
    </x-slot:scripts>
</x-layouts.app>
