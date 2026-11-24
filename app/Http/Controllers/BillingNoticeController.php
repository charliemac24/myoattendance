<?php

namespace App\Http\Controllers;

use App\Services\BillingNoticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingNoticeController extends Controller
{
    /**
     * Hide one billing notice for the signed-in user.
     */
    public function dismiss(Request $request, string $key, BillingNoticeService $notices): JsonResponse
    {
        abort_unless(preg_match('/^(sms-price|payment-approved|payment-rejected)-\d+$/', $key) === 1, 404);
        $notices->dismiss($request->user(), $key);

        return response()->json(['notices' => $notices->forUser($request->user())]);
    }
}
