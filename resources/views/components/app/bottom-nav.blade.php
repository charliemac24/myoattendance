@props(['active' => ''])
@php
    // The menu uses the organization's own words (Students, Workers, Athletes…), including any custom labels.
    $terms = \App\Support\OrganizationTerminology::forOrganization(auth()->user()?->organization ?? (object) ['type' => 'other', 'terminology' => null]);
    $sideLinks = [
        ['Home', '/app', 'home', request()->is('app')],
        [$terms['person_plural'], '/people', 'users', request()->is('people*')],
        ['Daily Logs', '/attendance', 'calendar', request()->is('attendance') ],
        ['Monthly Report', '/attendance/monthly', 'file-text-lg', request()->is('attendance/monthly*')],
        ['Needs Attention', '/attendance/needs-attention', 'alert-triangle', request()->is('attendance/needs-attention*')],
    ];
    if (auth()->user()?->organization?->tracksWorkHours()) {
        $sideLinks[] = ['Timesheet', '/attendance/timesheet', 'clock', request()->is('attendance/timesheet*')];
    }
    $manageLinks = [
        [$terms['group_plural'], '/groups', 'layers', request()->is('groups*')],
        [$terms['subgroup_plural'], '/subgroups', 'folder', request()->is('subgroups*')],
        ['Schedules', '/schedules', 'clock', request()->is('schedules*')],
        ['Holidays', '/settings/holidays', 'calendar', request()->is('settings/holidays*')],
        ['SMS Templates', '/sms/templates', 'mail', request()->is('sms*')],
        ['Manage Labels', '/settings/terminology', 'tag', request()->is('settings/terminology*')],
    ];
    $accountLinks = [
        // "Users" (not "Team"): Company and Sports Academy already call their subgroups "Teams".
        ['Users', '/settings/team', 'users', request()->is('settings/team*')],
        ['Billing', '/billing', 'file-text-lg', request()->is('billing*')],
        ['Account', '/settings/account', 'user', request()->is('settings/account*')],
    ];
    // Billing notices (SMS price ready, payment approved or not accepted) the owner or admin has not seen yet.
    $billingNoticeCount = request()->is('billing*') ? 0 : count(app(\App\Services\BillingNoticeService::class)->forUser(auth()->user()));
@endphp
{{-- Desktop sidebar (1024px and wider only); phones keep the bottom bar below. --}}
<aside class="desktop-sidebar" aria-label="Main menu">
    <a href="/app" class="desktop-sidebar-brand"><span>M</span>MYO Attendance</a>
    <a href="/scanner" class="desktop-sidebar-scan"><x-icons.qr-code-lg />Scan Attendance</a>
    @foreach ($sideLinks as [$label, $href, $icon, $isActive])
        <a href="{{ $href }}" class="desktop-sidebar-link {{ $isActive ? 'is-active' : '' }}"><x-dynamic-component :component="'icons.'.$icon" :stroke="$isActive ? '#1D4ED8' : '#475569'" /><span>{{ $label }}</span></a>
    @endforeach
    <p class="desktop-sidebar-label">Manage</p>
    @foreach ($manageLinks as [$label, $href, $icon, $isActive])
        <a href="{{ $href }}" class="desktop-sidebar-link {{ $isActive ? 'is-active' : '' }}"><x-dynamic-component :component="'icons.'.$icon" :stroke="$isActive ? '#1D4ED8' : '#475569'" /><span>{{ $label }}</span></a>
    @endforeach
    <p class="desktop-sidebar-label">Account</p>
    @foreach ($accountLinks as [$label, $href, $icon, $isActive])
        <a href="{{ $href }}" class="desktop-sidebar-link {{ $isActive ? 'is-active' : '' }}"><x-dynamic-component :component="'icons.'.$icon" :stroke="$isActive ? '#1D4ED8' : '#475569'" /><span>{{ $label }}</span>@if ($href === '/billing')<span class="nav-count-badge" data-billing-badge="{{ $billingNoticeCount }}" @if (! $billingNoticeCount) hidden @endif>{{ $billingNoticeCount }}</span>@endif</a>
    @endforeach
    <button type="button" class="desktop-sidebar-link is-danger" onclick="document.getElementById('more-logout-btn')?.click()"><x-icons.log-out stroke="#DC2626" /><span>Log Out</span></button>
