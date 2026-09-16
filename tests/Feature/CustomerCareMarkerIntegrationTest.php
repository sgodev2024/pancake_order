<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CustomerCareController;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\CustomerCareAssignmentService;
use App\Services\CustomerCareWriteAccessService;
use App\Services\ShopAccessService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame(1, ActivityLog::query()->where('action', 'customer_care.completed')->count());
        $this->assertSame(
            1,
            ActivityLog::query()
                ->where('action', 'customer_care.completed')
                ->sole()
                ->metadata['care_sequence_number']
        );
        $this->assertSame(1, DB::table('customer_care_journey_sequences')->value('last_sequence'));
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
        } catch (AuthorizationException) {
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
        $customerCare = $this->createCustomerCare(['is_accept' => 0]);
        $assignment = $this->createAssignment($customerCare);
        $manager = $this->createUser(32, 'manager-cskh', 'Care Manager', [7]);
        $this->grantPermission($manager, 'accept-schedule');
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

    public function test_completion_persists_note_and_creates_optional_next_care_date(): void
    {
        $customerCare = $this->createCustomerCare([
            'pancake_customer_id' => 'CUSTOMER-NEXT-CARE',
            'pancake_order_id' => 'ORDER-NEXT-CARE',
        ]);
        $assignment = $this->createAssignment($customerCare);
        Carbon::setTestNow('2026-08-21 15:10:00');
        $request = Request::create(
            "/api/v1/customer-cares/{$customerCare->id}",
            'PUT',
            [
                'status' => 1,
                'date' => '2026-08-20 10:00:00',
                'note' => 'Đã gọi và xác nhận nhu cầu',
                'next_date_care' => '2026-08-28',
            ]
        );

        $response = $this->controller->update($request, $customerCare);

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame('Đã gọi và xác nhận nhu cầu', $customerCare->fresh()->note);
        $this->assertSame('2026-08-21 15:10:00', $customerCare->fresh()->time_care);
        $this->assertSame('2026-08-21 15:10:00', $assignment->fresh()->cared_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('customer_cares', [
            'shop_id' => 7,
            'pancake_customer_id' => 'CUSTOMER-NEXT-CARE',
            'pancake_order_id' => 'ORDER-NEXT-CARE',
            'date_care' => '2026-08-28',
            'status' => 0,
        ]);
        $nextCare = CustomerCare::query()
            ->whereKeyNot($customerCare->id)
            ->where('pancake_customer_id', 'CUSTOMER-NEXT-CARE')
            ->sole();
        $nextAssignment = CustomerCareAssignment::query()
            ->where('customer_care_id', $nextCare->id)
            ->sole();
        $this->assertNotSame($assignment->id, $nextAssignment->id);
        $this->assertSame($assignment->assignee_user_id, $nextAssignment->assignee_user_id);
        $this->assertSame($assignment->shop_id, $nextAssignment->shop_id);
        $this->assertSame($assignment->source_type, $nextAssignment->source_type);
        $this->assertSame($assignment->source_id, $nextAssignment->source_id);
        $this->assertSame($assignment->assignee_pancake_user_id, $nextAssignment->assignee_pancake_user_id);
        $this->assertSame('2026-08-21 15:10:00', $nextAssignment->assigned_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-31', $nextAssignment->reclaim_eligible_on->toDateString());
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $nextAssignment->status);
        $this->assertNull($nextAssignment->cared_at);
        $this->assertSame(
            1,
            ActivityLog::query()->where('action', 'customer_care.reassigned')->count()
        );
    }

    public function test_completion_without_next_date_does_not_create_follow_up(): void
    {
        $customerCare = $this->createCustomerCare();
        $this->createAssignment($customerCare);

        $response = $this->updateCare($customerCare, 1, null);

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame(1, CustomerCare::query()->count());
        $this->assertSame(1, CustomerCareAssignment::query()->count());
    }

    public function test_follow_up_care_is_actionable_visible_and_completable_only_by_assignee(): void
    {
        $nextDate = date('Y-m-d', strtotime('+1 day'));
        $order = $this->createOrder('ORDER-FOLLOW-UP', 7, 'CUSTOMER-FOLLOW-UP');
        $firstCare = $this->createCustomerCare([
            'pancake_customer_id' => $order->pancake_customer_id,
            'pancake_order_id' => $order->pancake_order_id,
        ]);
        $firstAssignment = $this->createAssignment(
            $firstCare,
            CustomerCareAssignment::STATUS_ACTIVE,
            $order->id
        );

        $response = $this->updateCare(
            $firstCare,
            1,
            null,
            ['next_date_care' => $nextDate]
        );

        $this->assertTrue($response->getData(true)['success']);
        $followUp = CustomerCare::query()->whereKeyNot($firstCare->id)->sole();
        $followUpAssignment = CustomerCareAssignment::query()
            ->where('customer_care_id', $followUp->id)
            ->sole();
        $this->assertSame($firstAssignment->assignee_user_id, $followUpAssignment->assignee_user_id);
        $this->assertNull($followUpAssignment->cared_at);
        $this->assertTrue(CustomerCare::query()->actionable()->whereKey($followUp->id)->exists());

        $rows = $this->indexCustomerCareRows('customer_care_pending');
        $row = collect($rows)->firstWhere('id', $followUp->id);
        $this->assertNotNull($row);
        $this->assertSame($followUpAssignment->id, $row['current_assignment']['id']);
        $this->assertFalse($row['current_assignment_ambiguous']);

        $otherStaff = $this->createUser(32, 'staff-cskh', 'Other Staff', [7]);
        $this->authenticateAs($otherStaff);
        $denied = $this->updateCare($followUp->fresh(), 1, null);
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame(0, (int) $followUp->fresh()->status);
        $this->assertNull($followUpAssignment->fresh()->cared_at);

        $this->authenticateAs(User::findOrFail($firstAssignment->assignee_user_id));
        $completed = $this->updateCare($followUp->fresh(), 1, null);
        $this->assertTrue($completed->getData(true)['success']);
        $this->assertSame(1, (int) $followUp->fresh()->status);
        $this->assertNotNull($followUpAssignment->fresh()->cared_at);
    }

    #[DataProvider('followUpCareListCases')]
    public function test_follow_up_care_appears_in_the_matching_date_list(
        string $nextDate,
        string $expectedType
    ): void {
        $order = $this->createOrder(
            'ORDER-FOLLOW-UP-'.$expectedType,
            7,
            'CUSTOMER-FOLLOW-UP-'.$expectedType
        );
        $firstCare = $this->createCustomerCare([
            'pancake_customer_id' => $order->pancake_customer_id,
            'pancake_order_id' => $order->pancake_order_id,
        ]);
        $this->createAssignment(
            $firstCare,
            CustomerCareAssignment::STATUS_ACTIVE,
            $order->id
        );

        $response = $this->updateCare(
            $firstCare,
            1,
            null,
            ['next_date_care' => $nextDate]
        );

        $this->assertTrue($response->getData(true)['success']);
        $followUp = CustomerCare::query()->where('id', '!=', $firstCare->id)->sole();
        $this->assertContains($followUp->id, $this->indexCustomerCareIds($expectedType));
    }

    public static function followUpCareListCases(): array
    {
        return [
            'today' => [date('Y-m-d'), 'customer_care_today'],
            'upcoming' => [date('Y-m-d', strtotime('+1 day')), 'customer_care_pending'],
            'overdue' => [date('Y-m-d', strtotime('-1 day')), 'customer_care_expire'],
        ];
    }

    public function test_manager_can_reject_edit_request_with_reason_in_shop_scope(): void
    {
        $customerCare = $this->createCustomerCare(['is_accept' => 1, 'total_edit' => 2]);
        $manager = $this->createUser(32, 'manager-cskh', 'Care Manager', [7]);
        $this->grantPermission($manager, 'reject-cskh');
        $this->authenticateAs($manager);
        $request = Request::create(
            "/api/v1/customer-cares/{$customerCare->id}/accept",
            'POST',
            ['is_accept' => 0, 'reason' => 'Thông tin chỉnh sửa chưa hợp lệ']
        );

        $response = $this->controller->accept($customerCare->id, $request);

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame('Từ chối thành công', $response->getData(true)['message']);
        $this->assertSame(0, (int) $customerCare->fresh()->is_accept);
        $this->assertSame('Thông tin chỉnh sửa chưa hợp lệ', $customerCare->fresh()->reason);
        $this->assertSame($manager->id, (int) $customerCare->fresh()->user_accept_id);
    }

    public function test_reject_edit_request_enforces_manager_shop_scope_and_denies_staff(): void
    {
        $customerCare = $this->createCustomerCare(['is_accept' => 1, 'total_edit' => 2]);
        $request = Request::create(
            "/api/v1/customer-cares/{$customerCare->id}/accept",
            'POST',
            ['is_accept' => 0, 'reason' => 'Không duyệt']
        );

        $staffResponse = $this->controller->accept($customerCare->id, $request);
        $this->assertSame(403, $staffResponse->getStatusCode());

        $this->createShop(8);
        $otherShopManager = $this->createUser(32, 'manager-cskh', 'Other Shop Manager', [8]);
        $this->grantPermission($otherShopManager, 'reject-cskh');
        $this->authenticateAs($otherShopManager);
        $crossShopResponse = $this->controller->accept($customerCare->id, $request);

        $this->assertSame(403, $crossShopResponse->getStatusCode());
        $this->assertSame(1, (int) $customerCare->fresh()->is_accept);
        $this->assertNull($customerCare->fresh()->reason);
    }

    public function test_approve_and_reject_require_their_backend_permissions(): void
    {
        $manager = $this->createUser(32, 'manager-cskh', 'Care Manager', [7]);
        $this->authenticateAs($manager);
        $pending = $this->createCustomerCare(['is_accept' => 0, 'total_edit' => 2]);
        $approved = $this->createCustomerCare(['is_accept' => 1, 'total_edit' => 2]);

        $approve = Request::create(
            "/api/v1/customer-cares/{$pending->id}/accept",
            'POST',
            ['is_accept' => 1, 'reason' => 'Hợp lệ']
        );
        $reject = Request::create(
            "/api/v1/customer-cares/{$approved->id}/accept",
            'POST',
            ['is_accept' => 0, 'reason' => 'Không hợp lệ']
        );

        $this->assertSame(403, $this->controller->accept($pending->id, $approve)->getStatusCode());
        $this->assertSame(403, $this->controller->accept($approved->id, $reject)->getStatusCode());
        $this->assertSame(0, (int) $pending->fresh()->is_accept);
        $this->assertSame(1, (int) $approved->fresh()->is_accept);

        $this->grantPermission($manager, 'accept-schedule');
        $manager->unsetRelation('role');
        $this->assertTrue($this->controller->accept($pending->id, $approve)->getData(true)['success']);
        $this->assertSame(403, $this->controller->accept($approved->id, $reject)->getStatusCode());
    }

    public function test_admin_can_review_without_explicit_role_permission(): void
    {
        $admin = $this->createUser(50, 'admin', 'Admin', []);
        $this->authenticateAs($admin);
        $pending = $this->createCustomerCare(['is_accept' => 0, 'total_edit' => 2]);
        $request = Request::create(
            "/api/v1/customer-cares/{$pending->id}/accept",
            'POST',
            ['is_accept' => 1]
        );

        $response = $this->controller->accept($pending->id, $request);

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame(1, (int) $pending->fresh()->is_accept);
        $this->assertSame($admin->id, (int) $pending->fresh()->user_accept_id);
    }

    public function test_reject_requires_non_blank_trimmed_reason(): void
    {
        $manager = $this->createUser(32, 'manager-cskh', 'Care Manager', [7]);
        $this->grantPermission($manager, 'reject-cskh');
        $this->authenticateAs($manager);
        $approved = $this->createCustomerCare(['is_accept' => 1, 'total_edit' => 2]);

        foreach ([null, '   '] as $reason) {
            $request = Request::create(
                "/api/v1/customer-cares/{$approved->id}/accept",
                'POST',
                ['is_accept' => 0, 'reason' => $reason]
            );
            $this->assertSame(422, $this->controller->accept($approved->id, $request)->getStatusCode());
        }

        $this->assertSame(1, (int) $approved->fresh()->is_accept);
        $valid = Request::create(
            "/api/v1/customer-cares/{$approved->id}/accept",
            'POST',
            ['is_accept' => 0, 'reason' => '  Sai thông tin  ']
        );
        $this->assertTrue($this->controller->accept($approved->id, $valid)->getData(true)['success']);
        $this->assertSame('Sai thông tin', $approved->fresh()->reason);
    }

    public function test_invalid_or_repeated_approval_transitions_are_blocked(): void
    {
        $manager = $this->createUser(32, 'manager-cskh', 'Care Manager', [7]);
        $this->grantPermission($manager, 'accept-schedule');
        $this->grantPermission($manager, 'reject-cskh');
        $this->authenticateAs($manager);
        $pending = $this->createCustomerCare(['is_accept' => 0, 'total_edit' => 2]);
        $approved = $this->createCustomerCare(['is_accept' => 1, 'total_edit' => 2]);

        $approveApproved = Request::create(
            "/api/v1/customer-cares/{$approved->id}/accept",
            'POST',
            ['is_accept' => 1]
        );
        $rejectPending = Request::create(
            "/api/v1/customer-cares/{$pending->id}/accept",
            'POST',
            ['is_accept' => 0, 'reason' => 'Không hợp lệ']
        );

        $this->assertSame(409, $this->controller->accept($approved->id, $approveApproved)->getStatusCode());
        $this->assertSame(409, $this->controller->accept($pending->id, $rejectPending)->getStatusCode());
    }

    public function test_second_completion_is_rejected_without_mutation(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);
        Carbon::setTestNow('2026-08-21 15:10:00');
        $nextDate = '2026-08-28';
        $this->updateCare(
            $customerCare,
            1,
            '2026-08-20 10:00:00',
            ['next_date_care' => $nextDate]
        );

        Carbon::setTestNow('2026-08-22 09:00:00');
        $secondResponse = $this->updateCare(
            $customerCare->fresh(),
            1,
            '2026-08-22 08:00:00',
            ['next_date_care' => $nextDate]
        );

        $this->assertSame(409, $secondResponse->getStatusCode());
        $this->assertFalse($secondResponse->getData(true)['success']);
        $this->assertSame(1, (int) $customerCare->fresh()->status);
        $this->assertSame('2026-08-21 15:10:00', $customerCare->fresh()->time_care);
        $this->assertSame(
            '2026-08-21 15:10:00',
            $assignment->fresh()->cared_at->format('Y-m-d H:i:s')
        );
        $this->assertSame(1, ActivityLog::query()->where('action', 'customer_care.completed')->count());
        $this->assertSame(
            1,
            ActivityLog::query()
                ->where('action', 'customer_care.completed')
                ->sole()
                ->metadata['care_sequence_number']
        );
        $this->assertSame(1, DB::table('customer_care_journey_sequences')->value('last_sequence'));
        $this->assertSame(2, CustomerCare::query()->count());
        $this->assertSame(2, CustomerCareAssignment::query()->count());
    }

    public function test_status_already_completed_without_cca_marker_is_not_logged_again(): void
    {
        $customerCare = $this->createCustomerCare([
            'status' => 1,
            'time_care' => '2026-08-21 15:10:00',
        ]);
        $assignment = $this->createAssignment($customerCare);

        $response = $this->updateCare($customerCare, 1, null);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(1, (int) $customerCare->fresh()->status);
        $this->assertSame('2026-08-21 15:10:00', $customerCare->fresh()->time_care);
        $this->assertNull($assignment->fresh()->cared_at);
        $this->assertSame(0, ActivityLog::query()->count());
        $this->assertSame(0, DB::table('customer_care_journey_sequences')->count());
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

    public function test_follow_up_assignment_creation_failure_rolls_back_entire_completion(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);
        CustomerCareAssignment::creating(function () {
            throw new RuntimeException('Simulated follow-up assignment creation failure.');
        });

        try {
            $response = $this->updateCare(
                $customerCare,
                1,
                '2026-08-21 14:00:00',
                ['next_date_care' => '2026-08-28']
            );
        } finally {
            CustomerCareAssignment::flushEventListeners();
        }

        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(0, (int) $customerCare->fresh()->status);
        $this->assertNull($customerCare->fresh()->time_care);
        $this->assertNull($assignment->fresh()->cared_at);
        $this->assertSame(1, CustomerCare::query()->count());
        $this->assertSame(1, CustomerCareAssignment::query()->count());
        $this->assertSame(0, ActivityLog::query()->count());
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

    public function test_completion_writes_one_contract_event_with_local_identity_and_business_time(): void
    {
        $customer = $this->createLocalCustomer('CUSTOMER-EVENT-1');
        $order = $this->createOrder('ORDER-EVENT-1', 7, $customer->pancake_customer_id);
        $customerCare = $this->createCustomerCare([
            'pancake_customer_id' => $customer->pancake_customer_id,
            'pancake_order_id' => $order->pancake_order_id,
        ]);
        $assignment = $this->createAssignment(
            $customerCare,
            CustomerCareAssignment::STATUS_ACTIVE,
            $order->id
        );
        Carbon::setTestNow('2026-08-27 15:10:00');

        $response = $this->updateCare($customerCare, 1, '2000-01-01 00:00:00');
        $event = ActivityLog::query()->where('action', 'customer_care.completed')->sole();

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame(1, (int) $customerCare->fresh()->status);
        $this->assertSame('2026-08-27 15:10:00', $customerCare->fresh()->time_care);
        $this->assertSame('2026-08-27 15:10:00', $assignment->fresh()->cared_at->format('Y-m-d H:i:s'));
        $this->assertSame('customer_care', $event->subject_type);
        $this->assertSame((string) $customerCare->id, $event->subject_id);
        $this->assertSame($assignment->id, $event->metadata['assignment_id']);
        $this->assertSame($assignment->assignee_user_id, $event->metadata['assignee_user_id']);
        $this->assertSame(7, $event->shop_id);
        $this->assertSame($assignment->source_type, $event->metadata['source_type']);
        $this->assertSame($assignment->source_id, $event->metadata['source_id']);
        $this->assertSame($customer->id, $event->metadata['customer_id']);
        $this->assertSame($order->id, $event->metadata['order_id']);
        $this->assertSame(1, $event->metadata['care_sequence_number']);
        $this->assertSame('customer_shop', $event->metadata['sequence_scope']);
        $this->assertSame('journey_completed_events', $event->metadata['sequence_basis']);
        $this->assertNull($event->metadata['result']);
        $this->assertSame('user', $event->source);
        $this->assertSame(31, $event->actor_user_id);
        $this->assertSame(31, $event->target_user_id);
        $this->assertSame('2026-08-27 15:10:00', $event->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            'customer_care.completed:care:'.$customerCare->id,
            $event->idempotency_key
        );
    }

    public function test_same_customer_gets_ordered_sequence_across_three_real_completions(): void
    {
        $customer = $this->createLocalCustomer('CUSTOMER-SEQUENCE-1');
        $staffB = $this->createUser(32, 'staff-cskh', 'Second Care Staff', [7]);

        foreach ([1, 2, 3] as $number) {
            $order = $this->createOrder("ORDER-SEQUENCE-{$number}", 7, $customer->pancake_customer_id);
            $care = $this->createCustomerCare([
                'pancake_customer_id' => $customer->pancake_customer_id,
                'pancake_order_id' => $order->pancake_order_id,
            ]);
            $this->createAssignment(
                $care,
                CustomerCareAssignment::STATUS_ACTIVE,
                $order->id,
                CustomerCareAssignment::SOURCE_ORDER,
                $number === 2 ? $staffB->id : 31
            );
            $this->authenticateAs($number === 2 ? $staffB : User::findOrFail(31));
            Carbon::setTestNow("2026-08-27 15:{$number}0:00");

            $response = $this->updateCare($care, 1, null);

            $this->assertTrue($response->getData(true)['success']);
        }

        $sequences = ActivityLog::query()
            ->where('action', 'customer_care.completed')
            ->orderBy('id')
            ->get()
            ->pluck('metadata.care_sequence_number')
            ->all();

        $this->assertSame([1, 2, 3], $sequences);
    }

    public function test_different_customer_starts_at_one_and_same_external_id_isolated_by_shop(): void
    {
        $firstCustomer = $this->createLocalCustomer('CUSTOMER-SHARED');
        $otherCustomer = $this->createLocalCustomer('CUSTOMER-OTHER');
        $firstOrder = $this->createOrder('ORDER-SHARED-1', 7, $firstCustomer->pancake_customer_id);
        $otherOrder = $this->createOrder('ORDER-OTHER-1', 7, $otherCustomer->pancake_customer_id);

        $this->completeCareForOrder($firstOrder, $firstCustomer->pancake_customer_id);
        $this->completeCareForOrder($otherOrder, $otherCustomer->pancake_customer_id);

        $secondShop = 8;
        $this->createShop($secondShop);
        $secondCustomer = $this->createLocalCustomer('CUSTOMER-SHARED', $secondShop);
        $secondOrder = $this->createOrder('ORDER-SHARED-2', $secondShop, $secondCustomer->pancake_customer_id);
        $secondCare = $this->createCustomerCare([
            'shop_id' => $secondShop,
            'pancake_customer_id' => $secondCustomer->pancake_customer_id,
            'pancake_order_id' => $secondOrder->pancake_order_id,
        ]);
        $secondAssignment = $this->createAssignment($secondCare, CustomerCareAssignment::STATUS_ACTIVE, $secondOrder->id);
        $manager = $this->createUser(40, 'manager-cskh', 'Shop 8 Manager', [$secondShop]);
        $this->authenticateAs($manager);

        $this->assertTrue($this->updateCare($secondCare, 1, null)->getData(true)['success']);

        $events = ActivityLog::query()->where('action', 'customer_care.completed')->orderBy('id')->get();
        $this->assertSame(1, $events[0]->metadata['care_sequence_number']);
        $this->assertSame(1, $events[1]->metadata['care_sequence_number']);
        $this->assertSame(1, $events[2]->metadata['care_sequence_number']);
        $this->assertSame(7, $events[0]->shop_id);
        $this->assertSame(7, $events[1]->shop_id);
        $this->assertSame(8, $events[2]->shop_id);
        $this->assertSame($secondAssignment->assignee_user_id, $events[2]->target_user_id);
    }

    public function test_reclaimed_uncared_assignment_does_not_consume_a_sequence_number(): void
    {
        $customer = $this->createLocalCustomer('CUSTOMER-RECLAIMED-1');
        $reclaimedOrder = $this->createOrder('ORDER-RECLAIMED-1', 7, $customer->pancake_customer_id);
        $reclaimedCare = $this->createCustomerCare([
            'pancake_customer_id' => $customer->pancake_customer_id,
            'pancake_order_id' => $reclaimedOrder->pancake_order_id,
        ]);
        $this->createAssignment(
            $reclaimedCare,
            CustomerCareAssignment::STATUS_RECLAIMED,
            $reclaimedOrder->id
        );

        $validOrder = $this->createOrder('ORDER-RECLAIMED-2', 7, $customer->pancake_customer_id);
        $validCare = $this->createCustomerCare([
            'pancake_customer_id' => $customer->pancake_customer_id,
            'pancake_order_id' => $validOrder->pancake_order_id,
        ]);
        $this->createAssignment($validCare, CustomerCareAssignment::STATUS_ACTIVE, $validOrder->id);

        $this->assertTrue($this->updateCare($validCare, 1, null)->getData(true)['success']);
        $event = ActivityLog::query()->where('action', 'customer_care.completed')->sole();

        $this->assertSame(1, $event->metadata['care_sequence_number']);
        $this->assertSame(1, ActivityLog::query()->where('action', 'customer_care.completed')->count());
    }

    public function test_imported_opportunity_sequence_is_scoped_to_shop_and_source(): void
    {
        $first = $this->createCustomerCare(['pancake_customer_id' => 'IMPORT-1']);
        $second = $this->createCustomerCare(['pancake_customer_id' => 'IMPORT-1']);
        $third = $this->createCustomerCare(['pancake_customer_id' => 'IMPORT-1']);
        $this->createAssignment($first, CustomerCareAssignment::STATUS_ACTIVE, 7001, CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY);
        $this->createAssignment($second, CustomerCareAssignment::STATUS_ACTIVE, 7001, CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY);
        $this->createAssignment($third, CustomerCareAssignment::STATUS_ACTIVE, 7002, CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY);

        $this->assertTrue($this->updateCare($first, 1, null)->getData(true)['success']);
        $this->assertTrue($this->updateCare($second, 1, null)->getData(true)['success']);
        $this->assertTrue($this->updateCare($third, 1, null)->getData(true)['success']);

        $events = ActivityLog::query()->where('action', 'customer_care.completed')->orderBy('id')->get();
        $this->assertSame([1, 2, 1], $events->pluck('metadata.care_sequence_number')->all());
        $this->assertSame([
            'imported_opportunity_source',
            'imported_opportunity_source',
            'imported_opportunity_source',
        ], $events->pluck('metadata.sequence_scope')->all());
    }

    public function test_completion_and_event_roll_back_together_when_activity_logging_fails(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);
        $activityLog = Mockery::mock(ActivityLogService::class);
        $activityLog->shouldReceive('write')->once()->andThrow(new RuntimeException('activity log failed'));
        $controller = new CustomerCareController(
            new CustomerCareAssignmentService,
            $activityLog,
            new ShopAccessService,
            new CustomerCareWriteAccessService(new ShopAccessService)
        );

        $response = $this->updateCareWithController($controller, $customerCare, 1, null);

        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(0, (int) $customerCare->fresh()->status);
        $this->assertNull($customerCare->fresh()->time_care);
        $this->assertNull($assignment->fresh()->cared_at);
        $this->assertSame(0, ActivityLog::query()->count());
        $this->assertSame(0, DB::table('customer_care_journey_sequences')->count());
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
        ?string $timeCare,
        array $extraPayload = []
    ) {
        return $this->updateCareWithController(
            $this->controller,
            $customerCare,
            $status,
            $timeCare,
            $extraPayload
        );
    }

    private function updateCareWithController(
        CustomerCareController $controller,
        CustomerCare $customerCare,
        int $status,
        ?string $timeCare,
        array $extraPayload = []
    ) {
        $request = Request::create(
            "/api/v1/customer-cares/{$customerCare->id}",
            'PUT',
            array_merge([
                'status' => $status,
                'date' => $timeCare,
                'note' => 'Care note',
            ], $extraPayload)
        );

        return $controller->update($request, $customerCare);
    }

    private function indexCustomerCareIds(string $type): array
    {
        return array_map(
            static fn (array $customer) => (int) $customer['id'],
            $this->indexCustomerCareRows($type)
        );
    }

    private function indexCustomerCareRows(string $type): array
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

        return $payload['data']['customers'];
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

    private function createLocalCustomer(string $pancakeCustomerId, int $shopId = 7): Customer
    {
        return Customer::create([
            'shop_id' => $shopId,
            'pancake_customer_id' => $pancakeCustomerId,
        ]);
    }

    private function createOrder(
        string $pancakeOrderId,
        int $shopId,
        string $pancakeCustomerId
    ): Order {
        return Order::create([
            'shop_id' => $shopId,
            'pancake_order_id' => $pancakeOrderId,
            'pancake_customer_id' => $pancakeCustomerId,
            'status' => 3,
        ]);
    }

    private function completeCareForOrder(
        Order $order,
        string $pancakeCustomerId,
        ?int $assigneeUserId = null
    ): CustomerCare {
        $care = $this->createCustomerCare([
            'shop_id' => $order->shop_id,
            'pancake_customer_id' => $pancakeCustomerId,
            'pancake_order_id' => $order->pancake_order_id,
        ]);
        $this->createAssignment($care, CustomerCareAssignment::STATUS_ACTIVE, $order->id, CustomerCareAssignment::SOURCE_ORDER, $assigneeUserId);
        Carbon::setTestNow('2026-08-27 15:00:00');
        $this->assertTrue($this->updateCare($care, 1, null)->getData(true)['success']);

        return $care;
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

    private function grantPermission(User $user, string $slug): void
    {
        $groupId = DB::table('permission_groups')->value('id');
        if ($groupId === null) {
            $groupId = DB::table('permission_groups')->insertGetId([
                'name' => 'Customer Care',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'permission_group_id' => $groupId,
                'name' => $slug,
                'slug' => $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('role_permissions')->insert([
            'role_id' => $user->role_id,
            'permission_id' => $permissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->unsetRelation('role');
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
            $table->string('pancake_order_page_id')->nullable();
            $table->string('pancake_order_page_name')->nullable();
            $table->string('pancake_customer_id')->nullable();
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

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_customer_id');
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
            $table->timestamp('occurred_at')->nullable();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('customer_care_journey_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('sequence_scope', 50);
            $table->string('scope_key', 191);
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();
            $table->unique(['shop_id', 'sequence_scope', 'scope_key']);
        });
    }
}
