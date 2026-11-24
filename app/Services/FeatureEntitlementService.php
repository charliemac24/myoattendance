<?php

namespace App\Services;

use App\Models\FeatureEntitlement;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FeatureEntitlementService
{
    public function canUseSms(Organization $organization, ?Carbon $today = null): bool
    {
        return $this->canUse($organization, FeatureEntitlement::SMS, $today);
    }

    public function canUse(Organization $organization, string $feature, ?Carbon $today = null): bool
    {
        $entitlement = $organization->featureEntitlements()->where('feature', $feature)->first();
        $date = ($today ?? now())->toDateString();

        return $entitlement !== null
            && $entitlement->enabled
            && ($entitlement->starts_at === null || $entitlement->starts_at->toDateString() <= $date)
            && ($entitlement->expires_at === null || $entitlement->expires_at->toDateString() >= $date);
    }

    public function enableSms(Organization $organization, User $actor, ?string $startsAt, ?string $expiresAt): FeatureEntitlement
    {
        return DB::transaction(fn () => $organization->featureEntitlements()->updateOrCreate(
            ['feature' => FeatureEntitlement::SMS],
            ['enabled' => true, 'starts_at' => $startsAt, 'expires_at' => $expiresAt, 'enabled_by' => $actor->id, 'disabled_at' => null, 'disabled_by' => null],
        ));
    }

    /**
     * Turn SMS on for a paid Base + SMS period. SMS always follows the Base period it was bought
     * with; access that is already active or scheduled keeps its start date and is only ever
     * lengthened, never shortened. Dates are inclusive.
     */
    public function coverSmsThrough(Organization $organization, User $actor, Carbon $start, Carbon $end): FeatureEntitlement
    {
        return DB::transaction(function () use ($organization, $actor, $start, $end): FeatureEntitlement {
            $entitlement = $organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->lockForUpdate()->first();
            $today = now()->toDateString();
            $hasCurrentCoverage = $entitlement?->enabled && $entitlement->expires_at && $entitlement->expires_at->toDateString() >= $today;
            $expiresAt = $hasCurrentCoverage && $entitlement->expires_at->gt($end) ? $entitlement->expires_at : $end;

            return $organization->featureEntitlements()->updateOrCreate(
                ['feature' => FeatureEntitlement::SMS],
                ['enabled' => true, 'starts_at' => $hasCurrentCoverage ? $entitlement->starts_at?->toDateString() : $start->toDateString(), 'expires_at' => $expiresAt->toDateString(), 'enabled_by' => $actor->id, 'disabled_at' => null, 'disabled_by' => null],
            );
        });
    }

    public function disableSms(Organization $organization, User $actor): FeatureEntitlement
    {
        return DB::transaction(fn () => tap(
            $organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->firstOrFail(),
            fn (FeatureEntitlement $entitlement) => $entitlement->update(['enabled' => false, 'disabled_at' => now(), 'disabled_by' => $actor->id]),
        ));
    }

    /**
     * The last day of SMS the organization already paid for, while SMS is turned off by an admin and that day has not passed.
     */
    public function resumableSmsUntil(Organization $organization): ?Carbon
    {
        $entitlement = $organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->first();

        return $entitlement && ! $entitlement->enabled && $entitlement->expires_at && $entitlement->expires_at->toDateString() >= now()->toDateString()
            ? Carbon::parse($entitlement->expires_at)
            : null;
    }

    /**
     * Turns SMS back on after an admin turned it off, until the end date already paid for. No new payment.
     */
    public function resumeSms(Organization $organization, User $actor): FeatureEntitlement
    {
        abort_unless($this->resumableSmsUntil($organization), 409, 'There is no paid SMS time left to turn back on. Ask the school to pay for SMS, then press Activate on that payment.');

        return DB::transaction(fn () => tap(
            $organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->lockForUpdate()->firstOrFail(),
            fn (FeatureEntitlement $entitlement) => $entitlement->update(['enabled' => true, 'enabled_by' => $actor->id, 'disabled_at' => null, 'disabled_by' => null]),
        ));
    }

    public function extendSms(Organization $organization, User $actor, string $expiresAt): FeatureEntitlement
    {
        return DB::transaction(fn () => tap(
            $organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->firstOrFail(),
            fn (FeatureEntitlement $entitlement) => $entitlement->update(['enabled' => true, 'expires_at' => $expiresAt, 'enabled_by' => $actor->id, 'disabled_at' => null, 'disabled_by' => null]),
        ));
    }
}
