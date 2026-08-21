<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\ImportedOpportunity;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OpportunitySecurityHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Ho_Chi_Minh',
            'database.default' => 'security_hardening_testing',
            'database.connections.security_hardening_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('security_hardening_testing');
        DB::setDefaultConnection('security_hardening_testing');
        $this->createSchema();
        Carbon::setTestNow('2026-08-21 10:15:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_user_with_view_chance_permission_only_sees_requested_shop_in_scope_with_compatible_payload(): void
    {
        $ownShop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $actor = $this->createUser('employee', 'Actor');
        $this->attachShop($actor, $ownShop);
        $this->grantPermission($actor, 'view-chance');
        $ownOrder = $this->createOrder($ownShop);
        $this->createOrder($otherShop);

        $response = $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/chance?shop_id={$ownShop->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.id', $ownOrder->id);
        $this->assertSame(['success', 'data'], array_keys($response->json()));
        $this->assertSame(
            ['orders', 'current_page', 'per_page', 'total_items', 'total_pages'],
            array_keys($response->json('data'))
        );
    }

    public function test_user_without_view_chance_permission_is_denied_with_legacy_http_200_response(): void
    {
        $shop = $this->createShop();
        $actor = $this->createUser('employee', 'Actor');
        $this->attachShop($actor, $shop);
        $this->createOrder($shop);

        $this->actingAs($actor, 'api')
            ->getJson('/api/v1/orders/chance')
            ->assertOk()
            ->assertExactJson([
                'success' => false,
                'message' => 'Bạn không có quyền.',
            ]);
    }

    public function test_non_admin_cannot_query_chance_for_shop_outside_scope(): void
    {
        $ownShop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $actor = $this->createUser('employee', 'Actor');
        $this->attachShop($actor, $ownShop);
        $this->grantPermission($actor, 'view-chance');
        $this->createOrder($otherShop);

        $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/chance?shop_id={$otherShop->id}")
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Bạn không có quyền truy cập cửa hàng này.');
    }

    public function test_admin_uses_role_slug_convention_and_can_query_any_shop(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser('admin', 'Admin');
        $this->grantPermission($admin, 'view-chance');
        $order = $this->createOrder($shop);

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/orders/chance?shop_id={$shop->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.orders.0.id', $order->id);
    }

    public function test_reclaimed_order_returns_to_chance_pool_for_authorized_user(): void
    {
        $shop = $this->createShop();
        $actor = $this->createUser('employee', 'Actor');
        $assignee = $this->createUser('employee', 'Assignee');
        $this->attachShop($actor, $shop);
        $this->attachShop($assignee, $shop);
        $this->grantPermission($actor, 'view-chance');
        $order = $this->createOrder($shop);
        $care = $this->createCustomerCare($order, $assignee);
        $this->createAssignment($order, $care, $assignee, CustomerCareAssignment::STATUS_RECLAIMED);

        $this->actingAs($actor, 'api')
            ->getJson('/api/v1/orders/chance')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.orders.0.id', $order->id);
    }

    public function test_valid_assign_preserves_response_assignment_state_dates_and_activity_log(): void
    {
        $shop = $this->createShop();
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $shop);
        $this->attachShop($assignee, $shop);
        $this->grantPermission($actor, 'asign-cskh');
        $order = $this->createOrder($shop);

        $response = $this->actingAs($actor, 'api')->postJson(
            "/api/v1/customer-cares/{$order->id}/assign",
            [
                'pancake_user_ids' => [$assignee->pancake_user_id],
                'is_multiple' => false,
                'order_ids' => [],
            ]
        );

        $response->assertOk()->assertExactJson([
            'success' => true,
            'message' => 'Phân công thành công',
        ]);

        $assignment = CustomerCareAssignment::query()->sole();
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->status);
        $this->assertSame($order->id, $assignment->source_id);
        $this->assertSame($shop->id, $assignment->shop_id);
        $this->assertSame($assignee->id, $assignment->assignee_user_id);
        $this->assertSame('2026-08-21 10:15:00', $assignment->assigned_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-24', $assignment->reclaim_eligible_on->toDateString());

        $log = ActivityLog::query()->sole();
        $this->assertSame('customer_care.assigned', $log->action);
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame($assignee->id, $log->target_user_id);
        $this->assertSame($order->id, (int) $log->subject_id);
    }

    public function test_assign_without_asign_cskh_permission_is_denied_without_writes(): void
    {
        $shop = $this->createShop();
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $shop);
        $this->attachShop($assignee, $shop);
        $order = $this->createOrder($shop);

        $this->actingAs($actor, 'api')->postJson(
            "/api/v1/customer-cares/{$order->id}/assign",
            ['pancake_user_ids' => [$assignee->pancake_user_id], 'is_multiple' => false]
        )->assertOk()->assertExactJson([
            'success' => false,
            'message' => 'Bạn không có quyền.',
        ]);

        $this->assertDatabaseCount('customer_care_assignments', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_assign_rejects_source_order_outside_actor_shop_scope_atomically(): void
    {
        $actorShop = $this->createShop('Actor Shop');
        $sourceShop = $this->createShop('Source Shop');
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $actorShop);
        $this->attachShop($assignee, $sourceShop);
        $this->grantPermission($actor, 'asign-cskh');
        $order = $this->createOrder($sourceShop);

        $this->actingAs($actor, 'api')->postJson(
            "/api/v1/customer-cares/{$order->id}/assign",
            ['pancake_user_ids' => [$assignee->pancake_user_id], 'is_multiple' => false]
        )->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Bạn không có quyền phân công cơ hội thuộc cửa hàng này.');

        $this->assertDatabaseCount('customer_cares', 0);
        $this->assertDatabaseCount('customer_care_assignments', 0);
    }

    public function test_assign_rejects_assignee_from_different_shop_atomically(): void
    {
        $sourceShop = $this->createShop('Source Shop');
        $targetShop = $this->createShop('Target Shop');
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $sourceShop);
        $this->attachShop($assignee, $targetShop);
        $this->grantPermission($actor, 'asign-cskh');
        $order = $this->createOrder($sourceShop);

        $this->actingAs($actor, 'api')->postJson(
            "/api/v1/customer-cares/{$order->id}/assign",
            ['pancake_user_ids' => [$assignee->pancake_user_id], 'is_multiple' => false]
        )->assertOk()->assertJsonPath('success', false);

        $this->assertDatabaseCount('customer_cares', 0);
        $this->assertDatabaseCount('customer_care_assignments', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_import_assignment_enforces_actor_shop_scope_and_valid_flow_still_works(): void
    {
        $ownShop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $ownShop);
        $this->attachShop($assignee, $ownShop);
        $this->attachShop($assignee, $otherShop);
        $this->grantPermission($actor, 'asign-cskh');
        $outside = $this->createImportedOpportunity($otherShop);

        $this->actingAs($actor, 'api')->postJson('/api/v1/imported-opportunities/assign', [
            'ids' => [$outside->id],
            'pancake_user_ids' => [$assignee->pancake_user_id],
        ])->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Bạn không có quyền phân công cơ hội thuộc cửa hàng này.');

        $inside = $this->createImportedOpportunity($ownShop);
        $this->actingAs($actor, 'api')->postJson('/api/v1/imported-opportunities/assign', [
            'ids' => [$inside->id],
            'pancake_user_ids' => [$assignee->pancake_user_id],
        ])->assertOk()->assertExactJson([
            'success' => true,
            'message' => 'Phân công thành công',
        ]);

        $assignment = CustomerCareAssignment::query()->sole();
        $this->assertSame(CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY, $assignment->source_type);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->status);
        $this->assertSame(0, (int) $outside->fresh()->status);
        $this->assertSame(1, (int) $inside->fresh()->status);
        $this->assertSame('customer_care.assigned', ActivityLog::query()->sole()->action);
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
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_address')->nullable();
            $table->integer('status')->default(3);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('customer_cares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_customer_id');
            $table->string('pancake_order_id')->nullable();
            $table->text('customer_phones')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_addresss')->nullable();
            $table->date('date_care');
            $table->string('user_creator_id')->nullable();
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
            $table->date('reclaim_eligible_on');
            $table->string('status', 20)->default(CustomerCareAssignment::STATUS_ACTIVE);
            $table->timestamp('cared_at')->nullable();
            $table->timestamp('reclaimed_at')->nullable();
            $table->string('reclaim_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('imported_opportunities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('name');
            $table->string('phone');
            $table->string('address')->nullable();
            $table->integer('status')->default(0);
            $table->unsignedBigInteger('imported_by')->nullable();
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

    private function createUser(string $roleSlug, string $name): User
    {
        $role = Role::create(['name' => $roleSlug, 'slug' => $roleSlug]);
        $nextId = (int) User::query()->max('id') + 1;

        return User::create([
            'role_id' => $role->id,
            'name' => $name,
            'email' => strtolower($name)."-{$nextId}@example.test",
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

    private function createOrder(Shop $shop): Order
    {
        $nextId = (int) Order::query()->max('id') + 1;

        return Order::create([
            'shop_id' => $shop->id,
            'pancake_order_id' => "ORDER-{$nextId}",
            'pancake_customer_id' => "CUSTOMER-{$nextId}",
            'customer_name' => "Customer {$nextId}",
            'customer_phone' => "09000000{$nextId}",
            'customer_address' => "Address {$nextId}",
            'status' => 3,
        ]);
    }

    private function createCustomerCare(Order $order, User $assignee): CustomerCare
    {
        return CustomerCare::create([
            'shop_id' => $order->shop_id,
            'pancake_customer_id' => $order->pancake_customer_id,
            'pancake_order_id' => $order->pancake_order_id,
            'date_care' => '2026-08-24',
            'user_creator_id' => $assignee->pancake_user_id,
        ]);
    }

    private function createAssignment(
        Order $order,
        CustomerCare $care,
        User $assignee,
        string $status
    ): CustomerCareAssignment {
        return CustomerCareAssignment::create([
            'shop_id' => $order->shop_id,
            'customer_care_id' => $care->id,
            'source_type' => CustomerCareAssignment::SOURCE_ORDER,
            'source_id' => $order->id,
            'assignee_user_id' => $assignee->id,
            'assignee_pancake_user_id' => $assignee->pancake_user_id,
            'assigned_at' => '2026-08-18 10:15:00',
            'reclaim_eligible_on' => '2026-08-21',
            'status' => $status,
            'reclaimed_at' => $status === CustomerCareAssignment::STATUS_RECLAIMED
                ? '2026-08-21 09:00:00'
                : null,
        ]);
    }

    private function createImportedOpportunity(Shop $shop): ImportedOpportunity
    {
        $nextId = (int) ImportedOpportunity::query()->max('id') + 1;

        return ImportedOpportunity::create([
            'shop_id' => $shop->id,
            'name' => "Imported {$nextId}",
            'phone' => "09100000{$nextId}",
            'address' => "Imported address {$nextId}",
            'status' => 0,
        ]);
    }
}
