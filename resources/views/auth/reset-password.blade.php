<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Reset password · {{ config('app.name') }}</title>
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
                    <h1>Reset Password</h1>
                </div>
            </div>
        </header>

        <p class="form-error" id="form-error" role="alert" style="margin: 20px 20px 0;"></p>
        <x-ui.alert type="success" id="success-alert" style="margin: 20px 20px 0; width: auto;"></x-ui.alert>

        <form id="reset-password-form" class="content-body" novalidate>
            <input type="hidden" id="token" value="{{ $token }}">
            <input type="hidden" id="email" value="{{ $email }}">

            <x-auth.field>
                <label class="field-label" for="password">New Password</label>
                <x-auth.input-shell icon="lock">
                    <input type="password" id="password" name="password" autocomplete="new-password" required>
                </x-auth.input-shell>
            </x-auth.field>

            <x-auth.field>
                <label class="field-label" for="password_confirmation">Confirm Password</label>
                <x-auth.input-shell icon="lock">
                    <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
                </x-auth.input-shell>
            </x-auth.field>

            <div class="guidance-box">
                <h3>Password Security Criteria</h3>
                <div class="rule-row" id="rule-length">
                    <span class="rule-dot"></span>
                    <p>Minimum of 8 characters</p>
                </div>
                <div class="rule-row" id="rule-case">
                    <span class="rule-dot"></span>
                    <p>At least one uppercase and lowercase letter</p>
                </div>
                <div class="rule-row" id="rule-number">
                    <span class="rule-dot"></span>
                    <p>At least one operational number (0-9)</p>
                </div>
            </div>

            <button type="submit" class="btn-primary" id="submit-btn">Reset Password</button>
        </form>
    </div>

    <script src="{{ asset('js/auth-shared.js') }}"></script>
    <script>
        (function () {
            const form = document.getElementById('reset-password-form');
            const errorBox = document.getElementById('form-error');
            const successBox = document.getElementById('success-alert');
            const submitBtn = document.getElementById('submit-btn');
            const passwordInput = document.getElementById('password');

            const rules = {
                'rule-length': (value) => value.length >= 8,
                'rule-case': (value) => /[a-z]/.test(value) && /[A-Z]/.test(value),
                'rule-number': (value) => /[0-9]/.test(value),
            };

            function updateRules() {
                const value = passwordInput.value;
                Object.entries(rules).forEach(([id, test]) => {
                    document.getElementById(id).classList.toggle('met', test(value));
                });
            }

            passwordInput.addEventListener('input', updateRules);

            function clearAlerts() {
                errorBox.textContent = '';
                errorBox.classList.remove('visible');
                successBox.classList.remove('visible');
            }

            if (!document.getElementById('email').value) {
                errorBox.textContent = 'This reset link is missing your email address. Please request a new password reset link.';
                errorBox.classList.add('visible');
            }

            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                clearAlerts();
                submitBtn.disabled = true;
                submitBtn.textContent = 'Resetting…';

                try {
                    const payload = await window.MyoAuth.submitJson('/api/auth/reset-password', {
                        token: document.getElementById('token').value,
                        email: document.getElementById('email').value,
                        password: passwordInput.value,
                        password_confirmation: document.getElementById('password_confirmation').value,
                    });

                    successBox.querySelector('p').textContent = payload.message;
                    successBox.classList.add('visible');
                    form.reset();
                    updateRules();
                } catch (error) {
                    errorBox.textContent = window.MyoAuth.firstErrorMessage(error);
                    errorBox.classList.add('visible');
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Reset Password';
                }
            });
        })();
    </script>
</body>
</html>
