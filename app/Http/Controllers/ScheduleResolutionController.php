<?php

namespace App\Http\Controllers;

use App\Services\ScheduleResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleResolutionController extends Controller
{
    public function show(Request $request, int $person, ScheduleResolver $resolver): JsonResponse
    {
        $owned = $request->user()->organization->persons()->findOrFail($person);
        $date = Carbon::parse($request->query('date', now()->toDateString()), $request->user()->organization->timezone);

        return response()->json(['schedule' => $resolver->resolve($owned, $date)]);
    }
}
