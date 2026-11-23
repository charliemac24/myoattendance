<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SmsPricingAgreement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SmsPricingService
{
    public function __construct(private readonly BillingService $billing) {}

    /**
     * Set a school's monthly SMS price from a date (today by default). The previous price stays in
     * history and keeps applying to bills that start before that date. Paid bills and bills with a
     * payment under review are never repriced.
     */
    public function setPrice(Organization $organization, User $actor, int $priceMinor, ?string $effectiveFrom = null, ?string $notes = null, string $source = SmsPricingAgreement::SOURCE_ADMIN): SmsPricingAgreement
    {
        $effectiveFrom = Carbon::parse($effectiveFrom ?? now($organization->timezone ?: config('app.timezone'))->toDateString())->toDateString();

        $agreement = DB::transaction(function () use ($organization, $actor, $priceMinor, $effectiveFrom, $notes, $source): SmsPricingAgreement {
            $organization->smsPricingAgreements()->where('status', SmsPricingAgreement::STATUS_ACTIVE)->lockForUpdate()->get()
                ->each(fn (SmsPricingAgreement $previous) => $previous->update([
                    'status' => SmsPricingAgreement::STATUS_SUPERSEDED,
                    'effective_until' => Carbon::parse($effectiveFrom)->subDay()->toDateString(),
                ]));

            return $organization->smsPricingAgreements()->create([
                'sms_monthly_price_minor' => $priceMinor,
                'currency' => 'PHP',
                'person_count_snapshot' => $organization->persons()->where('is_active', true)->count(),
                'effective_from' => $effectiveFrom,
                'status' => SmsPricingAgreement::STATUS_ACTIVE,
                'source' => $source,
                'created_by' => $actor->id,
                'agreed_at' => now(),
                'notes' => $notes,
            ]);
        });

        // Refresh any unpaid bill so the school sees the right amount right away.
        $this->billing->currentBill($organization);

        return $agreement;
    }

    /**
     * Every price the school has had, newest first.
     *
     * @return Collection<int, SmsPricingAgreement>
     */
    public function history(Organization $organization): Collection
    {
        return $organization->smsPricingAgreements()->with('createdBy:id,name')
            ->whereIn('status', [SmsPricingAgreement::STATUS_ACTIVE, SmsPricingAgreement::STATUS_SUPERSEDED])
            ->latest('effective_from')->latest('id')->get();
    }
}
