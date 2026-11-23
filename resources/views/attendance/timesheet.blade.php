<x-layouts.app
    :user="$user"
    active-tab="attendance"
    :page-title="'Timesheet · '.config('app.name')"
>
    <x-slot:head>
        <style>
            .ts-page { display: flex; flex-direction: column; gap: 14px; padding: 16px; width: 100%; max-width: 960px; }
            .ts-intro { margin: 0; font-size: 13px; color: #4b5563; line-height: 1.45; }
            .ts-presets { display: flex; gap: 6px; flex-wrap: wrap; }
            .ts-range { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
            .ts-range .plain-field, .ts-group { display: flex; flex-direction: column; gap: 4px; }
            .ts-input { width: 100%; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font: inherit; font-size: 14px; background: #fff; }
            .ts-downloads { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
            .ts-list { display: flex; flex-direction: column; gap: 10px; }
            .ts-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
            .ts-card-head { width: 100%; display: flex; flex-direction: column; gap: 8px; padding: 12px 14px; border: 0; background: none; text-align: left; font: inherit; cursor: pointer; }
            .ts-name { margin: 0; font-size: 15px; font-weight: 700; color: #111827; }
            .ts-sub { margin: 0; font-size: 12px; color: #6b7280; }
            .ts-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
            .ts-stat { padding: 6px 8px; border-radius: 8px; background: #f9fafb; }
            .ts-stat b { display: block; font-size: 14px; color: #111827; }
            .ts-stat span { font-size: 11px; color: #6b7280; }
            .ts-stat--warn b { color: #b45309; }
            .ts-stat--bad b { color: #dc2626; }
            .ts-days { display: none; border-top: 1px solid #f3f4f6; }
            .ts-card.is-open .ts-days { display: block; }
            .ts-day { display: flex; justify-content: space-between; gap: 10px; padding: 9px 14px; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
            .ts-day:last-child { border-bottom: 0; }
            .ts-day p { margin: 0; }
            .ts-day-meta { color: #6b7280; font-size: 12px; margin-top: 2px !important; }
            .ts-day-hours { text-align: right; white-space: nowrap; font-weight: 700; color: #111827; }
            .ts-empty { margin: 0; text-align: center; color: #6b7280; font-size: 14px; padding: 24px 0; }
            @media (max-width: 480px) { .ts-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        </style>
    </x-slot:head>

    <div class="ts-page">
        <p class="ts-intro">Hours worked for payroll. Regular hours count inside the shift, minus the unpaid break. Overtime counts after the shift ends once it reaches 30 minutes. Days with a missing scan show as Incomplete and are not counted until the scans are fixed.</p>

        <div class="ts-presets" role="group" aria-label="Pay period">
            <button type="button" class="history-preset" data-preset="first">1st – 15th</button>
            <button type="button" class="history-preset" data-preset="second">16th – end</button>
            <button type="button" class="history-preset" data-preset="month">Whole month</button>
        </div>
        <div class="ts-range">
            <label class="plain-field"><span class="plain-field-label">From</span><input type="date" id="ts-from" class="ts-input"></label>
            <label class="plain-field"><span class="plain-field-label">To</span><input type="date" id="ts-to" class="ts-input"></label>
        </div>
        <label class="ts-group">
            <span class="plain-field-label">{{ $terminology['group_singular'] }}</span>
            <select id="ts-group" class="ts-input"><option value="">All {{ strtolower($terminology['group_plural']) }}</option></select>
        </label>
        <div class="ts-downloads">
            <button type="button" class="btn-download" data-format="daily"><x-icons.download /><span>Daily CSV</span></button>
            <button type="button" class="btn-download" data-format="summary"><x-icons.download /><span>Summary CSV</span></button>
        </div>

        <p class="form-error" id="ts-error" role="alert"></p>
        <p class="ts-sub" id="ts-count" aria-live="polite"></p>
        <div class="ts-list" id="ts-list"></div>
        <p class="ts-empty" id="ts-empty" hidden>No {{ strtolower($terminology['person_plural']) }} with shifts in this range.</p>
    </div>

    <x-slot:scripts>
        @vite(['resources/js/timesheet.js'])
    </x-slot:scripts>
</x-layouts.app>
