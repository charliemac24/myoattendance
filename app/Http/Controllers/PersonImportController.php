<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class PersonImportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
            'upsert' => ['nullable', 'boolean'],
        ]);
        $upsert = $request->boolean('upsert');
        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        $header = fgetcsv($handle);
        $header = array_map(fn ($value) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value))), $header ?: []);
        $required = ['person_number', 'first_name', 'last_name'];
        if (array_diff($required, $header)) {
            return response()->json(['message' => 'CSV must include person_number, first_name, and last_name columns.'], 422);
        }
        if (count($header) !== count(array_unique($header))) {
            return response()->json(['message' => 'CSV headers must be unique.'], 422);
        }

        $summary = ['imported' => 0, 'updated' => 0, 'skipped_duplicates' => 0, 'failed' => 0, 'errors' => []];
        $organization = $request->user()->organization;
        $rowNumber = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($rowNumber > 1001) {
                $summary['failed']++;
                $summary['errors'][] = ['row' => $rowNumber, 'message' => 'The import is limited to 1,000 data rows.'];
                break;
            }
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $values = array_pad($row, count($header), null);
            $record = array_combine($header, array_slice($values, 0, count($header)));
            $record = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $record);
            $existing = ! empty($record['person_number'])
                ? $organization->persons()
                    ->where('organization_id', $organization->id)
                    ->where('person_number', $record['person_number'])
                    ->first()
                : null;
            $qrTokenRule = Rule::unique('persons', 'qr_token');
            if ($existing) {
                $qrTokenRule->ignore($existing->id);
            }
            $rowValidator = Validator::make($record, [
                'person_number' => ['required', 'string', 'max:64'],
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'group' => ['nullable', 'string', 'max:255'],
                'subgroup' => ['nullable', 'string', 'max:255'],
                'contact_number' => ['nullable', 'string', 'max:32'],
                'membership_valid_until' => ['nullable', 'date_format:Y-m-d'],
                'qr_token' => ['nullable', 'string', 'max:128', $qrTokenRule],
            ]);
            if ($rowValidator->fails()) {
                $summary['failed']++;
                $summary['errors'][] = ['row' => $rowNumber, 'message' => 'The row contains invalid person data.'];

                continue;
            }
            $record = $rowValidator->validated();
            $existing ??= $organization->persons()
                ->where('organization_id', $organization->id)
                ->where('person_number', $record['person_number'])
                ->first();
            if ($existing && ! $upsert) {
                $summary['skipped_duplicates']++;

                continue;
            }
            try {
                DB::transaction(function () use ($organization, $record, $existing, $upsert): void {
                    $group = null;
                    if (array_key_exists('group', $record) && ! blank($record['group'])) {
                        $group = $organization->groups()->whereRaw('lower(name) = ?', [strtolower($record['group'])])->first() ?? $organization->groups()->create(['name' => $record['group']]);
                    } elseif ($existing && $upsert) {
                        $group = $existing->group;
                    }
                    $subgroup = null;
                    if (array_key_exists('subgroup', $record) && ! blank($record['subgroup'])) {
                        if (! $group) {
                            throw new \InvalidArgumentException('A subgroup requires a group.');
                        }
                        $subgroup = $organization->subgroups()->where('group_id', $group->id)->whereRaw('lower(name) = ?', [strtolower($record['subgroup'])])->first() ?? $organization->subgroups()->create(['group_id' => $group->id, 'name' => $record['subgroup']]);
                    } elseif ($existing && $upsert && ! array_key_exists('group', $record) && ! array_key_exists('subgroup', $record)) {
                        $subgroup = $existing->subgroup;
                    }
                    $attributes = ['first_name' => $record['first_name'], 'last_name' => $record['last_name']];
                    if (! $existing) {
                        $attributes = array_merge($attributes, [
                            'person_number' => $record['person_number'],
                            'group_id' => $group?->id,
                            'subgroup_id' => $subgroup?->id,
                            'contact_number' => blank($record['contact_number'] ?? null) ? null : $record['contact_number'],
                            'membership_valid_until' => blank($record['membership_valid_until'] ?? null) ? null : $record['membership_valid_until'],
                            'qr_token' => blank($record['qr_token'] ?? null) ? null : $record['qr_token'],
                        ]);
                        $organization->persons()->create($attributes);

                        return;
                    }
                    if (array_key_exists('group', $record)) {
                        $attributes['group_id'] = $group?->id;
                        $attributes['subgroup_id'] = $subgroup?->id;
                    } elseif (array_key_exists('subgroup', $record)) {
                        $attributes['subgroup_id'] = $subgroup?->id;
                    }
                    foreach (['contact_number', 'membership_valid_until'] as $field) {
                        if (array_key_exists($field, $record)) {
                            $attributes[$field] = blank($record[$field]) ? null : $record[$field];
                        }
                    }
                    if (array_key_exists('qr_token', $record) && ! blank($record['qr_token'])) {
                        $attributes['qr_token'] = $record['qr_token'];
                    }
                    $existing->update($attributes);
                });
                $summary[$existing ? 'updated' : 'imported']++;
            } catch (Throwable) {
                $summary['failed']++;
                $summary['errors'][] = ['row' => $rowNumber, 'message' => 'The row could not be imported.'];
            }
        }
        fclose($handle);

        return response()->json($summary);
    }
}
