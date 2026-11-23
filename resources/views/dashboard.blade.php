<x-layouts.app
    :user="$user"
    active-tab="home"
    :page-title="'Home · '.config('app.name')"
>
    @php
        $organizationLabel = \App\Support\OrganizationTypes::LABELS[$user->organization->type] ?? ucfirst($user->organization->type);
        $firstName = trim(explode(' ', trim($user->name))[0] ?? $user->name);
        $hour = (int) $localNow->format('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
        $today = $dashboard['today_summary'];
        $recorded = $dashboard['recorded'];
        $stillToRecord = max($today['expected_total'] - $recorded, 0);
        $attention = $dashboard['needs_attention'];
    @endphp

    <div class="home-body">
        <div class="home-greeting-row">
            <div class="home-greeting-copy">
                <p class="home-greeting-title">{{ $greeting }}, {{ $firstName }}</p>
                <p class="home-greeting-sub">{{ $organizationLabel }} &middot; {{ $today['expected_total'] }} {{ strtolower($terminology['person_plural']) }} scheduled today</p>
            </div>
            <div class="selector-box home-date-pill">
                <span class="selector-box-title">{{ $localNow->format('D, M j') }}</span>
            </div>
        </div>

        <div class="home-summary-card">
            <div class="home-summary-header">
                <p>Today's Attendance</p>
                <span class="home-live-badge"><span class="dot"></span>Live</span>
            </div>
            <div class="home-metrics-grid">
                <div class="home-metrics-row">
                    <a href="/attendance?date={{ $localNow->toDateString() }}" class="home-metric home-metric--expected">
                        <div class="home-metric-head">
                            <div class="home-metric-icon"><x-icons.users stroke="#2563EB" /></div>
                            <p class="home-metric-label">Expected</p>
                        </div>
                        <div>
                            <p class="home-metric-value">{{ $today['expected_total'] }}</p>
                            <p class="home-metric-sub">Scheduled for today</p>
                        </div>
                    </a>
                    <a href="/attendance?date={{ $localNow->toDateString() }}&status=PRESENT" class="home-metric home-metric--present">
                        <div class="home-metric-head">
                            <div class="home-metric-icon"><x-icons.check /></div>
                            <p class="home-metric-label">Present</p>
                        </div>
                        <div>
                            <p class="home-metric-value">{{ $today['present'] }}</p>
                            <p class="home-metric-sub">{{ $today['expected_total'] > 0 ? round($today['present'] / $today['expected_total'] * 100, 1) : 0 }}% of expected</p>
                        </div>
                    </a>
                </div>
                <div class="home-metrics-row">
                    <a href="/attendance?date={{ $localNow->toDateString() }}&status=LATE" class="home-metric home-metric--late">
                        <div class="home-metric-head">
                            <div class="home-metric-icon"><x-icons.clock stroke="#F59E0B" /></div>
                            <p class="home-metric-label">Late</p>
                        </div>
                        <div>
                            <p class="home-metric-value">{{ $today['late'] }}</p>
                            <p class="home-metric-sub">{{ $today['expected_total'] > 0 ? round($today['late'] / $today['expected_total'] * 100, 1) : 0 }}% of expected</p>
                        </div>
                    </a>
                    <a href="/attendance?date={{ $localNow->toDateString() }}&status=ABSENT" class="home-metric home-metric--absent">
                        <div class="home-metric-head">
                            <div class="home-metric-icon"><x-icons.user-x /></div>
                            <p class="home-metric-label">Absent</p>
                        </div>
                        <div>
                            <p class="home-metric-value">{{ $today['absent'] }}</p>
                            <p class="home-metric-sub">{{ $today['expected_total'] > 0 ? round($today['absent'] / $today['expected_total'] * 100, 1) : 0 }}% of expected</p>
                        </div>
                    </a>
                </div>
            </div>
            <div class="home-progress-area">
                <div class="home-progress-row">
                    <span>{{ $recorded }} of {{ $today['expected_total'] }} have recorded attendance</span>
                    <span>{{ $dashboard['recorded_percent'] }}%</span>
                </div>
                <div class="home-progress-segments">
                    @if ($today['present'] > 0)
                        <div class="home-progress-segment home-progress-segment--present" style="flex-grow:{{ $today['present'] }};"></div>
                    @endif
                    @if ($today['late'] > 0)
                        <div class="home-progress-segment home-progress-segment--late" style="flex-grow:{{ $today['late'] }};"></div>
                    @endif
                    @if ($stillToRecord > 0)
                        <div class="home-progress-segment home-progress-segment--remaining" style="flex-grow:{{ $stillToRecord }};"></div>
                    @endif
                </div>
                <p class="home-progress-note">{{ $today['late'] }} late &middot; {{ $stillToRecord }} still to record</p>
            </div>
        </div>

        <div class="quick-actions">
            <p class="quick-actions-title">Quick Actions</p>
            <div class="quick-actions-grid">
                <a href="/scanner" class="quick-action-card quick-action-card--primary">
                    <div class="quick-action-icon quick-action-icon--blue"><x-icons.scan /></div>
                    <div>
                        <p class="quick-action-title">Scan Attendance</p>
                        <p class="quick-action-sub">Open scanner instantly</p>
                    </div>
                </a>
                <a href="/attendance" class="quick-action-card">
                    <div class="quick-action-icon quick-action-icon--green"><x-icons.calendar-check /></div>
                    <div>
                        <p class="quick-action-title">View Attendance</p>
                        <p class="quick-action-sub">Check today's records</p>
                    </div>
                </a>
                <a href="/people" class="quick-action-card">
                    <div class="quick-action-icon quick-action-icon--amber"><x-icons.user-plus /></div>
                    <div>
                        <p class="quick-action-title">Add Person</p>
                        <p class="quick-action-sub">Register new person</p>
                    </div>
                </a>
                <a href="/schedules" class="quick-action-card">
                    <div class="quick-action-icon quick-action-icon--gray"><x-icons.clock /></div>
                    <div>
                        <p class="quick-action-title">Manage Schedules</p>
                        <p class="quick-action-sub">Set operating hours</p>
                    </div>
                </a>
            </div>
        </div>

        <div class="home-section home-attn" id="home-needs-attention">
            <div class="home-attn-header">
                <div>
                    <p class="home-attn-heading">Needs attention @if ($attention['total'] > 0)<span class="home-attn-count">{{ $attention['total'] }}</span>@endif</p>
                    <p class="home-attn-sub">{{ $terminology['person_plural'] }} who may have stopped coming</p>
                </div>
                <a href="/attendance/needs-attention" class="home-attn-link">{{ $attention['total'] > 0 ? 'View all ('.$attention['total'].')' : 'Open' }}</a>
            </div>
            @if (count($attention['people']) > 0)
                <div class="home-attn-list">
                    @foreach ($attention['people'] as $person)
                        @php
                            $never = $person['last_seen_date'] === null;
                            $days = $person['days_since_last_seen'];
                            $severity = $never || ($days ?? 0) >= 14 || $person['absence_streak'] >= 5 ? 'high' : 'medium';
                            $scope = collect([$person['group'], $person['subgroup']])->filter()->implode(' · ');
                            $lastSeen = $never ? '' : ($days === 0 ? 'Seen today' : ($days === 1 ? 'Seen yesterday' : $days.' days ago'));
                        @endphp
                        <a href="/people/{{ $person['id'] }}" class="home-attn-row">
                            <span class="home-attn-dot home-attn-dot--{{ $severity }}" aria-hidden="true"></span>
                            <div class="home-attn-who">
                                <p class="home-attn-name">{{ $person['full_name'] }}</p>
                                <p class="home-attn-reasons home-attn-reasons--{{ $severity }}">{{ collect($person['reasons'])->pluck('label')->implode(' · ') }}</p>
                                <p class="home-attn-meta">#{{ $person['person_number'] }}@if ($scope) &middot; {{ $scope }}@endif</p>
                            </div>
                            @if ($lastSeen !== '')
                                <span class="home-attn-last">{{ $lastSeen }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @else
                <p class="home-attn-clear">No one needs attention right now. Everyone is coming as expected.</p>
            @endif
        </div>

        <div class="home-section">
            <div class="home-section-header">
                <p>Recent Activity</p>
                <a href="/attendance">View All</a>
            </div>
            @forelse ($dashboard['recent_activity'] as $event)
                @php
                    $iconVariant = $event['direction'] === 'OUT' ? 'out' : ($event['status'] === 'LATE' ? 'late' : 'present');
                @endphp
                <div class="home-activity-row">
                    <div class="home-activity-icon home-activity-icon--{{ $iconVariant }}">
                        @if ($event['direction'] === 'OUT')
                            <x-icons.arrow-up-right />
                        @else
                            <x-icons.arrow-down-left stroke="{{ $event['status'] === 'LATE' ? '#F59E0B' : '#10B981' }}" />
                        @endif
                    </div>
                    <div class="home-activity-copy">
                        <p class="home-activity-name">{{ $event['person']['name'] ?? 'Unknown' }}</p>
                        <p class="home-activity-meta">{{ $event['person']['person_number'] ?? '' }} &middot; {{ $event['direction'] }} at {{ $event['time'] }}</p>
                    </div>
                    <div class="home-activity-right">
                        @if ($event['status'])
                            <span class="attendance-log-status-tag {{ $event['status'] === 'LATE' ? 'attendance-log-status-tag--late' : 'attendance-log-status-tag--present' }}">{{ $event['status'] }}</span>
                        @endif
                        <p class="home-activity-time">{{ $event['time_ago'] }}</p>
                    </div>
                </div>
            @empty
                <p class="home-empty-note">No attendance activity recorded yet today.</p>
            @endforelse
        </div>
    </div>
</x-layouts.app>
