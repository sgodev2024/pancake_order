<?php
namespace App\Http\Middleware;
use App\Services\WebsiteMaintenance;
use Closure;
use Illuminate\Http\Request;
class WebsiteMaintenanceMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('api/v1/me', 'api/v1/me/*', 'api/v1/change-first-password') || $request->user()?->role?->slug === 'admin') {
            return $next($request);
        }
        if (app(WebsiteMaintenance::class)->enabled()) {
            return response()->json(['success' => false, 'maintenance' => true, 'message' => 'Website đang bảo trì'], 503)
                ->header('Retry-After', '30')->header('Cache-Control', 'no-store');
        }
        return $next($request);
    }
}
