<?php

namespace App\Http\Controllers;

use App\Services\FeatureEntitlementService;
use App\Services\SmsTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsTemplateController extends Controller
{
    public function index(Request $request, FeatureEntitlementService $features, SmsTemplateService $templates): JsonResponse
    {
        $organization = $request->user()->organization;
        abort_unless($organization && $features->canUseSms($organization), 403, 'SMS access is not active for this organization.');

        return response()->json(['templates' => $templates->ensureDefaults($organization)]);
    }

    public function update(Request $request, string $template, FeatureEntitlementService $features, SmsTemplateService $templates): JsonResponse
    {
        $organization = $request->user()->organization;
        abort_unless($organization && $features->canUseSms($organization), 403, 'SMS access is not active for this organization.');
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403);
        abort_unless(array_key_exists($template, $templates->defaults()), 404);

        $data = $request->validate(['body' => ['required', 'string', 'max:1000'], 'enabled' => ['sometimes', 'boolean']]);
        $smsTemplate = $organization->smsTemplates()->where('template_key', $template)->firstOrFail();
        $smsTemplate->update(['body' => trim($data['body']), 'enabled' => $data['enabled'] ?? $smsTemplate->enabled]);

        return response()->json(['template' => $smsTemplate->fresh()]);
    }
}
