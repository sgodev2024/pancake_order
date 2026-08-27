<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerJourneyService;
use App\Services\CustomerReadAccessService;
use Illuminate\Http\Request;

class CustomerJourneyController extends Controller
{
    public function __construct(
        private readonly CustomerJourneyService $customerJourneyService,
        private readonly CustomerReadAccessService $customerReadAccessService
    ) {}

    public function show(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'action' => ['nullable', 'string', 'in:'.implode(',', CustomerJourneyService::ACTIONS)],
        ]);

        $this->customerReadAccessService->authorize($request->user(), $customer);

        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 50);
        $result = $this->customerJourneyService->paginate(
            $customer,
            $page,
            $pageSize,
            $validated['action'] ?? null
        );
        $paginator = $result['paginator'];

        $customer->load([
            'shop' => fn ($query) => $query->select('shops.id', 'shops.name'),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'customer' => [
                    'id' => (int) $customer->getKey(),
                    'name' => $customer->name,
                    'shop_id' => (int) $customer->shop_id,
                    'shop_name' => $customer->shop?->name,
                ],
                'summary' => [
                    'care_count' => $result['care_count'],
                    'order_count' => $result['order_count'],
                ],
                'timeline' => $paginator->items(),
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total_items' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }
}
