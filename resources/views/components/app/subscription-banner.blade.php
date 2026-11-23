@props(['user'])
@php
    $subscriptionExpired = $user->organization
        && app(\App\Services\BaseSubscriptionService::class)->statusFor($user->organization) === \App\Models\BaseSubscription::STATUS_EXPIRED;
@endphp

@if ($subscriptionExpired)
    <div class="subscription-expired-banner" role="alert">
        <x-icons.alert-triangle stroke="#B91C1C" />
        <div class="subscription-expired-banner-copy">
            <p class="subscription-expired-banner-title">Subscription expired</p>
            <p class="subscription-expired-banner-message">Renew your base subscription to continue using the scanner.</p>
        </div>
        <a class="subscription-expired-banner-link" href="/billing">Renew</a>
    </div>
@endif
