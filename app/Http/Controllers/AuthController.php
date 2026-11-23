<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\BaseSubscriptionService;
use App\Services\SmsPriceEstimator;
use App\Services\TurnstileService;
use App\Support\OrganizationTerminology;
use App\Support\OrganizationTypes;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function user(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function register(Request $request, TurnstileService $turnstile): JsonResponse
    {
        if ($request->filled('username')) {
            $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
            'organization_name' => ['required', 'string', 'max:255'],
            'organization_type' => ['required', 'string', 'in:'.implode(',', OrganizationTypes::VALUES)],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'contact_number' => ['required', 'string', 'max:32', 'regex:/^[0-9+() .-]+$/'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            // Optional answers used to show an estimated SMS price on the Billing page.
            'sms_estimate_people' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'sms_estimate_texts' => ['nullable', 'array'],
            'sms_estimate_texts.*' => ['string', 'in:time_in,time_out,late'],
            'sms_estimate_days' => ['nullable', 'string', 'in:weekdays,mon_sat,every_day'],
        ]);

        if (! $turnstile->validate((string) $request->input('cf-turnstile-response'), $request->ip())) {
            return response()->json(['message' => 'Please complete the security check and try again.'], 422);
        }

        $user = DB::transaction(function () use ($data): User {
            $organization = Organization::create([
                'name' => $data['organization_name'],
                'type' => $data['organization_type'],
                'timezone' => 'Asia/Manila',
            ]);
            app(BaseSubscriptionService::class)->createTrial($organization);

            $owner = User::create([
                'organization_id' => $organization->id,
                'name' => $data['name'],
                'username' => strtolower($data['username']),
                'email' => strtolower($data['email']),
                'contact_number' => trim($data['contact_number']),
                'password' => Hash::make($data['password']),
                'role' => 'owner',
            ]);
            app(SmsPriceEstimator::class)->saveAnswers($organization, $owner, $data['sms_estimate_people'] ?? null, $data['sms_estimate_texts'] ?? [], $data['sms_estimate_days'] ?? null);

            return $owner;
        });

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $this->userPayload($user), 'csrf_token' => csrf_token()], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['nullable', 'string', 'max:255', 'required_without:email'],
            'email' => ['nullable', 'email', 'required_without:identifier'],
            'password' => ['required', 'string'],
        ]);

        $identifier = trim($data['identifier'] ?? $data['email']);
        $user = User::query()
            ->where(fn ($query) => $query->where('username', strtolower($identifier))->orWhere('email', strtolower($identifier)))
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password) || ! Auth::loginUsingId($user->id)) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 422);
        }

        $request->session()->regenerate();

        return response()->json(['user' => $this->userPayload($request->user()), 'csrf_token' => csrf_token()]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    public function updateAccount(Request $request): JsonResponse
    {
        if ($request->filled('username')) {
            $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['sometimes', 'nullable', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._@+-]+$/', 'unique:users,username,'.$request->user()->id],
        ]);
        $user = $request->user();
        $attributes = ['name' => trim($data['name'])];
        if (array_key_exists('username', $data) && $data['username'] !== null) {
            $attributes['username'] = $data['username'];
        }
        $user->update($attributes);

        return response()->json(['user' => $this->userPayload($user->fresh()), 'message' => 'Account details updated.']);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'confirmed', PasswordRule::defaults()]]);
        $request->user()->update(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)]);

        return response()->json(['message' => 'Password updated successfully.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => strtolower($data['email'])]);

        return response()->json(['message' => 'If an account exists for that email, a password reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password)])->setRememberToken(Str::random(60));
            $user->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    private function userPayload(?User $user): array
    {
        $user->loadMissing('organization');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username ?: $user->email,
            'email' => $user->email,
            'contact_number' => $user->contact_number,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'organization' => $user->organization ? [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
                'type' => $user->organization->type,
                'timezone' => $user->organization->timezone,
                'terminology' => OrganizationTerminology::forOrganization($user->organization),
            ] : null,
            'role' => $user->role,
            'base_subscription' => $user->organization ? app(BaseSubscriptionService::class)->payload($user->organization) : null,
        ];
    }
}
