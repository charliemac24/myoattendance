<x-layouts.app
    :user="$user"
    active-tab="more"
    page-title="Terminology · {{ config('app.name') }}"
>
    <div class="people-roster" style="padding:16px;gap:20px;">
        <div class="terminology-explain-card">
            <p>Changing labels updates terminology throughout MYO Attendance in screens, headers, buttons, and system sheets instantly.</p>
        </div>

        <p class="form-error" id="terminology-error" role="alert" style="width:100%;"></p>
        <p class="form-success" id="terminology-success" role="status" style="width:100%;"></p>

        <div class="terminology-section">
            <p class="terminology-section-label">Industry</p>
            <div class="terminology-field">
                <label class="terminology-field-label" for="tm-industry">Your organization is a&hellip;</label>
                <select id="tm-industry" class="terminology-input">
                    @foreach (\App\Support\OrganizationTypes::selectableLabels($user->organization->type) as $value => $label)
                        <option value="{{ $value }}" @selected($user->organization->type === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="terminology-field-label" style="font-weight:400;font-size:12px;">Picking an industry switches the labels below to its usual words.</p>
            </div>
        </div>

        <form id="terminology-form" novalidate style="display:flex;flex-direction:column;gap:16px;width:100%;">
            <div class="terminology-section">
                <p class="terminology-section-label">Personnel Vocabulary</p>
                <div class="terminology-row">
                    <div class="terminology-field">
                        <label class="terminology-field-label" for="tm-person-singular">Person (Singular)</label>
                        <input type="text" id="tm-person-singular" class="terminology-input" value="{{ $terminology['person_singular'] }}" maxlength="40" required>
                    </div>
                    <div class="terminology-field">
                        <label class="terminology-field-label" for="tm-person-plural">Person (Plural)</label>
                        <input type="text" id="tm-person-plural" class="terminology-input" value="{{ $terminology['person_plural'] }}" maxlength="40" required>
                    </div>
                </div>
            </div>

            <div class="terminology-section">
                <p class="terminology-section-label">Primary Group Vocabulary</p>
                <div class="terminology-row">
                    <div class="terminology-field">
                        <label class="terminology-field-label" for="tm-group-singular">Group (Singular)</label>
                        <input type="text" id="tm-group-singular" class="terminology-input" value="{{ $terminology['group_singular'] }}" maxlength="40" required>
                    </div>
                    <div class="terminology-field">
                        <label class="terminology-field-label" for="tm-group-plural">Group (Plural)</label>
                        <input type="text" id="tm-group-plural" class="terminology-input" value="{{ $terminology['group_plural'] }}" maxlength="40" required>
                    </div>
                </div>
            </div>

            <div class="terminology-section">
                <p class="terminology-section-label">Secondary Group Vocabulary</p>
                <div class="terminology-row">
                    <div class="terminology-field">
                        <label class="terminology-field-label" for="tm-subgroup-singular">Subgroup (Singular)</label>
                        <input type="text" id="tm-subgroup-singular" class="terminology-input" value="{{ $terminology['subgroup_singular'] }}" maxlength="40" required>
                    </div>
                    <div class="terminology-field">
                        <label class="terminology-field-label" for="tm-subgroup-plural">Subgroup (Plural)</label>
                        <input type="text" id="tm-subgroup-plural" class="terminology-input" value="{{ $terminology['subgroup_plural'] }}" maxlength="40" required>
                    </div>
                </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:10px;width:100%;padding-top:12px;">
                <button type="submit" class="btn-primary" id="terminology-save">Save Changes</button>
                <button type="button" class="btn-cancel-outline" id="terminology-restore">Restore Defaults</button>
            </div>
        </form>
    </div>

    <x-slot:scripts>
        <script>
            (function () {
                const form = document.getElementById('terminology-form');
                const errorBox = document.getElementById('terminology-error');
                const successBox = document.getElementById('terminology-success');
                const saveBtn = document.getElementById('terminology-save');
                const restoreBtn = document.getElementById('terminology-restore');

                const fields = {
                    person_singular: document.getElementById('tm-person-singular'),
                    person_plural: document.getElementById('tm-person-plural'),
                    group_singular: document.getElementById('tm-group-singular'),
                    group_plural: document.getElementById('tm-group-plural'),
                    subgroup_singular: document.getElementById('tm-subgroup-singular'),
                    subgroup_plural: document.getElementById('tm-subgroup-plural'),
                };

                function clearMessages() {
                    errorBox.classList.remove('visible');
                    successBox.classList.remove('visible');
                }

                function applyTerminology(terminology) {
                    Object.entries(fields).forEach(([key, input]) => { input.value = terminology[key]; });
                }

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    clearMessages();
                    saveBtn.disabled = true;
                    saveBtn.textContent = 'Saving…';

                    const payload = {};
                    Object.entries(fields).forEach(([key, input]) => { payload[key] = input.value; });

                    try {
                        const result = await window.MyoAuth.submitJson('/api/organization/terminology', payload, 'PUT');
                        applyTerminology(result.terminology);
                        successBox.textContent = 'Labels saved.';
                        successBox.classList.add('visible');
                    } catch (error) {
                        errorBox.textContent = window.MyoAuth.firstErrorMessage(error);
                        errorBox.classList.add('visible');
                    } finally {
                        saveBtn.disabled = false;
                        saveBtn.textContent = 'Save Changes';
                    }
                });

                const industry = document.getElementById('tm-industry');
                let currentIndustry = industry.value;
                industry.addEventListener('change', async () => {
                    clearMessages();
                    const label = industry.options[industry.selectedIndex].text;
                    if (!window.confirm(`Switch to ${label}? Your labels will change to the usual ${label} words.`)) {
                        industry.value = currentIndustry;
                        return;
                    }
                    industry.disabled = true;
                    try {
                        const result = await window.MyoAuth.submitJson('/api/organization/industry', { type: industry.value }, 'PUT');
                        currentIndustry = result.type;
                        applyTerminology(result.terminology);
                        successBox.textContent = `Switched to ${label}.`;
                        successBox.classList.add('visible');
                    } catch (error) {
                        industry.value = currentIndustry;
                        errorBox.textContent = window.MyoAuth.firstErrorMessage(error);
                        errorBox.classList.add('visible');
                    } finally {
                        industry.disabled = false;
                    }
                });

                restoreBtn.addEventListener('click', async () => {
                    clearMessages();
                    restoreBtn.disabled = true;

                    try {
                        const response = await fetch('/api/organization/terminology/reset', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': window.MyoAuth.csrfToken(),
                            },
                        });
                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            errorBox.textContent = payload.message || 'Unable to restore default labels.';
                            errorBox.classList.add('visible');
                            return;
                        }

                        applyTerminology(payload.terminology);
                        successBox.textContent = 'Default labels restored.';
                        successBox.classList.add('visible');
                    } catch (error) {
                        errorBox.textContent = 'Unable to reach the server. Please check your connection and try again.';
                        errorBox.classList.add('visible');
                    } finally {
                        restoreBtn.disabled = false;
                    }
                });
            })();
        </script>
    </x-slot:scripts>
</x-layouts.app>
