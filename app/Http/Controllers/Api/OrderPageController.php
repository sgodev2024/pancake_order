<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CustomerCareListQuery;
use App\Services\CustomerCareOrderSourceService;
use App\Services\OrderPageOptionsService;
use App\Services\ShopAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderPageController extends Controller
{
    public function index(Request $request, ShopAccessService $shopAccess, OrderPageOptionsService $pages)
    {
        $careContext = $request->query('context') === 'customer_care';
        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'min:1'],
            'context' => ['nullable', Rule::in(['customer_care'])],
            'type' => [Rule::requiredIf($careContext), 'nullable', Rule::in([
                'customer_care_today', 'customer_care_pending', 'customer_care_expire', 'customer_care_edit',
            ])],
        ]);
        $shopId = $shopAccess->authorizeRequestedShopId($request->user(), (int) $validated['shop_id']);

        if ($careContext) {
            $user = $request->user();
            abort_unless($user->isAdmin() || $user->isManagerCskh() || $user->isStaffCskh(), 403);
            $allowed = app(CustomerCareListQuery::class)->query($validated['type'], $user, ['shop_id' => $shopId]);
            $options = app(CustomerCareOrderSourceService::class)->pageOptions($allowed, $shopId);
        } else {
            $options = $pages->query($request->user(), $shopId)->get()->map(static fn ($page): array => [
                'id' => (string) $page->id,
                'name' => $page->name,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $options,
        ]);
    }
}
