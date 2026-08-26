<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleReadAccessMiddleware
{
    /**
     * Allow role options to the admin role or to users who can already list
     * employees. Role administration remains protected separately.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isAdmin() || can_access('list-staff')) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Bạn không có quyền.',
        ], 403);
    }
}
