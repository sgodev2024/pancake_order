<?php

namespace Tests\Feature;

use App\Jobs\GetProductByShopJob;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductPlanOneTest extends TestCase
{
    private Shop $shop;

    private Shop $otherShop;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        // All writes, including the real migrations below, use a disposable in-memory DB.
        config([
            'database.default' => 'product_plan_one_testing',
            'database.connections.product_plan_one_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('product_plan_one_testing');
        DB::setDefaultConnection('product_plan_one_testing');
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_04_24_082539_create_permission_groups_table.php',
            '2026_04_24_082550_create_permissions_table.php',
            '2026_04_24_082551_create_roles_table.php',
            '2026_04_24_082556_create_role_permissions_table.php',
            '2026_04_27_084626_create_shops_table.php',
            '2026_04_27_093216_add_column_to_users_table.php',
            '2026_04_28_072537_create_shop_users_table.php',
            '2026_05_04_082209_add_slug_to_roles_table.php',
            '2026_05_11_161116_create_products_table.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        Storage::fake('public');
        $this->shop = $this->shop('Assigned');
        $this->otherShop = $this->shop('Other');
        $this->actor = $this->actor('staff-sale');
        $this->actingAs($this->actor, 'api');
    }

    public function test_create_and_edit_local_product_without_a_fake_pancake_id(): void
    {
        $id = $this->postJson('/api/v1/products', $this->payload())
            ->assertCreated()->assertJsonPath('data.pancake_product_id', null)
            ->assertJsonPath('data.price', 125000)->assertJsonPath('data.inventory_quantity', 3)
            ->assertJsonPath('data.virtual_inventory_quantity', 5)->json('data.id');
        $this->assertDatabaseHas('products', ['id' => $id, 'pancake_product_id' => null]);
        $this->putJson("/api/v1/products/{$id}", [
            'name' => 'Updated', 'selling_price' => 150000.25, 'initial_quantity' => 20,
        ])->assertOk()->assertJsonPath('data.name', 'Updated')
            ->assertJsonPath('data.price', 150000.25)->assertJsonPath('data.inventory_quantity', 20)
            ->assertJsonPath('data.virtual_inventory_quantity', 20);
        $this->getJson("/api/v1/products/{$id}")->assertOk()->assertJsonPath('data.shop.id', $this->shop->id);
        $this->getJson('/api/v1/products?search=Updated')->assertOk()->assertJsonCount(1, 'data.products');
        $this->assertSame(['retail_price' => 150000.25, 'remain_quantity' => 20], Product::findOrFail($id)->pancake_full_data);
    }

    public function test_zero_price_and_zero_quantity_are_valid(): void
    {
        $this->postJson('/api/v1/products', $this->payload(['selling_price' => 0, 'initial_quantity' => 0]))
            ->assertCreated()->assertJsonPath('data.price', 0)
            ->assertJsonPath('data.inventory_quantity', 0)->assertJsonPath('data.virtual_inventory_quantity', 5);
    }

    public static function invalidInputs(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'long name' => [['name' => str_repeat('a', 256)], 'name'],
            'missing shop' => [['shop_id' => null], 'shop_id'],
            'nonexistent shop' => [['shop_id' => 999999], 'shop_id'],
            'negative price' => [['selling_price' => -1], 'selling_price'],
            'invalid price' => [['selling_price' => 'cost'], 'selling_price'],
            'missing price' => [['selling_price' => null], 'selling_price'],
            'too many decimals' => [['selling_price' => 1.234], 'selling_price'],
            'negative quantity' => [['initial_quantity' => -1], 'initial_quantity'],
            'fractional quantity' => [['initial_quantity' => 1.5], 'initial_quantity'],
            'missing quantity' => [['initial_quantity' => null], 'initial_quantity'],
            'forged identity' => [['pancake_product_id' => 'fake'], 'pancake_product_id'],
            'raw payload' => [['pancake_full_data' => ['retail_price' => 1]], 'pancake_full_data'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_validation_rejects_invalid_input_without_writes(array $override, string $field): void
    {
        $this->postJson('/api/v1/products', $this->payload($override))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_upload_uses_random_name_and_edit_without_upload_preserves_image(): void
    {
        $response = $this->post('/api/v1/products', $this->payload([
            'image' => UploadedFile::fake()->image('untrusted-name.png'),
        ]), ['Accept' => 'application/json'])->assertCreated();
        $id = $response->json('data.id');
        $files = Storage::disk('public')->allFiles('products');
        $this->assertCount(1, $files);
        $this->assertStringNotContainsString('untrusted-name', $files[0]);
        $url = $response->json('data.pancake_full_data.images.0');
        $this->assertSame(Storage::disk('public')->url($files[0]), $url);
        $this->putJson("/api/v1/products/{$id}", ['name' => 'Keep image'])
            ->assertOk()->assertJsonPath('data.pancake_full_data.images.0', $url);
        // The same POST method override used by the frontend must reach update with its file.
        $this->post("/api/v1/products/{$id}", [
            '_method' => 'PUT', 'selling_price' => '42.50',
            'image' => UploadedFile::fake()->image('replacement.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.price', 42.5);
        $this->assertNotSame($url, Product::findOrFail($id)->pancake_full_data['images'][0]);
    }

    public function test_invalid_or_oversized_upload_is_rejected(): void
    {
        foreach ([
            UploadedFile::fake()->create('payload.php', 1, 'text/plain'),
            UploadedFile::fake()->create('vector.svg', 1, 'image/svg+xml'),
            UploadedFile::fake()->image('large.png')->size(5121),
        ] as $file) {
            $this->post('/api/v1/products', $this->payload(['image' => $file]), ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('products', 0);
    }

    public static function scopedRoles(): array
    {
        return [['staff-sale'], ['manager-sale'], ['warehouse'], ['staff-cskh'], ['manager-cskh']];
    }

    #[DataProvider('scopedRoles')]
    public function test_each_non_admin_role_cannot_read_write_or_upload_to_another_shop(string $role): void
    {
        $actor = $this->actor($role);
        $this->actingAs($actor, 'api');
        $own = Product::create(['shop_id' => $this->shop->id, 'name' => 'Own', 'pancake_full_data' => []]);
        $other = Product::create(['shop_id' => $this->otherShop->id, 'name' => 'Other', 'pancake_full_data' => []]);
        $this->getJson('/api/v1/shops?view=summary')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->shop->id);
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.id', $own->id);
        $this->getJson('/api/v1/products?shop_id='.$this->otherShop->id)->assertForbidden();
        $this->getJson("/api/v1/products/{$other->id}")->assertForbidden();
        $this->putJson("/api/v1/products/{$other->id}", ['name' => 'Attack'])->assertForbidden();
        $this->putJson("/api/v1/products/{$own->id}", ['shop_id' => $this->otherShop->id])->assertForbidden();
        $this->deleteJson("/api/v1/products/{$other->id}")->assertForbidden();
        $this->post('/api/v1/products', $this->payload([
            'shop_id' => $this->otherShop->id, 'image' => UploadedFile::fake()->image('forbidden.png'),
        ]), ['Accept' => 'application/json'])->assertForbidden();
        $this->post("/api/v1/products/{$other->id}", [
            '_method' => 'PUT', 'image' => UploadedFile::fake()->image('forbidden.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame('Other', $other->fresh()->name);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_permission_and_no_shop_membership_are_enforced(): void
    {
        $actor = $this->actor('no-product-permission', false);
        $this->actingAs($actor, 'api');
        $this->getJson('/api/v1/products')->assertForbidden();
        $this->postJson('/api/v1/products', $this->payload())->assertForbidden();
        $actor = $this->actor('no-shops');
        $actor->shops()->detach();
        $this->actingAs($actor, 'api');
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data.products');
        $this->postJson('/api/v1/products', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_deleted_shop_cannot_receive_a_product(): void
    {
        $this->shop->delete();
        $this->postJson('/api/v1/products', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('shop_id');
    }

    public function test_sync_still_updates_its_own_product_and_does_not_touch_local_products(): void
    {
        $localId = $this->postJson('/api/v1/products', $this->payload())->assertCreated()->json('data.id');
        $source = [
            'id' => 'real-upstream-variation', 'product' => ['name' => 'Synced'],
            'retail_price' => 240000, 'average_imported_price' => 60000, 'remain_quantity' => 3,
            'images' => ['https://example.test/synced.jpg'],
        ];
        (new GetProductByShopJob([$source], $this->shop->id))->handle();
        $synced = Product::where('pancake_product_id', $source['id'])->sole();
        $this->getJson("/api/v1/products/{$synced->id}")->assertOk()
            ->assertJsonPath('data.price', 240000)->assertJsonPath('data.virtual_inventory_quantity', 5);
        $this->putJson("/api/v1/products/{$synced->id}", [
            'name' => 'Local edit', 'selling_price' => 250000, 'initial_quantity' => 2,
        ])->assertOk()->assertJsonPath('data.pancake_product_id', $source['id'])
            ->assertJsonPath('data.pancake_full_data.average_imported_price', 60000);
        $this->putJson("/api/v1/products/{$synced->id}", ['pancake_product_id' => 'forged'])
            ->assertUnprocessable()->assertJsonValidationErrors('pancake_product_id');
        // Even an admin cannot move an upstream variation away from its sync key.
        $this->actingAs($this->actor('admin'), 'api');
        $this->putJson("/api/v1/products/{$synced->id}", ['shop_id' => $this->otherShop->id])
            ->assertUnprocessable()->assertJsonValidationErrors('shop_id');
        $source['retail_price'] = 260000;
        (new GetProductByShopJob([$source], $this->shop->id))->handle();
        $this->assertSame($source, $synced->fresh()->pancake_full_data);
        $this->assertSame('Synced', $synced->fresh()->name);
        $this->assertNull(Product::findOrFail($localId)->pancake_product_id);
        $this->assertEquals(125000, Product::findOrFail($localId)->price);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_display_floor_is_consistent_and_never_writes_physical_stock(): void
    {
        foreach ([0 => 5, 3 => 5, 5 => 5, 20 => 20] as $source => $display) {
            $product = Product::create([
                'shop_id' => $this->shop->id, 'name' => "Stock {$source}",
                'pancake_full_data' => ['remain_quantity' => $source, 'variations_warehouses' => [['remain_quantity' => $source]]],
            ]);
            $before = $product->fresh()->getRawOriginal();
            $this->getJson("/api/v1/products/{$product->id}")->assertOk()
                ->assertJsonPath('data.inventory_quantity', $source)
                ->assertJsonPath('data.virtual_inventory_quantity', $display);
            $this->assertSame($before, $product->fresh()->getRawOriginal());
        }
        $rows = $this->getJson('/api/v1/products')->assertOk()->json('data.products');
        foreach ($rows as $row) {
            $this->assertEquals(max(5, $row['inventory_quantity']), $row['virtual_inventory_quantity']);
        }
    }

    public function test_invalid_update_does_not_change_product(): void
    {
        $id = $this->postJson('/api/v1/products', $this->payload())->assertCreated()->json('data.id');
        $before = Product::findOrFail($id)->getRawOriginal();
        $this->putJson("/api/v1/products/{$id}", ['name' => '', 'selling_price' => -1, 'initial_quantity' => 1.5])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'selling_price', 'initial_quantity']);
        $this->assertSame($before, Product::findOrFail($id)->getRawOriginal());
    }

    private function payload(array $override = []): array
    {
        return array_replace(['shop_id' => $this->shop->id, 'name' => 'Local', 'selling_price' => 125000, 'initial_quantity' => 3], $override);
    }

    private function shop(string $name): Shop
    {
        return Shop::create(['name' => $name, 'pancake_shop_id' => 'upstream-'.$name, 'api_key' => 'test-only']);
    }

    private function actor(string $slug, bool $grant = true): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $actor = User::create([
            'role_id' => $role->id, 'name' => $slug, 'email' => uniqid().'@example.test', 'password' => 'test-only',
        ]);
        $actor->shops()->attach($this->shop->id);
        if ($grant) {
            $group = PermissionGroup::firstOrCreate(['name' => 'Products']);
            $permission = Permission::firstOrCreate(['slug' => 'list-product'], ['name' => 'Products', 'permission_group_id' => $group->id]);
            RolePermission::firstOrCreate(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }

        return $actor;
    }
}
