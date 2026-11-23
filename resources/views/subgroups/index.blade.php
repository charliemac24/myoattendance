<x-layouts.app
    :user="$user"
    active-tab="more"
    :page-title="$terminology['subgroup_plural'].' · '.config('app.name')"
>
    <div class="people-controls">
        <div class="parent-filter">
            <div class="parent-filter-left">
                <span class="parent-filter-label">Parent {{ $terminology['group_singular'] }}:</span>
                <select id="filter-group">
                    <option value="">All {{ strtolower($terminology['group_plural']) }}</option>
                </select>
            </div>
            <span class="select-chevron"><x-icons.chevron-down stroke="#4B5563" /></span>
        </div>

        <label class="search-shell">
            <x-icons.search />
            <input type="text" id="subgroups-search" placeholder="Search {{ strtolower($terminology['subgroup_plural']) }}...">
        </label>

        <div class="people-actions-row">
            <a href="/subgroups/create" class="people-action people-action--primary" id="add-subgroup-btn">
                <x-icons.plus />
                <span>Add {{ $terminology['subgroup_singular'] }}</span>
            </a>
        </div>
    </div>

    <div class="people-roster">
        <div id="subgroups-list"></div>

        <div class="empty-state" id="subgroups-empty" hidden>
            <p>No {{ strtolower($terminology['subgroup_plural']) }} found.</p>
        </div>

        <button type="button" class="load-more-btn" id="load-more-btn" hidden>Load more</button>
    </div>

    <script>
        window.MyoSubgroups = {
            terminology: @json($terminology),
        };
    </script>

    <x-slot:scripts>
        @vite(['resources/js/subgroups.js'])
    </x-slot:scripts>
</x-layouts.app>
