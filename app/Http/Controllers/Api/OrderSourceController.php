<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PancakeOrderSource;
use App\Services\ShopAccessService;
use Illuminate\Http\Request;

class OrderSourceController extends Controller
{
    public function __construct(private readonly ShopAccessService $shopAccessService) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'min:1'],
        ]);

        $shopId = $this->shopAccessService->authorizeRequestedShopId(
            $request->user(),
            (int) $validated['shop_id']
        );

        $sources = PancakeOrderSource::query()
            ->where('shop_id', $shopId)
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('external_source_id')
            ->get([
                'external_source_id',
                'name',
                'parent_external_source_id',
                'is_active',
            ])
            ->map(static fn (PancakeOrderSource $source): array => [
                'id' => (string) $source->external_source_id,
                'name' => $source->name,
                'parent_id' => $source->parent_external_source_id,
                'is_active' => (bool) $source->is_active,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $sources,
        ]);
    }
}
