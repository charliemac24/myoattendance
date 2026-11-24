{{-- Notices from MYO Attendance about billing (SMS price ready, payment approved or not accepted), shown on Home. --}}
@php
    $billingNotices = app(\App\Services\BillingNoticeService::class)->forUser(auth()->user());
@endphp

@if ($billingNotices)
    <div class="billing-notices" id="billing-notices">
        @foreach ($billingNotices as $notice)
            <div class="billing-notice billing-notice--{{ $notice['tone'] }}" role="status" data-notice-key="{{ $notice['key'] }}">
                <div class="billing-notice-copy">
                    <p class="billing-notice-title">{{ $notice['title'] }}</p>
                    <p class="billing-notice-message">{{ $notice['message'] }}</p>
                </div>
                <div class="billing-notice-actions">
                    <a class="billing-notice-link" href="/billing">{{ $notice['action'] }}</a>
                    <button type="button" class="billing-notice-dismiss" data-dismiss-notice aria-label="Dismiss">&times;</button>
                </div>
            </div>
        @endforeach
    </div>
    <script>
        document.getElementById('billing-notices').addEventListener('click', async function (event) {
            const button = event.target.closest('[data-dismiss-notice]');
            if (!button) return;
            const notice = button.closest('[data-notice-key]');
            notice.remove();
            document.querySelectorAll('[data-billing-badge]').forEach((badge) => {
                const left = Math.max(Number(badge.dataset.billingBadge) - 1, 0);
                badge.dataset.billingBadge = left;
                if (badge.textContent.trim() !== '') badge.textContent = left;
                badge.hidden = left === 0;
            });
            await fetch('/api/billing/notices/' + encodeURIComponent(notice.dataset.noticeKey) + '/dismiss', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': window.MyoAuth ? window.MyoAuth.csrfToken() : '' },
            });
        });
    </script>
@endif
