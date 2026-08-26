<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CustomerCareController;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\CustomerCareAssignmentService;
use App\Services\CustomerCareWriteAccessService;
use App\Services\ShopAccessService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CustomerCareMarkerIntegrationTest extends TestCase
{
    private CustomerCareController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'phase_c_testing',
            'database.connections.phase_c_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('phase_c_testing');
        DB::setDefaultConnection('phase_c_testing');

        $this->createSchema();
        $this->authenticateStaffUser();

        $this->controller = new CustomerCareController(
            new CustomerCareAssignmentService,
            new ActivityLogService,
            new ShopAccessService,
            new CustomerCareWriteAccessService(new ShopAccessService)
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_valid_care_sets_active_assignment_marker_from_server_time(): void
    {
        $order = Order::create([
            'shop_id' => 7,
            'pancake_order_id' => 'ORDER-CURRENT-1',
            'status' => 3,
        ]);
        $customerCare = $this->createCustomerCare([
            'pancake_order_id' => $order->pancake_order_id,
        ]);
        $assignment = $this->createAssignment($customerCare, CustomerCareAssignment::STATUS_ACTIVE, $order->id);
        Carbon::setTestNow('2026-08-21 15:10:00');

        $response = $this->updateCare($customerCare, 1, '2026-08-20 10:00:00');

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame(
            '2026-08-21 15:10:00',
            CustomerCare::findOrFail($customerCare->id)->time_care
        );
        $this->assertSame(
            '2026-08-21 15:10:00',
            $assignment->fresh()->cared_at->format('Y-m-d H:i:s')
        );
        $this->assertSame(3, (int) $order->fresh()->status);
    }

    public function test_completion_payload_is_server_controlled_and_consistent(): void
    {
        $notCared = $this->createCustomerCare();
        $notCaredAssignment = $this->createAssignment($notCared);
        $this->updateCare($notCared, 0, null);

        Carbon::setTestNow('2026-08-21 15:10:00');
        $statusOnly = $this->createCustomerCare();
        $statusOnlyAssignment = $this->createAssignment($statusOnly);
        $statusOnlyResponse = $this->updateCare($statusOnly, 1, null);

        $timeOnly = $this->createCustomerCare();
        $timeOnlyAssignment = $this->createAssignment($timeOnly);
        $this->updateCare($timeOnly, 0, '2026-08-21 12:00:00');

        $this->assertNull($notCaredAssignment->fresh()->cared_at);
        $this->assertTrue($statusOnlyResponse->getData(true)['success']);
        $this->assertSame(1, (int) $statusOnly->fresh()->status);
        $this->assertSame('2026-08-21 15:10:00', $statusOnly->fresh()->time_care);
        $this->assertSame(
            '2026-08-21 15:10:00',
            $statusOnlyAssignment->fresh()->cared_at->format('Y-m-d H:i:s')
        );
        $this->assertNull($timeOnlyAssignment->fresh()->cared_at);
    }

    public function test_confirm_does_not_set_cared_at(): void
    {
        $creator = $this->createUser(32, 'staff-cskh', 'Original Creator', [7]);
        $customerCare = $this->createCustomerCare([
            'user_creator_id' => $creator->pancake_user_id,
        ]);
        $assignment = $this->createAssignment($customerCare);

        $response = $this->controller->confirmCare($customerCare->id);

        $this->assertTrue($response->getData(true)['success']);
        $this->assertTrue((bool) $customerCare->fresh()->is_confirm_care);
        $this->assertSame($creator->pancake_user_id, $customerCare->fresh()->user_creator_id);
        $this->assertNull($assignment->fresh()->cared_at);
    }

    public function test_same_shop_staff_cannot_update_another_assignees_customer_care(): void
    {
        $otherStaff = $this->createUser(32, 'staff-cskh', 'Other Staff', [7]);
        $customerCare = $this->createCustomerCare(['note' => 'original']);
        $this->createAssignment($customerCare, assigneeUserId: $otherStaff->id);

        $response = $this->updateCare($customerCare, 0, null);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('original', $customerCare->fresh()->note);
        $this->assertSame(0, (int) $customerCare->fresh()->status);
    }

    public function test_cross_shop_manager_cskh_cannot_confirm_or_update_customer_care(): void
    {
        $manager = $this->createUser(32, 'manager-cskh', 'Other Shop Manager', [8]);
        $customerCare = $this->createCustomerCare(['note' => 'original']);
        $this->createAssignment($customerCare);
        $this->authenticateAs($manager);

        $confirm = $this->controller->confirmCare($customerCare->id);
        $update = $this->updateCare($customerCare, 0, null);

        $this->assertSame(403, $confirm->getStatusCode());
        $this->assertSame(403, $update->getStatusCode());
        $this->assertFalse((bool) $customerCare->fresh()->is_confirm_care);
        $this->assertSame('original', $customerCare->fresh()->note);
    }

    public function test_manager_sale_cannot_mutate_customer_care_even_in_same_shop(): void
    {
        $saleManager = $this->createUser(32, 'manager-sale', 'Sale Manager', [7]);
        $customerCare = $this->createCustomerCare();
        $this->createAssignment($customerCare);
        $this->authenticateAs($saleManager);
        $request = Request::create("/api/v1/customer-cares/{$customerCare->id}/accept", 'POST', ['is_accept' => 1]);

        $accept = $this->controller->accept($customerCare->id, $request);
        $confirm = $this->controller->confirmCare($customerCare->id);
        $update = $this->updateCare($customerCare, 0, null);

        $this->assertSame(403, $accept->getStatusCode());
        $this->assertSame(403, $confirm->getStatusCode());
        $this->assertSame(403, $update->getStatusCode());
        $this->assertSame(0, (int) $customerCare->fresh()->status);
        $this->assertFalse((bool) $customerCare->fresh()->is_confirm_care);
    }

    public function test_delete_requires_manager_cskh_or_admin_and_checks_the_shop(): void
    {
        $saleManager = $this->createUser(32, 'manager-sale', 'Sale Manager', [7]);
        $customerCare = $this->createCustomerCare();
        $this->authenticateAs($saleManager);
        $request = Request::create("/api/v1/customer-cares/{$customerCare->id}", 'DELETE');

        try {
            $this->controller->destroy($request, $customerCare);
            $this->fail('A sale manager must not delete CustomerCare.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertDatabaseHas('customer_cares', ['id' => $customerCare->id]);
        }

        $manager = $this->createUser(33, 'manager-cskh', 'Care Manager', [7]);
        $this->authenticateAs($manager);
        $response = $this->controller->destroy($request, $customerCare->fresh());

        $this->assertTrue($response->getData(true)['success']);
        $this->assertDatabaseMissing('customer_cares', ['id' => $customerCare->id]);
    }

    public function test_accept_does_not_set_cared_at(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);
        $manager = $this->createUser(32, 'manager-cskh', 'Care Manager', [7]);
        $this->authenticateAs($manager);
        $request = Request::create(
            "/api/v1/customer-cares/{$customerCare->id}/accept",
            'POST',
            ['is_accept' => 1, 'reason' => 'approved']
        );

        $response = $this->controller->accept($customerCare->id, $request);

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame('approved', $customerCare->fresh()->reason);
        $this->assertNull($assignment->fresh()->cared_at);
    }

    public function test_second_completion_is_rejected_without_mutation(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);
        Carbon::setTestNow('2026-08-21 15:10:00');
        $this->updateCare($customerCare, 1, '2026-08-20 10:00:00');

        Carbon::setTestNow('2026-08-22 09:00:00');
        $secondResponse = $this->updateCare($customerCare->fresh(), 1, '2026-08-22 08:00:00');

        $this->assertSame(409, $secondResponse->getStatusCode());
        $this->assertFalse($secondResponse->getData(true)['success']);
        $this->assertSame(1, (int) $customerCare->fresh()->status);
        $this->assertSame('2026-08-21 15:10:00', $customerCare->fresh()->time_care);
        $this->assertSame(
            '2026-08-21 15:10:00',
            $assignment->fresh()->cared_at->format('Y-m-d H:i:s')
        );
    }

    public function test_later_non_care_edit_does_not_clear_first_marker(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);
        Carbon::setTestNow('2026-08-21 15:10:00');
        $this->updateCare($customerCare, 1, '2026-08-21 14:00:00');

        Carbon::setTestNow('2026-08-22 09:00:00');
        $this->updateCare($customerCare->fresh(), 0, null);

        $this->assertSame(0, (int) $customerCare->fresh()->status);
        $this->assertSame(
            '2026-08-21 15:10:00',
            $customerCare->fresh()->time_care
        );
        $this->assertSame(
            '2026-08-21 15:10:00',
            $assignment->fresh()->cared_at->format('Y-m-d H:i:s')
        );
    }

    public function test_legacy_care_without_assignment_is_rejected_without_mutation(): void
    {
        $customerCare = $this->createCustomerCare();

        $response = $this->updateCare($customerCare, 1, '2026-08-21 14:00:00');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(0, (int) $customerCare->fresh()->status);
        $this->assertNull($customerCare->fresh()->time_care);
        $this->assertSame(0, CustomerCareAssignment::query()->count());
    }

    public function test_legacy_order_care_is_rejected_when_another_care_owns_active_assignment(): void
    {
        $order = Order::create([
            'shop_id' => 7,
            'pancake_order_id' => 'ORDER-GUARD-1',
            'status' => 3,
        ]);
        $legacyCare = CustomerCare::create([
            'shop_id' => 7,
            'pancake_customer_id' => 'CUSTOMER-GUARD-1',
            'pancake_order_id' => $order->pancake_order_id,
            'date_care' => '2026-08-21',
            'status' => 0,
            'time_care' => null,
            'note' => 'Legacy history',
            'total_edit' => 0,
            'is_accept' => 1,
        ]);
        $activeCare = CustomerCare::create([
            'shop_id' => 7,
            'pancake_customer_id' => 'CUSTOMER-GUARD-1',
            'pancake_order_id' => $order->pancake_order_id,
            'date_care' => '2026-08-24',
            'status' => 0,
            'time_care' => null,
            'total_edit' => 0,
            'is_accept' => 1,
        ]);
        $assignment = $this->createAssignment(
            $activeCare,
            CustomerCareAssignment::STATUS_ACTIVE,
            $order->id
        );

        $response = $this->updateCare($legacyCare, 1, '2026-08-21 14:00:00');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(0, (int) $legacyCare->fresh()->status);
        $this->assertNull($legacyCare->fresh()->time_care);
        $this->assertSame('Legacy history', $legacyCare->fresh()->note);
        $this->assertNull($assignment->fresh()->cared_at);

        Carbon::setTestNow('2026-08-21 15:10:00');
        $activeResponse = $this->updateCare($activeCare, 1, '2026-08-21 14:30:00');
        $this->assertTrue($activeResponse->getData(true)['success']);
        $this->assertSame(
            '2026-08-21 15:10:00',
            $assignment->fresh()->cared_at->format('Y-m-d H:i:s')
        );
    }

    public function test_duplicate_local_orders_fail_safe_for_legacy_care_and_allow_active_care(): void
    {
        $orderA = Order::create([
            'shop_id' => 7,
            'pancake_order_id' => 'ORDER-DUPLICATE-1',
            'status' => 3,
        ]);
        $orderB = Order::create([
            'shop_id' => 7,
            'pancake_order_id' => $orderA->pancake_order_id,
            'status' => 3,
        ]);
        $legacyCare = CustomerCare::create([
            'shop_id' => 7,
            'pancake_customer_id' => 'CUSTOMER-DUPLICATE-1',
            'pancake_order_id' => $orderA->pancake_order_id,
            'date_care' => '2026-08-20',
            'status' => 0,
            'note' => 'Duplicate legacy history',
            'total_edit' => 0,
            'is_accept' => 1,
        ]);
        $activeCare = CustomerCare::create([
            'shop_id' => 7,
            'pancake_customer_id' => 'CUSTOMER-DUPLICATE-1',
            'pancake_order_id' => $orderB->pancake_order_id,
            'date_care' => '2026-08-24',
            'status' => 0,
            'total_edit' => 0,
            'is_accept' => 1,
        ]);
        $assignment = $this->createAssignment(
            $activeCare,
            CustomerCareAssignment::STATUS_ACTIVE,
            $orderB->id
        );

        $legacyResponse = $this->updateCare($legacyCare, 1, '2026-08-20 14:00:00');

        $this->assertSame(409, $legacyResponse->getStatusCode());
        $this->assertSame(0, (int) $legacyCare->fresh()->status);
        $this->assertNull($legacyCare->fresh()->time_care);
        $this->assertSame('Duplicate legacy history', $legacyCare->fresh()->note);
        $this->assertNull($assignment->fresh()->cared_at);

        Carbon::setTestNow('2026-08-21 16:00:00');
        $activeResponse = $this->updateCare($activeCare, 1, '2026-08-21 15:30:00');

        $this->assertTrue($activeResponse->getData(true)['success']);
        $this->assertSame(1, (int) $activeCare->fresh()->status);
        $this->assertSame(
            '2026-08-21 16:00:00',
            $assignment->fresh()->cared_at->format('Y-m-d H:i:s')
        );
    }

    public function test_existing_multi_active_duplicates_allow_each_exact_care_and_reject_legacy_care(): void
    {
        $orderA = Order::create([
            'shop_id' => 7,
            'pancake_order_id' => 'ORDER-MULTI-ACTIVE-1',
            'status' => 3,
        ]);
        $orderB = Order::create([
            'shop_id' => 7,
            'pancake_order_id' => $orderA->pancake_order_id,
            'status' => 3,
        ]);
        $legacyCare = CustomerCare::create([
            'shop_id' => 7,
            'pancake_customer_id' => 'CUSTOMER-MULTI-ACTIVE-1',
            'pancake_order_id' => $orderA->pancake_order_id,
            'date_care' => '2026-08-20',
            'status' => 0,
            'note' => 'Legacy history',
            'total_edit' => 0,
            'is_accept' => 1,
        ]);
        $activeCareA = CustomerCare::create([
            'shop_id' => 7,
            'pancake_customer_id' => 'CUSTOMER-MULTI-ACTIVE-1',
            'pancake_order_id' => $orderA->pancake_order_id,
            'date_care' => '2026-08-21',
            'status' => 0,
            'total_edit' => 0,
            'is_accept' => 1,
        ]);
        $activeCareB = CustomerCare::create([
            'shop_id' => 7,
            'pancake_customer_id' => 'CUSTOMER-MULTI-ACTIVE-1',
            'pancake_order_id' => $orderB->pancake_order_id,
            'date_care' => '2026-08-22',
            'status' => 0,
            'total_edit' => 0,
            'is_accept' => 1,
        ]);
        $assignmentA = $this->createAssignment(
            $activeCareA,
            CustomerCareAssignment::STATUS_ACTIVE,
            $orderA->id
        );
        $assignmentB = $this->createAssignment(
            $activeCareB,
            CustomerCareAssignment::STATUS_ACTIVE,
            $orderB->id
        );

        Carbon::setTestNow('2026-08-21 16:00:00');
        $this->assertTrue(
            $this->updateCare($activeCareA, 1, '2026-08-21 15:30:00')->getData(true)['success']
        );
        $this->assertSame('2026-08-21 16:00:00', $assignmentA->fresh()->cared_at->format('Y-m-d H:i:s'));
        $this->assertNull($assignmentB->fresh()->cared_at);

        Carbon::setTestNow('2026-08-21 16:05:00');
        $this->assertTrue(
            $this->updateCare($activeCareB, 1, '2026-08-21 15:35:00')->getData(true)['success']
        );
        $this->assertSame('2026-08-21 16:05:00', $assignmentB->fresh()->cared_at->format('Y-m-d H:i:s'));

        $legacyResponse = $this->updateCare($legacyCare, 1, '2026-08-21 15:40:00');
        $this->assertSame(409, $legacyResponse->getStatusCode());
        $this->assertSame(0, (int) $legacyCare->fresh()->status);
        $this->assertNull($legacyCare->fresh()->time_care);
    }

    public function test_customer_care_update_failure_does_not_mark_assignment(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);
        CustomerCare::updating(function () {
            throw new RuntimeException('Simulated customer care write failure.');
        });

        try {
            $response = $this->updateCare($customerCare, 1, '2026-08-21 14:00:00');
        } finally {
            CustomerCare::flushEventListeners();
        }

        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(0, (int) $customerCare->fresh()->status);
        $this->assertNull($assignment->fresh()->cared_at);
    }

    public function test_assignment_write_failure_rolls_back_customer_care_update(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);
        CustomerCareAssignment::updating(function () {
            throw new RuntimeException('Simulated assignment marker write failure.');
        });

        try {
            $response = $this->updateCare($customerCare, 1, '2026-08-21 14:00:00');
        } finally {
            CustomerCareAssignment::flushEventListeners();
        }

        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(0, (int) $customerCare->fresh()->status);
        $this->assertNull($customerCare->fresh()->time_care);
        $this->assertNull($assignment->fresh()->cared_at);
    }

    public function test_reclaimed_assignment_cannot_be_completed(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment(
            $customerCare,
            CustomerCareAssignment::STATUS_RECLAIMED
        );

        $response = $this->updateCare($customerCare, 1, '2026-08-21 14:00:00');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(0, (int) $customerCare->fresh()->status);
        $this->assertNull($customerCare->fresh()->time_care);
        $this->assertNull($assignment->fresh()->cared_at);
    }

    public function test_imported_opportunity_completion_does_not_require_order_id(): void
    {
        Carbon::setTestNow('2026-08-21 15:10:00');
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment(
            $customerCare,
            CustomerCareAssignment::STATUS_ACTIVE,
            99001,
            CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY
        );

        $response = $this->updateCare($customerCare, 1, null);

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame(1, (int) $customerCare->fresh()->status);
        $this->assertSame('2026-08-21 15:10:00', $customerCare->fresh()->time_care);
        $this->assertSame('2026-08-21 15:10:00', $assignment->fresh()->cared_at->format('Y-m-d H:i:s'));
    }

    public function test_wrong_staff_member_in_same_shop_cannot_complete_exact_assignment(): void
    {
        $wrongStaff = $this->createUser(32, 'staff-cskh', 'Other Care Staff', [7]);
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare, CustomerCareAssignment::STATUS_ACTIVE, null, CustomerCareAssignment::SOURCE_ORDER, 31);
        $this->authenticateAs($wrongStaff);

        $response = $this->updateCare($customerCare, 1, null);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(0, (int) $customerCare->fresh()->status);
        $this->assertNull($customerCare->fresh()->time_care);
        $this->assertNull($assignment->fresh()->cared_at);
    }

    public function test_staff_member_without_customer_care_shop_access_cannot_complete(): void
    {
        $this->createShop(8);
        $crossShopStaff = $this->createUser(32, 'staff-cskh', 'Cross Shop Staff', [8]);
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare, CustomerCareAssignment::STATUS_ACTIVE, null, CustomerCareAssignment::SOURCE_ORDER, 32);
        $this->authenticateAs($crossShopStaff);

        $response = $this->updateCare($customerCare, 1, null);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, (int) $customerCare->fresh()->status);
        $this->assertNull($customerCare->fresh()->time_care);
        $this->assertNull($assignment->fresh()->cared_at);
    }

    public function test_manager_cskh_can_complete_in_same_shop(): void
    {
        $manager = $this->createUser(40, 'manager-cskh', 'Shop Manager', [7]);
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);
        $this->authenticateAs($manager);
        Carbon::setTestNow('2026-08-21 15:10:00');

        $response = $this->updateCare($customerCare, 1, '2000-01-01 00:00:00');

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame('2026-08-21 15:10:00', $customerCare->fresh()->time_care);
        $this->assertSame('2026-08-21 15:10:00', $assignment->fresh()->cared_at->format('Y-m-d H:i:s'));
    }

    public function test_manager_cskh_from_another_shop_cannot_complete(): void
    {
        $this->createShop(8);
        $manager = $this->createUser(40, 'manager-cskh', 'Other Shop Manager', [8]);
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);
        $this->authenticateAs($manager);

        $response = $this->updateCare($customerCare, 1, null);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, (int) $customerCare->fresh()->status);
        $this->assertNull($customerCare->fresh()->time_care);
        $this->assertNull($assignment->fresh()->cared_at);
    }

    public function test_current_task_visibility_uses_exact_assignment_for_staff_and_shop_scope_for_manager(): void
    {
        $staffB = $this->createUser(32, 'staff-cskh', 'Staff B', [7]);
        $manager = $this->createUser(40, 'manager-cskh', 'Shop Manager', [7]);
        $this->createShop(8);
        $otherManager = $this->createUser(41, 'manager-cskh', 'Other Shop Manager', [8]);
        $today = date('Y-m-d');

        $careA = $this->createCustomerCare(['date_care' => $today]);
        $careB = $this->createCustomerCare(['date_care' => $today]);
        $this->createAssignment($careA, CustomerCareAssignment::STATUS_ACTIVE, 91001, CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY, 31);
        $this->createAssignment($careB, CustomerCareAssignment::STATUS_ACTIVE, 91002, CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY, $staffB->id);

        $this->authenticateAs(User::findOrFail(31));
        $staffAIds = $this->indexCustomerCareIds('customer_care_today');
        $this->assertContains($careA->id, $staffAIds);
        $this->assertNotContains($careB->id, $staffAIds);

        $this->authenticateAs($staffB);
        $staffBIds = $this->indexCustomerCareIds('customer_care_today');
        $this->assertContains($careB->id, $staffBIds);
        $this->assertNotContains($careA->id, $staffBIds);

        $this->authenticateAs($manager);
        $managerIds = $this->indexCustomerCareIds('customer_care_today');
        $this->assertContains($careA->id, $managerIds);
        $this->assertContains($careB->id, $managerIds);

        $this->authenticateAs($otherManager);
        $this->assertNotContains($careA->id, $this->indexCustomerCareIds('customer_care_today'));
        $this->assertNotContains($careB->id, $this->indexCustomerCareIds('customer_care_today'));
    }

    private function updateCare(
        CustomerCare $customerCare,
        int $status,
        ?string $timeCare
    ) {
        $request = Request::create(
            "/api/v1/customer-cares/{$customerCare->id}",
            'PUT',
            [
                'status' => $status,
                'date' => $timeCare,
                'note' => 'Care note',
            ]
        );

        return $this->controller->update($request, $customerCare);
    }

    private function indexCustomerCareIds(string $type): array
    {
        $request = Request::create(
            '/api/v1/customer-cares',
            'GET',
            ['type' => $type, 'page' => 1]
        );
        $request->setUserResolver(fn () => Auth::user());
        $response = $this->controller->index($request);

        $payload = $response->getData(true);
        $this->assertTrue($payload['success'], $payload['message'] ?? 'CustomerCare index failed.');

        return array_map(
            static fn (array $customer) => (int) $customer['id'],
            $payload['data']['customers']
        );
    }

    private function createCustomerCare(array $overrides = []): CustomerCare
    {
        return CustomerCare::create(array_merge([
            'shop_id' => 7,
            'pancake_customer_id' => 'CUSTOMER-'.uniqid(),
            'date_care' => '2026-08-21',
            'status' => 0,
            'time_care' => null,
            'total_edit' => 0,
            'is_accept' => 1,
            'is_confirm_care' => false,
        ], $overrides));
    }

    private function createUser(int $id, string $roleSlug, string $name, array $shopIds): User
    {
        $role = DB::table('roles')->where('slug', $roleSlug)->first();
        if ($role === null) {
            $roleId = (int) DB::table('roles')->max('id') + 1;
            DB::table('roles')->insert([
                'id' => $roleId,
                'name' => $name,
                'slug' => $roleSlug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $roleId = $role->id;
        }

        DB::table('users')->insert([
            'id' => $id,
            'role_id' => $roleId,
            'name' => $name,
            'email' => "user{$id}@example.test",
            'password' => 'unused',
            'pancake_user_id' => "PANCAKE-{$id}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($shopIds as $shopId) {
            DB::table('shop_users')->insert([
                'shop_id' => $shopId,
                'user_id' => $id,
                'is_manager' => $roleSlug === 'manager-cskh',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return User::findOrFail($id);
    }

    private function authenticateAs(User $user): void
    {
        Auth::guard('web')->setUser($user);
    }

    private function createShop(int $id): void
    {
        DB::table('shops')->insert([
            'id' => $id,
            'name' => "Shop {$id}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAssignment(
        CustomerCare $customerCare,
        string $status = CustomerCareAssignment::STATUS_ACTIVE,
        ?int $sourceId = null,
        string $sourceType = CustomerCareAssignment::SOURCE_ORDER,
        ?int $assigneeUserId = null
    ): CustomerCareAssignment {
        $assignee = $assigneeUserId === null ? User::findOrFail(Auth::id()) : User::findOrFail($assigneeUserId);
        return CustomerCareAssignment::create([
            'shop_id' => $customerCare->shop_id,
            'customer_care_id' => $customerCare->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId ?? $customerCare->id + 1000,
            'assignee_user_id' => $assignee->id,
            'assignee_pancake_user_id' => $assignee->pancake_user_id,
            'assigned_at' => '2026-08-21 08:00:00',
            'reclaim_eligible_on' => '2026-08-24',
            'status' => $status,
            'cared_at' => null,
        ]);
    }

    private function authenticateStaffUser(): void
    {
        DB::table('roles')->insert([
            'id' => 3,
            'name' => 'Staff CSKH',
            'slug' => 'staff-cskh',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'id' => 31,
            'role_id' => 3,
            'name' => 'Care Staff',
            'email' => 'care@example.test',
            'password' => 'unused',
            'pancake_user_id' => 'PANCAKE-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shops')->insert([
            'id' => 7,
            'name' => 'Shop 7',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('shop_users')->insert([
            'shop_id' => 7,
            'user_id' => 31,
            'is_manager' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Auth::shouldUse('web');
        Auth::guard('web')->setUser(User::findOrFail(31));
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
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
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_manager')->default(false);
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

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_order_id');
            $table->integer('status')->default(3);
            $table->softDeletes();
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
            $table->unsignedBigInteger('customer_care_id');
            $table->string('pancake_user_id');
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
    }
}
