<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700,800">
    <link rel="stylesheet" href="{{ asset('css/auth-shared.css') }}">
    <style>
        body { display: flex; justify-content: center; }

        .login-screen {
            position: relative;
            width: 100%;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .login-screen::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url('{{ asset('images/auth/login-background.png') }}');
            background-size: cover;
            background-position: center;
            z-index: 0;
        }

        .login-screen::after {
            content: '';
            position: fixed;
            inset: 0;
            background: linear-gradient(to bottom, rgba(6,12,28,0.18) 0%, rgba(6,12,28,0.42) 48%, rgba(6,12,28,0.88) 100%);
            z-index: 1;
        }

        .login-content {
            position: relative;
            z-index: 2;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 32px;
            padding: 24px;
            width: 100%;
            max-width: 430px;
            margin: 0 auto;
        }

        .branding {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .brand-logo-surface {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100px;
        }

        .brand-logo-surface img {
            width: 200px;
            height: 67px;
            object-fit: contain;
        }

        .login-card {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 20px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: 24px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 12px 14px rgba(0, 0, 0, 0.15);
        }

        .form-section {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .actions-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="login-screen">
        <div class="login-content">
            <div class="branding">
                <div class="brand-logo-surface">
                    <img src="{{ asset('images/auth/myo-logo-white.png') }}" alt="{{ config('app.name') }}">
                </div>
            </div>

            <div class="login-card">
                <p class="form-error" id="form-error" role="alert"></p>

                <form id="login-form" novalidate>
                    <div class="form-section">
                        <x-auth.field>
                            <label class="field-label" for="identifier">Username or Email</label>
                            <x-auth.input-shell icon="mail">
                                <input type="text" id="identifier" name="identifier" placeholder="alex.mercer or operator@enterprise.com" autocomplete="username" required>
                            </x-auth.input-shell>
                        </x-auth.field>

                        <x-auth.field>
                            <div class="field-label">
                                <label for="password">Password</label>
                                <a href="/forgot-password" class="text-link">Forgot Password?</a>
                            </div>
                            <x-auth.input-shell icon="lock">
                                <input type="password" id="password" name="password" autocomplete="current-password" required>
                                <x-slot:trailing>
                                    <button type="button" class="toggle-password" data-toggle-password="password" aria-label="Show password">
                                        <x-icons.eye />
                                    </button>
                                </x-slot:trailing>
                            </x-auth.input-shell>
                        </x-auth.field>
                    </div>

                    <div class="actions-section">
                        <button type="submit" class="btn-primary" id="submit-btn">Log In</button>
                        <div class="prompt-link">
                            <span>New to MYO?</span>
                            <a href="/register">Create Account</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="{{ asset('js/auth-shared.js') }}"></script>
    <script>
        (function () {
            const form = document.getElementById('login-form');
            const errorBox = document.getElementById('form-error');
            const submitBtn = document.getElementById('submit-btn');
            const passwordInput = document.getElementById('password');

            function showError(message) {
                errorBox.textContent = message;
                errorBox.classList.add('visible');
            }

            function clearError() {
                errorBox.textContent = '';
                errorBox.classList.remove('visible');
            }

            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                clearError();
                submitBtn.disabled = true;
                submitBtn.textContent = 'Logging in…';

                try {
                    const payload = await window.MyoAuth.submitJson('/api/auth/login', {
                        identifier: document.getElementById('identifier').value,
                        password: passwordInput.value,
                    });

                    const user = payload.user;
                    window.location.href = user && user.email_verified_at ? '/app' : '/verify-email';
                } catch (error) {
                    showError(window.MyoAuth.firstErrorMessage(error));
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Log In';
                }
            });
        })();
    </script>
</body>
</html>
