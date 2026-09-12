<?php

namespace Tests\Feature;

use App\Enums\ActivityLogAction;
use App\Jobs\GetOrderByShopJob;
use App\Jobs\GetOrderFromWebhookJob;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerCare;
use App\Models\Order;
use App\Models\Shop;
use App\Services\OrderCreatedEventWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OrderJourneyEventTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Ho_Chi_Minh',
            'database.default' => 'order_journey_testing',
            'database.connections.order_journey_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('order_journey_testing');
        DB::setDefaultConnection('order_journey_testing');
        $this->createSchema();
        CarbonImmutable::setTestNow('2026-08-27 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_new_bulk_order_gets_one_event_with_local_identity_and_safe_metadata(): void
    {
        $shop = $this->createShop('Bulk Shop');
        $customer = $this->createCustomer($shop, 'PAN-CUSTOMER-1');
        $insertedAt = '2026-08-25T02:30:00Z';

        $this->runBulk($shop, [$this->orderData('PAN-ORDER-1', 'PAN-CUSTOMER-1', $insertedAt)]);

        $order = Order::query()->sole();
        $event = ActivityLog::query()->sole();

        $this->assertSame(ActivityLogAction::ORDER_CREATED->value, $event->action);
        $this->assertSame('order', $event->subject_type);
        $this->assertSame((string) $order->id, $event->subject_id);
        $this->assertSame($shop->id, $event->shop_id);
        $this->assertSame($order->pancake_order_id, $event->pancake_order_id);
        $this->assertSame('PAN-CUSTOMER-1', $event->pancake_customer_id);
        $this->assertSame('system', $event->source);
        $this->assertNull($event->actor_user_id);
        $this->assertSame(
            CarbonImmutable::parse($insertedAt, 'UTC')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            $event->occurred_at->format('Y-m-d H:i:s')
        );
        $this->assertSame("order.created:shop:{$shop->id}:order:{$order->id}", $event->idempotency_key);
        $this->assertSame($order->id, $event->metadata['order_id']);
        $this->assertSame($shop->id, $event->metadata['shop_id']);
        $this->assertSame($customer->id, $event->metadata['customer_id']);
        $this->assertSame('pancake_bulk_sync', $event->metadata['ingestion_path']);
        $this->assertSame(125000, $event->metadata['amount']);
        $this->assertSame(3, $event->metadata['quantity']);
        $this->assertSame([
            ['quantity' => 2, 'product_id' => 'PRODUCT-1', 'name' => 'Classic pancake'],
            ['quantity' => 1, 'product_id' => 'VARIATION-2', 'name' => 'Chocolate pancake'],
        ], $event->metadata['items']);
        $this->assertArrayNotHasKey('token', $event->metadata);
        $this->assertArrayNotHasKey('pancake_full_data', $event->metadata);
    }

    public function test_bulk_order_persists_string_source_snapshot_and_full_payload(): void
    {
        $shop = $this->createShop();
        $data = $this->orderData('PAN-ORDER-SOURCE-BULK', null);
        $data['order_sources'] = '-9';
        $data['order_sources_name'] = '  TikTok  ';

        $this->runBulk($shop, [$data]);

        $order = Order::query()->sole();

        $this->assertSame('-9', $order->pancake_order_source_id);
        $this->assertSame('TikTok', $order->pancake_order_source_name);
        $this->assertSame($data, $order->pancake_full_data);
    }

    public function test_webhook_order_persists_integer_source_id_as_string(): void
    {
        $shop = $this->createShop();
        $data = $this->orderData('PAN-ORDER-SOURCE-WEBHOOK', null);
        $data['shop_id'] = $shop->pancake_shop_id;
        $data['order_sources'] = -9;
        $data['order_sources_name'] = '  Tiktok  ';

        $this->runWebhook($data);

        $order = Order::query()->sole();

        $this->assertSame('-9', $order->pancake_order_source_id);
        $this->assertSame('Tiktok', $order->pancake_order_source_name);
        $this->assertSame($data, $order->pancake_full_data);
    }

    public function test_order_source_normalization_keeps_null_id_and_blanks_name_without_fallback(): void
    {
        $shop = $this->createShop('Must Not Be Used As Source');
        $data = $this->orderData('PAN-ORDER-SOURCE-NULL', null);
        $data['shop_id'] = $shop->pancake_shop_id;
        $data['order_sources'] = null;
        $data['order_sources_name'] = " \t\r\n ";
        $data['page'] = ['name' => 'Page fallback'];
        $data['ads_source'] = 'Ads fallback';

        $this->runWebhook($data);

        $order = Order::query()->sole();

        $this->assertNull($order->pancake_order_source_id);
        $this->assertNull($order->pancake_order_source_name);
    }

    public function test_repeated_bulk_sync_does_not_create_a_duplicate_order_or_event(): void
    {
        $shop = $this->createShop();
        $data = $this->orderData('PAN-ORDER-RETRY', null);

        $this->runBulk($shop, [$data]);
        $this->runBulk($shop, [$data]);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityLogAction::ORDER_CREATED->value)->count());
        $this->assertSame("PAN-ORDER-RETRY_{$shop->id}", Order::query()->sole()->pancake_order_id);
        $this->assertNull(ActivityLog::query()->sole()->metadata['customer_id']);
    }

    public function test_existing_bulk_order_gets_no_new_event_and_non_vtp_orders_stay_filtered(): void
    {
        $shop = $this->createShop();
        $existing = Order::create([
            'shop_id' => $shop->id,
            'pancake_order_id' => "PAN-ORDER-EXISTING_{$shop->id}",
            'order_number_vtp' => 'VTP-existing',
            'pancake_customer_id' => null,
            'status' => 3,
            'received_at_shop' => false,
        ]);

        $this->runBulk($shop, [
            $this->orderData('PAN-ORDER-EXISTING', null),
            $this->orderData('PAN-ORDER-NO-VTP', null, partnerOrderNumber: null),
        ]);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame($existing->id, Order::query()->sole()->id);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_two_new_bulk_orders_get_two_events_with_local_subject_ids(): void
    {
        $shop = $this->createShop();

        $this->runBulk($shop, [
            $this->orderData('PAN-ORDER-A', null),
            $this->orderData('PAN-ORDER-B', null),
        ]);

        $orders = Order::query()->orderBy('id')->get();
        $events = ActivityLog::query()->where('action', ActivityLogAction::ORDER_CREATED->value)->orderBy('id')->get();

        $this->assertCount(2, $orders);
        $this->assertCount(2, $events);
        $this->assertSame($orders->pluck('id')->map(fn ($id) => (string) $id)->all(), $events->pluck('subject_id')->all());
        $this->assertCount(2, $events->pluck('idempotency_key')->unique());
    }

    public function test_same_external_bulk_order_id_is_isolated_by_local_shop(): void
    {
        $firstShop = $this->createShop('First Shop', 'PAN-SHOP-1');
        $secondShop = $this->createShop('Second Shop', 'PAN-SHOP-2');

        $this->runBulk($firstShop, [$this->orderData('PAN-SHARED-ORDER', null)]);
        $this->runBulk($secondShop, [$this->orderData('PAN-SHARED-ORDER', null)]);

        $orders = Order::query()->orderBy('shop_id')->get();
        $events = ActivityLog::query()->where('action', ActivityLogAction::ORDER_CREATED->value)->orderBy('shop_id')->get();

        $this->assertSame(2, $orders->count());
        $this->assertSame([
            "PAN-SHARED-ORDER_{$firstShop->id}",
            "PAN-SHARED-ORDER_{$secondShop->id}",
        ], $orders->pluck('pancake_order_id')->all());
        $this->assertSame([$firstShop->id, $secondShop->id], $events->pluck('shop_id')->all());
        $this->assertNotSame($events[0]->idempotency_key, $events[1]->idempotency_key);
    }

    public function test_bulk_order_and_event_roll_back_together_when_activity_logging_fails(): void
    {
        $shop = $this->createShop();
        $writer = Mockery::mock(OrderCreatedEventWriter::class);
        $writer->shouldReceive('write')->once()->andThrow(new RuntimeException('activity log failed'));
        $this->app->instance(OrderCreatedEventWriter::class, $writer);

        $this->expectException(RuntimeException::class);

        try {
            $this->runBulk($shop, [$this->orderData('PAN-ORDER-ROLLBACK', null)]);
        } finally {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, ActivityLog::query()->count());
        }
    }

    public function test_new_webhook_order_gets_one_event_and_retry_does_not_duplicate_it(): void
    {
        $shop = $this->createShop('Webhook Shop');
        $customer = $this->createCustomer($shop, 'PAN-WEBHOOK-CUSTOMER');
        $data = $this->orderData('PAN-WEBHOOK-ORDER', 'PAN-WEBHOOK-CUSTOMER');
        $data['shop_id'] = $shop->pancake_shop_id;

        $this->runWebhook($data);
        $this->runWebhook($data);

        $order = Order::query()->sole();
        $event = ActivityLog::query()->sole();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame($customer->id, $event->metadata['customer_id']);
        $this->assertSame('pancake_order_webhook', $event->metadata['ingestion_path']);
        $this->assertSame((string) $order->id, $event->subject_id);
        $this->assertSame($order->created_at->format('Y-m-d H:i:s'), $event->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityLogAction::ORDER_CREATED->value)->count());
    }

    public function test_new_webhook_customer_care_copies_the_exact_order_address(): void
    {
        $shop = $this->createShop('Webhook Care Shop');
        $customer = $this->createCustomer($shop, 'PAN-WEBHOOK-CARE-CUSTOMER');
        $data = $this->orderData(
            'PAN-WEBHOOK-CARE-ADDRESS',
            $customer->pancake_customer_id,
            status: 2
        );
        $data['shop_id'] = $shop->pancake_shop_id;
        $data['shipping_address'] = [
            'full_address' => 'Authoritative order shipping address',
        ];
        $data['customer']['shop_customer_addresses'] = [];

        $this->runWebhook($data);

        $order = Order::query()->sole();
        $customerCare = CustomerCare::query()->sole();

        $this->assertSame('Authoritative order shipping address', $order->customer_address);
        $this->assertSame($order->customer_address, $customerCare->customer_addresss);
        $this->assertSame($order->pancake_order_id, $customerCare->pancake_order_id);
        $this->assertSame($shop->id, $customerCare->shop_id);
    }

    public function test_existing_webhook_order_update_gets_no_new_order_event(): void
    {
        $shop = $this->createShop();
        $this->createCustomer($shop, 'PAN-CUSTOMER-EXISTING');
        $first = $this->orderData('PAN-WEBHOOK-EXISTING', 'PAN-CUSTOMER-EXISTING');
        $first['shop_id'] = $shop->pancake_shop_id;
        $second = $first;
        $second['status'] = 2;
        $second['note'] = 'updated';

        $this->runWebhook($first);
        $this->runWebhook($second);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2, Order::query()->sole()->status);
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityLogAction::ORDER_CREATED->value)->count());
    }

    public function test_same_external_webhook_order_id_is_isolated_by_local_shop(): void
    {
        $firstShop = $this->createShop('First Shop', 'PAN-SHOP-1');
        $secondShop = $this->createShop('Second Shop', 'PAN-SHOP-2');

        foreach ([$firstShop, $secondShop] as $shop) {
            $this->createCustomer($shop, 'PAN-SHARED-CUSTOMER');
            $data = $this->orderData('PAN-SHARED-ORDER', 'PAN-SHARED-CUSTOMER');
            $data['shop_id'] = $shop->pancake_shop_id;
            $this->runWebhook($data);
        }

        $orders = Order::query()->orderBy('shop_id')->get();
        $events = ActivityLog::query()->where('action', ActivityLogAction::ORDER_CREATED->value)->orderBy('shop_id')->get();

        $this->assertSame(2, $orders->count());
        $this->assertSame([$firstShop->id, $secondShop->id], $events->pluck('shop_id')->all());
        $this->assertNotSame($events[0]->subject_id, $events[1]->subject_id);
        $this->assertNotSame($events[0]->idempotency_key, $events[1]->idempotency_key);
    }

    public function test_webhook_order_and_event_roll_back_together_when_order_logging_fails(): void
    {
        $shop = $this->createShop();
        $this->createCustomer($shop, 'PAN-CUSTOMER-ROLLBACK');
        $writer = Mockery::mock(OrderCreatedEventWriter::class);
        $writer->shouldReceive('write')->once()->andThrow(new RuntimeException('activity log failed'));
        $this->app->instance(OrderCreatedEventWriter::class, $writer);
        $data = $this->orderData('PAN-WEBHOOK-ROLLBACK', 'PAN-CUSTOMER-ROLLBACK');
        $data['shop_id'] = $shop->pancake_shop_id;

        $this->expectException(RuntimeException::class);

        try {
            $this->runWebhook($data);
        } finally {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, ActivityLog::query()->count());
        }
    }

    public function test_purchase_completed_is_not_emitted_without_a_reliable_completion_signal(): void
    {
        $shop = $this->createShop();
        $this->createCustomer($shop, 'PAN-CUSTOMER-NO-PURCHASE-EVENT');
        $data = $this->orderData('PAN-ORDER-NO-PURCHASE-EVENT', 'PAN-CUSTOMER-NO-PURCHASE-EVENT', status: 3);
        $data['shop_id'] = $shop->pancake_shop_id;
        $data['received_at_shop'] = true;

        $this->runWebhook($data);

        $this->assertSame(0, ActivityLog::query()->where('action', 'purchase.completed')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityLogAction::ORDER_CREATED->value)->count());
    }

    private function runBulk(Shop $shop, array $data): void
    {
        $job = new GetOrderByShopJob($data, $shop->id);
        $this->app->call([$job, 'handle']);
    }

    private function runWebhook(array $data): void
    {
        $job = new GetOrderFromWebhookJob($data);
        $this->app->call([$job, 'handle']);
    }

    private function createShop(string $name = 'Shop', string $pancakeShopId = 'PAN-SHOP-1'): Shop
    {
        return Shop::create([
            'pancake_shop_id' => $pancakeShopId,
            'name' => $name,
            'api_key' => 'test-key',
            'care_cycle_days' => 5,
        ]);
    }

    private function createCustomer(Shop $shop, string $pancakeCustomerId): Customer
    {
        return Customer::create([
            'shop_id' => $shop->id,
            'pancake_customer_id' => $pancakeCustomerId,
            'name' => 'Test customer',
            'phone_numbers' => '0900000000',
            'order_count' => 1,
            'purchased_amount' => 0,
        ]);
    }

    private function orderData(
        string $orderId,
        ?string $customerId,
        string $insertedAt = '2026-08-25T02:30:00Z',
        ?string $partnerOrderNumber = 'VTP-order',
        ?int $status = 3
    ): array {
        return [
            'id' => $orderId,
            'inserted_at' => $insertedAt,
            'updated_at' => '2026-08-25T03:00:00Z',
            'partner' => ['order_number_vtp' => $partnerOrderNumber],
            'items' => [
                [
                    'product_id' => 'PRODUCT-1',
                    'name' => 'Classic pancake',
                    'quantity' => 2,
                    'token' => 'must-not-leak',
                ],
                [
                    'variation_id' => 'VARIATION-2',
                    'variation_info' => ['id' => 'VARIATION-2', 'name' => 'Chocolate pancake'],
                    'quantity' => 1,
                ],
            ],
            'cod' => 125000,
            'cash' => 5000,
            'note' => 'customer note',
            'creator' => ['id' => 'PAN-CREATOR-1'],
            'assigning_care' => [],
            'assigning_seller' => [],
            'status' => $status,
            'received_at_shop' => false,
            'bill_full_name' => 'Test buyer',
            'bill_phone_number' => '0900000001',
            'shipping_address' => [],
            'assigning_care_id' => null,
            'customer' => [
                'id' => $customerId ?? 'PAN-CUSTOMER-UNLINKED',
                'customer_id' => $customerId,
                'order_count' => 1,
                'purchased_amount' => 0,
                'fb_id' => null,
                'name' => 'Test customer',
                'phone_numbers' => ['0900000001'],
                'shop_customer_addresses' => [],
            ],
        ];
    }

    private function createSchema(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_shop_id');
            $table->string('name');
            $table->string('api_key');
            $table->json('pancake_full_data')->nullable();
            $table->integer('care_cycle_days')->default(5);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->decimal('min_order_value', 15, 2)->default(0);
            $table->decimal('max_order_value', 15, 2)->nullable();
            $table->string('name')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->integer('order_count')->default(0);
            $table->unsignedBigInteger('loyalty_tier_id')->nullable();
            $table->string('pancake_customer_id')->nullable();
            $table->string('fb_id')->nullable();
            $table->string('name')->nullable();
            $table->text('phone_numbers')->nullable();
            $table->json('pancake_full_data')->nullable();
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->decimal('purchased_amount', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('province_id')->nullable();
            $table->string('pancake_order_id');
            $table->string('pancake_order_source_id')->nullable()->index();
            $table->string('pancake_order_source_name')->nullable();
            $table->string('order_number_vtp')->nullable();
            $table->integer('total_quantity')->default(0);
            $table->decimal('cod', 15, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('cash', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->string('user_creator_id')->nullable();
            $table->string('user_care_id')->nullable();
            $table->string('user_assigning_seller_id')->nullable();
            $table->integer('status')->default(3);
            $table->string('status_vtp')->nullable();
            $table->string('pancake_customer_id')->nullable();
            $table->json('pancake_full_data')->nullable();
            $table->boolean('received_at_shop')->default(false);
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('customer_address')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('customer_cares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_customer_id')->nullable();
            $table->string('pancake_order_id')->nullable();
            $table->json('customer_phones')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_addresss')->nullable();
            $table->date('date_care')->nullable();
            $table->text('note')->nullable();
            $table->string('user_creator_id')->nullable();
            $table->string('user_care_id')->nullable();
            $table->string('user_assigning_seller_id')->nullable();
            $table->integer('status')->default(0);
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->string('target_user_name')->nullable();
            $table->string('source', 50);
            $table->string('action', 100)->index();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('shop_name')->nullable();
            $table->string('subject_type', 50);
            $table->string('subject_id')->nullable();
            $table->string('pancake_order_id')->nullable();
            $table->string('pancake_customer_id')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
