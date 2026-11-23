<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PAYMENT_PENDING = 'payment_pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_BASE = 'base';

    /**
     * Base + SMS. SMS is an add-on to Base: texts are only sent when a scan is recorded.
     */
    public const TYPE_PACKAGE = 'package';

    /**
     * One-time SMS charge for the rest of a paid Base period ("Start SMS now"). Not a plan.
     */
    public const TYPE_SMS_NOW = 'sms_now';

    /**
     * The two plans a school can choose.
     */
    public const TYPES = [self::TYPE_BASE, self::TYPE_PACKAGE];

    /**
     * Every kind of bill a school can pay.
     */
    public const ALL_TYPES = [self::TYPE_BASE, self::TYPE_PACKAGE, self::TYPE_SMS_NOW];

    /**
     * Plan lengths a school can pay for at once.
     */
    public const MONTH_OPTIONS = [1, 3, 6, 12];

    public const FEATURE_BASE = 'base';

    public const FEATURE_SMS = 'sms';

    protected $fillable = ['organization_id', 'bill_type', 'months', 'billing_period_start', 'billing_period_end', 'currency', 'subtotal_minor', 'total_minor', 'sms_included', 'status', 'due_at'];

    protected $attributes = ['bill_type' => self::TYPE_BASE, 'months' => 1];

    protected function casts(): array
    {
        return ['months' => 'integer', 'billing_period_start' => 'date', 'billing_period_end' => 'date', 'sms_included' => 'boolean', 'due_at' => 'date'];
    }

    /**
     * The features a bill of the given type pays for.
     *
     * @return list<string>
     */
    public static function featuresFor(string $type): array
    {
        return match ($type) {
            self::TYPE_BASE => [self::FEATURE_BASE],
            self::TYPE_PACKAGE => [self::FEATURE_BASE, self::FEATURE_SMS],
            self::TYPE_SMS_NOW => [self::FEATURE_SMS],
            default => throw new \InvalidArgumentException("Unknown bill type [{$type}]."),
        };
    }

    /**
     * Bill types that pay for at least one of the given bill type's features.
     *
     * @return list<string>
     */
    public static function overlappingTypes(string $type): array
    {
        $features = self::featuresFor($type);

        return array_values(array_filter(self::ALL_TYPES, fn (string $candidate): bool => array_intersect(self::featuresFor($candidate), $features) !== []));
    }

    /**
     * The name schools and admins see for a kind of bill.
     */
    public static function labelFor(string $type): string
    {
        return match ($type) {
            self::TYPE_PACKAGE => 'Base + SMS',
            self::TYPE_SMS_NOW => 'SMS for the rest of this period',
            'sms' => 'SMS add-on (old plan)',
            default => 'Base',
        };
    }

    public function coversBase(): bool
    {
        return in_array(self::FEATURE_BASE, self::featuresFor($this->bill_type), true);
    }

    public function coversSms(): bool
    {
        return in_array(self::FEATURE_SMS, self::featuresFor($this->bill_type), true);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
