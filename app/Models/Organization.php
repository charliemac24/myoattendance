<?php

namespace App\Models;

use App\Support\OrganizationTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    protected $fillable = ['name', 'type', 'timezone', 'sms_sender_name', 'terminology', 'sms_estimate_people', 'sms_estimate_texts', 'sms_estimate_days'];

    /** Employers (company, construction, warehouse, staffing) track hours worked and get the Timesheet. */
    public function tracksWorkHours(): bool
    {
        return in_array($this->type, OrganizationTypes::WORKFORCE, true);
    }

    /** How a new group checks in unless told otherwise: gyms and studios default to open hours. */
    public function defaultGroupMode(): string
    {
        return in_array($this->type, OrganizationTypes::OPEN_HOURS_BY_DEFAULT, true) ? Group::MODE_OPEN : Group::MODE_SCHEDULED;
    }

    protected function casts(): array
    {
        return ['terminology' => 'array', 'sms_estimate_people' => 'integer', 'sms_estimate_texts' => 'array'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->where('role', 'owner');
    }

    public function featureEntitlements(): HasMany
    {
        return $this->hasMany(FeatureEntitlement::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function baseSubscription(): HasOne
    {
        return $this->hasOne(BaseSubscription::class);
    }

    public function smsPricingAgreements(): HasMany
    {
        return $this->hasMany(SmsPricingAgreement::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function featureRequests(): HasMany
    {
        return $this->hasMany(OrganizationFeatureRequest::class);
    }

    public function smsTemplates(): HasMany
    {
        return $this->hasMany(SmsTemplate::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function subgroups(): HasMany
    {
        return $this->hasMany(Subgroup::class);
    }

    public function persons(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    public function scanIdempotencies(): HasMany
    {
        return $this->hasMany(AttendanceScanIdempotency::class);
    }

    public function smsMessages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }
}
