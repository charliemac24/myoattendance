<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements CanResetPassword, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use CanResetPasswordTrait, HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    /** Can only scan attendance. */
    public const ROLE_STAFF = 'staff';

    public const ROLE_LABELS = [self::ROLE_OWNER => 'Owner', self::ROLE_ADMIN => 'Admin', self::ROLE_STAFF => 'Staff'];

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN && $this->organization_id === null;
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF;
    }

    protected $fillable = ['organization_id', 'name', 'username', 'email', 'contact_number', 'password', 'role', 'email_verified_at'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class, 'performed_by_user_id');
    }

    public function recordedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'recorded_by');
    }

    public function enabledEntitlements(): HasMany
    {
        return $this->hasMany(FeatureEntitlement::class, 'enabled_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
