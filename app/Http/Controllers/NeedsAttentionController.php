<?php

namespace App\Http\Controllers;

use App\Services\BuildNeedsAttentionReport;
use App\Support\OrganizationTerminology;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NeedsAttentionController extends Controller
{
    public function show(Request $request, BuildNeedsAttentionReport $report): JsonResponse
    {
        return response()->json($report->handle($request->user()->organization, $this->notSeenDays($request)));
    }

    public function export(Request $request, BuildNeedsAttentionReport $report): StreamedResponse
    {
        $data = $report->handle($request->user()->organization, $this->notSeenDays($request));
        $organization = $request->user()->organization;
        $date = now($organization->timezone)->toDateString();
        $terminology = OrganizationTerminology::forOrganization($organization);

        return response()->streamDownload(function () use ($data, $terminology): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['ID Number', 'Full Name', $terminology['group_singular'], $terminology['subgroup_singular'], 'Contact Number', 'Last Seen', 'Days Since Last Seen', 'Absences In A Row', 'Attendance Rate (Last 30 Days)', 'Reasons']);
            foreach ($data['people'] as $person) {
                fputcsv($output, [
                    $person['person_number'],
                    $person['full_name'],
                    $person['group'],
                    $person['subgroup'],
                    $person['contact_number'],
                    $person['last_seen'] ?? 'Never',
                    $person['days_since_last_seen'],
                    $person['absence_streak'],
                    $person['attendance_rate'] === null ? '' : $person['attendance_rate'].'%',
                    implode('; ', array_column($person['reasons'], 'label')),
                ]);
            }
            fclose($output);
        }, "needs-attention-{$date}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function notSeenDays(Request $request): int
    {
        $validated = $request->validate(['not_seen_days' => ['nullable', 'integer', Rule::in(BuildNeedsAttentionReport::NOT_SEEN_OPTIONS)]]);

        return (int) ($validated['not_seen_days'] ?? BuildNeedsAttentionReport::DEFAULT_NOT_SEEN_DAYS);
    }
}
