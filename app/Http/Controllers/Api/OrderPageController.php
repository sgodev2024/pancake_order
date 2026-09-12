<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrderPageOptionsService;
use App\Services\ShopAccessService;
use Illuminate\Http\Request;

class OrderPageController extends Controller
{
    public function index(Request $request, ShopAccessService $shopAccess, OrderPageOptionsService $pages)
    {
        $validated = $request->validate(['shop_id' => ['required', 'integer', 'min:1']]);
        $shopId = $shopAccess->authorizeRequestedShopId($request->user(), (int) $validated['shop_id']);

        return response()->json([
            'success' => true,
            'data' => $pages->query($request->user(), $shopId)->get()->map(static fn ($page): array => [
                'id' => (string) $page->id,
                'name' => $page->name,
            ]),
        ]);
    }
}
