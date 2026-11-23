<x-layouts.app
    :user="$user"
    active-tab="attendance"
    :page-title="'Needs Attention · '.config('app.name')"
>
    <div class="attention-page">
        <div class="attention-controls">
            <p class="attention-intro">{{ $terminology['person_plural'] }} who may have stopped coming: not seen for a while, absent on their last 3 scheduled days in a row, or attending less than 75% of their scheduled days in the last 30 days.</p>
            <label class="plain-field">
                <span class="plain-field-label">Not seen in</span>
                <select id="attention-days" class="attention-select">
                    <option value="3">3 days or more</option>
                    <option value="7" selected>7 days or more</option>
                    <option value="14">14 days or more</option>
                    <option value="30">30 days or more</option>
                </select>
            </label>
            <button type="button" class="btn-download" id="attention-download">
                <x-icons.download />
                <span>Export CSV</span>
            </button>
        </div>

        <p class="form-error" id="attention-error" role="alert"></p>
        <p class="attention-count" id="attention-count" aria-live="polite"></p>
        <div class="attention-list" id="attention-list"></div>
        <p class="attention-empty" id="attention-empty" hidden>No one needs attention right now.</p>
    </div>

    <script>
        window.MyoNeedsAttention = { groupLabel: @json($terminology['group_singular']), personSingular: @json(strtolower($terminology['person_singular'])), personPlural: @json(strtolower($terminology['person_plural'])) };
    </script>

    <x-slot:scripts>
        @vite(['resources/js/needs-attention.js'])
    </x-slot:scripts>
</x-layouts.app>
