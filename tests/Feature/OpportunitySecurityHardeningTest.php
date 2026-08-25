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
use App\Services\CustomerCareReclaimService;
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
        Carbon::setTestNow('2026-08-24 23:50:00');
        $shop = $this->createShop('Three-day Shop', 3);
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
        $this->assertSame('2026-08-24 23:50:00', $assignment->assigned_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-27', $assignment->customerCare->date_care);
        $this->assertSame('2026-08-30', $assignment->reclaim_eligible_on->toDateString());

        $log = ActivityLog::query()->sole();
        $this->assertSame('customer_care.assigned', $log->action);
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame($assignee->id, $log->target_user_id);
        $this->assertSame($order->id, (int) $log->subject_id);
        $this->assertSame($assignment->customer_care_id, $log->metadata['customer_care_id']);
        $this->assertSame($assignment->id, $log->metadata['assignment_id']);
        $this->assertSame('order', $log->metadata['source_type']);
        $this->assertSame($order->id, $log->metadata['source_id']);
        $this->assertSame($assignee->id, $log->metadata['assignee_user_id']);
        $this->assertSame(
            $assignee->pancake_user_id,
            $log->metadata['assignee_pancake_user_id']
        );
        $this->assertNotEmpty($log->metadata['assigned_at']);
    }

    public function test_order_assignment_uses_five_day_shop_schedule_before_reclaim_grace(): void
    {
        Carbon::setTestNow('2026-08-24 23:50:00');
        $shop = $this->createShop('Five-day Shop', 5);
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $shop);
        $this->attachShop($assignee, $shop);
        $this->grantPermission($actor, 'asign-cskh');
        $order = $this->createOrder($shop);

        $this->actingAs($actor, 'api')->postJson(
            "/api/v1/customer-cares/{$order->id}/assign",
            ['pancake_user_ids' => [$assignee->pancake_user_id], 'is_multiple' => false]
        )->assertOk()->assertJsonPath('success', true);

        $assignment = CustomerCareAssignment::query()->sole();
        $this->assertSame('2026-08-29', $assignment->customerCare->date_care);
        $this->assertSame('2026-09-01', $assignment->reclaim_eligible_on->toDateString());
    }

    public function test_mixed_shop_batch_calculates_each_schedule_and_reclaim_date_independently(): void
    {
        Carbon::setTestNow('2026-08-24 23:50:00');
        $shopA = $this->createShop('Shop A', 3);
        $shopB = $this->createShop('Shop B', 5);
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $shopA);
        $this->attachShop($actor, $shopB);
        $this->attachShop($assignee, $shopA);
        $this->attachShop($assignee, $shopB);
        $this->grantPermission($actor, 'asign-cskh');
        $orderA = $this->createOrder($shopA);
        $orderB = $this->createOrder($shopB);

        $this->actingAs($actor, 'api')->postJson(
            "/api/v1/customer-cares/{$orderA->id}/assign",
            [
                'pancake_user_ids' => [$assignee->pancake_user_id],
                'is_multiple' => true,
                'order_ids' => [$orderA->id, $orderB->id],
            ]
        )->assertOk()->assertJsonPath('success', true);

        $assignmentA = CustomerCareAssignment::where('source_id', $orderA->id)->sole();
        $assignmentB = CustomerCareAssignment::where('source_id', $orderB->id)->sole();
        $this->assertSame('2026-08-27', $assignmentA->customerCare->date_care);
        $this->assertSame('2026-08-30', $assignmentA->reclaim_eligible_on->toDateString());
        $this->assertSame('2026-08-29', $assignmentB->customerCare->date_care);
        $this->assertSame('2026-09-01', $assignmentB->reclaim_eligible_on->toDateString());
    }

    public function test_zero_day_shop_setting_schedules_same_local_day_and_keeps_reclaim_grace(): void
    {
        Carbon::setTestNow('2026-08-24 23:50:00');
        $shop = $this->createShop('Zero-day Shop', 0);
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $shop);
        $this->attachShop($assignee, $shop);
        $this->grantPermission($actor, 'asign-cskh');
        $order = $this->createOrder($shop);

        $this->actingAs($actor, 'api')->postJson(
            "/api/v1/customer-cares/{$order->id}/assign",
            ['pancake_user_ids' => [$assignee->pancake_user_id], 'is_multiple' => false]
        )->assertOk()->assertJsonPath('success', true);

        $assignment = CustomerCareAssignment::query()->sole();
        $this->assertSame('2026-08-24', $assignment->customerCare->date_care);
        $this->assertSame('2026-08-27', $assignment->reclaim_eligible_on->toDateString());
    }

    public function test_overdue_assignment_inside_grace_is_listed_but_not_reclaim_eligible(): void
    {
        Carbon::setTestNow('2026-08-24 10:15:00');
        DB::connection()->getPdo()->sqliteCreateFunction(
            'concat',
            fn (...$parts) => implode('', $parts)
        );
        $shop = $this->createShop('Grace Shop', 3);
        $admin = $this->createUser('admin', 'Admin');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($assignee, $shop);
        $order = $this->createOrder($shop);
        $care = $this->createCustomerCare($order, $assignee, '2026-08-23');
        $assignment = $this->createAssignment(
            $order,
            $care,
            $assignee,
            CustomerCareAssignment::STATUS_ACTIVE,
            '2026-08-27'
        );

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_expire&page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customers.0.id', $care->id);

        $evaluation = $this->app->make(CustomerCareReclaimService::class)
            ->evaluate($assignment, Carbon::now());

        $this->assertSame(CustomerCareReclaimService::RESULT_NOT_YET_DUE, $evaluation['result']);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->fresh()->status);
    }

    public function test_assign_rejects_non_status_three_order_without_writes(): void
    {
        $shop = $this->createShop();
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $shop);
        $this->attachShop($assignee, $shop);
        $this->grantPermission($actor, 'asign-cskh');
        $order = $this->createOrder($shop, 2);

        $this->actingAs($actor, 'api')->postJson(
            "/api/v1/customer-cares/{$order->id}/assign",
            ['pancake_user_ids' => [$assignee->pancake_user_id], 'is_multiple' => false]
        )->assertOk()->assertJsonPath('success', false);

        $this->assertDatabaseCount('customer_cares', 0);
        $this->assertDatabaseCount('customer_care_assignments', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_mixed_status_batch_is_rejected_atomically(): void
    {
        $shop = $this->createShop();
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $shop);
        $this->attachShop($assignee, $shop);
        $this->grantPermission($actor, 'asign-cskh');
        $validOrder = $this->createOrder($shop, 3);
        $invalidOrder = $this->createOrder($shop, 2);

        $this->actingAs($actor, 'api')->postJson(
            "/api/v1/customer-cares/{$validOrder->id}/assign",
            [
                'pancake_user_ids' => [$assignee->pancake_user_id],
                'is_multiple' => true,
                'order_ids' => [$validOrder->id, $invalidOrder->id],
            ]
        )->assertOk()->assertJsonPath('success', false);

        $this->assertDatabaseCount('customer_cares', 0);
        $this->assertDatabaseCount('customer_care_assignments', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_assign_rejects_non_cskh_assignee_in_same_shop(): void
    {
        $shop = $this->createShop();
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('employee', 'Assignee');
        $this->attachShop($actor, $shop);
        $this->attachShop($assignee, $shop);
        $this->grantPermission($actor, 'asign-cskh');
        $order = $this->createOrder($shop);

        $this->actingAs($actor, 'api')->postJson(
            "/api/v1/customer-cares/{$order->id}/assign",
            ['pancake_user_ids' => [$assignee->pancake_user_id], 'is_multiple' => false]
        )->assertStatus(422)->assertJsonPath('success', false);

        $this->assertDatabaseCount('customer_cares', 0);
        $this->assertDatabaseCount('customer_care_assignments', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_assign_preserves_duplicate_legacy_cares_and_links_only_new_care(): void
    {
        $shop = $this->createShop();
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $shop);
        $this->attachShop($assignee, $shop);
        $this->grantPermission($actor, 'asign-cskh');
        $order = $this->createOrder($shop);
        $legacyOne = $this->createCustomerCare($order, $assignee, '2026-08-19', 'Legacy one');
        $legacyTwo = $this->createCustomerCare($order, $assignee, '2026-08-20', 'Legacy two');

        $this->actingAs($actor, 'api')->postJson(
            "/api/v1/customer-cares/{$order->id}/assign",
            ['pancake_user_ids' => [$assignee->pancake_user_id], 'is_multiple' => false]
        )->assertOk()->assertJsonPath('success', true);

        $assignment = CustomerCareAssignment::query()->sole();
        $this->assertNotContains(
            $assignment->customer_care_id,
            [$legacyOne->id, $legacyTwo->id]
        );
        $this->assertSame('Legacy one', $legacyOne->fresh()->note);
        $this->assertSame('Legacy two', $legacyTwo->fresh()->note);
        $this->assertSame(3, CustomerCare::query()->count());
        $this->assertSame(
            [$assignment->customer_care_id],
            CustomerCare::query()->actionable()->pluck('id')->all()
        );
    }

    public function test_assignment_user_endpoint_only_returns_cskh_roles(): void
    {
        $shop = $this->createShop();
        $manager = $this->createUser('manager-cskh', 'Manager');
        $staff = $this->createUser('staff-cskh', 'Staff');
        $employee = $this->createUser('employee', 'Employee');
        $this->attachShop($manager, $shop);
        $this->attachShop($staff, $shop);
        $this->attachShop($employee, $shop);

        $response = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/users/all-user?shop_id={$shop->id}&assignment_eligible=1")
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($manager->id));
        $this->assertTrue($ids->contains($staff->id));
        $this->assertFalse($ids->contains($employee->id));
    }

    public function test_actionable_lists_only_return_active_order_care_and_keep_imported_care(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction(
            'concat',
            fn (...$parts) => implode('', $parts)
        );

        $shop = $this->createShop();
        $admin = $this->createUser('admin', 'Admin');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($assignee, $shop);

        $realToday = date('Y-m-d');
        $types = [
            'customer_care_today' => $realToday,
            'customer_care_pending' => date('Y-m-d', strtotime($realToday.' +3 days')),
            'customer_care_expire' => date('Y-m-d', strtotime($realToday.' -1 day')),
        ];

        foreach ($types as $type => $dateCare) {
            $order = $this->createOrder($shop);
            $legacy = $this->createCustomerCare($order, $assignee, $dateCare, 'Legacy');
            $active = $this->createCustomerCare($order, $assignee, $dateCare, 'Active');
            $assignment = $this->createAssignment(
                $order,
                $active,
                $assignee,
                CustomerCareAssignment::STATUS_ACTIVE
            );

            $response = $this->actingAs($admin, 'api')
                ->getJson("/api/v1/customer-cares?type={$type}&page=1")
                ->assertOk()
                ->assertJsonPath('success', true);

            $ids = collect($response->json('data.customers'))->pluck('id');
            $this->assertTrue($ids->contains($active->id));
            $this->assertFalse($ids->contains($legacy->id));
            $response->assertJsonPath(
                'data.customers.0.active_assignment.customer_care_id',
                $assignment->customer_care_id
            );
            $response->assertJsonPath('data.customers.0.active_assignment.assignee.id', $assignee->id);
            $response->assertJsonPath('data.customers.0.active_assignment.assignee.name', $assignee->name);
        }

        $unassignedOrder = $this->createOrder($shop);
        $unassignedLegacy = $this->createCustomerCare(
            $unassignedOrder,
            $assignee,
            $realToday,
            'Unassigned legacy'
        );
        $importedCare = CustomerCare::create([
            'shop_id' => $shop->id,
            'pancake_customer_id' => 'IMPORT-KEEP',
            'pancake_order_id' => null,
            'date_care' => $realToday,
            'note' => 'Imported semantics stay unchanged',
            'user_creator_id' => $assignee->pancake_user_id,
        ]);
        $nonOpportunityOrder = $this->createOrder($shop, 2);
        $nonOpportunityCare = $this->createCustomerCare(
            $nonOpportunityOrder,
            $assignee,
            $realToday,
            'Existing non-opportunity care remains actionable'
        );

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_today&page=1')
            ->assertOk();
        $ids = collect($response->json('data.customers'))->pluck('id');
        $this->assertFalse($ids->contains($unassignedLegacy->id));
        $this->assertTrue($ids->contains($importedCare->id));
        $this->assertTrue($ids->contains($nonOpportunityCare->id));
    }

    public function test_upcoming_keeps_cared_active_task_with_exact_manager_assignee_and_source_order(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser('admin', 'Admin');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $exactManager = $this->createUser('employee', 'Exact Manager');
        $roleOnlyManager = $this->createUser('manager', 'Role Only Manager');
        $this->attachShop($assignee, $shop);
        $this->attachShop($exactManager, $shop, true);
        $this->attachShop($roleOnlyManager, $shop);

        $ambiguousOrder = $this->createOrder($shop, 2);
        $sourceOrder = $this->createOrder($shop, 3);
        $sourceOrder->update(['pancake_order_id' => $ambiguousOrder->pancake_order_id]);
        $futureDate = date('Y-m-d', strtotime(date('Y-m-d').' +3 days'));
        $care = $this->createCustomerCare(
            $sourceOrder->fresh(),
            $assignee,
            $futureDate,
            'Already cared but still upcoming'
        );
        $care->update([
            'status' => 1,
            'time_care' => date('Y-m-d H:i:s'),
        ]);
        $assignment = $this->createAssignment(
            $sourceOrder->fresh(),
            $care,
            $assignee,
            CustomerCareAssignment::STATUS_ACTIVE
        );
        $assignment->update(['cared_at' => date('Y-m-d H:i:s')]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_pending&page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.customers')
            ->assertJsonPath('data.customers.0.id', $care->id)
            ->assertJsonPath('data.customers.0.status', 1)
            ->assertJsonPath('data.customers.0.shop.managers.0.id', $exactManager->id)
            ->assertJsonPath('data.customers.0.shop.managers.0.name', $exactManager->name)
            ->assertJsonPath('data.customers.0.active_assignment.customer_care_id', $care->id)
            ->assertJsonPath('data.customers.0.active_assignment.assignee.id', $assignee->id)
            ->assertJsonPath('data.customers.0.active_assignment.assignee.name', $assignee->name)
            ->assertJsonPath('data.customers.0.active_assignment.source_order.id', $sourceOrder->id)
            ->assertJsonPath('data.customers.0.active_assignment.source_order.status', 3);

        $managerIds = collect($response->json('data.customers.0.shop.managers'))->pluck('id');
        $this->assertSame([$exactManager->id], $managerIds->all());
        $this->assertFalse($managerIds->contains($roleOnlyManager->id));

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_pending&status=0&page=1')
            ->assertOk()
            ->assertJsonCount(0, 'data.customers');

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_pending&status=1&page=1')
            ->assertOk()
            ->assertJsonPath('data.customers.0.id', $care->id);
    }

    public function test_legacy_edit_requests_remain_visible_but_are_hidden_from_actionable_task_lists(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction(
            'concat',
            fn (...$parts) => implode('', $parts)
        );

        $shop = $this->createShop();
        $admin = $this->createUser('admin', 'Admin');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $order = $this->createOrder($shop, 3);
        $today = date('Y-m-d');
        $legacyByType = [
            'customer_care_today' => $today,
            'customer_care_pending' => date('Y-m-d', strtotime($today.' +1 day')),
            'customer_care_expire' => date('Y-m-d', strtotime($today.' -1 day')),
        ];

        foreach ($legacyByType as $type => $dateCare) {
            $legacyCare = $this->createCustomerCare(
                $order,
                $assignee,
                $dateCare,
                "Legacy edit {$type}"
            );
            $legacyCare->update(['total_edit' => 2, 'is_accept' => 1]);

            $taskResponse = $this->actingAs($admin, 'api')
                ->getJson("/api/v1/customer-cares?type={$type}&page=1")
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertNotContains(
                $legacyCare->id,
                collect($taskResponse->json('data.customers'))->pluck('id')->all()
            );
        }

        $editResponse = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_edit&page=1')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEqualsCanonicalizing(
            CustomerCare::query()->pluck('id')->all(),
            collect($editResponse->json('data.customers'))->pluck('id')->all()
        );

        $overview = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/overview')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(3, $overview->json('data.customer_care_edit'));
        $this->assertSame(3, $overview->json('data.customer_care_edit_accepted'));
        $this->assertSame(0, $overview->json('data.customer_care_today'));
        $this->assertSame(0, $overview->json('data.customer_care_pending'));
        $this->assertSame(0, $overview->json('data.customer_care_expire'));
    }

    public function test_duplicate_local_orders_use_active_assignment_source_identity_for_actionable_scope(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser('admin', 'Admin');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $orderA = $this->createOrder($shop, 3);
        $orderB = $this->createOrder($shop, 3);
        $orderB->update(['pancake_order_id' => $orderA->pancake_order_id]);
        $today = date('Y-m-d');
        $legacyCare = $this->createCustomerCare($orderA, $assignee, $today, 'Legacy');
        $activeCare = $this->createCustomerCare($orderB->fresh(), $assignee, $today, 'Active');
        $this->createAssignment(
            $orderB->fresh(),
            $activeCare,
            $assignee,
            CustomerCareAssignment::STATUS_ACTIVE
        );

        $actionableIds = CustomerCare::query()->actionable()->pluck('id')->all();

        $this->assertSame([$activeCare->id], $actionableIds);
        $this->assertNotContains($legacyCare->id, $actionableIds);

        $listResponse = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_today&page=1')
            ->assertOk()
            ->assertJsonPath('success', true);
        $listedIds = collect($listResponse->json('data.customers'))->pluck('id')->all();

        $this->assertSame([$activeCare->id], $listedIds);
        $this->assertNotContains($legacyCare->id, $listedIds);
    }

    public function test_duplicate_order_identity_is_null_when_unassigned_and_exact_when_active(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser('admin', 'Admin');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $orderA = $this->createOrder($shop, 3);
        $orderB = $this->createOrder($shop, 3);
        $orderB->update(['pancake_order_id' => $orderA->pancake_order_id]);
        $legacyCare = $this->createCustomerCare($orderA, $assignee, date('Y-m-d'), 'Legacy edit');
        $legacyCare->update(['total_edit' => 2, 'is_accept' => 1]);
        $activeCare = $this->createCustomerCare($orderB->fresh(), $assignee, date('Y-m-d'), 'Active edit');
        $activeCare->update(['total_edit' => 2, 'is_accept' => 1]);
        $this->createAssignment(
            $orderB->fresh(),
            $activeCare,
            $assignee,
            CustomerCareAssignment::STATUS_ACTIVE
        );

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_edit&page=1')
            ->assertOk()
            ->assertJsonPath('success', true);

        $cares = collect($response->json('data.customers'))->keyBy('id');
        $this->assertNull($cares[$legacyCare->id]['assignable_order_id']);
        $this->assertSame($orderB->id, $cares[$activeCare->id]['assignable_order_id']);
    }

    public function test_assignment_rejects_duplicate_logical_order_when_sibling_has_active_assignment(): void
    {
        $shop = $this->createShop();
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $shop);
        $this->attachShop($assignee, $shop);
        $this->grantPermission($actor, 'asign-cskh');
        $orderA = $this->createOrder($shop, 3);
        $orderB = $this->createOrder($shop, 3);
        $orderB->update(['pancake_order_id' => $orderA->pancake_order_id]);
        $existingCare = $this->createCustomerCare($orderA, $assignee, date('Y-m-d'), 'Existing active');
        $this->createAssignment(
            $orderA,
            $existingCare,
            $assignee,
            CustomerCareAssignment::STATUS_ACTIVE
        );

        $this->actingAs($actor, 'api')->postJson(
            "/api/v1/customer-cares/{$orderB->id}/assign",
            ['pancake_user_ids' => [$assignee->pancake_user_id], 'is_multiple' => false]
        )->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Đơn Pancake này đã có phân công CSKH đang hoạt động.');

        $this->assertDatabaseCount('customer_cares', 1);
        $this->assertDatabaseCount('customer_care_assignments', 1);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_multi_active_duplicate_orders_keep_each_exact_care_actionable_and_hide_legacy(): void
    {
        $shop = $this->createShop();
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $orderA = $this->createOrder($shop, 3);
        $orderB = $this->createOrder($shop, 3);
        $orderB->update(['pancake_order_id' => $orderA->pancake_order_id]);
        $legacyCare = $this->createCustomerCare($orderA, $assignee, date('Y-m-d'), 'Legacy');
        $activeCareA = $this->createCustomerCare($orderA, $assignee, date('Y-m-d'), 'Active A');
        $activeCareB = $this->createCustomerCare($orderB->fresh(), $assignee, date('Y-m-d'), 'Active B');
        $this->createAssignment($orderA, $activeCareA, $assignee, CustomerCareAssignment::STATUS_ACTIVE);
        $this->createAssignment($orderB->fresh(), $activeCareB, $assignee, CustomerCareAssignment::STATUS_ACTIVE);

        $actionableIds = CustomerCare::query()->actionable()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$activeCareA->id, $activeCareB->id], $actionableIds);
        $this->assertNotContains($legacyCare->id, $actionableIds);
    }

    public function test_status_mixed_duplicate_orders_fail_closed_for_unassigned_legacy_care(): void
    {
        $shop = $this->createShop();
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $statusThreeOrder = $this->createOrder($shop, 3);
        $statusTwoOrder = $this->createOrder($shop, 2);
        $statusTwoOrder->update(['pancake_order_id' => $statusThreeOrder->pancake_order_id]);
        $legacyCare = $this->createCustomerCare($statusThreeOrder, $assignee, date('Y-m-d'), 'Ambiguous legacy');

        $this->assertNotContains(
            $legacyCare->id,
            CustomerCare::query()->actionable()->pluck('id')->all()
        );
    }

    public function test_staff_and_monitoring_workload_aggregates_count_only_actionable_cares(): void
    {
        Carbon::setTestNow(date('Y-m-d').' 10:15:00');
        DB::connection()->getPdo()->sqliteCreateFunction(
            'concat',
            fn (...$parts) => implode('', $parts)
        );
        DB::connection()->getPdo()->sqliteCreateFunction(
            'curdate',
            fn () => now()->toDateString()
        );

        $shop = $this->createShop();
        $admin = $this->createUser('admin', 'Admin');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $assignee->update(['pancake_user_id' => (string) $assignee->id]);
        $assignee->refresh();
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $overdueOrder = $this->createOrder($shop, 3);
        $this->createCustomerCare($overdueOrder, $assignee, $yesterday, 'Legacy overdue');
        $activeOverdue = $this->createCustomerCare(
            $overdueOrder,
            $assignee,
            $yesterday,
            'Active overdue'
        );
        $this->createAssignment(
            $overdueOrder,
            $activeOverdue,
            $assignee,
            CustomerCareAssignment::STATUS_ACTIVE
        );

        $todayOrder = $this->createOrder($shop, 3);
        $legacyToday = $this->createCustomerCare(
            $todayOrder,
            $assignee,
            $today,
            'Legacy today done'
        );
        $legacyToday->update(['status' => 1, 'time_care' => "{$today} 09:00:00"]);
        $activeToday = $this->createCustomerCare(
            $todayOrder,
            $assignee,
            $today,
            'Active today pending'
        );
        $this->createAssignment(
            $todayOrder,
            $activeToday,
            $assignee,
            CustomerCareAssignment::STATUS_ACTIVE
        );

        $overdueListResponse = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_expire&page=1')
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertSame(
            [$activeOverdue->id],
            collect($overdueListResponse->json('data.customers'))->pluck('id')->all()
        );

        $todayListResponse = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_today&page=1')
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertSame(
            [$activeToday->id],
            collect($todayListResponse->json('data.customers'))->pluck('id')->all()
        );

        $staffResponse = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-assigned-by-staff?page=1')
            ->assertOk()
            ->assertJsonPath('success', true);
        $staffMetrics = collect($staffResponse->json('data.customers'))
            ->firstWhere('id', $assignee->id);

        $this->assertNotNull($staffMetrics);
        $this->assertSame(1, $staffMetrics['expired_total']);
        $this->assertSame(1, $staffMetrics['today_total']);
        $this->assertSame(0, $staffMetrics['today_done']);

        $monitoringResponse = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/users/mornitoring')
            ->assertOk()
            ->assertJsonPath('success', true);
        $monitoringMetrics = collect($monitoringResponse->json('data'))
            ->firstWhere('id', $assignee->pancake_user_id);

        $this->assertNotNull($monitoringMetrics);
        $this->assertSame(1, $monitoringMetrics['overdue_care_count']);
        $this->assertEquals(0.0, (float) $monitoringMetrics['care_progress_today']);
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
        Carbon::setTestNow('2026-08-24 23:50:00');
        $ownShop = $this->createShop('Own Shop', 5);
        $otherShop = $this->createShop('Other Shop', 3);
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
        $this->assertSame('2026-08-29', $assignment->customerCare->date_care);
        $this->assertSame('2026-09-01', $assignment->reclaim_eligible_on->toDateString());
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
            $table->integer('care_cycle_days')->default(5);
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
            $table->string('user_creator_id')->nullable();
            $table->decimal('cod', 15, 2)->default(0);
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
            $table->text('note')->nullable();
            $table->string('user_creator_id')->nullable();
            $table->string('user_care_id')->nullable();
            $table->string('user_assigning_seller_id')->nullable();
            $table->integer('status')->default(0);
            $table->dateTime('time_care')->nullable();
            $table->boolean('is_accept')->default(true);
            $table->unsignedBigInteger('user_accept_id')->nullable();
            $table->integer('total_edit')->default(0);
            $table->string('reason')->nullable();
            $table->boolean('is_confirm_care')->default(false);
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

    private function createShop(string $name = 'Shop', int $careCycleDays = 5): Shop
    {
        return Shop::create([
            'name' => $name,
            'care_cycle_days' => $careCycleDays,
        ]);
    }

    private function attachShop(User $user, Shop $shop, bool $isManager = false): void
    {
        $user->shops()->attach($shop->id, ['is_manager' => $isManager]);
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

    private function createOrder(Shop $shop, int $status = 3): Order
    {
        $nextId = (int) Order::query()->max('id') + 1;

        return Order::create([
            'shop_id' => $shop->id,
            'pancake_order_id' => "ORDER-{$nextId}",
            'pancake_customer_id' => "CUSTOMER-{$nextId}",
            'customer_name' => "Customer {$nextId}",
            'customer_phone' => "09000000{$nextId}",
            'customer_address' => "Address {$nextId}",
            'status' => $status,
        ]);
    }

    private function createCustomerCare(
        Order $order,
        User $assignee,
        string $dateCare = '2026-08-24',
        ?string $note = null
    ): CustomerCare
    {
        return CustomerCare::create([
            'shop_id' => $order->shop_id,
            'pancake_customer_id' => $order->pancake_customer_id,
            'pancake_order_id' => $order->pancake_order_id,
            'date_care' => $dateCare,
            'note' => $note,
            'user_creator_id' => $assignee->pancake_user_id,
        ]);
    }

    private function createAssignment(
        Order $order,
        CustomerCare $care,
        User $assignee,
        string $status,
        string $eligibleOn = '2026-08-21'
    ): CustomerCareAssignment {
        return CustomerCareAssignment::create([
            'shop_id' => $order->shop_id,
            'customer_care_id' => $care->id,
            'source_type' => CustomerCareAssignment::SOURCE_ORDER,
            'source_id' => $order->id,
            'assignee_user_id' => $assignee->id,
            'assignee_pancake_user_id' => $assignee->pancake_user_id,
            'assigned_at' => '2026-08-18 10:15:00',
            'reclaim_eligible_on' => $eligibleOn,
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
