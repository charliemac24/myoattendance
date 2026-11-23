<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Add {{ $terminology['group_singular'] }} · {{ config('app.name') }}</title>
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
                    <h1>Add {{ $terminology['group_singular'] }}</h1>
                </div>
            </div>
        </header>

        <div class="create-form-body">
            <p class="form-error" id="form-error" role="alert"></p>

            <form id="create-group-form" novalidate>
                <div class="plain-field">
                    <label class="plain-field-label" for="group-name">{{ $terminology['group_singular'] }} Name</label>
                    <input type="text" id="group-name" name="name" class="plain-input" placeholder="{{ \App\Support\OrganizationTypes::nameExample($user->organization->type, 'group') }}" required>
                    <p class="field-helper">Use clear, recognizable labels to group attendance logs.</p>
                </div>
                @include('groups.partials.mode-fields', ['terminology' => $terminology, 'mode' => $user->organization->defaultGroupMode()])
            </form>

            <div class="guidance-card">
                <div class="guidance-headline">
                    <x-icons.info />
                    <span>About {{ $terminology['group_plural'] }}</span>
                </div>
                <p class="guidance-body">{{ $terminology['group_plural'] }} allow you to categorize registered members and assign targeted rosters, schedules, and operations natively without complexity.</p>
            </div>
        </div>

        <div class="action-sticky">
            <button type="submit" form="create-group-form" class="btn-primary" id="create-group-submit">Create {{ $terminology['group_singular'] }}</button>
        </div>
    </div>

    <script src="{{ asset('js/auth-shared.js') }}"></script>
    <script>
        (function () {
            const form = document.getElementById('create-group-form');
            const nameInput = document.getElementById('group-name');
            const errorBox = document.getElementById('form-error');
            const submitBtn = document.getElementById('create-group-submit');

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                errorBox.textContent = '';
                errorBox.classList.remove('visible');
                submitBtn.disabled = true;
                submitBtn.textContent = 'Creating…';

                try {
                    const payload = await window.MyoAuth.submitJson('/api/groups', {
                        name: nameInput.value,
                        ...window.MyoGroupMode(),
                    });
                    window.location.href = '/groups';
                } catch (error) {
                    errorBox.textContent = window.MyoAuth.firstErrorMessage(error);
                    errorBox.classList.add('visible');
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Create {{ $terminology['group_singular'] }}';
                }
            });
        })();
    </script>
</body>
</html>
