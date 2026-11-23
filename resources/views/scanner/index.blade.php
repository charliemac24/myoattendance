<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Camera Scanner · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700,800">
    <link rel="stylesheet" href="{{ asset('css/auth-shared.css') }}?v={{ filemtime(public_path('css/auth-shared.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/app-shared.css') }}?v={{ filemtime(public_path('css/app-shared.css')) }}">
    <style>
        body { background: #111827; display: flex; justify-content: center; overflow-x: hidden; }
    </style>
</head>
<body>
    <div class="scanner-page">
        <x-app.subscription-banner :user="$user" />
        <div class="scanner-camera" id="scanner-camera">
            <video id="scanner-video" muted playsinline></video>
            <div class="scanner-notification" id="scanner-notification" role="status" aria-live="polite" hidden>
                <div class="scanner-notification-icon" id="scanner-notification-icon" aria-hidden="true"></div>
                <div class="scanner-notification-copy">
                    <p class="scanner-notification-title" id="scanner-notification-title"></p>
                    <p class="scanner-notification-message" id="scanner-notification-message"></p>
                </div>
                <button type="button" class="scanner-notification-close" id="scanner-notification-close" aria-label="Dismiss notification">&times;</button>
            </div>
            <div class="scanner-overlay-top">
                <button type="button" class="scanner-icon-btn" id="scanner-back" aria-label="Back" @if ($user->isStaff()) hidden @endif>
                    <x-icons.chevron-left stroke="#fff" />
                </button>
                <p class="scanner-title">Camera Scanner</p>
                <button type="button" class="scanner-icon-btn" id="scanner-flash" aria-label="Toggle flashlight" hidden>
                    <x-icons.zap />
                </button>
            </div>
            <div class="scanner-frame" id="scanner-frame">
                <div class="scanner-laser" id="scanner-laser"></div>
                <div class="scanner-loader-box" id="scanner-loader-box" hidden>
                    <x-icons.loader />
                </div>
            </div>

            <div class="scanner-dim-overlay" id="scanner-result-overlay" hidden>
                <div class="scanner-success-dialog">
                    <div class="scanner-success-seal" id="scanner-result-seal">
                        <x-icons.check-circle style="width:32px;height:32px" />
                    </div>
                    <div class="scanner-success-text">
                        <p class="scanner-success-name" id="scanner-result-name"></p>
                        <p class="scanner-success-id" id="scanner-result-id"></p>
                    </div>
                    <hr class="scanner-success-divider">
                    <div class="scanner-meta-list" id="scanner-result-meta"></div>
                    <div class="scanner-auto-close">
                        <x-icons.clock stroke="#9CA3AF" style="width:14px;height:14px" />
                        <span>Automatically returning to ready&hellip;</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="scanner-controls">
            <div class="scanner-guidance" id="scanner-guidance">
                <div class="scanner-status-row">
                    <span class="scanner-status-dot scanner-status-dot--ready" id="scanner-status-dot"></span>
                    <p class="scanner-status-title" id="scanner-status-title">Starting camera&hellip;</p>
                </div>
                <p class="scanner-status-message" id="scanner-status-message">Align a barcode or QR code inside the frame to register attendance.</p>
            </div>
            <div class="scanner-summary-bar">
                <span>This Session</span>
                <span id="scanner-session-count">0 Total Scans</span>
            </div>
        </div>

        @if ($user->isStaff())
            <div class="scanner-summary-bar">
                <span>Signed in as {{ $user->name }}</span>
                <button type="button" id="scanner-logout" style="border:0;background:none;color:#f87171;font:inherit;font-weight:700;cursor:pointer;">Log Out</button>
            </div>
            <script>
                document.getElementById('scanner-logout').addEventListener('click', async () => {
                    try {
                        await fetch('/api/auth/logout', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' } });
                    } finally {
                        window.location.href = '/login';
                    }
                });
            </script>
        @else
            <x-app.bottom-nav active="" />
        @endif
    </div>

    <script src="{{ asset('js/auth-shared.js') }}?v={{ filemtime(public_path('js/auth-shared.js')) }}"></script>
    <script src="{{ asset('js/app-shared.js') }}?v={{ filemtime(public_path('js/app-shared.js')) }}"></script>
    @vite(['resources/js/scanner.js'])
</body>
</html>
