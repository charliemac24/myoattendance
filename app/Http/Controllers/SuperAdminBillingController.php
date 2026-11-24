<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\OrganizationFeatureRequest;
use App\Models\Payment;
use App\Services\BaseSubscriptionService;
use App\Services\BillingService;
use App\Services\PaymentActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SuperAdminBillingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::with(['organization:id,name', 'submittedBy:id,name,email', 'reviewedBy:id,name', 'recordedBy:id,name', 'bill.items'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($query) => $query->where('reference_number', 'like', '%'.$request->input('search').'%')->orWhereHas('organization', fn ($organization) => $organization->where('name', 'like', '%'.$request->input('search').'%'))))
            ->latest()->latest('id')->paginate(min($request->integer('per_page', 30), 100))->withQueryString();
        $payments->getCollection()->each(fn (Payment $payment) => $payment->setAttribute('activates', $activates = $payment->activates())->setAttribute('activates_label', Payment::activatesLabel($activates)));

        return response()->json($payments);
    }

    public function show(Payment $payment, BaseSubscriptionService $subscriptions, BillingService $billing): JsonResponse
    {
        $payment->load(['organization.baseSubscription', 'organization.featureEntitlements', 'submittedBy:id,name,email', 'reviewedBy:id,name', 'proofRequestedBy:id,name', 'proofs.submittedBy:id,name', 'bill.items', 'bill.payments' => fn ($query) => $query->latest()]);
        $smsRequest = $payment->organization->featureRequests()->where('feature', OrganizationFeatureRequest::FEATURE_SMS)->latest('requested_at')->first();

        return response()->json([
            'payment' => $payment,
            'activates' => $activates = $payment->activates(),
            'activates_label' => Payment::activatesLabel($activates),
            'expected_base_amount_minor' => BaseSubscriptionService::MONTHLY_PRICE_MINOR,
            'subscription' => $subscriptions->payload($payment->organization),
            'current_plan' => $currentPlan = $billing->currentPlan($payment->organization),
            'plan_change' => $payment->bill ? $billing->describeChange($currentPlan, $payment->bill, forAdmin: true) : null,
            'sms_activation_request' => $smsRequest ? ['status' => $smsRequest->status, 'activation_mode' => $smsRequest->activation_mode, 'quote_sent_at' => $smsRequest->quote_sent_at] : null,
            'sms_price_set' => $payment->organization->smsPricingAgreements()->where('status', 'active')->exists(),
            'sms_price_source' => $payment->organization->smsPricingAgreements()->where('status', 'active')->latest('effective_from')->value('source'),
            'sms_estimate' => SuperAdminOrganizationController::estimateAnswers($payment->organization),
        ]);
    }

    public function approve(Request $request, Payment $payment, PaymentActivationService $activation): JsonResponse
    {
        $payment = $activation->activate($payment, $request->user());

        return response()->json(['payment' => $payment, 'activated' => $activated = $payment->activates(), 'activated_label' => Payment::activatesLabel($activated)]);
    }

    public function reject(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $payment = DB::transaction(function () use ($request, $payment, $data): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROOF_REQUIRED], true)) {
                abort(409, 'This payment has already been reviewed.');
            }
            $payment->update(['status' => Payment::STATUS_REJECTED, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'rejection_reason' => $data['reason']]);
            if ($payment->bill_id) {
                $bill = Bill::query()->lockForUpdate()->findOrFail($payment->bill_id);
                if ($bill->status === Bill::STATUS_PAYMENT_PENDING) {
                    $bill->update(['status' => Bill::STATUS_UNPAID]);
                }
            }

            return $payment->fresh(['organization', 'submittedBy', 'reviewedBy', 'bill.items']);
        });

        return response()->json(['payment' => $payment]);
    }

    public function requestProof(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $payment = DB::transaction(function () use ($request, $payment, $data): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless($payment->status === Payment::STATUS_PENDING, 409, 'Only pending payments can be sent back for proof correction.');
            $payment->update(['status' => Payment::STATUS_PROOF_REQUIRED, 'proof_request_reason' => $data['reason'], 'proof_requested_by' => $request->user()->id, 'proof_requested_at' => now(), 'proof_unavailable' => false]);

            return $payment->fresh(['organization', 'submittedBy', 'proofRequestedBy', 'proofs', 'bill.items']);
        });

        return response()->json(['payment' => $payment]);
    }

    public function proof(Payment $payment)
    {
        abort_unless($payment->proof_path, 404);

        return Storage::download($payment->proof_path);
    }

    public function proofPreview(Payment $payment)
    {
        abort_unless($payment->proof_path, 404);

        return Storage::response($payment->proof_path);
    }
}
