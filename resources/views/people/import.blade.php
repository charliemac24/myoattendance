<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Import CSV · {{ config('app.name') }}</title>
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
        .import-option { display: flex; gap: 12px; align-items: flex-start; margin-top: 20px; padding: 14px; border: 1px solid #d1d5db; border-radius: 12px; background: #fff; }
        .import-option input { margin-top: 3px; }
        .import-option span { display: flex; flex-direction: column; gap: 4px; }
        .import-option small { color: #6b7280; }
    </style>
</head>
<body>
    <div class="app-screen">
        <header class="native-header">
            <div class="header-row">
                <a href="/people" class="back-button" id="back-button" aria-label="Back to {{ strtolower($terminology['person_plural']) }}">
                    <x-icons.chevron-left />
                </a>
                <div class="title-group">
                    <h1 id="header-title">Import CSV</h1>
                    <p id="header-subtitle">Bulk Profile Registration</p>
                </div>
            </div>
        </header>

        <div id="upload-section" class="import-body">
            <p class="form-error" id="import-error" role="alert"></p>

            <label class="file-drop-area" for="csv-input">
                <div class="file-drop-icon"><x-icons.file-text-lg /></div>
                <div class="file-drop-text">
                    <p class="file-drop-title" id="file-drop-title">Select CSV Document</p>
                    <p class="file-drop-sub" id="file-drop-sub">Tap to explore local storage files (.csv)</p>
                </div>
                <span class="btn-browse">Browse Files</span>
                <input type="file" id="csv-input" accept=".csv,text/csv" hidden>
            </label>

            <div class="columns-section">
                <p class="columns-heading">Expected Columns Mapping</p>
                <div class="columns-stack">
                    <div class="column-row">
                        <div class="column-left">
                            <span class="column-name">person_number</span>
                            <span class="required-tag">REQUIRED</span>
                        </div>
                        <span class="column-desc">Unique record identifier</span>
                    </div>
                    <div class="column-row">
                        <div class="column-left">
                            <span class="column-name">first_name</span>
                            <span class="required-tag">REQUIRED</span>
                        </div>
                        <span class="column-desc">Given legal name</span>
                    </div>
                    <div class="column-row">
                        <div class="column-left">
                            <span class="column-name">last_name</span>
                            <span class="required-tag">REQUIRED</span>
                        </div>
                        <span class="column-desc">Family / surname</span>
                    </div>
                    <div class="column-row">
                        <div class="column-left"><span class="column-name">group</span></div>
                        <span class="column-desc">Primary {{ strtolower($terminology['group_singular']) }}</span>
                    </div>
                    <div class="column-row">
                        <div class="column-left"><span class="column-name">subgroup</span></div>
                        <span class="column-desc">Sub-team or sectional info</span>
                    </div>
                    <div class="column-row">
                        <div class="column-left"><span class="column-name">contact_number</span></div>
                        <span class="column-desc">Emergency mobile entry</span>
                    </div>
                    @if ($user->organization->type !== 'school')
                    <div class="column-row">
                        <div class="column-left"><span class="column-name">membership_valid_until</span></div>
                        <span class="column-desc">Last day of membership, e.g. 2026-12-31</span>
                    </div>
                    @endif
                    <div class="column-row">
                        <div class="column-left"><span class="column-name">qr_token</span></div>
                        <span class="column-desc">Optional existing QR token</span>
                    </div>
                </div>
            </div>

            <label class="import-option" for="upsert-input">
                <input type="checkbox" id="upsert-input">
                <span>
                    <strong>Update existing persons</strong>
                    <small>Match by organization and person number. New rows are still added.</small>
                </span>
            </label>
        </div>

        <div id="results-section" class="import-body" hidden>
            <div class="metrics-row">
                <div class="metric-box metric-box-success">
                    <p class="metric-label">IMPORTED</p>
                    <p class="metric-value" id="metric-imported">0</p>
                    <p class="metric-sub">Added profiles</p>
                </div>
                <div class="metric-box metric-box-success">
                    <p class="metric-label">UPDATED</p>
                    <p class="metric-value" id="metric-updated">0</p>
                    <p class="metric-sub">Existing profiles</p>
                </div>
                <div class="metric-box metric-box-warning">
                    <p class="metric-label">SKIPPED</p>
                    <p class="metric-value" id="metric-duplicates">0</p>
                    <p class="metric-sub">Skipped</p>
                </div>
                <div class="metric-box metric-box-error">
                    <p class="metric-label">FAILED</p>
                    <p class="metric-value" id="metric-failed">0</p>
                    <p class="metric-sub">Skipped rows</p>
                </div>
            </div>

            <div class="error-logs-section" id="error-logs-section" hidden>
                <p class="error-logs-heading">Row-Level Processing Errors</p>
                <div class="logs-stack" id="logs-stack"></div>
            </div>
        </div>

        <div class="action-bar">
            <button type="button" class="btn-primary" id="upload-btn" disabled>Upload &amp; Validate Data</button>
            <a href="/people" class="btn-primary" id="finish-btn" style="text-align:center;text-decoration:none;display:none;">Continue to Roster</a>
        </div>
    </div>

    <script src="{{ asset('js/auth-shared.js') }}"></script>
    <script>
        (function () {
            const fileInput = document.getElementById('csv-input');
            const dropTitle = document.getElementById('file-drop-title');
            const dropSub = document.getElementById('file-drop-sub');
            const uploadBtn = document.getElementById('upload-btn');
            const finishBtn = document.getElementById('finish-btn');
            const errorBox = document.getElementById('import-error');
            const backButton = document.getElementById('back-button');
            const headerTitle = document.getElementById('header-title');
            const headerSubtitle = document.getElementById('header-subtitle');
            const uploadSection = document.getElementById('upload-section');
            const resultsSection = document.getElementById('results-section');
            const errorLogsSection = document.getElementById('error-logs-section');
            const logsStack = document.getElementById('logs-stack');
            const upsertInput = document.getElementById('upsert-input');

            let selectedFile = null;

            function escapeHtml(value) {
                return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
            }

            function clearError() {
                errorBox.textContent = '';
                errorBox.classList.remove('visible');
            }

            function resetPicker() {
                selectedFile = null;
                fileInput.value = '';
                dropTitle.textContent = 'Select CSV Document';
                dropSub.textContent = 'Tap to explore local storage files (.csv)';
                uploadBtn.disabled = true;
                clearError();
            }

            fileInput.addEventListener('change', () => {
                clearError();
                selectedFile = fileInput.files[0] || null;
                if (selectedFile) {
                    dropTitle.textContent = selectedFile.name;
                    dropSub.textContent = 'Ready to upload';
                    uploadBtn.disabled = false;
                } else {
                    resetPicker();
                }
            });

            function showResults(payload) {
                document.getElementById('metric-imported').textContent = payload.imported;
                document.getElementById('metric-updated').textContent = payload.updated || 0;
                document.getElementById('metric-duplicates').textContent = payload.skipped_duplicates;
                document.getElementById('metric-failed').textContent = payload.failed;

                const errors = payload.errors || [];
                if (errors.length) {
                    logsStack.innerHTML = errors.map((item) => `
                        <div class="log-row">
                            <div class="log-meta-header">
                                <div class="log-header-left">
                                    <span class="log-row-number">Row #${escapeHtml(item.row)}</span>
                                </div>
                                <span class="log-type-tag">FAILED</span>
                            </div>
                            <p class="log-message">${escapeHtml(item.message)}</p>
                        </div>
                    `).join('');
                    errorLogsSection.hidden = false;
                } else {
                    errorLogsSection.hidden = true;
                }

                headerTitle.textContent = 'Import Complete';
                headerSubtitle.textContent = 'Process report log details';
                backButton.style.display = 'none';
                uploadSection.hidden = true;
                resultsSection.hidden = false;
                uploadBtn.style.display = 'none';
                finishBtn.style.display = 'flex';
            }

            uploadBtn.addEventListener('click', async () => {
                if (!selectedFile) return;
                clearError();
                uploadBtn.disabled = true;
                uploadBtn.textContent = 'Uploading…';

                try {
                    const form = new FormData();
                    form.append('file', selectedFile);
                    form.append('upsert', upsertInput.checked ? '1' : '0');
                    const response = await fetch('/api/people/import', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': window.MyoAuth.csrfToken(),
                        },
                        body: form,
                    });

                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        errorBox.textContent = payload.message || 'Unable to import this file.';
                        errorBox.classList.add('visible');
                        return;
                    }

                    showResults(payload);
                } catch (error) {
                    errorBox.textContent = 'Unable to reach the server. Please check your connection and try again.';
                    errorBox.classList.add('visible');
                } finally {
                    uploadBtn.disabled = !selectedFile;
                    uploadBtn.textContent = 'Upload & Validate Data';
                }
            });
        })();
    </script>
</body>
</html>
