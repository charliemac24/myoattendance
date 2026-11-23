<x-layouts.app
    :user="$user"
    active-tab="people"
    :page-title="$terminology['person_plural'].' · '.config('app.name')"
>
    <div class="people-controls">
        <label class="search-shell" id="people-search-shell">
            <x-icons.search />
            <input type="text" id="people-search" placeholder="Search name, ID or {{ strtolower($terminology['group_singular']) }}...">
            <button type="button" class="search-shell-clear" id="people-search-clear" aria-label="Clear search" hidden>
                <x-icons.x-circle />
            </button>
        </label>

        <div class="people-actions-row">
            <button type="button" class="people-action people-action--primary" id="add-person-btn">
                <x-icons.user-plus stroke="#2563EB" />
                <span>Add {{ $terminology['person_singular'] }}</span>
            </button>
            <a href="/people/import" class="people-action">
                <x-icons.file-text />
                <span>Import CSV</span>
            </a>
            <a href="/api/people/export" class="people-action">
                <x-icons.download />
                <span>Export CSV</span>
            </a>
            <a href="/groups" class="people-action people-action--accent">
                <x-icons.layers stroke="#2563EB" />
                <span>{{ $terminology['group_plural'] }}</span>
            </a>
        </div>

        <div class="filter-row">
            <select id="filter-group"><option value="">All {{ strtolower($terminology['group_plural']) }}</option></select>
            <select id="filter-subgroup" disabled><option value="">All {{ strtolower($terminology['subgroup_plural']) }}</option></select>
        </div>
    </div>

    <div class="people-roster">
        <div class="active-filters" id="active-filters" hidden>
            <div class="filter-summary">
                <x-icons.layers stroke="#1D4ED8" />
                <p id="filter-summary-text"></p>
                <button type="button" id="clear-filters-btn">Clear</button>
            </div>
            <div class="active-chips" id="active-chips"></div>
        </div>

        <div id="people-list"></div>

        <div class="empty-state" id="people-empty" hidden>
            <p>No {{ strtolower($terminology['person_plural']) }} found.</p>
        </div>

        <div class="people-empty-card" id="people-no-results" hidden>
            <div class="people-empty-icon-wrap"><x-icons.search stroke="#9CA3AF" /></div>
            <div class="people-empty-text-wrap">
                <p class="people-empty-title">No Matching {{ $terminology['person_plural'] }}</p>
                <p class="people-empty-body" id="people-no-results-text"></p>
            </div>
            <button type="button" class="btn-primary" id="people-reset-search">Reset Search &amp; Filters</button>
        </div>

        <button type="button" class="load-more-btn" id="load-more-btn" hidden>Load more</button>
    </div>

    <script>
        window.MyoPeople = {
            organizationTimezone: @json($user->organization->timezone),
            terminology: @json($terminology),
            openAddSheet: @json($openAddSheet ?? false),
        };
    </script>

    <x-slot:scripts>
        <div class="sheet-overlay" id="add-person-overlay" hidden>
            <div class="sheet-panel" role="dialog" aria-label="Add {{ $terminology['person_singular'] }}">
                <div class="sheet-handle"></div>
                <p class="sheet-title">Add {{ $terminology['person_singular'] }}</p>
                <p class="form-error" id="add-person-error" role="alert"></p>
                <form id="add-person-form" novalidate>
                    <div class="form-section" style="display:flex;flex-direction:column;gap:16px;">
                        <x-auth.field>
                            <label class="field-label" for="ap-person-number">{{ $terminology['person_singular'] }} Number</label>
                            <x-auth.input-shell icon="tag">
                                <input type="text" id="ap-person-number" name="person_number" required>
                            </x-auth.input-shell>
                        </x-auth.field>
                        <x-auth.field>
                            <label class="field-label" for="ap-first-name">First Name</label>
                            <x-auth.input-shell icon="user">
                                <input type="text" id="ap-first-name" name="first_name" required>
                            </x-auth.input-shell>
                        </x-auth.field>
                        <x-auth.field>
                            <label class="field-label" for="ap-last-name">Last Name</label>
                            <x-auth.input-shell icon="user">
                                <input type="text" id="ap-last-name" name="last_name" required>
                            </x-auth.input-shell>
                        </x-auth.field>
                        <x-auth.field>
                            <label class="field-label" for="ap-contact-number">Contact Number</label>
                            <x-auth.input-shell icon="phone">
                                <input type="text" id="ap-contact-number" name="contact_number">
                            </x-auth.input-shell>
                        </x-auth.field>
                        @if ($user->organization->type !== 'school')
                        <x-auth.field>
                            <label class="field-label" for="ap-membership">Membership valid until (optional)</label>
                            <x-auth.input-shell icon="calendar">
                                <input type="date" id="ap-membership" name="membership_valid_until">
                            </x-auth.input-shell>
                        </x-auth.field>
                        @endif
                        <x-auth.field>
                            <label class="field-label" for="ap-group">{{ $terminology['group_singular'] }}</label>
                            <x-auth.input-shell icon="layers">
                                <select id="ap-group"><option value="">No {{ strtolower($terminology['group_singular']) }}</option></select>
                                <x-slot:trailing><span class="select-chevron"><x-icons.chevron-down /></span></x-slot:trailing>
                            </x-auth.input-shell>
                        </x-auth.field>
                        <x-auth.field>
                            <label class="field-label" for="ap-subgroup">{{ $terminology['subgroup_singular'] }}</label>
                            <x-auth.input-shell icon="folder">
                                <select id="ap-subgroup" disabled><option value="">Select a {{ strtolower($terminology['group_singular']) }} first</option></select>
                                <x-slot:trailing><span class="select-chevron"><x-icons.chevron-down /></span></x-slot:trailing>
                            </x-auth.input-shell>
                        </x-auth.field>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:12px;margin-top:20px;">
                        <button type="submit" class="btn-primary" id="add-person-submit">Add {{ $terminology['person_singular'] }}</button>
                        <button type="button" class="people-action" id="add-person-cancel">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="sheet-overlay" id="person-details-overlay" hidden>
            <div class="sheet-panel" role="dialog" aria-modal="true" aria-labelledby="pd-name">
                <div class="sheet-handle"></div>
                <div class="person-modal-header">
                    <p class="sheet-title">{{ $terminology['person_singular'] }} Details</p>
                    <button type="button" class="person-modal-close" id="pd-close-x" aria-label="Close details">
                        <x-icons.x />
                    </button>
                </div>
                <p class="form-error" id="pd-error" role="alert"></p>
                <div id="pd-body" style="display:flex;flex-direction:column;gap:16px;">
                    <div class="profile-summary-card">
                        <div class="profile-avatar" id="pd-initials"></div>
                        <div class="profile-summary-text">
                            <h1 id="pd-name"></h1>
                            <div class="profile-status-row">
                                <span id="pd-number"></span>
                                <span class="profile-status-dot"></span>
                                <span class="badge-pill" id="pd-status"></span>
                            </div>
                        </div>
                    </div>

                    <div class="details-card">
                        <div class="detail-row">
                            <p class="detail-row-label">{{ $terminology['group_singular'] }}</p>
                            <p class="detail-row-value" id="pd-group"></p>
                        </div>
                        <hr class="detail-divider">
                        <div class="detail-row">
                            <p class="detail-row-label">{{ $terminology['subgroup_singular'] }}</p>
                            <p class="detail-row-value" id="pd-subgroup"></p>
                        </div>
                        <hr class="detail-divider">
                        <div class="detail-row">
                            <p class="detail-row-label">Contact Number</p>
                            <p class="detail-row-value" id="pd-contact"></p>
                        </div>
                    </div>

                    <div class="detail-actions-row">
                        <a href="#" class="btn-detail-action" id="pd-edit">
                            <x-icons.settings />
                            <span>Edit Profile</span>
                        </a>
                        <button type="button" class="btn-detail-action btn-detail-action--primary" id="pd-view-qr">
                            <x-icons.qr-code stroke="#fff" />
                            <span>View QR Card</span>
                        </button>
                    </div>
                </div>
                <button type="button" class="people-action" id="pd-close" style="margin-top:16px;">Back to {{ $terminology['person_plural'] }} List</button>
            </div>
        </div>

        <div class="sheet-overlay" id="qr-overlay" hidden>
            <div class="sheet-panel" role="dialog" aria-modal="true" aria-label="QR Card">
                <div class="sheet-handle"></div>
                <p class="sheet-title" id="qr-title">QR Card</p>
                <img class="qr-image" id="qr-image" alt="QR code">
                <p class="qr-payload" id="qr-payload"></p>
                <div style="display:flex;flex-direction:column;gap:12px;margin-top:16px;">
                    <button type="button" class="people-action" id="qr-regenerate">Regenerate QR Code</button>
                    <button type="button" class="btn-primary" id="qr-close">Done</button>
                </div>
            </div>
        </div>

        @vite(['resources/js/people.js'])
    </x-slot:scripts>
</x-layouts.app>
