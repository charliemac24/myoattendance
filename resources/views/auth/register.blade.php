<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Create account · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700,800">
    <link rel="stylesheet" href="{{ asset('css/auth-shared.css') }}">
    @if (config('services.turnstile.site_key'))
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
    <style>
        body { background: #f9fafb; display: flex; justify-content: center; }
        .register-sms-estimate { display: grid; gap: 10px; padding: 12px 14px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; }
        .register-sms-estimate summary { cursor: pointer; color: #1d4ed8; font-size: 14px; font-weight: 700; }
        .register-sms-estimate[open] summary { margin-bottom: 4px; }
        .register-sms-hint { margin: 0 0 6px; color: #6b7280; font-size: 12px; line-height: 1.45; }
        .register-sms-group { display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: center; margin-top: 8px; font-size: 13px; }
        .register-sms-group .field-label { width: 100%; }
        .register-sms-group label { display: flex; align-items: center; gap: 6px; }

        /* Desktop: a wider card with the fields in pairs. Below 1024px the mobile layout is unchanged. */
        @media (min-width: 1024px) {
            body { align-items: flex-start; padding: 48px 24px; }
            .register-screen { max-width: 880px; min-height: 0; border: 1px solid #e5e7eb; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(17, 24, 39, 0.06); }
            .register-screen .native-header { position: static; padding: 20px 32px; }
            .register-screen .trial-notice { margin: 28px 32px 0; }
            .register-screen #form-error { margin: 28px 32px 0 !important; }
            .register-screen .content-body { display: grid; grid-template-columns: 1fr 1fr; gap: 20px 24px; padding: 28px 32px 32px; }
            .register-screen .content-body > .register-sms-estimate,
            .register-screen .content-body > .cf-turnstile,
            .register-screen .content-body > .btn-primary,
            .register-screen .content-body > .payment-note { grid-column: 1 / -1; }
            .register-screen .content-body > .btn-primary { justify-self: center; width: 100%; max-width: 360px; margin-top: 4px; }
            .register-sms-estimate .field { max-width: calc(50% - 12px); }
        }
    </style>
</head>
<body>
    <div class="app-screen register-screen">
        <header class="native-header">
            <div class="header-row">
                <a href="/login" class="back-button" aria-label="Back to sign in">
                    <x-icons.chevron-left />
                </a>
                <div class="title-group">
                    <h1>Create Admin Account</h1>
                    <p>Register a new MYO Organization</p>
                </div>
            </div>
        </header>

        <p class="form-error" id="form-error" role="alert" style="margin: 20px 20px 0;"></p>

        <aside class="trial-notice" aria-label="Subscription offer">
            <div class="trial-notice-header">
                <strong>30-day free trial — no credit card needed</strong>
            </div>
            <p>Try MYO Attendance free for 30 days. After your trial, continue for <strong>₱2,999/month</strong> — pay conveniently through <strong>GCash</strong>, no credit or debit card required. SMS Notifications are optional and priced separately.</p>
        </aside>

        <form id="register-form" class="content-body" novalidate>
            <x-auth.field>
                <label class="field-label" for="name">Full Name</label>
                <x-auth.input-shell icon="user">
                    <input type="text" id="name" name="name" placeholder="Alex Mercer" autocomplete="name" required>
                </x-auth.input-shell>
            </x-auth.field>

            <x-auth.field>
                <label class="field-label" for="username">Username</label>
                <x-auth.input-shell icon="user">
                    <input type="text" id="username" name="username" placeholder="alex.mercer" autocomplete="username" required pattern="[A-Za-z0-9._-]+">
                </x-auth.input-shell>
            </x-auth.field>

            <x-auth.field>
                <label class="field-label" for="organization_name">Organization Name</label>
                <x-auth.input-shell icon="briefcase">
                    <input type="text" id="organization_name" name="organization_name" placeholder="Apex Operations" autocomplete="organization" required>
                </x-auth.input-shell>
            </x-auth.field>

            <x-auth.field>
                <label class="field-label" for="organization_type">Organization Type</label>
                <x-auth.input-shell icon="grid">
                    <select id="organization_type" name="organization_type" required>
                        <option value="" selected disabled>Select organization type</option>
                        @foreach (\App\Support\OrganizationTypes::selectableLabels() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-slot:trailing>
                        <span class="select-chevron"><x-icons.chevron-down /></span>
                    </x-slot:trailing>
                </x-auth.input-shell>
            </x-auth.field>

            <x-auth.field>
                <label class="field-label" for="email">Email Address</label>
                <x-auth.input-shell icon="mail">
                    <input type="email" id="email" name="email" placeholder="alex@enterprise.com" autocomplete="email" required>
                </x-auth.input-shell>
            </x-auth.field>

            <x-auth.field>
                <label class="field-label" for="contact_number">Owner Contact Number</label>
                <x-auth.input-shell icon="phone">
                    <input type="tel" id="contact_number" name="contact_number" placeholder="+63 917 123 4567" autocomplete="tel" required>
                </x-auth.input-shell>
            </x-auth.field>

            <details class="register-sms-estimate">
                <summary>SMS Notifications (optional) · get an instant SMS price</summary>
                <p class="register-sms-hint">SMS texts a person's contact number when their Time In, Late, or Time Out is recorded. You can skip this and answer later on the Billing page.</p>
                <x-auth.field>
                    <label class="field-label" for="sms_estimate_people">How many people will receive SMS?</label>
                    <x-auth.input-shell icon="users">
                        <input type="number" id="sms_estimate_people" name="sms_estimate_people" min="1" max="100000" placeholder="e.g. 350">
                    </x-auth.input-shell>
                </x-auth.field>
                <div class="register-sms-group">
                    <span class="field-label">Which texts?</span>
                    <label><input type="checkbox" name="sms_estimate_texts" value="time_in" checked> Time In</label>
                    <label><input type="checkbox" name="sms_estimate_texts" value="time_out" checked> Time Out</label>
                    <label><input type="checkbox" name="sms_estimate_texts" value="late"> Late (replaces Time In)</label>
                </div>
                <div class="register-sms-group">
                    <span class="field-label">Which days?</span>
                    <label><input type="radio" name="sms_estimate_days" value="weekdays" checked> Weekdays</label>
                    <label><input type="radio" name="sms_estimate_days" value="mon_sat"> Mon–Sat</label>
                    <label><input type="radio" name="sms_estimate_days" value="every_day"> Every day</label>
                </div>
            </details>

            <x-auth.field>
                <label class="field-label" for="password">Password</label>
                <x-auth.input-shell icon="lock">
                    <input type="password" id="password" name="password" placeholder="At least 8 characters" autocomplete="new-password" required>
                    <x-slot:trailing>
                        <button type="button" class="toggle-password" data-toggle-password="password" aria-label="Show password">
                            <x-icons.eye />
                        </button>
                    </x-slot:trailing>
                </x-auth.input-shell>
            </x-auth.field>

            <x-auth.field>
                <label class="field-label" for="password_confirmation">Confirm Password</label>
                <x-auth.input-shell icon="lock">
                    <input type="password" id="password_confirmation" name="password_confirmation" placeholder="Repeat your password" autocomplete="new-password" required>
                    <x-slot:trailing>
                        <button type="button" class="toggle-password" data-toggle-password="password_confirmation" aria-label="Show password">
                            <x-icons.eye />
                        </button>
                    </x-slot:trailing>
                </x-auth.input-shell>
            </x-auth.field>

            @if (config('services.turnstile.site_key'))
                <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" data-action="register"></div>
            @endif

            <button type="submit" class="btn-primary" id="submit-btn">Create Account</button>
            <p class="payment-note">No credit card required — billing is handled through GCash</p>
        </form>
    </div>

    <script src="{{ asset('js/auth-shared.js') }}"></script>
    <script>
        (function () {
            const form = document.getElementById('register-form');
            const errorBox = document.getElementById('form-error');
            const submitBtn = document.getElementById('submit-btn');

            function showError(message) {
                errorBox.textContent = message;
                errorBox.classList.add('visible');
            }

            // The SMS questions are optional; they are only sent when the number of people is filled in.
            function smsEstimate() {
                const people = document.getElementById('sms_estimate_people').value;
                if (!people) return {};
                return {
                    sms_estimate_people: Number(people),
                    sms_estimate_texts: [...form.querySelectorAll('input[name="sms_estimate_texts"]:checked')].map((input) => input.value),
                    sms_estimate_days: form.querySelector('input[name="sms_estimate_days"]:checked')?.value || null,
                };
            }

            function clearError() {
                errorBox.textContent = '';
                errorBox.classList.remove('visible');
            }

            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                clearError();
                submitBtn.disabled = true;
                submitBtn.textContent = 'Creating account…';

                try {
                    const payload = await window.MyoAuth.submitJson('/api/auth/register', {
                        name: document.getElementById('name').value,
                        username: document.getElementById('username').value,
                        organization_name: document.getElementById('organization_name').value,
                        organization_type: document.getElementById('organization_type').value,
                        email: document.getElementById('email').value,
                        contact_number: document.getElementById('contact_number').value,
                        password: document.getElementById('password').value,
                        password_confirmation: document.getElementById('password_confirmation').value,
                        ...smsEstimate(),
                        'cf-turnstile-response': document.querySelector('[name="cf-turnstile-response"]')?.value || '',
                    });

                    const user = payload.user;
                    window.location.href = user && user.email_verified_at ? '/app' : '/verify-email';
                } catch (error) {
                    showError(window.MyoAuth.firstErrorMessage(error));
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Create Account';
                }
            });
        })();
    </script>
</body>
</html>
