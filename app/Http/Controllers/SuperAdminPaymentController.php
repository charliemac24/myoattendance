<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SuperAdminPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::with(['organization:id,name', 'recordedBy:id,name'])
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->integer('organization_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('paid_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('paid_at', '<=', $request->input('to')))
            ->when($request->filled('reference'), fn ($q) => $q->where('reference_number', 'like', '%'.$request->input('reference').'%'))
            ->latest('paid_at')->paginate(min($request->integer('per_page', 20), 100))->withQueryString();

        return response()->json($payments);
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'payment_method' => ['required', Rule::in([Payment::METHOD_GCASH])],
            'reference_number' => ['nullable', 'string', 'max:128'],
            'paid_at' => ['required', 'date'],
            'coverage_start' => ['nullable', 'date'],
            'coverage_end' => ['nullable', 'date', 'after_or_equal:coverage_start'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['amount_minor'] = (int) round(((float) $data['amount']) * 100);
        unset($data['amount']);
        $data['currency'] = 'PHP';
        $data['recorded_by'] = $request->user()->id;

        $payment = $organization->payments()->create($data);

        return response()->json(['payment' => $payment->load(['organization:id,name', 'recordedBy:id,name'])], 201);
    }
}
