<x-layouts.app
    :user="$user"
    active-tab="more"
    :page-title="$terminology['group_plural'].' · '.config('app.name')"
>
    <div class="people-controls">
        <label class="search-shell">
            <x-icons.search />
            <input type="text" id="groups-search" placeholder="Search {{ strtolower($terminology['group_plural']) }}...">
        </label>

        <div class="people-actions-row">
            <a href="/groups/create" class="people-action people-action--primary">
                <x-icons.plus />
                <span>Add {{ $terminology['group_singular'] }}</span>
            </a>
        </div>
    </div>

    <div class="people-roster">
        <div id="groups-list"></div>

        <div class="empty-state" id="groups-empty" hidden>
            <p>No {{ strtolower($terminology['group_plural']) }} found.</p>
        </div>

        <button type="button" class="load-more-btn" id="load-more-btn" hidden>Load more</button>
    </div>

    <script>
        window.MyoGroups = {
            terminology: @json($terminology),
        };
    </script>

    <x-slot:scripts>
        @vite(['resources/js/groups.js'])
    </x-slot:scripts>
</x-layouts.app>
