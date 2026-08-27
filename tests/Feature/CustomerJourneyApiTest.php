<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerJourneyApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'customer_journey_testing',
            'database.connections.customer_journey_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('customer_journey_testing');
        DB::setDefaultConnection('customer_journey_testing');
        $this->createSchema();
    }

    public function test_admin_gets_a_safe_local_customer_journey_in_chronological_order(): void
    {
        $shop = $this->createShop('Shop One');
        $admin = $this->createUser('admin', 'Admin');
        $this->grantPermission($admin, 'list-customer');
        $customer = $this->createCustomer($shop, [
            'name' => 'Nguyễn Văn A',
            'pancake_customer_id' => 'PAN-CUSTOMER-A',
        ]);
        $orderA = $this->createOrder($shop, [
            'pancake_customer_id' => $customer->pancake_customer_id,
            'pancake_order_id' => 'PAN-ORDER-A',
        ]);
        $orderB = $this->createOrder($shop, [
            'pancake_customer_id' => $customer->pancake_customer_id,
            'pancake_order_id' => 'PAN-ORDER-B',
        ]);
        $careA = $this->createCare($shop, $customer, $orderA);
        $careB = $this->createCare($shop, $customer, $orderB);
        $assignmentA = $this->createAssignment($shop, $careA, $orderA);
        $assignmentB = $this->createAssignment($shop, $careB, $orderB);

        $this->createLog([
            'action' => 'customer.entered_system',
            'subject_type' => 'customer',
            'subject_id' => $customer->id,
            'pancake_customer_id' => $customer->pancake_customer_id,
            'occurred_at' => '2026-08-20 09:00:00',
            'metadata' => [
                'customer_id' => $customer->id,
                'ingestion_path' => 'webhook',
                'secret_token' => 'must-not-leak',
            ],
        ]);
        $this->createLog([
            'action' => 'customer_care.assigned',
            'subject_type' => 'customer_care_assignment',
            'subject_id' => $assignmentA->id,
            'pancake_order_id' => $orderA->pancake_order_id,
            'metadata' => [
                'assignment_id' => $assignmentA->id,
                'customer_care_id' => $careA->id,
                'assignee_user_id' => 99,
                'source_type' => 'order',
                'source_id' => $orderA->id,
            ],
            'occurred_at' => '2026-08-20 10:00:00',
        ]);
        $this->createLog([
            'action' => 'customer_care.completed',
            'source' => 'user',
            'subject_type' => 'customer_care',
            'subject_id' => $careA->id,
            'actor_user_id' => $admin->id,
            'actor_name' => 'Admin',
            'target_user_id' => 99,
            'target_user_name' => 'Care staff',
            'pancake_order_id' => $orderA->pancake_order_id,
            'metadata' => [
                'customer_care_id' => $careA->id,
                'assignment_id' => $assignmentA->id,
                'customer_id' => $customer->id,
                'care_sequence_number' => 1,
                'sequence_scope' => 'customer_shop',
                'result' => 'completed',
                'old_values' => ['must-not-leak' => true],
            ],
            'occurred_at' => '2026-08-20 11:00:00',
        ]);
        $this->createLog([
            'action' => 'customer_care.reassigned',
            'subject_type' => 'customer_care_assignment',
            'subject_id' => $assignmentB->id,
            'pancake_order_id' => $orderB->pancake_order_id,
            'metadata' => [
                'previous_assignment_id' => $assignmentA->id,
                'new_assignment_id' => $assignmentB->id,
                'previous_assignee_user_id' => 99,
                'new_assignee_user_id' => 100,
            ],
            'occurred_at' => '2026-08-20 12:00:00',
        ]);
        $this->createLog([
            'action' => 'customer_care.completed',
            'source' => 'user',
            'subject_type' => 'customer_care',
            'subject_id' => $careB->id,
            'pancake_order_id' => $orderB->pancake_order_id,
            'metadata' => [
                'customer_care_id' => $careB->id,
                'assignment_id' => $assignmentB->id,
                'customer_id' => $customer->id,
                'care_sequence_number' => 2,
                'sequence_scope' => 'customer_shop',
            ],
            'occurred_at' => '2026-08-20 13:00:00',
        ]);
        $this->createLog([
            'action' => 'order.created',
            'subject_type' => 'order',
            'subject_id' => $orderA->id,
            'pancake_order_id' => $orderA->pancake_order_id,
            'pancake_customer_id' => $customer->pancake_customer_id,
            'metadata' => [
                'order_id' => $orderA->id,
                'customer_id' => $customer->id,
                'amount' => 100000,
                'items' => [
                    ['product_id' => 'P-1', 'name' => 'Pancake', 'quantity' => 2, 'token' => 'secret'],
                ],
                'pancake_full_data' => ['secret' => 'must-not-leak'],
            ],
            'occurred_at' => null,
            'created_at' => '2026-08-20 14:00:00',
        ]);
        $this->createLog([
            'action' => 'customer_care.reclaim_date_repaired',
            'subject_type' => 'customer_care_assignment',
            'subject_id' => $assignmentA->id,
            'pancake_customer_id' => $customer->pancake_customer_id,
            'metadata' => ['assignment_id' => $assignmentA->id],
        ]);
        $this->createLog([
            'action' => 'customer_care.assigned',
            'subject_type' => 'customer_care_assignment',
            'subject_id' => $assignmentB->id + 1000,
            'pancake_customer_id' => $customer->pancake_customer_id,
            'metadata' => [
                'source_type' => 'imported_opportunity',
                'source_id' => 77,
            ],
        ]);

        $response = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/customers/{$customer->id}/journey?page_size=100")
            ->assertOk()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.customer.shop_id', $shop->id)
            ->assertJsonPath('data.summary.care_count', 2)
            ->assertJsonPath('data.summary.order_count', 1)
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.per_page', 100)
            ->assertJsonPath('data.total_items', 6)
            ->assertJsonPath('data.total_pages', 1);

        $timeline = collect($response->json('data.timeline'));
        $this->assertSame([
            'customer.entered_system',
            'customer_care.assigned',
            'customer_care.completed',
            'customer_care.reassigned',
            'customer_care.completed',
            'order.created',
        ], $timeline->pluck('action')->all());
        $this->assertSame($customer->id, $timeline[0]['subject']['id'] === (string) $customer->id
            ? $customer->id
            : null);
        $this->assertSame(2, $timeline[4]['metadata']['care_sequence_number']);
        $this->assertSame('2026-08-20T07:00:00.000000Z', $timeline[5]['occurred_at']);
        $this->assertArrayNotHasKey('secret_token', $timeline[0]['metadata']);
        $this->assertArrayNotHasKey('pancake_full_data', $timeline[5]['metadata']);
        $this->assertArrayNotHasKey('token', $timeline[5]['metadata']['items'][0]);
        $this->assertArrayNotHasKey('total_spent', $response->json('data.summary'));
        $this->assertArrayNotHasKey('successful_purchase_count', $response->json('data.summary'));
    }

    public function test_customer_journey_preserves_shop_and_staff_customer_visibility(): void
    {
        $shopOne = $this->createShop('Shop One');
        $shopTwo = $this->createShop('Shop Two');
        $managerOne = $this->createUser('manager-cskh', 'Manager One');
        $staffOne = $this->createUser('staff-sale', 'Staff One');
        $staffTwo = $this->createUser('staff-sale', 'Staff Two');
        $admin = $this->createUser('admin', 'Admin');
        foreach ([$managerOne, $staffOne, $staffTwo, $admin] as $user) {
            $this->grantPermission($user, 'list-customer');
        }
        $this->attach($managerOne, $shopOne);
        $this->attach($staffOne, $shopOne);
        $this->attach($staffTwo, $shopTwo);

        $own = $this->createCustomer($shopOne, [
            'assigned_user_id' => $staffOne->pancake_user_id,
            'pancake_customer_id' => 'SHARED-CUSTOMER',
        ]);
        $otherCustomerSameShop = $this->createCustomer($shopOne, [
            'assigned_user_id' => 'someone-else',
            'pancake_customer_id' => 'OTHER-CUSTOMER',
        ]);
        $sameExternalOtherShop = $this->createCustomer($shopTwo, [
            'assigned_user_id' => $staffTwo->pancake_user_id,
            'pancake_customer_id' => 'SHARED-CUSTOMER',
        ]);
        $ownOrder = $this->createOrder($shopOne, [
            'pancake_customer_id' => $own->pancake_customer_id,
        ]);
        $otherShopOrder = $this->createOrder($shopTwo, [
            'pancake_customer_id' => $sameExternalOtherShop->pancake_customer_id,
        ]);
        $this->createLog([
            'action' => 'order.created',
            'subject_type' => 'order',
            'subject_id' => $ownOrder->id,
            'pancake_order_id' => $ownOrder->pancake_order_id,
            'pancake_customer_id' => 'SHARED-CUSTOMER',
            'metadata' => [
                'order_id' => $ownOrder->id,
                'customer_id' => $own->id,
            ],
        ]);
        $this->createLog([
            'action' => 'order.created',
            'subject_type' => 'order',
            'subject_id' => $otherShopOrder->id,
            'pancake_order_id' => $otherShopOrder->pancake_order_id,
            'pancake_customer_id' => 'SHARED-CUSTOMER',
            'metadata' => [],
        ], $shopTwo);

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/customers/{$sameExternalOtherShop->id}/journey")
            ->assertOk()
            ->assertJsonPath('data.customer.id', $sameExternalOtherShop->id)
            ->assertJsonPath('data.total_items', 1);
        $this->actingAs($managerOne, 'api')
            ->getJson("/api/v1/customers/{$own->id}/journey")
            ->assertOk()
            ->assertJsonPath('data.total_items', 1);
        $this->actingAs($managerOne, 'api')
            ->getJson("/api/v1/customers/{$sameExternalOtherShop->id}/journey")
            ->assertForbidden();
        $this->actingAs($staffOne, 'api')
            ->getJson("/api/v1/customers/{$own->id}/journey")
            ->assertOk();
        $this->actingAs($staffOne, 'api')
            ->getJson("/api/v1/customers/{$otherCustomerSameShop->id}/journey")
            ->assertForbidden();
        $this->actingAs($staffTwo, 'api')
            ->getJson("/api/v1/customers/{$own->id}/journey")
            ->assertForbidden();
    }

    public function test_same_shop_legacy_order_fallback_and_imported_opportunity_are_fail_closed(): void
    {
        $shop = $this->createShop('Shop One');
        $manager = $this->createUser('manager-sale', 'Manager');
        $this->attach($manager, $shop);
        $this->grantPermission($manager, 'list-customer');
        $customer = $this->createCustomer($shop, ['pancake_customer_id' => 'PAN-FALLBACK']);
        $order = $this->createOrder($shop, [
            'pancake_customer_id' => $customer->pancake_customer_id,
        ]);
        $this->createLog([
            'action' => 'order.created',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'pancake_customer_id' => $customer->pancake_customer_id,
            'metadata' => ['order_id' => $order->id],
        ]);
        $this->createLog([
            'action' => 'customer_care.assigned',
            'subject_type' => 'customer_care_assignment',
            'subject_id' => 999,
            'pancake_customer_id' => $customer->pancake_customer_id,
            'metadata' => [
                'source_type' => 'imported_opportunity',
                'source_id' => 55,
            ],
        ]);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/customers/{$customer->id}/journey")
            ->assertOk()
            ->assertJsonPath('data.summary.order_count', 1)
            ->assertJsonPath('data.total_items', 1);
    }

    public function test_empty_journey_and_pagination_contract_are_bounded(): void
    {
        $shop = $this->createShop();
        $manager = $this->createUser('manager-cskh', 'Manager');
        $this->attach($manager, $shop);
        $this->grantPermission($manager, 'list-customer');
        $customer = $this->createCustomer($shop);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/customers/{$customer->id}/journey")
            ->assertOk()
            ->assertJsonPath('data.timeline', [])
            ->assertJsonPath('data.summary.care_count', 0)
            ->assertJsonPath('data.summary.order_count', 0)
            ->assertJsonPath('data.per_page', 50)
            ->assertJsonPath('data.total_pages', 1);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/customers/{$customer->id}/journey?page_size=101")
            ->assertStatus(422);
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/customers/{$customer->id}/journey?action=activity.login")
            ->assertStatus(422);
    }

    public function test_pagination_keeps_chronological_order_and_has_no_cross_page_duplicates(): void
    {
        $shop = $this->createShop();
        $manager = $this->createUser('manager-cskh', 'Manager');
        $this->attach($manager, $shop);
        $this->grantPermission($manager, 'list-customer');
        $customer = $this->createCustomer($shop);

        foreach (['09:00:00', '10:00:00', '11:00:00'] as $time) {
            $this->createLog([
                'action' => 'customer.entered_system',
                'subject_type' => 'customer',
                'subject_id' => $customer->id,
                'metadata' => ['customer_id' => $customer->id],
                'occurred_at' => '2026-08-20 '.$time,
            ]);
        }

        $pageOne = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/customers/{$customer->id}/journey?page_size=2&page=1")
            ->assertOk()
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonPath('data.total_items', 3)
            ->assertJsonPath('data.total_pages', 2);
        $pageTwo = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/customers/{$customer->id}/journey?page_size=2&page=2")
            ->assertOk()
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonCount(1, 'data.timeline');

        $pageOneIds = collect($pageOne->json('data.timeline'))->pluck('id')->all();
        $pageTwoIds = collect($pageTwo->json('data.timeline'))->pluck('id')->all();
        $this->assertSame(2, count(array_unique($pageOneIds)));
        $this->assertCount(0, array_intersect($pageOneIds, $pageTwoIds));
        $this->assertSame([
            '2026-08-20T02:00:00.000000Z',
            '2026-08-20T03:00:00.000000Z',
        ], collect($pageOne->json('data.timeline'))->pluck('occurred_at')->all());
        $this->assertSame('2026-08-20T04:00:00.000000Z', $pageTwo->json('data.timeline.0.occurred_at'));
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('pancake_user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('shops', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('shop_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_manager')->default(false);
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('assigned_user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_order_id');
            $table->string('pancake_customer_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('customer_cares', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_customer_id');
            $table->string('pancake_order_id')->nullable();
            $table->integer('status')->default(0);
            $table->date('date_care')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_care_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('customer_care_id');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('assignee_user_id');
            $table->string('assignee_pancake_user_id');
            $table->timestamp('assigned_at')->nullable();
            $table->date('reclaim_eligible_on')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->string('target_user_name')->nullable();
            $table->string('source');
            $table->string('action');
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('shop_name')->nullable();
            $table->string('subject_type');
            $table->string('subject_id')->nullable();
            $table->string('pancake_order_id')->nullable();
            $table->string('pancake_customer_id')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    private function createShop(string $name = 'Shop'): Shop
    {
        return Shop::create(['name' => $name]);
    }

    private function createUser(string $roleSlug, string $name): User
    {
        $role = Role::query()->firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug]);
        $id = (int) User::query()->max('id') + 1;

        return User::create([
            'role_id' => $role->id,
            'name' => $name,
            'email' => "journey-{$id}@example.test",
            'password' => 'password',
            'pancake_user_id' => "PAN-USER-{$id}",
        ]);
    }

    private function attach(User $user, Shop $shop): void
    {
        $user->shops()->attach($shop->id, ['is_manager' => false]);
    }

    private function grantPermission(User $user, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        DB::table('role_permissions')->insert([
            'role_id' => $user->role_id,
            'permission_id' => $permission->id,
        ]);
    }

    private function createCustomer(Shop $shop, array $attributes = []): Customer
    {
        $id = (int) Customer::query()->max('id') + 1;

        return Customer::create(array_merge([
            'shop_id' => $shop->id,
            'pancake_customer_id' => "PAN-CUSTOMER-{$id}",
            'name' => 'Customer',
        ], $attributes));
    }

    private function createOrder(Shop $shop, array $attributes = []): Order
    {
        $id = (int) Order::query()->max('id') + 1;

        return Order::create(array_merge([
            'shop_id' => $shop->id,
            'pancake_order_id' => "PAN-ORDER-{$id}",
            'pancake_customer_id' => null,
        ], $attributes));
    }

    private function createCare(Shop $shop, Customer $customer, Order $order): CustomerCare
    {
        return CustomerCare::create([
            'shop_id' => $shop->id,
            'pancake_customer_id' => $customer->pancake_customer_id,
            'pancake_order_id' => $order->pancake_order_id,
            'date_care' => now()->toDateString(),
        ]);
    }

    private function createAssignment(Shop $shop, CustomerCare $care, Order $order): CustomerCareAssignment
    {
        return CustomerCareAssignment::create([
            'shop_id' => $shop->id,
            'customer_care_id' => $care->id,
            'source_type' => CustomerCareAssignment::SOURCE_ORDER,
            'source_id' => $order->id,
            'assignee_user_id' => 1,
            'assignee_pancake_user_id' => 'PAN-USER-1',
            'assigned_at' => now(),
            'reclaim_eligible_on' => now()->addDays(3)->toDateString(),
        ]);
    }

    private function createLog(array $attributes, ?Shop $shop = null): ActivityLog
    {
        $attributes = array_merge([
            'source' => 'system',
            'shop_id' => $shop?->id,
            'shop_name' => $shop?->name,
            'subject_id' => null,
            'metadata' => [],
            'created_at' => '2026-08-20 15:00:00',
        ], $attributes);

        $attributes['shop_id'] ??= $this->currentShopId();
        $attributes['shop_name'] ??= Shop::query()->find($attributes['shop_id'])?->name;
        $attributes['metadata'] = json_encode($attributes['metadata']);

        $id = DB::table('activity_logs')->insertGetId($attributes);

        return ActivityLog::query()->findOrFail($id);
    }

    private function currentShopId(): int
    {
        return (int) Shop::query()->orderBy('id')->value('id');
    }
}
