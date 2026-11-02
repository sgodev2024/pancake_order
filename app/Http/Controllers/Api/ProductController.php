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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ShopAccessService $shopAccessService) {}

    public static function middleware(): array
    {
        return [
            new Middleware(
                PermissionCheckMiddleware::class.':list-product,strict',
                only: ['index', 'show', 'store', 'update', 'destroy']
            ),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['shop_id' => ['nullable', 'integer', 'min:1']]);
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
            $term = '%'.$validated['search'].'%';
            $q->where(function ($search) use ($term) {
                $search->where('name', 'like', $term)
                    ->orWhere('pancake_product_id', 'like', $term)
                    ->orWhere('pancake_full_data->display_id', 'like', $term)
                    ->orWhere('pancake_full_data->barcode', 'like', $term);
            });
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
        $validated = $this->validateProduct($request);

        $this->shopAccessService->authorizeRequestedShopId($request->user(), $validated['shop_id']);
        $product = new Product(['shop_id' => $validated['shop_id'], 'pancake_product_id' => null]);
        $this->saveProduct($request, $product, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Tạo sản phẩm thành công.',
            'data' => $product->load('shop:id,name'),
        ], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProductAccess($request, $product);

        $validated = $this->validateProduct($request, true);

        if (isset($validated['shop_id'])) {
            $this->shopAccessService->authorizeRequestedShopId($request->user(), $validated['shop_id']);
        }

        if ($product->pancake_product_id !== null && isset($validated['shop_id'])
            && (int) $validated['shop_id'] !== (int) $product->shop_id) {
            throw ValidationException::withMessages([
                'shop_id' => 'Không thể chuyển sản phẩm đồng bộ Pancake sang shop khác.',
            ]);
        }

        $this->saveProduct($request, $product, $validated);

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

    private function validateProduct(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'shop_id' => [$updating ? 'sometimes' : 'required', 'integer', Rule::exists('shops', 'id')->whereNull('deleted_at')],
            'name' => [$updating ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'selling_price' => [$updating ? 'sometimes' : 'required', 'required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'initial_quantity' => [$updating ? 'sometimes' : 'required', 'required', 'integer', 'min:0', 'max:2147483647'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
            // Upstream identity and raw payload can only be written by the sync job.
            'pancake_product_id' => ['prohibited'],
            'pancake_full_data' => ['prohibited'],
        ]);
    }

    private function saveProduct(Request $request, Product $product, array $validated): void
    {
        $data = $product->pancake_full_data ?? [];
        if (array_key_exists('selling_price', $validated)) {
            $data['retail_price'] = (float) $validated['selling_price'];
        }
        if (array_key_exists('initial_quantity', $validated)) {
            $data['remain_quantity'] = (int) $validated['initial_quantity'];
        }

        $previousImagePaths = $this->localProductImagePaths($data);
        $imagePath = null;
        $removeImage = $request->boolean('remove_image') && ! $request->hasFile('image');
        try {
            if ($request->hasFile('image')) {
                // Random filenames and server-detected types; never use a client filename.
                $imagePath = $request->file('image')->store('products', 'public');
                if ($imagePath === false) {
                    throw new \RuntimeException('Không thể lưu ảnh sản phẩm.');
                }
                $data['images'] = [Storage::disk('public')->url($imagePath)];
                unset($data['image_removed']);
            } elseif ($removeImage) {
                $data['images'] = [];
                $data['image_removed'] = true;
            }
            $product->fill(array_intersect_key($validated, array_flip(['shop_id', 'name'])));
            $product->pancake_full_data = $data;
            $product->save();
        } catch (\Throwable $exception) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }
            throw $exception;
        }

        foreach ($previousImagePaths as $previousImagePath) {
            if ($previousImagePath === $imagePath) {
                continue;
            }

            try {
                Storage::disk('public')->delete($previousImagePath);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    /** @return list<string> */
    private function localProductImagePaths(array $data): array
    {
        $imageValues = [
            $data['images'] ?? null,
            $data['image'] ?? null,
            data_get($data, 'product.images'),
            data_get($data, 'product.image'),
            data_get($data, 'product.variation_info.images'),
            data_get($data, 'product.variation_info.image'),
            data_get($data, 'variation_info.images'),
            data_get($data, 'variation_info.image'),
        ];
        $storageUrlPath = parse_url(Storage::disk('public')->url(''), PHP_URL_PATH);
        if (! is_string($storageUrlPath) || $storageUrlPath === '') {
            return [];
        }

        $storageUrlPrefix = rtrim($storageUrlPath, '/') . '/';
        $paths = [];
        foreach ($imageValues as $imageValue) {
            $images = is_array($imageValue) ? $imageValue : [$imageValue];
            foreach ($images as $image) {
                if (! is_string($image)) {
                    continue;
                }

                $imageUrlPath = parse_url($image, PHP_URL_PATH);
                if (! is_string($imageUrlPath) || ! str_starts_with($imageUrlPath, $storageUrlPrefix)) {
                    continue;
                }

                $path = substr($imageUrlPath, strlen($storageUrlPrefix));
                if (str_starts_with($path, 'products/')) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique($paths));
    }
}
