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
    public function handle(Request $request, Closure $next, $pms_param, $mode = null): Response
    {
        $params = explode('|', (string) $pms_param);
        $allowed = false;
        foreach ($params as $param) {
            if (can_access($param)) {
                $allowed = true;
                break;
            }
        }

        if ($allowed) {
            return $next($request);
        }
        $response = response()->json([
            "success" => false,
            "message" => "Bạn không có quyền."
        ]);

        return $mode === 'strict' ? $response->setStatusCode(403) : $response;
    }
}
