<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationFeatureRequest;
use App\Models\Payment;
use App\Models\SmsPricingAgreement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SmsAccessRequestService
{
    /**
     * Request SMS, or (for a school still on its trial) change when an existing request should start.
     * A new request needs the school's answers (people, texts, days) so MYO Attendance can work out its price.
     *
     * @param  array{people: int, texts: list<string>, days: string}|null  $answers
     */
    public function request(Organization $organization, User $actor, ?string $activationMode = null, ?array $answers = null): OrganizationFeatureRequest
    {
        return DB::transaction(function () use ($organization, $actor, $activationMode, $answers): OrganizationFeatureRequest {
            $organization = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $pending = $organization->featureRequests()->where('feature', OrganizationFeatureRequest::FEATURE_SMS)
                ->where('status', OrganizationFeatureRequest::STATUS_PENDING)->lockForUpdate()->first();
            if ($pending) {
                // Trial schools may change their start choice until SMS is paid for and approved.
                $inTrial = app(BillingService::class)->currentPlan($organization)['in_trial'];
                if ($inTrial && $activationMode && $activationMode !== $pending->activation_mode) {
                    app(BillingService::class)->setTrialStartChoice($organization, $activationMode);
                    $pending->refresh();
                }

                return $pending;
            }

            if (app(FeatureEntitlementService::class)->canUseSms($organization)) {
                throw new \DomainException('SMS is already active for this organization.');
            }
            // An expired school may ask for its SMS price too: it then renews with Base + SMS in one payment.
            $currentPlan = app(BillingService::class)->currentPlan($organization);
            // Any price can be asked to change, including one MYO Attendance set (e.g. the school now has more people).
            // Until the new price is set, Base + SMS keeps using the current one.
            $this->saveAnswers($organization, $actor, $answers);
            // Only schools still on their free trial choose when their paid plan starts; the request follows that choice.
            if (! $currentPlan['in_trial']) {
                $activationMode = OrganizationFeatureRequest::ACTIVATION_NEXT_PAYMENT;
            } elseif ($activationMode && $activationMode !== OrganizationFeatureRequest::ACTIVATION_NEXT_PAYMENT) {
                app(BillingService::class)->setTrialStartChoice($organization, $activationMode);
            } else {
                $activationMode = $currentPlan['start_choice'] ?? OrganizationFeatureRequest::ACTIVATION_AFTER_TRIAL;
            }

            return $organization->featureRequests()->create([
                'feature' => OrganizationFeatureRequest::FEATURE_SMS,
                'status' => OrganizationFeatureRequest::STATUS_PENDING,
                'requested_by' => $actor->id,
                'requested_at' => now(),
                'activation_mode' => $activationMode,
            ]);
        });
    }

    /**
     * Keep the answers that come with a custom price request. A price estimated from older answers follows the new ones;
     * otherwise the price stays unset until MYO Attendance sets it.
     *
     * @param  array{people: int, texts: list<string>, days: string}|null  $answers
     */
    private function saveAnswers(Organization $organization, User $actor, ?array $answers): void
    {
        if ($answers !== null) {
            $estimator = app(SmsPriceEstimator::class);
            $source = $organization->smsPricingAgreements()->where('status', SmsPricingAgreement::STATUS_ACTIVE)->latest('effective_from')->value('source');
            $source === SmsPricingAgreement::SOURCE_ESTIMATE
                ? $estimator->saveAnswers($organization, $actor, $answers['people'], $answers['texts'], $answers['days'])
                : $estimator->storeAnswers($organization, $answers['people'], $answers['texts'], $answers['days']);
        }

        if (! SmsPriceEstimator::hasAnswers($organization)) {
            throw ValidationException::withMessages(['people' => 'Tell us how many people will receive SMS, which texts, and which days, so we can work out your price.']);
        }
    }

    public function approve(OrganizationFeatureRequest $request, User $actor, array $data): OrganizationFeatureRequest
    {
        return DB::transaction(function () use ($request, $actor, $data): OrganizationFeatureRequest {
            $request = OrganizationFeatureRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->feature !== OrganizationFeatureRequest::FEATURE_SMS || $request->status !== OrganizationFeatureRequest::STATUS_PENDING) {
                throw new \DomainException('This SMS request has already been reviewed.');
            }

            $payment = $request->organization->payments()->create([
                'amount_minor' => $data['amount_minor'],
                'currency' => 'PHP',
                'payment_method' => Payment::METHOD_GCASH,
                'reference_number' => $data['reference_number'] ?? null,
                'paid_at' => $data['paid_at'],
                'coverage_start' => $data['coverage_start'] ?? null,
                'coverage_end' => $data['coverage_end'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            app(FeatureEntitlementService::class)->enableSms(
                $request->organization,
                $actor,
                $data['starts_at'] ?? null,
                $data['expires_at'] ?? null,
            );

            $request->update(['status' => OrganizationFeatureRequest::STATUS_APPROVED, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);

            return $request->fresh(['organization', 'requestedBy', 'reviewedBy']);
        });
    }

    public function reject(OrganizationFeatureRequest $request, User $actor, string $reason): OrganizationFeatureRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason): OrganizationFeatureRequest {
            $request = OrganizationFeatureRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->feature !== OrganizationFeatureRequest::FEATURE_SMS || $request->status !== OrganizationFeatureRequest::STATUS_PENDING) {
                throw new \DomainException('This SMS request has already been reviewed.');
            }
            $request->update(['status' => OrganizationFeatureRequest::STATUS_REJECTED, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'notes' => $reason]);

            return $request->fresh(['organization', 'requestedBy', 'reviewedBy']);
        });
    }
}
