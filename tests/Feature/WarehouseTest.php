<?php

namespace Tests\Feature;

use App\Jobs\GetOrderByShopJob;
use App\Jobs\GetOrderFromWebhookJob;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Shop;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\CustomerEnteredSystemEventWriter;
use App\Services\OrderCreatedEventWriter;
use App\Services\WarehouseService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    private User $actor;

    private User $packer;

    private User $otherPacker;

    private Shop $shop;

    private Shop $otherShop;

    private Product $local;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'warehouse_testing', 'database.connections.warehouse_testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'warehouse.activated_at' => null]);
        DB::purge('warehouse_testing');
        DB::setDefaultConnection('warehouse_testing');
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_04_24_082539_create_permission_groups_table.php',
            '2026_04_24_082550_create_permissions_table.php',
            '2026_04_24_082551_create_roles_table.php',
            '2026_04_24_082556_create_role_permissions_table.php',
            '2026_04_27_084626_create_shops_table.php',
            '2026_04_27_093216_add_column_to_users_table.php',
            '2026_04_28_072537_create_shop_users_table.php',
            '2026_04_28_111056_create_customers_table.php',
            '2026_04_28_111057_create_orders_table.php',
            '2026_04_29_170341_add_column_to_orders_table.php',
            '2026_05_04_082209_add_slug_to_roles_table.php',
            '2026_05_11_161116_create_products_table.php',
            '2026_05_14_074248_add_assigned_user_id_to_customers_table.php',
            '2026_06_04_110133_add_order_count_to_customers_table.php',
            '2026_08_20_000000_create_activity_logs_table.php',
            '2026_08_27_000000_add_customer_journey_contract_to_activity_logs_table.php',
            '2026_09_12_000001_add_pancake_order_source_to_orders_table.php',
            '2026_09_12_000004_add_pancake_order_page_to_orders_table.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        Schema::table('customers', function (Blueprint $table) {
            $table->double('purchased_amount')->nullable();
        });
        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('province_id')->nullable();
        });
        (require database_path('migrations/2026_10_21_000001_add_local_pos_support.php'))->up();
        (require database_path('migrations/2026_10_21_000002_add_warehouse_completion_and_source_time.php'))->up();
        $this->shop = Shop::create(['name' => 'Assigned', 'pancake_shop_id' => 'shop-1', 'api_key' => 'test']);
        $this->otherShop = Shop::create(['name' => 'Other', 'pancake_shop_id' => 'shop-2', 'api_key' => 'test']);
        $this->actor = $this->actor('staff-sale');
        $this->local = Product::create(['shop_id' => $this->shop->id, 'name' => 'Local', 'pancake_full_data' => ['retail_price' => 250000, 'remain_quantity' => 20]]);
        $this->packer = $this->actor('warehouse-test');
        $this->otherPacker = $this->actor('warehouse-second');
        config(['warehouse.activated_at' => '2026-10-21T00:00:00+07:00']);
        Carbon::setTestNow(Carbon::parse('2026-10-21T12:00:00+07:00'));
        $this->actingAs($this->actor, 'api');
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_local_new_order_after_activation_enters_waiting_queue(): void
    {
        $id = $this->createLocal();
        $this->assertNotNull(Order::findOrFail($id)->warehouse_queued_at);
        $this->actingAs($this->packer, 'api')->getJson('/api/v1/warehouse/orders')->assertOk()
            ->assertJsonPath('data.module.enabled', true)->assertJsonPath('data.orders.0.id', $id)
            ->assertJsonPath('data.orders.0.origin', 'local_pos');
    }

    public function test_new_pancake_order_without_tracking_is_admitted_by_bulk_and_webhook_without_legacy_side_effects(): void
    {
        $this->bulk([$this->source('bulk-no-tracking')]);
        $this->webhook($this->source('webhook-no-tracking'));
        $this->assertDatabaseCount('orders', 2);
        foreach (Order::all() as $order) {
            $this->assertSame('pancake', $order->origin);
            $this->assertSame(Order::WAREHOUSE_WAITING, $order->warehouse_status);
            $this->assertNotNull($order->warehouse_queued_at);
            $this->assertSame('2026-10-21T02:00:00+00:00', $order->source_created_at->utc()->toIso8601String());
            $this->assertNotNull($order->first_synced_at);
            $this->assertNull($order->order_number_vtp);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_old_or_late_synced_orders_never_enter_queue_even_if_updated_at_is_new(): void
    {
        $source = $this->source('old', ['inserted_at' => '2026-10-19T02:00:00Z', 'updated_at' => '2026-10-21T04:00:00Z', 'partner' => ['order_number_vtp' => 'real-old-tracking']]);
        $this->bulk([$source]);
        $order = Order::sole();
        $this->assertNull($order->warehouse_queued_at);
        $this->assertNull($order->warehouse_status);
        $source['inserted_at'] = '2026-10-21T03:00:00Z';
        $this->bulk([$source]);
        $this->assertNull($order->fresh()->warehouse_queued_at);
        $this->actingAs($this->packer, 'api')->getJson('/api/v1/warehouse/orders')->assertOk()->assertJsonCount(0, 'data.orders');
    }

    public function test_missing_invalid_future_or_conflicting_birth_time_cannot_admit_an_order(): void
    {
        Log::spy();
        foreach ([
            ['inserted_at' => null, 'updated_at' => '2026-10-21T02:00:00Z'],
            ['inserted_at' => 'tomorrow'],
            ['inserted_at' => '2026-02-31T01:00:00Z'],
            ['inserted_at' => '2026-10-22T02:00:00Z'],
            ['inserted_at' => '2026-10-20T02:00:00Z', 'created_at' => '2026-10-21T02:00:00Z'],
        ] as $i => $override) {
            $this->bulk([$this->source('invalid-'.$i, $override)]);
        }
        $this->assertDatabaseCount('orders', 0);
        Log::shouldHaveReceived('warning')->with('warehouse.review_required', \Mockery::on(fn ($context) => in_array($context['reason'], ['missing_source_created_at', 'invalid_source_created_at'])))->atLeast()->once();
    }

    public function test_cancelled_delivered_completed_and_unknown_new_orders_are_excluded(): void
    {
        foreach ([2, 3, 4, 5, 6, 7, 9, 15, 16, 99] as $status) {
            $this->bulk([$this->source('closed-'.$status, ['status' => $status])]);
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_pack_saves_actor_timestamp_history_once_and_never_changes_money_or_stock(): void
    {
        $id = $this->createLocal();
        $orderBefore = Order::findOrFail($id)->getRawOriginal();
        $stockBefore = $this->local->fresh()->getRawOriginal();
        $this->actingAs($this->packer, 'api')->postJson('/api/v1/warehouse/orders/'.$id.'/complete')
            ->assertOk()->assertJsonPath('replayed', false)->assertJsonPath('data.warehouse_status', 'packed')
            ->assertJsonPath('data.packed_by_user_id', $this->packer->id);
        $packed = Order::findOrFail($id);
        $this->assertNotNull($packed->packed_at);
        $firstTime = $packed->packed_at->toIso8601String();
        $this->actingAs($this->otherPacker, 'api')->postJson('/api/v1/warehouse/orders/'.$id.'/complete')
            ->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.packed_by_user_id', $this->packer->id);
        $this->assertSame($firstTime, $packed->fresh()->packed_at->toIso8601String());
        $this->assertSame(1, ActivityLog::where('action', 'warehouse.packed')->count());
        $after = $packed->fresh()->getRawOriginal();
        foreach (['warehouse_status', 'packed_at', 'packed_by_user_id', 'updated_at'] as $key) {
            unset($orderBefore[$key], $after[$key]);
        }
        $this->assertSame($orderBefore, $after);
        $this->assertSame($stockBefore, $this->local->fresh()->getRawOriginal());
        $this->getJson('/api/v1/warehouse/orders?status=waiting_packing')->assertOk()->assertJsonCount(0, 'data.orders');
        $this->getJson('/api/v1/warehouse/orders?status=packed')->assertOk()->assertJsonPath('data.orders.0.packed_by.name', $this->packer->name);
    }

    public function test_shop_scope_and_explicit_warehouse_permissions_are_enforced(): void
    {
        $id = $this->createLocal();
        $this->actingAs($this->actor, 'api')->postJson('/api/v1/warehouse/orders/'.$id.'/complete')->assertForbidden();
        $outsider = $this->actor('warehouse-outsider');
        $outsider->shops()->detach();
        $outsider->shops()->attach($this->otherShop->id);
        $this->actingAs($outsider, 'api');
        $this->getJson('/api/v1/warehouse/orders')->assertOk()->assertJsonCount(0, 'data.orders');
        $this->getJson('/api/v1/warehouse/orders?shop_id='.$this->shop->id)->assertForbidden();
        $this->getJson('/api/v1/warehouse/orders/'.$id)->assertForbidden();
        $this->postJson('/api/v1/warehouse/orders/'.$id.'/complete')->assertForbidden();
        $this->assertSame(0, ActivityLog::where('action', 'warehouse.packed')->count());
    }

    public function test_view_only_and_admin_without_explicit_permissions_cannot_complete(): void
    {
        $id = $this->createLocal();
        $viewOnly = $this->actor('warehouse-view-only');
        RolePermission::where('role_id', $viewOnly->role_id)->where('permission_id', Permission::where('slug', 'warehouse-pack')->value('id'))->delete();
        $this->actingAs($viewOnly, 'api')->getJson('/api/v1/warehouse/orders/'.$id)->assertOk();
        $this->postJson('/api/v1/warehouse/orders/'.$id.'/complete')->assertForbidden();
        $admin = $this->actor('admin');
        RolePermission::where('role_id', $admin->role_id)->delete();
        $this->actingAs($admin, 'api')->getJson('/api/v1/warehouse/orders')->assertForbidden();
        $this->postJson('/api/v1/warehouse/orders/'.$id.'/complete')->assertForbidden();
        $this->assertSame('waiting_packing', Order::findOrFail($id)->warehouse_status);
    }

    public function test_legacy_updated_at_fallback_is_never_used_as_warehouse_birth(): void
    {
        $this->bulk([$this->source('tracked-without-birth', ['inserted_at' => null,
            'updated_at' => '2026-10-21T03:00:00Z', 'partner' => ['order_number_vtp' => 'existing-tracked-source']])]);
        $order = Order::sole();
        $this->assertNotNull($order->created_at);
        $this->assertNull($order->source_created_at);
        $this->assertNull($order->warehouse_queued_at);
    }

    public function test_cancel_before_pack_removes_waiting_and_retry_does_not_requeue(): void
    {
        $source = $this->source('cancel-before');
        $this->webhook($source);
        $order = Order::sole();
        $source['status'] = 6;
        $this->webhook($source);
        $this->webhook($source);
        $this->assertNull($order->fresh()->warehouse_status);
        $this->assertSame(1, ActivityLog::where('action', 'warehouse.order_removed')->count());
        $this->actingAs($this->packer, 'api')->postJson('/api/v1/warehouse/orders/'.$order->id.'/complete')->assertStatus(409);
        $source['status'] = 0;
        $this->bulk([$source]);
        $this->assertNull($order->fresh()->warehouse_status);
    }

    public function test_partial_cancellation_preserves_money_items_and_original_birth(): void
    {
        $this->webhook($this->source('partial-cancel', ['cash' => 500, 'creator' => [],
            'assigning_seller' => ['id' => 'seller', 'name' => 'Original seller']]));
        $before = Order::sole();
        $this->assertSame('Original seller', $before->source_creator_name);
        $this->webhook(['id' => 'partial-cancel', 'shop_id' => $this->shop->pancake_shop_id, 'status' => 6]);
        $after = $before->fresh();
        $this->assertNull($after->warehouse_status);
        foreach (['cod', 'cash', 'created_at', 'source_created_at', 'first_synced_at', 'total_quantity', 'customer_address', 'user_creator_id'] as $field) {
            $this->assertEquals($before->$field, $after->$field, $field);
        }
        $this->assertSame($before->pancake_full_data['items'], $after->pancake_full_data['items']);
        $this->assertSame(1, ActivityLog::where('action', 'warehouse.order_removed')->count());
    }

    public function test_sync_after_pack_keeps_local_completion_and_original_creator(): void
    {
        $source = $this->source('packed-sync', ['creator' => ['id' => 'first-creator', 'name' => 'First']]);
        $this->webhook($source);
        $id = Order::sole()->id;
        $this->actingAs($this->packer, 'api')->postJson('/api/v1/warehouse/orders/'.$id.'/complete')->assertOk();
        $source['status'] = 6;
        $source['creator'] = ['id' => 'changed-creator'];
        $this->webhook($source);
        $this->bulk([$source]);
        $order = Order::findOrFail($id);
        $this->assertSame('packed', $order->warehouse_status);
        $this->assertSame('first-creator', $order->user_creator_id);
        $this->assertSame($this->packer->id, $order->packed_by_user_id);
        $this->assertSame(1, ActivityLog::where('action', 'warehouse.packed')->count());
    }

    public function test_module_disabled_or_future_preserves_legacy_import_and_does_not_backfill(): void
    {
        config(['warehouse.activated_at' => null]);
        $oldLocal = $this->createLocal();
        $this->bulk([$this->source('off-no-tracking')]);
        $this->assertDatabaseCount('orders', 1);
        $this->actingAs($this->packer, 'api')->getJson('/api/v1/warehouse/orders')->assertOk()->assertJsonPath('data.module.enabled', false);
        $this->postJson('/api/v1/warehouse/orders/'.$oldLocal.'/complete')->assertStatus(409);
        config(['warehouse.activated_at' => '2026-10-21T13:00:00+07:00']);
        $this->assertFalse(app(WarehouseService::class)->enabled());
        config(['warehouse.activated_at' => '2026-10-21T11:00:00+07:00']);
        $this->getJson('/api/v1/warehouse/orders')->assertOk()->assertJsonCount(0, 'data.orders');
        $this->assertNull(Order::findOrFail($oldLocal)->warehouse_queued_at);
        config(['warehouse.activated_at' => 'yesterday']);
        $this->getJson('/api/v1/warehouse/orders')->assertOk()->assertJsonPath('data.module.configuration_valid', false);
    }

    public function test_failed_audit_write_rolls_back_completion(): void
    {
        $id = $this->createLocal();
        $this->mock(ActivityLogService::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit failed'));
        $this->actingAs($this->packer, 'api')->postJson('/api/v1/warehouse/orders/'.$id.'/complete')->assertStatus(500);
        $this->assertSame('waiting_packing', Order::findOrFail($id)->warehouse_status);
        $this->assertNull(Order::findOrFail($id)->packed_at);
    }

    public function test_search_pagination_and_birth_timezone_are_consistent(): void
    {
        $this->bulk([$this->source('timezone', ['inserted_at' => '2026-10-21T01:00:00+07:00'])]);
        $id = Order::sole()->id;
        $this->actingAs($this->packer, 'api')->getJson('/api/v1/warehouse/orders?search=timezone&page_size=1')->assertOk()
            ->assertJsonPath('data.total_items', 1)->assertJsonPath('data.orders.0.id', $id);
        $this->getJson('/api/v1/warehouse/orders?page_size=101')->assertUnprocessable();
        $this->getJson('/api/v1/warehouse/orders?status=invalid')->assertUnprocessable();
    }

    public function test_migration_retry_preserves_existing_orders_and_permission_assignments(): void
    {
        $this->createLocal();
        $orders = DB::table('orders')->orderBy('id')->get()->toJson();
        $assignments = DB::table('role_permissions')->orderBy('id')->get()->toJson();
        $permissions = DB::table('permissions')->orderBy('id')->get()->toJson();
        // Simulate interrupted MySQL DDL: columns exist, but the index is missing.
        Schema::table('orders', fn (Blueprint $table) => $table->dropIndex('orders_warehouse_packed_index'));
        $migration = require database_path('migrations/2026_10_21_000002_add_warehouse_completion_and_source_time.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasIndex('orders', 'orders_warehouse_packed_index'));
        $this->assertSame($orders, DB::table('orders')->orderBy('id')->get()->toJson());
        $this->assertSame($assignments, DB::table('role_permissions')->orderBy('id')->get()->toJson());
        $this->assertSame($permissions, DB::table('permissions')->orderBy('id')->get()->toJson());
    }

    public function test_activation_never_enrolls_pos_sample_or_repeated_source_jobs(): void
    {
        $sample = Order::forceCreate(['id' => 349305, 'shop_id' => $this->shop->id, 'origin' => 'local_pos',
            'created_at' => '2026-10-20 15:07:39', 'warehouse_status' => 'waiting_packing',
            'warehouse_queued_at' => null, 'cod' => 6320000, 'cash' => 200000]);
        $before = $sample->refresh()->getRawOriginal();
        $source = $this->source('activation-retry', ['inserted_at' => '2026-10-21T02:00:00.928573']);
        $this->bulk([$source, $source]);
        $this->webhook($source);
        $this->bulk([$source]);
        $this->webhook($source);
        $this->assertDatabaseCount('orders', 2);
        $this->assertSame(1, Order::whereNotNull('warehouse_queued_at')->count());
        $this->assertSame($before, $sample->fresh()->getRawOriginal());
        $this->actingAs($this->packer, 'api')->getJson('/api/v1/warehouse/orders')->assertOk()
            ->assertJsonPath('data.total_items', 1);
        $this->getJson('/api/v1/warehouse/orders/349305')->assertNotFound();
        $this->postJson('/api/v1/warehouse/orders/349305/complete')->assertStatus(409);
        $this->assertSame($before, $sample->fresh()->getRawOriginal());
    }

    public function test_main_payment_and_customer_recency_sync_preserve_warehouse_snapshots(): void
    {
        (require database_path('migrations/2026_05_23_160750_create_loyalty_tiers_table.php'))->up();
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('prepaid_amount', 20, 2)->default(0);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dateTime('last_order_at')->nullable();
            $table->unsignedBigInteger('loyalty_tier_id')->nullable();
        });
        $customer = Customer::create([
            'shop_id' => $this->shop->id, 'pancake_customer_id' => 'merge-customer',
            'name' => 'Merge customer', 'last_order_at' => '2026-10-19 02:00:00',
        ]);
        $source = $this->source('merge-payment', [
            'prepaid' => 50000,
            'partner' => ['order_number_vtp' => 'merge-tracking'],
            'customer' => [
                'customer_id' => 'merge-customer', 'last_order_at' => '2026-10-21T02:00:00Z',
                'order_count' => 1, 'purchased_amount' => 0,
            ],
        ]);
        $this->bulk([$source]);
        $order = Order::sole();
        $this->assertEquals(50000, $order->prepaid_amount);
        $this->assertNotNull($order->warehouse_queued_at);
        $this->assertSame('2026-10-21 09:00:00', $customer->fresh()->last_order_at->format('Y-m-d H:i:s'));

        $this->webhook(array_replace($source, ['prepaid' => 70000]));
        $this->assertEquals(70000, $order->fresh()->prepaid_amount);
        // A status-only webhook must retain both the indexed payment and JSON snapshot.
        $this->webhook(['id' => $source['id'], 'shop_id' => $source['shop_id'], 'status' => 1]);
        $this->assertEquals(70000, $order->fresh()->prepaid_amount);
        $this->assertEquals(70000, $order->fresh()->pancake_full_data['prepaid']);
        $this->assertNotNull($order->fresh()->warehouse_queued_at);
        Http::assertNothingSent();
    }

    private function createLocal(): int
    {
        $this->actingAs($this->actor, 'api');

        return $this->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(), 'shop_id' => $this->shop->id,
            'customer' => ['name' => 'Test Local', 'phone' => '0901234567'], 'shipping_address' => 'Test only',
            'items' => [['product_id' => $this->local->id, 'quantity' => 2]], 'paid_amount' => 100,
        ])->assertCreated()->json('data.id');
    }

    private function source(string $id, array $override = []): array
    {
        return array_replace([
            'id' => $id, 'shop_id' => $this->shop->pancake_shop_id, 'inserted_at' => '2026-10-21T02:00:00Z',
            'status' => 0, 'cod' => 123000, 'cash' => 0,
            'creator' => ['id' => 'upstream-creator', 'name' => 'Pancake creator'],
            'items' => [['quantity' => 100, 'variation_info' => ['name' => 'Pancake product', 'images' => []]]],
            'bill_full_name' => 'Test Pancake', 'shipping_address' => ['full_address' => 'Test address'],
        ], $override);
    }

    private function bulk(array $sources): void
    {
        (new GetOrderByShopJob($sources, $this->shop->id))->handle(app(OrderCreatedEventWriter::class));
    }

    private function webhook(array $source): void
    {
        (new GetOrderFromWebhookJob($source))->handle(app(CustomerEnteredSystemEventWriter::class), app(OrderCreatedEventWriter::class));
    }

    private function actor(string $slug): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $user = User::create(['role_id' => $role->id, 'name' => $slug, 'email' => uniqid().'@test.local', 'password' => 'test-only']);
        $user->shops()->attach($this->shop->id);
        $group = PermissionGroup::firstOrCreate(['name' => 'Test']);
        foreach ($slug === 'staff-sale' ? ['list-product', 'list-customer'] : ['warehouse-view', 'warehouse-pack'] as $permissionSlug) {
            $permission = Permission::firstOrCreate(['slug' => $permissionSlug], ['permission_group_id' => $group->id, 'name' => $permissionSlug]);
            RolePermission::firstOrCreate(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }

        return $user;
    }
}
