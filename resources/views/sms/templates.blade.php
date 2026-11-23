<x-layouts.app
    :user="$user"
    active-tab="more"
    page-title="Message Templates · {{ config('app.name') }}"
>
    <div class="people-roster" style="padding:16px;gap:16px;">
        <div class="empty-state" id="sms-loading">
            <p>Loading&hellip;</p>
        </div>

        <div class="people-empty-card" id="sms-locked" hidden>
            <div class="people-empty-icon-wrap"><x-icons.mail-big stroke="#9CA3AF" /></div>
            <div class="people-empty-text-wrap">
                <p class="people-empty-title" id="sms-locked-title">SMS Notifications Not Active</p>
                <p class="people-empty-body" id="sms-locked-text"></p>
            </div>
            <p class="people-empty-body" id="sms-request-help" hidden>Request SMS and choose when your paid plan (Base + SMS) should start. MYO Attendance checks your payment before it starts.</p>
            <div class="billing-sms-choice" id="sms-request-actions" hidden>
                <button type="button" class="billing-sms-choice-btn billing-sms-choice-btn--primary" id="sms-request-after-trial">
                    <span class="billing-sms-choice-icon"><x-icons.clock stroke="#2563EB" /></span>
                    <span class="billing-sms-choice-text">
                        <span class="billing-sms-choice-title">Request SMS · start my paid plan after the trial</span>
                        <span class="billing-sms-choice-caption">Recommended &middot; you keep your remaining free days</span>
                    </span>
                    <span class="billing-sms-choice-chevron"><x-icons.chevron-right stroke="#2563EB" /></span>
                </button>
                <button type="button" class="billing-sms-choice-btn billing-sms-choice-btn--warning" id="sms-request-immediate">
                    <span class="billing-sms-choice-icon"><x-icons.zap stroke="#B45309" /></span>
                    <span class="billing-sms-choice-text">
                        <span class="billing-sms-choice-title">Request SMS · start my paid plan right away</span>
                        <span class="billing-sms-choice-caption">Your free trial ends early, on the day your payment is approved</span>
                    </span>
                    <span class="billing-sms-choice-chevron"><x-icons.chevron-right stroke="#B45309" /></span>
                </button>
            </div>
        </div>

        <div id="sms-active" hidden style="display:flex;flex-direction:column;gap:16px;width:100%;">
            <div class="sms-status-pill" id="sms-active-badge" hidden>
                <span class="dot"></span>
                <span id="sms-active-badge-text"></span>
            </div>

            <div class="sms-warning-banner">
                <x-icons.alert-triangle />
                <p>No messages are sent from this page. This updates SMS templates only.</p>
            </div>

            <div id="sms-template-list" style="display:flex;flex-direction:column;gap:12px;width:100%;"></div>
        </div>
    </div>

    <script>
        window.MyoSmsTemplates = { userRole: @json($user->role) };
    </script>

    <x-slot:scripts>
        <div class="sheet-overlay" id="sms-edit-overlay" hidden>
            <div class="sheet-panel" role="dialog" aria-label="Edit Template">
                <div class="sheet-handle"></div>
                <div style="display:flex;align-items:center;justify-content:space-between;width:100%;margin-bottom:16px;">
                    <div>
                        <p class="people-empty-title" style="font-size:11px;color:#9ca3af;text-transform:uppercase;margin:0;">Edit Template</p>
                        <p class="sms-template-name" id="sms-edit-name" style="font-size:18px;"></p>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" id="sms-edit-enabled">
                        <span class="toggle-switch-track"></span>
                    </label>
                </div>

                <p class="form-error" id="sms-edit-error" role="alert"></p>
                <p class="form-success" id="sms-edit-success" role="status"></p>

                <div style="display:flex;flex-direction:column;gap:8px;width:100%;">
                    <div style="display:flex;align-items:center;justify-content:space-between;width:100%;">
                        <label class="plain-field-label" for="sms-edit-body" style="font-size:13px;">Message body</label>
                        <p class="sms-char-count" id="sms-edit-count">0 / 1000 characters</p>
                    </div>
                    <textarea id="sms-edit-body" class="plain-input" maxlength="1000" rows="4" style="resize:vertical;font-family:inherit;"></textarea>
                    <p class="sms-validation-warning" id="sms-edit-warning" hidden><span class="dot"></span><span id="sms-edit-warning-text"></span></p>
                </div>

                <div style="display:flex;flex-direction:column;gap:8px;width:100%;margin-top:16px;">
                    <p class="people-empty-title" style="font-size:12px;color:#9ca3af;text-transform:uppercase;margin:0;">Supported variables</p>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                        <span class="sms-variable-chip">@{{name}}</span>
                        <span class="sms-variable-chip">@{{time}}</span>
                        <span class="sms-variable-chip">@{{date}}</span>
                    </div>
                </div>

                <div class="sms-preview-box" style="margin-top:16px;">
                    <div class="sms-preview-box-header">
                        <p class="sms-preview-box-label">Live phone preview</p>
                        <p class="sms-preview-box-note">Standard SMS charges apply</p>
                    </div>
                    <p class="sms-preview-box-body" id="sms-edit-preview"></p>
                </div>

                <div style="display:flex;flex-direction:column;gap:12px;margin-top:20px;">
                    <button type="button" class="btn-primary" id="sms-edit-save">Save Template</button>
                    <button type="button" class="people-action" id="sms-edit-cancel">Cancel</button>
                </div>
            </div>
        </div>

        <template id="sms-template-card-template">
            <button type="button" class="sms-template-card" data-key>
                <div class="sms-template-header">
                    <div class="sms-template-name-row">
                        <span class="sms-template-name-dot" data-dot></span>
                        <p class="sms-template-name" data-name></p>
                    </div>
                    <p class="sms-template-enabled-label" data-enabled-label></p>
                </div>
                <p class="sms-template-desc" data-desc></p>
                <div class="sms-template-preview">
                    <p class="sms-template-preview-label">Preview template</p>
                    <p class="sms-template-preview-body" data-preview></p>
                </div>
            </button>
        </template>

        @vite(['resources/js/sms-templates.js'])
    </x-slot:scripts>
</x-layouts.app>
