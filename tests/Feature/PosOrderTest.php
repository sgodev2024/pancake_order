<?php

namespace Tests\Feature;

use App\Jobs\GetProductByShopJob;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Shop;
use App\Models\User;
use App\Services\AicrmOrderPayload;
use App\Services\OrderCreatedEventWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosOrderTest extends TestCase
{
    private User $actor;

    private Shop $shop;

    private Shop $otherShop;

    private Product $local;

    private Product $synced;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'pos_testing', 'database.connections.pos_testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'warehouse.activated_at' => null]);
        DB::purge('pos_testing');
        DB::setDefaultConnection('pos_testing');
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
        $this->runMigration();
        (require database_path('migrations/2026_11_05_000003_add_crm_shipping_address_to_customers.php'))->up();
        $this->shop = Shop::create(['name' => 'Assigned', 'pancake_shop_id' => 'shop-1', 'api_key' => 'test']);
        $this->otherShop = Shop::create(['name' => 'Other', 'pancake_shop_id' => 'shop-2', 'api_key' => 'test']);
        $this->actor = $this->actor('staff-sale');
        $this->local = $this->product('Local', null, 250000, 20);
        $this->synced = $this->product('Pancake', 'upstream-variation', 100000, 5);
        (require database_path('migrations/2026_11_04_000001_create_staff_account_states_table.php'))->up();
        $this->actingAs($this->actor, 'api');
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_pos_saves_a_source_from_its_shop_without_changing_order_origin(): void
    {
        Order::create([
            'shop_id' => $this->shop->id, 'origin' => 'pancake',
            'user_creator_id' => $this->actor->pancake_user_id,
            'pancake_order_page_id' => 'page-1', 'pancake_order_page_name' => 'Facebook Shop',
        ]);
        $this->postJson('/api/v1/orders', $this->payload(['order_page_id' => 'unknown']))
            ->assertUnprocessable()->assertJsonValidationErrors('order_page_id');
        $input = $this->payload(['order_page_id' => 'page-1']);
        $this->postJson('/api/v1/orders', $input)->assertCreated()
            ->assertJsonPath('data.origin', 'local_pos')
            ->assertJsonPath('data.pancake_order_page_id', 'page-1')
            ->assertJsonPath('data.order_page_name', 'Facebook Shop');
        $this->postJson('/api/v1/orders', $input)->assertOk()->assertJsonPath('replayed', true);
    }

    public function test_admin_can_soft_delete_multiple_new_pos_orders_atomically(): void
    {
        (require database_path('migrations/2026_11_04_000001_create_aicrm_shipments.php'))->up();
        $first = $this->postJson('/api/v1/orders', $this->payload())->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/orders', $this->payload())->assertCreated()->json('data.id');
        $pancake = Order::create(['shop_id' => $this->shop->id, 'origin' => 'pancake']);
        $url = '/api/v1/orders/pos/delete';

        $this->postJson($url, ['ids' => [$first]])->assertForbidden();
        $this->actingAs($this->actor('admin'), 'api');
        $this->postJson($url, ['ids' => [$first, $pancake->id]])->assertUnprocessable();
        Order::findOrFail($second)->forceFill(['warehouse_status' => Order::WAREHOUSE_PACKED])->save();
        $this->postJson($url, ['ids' => [$first, $second]])->assertConflict();
        $this->assertNotNull(Order::find($first));
        Order::findOrFail($second)->forceFill(['warehouse_status' => Order::WAREHOUSE_WAITING])->save();

        $this->postJson($url, ['ids' => [$first, $second]])->assertOk();
        $this->postJson($url, ['ids' => [$first]])->assertOk();
        $this->assertSoftDeleted('orders', ['id' => $first]);
        $this->assertSoftDeleted('orders', ['id' => $second]);
        $this->assertNotNull(Order::find($pancake->id));
    }

    private function runMigration(): void
    {
        (require database_path('migrations/2026_10_21_000001_add_local_pos_support.php'))->up();
    }

    public function test_creates_local_order_customer_item_snapshot_and_partial_payment_with_exact_cod(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->payload([
            'shipping_fee' => 30000, 'paid_amount' => 200000,
        ]))->assertCreated()->assertJsonPath('data.origin', 'local_pos')
            ->assertJsonPath('data.pancake_order_id', null)->assertJsonPath('data.order_number_vtp', null)
            ->assertJsonPath('data.warehouse_status', 'waiting_packing')->assertJsonPath('data.warehouse_queued_at', null)
            ->assertJsonPath('data.totals.total_amount', '530000.00')
            ->assertJsonPath('data.totals.paid_amount', '200000.00')
            ->assertJsonPath('data.totals.remaining_amount', '330000.00')
            ->assertJsonPath('data.totals.expected_cod', '330000.00')
            ->assertJsonPath('data.items.0.unit_price', '250000.00')
            ->assertJsonPath('data.created_by_user_id', $this->actor->id);
        $order = Order::findOrFail($response->json('data.id'));
        $this->assertEquals(200000, $order->cash);
        $this->assertEquals(330000, $order->cod);
        $customer = Customer::findOrFail($order->customer_id);
        $this->assertNull($customer->pancake_customer_id);
        $this->assertSame('Khách Local', $customer->name);
        $this->assertEquals(0, $customer->purchased_amount);
        $this->assertSame(0, $customer->order_count);
        $this->assertSame('valid', $order->pancake_full_data['payments'][0]['status']);
        $this->getJson('/api/v1/orders/'.$order->id)->assertOk()->assertJsonPath('data.reference', 'POS-'.$order->id);
        $this->getJson('/api/v1/customers?view=pos&shop_id='.$this->shop->id)->assertOk()
            ->assertJsonPath('data.customers.0.id', $customer->id);
        $this->assertDatabaseCount('activity_logs', 2);
        $this->assertDatabaseHas('activity_logs', ['action' => 'order.created', 'subject_id' => (string) $order->id]);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_synced_product_at_floor_can_sell_100_and_sync_cannot_reset_local_counter(): void
    {
        $source = $this->synced->pancake_full_data;
        $this->postJson('/api/v1/orders', $this->payload(['items' => [
            ['product_id' => $this->synced->id, 'quantity' => 100, 'unit_price' => 100000],
        ]]))->assertCreated()->assertJsonPath('data.total_quantity', 100);
        $this->assertSame($source, $this->synced->fresh()->pancake_full_data);
        $this->assertEquals(5, $this->synced->fresh()->virtual_stock);
        $this->assertSame(5, $this->synced->fresh()->inventory_quantity);
        (new GetProductByShopJob([[
            'id' => 'upstream-variation', 'product' => ['name' => 'Updated from Pancake'],
            'retail_price' => 110000, 'remain_quantity' => 500,
        ]], $this->shop->id))->handle();
        $this->assertSame(500, $this->synced->fresh()->inventory_quantity);
        $this->assertEquals(5, $this->synced->fresh()->virtual_inventory_quantity);
        $this->assertSame(110000, $this->synced->fresh()->price);
        $this->postJson('/api/v1/orders', $this->payload(['items' => [
            ['product_id' => $this->synced->id, 'quantity' => 10, 'unit_price' => 110000],
        ]]))->assertCreated();
        $this->assertEquals(5, $this->synced->fresh()->virtual_stock);
        $this->assertSame(500, $this->synced->fresh()->inventory_quantity);
    }

    public function test_virtual_decrement_floors_and_does_not_mutate_physical_payload(): void
    {
        foreach ([[100, 20, 80], [10, 10, 5], [5, 10, 5], [5, 100, 5]] as [$before, $sold, $after]) {
            $product = $this->product('Stock', null, 1, $before);
            $raw = $product->pancake_full_data;
            $this->postJson('/api/v1/orders', $this->payload(['items' => [['product_id' => $product->id, 'quantity' => $sold]]]))->assertCreated();
            $this->assertEquals($after, $product->fresh()->virtual_inventory_quantity);
            $this->assertSame($raw, $product->fresh()->pancake_full_data);
        }
    }

    public function test_full_payment_discount_shipping_surcharge_and_no_cod_are_exact(): void
    {
        $this->local->update(['pancake_full_data' => ['retail_price' => 0.10, 'remain_quantity' => 20]]);
        $this->postJson('/api/v1/orders', $this->payload([
            'items' => [['product_id' => $this->local->id, 'quantity' => 3, 'unit_price' => '0.10']],
            'discount_amount' => '0.10', 'shipping_fee' => '0.20', 'surcharge' => '0.30',
            'paid_amount' => '0.70', 'payment_method' => 'bank_transfer',
        ]))->assertCreated()->assertJsonPath('data.totals.total_amount', '0.70')
            ->assertJsonPath('data.totals.remaining_amount', '0.00')->assertJsonPath('data.totals.expected_cod', '0.00');
        $this->postJson('/api/v1/orders', $this->payload(['cod_mode' => 'none']))
            ->assertCreated()->assertJsonPath('data.totals.expected_cod', '0.00')
            ->assertJsonPath('data.totals.remaining_amount', '0.20');
    }

    public function test_idempotent_replay_does_not_duplicate_order_customer_receipt_event_or_decrement(): void
    {
        $payload = $this->payload(['paid_amount' => 100]);
        $first = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('data.id');
        $this->local->update(['pancake_full_data' => ['retail_price' => 999, 'remain_quantity' => 1000]]);
        $this->postJson('/api/v1/orders', $payload)->assertOk()->assertJsonPath('replayed', true)
            ->assertJsonPath('data.id', $first)->assertJsonPath('data.items.0.unit_price', '250000.00');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('activity_logs', 2);
        $this->assertEquals(18, $this->local->fresh()->virtual_stock);
        $this->postJson('/api/v1/orders', array_replace($payload, ['paid_amount' => 101]))->assertStatus(409);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_pos_prefills_imported_address_and_saves_edits_without_changing_old_orders(): void
    {
        $customer = $this->customer($this->shop, $this->actor);
        $profile = ['address' => '', 'shop_customer_addresses' => [['full_address' => 'Địa chỉ đã đồng bộ']]];
        $customer->update(['pancake_full_data' => $profile]);
        $options = '/api/v1/customers?view=pos&shop_id='.$this->shop->id;
        $this->getJson($options)->assertOk()->assertJsonPath('data.customers.0.address', 'Địa chỉ đã đồng bộ');

        $first = $this->payload(['customer_id' => $customer->id, 'shipping_address' => 'Địa chỉ mới']);
        $id = $this->postJson('/api/v1/orders', $first)->assertCreated()
            ->assertJsonPath('data.customer_id', $customer->id)->json('data.id');
        $this->getJson($options)->assertOk()->assertJsonPath('data.customers.0.address', 'Địa chỉ mới');
        $this->assertSame($profile, $customer->fresh()->pancake_full_data);

        $this->postJson('/api/v1/orders', $this->payload([
            'customer_id' => $customer->id, 'shipping_address' => 'Địa chỉ mới hơn',
        ]))->assertCreated();
        $this->postJson('/api/v1/orders', $first)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame('Địa chỉ mới hơn', $customer->fresh()->crm_shipping_address);
        $customer->update(['name' => 'Changed profile', 'pancake_full_data' => []]);
        $this->getJson($options)->assertOk()->assertJsonPath('data.customers.0.address', 'Địa chỉ mới hơn');
        $this->getJson('/api/v1/orders/'.$id)->assertOk()
            ->assertJsonPath('data.customer_name', 'Existing')->assertJsonPath('data.customer_address', 'Địa chỉ mới');
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_tampering_price_shop_product_customer_and_permissions_is_rejected(): void
    {
        $this->postJson('/api/v1/orders', $this->payload(['shop_id' => $this->otherShop->id]))->assertForbidden();
        $foreign = $this->product('Foreign', null, 1, 5, $this->otherShop);
        $this->postJson('/api/v1/orders', $this->payload(['items' => [['product_id' => $foreign->id, 'quantity' => 1]]]))->assertUnprocessable();
        $this->postJson('/api/v1/orders', $this->payload(['items' => [['product_id' => $this->local->id, 'quantity' => 1, 'unit_price' => 1]]]))->assertUnprocessable();
        $otherCustomer = $this->customer($this->otherShop, $this->actor);
        $this->postJson('/api/v1/orders', $this->payload(['customer_id' => $otherCustomer->id, 'customer' => null]))->assertUnprocessable();
        $anotherActor = $this->actor('staff-sale');
        $privateCustomer = $this->customer($this->shop, $anotherActor);
        $this->postJson('/api/v1/orders', $this->payload(['customer_id' => $privateCustomer->id, 'customer' => null]))->assertForbidden();
        $this->getJson('/api/v1/customers?view=pos&shop_id='.$this->otherShop->id)->assertForbidden();
        $this->getJson('/api/v1/products?shop_id='.$this->otherShop->id.'&search=Foreign')->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
        $this->assertNull($this->local->fresh()->virtual_stock);
        $this->actingAs($this->actor('staff-cskh'), 'api');
        $this->postJson('/api/v1/orders', $this->payload())->assertForbidden();
        $noPermission = $this->actor('staff-sale-no-permission', false);
        $this->actingAs($noPermission, 'api');
        $this->postJson('/api/v1/orders', $this->payload())->assertForbidden();
    }

    public function test_negative_overpayment_overdiscount_empty_cart_and_duplicate_products_reject_without_writes(): void
    {
        foreach ([['paid_amount' => -1], ['paid_amount' => 500001], ['discount_amount' => 500001], ['shipping_fee' => -1],
            ['surcharge' => -1], ['paid_amount' => '0.001'], ['items' => []], ['items' => [
                ['product_id' => $this->local->id, 'quantity' => 1], ['product_id' => $this->local->id, 'quantity' => 1],
            ]], ['items' => [['product_id' => $this->local->id, 'quantity' => 0]]],
        ] as $override) {
            $this->postJson('/api/v1/orders', $this->payload($override))->assertUnprocessable();
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertNull($this->local->fresh()->virtual_stock);
    }

    public function test_transaction_rolls_back_customer_order_and_stock_if_event_fails(): void
    {
        $this->mock(OrderCreatedEventWriter::class)->shouldReceive('write')->once()->andThrow(new \RuntimeException('Test failure'));
        $this->postJson('/api/v1/orders', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertNull($this->local->fresh()->virtual_stock);
    }

    public function test_warehouse_queue_respects_activation_and_never_enrolls_historical_orders(): void
    {
        $old = $this->postJson('/api/v1/orders', $this->payload())->assertCreated()->json('data.id');
        config(['warehouse.activated_at' => now()->subMinute()->toIso8601String()]);
        $new = $this->postJson('/api/v1/orders', $this->payload())->assertCreated()->json('data.id');
        $this->assertNull(Order::findOrFail($old)->warehouse_queued_at);
        $this->assertNotNull(Order::findOrFail($new)->warehouse_queued_at);
        config(['warehouse.activated_at' => now()->addDay()->toIso8601String()]);
        $this->postJson('/api/v1/orders', $this->payload())->assertCreated()->assertJsonPath('data.warehouse_queued_at', null);
    }

    public function test_order_detail_is_scoped_and_search_by_product_code_does_not_expand_shop_scope(): void
    {
        $this->synced->update(['pancake_full_data' => ['display_id' => 'SKU-UNIQUE', 'retail_price' => 100000, 'remain_quantity' => 5]]);
        $this->getJson('/api/v1/products?search=SKU-UNIQUE')->assertOk()->assertJsonCount(1, 'data.products');
        $id = $this->postJson('/api/v1/orders', $this->payload())->assertCreated()->json('data.id');
        $outsider = $this->actor('staff-sale');
        $outsider->shops()->detach();
        $outsider->shops()->attach($this->otherShop->id);
        $this->actingAs($outsider, 'api');
        $this->getJson('/api/v1/orders/'.$id)->assertForbidden();
    }

    public function test_local_total_in_existing_list_is_correct_even_when_cod_collection_is_disabled(): void
    {
        $id = $this->postJson('/api/v1/orders', $this->payload(['cod_mode' => 'none', 'paid_amount' => 100000]))->assertCreated()->json('data.id');
        $this->getJson('/api/v1/orders?view=summary&page=1')->assertOk()
            ->assertJsonPath('data.orders.0.order_origin', 'local_pos')
            ->assertJsonPath('data.orders.0.pos_total_amount', '500000.00')
            ->assertJsonPath('data.total_revenue', 500000);
        $this->getJson('/api/v1/orders?view=summary&page=1&search=POS-'.$id)->assertOk()
            ->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.id', $id);
    }

    public function test_second_new_order_reuses_accessible_local_customer_with_same_phone(): void
    {
        $first = $this->postJson('/api/v1/orders', $this->payload())->assertCreated()->json('data.customer_id');
        $this->postJson('/api/v1/orders', $this->payload())->assertCreated()->assertJsonPath('data.customer_id', $first);
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('activity_logs', 3);
    }

    public function test_pos_payment_and_total_remain_correct_in_the_main_overview(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('prepaid_amount', 20, 2)->default(0);
        });
        $id = $this->postJson('/api/v1/orders', $this->payload([
            'cod_mode' => 'none', 'paid_amount' => 100000,
        ]))->assertCreated()->json('data.id');

        $this->assertEquals(100000, Order::findOrFail($id)->prepaid_amount);
        $this->getJson('/api/v1/orders?view=summary&totals_only=1')->assertOk()
            ->assertJsonPath('data.total_revenue', 500000)
            ->assertJsonPath('data.total_prepaid_amount', 100000)
            ->assertJsonPath('data.total_items', 1)
            ->assertJsonCount(0, 'data.orders');
        Http::assertNothingSent();
    }

    public function test_one_order_can_mix_local_and_pancake_products(): void
    {
        $this->postJson('/api/v1/orders', $this->payload(['items' => [
            ['product_id' => $this->local->id, 'quantity' => 2],
            ['product_id' => $this->synced->id, 'quantity' => 1],
        ]]))->assertCreated()->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.pancake_product_id', null)
            ->assertJsonPath('data.items.1.pancake_product_id', 'upstream-variation')
            ->assertJsonPath('data.totals.total_amount', '600000.00');
        $this->assertEquals(18, $this->local->fresh()->virtual_stock);
        $this->assertEquals(5, $this->synced->fresh()->virtual_stock);
    }

    private function payload(array $override = []): array
    {
        $data = array_replace([
            'idempotency_key' => (string) Str::uuid(), 'shop_id' => $this->shop->id,
            'customer' => ['name' => 'Khách Local', 'phone' => '0901234567'], 'shipping_address' => '12 Nguyễn Huệ',
            'items' => [['product_id' => $this->local->id, 'quantity' => 2, 'unit_price' => $this->local->price]],
            'discount_amount' => 0, 'shipping_fee' => 0, 'surcharge' => 0, 'paid_amount' => 0,
            'payment_method' => 'cash', 'cod_mode' => 'remaining',
        ], $override);
        if (isset($data['customer_id'])) {
            unset($data['customer']);
        }

        return $data;
    }

    private function product(string $name, ?string $upstreamId, int|float $price, int $stock, ?Shop $shop = null): Product
    {
        return Product::create(['shop_id' => ($shop ?? $this->shop)->id, 'name' => $name, 'pancake_product_id' => $upstreamId,
            'pancake_full_data' => ['retail_price' => $price, 'remain_quantity' => $stock, 'variations_warehouses' => [['remain_quantity' => $stock]]]]);
    }

    private function customer(Shop $shop, User $actor): Customer
    {
        return Customer::create(['shop_id' => $shop->id, 'name' => 'Existing', 'pancake_customer_id' => (string) Str::uuid(),
            'phone_numbers' => '0902222222', 'assigned_user_id' => $actor->pancake_user_id]);
    }

    public function test_custom_sales_role_requires_explicit_pos_permission_and_both_read_permissions(): void
    {
        $custom = $this->actor('sales-custom');
        $this->actingAs($custom, 'api');
        $this->postJson('/api/v1/orders', $this->payload())->assertForbidden();
        $migration = require database_path('migrations/2026_10_22_000001_add_explicit_pos_permission.php');
        $grants = DB::table('role_permissions')->orderBy('id')->get()->toJson();
        $migration->up();
        $migration->up();
        $this->assertSame($grants, DB::table('role_permissions')->orderBy('id')->get()->toJson());
        $permission = Permission::where('slug', 'pos-create')->sole();
        RolePermission::create(['role_id' => $custom->role_id, 'permission_id' => $permission->id]);
        $this->postJson('/api/v1/orders', $this->payload())->assertCreated();
        $this->postJson('/api/v1/orders', $this->payload(['shop_id' => $this->otherShop->id]))->assertForbidden();
        foreach (['list-product', 'list-customer'] as $slug) {
            $id = Permission::where('slug', $slug)->sole()->id;
            RolePermission::where('role_id', $custom->role_id)->where('permission_id', $id)->delete();
            $this->postJson('/api/v1/orders', $this->payload())->assertForbidden();
            RolePermission::create(['role_id' => $custom->role_id, 'permission_id' => $id]);
        }
        $this->assertDatabaseCount('orders', 1);
        Http::assertNothingSent();
    }

    public function test_staff_pos_migration_is_minimal_idempotent_and_keeps_existing_roles(): void
    {
        $cskh = $this->actor('staff-cskh', false);
        $manager = $this->actor('manager-cskh');
        $admin = $this->actor('admin');
        $warehouse = $this->actor('warehouse-local-test');
        (require database_path('migrations/2026_10_22_000001_add_explicit_pos_permission.php'))->up();
        $untouched = DB::table('role_permissions')->whereIn('role_id', [$admin->role_id, $warehouse->role_id])
            ->orderBy('id')->get()->toJson();
        $rolesBefore = DB::table('roles')->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_11_04_000004_enable_staff_pos_permissions.php');
        $migration->up();
        $once = DB::table('role_permissions')->orderBy('id')->get()->toJson();
        $migration->up();
        $this->assertSame($once, DB::table('role_permissions')->orderBy('id')->get()->toJson());
        $this->assertSame($rolesBefore, DB::table('roles')->orderBy('id')->get()->toJson());
        $this->assertSame($untouched, DB::table('role_permissions')->whereIn('role_id', [$admin->role_id, $warehouse->role_id])
            ->orderBy('id')->get()->toJson());
        $this->assertSame(['list-customer', 'list-product'], $this->actor->role->permissions()->pluck('slug')->sort()->values()->all());
        foreach ([$cskh, $manager] as $actor) {
            $this->assertSame(['list-customer', 'list-product', 'pos-create'], $actor->role->permissions()->pluck('slug')->sort()->values()->all());
        }
        foreach ([$this->actor, $cskh, $manager, $admin] as $actor) {
            $this->actingAs($actor, 'api');
            $this->postJson('/api/v1/orders', $this->payload())->assertCreated()->assertJsonPath('data.created_by_user_id', $actor->id);
            $this->postJson('/api/v1/orders', $this->payload(['shop_id' => $this->otherShop->id]))
                ->assertStatus($actor->isAdmin() ? 422 : 403);
        }
        $this->assertDatabaseCount('orders', 4);
        Http::assertNothingSent();
    }

    public function test_staff_pos_migration_rolls_back_all_grants_when_required_role_is_missing(): void
    {
        $this->actor('staff-cskh', false);
        (require database_path('migrations/2026_10_22_000001_add_explicit_pos_permission.php'))->up();
        $before = DB::table('role_permissions')->orderBy('id')->get()->toJson();
        try {
            (require database_path('migrations/2026_11_04_000004_enable_staff_pos_permissions.php'))->up();
            $this->fail('Missing manager-cskh must fail the entire permission migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('manager-cskh', $exception->getMessage());
        }
        $this->assertSame($before, DB::table('role_permissions')->orderBy('id')->get()->toJson());
    }

    public function test_staff_cskh_can_use_shop_customers_in_pos_but_cannot_cross_shops_or_forge_creator(): void
    {
        $cskh = $this->actor('staff-cskh');
        (require database_path('migrations/2026_10_22_000001_add_explicit_pos_permission.php'))->up();
        RolePermission::create(['role_id' => $cskh->role_id, 'permission_id' => Permission::where('slug', 'pos-create')->sole()->id]);
        $this->customer($this->shop, $cskh);
        $shopCustomer = $this->customer($this->shop, $this->actor);
        $outside = $this->customer($this->otherShop, $this->actor);
        $shopCustomer->update(['phone_numbers' => '+84 912.345.678']);
        Order::create([
            'shop_id' => $this->shop->id, 'origin' => 'pancake',
            'user_creator_id' => $this->actor->pancake_user_id,
            'pancake_order_page_id' => 'shared-page', 'pancake_order_page_name' => 'Shared source',
        ]);
        $this->actingAs($cskh, 'api');
        $this->getJson('/api/v1/order-pages?context=pos&shop_id='.$this->shop->id)
            ->assertOk()->assertJsonPath('data.0.id', 'shared-page');
        $this->getJson('/api/v1/order-pages?context=pos&shop_id='.$this->otherShop->id)->assertForbidden();
        foreach (['0912345678', '+84 912-345-678'] as $phone) {
            $this->getJson('/api/v1/customers?view=pos&shop_id='.$this->shop->id.'&search='.urlencode($phone))
                ->assertOk()->assertJsonCount(1, 'data.customers')->assertJsonPath('data.customers.0.id', $shopCustomer->id);
        }
        $this->getJson('/api/v1/customers?view=pos&shop_id='.$this->shop->id)->assertOk()
            ->assertJsonCount(2, 'data.customers')->assertJsonFragment(['id' => $shopCustomer->id]);
        $this->getJson('/api/v1/customers?view=pos&shop_id='.$this->otherShop->id)->assertForbidden();
        $this->postJson('/api/v1/orders', $this->payload(['customer_id' => $outside->id]))->assertUnprocessable();
        foreach (['user_id', 'created_by_user_id', 'user_creator_id', 'user_assigning_seller_id', 'role_id', 'permissions'] as $field) {
            $this->postJson('/api/v1/orders', $this->payload([$field => $field === 'permissions' ? ['pos-create'] : 1]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('orders', 1);
        $this->postJson('/api/v1/orders', $this->payload(['customer_id' => $shopCustomer->id, 'order_page_id' => 'shared-page']))->assertCreated()
            ->assertJsonPath('data.created_by_user_id', $cskh->id)->assertJsonPath('data.customer_id', $shopCustomer->id)
            ->assertJsonPath('data.order_page_name', 'Shared source');
        Http::assertNothingSent();
    }

    public function test_simplified_payment_modes_work_for_sale_cskh_and_admin_with_authoritative_cod(): void
    {
        $cskh = $this->actor('staff-cskh');
        (require database_path('migrations/2026_10_22_000001_add_explicit_pos_permission.php'))->up();
        RolePermission::create(['role_id' => $cskh->role_id, 'permission_id' => Permission::where('slug', 'pos-create')->sole()->id]);
        $admin = $this->actor('admin');
        config(['aicrm.mode' => 'mock', 'aicrm.shipping_provider_id' => 42,
            'warehouse.activated_at' => now()->subDay()->toIso8601String()]);
        foreach ([$this->actor, $cskh, $admin] as $actor) {
            $this->actingAs($actor, 'api');
            foreach (['cash', 'bank_transfer', 'cod'] as $mode) {
                $input = $this->simplePaymentPayload($mode, [
                    'discount_amount' => 10000, 'shipping_fee' => 30000, 'surcharge' => 5000,
                ]);
                if ($mode !== 'cod') {
                    $input += ['payment_confirmed' => true, 'confirmed_total' => '525000.00'];
                }
                $paid = $mode === 'cod' ? '0.00' : '525000.00';
                $cod = $mode === 'cod' ? '525000.00' : '0.00';
                $id = $this->postJson('/api/v1/orders', $input)->assertCreated()
                    ->assertJsonPath('data.created_by_user_id', $actor->id)
                    ->assertJsonPath('data.totals.total_amount', '525000.00')
                    ->assertJsonPath('data.totals.paid_amount', $paid)
                    ->assertJsonPath('data.totals.remaining_amount', $cod)
                    ->assertJsonPath('data.totals.expected_cod', $cod)->json('data.id');
                $order = Order::findOrFail($id);
                $this->assertSame('waiting_packing', $order->warehouse_status);
                $this->assertNotNull($order->warehouse_queued_at);
                $this->assertEquals($paid, $order->cash);
                $this->assertEquals($cod, $order->cod);
                $this->assertSame($mode, $order->pancake_full_data['payment_mode']);
                if ($mode === 'cod') {
                    $this->assertSame([], $order->pancake_full_data['payments']);
                    $this->assertFalse($order->pancake_full_data['payment_confirmed']);
                } else {
                    $this->assertSame($mode, $order->pancake_full_data['payments'][0]['method']);
                    $this->assertSame($actor->id, $order->pancake_full_data['payments'][0]['recorded_by_user_id']);
                }
                // Payload formatting only. No shipment creation or external HTTP.
                $shipping = app(AicrmOrderPayload::class)->make($order);
                $this->assertEquals(525000, $shipping['totalPrice']);
                $this->assertEquals($cod, $shipping['moneyCollection']);
                $this->postJson('/api/v1/orders', $input)->assertOk()
                    ->assertJsonPath('replayed', true)->assertJsonPath('data.id', $id);
            }
        }
        $this->assertDatabaseCount('orders', 9);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_full_receipt_requires_explicit_confirmation_and_matching_server_total_without_writes(): void
    {
        foreach (['cash', 'bank_transfer'] as $mode) {
            foreach ([[], ['payment_confirmed' => false, 'confirmed_total' => 500000],
                ['payment_confirmed' => true], ['payment_confirmed' => true, 'confirmed_total' => 1],
                ['payment_confirmed' => true, 'confirmed_total' => 500001],
            ] as $confirmation) {
                $this->postJson('/api/v1/orders', $this->simplePaymentPayload($mode, $confirmation))->assertUnprocessable();
            }
        }
        $changedPrice = $this->simplePaymentPayload('cash', ['payment_confirmed' => true, 'confirmed_total' => 500000]);
        unset($changedPrice['items'][0]['unit_price']);
        $this->local->update(['pancake_full_data' => ['retail_price' => 300000, 'remain_quantity' => 20]]);
        $this->postJson('/api/v1/orders', $changedPrice)->assertUnprocessable()->assertJsonValidationErrors('confirmed_total');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertNull($this->local->fresh()->virtual_stock);
        Http::assertNothingSent();
    }

    public function test_simplified_payment_cannot_mix_legacy_amounts_or_claim_cod_was_received(): void
    {
        foreach (['cash', 'bank_transfer', 'cod'] as $mode) {
            $base = $this->simplePaymentPayload($mode);
            if ($mode !== 'cod') {
                $base += ['payment_confirmed' => true, 'confirmed_total' => 500000];
            }
            foreach (['paid_amount' => 1, 'payment_method' => 'cash', 'cod_mode' => 'none'] as $field => $value) {
                $this->postJson('/api/v1/orders', $base + [$field => $value])
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->postJson('/api/v1/orders', $this->simplePaymentPayload('cod', ['payment_confirmed' => true]))->assertUnprocessable();
        $this->postJson('/api/v1/orders', $this->simplePaymentPayload('cod', ['confirmed_total' => 500000]))->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_empty_adjustments_are_zero_for_all_new_modes_and_legacy_partial_payment(): void
    {
        foreach (['cash', 'bank_transfer', 'cod'] as $mode) {
            foreach ([['', null, '  '], [null, '', null]] as [$discount, $shipping, $surcharge]) {
                $input = $this->simplePaymentPayload($mode, [
                    'discount_amount' => $discount, 'shipping_fee' => $shipping, 'surcharge' => $surcharge,
                ]);
                if ($mode !== 'cod') {
                    $input += ['payment_confirmed' => true, 'confirmed_total' => 500000];
                }
                $this->postJson('/api/v1/orders', $input)->assertCreated()
                    ->assertJsonPath('data.totals.discount_amount', '0.00')
                    ->assertJsonPath('data.totals.shipping_fee', '0.00')
                    ->assertJsonPath('data.totals.surcharge', '0.00')
                    ->assertJsonPath('data.totals.total_amount', '500000.00');
            }
        }
        $this->postJson('/api/v1/orders', $this->payload([
            'discount_amount' => '', 'shipping_fee' => null, 'surcharge' => '', 'paid_amount' => 100000,
        ]))->assertCreated()->assertJsonPath('data.totals.paid_amount', '100000.00')
            ->assertJsonPath('data.totals.expected_cod', '400000.00');
        Http::assertNothingSent();
    }

    public function test_optional_adjustments_still_reject_negative_malformed_or_excessive_money(): void
    {
        foreach (['discount_amount', 'shipping_fee', 'surcharge'] as $field) {
            foreach ([-1, 'abc', '1,50', '1.234', false, [], 1000000000000] as $value) {
                $this->postJson('/api/v1/orders', $this->simplePaymentPayload('cod', [$field => $value]))
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->postJson('/api/v1/orders', $this->simplePaymentPayload('cod', ['discount_amount' => 500001]))->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertNull($this->local->fresh()->virtual_stock);
    }

    public function test_new_payment_modes_preserve_exact_cents_and_never_modify_an_old_order(): void
    {
        $legacyId = $this->postJson('/api/v1/orders', $this->payload(['paid_amount' => 100000]))->assertCreated()->json('data.id');
        $legacyBefore = Order::findOrFail($legacyId)->getRawOriginal();
        $this->local->update(['pancake_full_data' => ['retail_price' => 0.10, 'remain_quantity' => 20]]);
        foreach (['cash', 'bank_transfer', 'cod'] as $mode) {
            $input = $this->simplePaymentPayload($mode, [
                'items' => [['product_id' => $this->local->id, 'quantity' => 3, 'unit_price' => '0.10']],
                'discount_amount' => '0.10', 'shipping_fee' => '0.20', 'surcharge' => '0.30',
            ]);
            if ($mode !== 'cod') {
                $input += ['payment_confirmed' => true, 'confirmed_total' => '0.70'];
            }
            $this->postJson('/api/v1/orders', $input)->assertCreated()->assertJsonPath('data.totals.total_amount', '0.70')
                ->assertJsonPath('data.totals.paid_amount', $mode === 'cod' ? '0.00' : '0.70')
                ->assertJsonPath('data.totals.expected_cod', $mode === 'cod' ? '0.70' : '0.00');
        }
        $this->assertSame($legacyBefore, Order::findOrFail($legacyId)->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_partial_transfer_records_receipt_and_only_collects_the_balance_as_cod(): void
    {
        config(['aicrm.shipping_provider_id' => 42]);
        $input = $this->simplePaymentPayload('bank_transfer_cod', ['shipping_fee' => 30000]);
        $input += ['deposit_amount' => '200000.00', 'payment_confirmed' => true, 'confirmed_total' => '530000.00'];
        $id = $this->postJson('/api/v1/orders', $input)->assertCreated()
            ->assertJsonPath('data.totals.total_amount', '530000.00')
            ->assertJsonPath('data.totals.paid_amount', '200000.00')
            ->assertJsonPath('data.totals.remaining_amount', '330000.00')
            ->assertJsonPath('data.totals.expected_cod', '330000.00')->json('data.id');
        $order = Order::findOrFail($id);
        $this->assertEquals(200000, $order->cash);
        $this->assertEquals(330000, $order->cod);
        $this->assertSame('bank_transfer', $order->pancake_full_data['payments'][0]['method']);
        $this->assertSame('bank_transfer_cod', $order->pancake_full_data['payment_mode']);
        $this->assertEquals(330000, app(AicrmOrderPayload::class)->make($order)['moneyCollection']);
        $this->postJson('/api/v1/orders', $input)->assertOk()->assertJsonPath('replayed', true);
        $input['deposit_amount'] = '200000';
        $this->postJson('/api/v1/orders', $input)->assertOk()->assertJsonPath('replayed', true);
        $input['deposit_amount'] = '210000';
        $this->postJson('/api/v1/orders', $input)->assertStatus(409);
        $this->assertDatabaseCount('orders', 1);
        Http::assertNothingSent();
    }

    public function test_partial_transfer_requires_valid_amount_and_confirmed_current_total(): void
    {
        $base = $this->simplePaymentPayload('bank_transfer_cod');
        $base += ['payment_confirmed' => true, 'confirmed_total' => '500000'];
        foreach ([null, 0, -1, 500000, 500001, 'abc', '1.234'] as $amount) {
            $this->postJson('/api/v1/orders', $base + ['deposit_amount' => $amount])->assertUnprocessable();
        }
        $input = $base + ['deposit_amount' => '200000'];
        $input['payment_confirmed'] = false;
        $this->postJson('/api/v1/orders', $input)->assertUnprocessable();
        unset($input['payment_confirmed']);
        $this->postJson('/api/v1/orders', $input)->assertUnprocessable();
        $input['payment_confirmed'] = true;
        $input['confirmed_total'] = '499999';
        $this->postJson('/api/v1/orders', $input)->assertUnprocessable();
        $input['confirmed_total'] = '500000';
        $input['payment_mode'] = 'cod';
        $this->postJson('/api/v1/orders', $input)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    private function simplePaymentPayload(string $mode, array $override = []): array
    {
        $payload = $this->payload($override);
        unset($payload['paid_amount'], $payload['payment_method'], $payload['cod_mode']);
        $payload['payment_mode'] = $mode;

        return $payload;
    }

    public function test_legacy_idempotency_hash_from_before_the_payment_change_remains_replayable(): void
    {
        $input = $this->payload(['paid_amount' => 100000]);
        $this->assertSame(1, $input['shop_id']);
        $this->assertSame(1, $input['items'][0]['product_id']);
        $id = $this->postJson('/api/v1/orders', $input)->assertCreated()->json('data.id');
        // Frozen digest produced by OrderService before the simplified payment change.
        DB::table('orders')->where('id', $id)->update([
            'request_hash' => '3545c56f558847624d0008aaf22428ad27c5c3c76f0e5120bab6959258498c8d',
        ]);
        $before = Order::findOrFail($id)->getRawOriginal();
        $this->postJson('/api/v1/orders', $input)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $id);
        $this->assertSame($before, Order::findOrFail($id)->getRawOriginal());
        $this->assertDatabaseCount('orders', 1);
    }

    private function actor(string $slug, bool $grant = true): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $user = User::create(['name' => $slug, 'role_id' => $role->id, 'email' => uniqid().'@test.local', 'password' => 'test-only']);
        $user->shops()->attach($this->shop->id);
        if ($grant) {
            $group = PermissionGroup::firstOrCreate(['name' => 'POS']);
            foreach (['list-product', 'list-customer'] as $slug) {
                $permission = Permission::firstOrCreate(['slug' => $slug], ['permission_group_id' => $group->id, 'name' => $slug]);
                RolePermission::firstOrCreate(['role_id' => $role->id, 'permission_id' => $permission->id]);
            }
        }

        return $user;
    }
}
