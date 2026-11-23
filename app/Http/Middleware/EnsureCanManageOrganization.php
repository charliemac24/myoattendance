<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff members only scan attendance. Every other page and API sends them back to the scanner (or answers 403).
 */
class EnsureCanManageOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isStaff()) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Staff accounts can only scan attendance.'], 403);
            }

            return redirect('/scanner');
        }

        return $next($request);
    }
}
