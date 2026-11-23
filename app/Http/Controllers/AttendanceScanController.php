<?php

namespace App\Http\Controllers;

use App\Services\BaseSubscriptionService;
use App\Services\ProcessAttendanceScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AttendanceScanController extends Controller
{
    public function store(Request $request, ProcessAttendanceScan $processor, BaseSubscriptionService $subscriptions): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'qr_payload' => ['required', 'string', 'max:128'],
            'scan_request_id' => ['nullable', 'uuid'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'code' => 'VALIDATION_ERROR',
                'message' => 'The scan request is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        if (! $subscriptions->canUseScanner($request->user()->organization)) {
            return response()->json(['success' => false, 'code' => 'SUBSCRIPTION_REQUIRED', 'message' => 'Your MYO Attendance subscription has expired. Renew it to continue scanning.'], 403);
        }

        return response()->json($processor->handle($request->user()->organization, $validated['qr_payload'], $validated['scan_request_id'] ?? null));
    }
}
