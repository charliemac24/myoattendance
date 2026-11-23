<?php

namespace App\Http\Controllers;

use App\Services\AttendanceTimeline;
use App\Services\BuildPersonAttendanceHistory;
use App\Support\SafeCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonAttendanceController extends Controller
{
    private const STATUS_LABELS = [AttendanceTimeline::PRESENT => 'Present', AttendanceTimeline::LATE => 'Late', AttendanceTimeline::ABSENT => 'Absent', AttendanceTimeline::NOT_YET => 'Not yet', AttendanceTimeline::HOLIDAY => 'Holiday'];

    public function show(Request $request, int $person, BuildPersonAttendanceHistory $history): JsonResponse
    {
        return response()->json($this->history($request, $person, $history));
    }

    public function export(Request $request, int $person, BuildPersonAttendanceHistory $history): StreamedResponse
    {
        $data = $this->history($request, $person, $history);
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($data['person']['full_name'])) ?: 'person';

        return response()->streamDownload(function () use ($data): void {
            $output = fopen('php://output', 'wb');
            SafeCsv::put($output, ['ID Number', 'Full Name', 'Date', 'Day', 'Status', 'Time In', 'Time Out', 'Minutes Late', 'Note']);
            foreach ($data['days'] as $day) {
                $note = collect([$day['holiday'] ? 'Holiday: '.$day['holiday'] : null, $day['scheduled'] || $day['holiday'] ? null : 'Not a scheduled day', $day['no_time_in'] ? 'No time in' : null, $day['no_time_out'] ? 'No time out' : null])->filter()->implode('; ');
                SafeCsv::put($output, [$data['person']['person_number'], $data['person']['full_name'], $day['date'], $day['day'], self::STATUS_LABELS[$day['status']], $day['time_in'], $day['time_out'], $day['minutes_late'], $note]);
            }
            fclose($output);
        }, "attendance-{$data['person']['person_number']}-{$name}-{$data['from']}-to-{$data['to']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function history(Request $request, int $person, BuildPersonAttendanceHistory $history): array
    {
        $validated = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $organization = $request->user()->organization;
        $owned = $organization->persons()->findOrFail($person);
        [$from, $to] = BuildPersonAttendanceHistory::range($organization, $validated['from'] ?? null, $validated['to'] ?? null);
        if ($from->gt($to)) {
            throw ValidationException::withMessages(['from' => 'The start date must be on or before the end date.']);
        }
        if ($from->diffInDays($to) + 1 > BuildPersonAttendanceHistory::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Choose a range of one year or less.']);
        }

        return $history->handle($organization, $owned, $from, $to);
    }
}
