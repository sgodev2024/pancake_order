<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionCheckMiddleware;
use App\Models\Product;
use App\Services\ShopAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ProductController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ShopAccessService $shopAccessService) {}

    public static function middleware(): array
    {
        return [
            new Middleware(
                PermissionCheckMiddleware::class.':list-product',
                only: ['index', 'show', 'store', 'update', 'destroy']
            ),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $user,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = Product::query();
        $query->with(['shop:id,name']);
        if ($requestedShopId !== null) {
            $query->where('shop_id', $requestedShopId);
        } elseif (! $this->shopAccessService->isGlobal($user)) {
            $query->whereIn('shop_id', $this->shopAccessService->ids($user));
        }

        $query->when(isset($validated['search']), function ($q) use ($validated) {
            $q->where('name', 'like', '%'.$validated['search'].'%');
        });

        $products = $query->latest()->paginate(
            $validated['page_size'] ?? 20,
            ['*'],
            'page',
            $validated['page'] ?? 1
        );

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $products->items(),
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total_items' => $products->total(),
                'total_pages' => $products->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProductAccess($request, $product);

        return response()->json([
            'success' => true,
            'data' => $product->load('shop:id,name'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'name' => ['required', 'string', 'max:255'],
            'pancake_product_id' => ['nullable', 'string', 'max:255'],
            'pancake_full_data' => ['nullable', 'array'],
        ]);

        $this->shopAccessService->authorizeRequestedShopId($request->user(), $validated['shop_id']);
        $validated['pancake_full_data'] ??= [];

        $product = Product::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tạo sản phẩm thành công.',
            'data' => $product->load('shop:id,name'),
        ], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProductAccess($request, $product);

        $validated = $request->validate([
            'shop_id' => ['sometimes', 'integer', 'exists:shops,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'pancake_product_id' => ['nullable', 'string', 'max:255'],
            'pancake_full_data' => ['nullable', 'array'],
        ]);

        if (isset($validated['shop_id'])) {
            $this->shopAccessService->authorizeRequestedShopId($request->user(), $validated['shop_id']);
        }

        $product->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật sản phẩm thành công.',
            'data' => $product->fresh()->load('shop:id,name'),
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProductAccess($request, $product);
        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa sản phẩm thành công.',
        ]);
    }

    private function authorizeProductAccess(Request $request, Product $product): void
    {
        if (! $this->shopAccessService->canAccessShop($request->user(), (int) $product->shop_id)) {
            throw new AuthorizationException('You do not have access to this product.');
        }
    }
}
