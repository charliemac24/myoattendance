<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Forgot password · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700,800">
    <link rel="stylesheet" href="{{ asset('css/auth-shared.css') }}">
    <style>
        body { background: #f9fafb; display: flex; justify-content: center; }
    </style>
</head>
<body>
    <div class="app-screen">
        <header class="native-header">
            <div class="header-row">
                <a href="/login" class="back-button" aria-label="Back to sign in">
                    <x-icons.chevron-left />
                </a>
                <div class="title-group">
                    <h1>Forgot Password</h1>
                </div>
            </div>
        </header>

        <p class="form-error" id="form-error" role="alert" style="margin: 20px 20px 0;"></p>
        <x-ui.alert type="success" id="success-alert" style="margin: 20px 20px 0; width: auto;"></x-ui.alert>

        <form id="forgot-password-form" class="content-body" novalidate>
            <div class="intro">
                <h2>Reset Operational Password</h2>
                <p>Enter your registered administrator email address and we'll send you a password recovery link.</p>
            </div>

            <x-auth.field>
                <label class="field-label" for="email">Email Address</label>
                <x-auth.input-shell icon="mail">
                    <input type="email" id="email" name="email" placeholder="operator@enterprise.com" autocomplete="email" required>
                </x-auth.input-shell>
            </x-auth.field>

            <button type="submit" class="btn-primary" id="submit-btn">Send Reset Link</button>

            <div class="prompt-link">
                <span>Remembered your password?</span>
                <a href="/login">Back to Login</a>
            </div>
        </form>
    </div>

    <script src="{{ asset('js/auth-shared.js') }}"></script>
    <script>
        (function () {
            const form = document.getElementById('forgot-password-form');
            const errorBox = document.getElementById('form-error');
            const successBox = document.getElementById('success-alert');
            const submitBtn = document.getElementById('submit-btn');

            function clearAlerts() {
                errorBox.textContent = '';
                errorBox.classList.remove('visible');
                successBox.classList.remove('visible');
            }

            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                clearAlerts();
                submitBtn.disabled = true;
                submitBtn.textContent = 'Sending…';

                try {
                    const payload = await window.MyoAuth.submitJson('/api/auth/forgot-password', {
                        email: document.getElementById('email').value,
                    });

                    successBox.querySelector('p').textContent = payload.message;
                    successBox.classList.add('visible');
                } catch (error) {
                    errorBox.textContent = window.MyoAuth.firstErrorMessage(error);
                    errorBox.classList.add('visible');
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Send Reset Link';
                }
            });
        })();
    </script>
</body>
</html>
