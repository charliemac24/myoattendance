<?php

namespace App\Http\Controllers;

use App\Services\BuildDailyAttendanceReport;
use App\Services\BuildMonthlyAttendanceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportController extends Controller
{
    public function show(Request $request, BuildDailyAttendanceReport $report): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['PRESENT', 'LATE', 'ABSENT'])],
            'flag' => ['nullable', Rule::in(['NO_TIME_IN', 'NO_TIME_OUT'])],
            'group_id' => ['nullable', 'integer'],
            'subgroup_id' => ['nullable', 'integer'],
        ]);

        return response()->json($report->handle($request->user()->organization, $validated['date'], $validated));
    }

    public function monthly(Request $request, BuildMonthlyAttendanceReport $report): JsonResponse
    {
        $validated = $this->monthlyValidation($request);

        return response()->json($report->handle($request->user()->organization, $validated['month'], $validated['group_id'], $validated['subgroup_id'] ?? null));
    }

    public function monthlyExport(Request $request, BuildMonthlyAttendanceReport $report): StreamedResponse
    {
        $validated = $this->monthlyValidation($request);
        $data = $report->handle($request->user()->organization, $validated['month'], $validated['group_id'], $validated['subgroup_id'] ?? null);
        $group = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($data['group']['name'])) ?: 'group';
        $subgroup = $data['subgroup'] ? '-'.(preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($data['subgroup']['name'])) ?: 'subgroup') : '';

        return response()->streamDownload(function () use ($data): void {
            $output = fopen('php://output', 'wb');
            $visitsColumn = $data['open_hours'] ? ['Total Visits'] : [];
            fputcsv($output, ['ID Number', 'Full Name', ...array_column($data['dates'], 'label'), ...$visitsColumn]);
            foreach ($data['persons'] as $person) {
                $visits = $data['open_hours'] ? [$person['total_visits']] : [];
                fputcsv($output, [$person['person_number'], $person['full_name'], ...array_values($person['attendance']), ...$visits]);
            }
            fclose($output);
        }, "monthly-attendance-{$data['month']}-{$group}{$subgroup}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function monthlyValidation(Request $request): array
    {
        $validated = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'group_id' => ['required', 'integer'], 'subgroup_id' => ['nullable', 'integer']]);
        $organization = $request->user()->organization;
        $group = $organization->groups()->findOrFail($validated['group_id']);
        if (isset($validated['subgroup_id'])) {
            $organization->subgroups()->where('group_id', $group->id)->findOrFail($validated['subgroup_id']);
        }

        return $validated;
    }
}
