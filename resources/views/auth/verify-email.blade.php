<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Verify email · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700,800">
    <link rel="stylesheet" href="{{ asset('css/auth-shared.css') }}">
    <style>
        body { background: #f9fafb; display: flex; justify-content: center; }

        .verify-content-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 32px;
            padding: 24px;
        }

        .icon-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 80px;
            height: 80px;
            border-radius: 40px;
            background: #eff6ff;
        }

        .message-stack {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            width: 100%;
            text-align: center;
        }

        .message-stack h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
            color: #111827;
        }

        .message-stack p {
            margin: 0;
            font-size: 15px;
            color: #4b5563;
        }

        .email-badge {
            padding: 6px 12px;
            border-radius: 8px;
            background: #eff6ff;
            color: #2563eb;
            font-size: 15px;
            font-weight: 700;
        }

        .actions {
            display: flex;
            flex-direction: column;
            gap: 16px;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="app-screen">
        <header class="native-header">
            <div class="header-row">
                <button type="button" class="back-button" id="logout-icon-button" aria-label="Log out">
                    <x-icons.chevron-left />
                </button>
                <div class="title-group">
                    <h1>Verify Email</h1>
                </div>
            </div>
        </header>

        <div class="verify-content-body">
            <div class="icon-badge">
                <x-icons.mail-big />
            </div>

            <div class="message-stack">
                <h2>Check Your Inbox</h2>
                <p>We sent a secure verification link to:</p>
                <span class="email-badge">{{ auth()->user()->email }}</span>
            </div>

            <x-ui.alert type="success" id="resend-alert"></x-ui.alert>
            <x-ui.alert type="error" id="resend-error"></x-ui.alert>

            <div class="actions">
                <button type="button" class="btn-primary" id="resend-btn">Resend Email</button>
                <button type="button" class="btn-text-danger" id="logout-btn">Log Out</button>
            </div>
        </div>
    </div>

    <script src="{{ asset('js/auth-shared.js') }}"></script>
    <script>
        (function () {
            const resendBtn = document.getElementById('resend-btn');
            const alertSuccess = document.getElementById('resend-alert');
            const alertError = document.getElementById('resend-error');

            function setAlert(el, message) {
                el.querySelector('p').textContent = message;
                el.classList.add('visible');
            }

            function clearAlerts() {
                alertSuccess.classList.remove('visible');
                alertError.classList.remove('visible');
            }

            resendBtn.addEventListener('click', async function () {
                clearAlerts();
                resendBtn.disabled = true;
                resendBtn.textContent = 'Sending…';

                try {
                    const response = await fetch('/api/auth/verification/resend', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': window.MyoAuth.csrfToken(),
                        },
                    });

                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        setAlert(alertError, payload.message || 'Unable to resend the verification email.');
                        return;
                    }

                    setAlert(alertSuccess, payload.message);
                } catch (error) {
                    setAlert(alertError, 'Unable to reach the server. Please check your connection and try again.');
                } finally {
                    resendBtn.disabled = false;
                    resendBtn.textContent = 'Resend Email';
                }
            });

            async function logout() {
                try {
                    await fetch('/api/auth/logout', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': window.MyoAuth.csrfToken(),
                        },
                    });
                } finally {
                    window.location.href = '/login';
                }
            }

            document.getElementById('logout-btn').addEventListener('click', logout);
            document.getElementById('logout-icon-button').addEventListener('click', logout);
        })();
    </script>
</body>
</html>
