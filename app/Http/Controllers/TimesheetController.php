<?php

namespace App\Http\Controllers;

use App\Services\BuildTimesheet;
use App\Support\OrganizationTerminology;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TimesheetController extends Controller
{
    public function show(Request $request, BuildTimesheet $timesheet): JsonResponse
    {
        return response()->json($this->timesheet($request, $timesheet));
    }

    /** CSV for payroll: one row per employee per day ("daily", the default), or one row per employee ("summary"). */
    public function export(Request $request, BuildTimesheet $timesheet): StreamedResponse
    {
        $format = $request->validate(['format' => ['nullable', Rule::in(['daily', 'summary'])]])['format'] ?? 'daily';
        $data = $this->timesheet($request, $timesheet);
        $terminology = OrganizationTerminology::forOrganization($request->user()->organization);
        $hours = static fn (int $minutes): string => number_format($minutes / 60, 2, '.', '');

        return response()->streamDownload(function () use ($data, $format, $terminology, $hours): void {
            $output = fopen('php://output', 'wb');
            if ($format === 'summary') {
                fputcsv($output, ['ID Number', 'Full Name', $terminology['group_singular'], $terminology['subgroup_singular'], 'Days Worked', 'Absences', 'Late Days', 'Incomplete Days', 'Regular Hours', 'Late Minutes', 'Undertime Minutes', 'Overtime Hours', 'Rest Day Hours']);
                foreach ($data['people'] as $row) {
                    $totals = $row['totals'];
                    fputcsv($output, [$row['person']['person_number'], $row['person']['full_name'], $row['person']['group'], $row['person']['subgroup'], $totals['days_worked'], $totals['absences'], $totals['late_days'], $totals['incomplete_days'], $hours($totals['regular_minutes']), $totals['late_minutes'], $totals['undertime_minutes'], $hours($totals['overtime_minutes']), $hours($totals['rest_day_minutes'])]);
                }
            } else {
                fputcsv($output, ['ID Number', 'Full Name', 'Date', 'Day', 'Shift', 'Time In', 'Time Out', 'Status', 'Regular Hours', 'Late Minutes', 'Undertime Minutes', 'Overtime Hours', 'Rest Day Hours', 'Unpaid Break Minutes', 'Note']);
                foreach ($data['people'] as $row) {
                    foreach ($row['days'] as $day) {
                        $note = collect([$day['holiday'] ? 'Holiday: '.$day['holiday'] : null, $day['no_time_in'] ? 'No time in' : null, $day['no_time_out'] ? 'No time out' : null, $day['incomplete'] ? 'Hours not counted until the scans are fixed' : null])->filter()->implode('; ');
                        fputcsv($output, [$row['person']['person_number'], $row['person']['full_name'], $day['date'], $day['day'], $day['shift'], $day['time_in'], $day['time_out'], $day['status'], $hours($day['regular_minutes']), $day['late_minutes'], $day['undertime_minutes'], $hours($day['overtime_minutes']), $hours($day['rest_day_minutes']), $day['break_minutes'], $note]);
                    }
                }
            }
            fclose($output);
        }, "timesheet-{$format}-{$data['from']}-to-{$data['to']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function timesheet(Request $request, BuildTimesheet $timesheet): array
    {
        $validated = $request->validate(['from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'], 'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from'], 'group_id' => ['nullable', 'integer'], 'subgroup_id' => ['nullable', 'integer'], 'person_id' => ['nullable', 'integer']]);
        $organization = $request->user()->organization;
        [$from, $to] = BuildTimesheet::range($organization, $validated['from'] ?? null, $validated['to'] ?? null);
        if ($from->gt($to)) {
            throw ValidationException::withMessages(['from' => 'The start date must be on or before the end date.']);
        }
        if ($from->diffInDays($to) + 1 > BuildTimesheet::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Choose a range of '.BuildTimesheet::MAX_DAYS.' days or less.']);
        }
        $groupId = isset($validated['group_id']) ? $organization->groups()->findOrFail($validated['group_id'])->id : null;
        $subgroupId = isset($validated['subgroup_id']) ? $organization->subgroups()->findOrFail($validated['subgroup_id'])->id : null;
        $personId = isset($validated['person_id']) ? $organization->persons()->findOrFail($validated['person_id'])->id : null;

        return $timesheet->handle($organization, $from, $to, $groupId, $subgroupId, $personId);
    }
}