</aside>
<nav class="bottom-nav">
    <div class="bottom-nav-bar">
        <a href="/app" class="bottom-nav-tab {{ $active === 'home' ? 'is-active' : '' }}">
            <x-icons.home :stroke="$active === 'home' ? '#2563EB' : '#4B5563'" />
            <span>Home</span>
        </a>
        <a href="/people" class="bottom-nav-tab {{ $active === 'people' ? 'is-active' : '' }}">
            <x-icons.users :stroke="$active === 'people' ? '#2563EB' : '#4B5563'" />
            <span>{{ $terms['person_plural'] }}</span>
        </a>
        <a href="/scanner" class="bottom-nav-fab" aria-label="Scan Attendance">
            <x-icons.qr-code-lg />
        </a>
        <a href="/attendance" class="bottom-nav-tab {{ $active === 'attendance' ? 'is-active' : '' }}">
            <x-icons.calendar :stroke="$active === 'attendance' ? '#2563EB' : '#4B5563'" />
            <span>Logs</span>
        </a>
        <button type="button" class="bottom-nav-tab {{ $active === 'more' ? 'is-active' : '' }}" id="more-tab-btn" aria-haspopup="true" aria-expanded="false">
            <x-icons.menu :stroke="$active === 'more' ? '#2563EB' : '#4B5563'" />
            <span class="nav-dot-badge" data-billing-badge="{{ $billingNoticeCount }}" aria-label="Billing needs a look" @if (! $billingNoticeCount) hidden @endif></span>
            <span>More</span>
        </button>
    </div>
    <div class="safe-area-bottom"><div class="safe-area-pill"></div></div>
</nav>

<div class="more-sheet-overlay" id="more-sheet-overlay" hidden>
    <div class="more-sheet" role="dialog" aria-label="More options">
        <div class="more-sheet-handle"></div>
        <a href="/groups" class="more-sheet-link"><x-icons.layers /><span>{{ $terms['group_plural'] }}</span></a>
        <a href="/subgroups" class="more-sheet-link"><x-icons.folder /><span>{{ $terms['subgroup_plural'] }}</span></a>
        <a href="/schedules" class="more-sheet-link"><x-icons.clock /><span>Schedules</span></a>
        <a href="/settings/holidays" class="more-sheet-link"><x-icons.calendar /><span>Holidays</span></a>
        <a href="/attendance/monthly" class="more-sheet-link"><x-icons.file-text-lg stroke="#4B5563" /><span>Monthly Report</span></a>
        <a href="/attendance/needs-attention" class="more-sheet-link"><x-icons.alert-triangle /><span>Needs Attention</span></a>
        @if (auth()->user()?->organization?->tracksWorkHours())
            <a href="/attendance/timesheet" class="more-sheet-link"><x-icons.clock /><span>Timesheet</span></a>
        @endif
        <a href="/settings/team" class="more-sheet-link"><x-icons.users /><span>Users</span></a>
        <a href="/billing" class="more-sheet-link"><x-icons.file-text-lg stroke="#4B5563" /><span>Billing</span><span class="nav-count-badge" data-billing-badge="{{ $billingNoticeCount }}" @if (! $billingNoticeCount) hidden @endif>{{ $billingNoticeCount }}</span></a>
        <a href="/settings/terminology" class="more-sheet-link"><x-icons.tag /><span>Manage Labels</span></a>
        <a href="/sms/templates" class="more-sheet-link"><x-icons.mail /><span>SMS Templates</span></a>
        <a href="/settings/account" class="more-sheet-link"><x-icons.user /><span>Account</span></a>
        <button type="button" class="more-sheet-link more-sheet-danger" id="more-logout-btn"><x-icons.log-out stroke="#EF4444" /><span>Log Out</span></button>
    </div>
</div>
