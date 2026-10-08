<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminOnlyMiddleware
{
    /**
     * Allow a request only when the authenticated user's role is explicitly admin.
     *
     * Scoped modes are for shared endpoints whose normal business use must remain
     * available to its existing roles.
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if ($mode === 'report-query' && $request->query('page_name') !== 'report_page') {
            return $next($request);
        }

        if ($mode === 'activity-log-query' && $request->query('page_name') !== 'activity_log') {
            return $next($request);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $allowedSlugs = ['admin'];
        if ($mode === 'allow-director' || $mode === 'report-query') {
            $allowedSlugs[] = 'director';
        }

        if (!in_array($user->role?->slug, $allowedSlugs, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền.',
            ], 403);
        }

        return $next($request);
    }
}
