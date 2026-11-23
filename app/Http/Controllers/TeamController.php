<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * The people who can sign in to an organization. Owners and admins add staff (scan only); only the owner adds or
 * changes admins. Staff sign in with a username, so an email address is optional.
 */
class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $members = $request->user()->organization->users()
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get();

        return response()->json([
            'members' => $members->map(fn (User $member): array => $this->memberPayload($request->user(), $member))->values(),
            'can_add_admins' => $request->user()->isOwner(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeUsername($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in($this->assignableRoles($request->user()))],
            'password' => ['required', PasswordRule::defaults()],
        ]);

        $member = $request->user()->organization->users()->create([
            'name' => trim($data['name']),
            'username' => $data['username'],
            'email' => isset($data['email']) ? strtolower($data['email']) : null,
            'role' => $data['role'],
            'password' => Hash::make($data['password']),
            'email_verified_at' => now(),
        ]);

        return response()->json(['member' => $this->memberPayload($request->user(), $member)], 201);
    }

    public function update(Request $request, int $member): JsonResponse
    {
        $target = $this->editable($request, $member);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in($this->assignableRoles($request->user()))],
            'password' => ['nullable', PasswordRule::defaults()],
        ]);

        $attributes = ['name' => trim($data['name']), 'role' => $data['role']];
        if (! empty($data['password'])) {
            $attributes['password'] = Hash::make($data['password']);
            $attributes['remember_token'] = Str::random(60);
        }
        $target->update($attributes);

        return response()->json(['member' => $this->memberPayload($request->user(), $target->fresh())]);
    }

    public function destroy(Request $request, int $member): JsonResponse
    {
        $this->editable($request, $member)->delete();

        return response()->json(['message' => 'Removed.']);
    }

    /** The owner can add admins and staff; an admin can only add staff. */
    private function assignableRoles(User $user): array
    {
        return $user->isOwner() ? [User::ROLE_ADMIN, User::ROLE_STAFF] : [User::ROLE_STAFF];
    }

    /** Nobody edits or removes the owner or themselves here, and only the owner manages admins. */
    private function editable(Request $request, int $member): User
    {
        $target = $request->user()->organization->users()->findOrFail($member);
        abort_unless($this->canManage($request->user(), $target), 403, 'You cannot change this account.');

        return $target;
    }

    private function canManage(User $user, User $target): bool
    {
        if ($target->id === $user->id || $target->isOwner()) {
            return false;
        }

        return $user->isOwner() || $target->isStaff();
    }

    private function normalizeUsername(Request $request): void
    {
        if ($request->filled('username')) {
            $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);
        }
    }

    /** @return array{id: int, name: string, username: ?string, email: ?string, role: string, role_label: string, is_you: bool, can_manage: bool} */
    private function memberPayload(User $user, User $member): array
    {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'username' => $member->username,
            'email' => $member->email,
            'role' => $member->role,
            'role_label' => User::ROLE_LABELS[$member->role] ?? ucfirst((string) $member->role),
            'is_you' => $member->id === $user->id,
            'can_manage' => $this->canManage($user, $member),
        ];
    }
}
