<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionCheckMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, $pms_param): Response
    {
        if (can_access($pms_param)) {
            return $next($request);
        }
        return response()->json([
            "success" => false,
            "message" => "Bạn không có quyền."
        ]);
    }
}
