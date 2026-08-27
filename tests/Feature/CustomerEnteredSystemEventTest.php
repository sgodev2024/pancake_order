<?php

namespace Tests\Feature;

use App\Enums\ActivityLogAction;
use App\Jobs\GetCustomerByShopJob;
use App\Jobs\GetOrderFromWebhookJob;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Shop;
use App\Services\CustomerEnteredSystemEventWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CustomerEnteredSystemEventTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Ho_Chi_Minh',
            'database.default' => 'customer_entered_system_testing',
            'database.connections.customer_entered_system_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('customer_entered_system_testing');
        DB::setDefaultConnection('customer_entered_system_testing');
        $this->createSchema();
        CarbonImmutable::setTestNow('2026-08-27 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_new_bulk_customer_gets_one_entry_event_with_local_identity_and_trusted_time(): void
    {
        $shop = $this->createShop('Bulk Shop');
        $insertedAt = '2026-08-25T02:30:00Z';

        $this->runBulk($shop, [$this->bulkCustomer('PAN-CUSTOMER-1', insertedAt: $insertedAt)]);

        $customer = Customer::query()->sole();
        $event = ActivityLog::query()->sole();

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(ActivityLogAction::CUSTOMER_ENTERED_SYSTEM->value, $event->action);
        $this->assertSame('customer', $event->subject_type);
        $this->assertSame((string) $customer->id, $event->subject_id);
        $this->assertSame($shop->id, $event->shop_id);
        $this->assertSame('PAN-CUSTOMER-1', $event->pancake_customer_id);
        $this->assertSame('system', $event->source);
        $this->assertNull($event->actor_user_id);
        $this->assertSame(
            CarbonImmutable::parse($insertedAt, 'UTC')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            $event->occurred_at->format('Y-m-d H:i:s')
        );
        $this->assertSame('pancake_bulk_sync', $event->metadata['ingestion_path']);
        $this->assertSame($customer->id, $event->metadata['customer_id']);
        $this->assertSame($shop->id, $event->metadata['shop_id']);
        $this->assertSame('PAN-CUSTOMER-1', $event->metadata['pancake_customer_id']);
        $this->assertSame(
            "customer.entered_system:shop:{$shop->id}:customer:{$customer->id}",
            $event->idempotency_key
        );
    }

    public function test_repeated_bulk_sync_updates_without_duplicate_customer_or_event(): void
    {
        $shop = $this->createShop();
        $data = $this->bulkCustomer('PAN-CUSTOMER-RETRY');

        $this->runBulk($shop, [$data]);
        $this->runBulk($shop, [$data]);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityLogAction::CUSTOMER_ENTERED_SYSTEM->value)->count());
    }

    public function test_existing_bulk_customer_is_updated_without_a_new_entry_event(): void
    {
        $shop = $this->createShop();
        $customer = Customer::create([
            'shop_id' => $shop->id,
            'pancake_customer_id' => 'PAN-CUSTOMER-EXISTING',
            'order_count' => 1,
            'name' => 'Before sync',
        ]);

        $this->runBulk($shop, [$this->bulkCustomer(
            'PAN-CUSTOMER-EXISTING',
            orderCount: 3,
            name: 'After sync'
        )]);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(3, Customer::query()->sole()->order_count);
        $this->assertSame('Before sync', $customer->fresh()->name);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_two_new_bulk_customers_get_two_distinct_entry_events(): void
    {
        $shop = $this->createShop();

        $this->runBulk($shop, [
            $this->bulkCustomer('PAN-CUSTOMER-A'),
            $this->bulkCustomer('PAN-CUSTOMER-B'),
        ]);

        $events = ActivityLog::query()->orderBy('id')->get();

        $this->assertSame(2, Customer::query()->count());
        $this->assertCount(2, $events);
        $this->assertCount(2, $events->pluck('subject_id')->unique());
        $this->assertCount(2, $events->pluck('idempotency_key')->unique());
    }

    public function test_bulk_customers_without_orders_remain_out_of_scope_without_an_event(): void
    {
        $shop = $this->createShop();

        $this->runBulk($shop, [$this->bulkCustomer('PAN-CUSTOMER-NO-ORDER', orderCount: 0)]);

        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_bulk_customer_and_entry_event_roll_back_together_when_activity_logging_fails(): void
    {
        $shop = $this->createShop();
        $writer = Mockery::mock(CustomerEnteredSystemEventWriter::class);
        $writer->shouldReceive('write')->once()->andThrow(new RuntimeException('activity log failed'));
        $this->app->instance(CustomerEnteredSystemEventWriter::class, $writer);

        $this->expectException(RuntimeException::class);

        try {
            $this->runBulk($shop, [$this->bulkCustomer('PAN-CUSTOMER-ROLLBACK')]);
        } finally {
            $this->assertSame(0, Customer::query()->count());
            $this->assertSame(0, ActivityLog::query()->count());
        }
    }

    public function test_new_webhook_customer_gets_one_entry_event_at_local_creation_time(): void
    {
        $shop = $this->createShop('Webhook Shop');
        $data = $this->webhookData($shop, 'PAN-WEBHOOK-ORDER-1', 'PAN-WEBHOOK-CUSTOMER-1');

        $this->runWebhook($data);

        $customer = Customer::query()->sole();
        $event = ActivityLog::query()->sole();

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, ActivityLog::query()->count());
        $this->assertSame((string) $customer->id, $event->subject_id);
        $this->assertSame($shop->id, $event->shop_id);
        $this->assertSame($customer->created_at->format('Y-m-d H:i:s'), $event->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('pancake_order_webhook', $event->metadata['ingestion_path']);
        $this->assertNull($event->metadata['acquisition_channel']);
        $this->assertSame('PAN-WEBHOOK-CUSTOMER-1', $event->pancake_customer_id);
        $this->assertSame('system', $event->source);
    }

    public function test_existing_webhook_customer_is_updated_without_a_new_entry_event(): void
    {
        $shop = $this->createShop();

        $this->runWebhook($this->webhookData($shop, 'PAN-WEBHOOK-ORDER-1', 'PAN-WEBHOOK-CUSTOMER-1'));
        $this->runWebhook($this->webhookData(
            $shop,
            'PAN-WEBHOOK-ORDER-2',
            'PAN-WEBHOOK-CUSTOMER-1',
            name: 'Updated customer'
        ));

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame('Updated customer', Customer::query()->sole()->name);
        $this->assertSame(1, ActivityLog::query()->count());
    }

    public function test_same_webhook_retry_is_idempotent_for_customer_and_event(): void
    {
        $shop = $this->createShop();
        $data = $this->webhookData($shop, 'PAN-WEBHOOK-ORDER-RETRY', 'PAN-WEBHOOK-CUSTOMER-RETRY');

        $this->runWebhook($data);
        $this->runWebhook($data);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, ActivityLog::query()->count());
    }

    public function test_same_external_customer_id_is_isolated_by_local_shop(): void
    {
        $firstShop = $this->createShop('First Shop', 'PAN-SHOP-1');
        $secondShop = $this->createShop('Second Shop', 'PAN-SHOP-2');

        $this->runWebhook($this->webhookData($firstShop, 'PAN-WEBHOOK-ORDER-1', 'PAN-SHARED-CUSTOMER'));
        $this->runWebhook($this->webhookData($secondShop, 'PAN-WEBHOOK-ORDER-2', 'PAN-SHARED-CUSTOMER'));

        $customers = Customer::query()->orderBy('shop_id')->get();
        $events = ActivityLog::query()->orderBy('shop_id')->get();

        $this->assertSame(2, $customers->count());
        $this->assertSame([$firstShop->id, $secondShop->id], $customers->pluck('shop_id')->all());
        $this->assertSame(2, $events->count());
        $this->assertSame([$firstShop->id, $secondShop->id], $events->pluck('shop_id')->all());
        $this->assertNotSame($events[0]->subject_id, $events[1]->subject_id);
        $this->assertNotSame($events[0]->idempotency_key, $events[1]->idempotency_key);
    }

    public function test_webhook_customer_and_entry_event_roll_back_together_when_activity_logging_fails(): void
    {
        $shop = $this->createShop();
        $writer = Mockery::mock(CustomerEnteredSystemEventWriter::class);
        $writer->shouldReceive('write')->once()->andThrow(new RuntimeException('activity log failed'));
        $this->app->instance(CustomerEnteredSystemEventWriter::class, $writer);

        $this->expectException(RuntimeException::class);

        try {
            $this->runWebhook($this->webhookData($shop, 'PAN-WEBHOOK-ORDER-FAIL', 'PAN-WEBHOOK-CUSTOMER-FAIL'));
        } finally {
            $this->assertSame(0, Customer::query()->count());
            $this->assertSame(0, ActivityLog::query()->count());
        }
    }

    private function runBulk(Shop $shop, array $data): void
    {
        $job = new GetCustomerByShopJob($data, $shop->id);
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

    private function bulkCustomer(
        string $customerId,
        int $orderCount = 1,
        string $insertedAt = '2026-08-25T02:30:00Z',
        string $name = 'Bulk customer'
    ): array {
        return [
            'customer_id' => $customerId,
            'order_count' => $orderCount,
            'inserted_at' => $insertedAt,
            'updated_at' => $insertedAt,
            'assigned_user_id' => null,
            'purchased_amount' => 0,
            'phone_numbers' => ['0900000000'],
            'fb_id' => null,
            'name' => $name,
        ];
    }

    private function webhookData(
        Shop $shop,
        string $orderId,
        string $customerId,
        string $name = 'Webhook customer'
    ): array {
        return [
            'shop_id' => $shop->pancake_shop_id,
            'id' => $orderId,
            'inserted_at' => '2026-08-27T01:00:00Z',
            'updated_at' => '2026-08-27T01:05:00Z',
            'partner' => ['order_number_vtp' => 'VTP-'.$orderId],
            'items' => [],
            'cod' => 0,
            'cash' => 0,
            'note' => null,
            'creator' => ['id' => 'PAN-CREATOR-1'],
            'assigning_care' => [],
            'assigning_seller' => [],
            'status' => 3,
            'received_at_shop' => false,
            'bill_full_name' => $name,
            'bill_phone_number' => '0900000001',
            'shipping_address' => [],
            'assigning_care_id' => null,
            'customer' => [
                'id' => $customerId,
                'customer_id' => $customerId,
                'order_count' => 1,
                'purchased_amount' => 0,
                'fb_id' => null,
                'name' => $name,
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
            $table->string('pancake_customer_id');
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
