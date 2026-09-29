<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\ImportedOpportunity;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\CustomerCareReclaimService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
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

    public function test_chance_returns_page_snapshot_and_filters_exact_string_id_with_pagination(): void
    {
        $shop = $this->createShop('Own Shop');
        $actor = $this->createUser('employee', 'Actor');
        $this->attachShop($actor, $shop);
        $this->grantPermission($actor, 'view-chance');

        $orderWithoutPage = $this->createOrder($shop);
        $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/chance?shop_id={$shop->id}")
            ->assertOk()
            ->assertJsonPath('data.orders.0.id', $orderWithoutPage->id)
            ->assertJsonPath('data.orders.0.order_page_id', null)
            ->assertJsonPath('data.orders.0.order_page_name', null)
            ->assertJsonMissingPath('data.orders.0.pancake_order_page_id')
            ->assertJsonMissingPath('data.orders.0.pancake_order_page_name');

        $pageId = 'pzl_695112902870160686';
        for ($index = 0; $index < 31; $index++) {
            $order = $this->createOrder($shop);
            $order->forceFill([
                'pancake_order_page_id' => $pageId,
                'pancake_order_page_name' => 'PZL Page',
            ])->save();
        }
        $similarPage = $this->createOrder($shop);
        $similarPage->forceFill([
            'pancake_order_page_id' => $pageId.'0',
            'pancake_order_page_name' => 'Similar Page',
        ])->save();

        $this->getJson("/api/v1/orders/chance?shop_id={$shop->id}&order_page_id={$pageId}&page=2")
            ->assertOk()
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.per_page', 30)
            ->assertJsonPath('data.total_items', 31)
            ->assertJsonPath('data.total_pages', 2)
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.order_page_id', $pageId)
            ->assertJsonPath('data.orders.0.order_page_name', 'PZL Page');

        $this->getJson("/api/v1/orders/chance?shop_id={$shop->id}&order_page_id[]=invalid")
            ->assertUnprocessable();
    }

    public function test_chance_filters_orders_by_partial_customer_phone(): void
    {
        $shop = $this->createShop('Own Shop');
        $actor = $this->createUser('employee', 'Actor');
        $this->attachShop($actor, $shop);
        $this->grantPermission($actor, 'view-chance');
        $matchingOrder = $this->createOrder($shop);
        $matchingOrder->forceFill(['customer_phone' => '0912345678'])->save();
        $this->createOrder($shop)->forceFill(['customer_phone' => '0987654321'])->save();

        $this->actingAs($actor, 'api')
            ->getJson("/api/v1/orders/chance?shop_id={$shop->id}&phone=1234")
            ->assertOk()
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.id', $matchingOrder->id);

        $this->getJson("/api/v1/orders/chance?shop_id={$shop->id}&phone[]=invalid")
            ->assertUnprocessable();
    }

    public function test_opportunity_page_options_match_chance_scope_without_regressing_default_scope(): void
    {
        $shop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $staff = $this->createUser('staff-sale', 'Staff');
        $this->attachShop($staff, $shop);
        $this->grantPermission($staff, 'view-chance');

        $visible = $this->createOrder($shop);
        $visible->forceFill([
            'pancake_order_page_id' => 'pzl_visible',
            'pancake_order_page_name' => 'Visible Opportunity',
        ])->save();

        $notOpportunity = $this->createOrder($shop, 2);
        $notOpportunity->forceFill([
            'pancake_order_page_id' => 'wrong-status',
            'pancake_order_page_name' => 'Wrong Status',
        ])->save();

        $assigned = $this->createOrder($shop);
        $assigned->forceFill([
            'pancake_order_page_id' => 'already-assigned',
            'pancake_order_page_name' => 'Already Assigned',
        ])->save();
        $care = $this->createCustomerCare($assigned, $staff);
        $this->createAssignment($assigned, $care, $staff, CustomerCareAssignment::STATUS_ACTIVE);

        $this->createOrder($shop);
        $outside = $this->createOrder($otherShop);
        $outside->forceFill([
            'pancake_order_page_id' => 'outside',
            'pancake_order_page_name' => 'Outside Shop',
        ])->save();

        $this->actingAs($staff, 'api')
            ->getJson('/api/v1/order-pages?shop_id='.$shop->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/order-pages?shop_id='.$shop->id.'&context=opportunity')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    ['id' => 'pzl_visible', 'name' => 'Visible Opportunity'],
                ],
            ]);
        $this->getJson('/api/v1/order-pages?shop_id='.$otherShop->id.'&context=opportunity')
            ->assertForbidden();

        $withoutPermission = $this->createUser('employee', 'Without Permission');
        $this->attachShop($withoutPermission, $shop);
        $this->actingAs($withoutPermission, 'api')
            ->getJson('/api/v1/order-pages?shop_id='.$shop->id.'&context=opportunity')
            ->assertForbidden();

        $manager = $this->createUser('manager-cskh', 'Manager');
        $this->attachShop($manager, $shop);
        $this->grantPermission($manager, 'view-chance');
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/order-pages?shop_id='.$shop->id.'&context=opportunity')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'pzl_visible');

        $admin = $this->createUser('admin', 'Admin');
        $this->grantPermission($admin, 'view-chance');
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/order-pages?shop_id='.$otherShop->id.'&context=opportunity')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'outside');
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
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to the requested shop.');
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
        $customerCare = $assignment->customerCare;
        $this->assertNotSame($actor->id, $assignee->id);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->status);
        $this->assertSame($order->id, $assignment->source_id);
        $this->assertSame($shop->id, $assignment->shop_id);
        $this->assertSame($assignee->id, $assignment->assignee_user_id);
        $this->assertSame($actor->pancake_user_id, $customerCare->user_creator_id);
        $this->assertSame($actor->id, $customerCare->user_creator->id);
        $this->assertSame($order->customer_address, $customerCare->customer_addresss);
        $this->assertSame('2026-08-24 23:50:00', $assignment->assigned_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-27', $customerCare->date_care);
        $this->assertSame('2026-08-30', $assignment->reclaim_eligible_on->toDateString());

        $log = ActivityLog::query()->sole();
        $this->assertSame('customer_care.assigned', $log->action);
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame($assignee->id, $log->target_user_id);
        $this->assertSame($assignment->id, (int) $log->subject_id);
        $this->assertSame('customer_care_assignment', $log->subject_type);
        $this->assertSame('2026-08-24 23:50:00', $log->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            'customer_care.assigned:assignment:'.$assignment->id,
            $log->idempotency_key
        );
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

        $listResponse = $this->actingAs($actor, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_pending&page=1')
            ->assertOk()
            ->assertJsonPath('data.customers.0.id', $customerCare->id)
            ->assertJsonPath('data.customers.0.user_creator.id', $actor->id)
            ->assertJsonPath('data.customers.0.user_creator.name', $actor->name)
            ->assertJsonPath('data.customers.0.user_assigning.id', $assignee->id)
            ->assertJsonPath('data.customers.0.user_assigning.name', $assignee->name)
            ->assertJsonPath('data.customers.0.legacy_user_assigning', null)
            ->assertJsonPath('data.customers.0.customer_addresss', $order->customer_address)
            ->assertJsonPath('data.customers.0.time_care', null)
            ->assertJsonPath('data.customers.0.current_assignment.assignee.id', $assignee->id)
            ->assertJsonPath('data.customers.0.current_assignment_ambiguous', false);

        $this->assertSame(['id', 'name'], array_keys($listResponse->json('data.customers.0.user_assigning')));
    }

    public function test_upcoming_care_api_returns_the_legacy_customer_address_field(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser('admin', 'Admin');
        $order = $this->createOrder($shop, 2);
        $care = CustomerCare::create([
            'shop_id' => $shop->id,
            'pancake_customer_id' => $order->pancake_customer_id,
            'pancake_order_id' => $order->pancake_order_id,
            'customer_addresss' => 'Address returned by the upcoming-care API',
            'date_care' => date('Y-m-d', strtotime('+1 day')),
            'status' => 0,
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_pending&page=1')
            ->assertOk()
            ->assertJsonPath('data.customers.0.id', $care->id)
            ->assertJsonPath(
                'data.customers.0.customer_addresss',
                'Address returned by the upcoming-care API'
            );
    }

    public function test_order_assignment_preserves_null_address(): void
    {
        $shop = $this->createShop();
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $shop);
        $this->attachShop($assignee, $shop);
        $this->grantPermission($actor, 'asign-cskh');
        $order = $this->createOrder($shop);
        $order->update(['customer_address' => null]);

        $this->actingAs($actor, 'api')->postJson(
            "/api/v1/customer-cares/{$order->id}/assign",
            ['pancake_user_ids' => [$assignee->pancake_user_id], 'is_multiple' => false]
        )->assertOk()->assertJsonPath('success', true);

        $this->assertNull(CustomerCare::query()->sole()->customer_addresss);
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
            $response->assertJsonPath(
                'data.customers.0.current_assignment.customer_care_id',
                $assignment->customer_care_id
            );
            $response->assertJsonPath('data.customers.0.current_assignment.assignee.id', $assignee->id);
            $response->assertJsonPath('data.customers.0.current_assignment.assignee.name', $assignee->name);
            $response->assertJsonPath('data.customers.0.current_assignment_ambiguous', false);
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
            'user_assigning_seller_id' => $roleOnlyManager->pancake_user_id,
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
            ->assertJsonPath('data.customers.0.active_assignment.source_order.status', 3)
            ->assertJsonPath('data.customers.0.current_assignment', null)
            ->assertJsonPath('data.customers.0.current_assignment_ambiguous', false)
            ->assertJsonPath('data.customers.0.user_assigning.id', $assignee->id)
            ->assertJsonPath('data.customers.0.user_assigning.name', $assignee->name)
            ->assertJsonPath('data.customers.0.legacy_user_assigning.id', $roleOnlyManager->id)
            ->assertJsonPath('data.customers.0.legacy_user_assigning.name', $roleOnlyManager->name)
            ->assertJsonPath('data.customers.0.shop.users.0.id', $exactManager->id)
            ->assertJsonPath('data.customers.0.shop.users.0.name', $exactManager->name);

        $this->assertNotNull($response->json('data.customers.0.active_assignment.cared_at'));
        $this->assertSame($assignment->id, $care->activeAssignment()->sole()->id);

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

    public function test_reclaimed_assignment_is_not_exposed_as_current(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser('admin', 'Admin');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $order = $this->createOrder($shop);
        $care = $this->createCustomerCare($order, $assignee);
        $care->update([
            'total_edit' => 2,
            'is_accept' => 1,
            'user_care_id' => $assignee->pancake_user_id,
        ]);
        $assignment = $this->createAssignment(
            $order,
            $care,
            $assignee,
            CustomerCareAssignment::STATUS_RECLAIMED
        );

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_edit&page=1')
            ->assertOk()
            ->assertJsonPath('data.customers.0.id', $care->id)
            ->assertJsonPath('data.customers.0.active_assignment', null)
            ->assertJsonPath('data.customers.0.current_assignment', null)
            ->assertJsonPath('data.customers.0.current_assignment_ambiguous', false)
            ->assertJsonPath('data.customers.0.user_assigning', null);

        $this->assertSame(
            CustomerCareAssignment::STATUS_RECLAIMED,
            CustomerCareAssignment::query()->findOrFail($assignment->id)->status
        );
    }

    public function test_list_preserves_legacy_assignee_data_without_synthesizing_assignment_data(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser('admin', 'Admin');
        $legacyAssignee = $this->createUser('staff-cskh', 'Legacy Assignee');
        $legacyOrder = $this->createOrder($shop, 2);
        $emptyOrder = $this->createOrder($shop, 2);
        $legacyCare = $this->createCustomerCare(
            $legacyOrder,
            $legacyAssignee,
            date('Y-m-d')
        );
        $legacyCare->update(['user_care_id' => $legacyAssignee->pancake_user_id]);
        $emptyCare = $this->createCustomerCare(
            $emptyOrder,
            $legacyAssignee,
            date('Y-m-d')
        );

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_today&page=1')
            ->assertOk()
            ->assertJsonPath('success', true);

        $cares = collect($response->json('data.customers'))->keyBy('id');
        $this->assertNull($cares[$legacyCare->id]['current_assignment']);
        $this->assertFalse($cares[$legacyCare->id]['current_assignment_ambiguous']);
        $this->assertSame($legacyAssignee->name, $cares[$legacyCare->id]['user_care']['name']);
        $this->assertSame($legacyAssignee->id, $cares[$legacyCare->id]['user_assigning']['id']);
        $this->assertSame($legacyAssignee->name, $cares[$legacyCare->id]['user_assigning']['name']);
        $this->assertNull($cares[$emptyCare->id]['current_assignment']);
        $this->assertFalse($cares[$emptyCare->id]['current_assignment_ambiguous']);
        $this->assertNull($cares[$emptyCare->id]['user_care']);
        $this->assertNull($cares[$emptyCare->id]['user_assigning']);
    }

    public function test_multiple_current_assignments_are_reported_as_ambiguous_without_selecting_an_employee(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser('admin', 'Admin');
        $assigneeA = $this->createUser('staff-cskh', 'Assignee A');
        $assigneeB = $this->createUser('staff-cskh', 'Assignee B');
        $order = $this->createOrder($shop);
        $care = $this->createCustomerCare($order, $assigneeA, date('Y-m-d'));
        $this->createAssignment($order, $care, $assigneeA, CustomerCareAssignment::STATUS_ACTIVE);
        $this->createAssignment($order, $care, $assigneeB, CustomerCareAssignment::STATUS_ACTIVE);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_today&page=1')
            ->assertOk()
            ->assertJsonPath('data.customers.0.id', $care->id)
            ->assertJsonPath('data.customers.0.current_assignment', null)
            ->assertJsonPath('data.customers.0.current_assignment_ambiguous', true)
            ->assertJsonPath('data.customers.0.user_assigning', null);
    }

    public function test_mixed_cared_and_uncared_active_assignments_are_still_ambiguous(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser('admin', 'Admin');
        $assigneeA = $this->createUser('staff-cskh', 'Assignee A');
        $assigneeB = $this->createUser('staff-cskh', 'Assignee B');
        $order = $this->createOrder($shop);
        $care = $this->createCustomerCare($order, $assigneeA, date('Y-m-d'));
        $caredAssignment = $this->createAssignment(
            $order,
            $care,
            $assigneeA,
            CustomerCareAssignment::STATUS_ACTIVE
        );
        $caredAssignment->update(['cared_at' => date('Y-m-d H:i:s')]);
        $this->createAssignment($order, $care, $assigneeB, CustomerCareAssignment::STATUS_ACTIVE);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_today&page=1')
            ->assertOk()
            ->assertJsonPath('data.customers.0.id', $care->id)
            ->assertJsonPath('data.customers.0.current_assignment', null)
            ->assertJsonPath('data.customers.0.current_assignment_ambiguous', true)
            ->assertJsonPath('data.customers.0.user_assigning', null);
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
        )->assertForbidden()->assertExactJson([
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
        )->assertForbidden()
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
        ])->assertForbidden()
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
        $this->assertSame($actor->pancake_user_id, $assignment->customerCare->user_creator_id);
        $this->assertSame($assignee->id, $assignment->assignee_user_id);
        $this->assertSame('2026-08-29', $assignment->customerCare->date_care);
        $this->assertSame('2026-09-01', $assignment->reclaim_eligible_on->toDateString());
        $this->assertSame(0, (int) $outside->fresh()->status);
        $this->assertSame(1, (int) $inside->fresh()->status);
        $log = ActivityLog::query()->sole();
        $this->assertSame('customer_care.assigned', $log->action);
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame($assignee->id, $log->target_user_id);
    }

    public function test_imported_opportunity_reassignment_reuses_local_source_history_without_fake_customer_event(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        $shop = $this->createShop('Imported Shop', 0);
        $actor = $this->createUser('manager-cskh', 'Actor');
        $assignee = $this->createUser('staff-cskh', 'Assignee');
        $this->attachShop($actor, $shop);
        $this->attachShop($assignee, $shop);
        $this->grantPermission($actor, 'asign-cskh');
        $opportunity = $this->createImportedOpportunity($shop);

        $this->actingAs($actor, 'api')->postJson('/api/v1/imported-opportunities/assign', [
            'ids' => [$opportunity->id],
            'pancake_user_ids' => [$assignee->pancake_user_id],
        ])->assertOk()->assertJsonPath('success', true);

        $first = CustomerCareAssignment::query()->sole();
        Carbon::setTestNow('2026-08-27 12:00:00');
        $this->app->make(CustomerCareReclaimService::class)->executeOne(
            $first->id,
            CarbonImmutable::parse('2026-08-27 12:00:00', config('app.timezone'))
        );

        $this->assertSame(0, (int) $opportunity->fresh()->status);

        $this->actingAs($actor, 'api')->postJson('/api/v1/imported-opportunities/assign', [
            'ids' => [$opportunity->id],
            'pancake_user_ids' => [$assignee->pancake_user_id],
        ])->assertOk()->assertJsonPath('success', true);

        $second = CustomerCareAssignment::query()->latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(CustomerCareAssignment::STATUS_RECLAIMED, $first->fresh()->status);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $second->status);
        $this->assertSame(1, ActivityLog::query()->where('action', 'customer_care.assigned')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'customer_care.reassigned')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'customer_care.reclaimed')->count());
        $reassigned = ActivityLog::query()->where('action', 'customer_care.reassigned')->sole();
        $this->assertSame((string) $second->id, $reassigned->subject_id);
        $this->assertSame(CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY, $reassigned->metadata['source_type']);
        $this->assertSame($opportunity->id, $reassigned->metadata['source_id']);
        $this->assertSame($shop->id, $reassigned->metadata['shop_id']);
        $this->assertArrayNotHasKey('customer_id', $reassigned->metadata);
        $this->assertSame(0, ActivityLog::query()->where('action', 'customer.entered_system')->count());
    }

    public function test_import_authorizes_feature_and_shop_before_processing_the_file(): void
    {
        $ownShop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $zeroShopManager = $this->createUser('manager-cskh', 'Zero Shop Manager');
        $saleManager = $this->createUser('manager-sale', 'Sale Manager');
        $admin = $this->createUser('admin', 'Admin');
        $this->attachShop($manager, $ownShop);
        $this->attachShop($saleManager, $ownShop);
        $this->grantPermission($manager, 'view-chance');

        $this->actingAs($manager, 'api')->postJson('/api/v1/imported-opportunities/import', ['shop_id' => $otherShop->id])->assertForbidden();
        $this->actingAs($zeroShopManager, 'api')->postJson('/api/v1/imported-opportunities/import', ['shop_id' => $ownShop->id])->assertForbidden();
        $this->actingAs($saleManager, 'api')->postJson('/api/v1/imported-opportunities/import', ['shop_id' => $ownShop->id])->assertForbidden();

        $this->actingAs($manager, 'api')->postJson('/api/v1/imported-opportunities/import', ['shop_id' => $ownShop->id])->assertStatus(422);
        $this->actingAs($admin, 'api')->postJson('/api/v1/imported-opportunities/import', ['shop_id' => $otherShop->id])->assertStatus(422);
        $this->assertDatabaseCount('imported_opportunities', 0);
    }

    public function test_import_rejects_incomplete_rows_and_invalid_phone_numbers_without_partial_import(): void
    {
        $shop = $this->createShop('Own Shop');
        $actor = $this->createUser('manager-cskh', 'Manager');
        $this->attachShop($actor, $shop);
        $this->grantPermission($actor, 'view-chance');
        $fileContents = Excel::raw(new class implements FromArray, WithHeadings
        {
            public function array(): array
            {
                return [
                    ['Thiếu địa chỉ', '', '0901234567'],
                    ['Sai số điện thoại', 'Địa chỉ đầy đủ', '12345'],
                    ['Dòng hợp lệ', 'Địa chỉ đầy đủ', '0912345678'],
                ];
            }

            public function headings(): array
            {
                return ['Tên', 'Địa chỉ', 'Số điện thoại'];
            }
        }, ExcelFormat::XLSX);
        $file = UploadedFile::fake()->createWithContent('opportunities.xlsx', $fileContents);

        $response = $this->actingAs($actor, 'api')
            ->post('/api/v1/imported-opportunities/import', [
                'shop_id' => $shop->id,
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $responseContent = $response->getContent();
        $this->assertStringContainsString('Dòng 2', $responseContent);
        $this->assertStringContainsString('Địa chỉ là bắt buộc.', $responseContent);
        $this->assertStringContainsString('Dòng 3', $responseContent);
        $this->assertStringContainsString('Số điện thoại phải gồm đúng 10 chữ số.', $responseContent);

        $this->assertDatabaseCount('imported_opportunities', 0);
    }

    public function test_phase_3_all_user_scope_rejects_tampering_and_preserves_assignment_eligibility(): void
    {
        $ownShop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $manager = $this->createUser('manager-cskh', 'Manager CSKH');
        $ownStaff = $this->createUser('staff-cskh', 'Own CSKH Staff');
        $otherStaff = $this->createUser('staff-cskh', 'Other CSKH Staff');
        $ownSaleManager = $this->createUser('manager-sale', 'Own Sale Manager');
        $zeroShopManager = $this->createUser('manager-cskh', 'Zero Shop Manager');
        $admin = $this->createUser('admin', 'Admin');

        $this->attachShop($manager, $ownShop);
        $this->attachShop($ownStaff, $ownShop);
        $this->attachShop($otherStaff, $otherShop);
        $this->attachShop($ownSaleManager, $ownShop);

        $unfiltered = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/users/all-user')
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$manager->id, $ownStaff->id, $ownSaleManager->id],
            collect($unfiltered->json('data'))->pluck('id')->all()
        );

        $eligible = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/users/all-user?shop_id={$ownShop->id}&assignment_eligible=1")
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$manager->id, $ownStaff->id],
            collect($eligible->json('data'))->pluck('id')->all()
        );

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/users/all-user?shop_id={$otherShop->id}")
            ->assertForbidden();
        $this->actingAs($zeroShopManager, 'api')
            ->getJson('/api/v1/users/all-user')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($zeroShopManager, 'api')
            ->getJson("/api/v1/users/all-user?shop_id={$otherShop->id}")
            ->assertForbidden();

        $adminResponse = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/users/all-user?shop_id={$otherShop->id}")
            ->assertOk();
        $this->assertSame(
            [$otherStaff->id],
            collect($adminResponse->json('data'))->pluck('id')->all()
        );
    }

    public function test_phase_3_monitoring_returns_only_users_intersecting_the_effective_shop_scope(): void
    {
        $shopA = $this->createShop('Shop A');
        $shopB = $this->createShop('Shop B');
        $shopC = $this->createShop('Shop C');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $staffA = $this->createUser('staff-cskh', 'Staff A');
        $staffB = $this->createUser('staff-cskh', 'Staff B');
        $staffC = $this->createUser('staff-cskh', 'Staff C');
        $zeroShopManager = $this->createUser('manager-cskh', 'Zero Shop Manager');

        $this->attachShop($manager, $shopA);
        $this->attachShop($manager, $shopB);
        $this->attachShop($staffA, $shopA);
        $this->attachShop($staffB, $shopB);
        $this->attachShop($staffC, $shopC);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/users/mornitoring')
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$manager->pancake_user_id, $staffA->pancake_user_id, $staffB->pancake_user_id],
            collect($response->json('data'))->pluck('id')->all()
        );

        $shopAResponse = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/users/mornitoring?shop_id={$shopA->id}")
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$manager->pancake_user_id, $staffA->pancake_user_id],
            collect($shopAResponse->json('data'))->pluck('id')->all()
        );
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/users/mornitoring?shop_id={$shopC->id}")
            ->assertForbidden();
        $this->actingAs($zeroShopManager, 'api')
            ->getJson('/api/v1/users/mornitoring')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_phase_3_imported_opportunity_list_enforces_feature_and_shop_scope(): void
    {
        $ownShop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $manager = $this->createUser('manager-cskh', 'Manager CSKH');
        $zeroShopManager = $this->createUser('manager-cskh', 'Zero Shop Manager');
        $managerSale = $this->createUser('manager-sale', 'Manager Sale');
        $admin = $this->createUser('admin', 'Admin');
        $this->attachShop($manager, $ownShop);
        $this->attachShop($managerSale, $ownShop);
        foreach ([$manager, $zeroShopManager, $admin] as $user) {
            $this->grantPermission($user, 'view-chance');
        }
        $ownOpportunity = $this->createImportedOpportunity($ownShop);
        $otherOpportunity = $this->createImportedOpportunity($otherShop);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/imported-opportunities?page=1')
            ->assertOk();
        $this->assertSame(
            [$ownOpportunity->id],
            collect($response->json('data.orders'))->pluck('id')->all()
        );
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/imported-opportunities?shop_id={$otherShop->id}&page=1")
            ->assertForbidden();
        $this->actingAs($zeroShopManager, 'api')
            ->getJson('/api/v1/imported-opportunities?page=1')
            ->assertOk()
            ->assertJsonCount(0, 'data.orders');
        $this->actingAs($managerSale, 'api')
            ->getJson('/api/v1/imported-opportunities?page=1')
            ->assertForbidden();

        $adminResponse = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/imported-opportunities?shop_id={$otherShop->id}&page=1")
            ->assertOk();
        $this->assertSame(
            [$otherOpportunity->id],
            collect($adminResponse->json('data.orders'))->pluck('id')->all()
        );
    }

    public function test_phase_3_product_list_is_scoped_through_its_product_shop_relationship(): void
    {
        $ownShop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $zeroShopManager = $this->createUser('manager-cskh', 'Zero Shop Manager');
        $admin = $this->createUser('admin', 'Admin');
        $this->attachShop($manager, $ownShop);
        foreach ([$manager, $zeroShopManager, $admin] as $user) {
            $this->grantPermission($user, 'list-product');
        }
        $ownProduct = Product::create(['shop_id' => $ownShop->id, 'name' => 'Own Product']);
        $otherProduct = Product::create(['shop_id' => $otherShop->id, 'name' => 'Other Product']);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/products?page=1')
            ->assertOk();
        $this->assertSame(
            [$ownProduct->id],
            collect($response->json('data.products'))->pluck('id')->all()
        );
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/products?shop_id={$otherShop->id}&page=1")
            ->assertForbidden();
        $this->actingAs($zeroShopManager, 'api')
            ->getJson('/api/v1/products?page=1')
            ->assertOk()
            ->assertJsonCount(0, 'data.products');

        $adminResponse = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/products?shop_id={$otherShop->id}&page=1")
            ->assertOk();
        $this->assertSame(
            [$otherProduct->id],
            collect($adminResponse->json('data.products'))->pluck('id')->all()
        );
    }

    public function test_phase_3_customer_care_list_role_and_shop_scope_are_separate(): void
    {
        $ownShop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $managerCskh = $this->createUser('manager-cskh', 'Manager CSKH');
        $managerSale = $this->createUser('manager-sale', 'Manager Sale');
        $admin = $this->createUser('admin', 'Admin');
        $this->attachShop($managerCskh, $ownShop);
        $this->attachShop($managerSale, $ownShop);
        $ownCare = $this->createCustomerCare(
            $this->createOrder($ownShop, 2),
            $managerCskh,
            '2030-01-01'
        );
        $otherCare = $this->createCustomerCare(
            $this->createOrder($otherShop, 2),
            $managerCskh,
            '2030-01-01'
        );

        $this->actingAs($managerSale, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_pending&page=1')
            ->assertForbidden();

        $managerResponse = $this->actingAs($managerCskh, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_pending&page=1')
            ->assertOk();
        $this->assertSame(
            [$ownCare->id],
            collect($managerResponse->json('data.customers'))->pluck('id')->all()
        );
        $this->actingAs($managerCskh, 'api')
            ->getJson("/api/v1/customer-cares?type=customer_care_pending&shop_id={$otherShop->id}&page=1")
            ->assertForbidden();

        $adminResponse = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_pending&page=1')
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$ownCare->id, $otherCare->id],
            collect($adminResponse->json('data.customers'))->pluck('id')->all()
        );
    }

    public function test_phase_3_1_customer_care_list_allows_real_staff_role_without_view_chance(): void
    {
        $ownShop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $staff = $this->createUser('staff-cskh', 'Staff CSKH');
        $managerCskh = $this->createUser('manager-cskh', 'Manager CSKH');
        $managerSale = $this->createUser('manager-sale', 'Manager Sale');
        $staffSale = $this->createUser('staff-sale', 'Staff Sale');
        $zeroShopManager = $this->createUser('manager-cskh', 'Zero Shop Manager');
        $this->attachShop($staff, $ownShop);
        $this->attachShop($managerCskh, $ownShop);
        $this->attachShop($managerSale, $ownShop);
        $this->attachShop($staffSale, $ownShop);

        $today = date('Y-m-d');
        $order = $this->createOrder($ownShop);
        $care = $this->createCustomerCare($order, $staff, $today);
        $this->createAssignment(
            $order,
            $care,
            $staff,
            CustomerCareAssignment::STATUS_ACTIVE,
            $today
        );

        $staffResponse = $this->actingAs($staff, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_today&page=1')
            ->assertOk();
        $this->assertSame(
            [$care->id],
            collect($staffResponse->json('data.customers'))->pluck('id')->all()
        );

        $managerResponse = $this->actingAs($managerCskh, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_today&page=1')
            ->assertOk();
        $this->assertSame(
            [$care->id],
            collect($managerResponse->json('data.customers'))->pluck('id')->all()
        );
        $this->actingAs($managerCskh, 'api')
            ->getJson("/api/v1/customer-cares?type=customer_care_today&shop_id={$otherShop->id}&page=1")
            ->assertForbidden();

        $this->actingAs($managerSale, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_today&page=1')
            ->assertForbidden();
        $this->actingAs($staffSale, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_today&page=1')
            ->assertForbidden();
        $this->actingAs($zeroShopManager, 'api')
            ->getJson('/api/v1/customer-cares?type=customer_care_today&page=1')
            ->assertOk()
            ->assertJsonCount(0, 'data.customers');
    }

    public function test_phase_3_related_user_list_scopes_visible_users_and_order_totals_before_pagination(): void
    {
        $ownShop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $sharedStaff = $this->createUser('staff-cskh', 'Shared Staff');
        $outsideStaff = $this->createUser('staff-cskh', 'Outside Staff');
        $this->attachShop($manager, $ownShop);
        $this->attachShop($sharedStaff, $ownShop);
        $this->attachShop($sharedStaff, $otherShop);
        $this->attachShop($outsideStaff, $otherShop);
        $this->grantPermission($manager, 'list-staff');

        $ownOrder = $this->createOrder($ownShop);
        $ownOrder->update(['user_creator_id' => $sharedStaff->pancake_user_id, 'cod' => 100]);
        $otherOrder = $this->createOrder($otherShop);
        $otherOrder->update(['user_creator_id' => $sharedStaff->pancake_user_id, 'cod' => 200]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/users?page=1')
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$manager->id, $sharedStaff->id],
            collect($response->json('data.items'))->pluck('id')->all()
        );
        $this->assertEquals(100.0, (float) $response->json('data.total_cod'));

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/users?shop_id={$otherShop->id}&page=1")
            ->assertForbidden();
    }

    public function test_phase_3_related_staff_assignment_metrics_do_not_count_other_shop_work(): void
    {
        $ownShop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $sharedStaff = $this->createUser('staff-cskh', 'Shared Staff');
        $sharedStaff->update(['pancake_user_id' => (string) $sharedStaff->id]);
        $sharedStaff->refresh();
        $this->attachShop($manager, $ownShop);
        $this->attachShop($sharedStaff, $ownShop);
        $this->attachShop($sharedStaff, $otherShop);

        $today = now()->toDateString();
        $this->createCustomerCare($this->createOrder($ownShop, 2), $sharedStaff, $today);
        $this->createCustomerCare($this->createOrder($otherShop, 2), $sharedStaff, $today);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/customer-assigned-by-staff?page=1')
            ->assertOk();
        $staffMetrics = collect($response->json('data.customers'))->firstWhere('id', $sharedStaff->id);
        $this->assertNotNull($staffMetrics);
        $this->assertSame(1, $staffMetrics['today_total']);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/customer-assigned-by-staff?shop_id={$otherShop->id}&page=1")
            ->assertForbidden();
    }

    public function test_phase_3_current_safe_order_history_chance_and_shop_lists_do_not_regress(): void
    {
        $ownShop = $this->createShop('Own Shop');
        $otherShop = $this->createShop('Other Shop');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $this->attachShop($manager, $ownShop);
        $this->grantPermission($manager, 'view-chance');
        $ownOrder = $this->createOrder($ownShop);
        $otherOrder = $this->createOrder($otherShop);

        $ordersResponse = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?page=1')
            ->assertOk();
        $this->assertSame(
            [$ownOrder->id],
            collect($ordersResponse->json('data.orders'))->pluck('id')->all()
        );

        $chanceResponse = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders/chance?page=1')
            ->assertOk();
        $this->assertSame(
            [$ownOrder->id],
            collect($chanceResponse->json('data.orders'))->pluck('id')->all()
        );

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/orders/{$ownOrder->id}/history")
            ->assertOk();
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/orders/{$otherOrder->id}/history")
            ->assertForbidden();

        $shopsResponse = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/shops')
            ->assertOk();
        $this->assertSame(
            [$ownShop->id],
            collect($shopsResponse->json('data'))->pluck('id')->all()
        );
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
            $table->string('pancake_shop_id')->nullable();
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
            $table->string('pancake_order_source_id')->nullable()->index();
            $table->string('pancake_order_source_name')->nullable();
            $table->string('pancake_order_page_id')->nullable();
            $table->string('pancake_order_page_name')->nullable();
            $table->string('pancake_customer_id')->nullable();
            $table->string('user_creator_id')->nullable();
            $table->string('user_care_id')->nullable();
            $table->string('user_assigning_seller_id')->nullable();
            $table->string('order_number_vtp')->nullable();
            $table->integer('total_quantity')->nullable();
            $table->decimal('cod', 15, 2)->default(0);
            $table->decimal('cash', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_address')->nullable();
            $table->integer('status')->default(3);
            $table->string('status_vtp')->nullable();
            $table->json('pancake_full_data')->nullable();
            $table->boolean('received_at_shop')->default(false);
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
        Schema::create('customer_assigneds', function (Blueprint $table) {
            $table->id();
            $table->string('customer_care_id');
            $table->string('pancake_user_id');
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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('name');
            $table->string('pancake_product_id')->nullable();
            $table->json('pancake_full_data')->nullable();
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
            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('idempotency_key', 191)->nullable()->unique();
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

        $user = User::create([
            'role_id' => $role->id,
            'name' => $name,
            'email' => strtolower($name)."-{$nextId}@example.test",
            'password' => 'unused',
            'pancake_user_id' => "PANCAKE-{$nextId}",
        ]);

        return $user;
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
    ): CustomerCare {
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
