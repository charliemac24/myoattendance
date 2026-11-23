<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Edit {{ $terminology['group_singular'] }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700,800">
    <link rel="stylesheet" href="{{ asset('css/auth-shared.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app-shared.css') }}">
    <x-theme-head />
    <style>
        body { background: #f9fafb; display: flex; justify-content: center; }
        .app-screen { display: flex; flex-direction: column; min-height: 100dvh; }
    </style>
</head>
<body>
    <div class="app-screen">
        <header class="native-header">
            <div class="header-row">
                <a href="/groups" class="back-button" aria-label="Back to {{ strtolower($terminology['group_plural']) }}">
                    <x-icons.chevron-left />
                </a>
                <div class="title-group">
                    <h1>Edit {{ $terminology['group_singular'] }}</h1>
                </div>
            </div>
        </header>

        <div class="create-form-body">
            <p class="form-error" id="form-error" role="alert"></p>

            <form id="edit-group-form" novalidate>
                <div class="plain-field">
                    <label class="plain-field-label" for="group-name">{{ $terminology['group_singular'] }} Name</label>
                    <div class="clearable-input-field">
                        <input type="text" id="group-name" name="name" value="{{ $group->name }}" required>
                        <button type="button" class="clear-input-btn" id="clear-name-btn" aria-label="Clear name">
                            <x-icons.x />
                        </button>
                    </div>
                </div>
                @include('groups.partials.mode-fields', ['terminology' => $terminology, 'mode' => $group->attendance_mode, 'scanOut' => $group->scan_out, 'modeLocked' => $group->hasAttendanceEvents()])
            </form>

            <div class="danger-zone">
                <div class="danger-zone-title">
                    <x-icons.alert-circle />
                    <span>Danger Zone</span>
                </div>
                <p class="danger-zone-body">Only a {{ strtolower($terminology['group_singular']) }} that has never been used can be deleted. Once {{ strtolower($terminology['person_plural']) }} have been in it, rename it instead so past reports stay correct. This action cannot be undone.</p>
                <button type="button" class="btn-danger-outline" id="delete-group-btn">Delete {{ $terminology['group_singular'] }}</button>
            </div>
        </div>

        <div class="action-sticky">
            <button type="submit" form="edit-group-form" class="btn-primary" id="save-group-submit">Save Changes</button>
        </div>
    </div>

    <script src="{{ asset('js/auth-shared.js') }}"></script>
    <script>
        (function () {
            const form = document.getElementById('edit-group-form');
            const nameInput = document.getElementById('group-name');
            const clearBtn = document.getElementById('clear-name-btn');
            const errorBox = document.getElementById('form-error');
            const submitBtn = document.getElementById('save-group-submit');
            const deleteBtn = document.getElementById('delete-group-btn');
            const groupId = {{ $group->id }};

            function showError(message) {
                errorBox.textContent = message;
                errorBox.classList.add('visible');
            }

            function clearError() {
                errorBox.textContent = '';
                errorBox.classList.remove('visible');
            }

            clearBtn.addEventListener('click', () => {
                nameInput.value = '';
                nameInput.focus();
            });

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                clearError();
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving…';

                try {
                    await window.MyoAuth.submitJson('/api/groups/' + groupId, { name: nameInput.value, ...window.MyoGroupMode() }, 'PUT');
                    window.location.href = '/groups';
                } catch (error) {
                    showError(window.MyoAuth.firstErrorMessage(error));
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Save Changes';
                }
            });

            deleteBtn.addEventListener('click', async () => {
                if (!window.confirm('Delete this {{ strtolower($terminology['group_singular']) }}? This cannot be undone.')) return;
                clearError();
                deleteBtn.disabled = true;

                try {
                    const response = await fetch('/api/groups/' + groupId, {
                        method: 'DELETE',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': window.MyoAuth.csrfToken(),
                        },
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        showError(payload.message || 'Unable to delete this {{ strtolower($terminology['group_singular']) }}.');
                        return;
                    }

                    window.location.href = '/groups';
                } catch (error) {
                    showError('Unable to reach the server. Please check your connection and try again.');
                } finally {
                    deleteBtn.disabled = false;
                }
            });
        })();
    </script>
</body>
</html>
