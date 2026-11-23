@php($mode = $mode ?? 'scheduled')
@php($scanOut = $scanOut ?? false)
{{-- How people in this group check in. "On a schedule" is today's behavior; "Anytime" is for gyms and open hours. --}}
<style>
    .mode-options { display: flex; flex-direction: column; gap: 8px; }
    .mode-option { display: flex; gap: 10px; align-items: flex-start; padding: 12px; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; cursor: pointer; }
    .mode-option:has(input:checked) { border-color: #2563eb; background: #eff6ff; }
    .mode-option input { margin-top: 3px; }
    .mode-option strong { display: block; font-size: 14px; color: #111827; }
    .mode-option span { font-size: 12px; color: #6b7280; line-height: 1.4; }
    .mode-scan-out { display: flex; gap: 8px; align-items: center; margin-top: 4px; font-size: 13px; color: #374151; }
</style>
<div class="plain-field" style="margin-top:20px;">
    <p class="plain-field-label">How do {{ strtolower($terminology['person_plural']) }} check in?</p>
    <div class="mode-options">
        <label class="mode-option">
            <input type="radio" name="attendance_mode" value="scheduled" @checked($mode !== 'open')>
            <div><strong>On a schedule</strong><span>For classes, school days and shifts. Uses Schedules to mark Present, Late and Absent.</span></div>
        </label>
        <label class="mode-option">
            <input type="radio" name="attendance_mode" value="open" @checked($mode === 'open')>
            <div><strong>Anytime (open hours)</strong><span>For gyms and walk-ins. Scan in any day, any time, as often as they come. Visits are counted; nobody is marked late or absent.</span></div>
        </label>
    </div>
    <label class="mode-scan-out" id="mode-scan-out" @if ($mode !== 'open') hidden @endif>
        <input type="checkbox" name="scan_out" value="1" @checked($scanOut)>
        Also scan when they leave
    </label>
</div>
<script>
    (function () {
        const scanOut = document.getElementById('mode-scan-out');
        document.querySelectorAll('input[name="attendance_mode"]').forEach((radio) => radio.addEventListener('change', () => {
            scanOut.hidden = document.querySelector('input[name="attendance_mode"]:checked').value !== 'open';
        }));
        window.MyoGroupMode = () => ({
            attendance_mode: document.querySelector('input[name="attendance_mode"]:checked').value,
            scan_out: document.querySelector('input[name="scan_out"]').checked,
        });
    })();
</script>
