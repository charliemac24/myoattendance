<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    public const METHOD_GCASH = 'gcash';

    /** Cash handed to the admin and recorded in super admin; activated in the same step. */
    public const METHOD_CASH = 'cash';

    public const TYPE_BASE_SUBSCRIPTION = 'base_subscription';

    public const TYPE_SMS_ADDON = 'sms_addon';

    public const TYPE_MONTHLY_BILL = 'monthly_bill';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PROOF_REQUIRED = 'proof_required';

    /** What the single Activate button turns on for this payment. */
    public const ACTIVATES_SCAN = 'scan';

    public const ACTIVATES_SCAN_AND_SMS = 'scan_sms';

    public const ACTIVATES_SMS = 'sms';

    protected $fillable = ['organization_id', 'bill_id', 'payment_type', 'status', 'amount_minor', 'currency', 'payment_method', 'reference_number', 'proof_path', 'paid_at', 'payment_time', 'coverage_start', 'coverage_end', 'notes', 'recorded_by', 'submitted_by', 'reviewed_by', 'reviewed_at', 'rejection_reason', 'proof_request_reason', 'proof_requested_by', 'proof_requested_at', 'proof_unavailable'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'payment_time' => 'datetime:H:i', 'coverage_start' => 'date', 'coverage_end' => 'date', 'reviewed_at' => 'datetime', 'proof_requested_at' => 'datetime', 'proof_unavailable' => 'boolean'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
    }

    public function proofRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proof_requested_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * What approving this payment turns on, decided by the bill the organization chose when it paid
     * (Base = Scan only, Base + SMS = Scan + SMS, "Add SMS" = SMS on top of the Scan it already has).
     * Payments without a bill are older Base-only submissions.
     */
    public function activates(): string
    {
        $bill = $this->relationLoaded('bill') ? $this->bill : $this->bill()->with('items')->first();
        if (! $bill) {
            return self::ACTIVATES_SCAN;
        }
        if ($bill->bill_type === Bill::TYPE_SMS_NOW) {
            return self::ACTIVATES_SMS;
        }

        return $bill->bill_type === Bill::TYPE_PACKAGE && $bill->items->contains('type', BillItem::TYPE_SMS)
            ? self::ACTIVATES_SCAN_AND_SMS
            : self::ACTIVATES_SCAN;
    }

    public static function activatesLabel(string $activates): string
    {
        return match ($activates) {
            self::ACTIVATES_SCAN_AND_SMS => 'Scan + SMS',
            self::ACTIVATES_SMS => 'SMS (Scan already active)',
            default => 'Scan only',
        };
    }
}
