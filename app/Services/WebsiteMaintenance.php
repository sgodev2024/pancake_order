<?php
namespace App\Services;
use App\Models\Setting;
class WebsiteMaintenance
{
    public function enabled(): bool
    {
        return (bool) (Setting::where('code', 'website_maintenance')->value('data')['enabled'] ?? false);
    }
}
