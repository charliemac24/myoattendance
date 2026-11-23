<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Support\SafeCsv;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $membership = $this->membershipFilter($request);
        $today = now($request->user()->organization->timezone)->toDateString();
        $people = $request->user()->organization->persons()->with(['group:id,name', 'subgroup:id,name'])
            ->when($membership, fn ($query) => $query->membershipStatus($membership, $today))
            ->when($request->string('search')->trim()->value(), function ($query, $search): void {
                $query->where(fn ($nested) => $nested->where('person_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhereHas('group', fn ($group) => $group->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('subgroup', fn ($subgroup) => $subgroup->where('name', 'like', "%{$search}%")));
            })
            ->when($request->filled('group_id'), fn ($query) => $query->where('group_id', $request->integer('group_id')))
            ->when($request->filled('subgroup_id'), fn ($query) => $query->where('subgroup_id', $request->integer('subgroup_id')))
            ->when($membership, fn ($query) => $query->orderBy('membership_valid_until'))
            ->orderBy('last_name')->orderBy('first_name')->paginate(min($request->integer('per_page', 20), 100))->withQueryString();

        return response()->json($people);
    }

    public function export(Request $request): StreamedResponse
    {
        $organization = $request->user()->organization;
        $membership = $this->membershipFilter($request);
        $today = now($organization->timezone)->toDateString();
        $filename = $membership ? "persons-{$membership}-{$today}.csv" : 'persons.csv';

        return response()->streamDownload(function () use ($organization, $membership, $today): void {
            $output = fopen('php://output', 'wb');
            SafeCsv::put($output, ['person_number', 'first_name', 'last_name', 'group', 'subgroup', 'contact_number', 'membership_valid_until']);

            $organization->persons()
                ->with(['group:id,name', 'subgroup:id,name'])
                ->when($membership, fn ($query) => $query->membershipStatus($membership, $today)->orderBy('membership_valid_until'))
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->orderBy('person_number')
                ->chunk(500, function ($people) use ($output): void {
                    foreach ($people as $person) {
                        SafeCsv::put($output, [
                            $person->person_number,
                            $person->first_name,
                            $person->last_name,
                            $person->group?->name,
                            $person->subgroup?->name,
                            $person->contact_number,
                            $person->membership_valid_until?->toDateString(),
                        ]);
                    }
                });

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $person = $request->user()->organization->persons()->create($data);

        return response()->json(['person' => $person->load(['group:id,name', 'subgroup:id,name'])], 201);
    }

    public function show(Request $request, int $person): JsonResponse
    {
        return response()->json(['person' => $this->owned($request, $person)->load(['group:id,name', 'subgroup:id,name'])]);
    }

    public function update(Request $request, int $person): JsonResponse
    {
        $owned = $this->owned($request, $person);
        $owned->update($this->validated($request, $owned->id));

        return response()->json(['person' => $owned->fresh()->load(['group:id,name', 'subgroup:id,name'])]);
    }

    public function archive(Request $request, int $person): JsonResponse
    {
        $owned = $this->owned($request, $person);
        $owned->update(['is_active' => false]);

        return response()->json(['person' => $owned->fresh()->load(['group:id,name', 'subgroup:id,name'])]);
    }

    public function regenerateQr(Request $request, int $person): JsonResponse
    {
        $owned = $this->owned($request, $person);
        $token = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = Str::random(64);
            if (Person::where('qr_token', $candidate)->exists()) {
                continue;
            }

            try {
                $owned->update(['qr_token' => $candidate]);
                $token = $candidate;
                break;
            } catch (UniqueConstraintViolationException) {
                // A concurrent generator may win the unique index race; retry.
            }
        }

        if ($token === null) {
            throw new \RuntimeException('Unable to generate a unique QR token.');
        }

        return response()->json(['person' => $owned->fresh()->load(['group:id,name', 'subgroup:id,name']), 'qr_payload' => $token]);
    }

    public function qr(Request $request, int $person): JsonResponse
    {
        $owned = $this->owned($request, $person);

        return response()->json(['person_id' => $owned->id, 'qr_payload' => $owned->qr_token]);
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        $data = $request->validate(['person_number' => ['required', 'string', 'max:64'], 'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'], 'group_id' => ['nullable', 'integer'], 'subgroup_id' => ['nullable', 'integer'], 'contact_number' => ['nullable', 'string', 'max:32'], 'membership_valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'is_active' => ['sometimes', 'boolean']]);
        $organization = $request->user()->organization;
        $data['person_number'] = trim($data['person_number']);
        $duplicate = $organization->persons()->where('person_number', $data['person_number'])->when($ignore, fn ($query) => $query->whereKeyNot($ignore))->exists();
        if ($duplicate) {
            return response()->json(['message' => 'That person number already exists in this organization.', 'errors' => ['person_number' => ['That person number already exists in this organization.']]], 422)->throwResponse();
        }
        $group = ! empty($data['group_id']) ? $organization->groups()->findOrFail($data['group_id']) : null;
        $subgroup = ! empty($data['subgroup_id']) ? $organization->subgroups()->whereKey($data['subgroup_id'])->firstOrFail() : null;
        if ($subgroup && (! $group || $subgroup->group_id !== $group->id)) {
            return response()->json(['message' => 'The subgroup must belong to the selected group.', 'errors' => ['subgroup_id' => ['The subgroup must belong to the selected group.']]], 422)->throwResponse();
        }
        $data['group_id'] = $group?->id;
        $data['subgroup_id'] = $subgroup?->id;
        if ($ignore === null) {
            // New people start active. On update, the archive state only changes when it is sent explicitly.
            $data['is_active'] ??= true;
        }

        return $data;
    }

    /** The People list's membership filter (expired, expiring_7, expiring_14), or null for everyone. */
    private function membershipFilter(Request $request): ?string
    {
        $request->validate(['membership' => ['nullable', Rule::in(Person::MEMBERSHIP_FILTERS)]]);

        return $request->filled('membership') ? $request->string('membership')->value() : null;
    }

    private function owned(Request $request, int $id): Person
    {
        return $request->user()->organization->persons()->findOrFail($id);
    }
}
