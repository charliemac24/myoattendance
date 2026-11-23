@props(['user'])
@php
    $initials = collect(preg_split('/\s+/', trim($user->name)))
        ->filter()
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->take(2)
        ->implode('');
@endphp
<header class="app-header">
    <div class="app-header-row">
        <div class="app-header-brand">
            <div class="app-header-icon"><x-icons.building-2 /></div>
            <div class="app-header-brand-text">
                <p class="app-brand-label">MYO Attendance</p>
                <p class="app-org-name">{{ $user->organization->name }}</p>
            </div>
        </div>
        <div class="app-header-actions">
            <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode">
                <svg class="theme-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>
                <svg class="theme-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
            </button>
            <div class="app-avatar" title="{{ $user->name }}">{{ $initials }}</div>
        </div>
    </div>
</header>
