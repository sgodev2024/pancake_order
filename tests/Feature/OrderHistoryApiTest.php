<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderHistoryApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'order_history_testing',
            'database.connections.order_history_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('order_history_testing');
        DB::setDefaultConnection('order_history_testing');
        $this->createSchema();
    }

    public function test_history_uses_local_order_anchor_scopes_customer_and_returns_controlled_sorted_data(): void
    {
        $shop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $actor = $this->createUser('employee', 'Actor');
        $creator = $this->createUser('employee', 'Creator');
        $careStaff = $this->createUser('employee', 'Historical Care');
        $this->attachShop($actor, $shop);
        $this->grantPermission($actor, 'view-chance');

        $anchor = $this->createOrder($shop, [
            'pancake_order_id' => 'DUPLICATE-PANCAKE-ID',
            'pancake_customer_id' => 'CUSTOMER-1',
            'user_creator_id' => $creator->pancake_user_id,
            'user_assigning_seller_id' => $careStaff->pancake_user_id,
            'status' => 7,
            'status_vtp' => 'delivered',
            'order_number_vtp' => 'VTP-1',
            'total_quantity' => 2,
            'cod' => 125000,
            'created_at' => '2026-08-21 10:00:00',
            'pancake_full_data' => [
                'items' => [
                    [
                        'variation_info' => [
                            'name' => 'Canxi váng sữa',
                            'images' => ['https://cdn.example.test/canxi.jpg'],
                        ],
                        'quantity' => 1,
                    ],
                    [
                        'variation_info' => [
                            'name' => 'VITAMIN D3K2 DROP',
                            'images' => [],
                        ],
                        'quantity' => 2,
                    ],
                ],
                'secret' => 'must-not-leak',
            ],
        ]);
        $sameTimestampHigherId = $this->createOrder($shop, [
            'pancake_customer_id' => 'CUSTOMER-1',
            'created_at' => '2026-08-21 10:00:00',
        ]);
        $olderDuplicate = $this->createOrder($shop, [
            'pancake_order_id' => 'DUPLICATE-PANCAKE-ID',
            'pancake_customer_id' => 'CUSTOMER-1',
            'created_at' => '2026-08-20 10:00:00',
        ]);
        $newer = $this->createOrder($shop, [
            'pancake_customer_id' => 'CUSTOMER-1',
            'created_at' => '2026-08-22 10:00:00',
        ]);
        $deleted = $this->createOrder($shop, [
            'pancake_customer_id' => 'CUSTOMER-1',
            'created_at' => '2026-08-23 10:00:00',
        ]);
        $deleted->delete();
        $this->createOrder($otherShop, [
            'pancake_customer_id' => 'CUSTOMER-1',
            'created_at' => '2026-08-24 10:00:00',
        ]);
        $this->createOrder($shop, [
            'pancake_customer_id' => 'OTHER-CUSTOMER',
            'created_at' => '2026-08-25 10:00:00',
        ]);

        $before = $this->orderSnapshot();

        $response = $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/{$anchor->id}/history");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 30)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $sameTimestampHigherId->id)
            ->assertJsonPath('data.2.id', $anchor->id)
            ->assertJsonPath('data.3.id', $olderDuplicate->id)
            ->assertJsonPath('data.2.amount', 125000)
            ->assertJsonPath('data.2.creator.id', $creator->id)
            ->assertJsonPath('data.2.creator.name', 'Creator')
            ->assertJsonPath('data.2.products.0.name', 'Canxi váng sữa')
            ->assertJsonPath('data.2.products.0.image', 'https://cdn.example.test/canxi.jpg')
            ->assertJsonPath('data.2.products.0.quantity', 1)
            ->assertJsonPath('data.2.products.1.name', 'VITAMIN D3K2 DROP')
            ->assertJsonPath('data.2.products.1.image', null)
            ->assertJsonPath('data.2.products.1.quantity', 2);

        $this->assertSame([
            'id',
            'created_at',
            'amount',
            'creator',
            'products',
        ], array_keys($response->json('data.2')));
        $this->assertArrayNotHasKey('pancake_full_data', $response->json('data.2'));
        $this->assertSame($before, $this->orderSnapshot());
    }

    public function test_null_or_blank_customer_id_returns_only_the_exact_anchor(): void
    {
        $shop = $this->createShop();
        $actor = $this->createUser('employee', 'Actor');
        $this->attachShop($actor, $shop);
        $this->grantPermission($actor, 'view-chance');

        $nullAnchor = $this->createOrder($shop, ['pancake_customer_id' => null]);
        $this->createOrder($shop, ['pancake_customer_id' => null]);

        $nullResponse = $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/{$nullAnchor->id}/history");

        $nullResponse->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $nullAnchor->id);

        $blankAnchor = $this->createOrder($shop, ['pancake_customer_id' => '   ']);
        $this->createOrder($shop, ['pancake_customer_id' => '   ']);

        $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/{$blankAnchor->id}/history")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $blankAnchor->id);
    }

    public function test_non_admin_cannot_use_an_unauthorized_anchor(): void
    {
        $ownShop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $actor = $this->createUser('employee', 'Actor');
        $this->attachShop($actor, $ownShop);
        $this->grantPermission($actor, 'view-chance');
        $anchor = $this->createOrder($otherShop, ['pancake_customer_id' => 'CUSTOMER-1']);

        $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/{$anchor->id}/history")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_view_chance_permission_is_required_with_proper_forbidden_status(): void
    {
        $shop = $this->createShop();
        $actor = $this->createUser('employee', 'Actor');
        $this->attachShop($actor, $shop);
        $anchor = $this->createOrder($shop);

        $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/{$anchor->id}/history")
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'Bạn không có quyền.',
            ]);
    }

    public function test_admin_can_read_related_orders_across_shops(): void
    {
        $firstShop = $this->createShop('First Shop');
        $secondShop = $this->createShop('Second Shop');
        $admin = $this->createUser('admin', 'Admin');
        $this->grantPermission($admin, 'view-chance');
        $anchor = $this->createOrder($firstShop, ['pancake_customer_id' => 'CUSTOMER-1']);
        $related = $this->createOrder($secondShop, ['pancake_customer_id' => 'CUSTOMER-1']);

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/orders/{$anchor->id}/history")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['id' => $anchor->id])
            ->assertJsonFragment(['id' => $related->id]);
    }

    public function test_history_paginates_validates_bounds_and_returns_404_for_invalid_or_deleted_anchor(): void
    {
        $shop = $this->createShop();
        $actor = $this->createUser('employee', 'Actor');
        $this->attachShop($actor, $shop);
        $this->grantPermission($actor, 'view-chance');
        $anchor = $this->createOrder($shop, ['pancake_customer_id' => 'CUSTOMER-1']);

        for ($index = 0; $index < 30; $index++) {
            $this->createOrder($shop, ['pancake_customer_id' => 'CUSTOMER-1']);
        }

        $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/{$anchor->id}/history")
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 30)
            ->assertJsonPath('meta.total', 31)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(30, 'data');

        $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/{$anchor->id}/history?per_page=2&page=2")
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 31)
            ->assertJsonPath('meta.last_page', 16)
            ->assertJsonCount(2, 'data');

        $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/{$anchor->id}/history?per_page=101")
            ->assertStatus(422);

        $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/{$anchor->id}/history?page=0")
            ->assertStatus(422);

        $deletedAnchor = $this->createOrder($shop);
        $deletedAnchor->delete();

        $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/{$deletedAnchor->id}/history")
            ->assertNotFound();

        $this->actingAs($actor, 'api')
            ->getJson('/api/v1/orders/999999/history')
            ->assertNotFound();
    }

    private function createSchema(): void
    {
        Schema::create('permission_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('permission_group_id');
            $table->string('name');
            $table->string('slug');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('pancake_user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('shop_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_manager')->default(false);
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_order_id');
            $table->string('pancake_customer_id')->nullable();
            $table->string('order_number_vtp')->nullable();
            $table->integer('total_quantity')->nullable();
            $table->decimal('cod', 15, 2)->default(0);
            $table->string('user_creator_id')->nullable();
            $table->string('user_assigning_seller_id')->nullable();
            $table->integer('status')->default(3);
            $table->string('status_vtp')->nullable();
            $table->json('pancake_full_data')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    private function createUser(string $roleSlug, string $name): User
    {
        $role = Role::create(['name' => $roleSlug, 'slug' => $roleSlug]);
        $nextId = (int) User::query()->max('id') + 1;

        return User::create([
            'role_id' => $role->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name))."-{$nextId}@example.test",
            'password' => 'unused',
            'pancake_user_id' => "PANCAKE-{$nextId}",
        ]);
    }

    private function createShop(string $name = 'Shop'): Shop
    {
        return Shop::create(['name' => $name]);
    }

    private function attachShop(User $user, Shop $shop): void
    {
        $user->shops()->attach($shop->id, ['is_manager' => false]);
    }

    private function grantPermission(User $user, string $slug): void
    {
        $group = PermissionGroup::query()->first()
            ?? PermissionGroup::create(['name' => 'Security']);
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['permission_group_id' => $group->id, 'name' => $slug]
        );

        DB::table('role_permissions')->insert([
            'role_id' => $user->role_id,
            'permission_id' => $permission->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrder(Shop $shop, array $attributes = []): Order
    {
        $nextId = (int) Order::query()->withTrashed()->max('id') + 1;
        $timestamp = $attributes['created_at'] ?? now();

        $order = Order::create(array_merge([
            'shop_id' => $shop->id,
            'pancake_order_id' => "ORDER-{$nextId}",
            'pancake_customer_id' => "CUSTOMER-{$nextId}",
            'status' => 3,
        ], $attributes));

        $order->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->saveQuietly();

        return $order->fresh();
    }

    private function orderSnapshot(): array
    {
        return DB::table('orders')
            ->orderBy('id')
            ->get()
            ->map(fn ($order) => (array) $order)
            ->all();
    }
}
