<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerCareAddressRepairCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'customer_care_address_repair_testing',
            'database.connections.customer_care_address_repair_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('customer_care_address_repair_testing');
        DB::setDefaultConnection('customer_care_address_repair_testing');
        $this->createSchema();
    }

    public function test_preview_reports_eligible_blank_address_without_writes(): void
    {
        $order = $this->createOrder(customerAddress: 'Source address');
        $care = $this->createCare($order, null);
        $this->createOrderAssignment($care, $order);

        $this->artisan('customer-care:repair-addresses', ['--ids' => (string) $care->id])
            ->expectsOutputToContain('PREVIEW ONLY')
            ->expectsOutputToContain('WOULD_REPAIR')
            ->expectsOutputToContain('Eligible: 1')
            ->expectsOutputToContain('Would repair: 1')
            ->expectsOutputToContain('Repaired: 0')
            ->assertSuccessful();

        $this->assertNull($care->fresh()->customer_addresss);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_empty_string_address_is_a_repair_candidate(): void
    {
        $order = $this->createOrder(customerAddress: 'Source address');
        $care = $this->createCare($order, '');
        $this->createOrderAssignment($care, $order);

        $this->artisan('customer-care:repair-addresses', ['--ids' => (string) $care->id])
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('WOULD_REPAIR')
            ->expectsOutputToContain('Repaired: 0')
            ->assertSuccessful();

        $this->assertSame('', $care->fresh()->customer_addresss);
    }

    public function test_full_missing_address_candidate_set_is_scanned_after_more_than_500_populated_rows(): void
    {
        foreach (range(1, 501) as $index) {
            $this->createPopulatedCare($index);
        }

        $firstOrder = $this->createOrder(customerAddress: 'First source');
        $firstCare = $this->createCare($firstOrder, null);
        $this->createOrderAssignment($firstCare, $firstOrder);

        $secondOrder = $this->createOrder(customerAddress: 'Second source');
        $secondCare = $this->createCare($secondOrder, '   ');
        $this->createOrderAssignment($secondCare, $secondOrder);

        $this->artisan('customer-care:repair-addresses', [
            '--shop-id' => '1',
            '--limit' => '1',
        ])->expectsOutputToContain('Scanned: 2')
            ->expectsOutputToContain('Eligible: 2')
            ->expectsOutputToContain('Displayed: 1 of 2 targeted candidates.')
            ->expectsOutputToContain('Already populated (not candidates): 0')
            ->expectsOutputToContain('Repaired: 0')
            ->assertSuccessful();

        $this->assertNull($firstCare->fresh()->customer_addresss);
        $this->assertSame('   ', $secondCare->fresh()->customer_addresss);
    }

    public function test_execute_repairs_whitespace_only_address_and_logs_identifiers_not_address_text(): void
    {
        $order = $this->createOrder(customerAddress: 'Source address');
        $care = $this->createCare($order, '   ');
        $this->createOrderAssignment($care, $order);
        $originalTimestamp = $care->updated_at->format('Y-m-d H:i:s');

        $this->artisan('customer-care:repair-addresses', [
            '--ids' => (string) $care->id,
            '--execute' => true,
        ])->expectsOutputToContain('EXECUTE')
            ->expectsOutputToContain('Repaired: 1')
            ->assertSuccessful();

        $care->refresh();
        $this->assertSame('Source address', $care->customer_addresss);
        $this->assertSame('Historical note', $care->note);
        $this->assertSame('2026-08-24', (string) $care->date_care);
        $this->assertSame(1, (int) $care->status);
        $this->assertSame($originalTimestamp, $care->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('Source address', $order->fresh()->customer_address);

        $log = ActivityLog::query()->sole();
        $this->assertSame('customer_care.address_repaired', $log->action);
        $this->assertSame('system', $log->source);
        $this->assertSame('customer_care', $log->subject_type);
        $this->assertSame((string) $care->id, $log->subject_id);
        $this->assertSame(['customer_addresss' => 'BLANK'], $log->old_values);
        $this->assertSame(['customer_addresss' => 'POPULATED'], $log->new_values);
        $this->assertSame($care->id, $log->metadata['customer_care_id']);
        $this->assertSame($order->id, $log->metadata['source_order_id']);
        $this->assertStringNotContainsString('Source address', json_encode($log->toArray()));
    }

    public function test_existing_customer_care_address_is_never_overwritten(): void
    {
        $order = $this->createOrder(customerAddress: 'Different source address');
        $care = $this->createCare($order, 'Existing address');
        $this->createOrderAssignment($care, $order);

        $this->artisan('customer-care:repair-addresses', [
            '--ids' => (string) $care->id,
            '--execute' => true,
        ])->expectsOutputToContain('Scanned: 0')
            ->expectsOutputToContain('Already populated (not candidates): 1')
            ->expectsOutputToContain('Repaired: 0')
            ->assertSuccessful();

        $this->assertSame('Existing address', $care->fresh()->customer_addresss);
        $this->assertSame('Different source address', $order->fresh()->customer_address);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_blank_order_address_is_skipped(): void
    {
        $order = $this->createOrder(customerAddress: null);
        $care = $this->createCare($order, null);
        $this->createOrderAssignment($care, $order);

        $this->artisan('customer-care:repair-addresses', [
            '--ids' => (string) $care->id,
            '--execute' => true,
        ])->expectsOutputToContain('ORDER_ADDRESS_BLANK')
            ->expectsOutputToContain('Repaired: 0')
            ->assertSuccessful();

        $this->assertNull($care->fresh()->customer_addresss);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_no_deterministic_order_is_skipped(): void
    {
        $care = $this->createCare(null, null, pancakeOrderId: 'MISSING-ORDER');

        $this->artisan('customer-care:repair-addresses', [
            '--ids' => (string) $care->id,
            '--execute' => true,
        ])->expectsOutputToContain('NO_SOURCE')
            ->expectsOutputToContain('Repaired: 0')
            ->assertSuccessful();

        $this->assertNull($care->fresh()->customer_addresss);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_order_from_a_different_shop_is_skipped(): void
    {
        $order = $this->createOrder(shopId: 2, customerAddress: 'Other shop address');
        $care = $this->createCare($order, null);
        $care->update(['shop_id' => 1]);
        $this->createOrderAssignment($care, $order);

        $this->artisan('customer-care:repair-addresses', [
            '--ids' => (string) $care->id,
            '--execute' => true,
        ])->expectsOutputToContain('SHOP_MISMATCH')
            ->expectsOutputToContain('Repaired: 0')
            ->assertSuccessful();

        $this->assertNull($care->fresh()->customer_addresss);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_duplicate_external_order_ids_without_exact_assignment_are_not_used_as_a_source(): void
    {
        $first = $this->createOrder(pancakeOrderId: 'DUPLICATE-ORDER', customerAddress: 'First source');
        $this->createOrder(pancakeOrderId: 'DUPLICATE-ORDER', customerAddress: 'Second source');
        $care = $this->createCare($first, null);

        $this->artisan('customer-care:repair-addresses', [
            '--ids' => (string) $care->id,
            '--execute' => true,
        ])->expectsOutputToContain('NO_SOURCE')
            ->expectsOutputToContain('Repaired: 0')
            ->assertSuccessful();

        $this->assertNull($care->fresh()->customer_addresss);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_row_without_an_exact_assignment_is_skipped_even_when_a_same_shop_order_key_exists(): void
    {
        $order = $this->createOrder(customerAddress: 'Legacy source');
        $care = $this->createCare($order, null);

        $this->artisan('customer-care:repair-addresses', [
            '--ids' => (string) $care->id,
            '--execute' => true,
        ])->expectsOutputToContain('NO_SOURCE')
            ->expectsOutputToContain('Repaired: 0')
            ->assertSuccessful();

        $this->assertNull($care->fresh()->customer_addresss);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_multiple_exact_order_assignment_sources_are_ambiguous(): void
    {
        $first = $this->createOrder(customerAddress: 'First source');
        $second = $this->createOrder(customerAddress: 'Second source');
        $care = $this->createCare($first, null);
        $this->createOrderAssignment($care, $first);
        $this->createOrderAssignment($care, $second);

        $this->artisan('customer-care:repair-addresses', [
            '--ids' => (string) $care->id,
            '--execute' => true,
        ])->expectsOutputToContain('AMBIGUOUS')
            ->expectsOutputToContain('Repaired: 0')
            ->assertSuccessful();

        $this->assertNull($care->fresh()->customer_addresss);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_exact_assignment_source_is_used_even_when_external_order_id_is_duplicated(): void
    {
        $sourceOrder = $this->createOrder(pancakeOrderId: 'DUPLICATE-ORDER', customerAddress: 'Exact source');
        $this->createOrder(pancakeOrderId: 'DUPLICATE-ORDER', customerAddress: 'Other source');
        $care = $this->createCare($sourceOrder, null);
        $this->createOrderAssignment($care, $sourceOrder);

        $this->artisan('customer-care:repair-addresses', [
            '--ids' => (string) $care->id,
            '--execute' => true,
        ])->expectsOutputToContain('Repaired: 1')
            ->assertSuccessful();

        $this->assertSame('Exact source', $care->fresh()->customer_addresss);
    }

    public function test_execute_is_idempotent_after_one_repair(): void
    {
        $order = $this->createOrder(customerAddress: 'Source address');
        $care = $this->createCare($order, null);
        $this->createOrderAssignment($care, $order);

        $this->artisan('customer-care:repair-addresses', [
            '--ids' => (string) $care->id,
            '--execute' => true,
        ])->expectsOutputToContain('Repaired: 1')->assertSuccessful();

        $this->artisan('customer-care:repair-addresses', ['--ids' => (string) $care->id])
            ->expectsOutputToContain('Scanned: 0')
            ->expectsOutputToContain('Eligible: 0')
            ->expectsOutputToContain('Already populated (not candidates): 1')
            ->expectsOutputToContain('Repaired: 0')
            ->assertSuccessful();

        $this->assertSame('Source address', $care->fresh()->customer_addresss);
        $this->assertSame(1, ActivityLog::query()->count());
    }

    public function test_ids_scope_is_strict_and_unknown_ids_are_reported(): void
    {
        $insideOrder = $this->createOrder(customerAddress: 'Inside source');
        $insideCare = $this->createCare($insideOrder, null);
        $this->createOrderAssignment($insideCare, $insideOrder);

        $outsideOrder = $this->createOrder(customerAddress: 'Outside source');
        $outsideCare = $this->createCare($outsideOrder, null);
        $this->createOrderAssignment($outsideCare, $outsideOrder);

        $this->artisan('customer-care:repair-addresses', [
            '--ids' => "{$insideCare->id},999999",
            '--execute' => true,
        ])->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Unknown requested IDs: 999999')
            ->expectsOutputToContain('Repaired: 1')
            ->assertSuccessful();

        $this->assertSame('Inside source', $insideCare->fresh()->customer_addresss);
        $this->assertNull($outsideCare->fresh()->customer_addresss);
    }

    private function createOrder(
        int $shopId = 1,
        ?string $pancakeOrderId = null,
        ?string $customerAddress = 'Source address'
    ): Order {
        return Order::create([
            'shop_id' => $shopId,
            'pancake_order_id' => $pancakeOrderId ?? 'ORDER-'.uniqid(),
            'pancake_customer_id' => 'CUSTOMER-'.uniqid(),
            'customer_address' => $customerAddress,
            'status' => 3,
        ]);
    }

    private function createPopulatedCare(int $index): CustomerCare
    {
        return CustomerCare::create([
            'shop_id' => 1,
            'pancake_customer_id' => "POPULATED-CUSTOMER-{$index}",
            'pancake_order_id' => "POPULATED-ORDER-{$index}",
            'customer_addresss' => 'Existing address',
            'date_care' => '2026-08-24',
            'note' => 'Historical note',
            'status' => 1,
        ]);
    }

    private function createCare(
        ?Order $order,
        ?string $customerAddress,
        ?string $pancakeOrderId = null
    ): CustomerCare {
        return CustomerCare::create([
            'shop_id' => $order?->shop_id ?? 1,
            'pancake_customer_id' => $order?->pancake_customer_id ?? 'CUSTOMER-'.uniqid(),
            'pancake_order_id' => $pancakeOrderId ?? $order?->pancake_order_id,
            'customer_addresss' => $customerAddress,
            'date_care' => '2026-08-24',
            'note' => 'Historical note',
            'status' => 1,
        ]);
    }

    private function createOrderAssignment(CustomerCare $care, Order $order): CustomerCareAssignment
    {
        return CustomerCareAssignment::create([
            'shop_id' => $care->shop_id,
            'customer_care_id' => $care->id,
            'source_type' => CustomerCareAssignment::SOURCE_ORDER,
            'source_id' => $order->id,
            'assignee_user_id' => 1,
            'assignee_pancake_user_id' => '1',
            'assigned_at' => '2026-08-24 10:00:00',
            'reclaim_eligible_on' => '2026-08-27',
            'status' => CustomerCareAssignment::STATUS_ACTIVE,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_order_id');
            $table->string('pancake_customer_id')->nullable();
            $table->string('customer_address')->nullable();
            $table->integer('status')->default(3);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('customer_cares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_customer_id')->nullable();
            $table->string('pancake_order_id')->nullable();
            $table->string('customer_addresss')->nullable();
            $table->date('date_care')->nullable();
            $table->text('note')->nullable();
            $table->integer('status')->default(0);
            $table->timestamps();
        });

        Schema::create('customer_care_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('customer_care_id');
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('assignee_user_id');
            $table->string('assignee_pancake_user_id');
            $table->timestamp('assigned_at');
            $table->date('reclaim_eligible_on')->nullable();
            $table->string('status', 20)->default(CustomerCareAssignment::STATUS_ACTIVE);
            $table->timestamp('cared_at')->nullable();
            $table->timestamp('reclaimed_at')->nullable();
            $table->string('reclaim_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->string('target_user_name')->nullable();
            $table->string('source', 50);
            $table->string('action', 100);
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('shop_name')->nullable();
            $table->string('subject_type', 50);
            $table->string('subject_id')->nullable();
            $table->string('pancake_order_id')->nullable();
            $table->string('pancake_customer_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
