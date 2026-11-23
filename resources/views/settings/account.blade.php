<x-layouts.app
    :user="$user"
    active-tab="more"
    page-title="Edit Account · {{ config('app.name') }}"
>
    <div class="settings-section-heading">
        <div class="settings-icon-circle"><x-icons.user stroke="#2563EB" /></div>
        <div class="settings-heading-text">
            <h2>Profile Information</h2>
            <p>Manage your personal details</p>
        </div>
    </div>

    <div class="settings-card">
        <p class="form-error" id="profile-error" role="alert"></p>
        <p class="form-success" id="profile-success" role="status"></p>

        <form id="profile-form" novalidate style="display:flex;flex-direction:column;gap:16px;width:100%;">
            <div class="settings-field">
                <label class="settings-label-row" for="account-name">
                    <x-icons.user stroke="#4B5563" />
                    <span>Full Name</span>
                </label>
                <input type="text" id="account-name" class="settings-input settings-input--editable" value="{{ $user->name }}" maxlength="255" required>
            </div>
            <div class="settings-field">
                <label class="settings-label-row" for="account-username">
                    <x-icons.user stroke="#4B5563" />
                    <span>Username</span>
                </label>
                <input type="text" id="account-username" class="settings-input settings-input--editable" value="{{ $user->username ?: $user->email }}" maxlength="50" pattern="[A-Za-z0-9._@+-]+" required>
                <small>Use letters, numbers, periods, underscores, hyphens, or @.</small>
            </div>
            <div class="settings-field">
                <label class="settings-label-row" for="account-email">
                    <x-icons.mail stroke="#4B5563" />
                    <span>Email Address</span>
                </label>
                <input type="email" id="account-email" class="settings-input" value="{{ $user->email }}" disabled>
            </div>
        </form>
    </div>

    <div class="settings-section-gap"></div>

    <div class="settings-section-heading">
        <div class="settings-icon-circle settings-icon-circle--danger"><x-icons.lock stroke="#EF4444" /></div>
        <div class="settings-heading-text">
            <h2>Change Password</h2>
            <p>Keep your account secure</p>
        </div>
    </div>

    <div class="settings-card">
        <p class="form-error" id="password-error" role="alert"></p>
        <p class="form-success" id="password-success" role="status"></p>

        <form id="password-form" novalidate style="display:flex;flex-direction:column;gap:16px;width:100%;">
            <div class="settings-field">
                <label class="settings-label-row" for="account-current-password">
                    <x-icons.shield stroke="#4B5563" />
                    <span>Current Password</span>
                </label>
                <input type="password" id="account-current-password" class="settings-input settings-input--editable" placeholder="Enter current password" autocomplete="current-password">
            </div>
            <div class="settings-field">
                <label class="settings-label-row" for="account-password">
                    <x-icons.lock stroke="#4B5563" />
                    <span>New Password</span>
                </label>
                <input type="password" id="account-password" class="settings-input settings-input--editable" placeholder="Enter new password" autocomplete="new-password">
            </div>
            <div class="settings-field">
                <label class="settings-label-row" for="account-password-confirmation">
                    <x-icons.shield stroke="#4B5563" />
                    <span>Confirm Password</span>
                </label>
                <input type="password" id="account-password-confirmation" class="settings-input settings-input--editable" placeholder="Re-enter new password" autocomplete="new-password">
            </div>
        </form>
    </div>

    <div class="settings-cta-area">
        <button type="button" class="btn-primary" id="account-save">Save Changes</button>
    </div>

    <x-slot:scripts>
        <script>
            (function () {
                const nameInput = document.getElementById('account-name');
                const usernameInput = document.getElementById('account-username');
                const currentPasswordInput = document.getElementById('account-current-password');
                const passwordInput = document.getElementById('account-password');
                const passwordConfirmInput = document.getElementById('account-password-confirmation');
                const profileError = document.getElementById('profile-error');
                const profileSuccess = document.getElementById('profile-success');
                const passwordError = document.getElementById('password-error');
                const passwordSuccess = document.getElementById('password-success');
                const saveBtn = document.getElementById('account-save');

                function clearMessages() {
                    [profileError, profileSuccess, passwordError, passwordSuccess].forEach((el) => {
                        el.textContent = '';
                        el.classList.remove('visible');
                    });
                }

                function showMessage(el, text) {
                    el.textContent = text;
                    el.classList.add('visible');
                }

                document.getElementById('account-save').addEventListener('click', async () => {
                    clearMessages();
                    const wantsPasswordChange = passwordInput.value || passwordConfirmInput.value || currentPasswordInput.value;

                    saveBtn.disabled = true;
                    saveBtn.textContent = 'Saving…';

                    try {
                        await window.MyoAuth.submitJson('/api/auth/account', { name: nameInput.value, username: usernameInput.value }, 'PUT');
                        showMessage(profileSuccess, 'Profile details updated.');
                    } catch (error) {
                        showMessage(profileError, window.MyoAuth.firstErrorMessage(error));
                        saveBtn.disabled = false;
                        saveBtn.textContent = 'Save Changes';
                        return;
                    }

                    if (wantsPasswordChange) {
                        try {
                            await window.MyoAuth.submitJson('/api/auth/password', {
                                current_password: currentPasswordInput.value,
                                password: passwordInput.value,
                                password_confirmation: passwordConfirmInput.value,
                            }, 'PUT');
                            showMessage(passwordSuccess, 'Password updated successfully.');
                            currentPasswordInput.value = '';
                            passwordInput.value = '';
                            passwordConfirmInput.value = '';
                        } catch (error) {
                            showMessage(passwordError, window.MyoAuth.firstErrorMessage(error));
                        }
                    }

                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save Changes';
                });
            })();
        </script>
    </x-slot:scripts>
</x-layouts.app>
