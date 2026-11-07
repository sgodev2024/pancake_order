<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\WebsiteMaintenance;
use Illuminate\Http\Request;
class WebsiteMaintenanceController extends Controller
{
    public function show(WebsiteMaintenance $maintenance)
    {
        return response()->json(['enabled' => $maintenance->enabled()])->header('Cache-Control', 'no-store');
    }
    public function update(Request $request)
    {
        abort_unless($request->user()?->role?->slug === 'admin', 403);
        $values = $request->validate(['enabled' => 'required|boolean']);
        Setting::updateOrCreate(['code' => 'website_maintenance'], ['data' => ['enabled' => (bool) $values['enabled']]]);
        return response()->json(['enabled' => (bool) $values['enabled']])->header('Cache-Control', 'no-store');
    }
}
