<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CustomerCareController;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\CustomerCareAssignmentService;
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
            new ActivityLogService
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_valid_care_sets_active_assignment_marker_from_server_time(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);
        Carbon::setTestNow('2026-08-21 15:10:00');

        $response = $this->updateCare($customerCare, 1, '2026-08-20 10:00:00');

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame(
            '2026-08-20 10:00:00',
            CustomerCare::findOrFail($customerCare->id)->time_care
        );
        $this->assertSame(
            '2026-08-21 15:10:00',
            $assignment->fresh()->cared_at->format('Y-m-d H:i:s')
        );
    }

    public function test_incomplete_and_inconsistent_states_do_not_set_marker(): void
    {
        $notCared = $this->createCustomerCare();
        $notCaredAssignment = $this->createAssignment($notCared);
        $this->updateCare($notCared, 0, null);

        $statusOnly = $this->createCustomerCare();
        $statusOnlyAssignment = $this->createAssignment($statusOnly);
        $this->updateCare($statusOnly, 1, null);

        $timeOnly = $this->createCustomerCare();
        $timeOnlyAssignment = $this->createAssignment($timeOnly);
        $this->updateCare($timeOnly, 0, '2026-08-21 12:00:00');

        $this->assertNull($notCaredAssignment->fresh()->cared_at);
        $this->assertNull($statusOnlyAssignment->fresh()->cared_at);
        $this->assertNull($timeOnlyAssignment->fresh()->cared_at);
    }

    public function test_confirm_does_not_set_cared_at(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);

        $response = $this->controller->confirmCare($customerCare->id);

        $this->assertTrue($response->getData(true)['success']);
        $this->assertTrue((bool) $customerCare->fresh()->is_confirm_care);
        $this->assertNull($assignment->fresh()->cared_at);
    }

    public function test_accept_does_not_set_cared_at(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);
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

    public function test_first_cared_at_is_not_overwritten_by_later_care_update(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare);
        Carbon::setTestNow('2026-08-21 15:10:00');
        $this->updateCare($customerCare, 1, '2026-08-20 10:00:00');

        Carbon::setTestNow('2026-08-22 09:00:00');
        $this->updateCare($customerCare->fresh(), 1, '2026-08-22 08:00:00');

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
            $assignment->fresh()->cared_at->format('Y-m-d H:i:s')
        );
    }

    public function test_legacy_care_without_assignment_still_updates(): void
    {
        $customerCare = $this->createCustomerCare();

        $response = $this->updateCare($customerCare, 1, '2026-08-21 14:00:00');

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame(1, (int) $customerCare->fresh()->status);
        $this->assertSame(0, CustomerCareAssignment::query()->count());
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

    public function test_reclaimed_assignment_is_not_marked(): void
    {
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment(
            $customerCare,
            CustomerCareAssignment::STATUS_RECLAIMED
        );

        $response = $this->updateCare($customerCare, 1, '2026-08-21 14:00:00');

        $this->assertTrue($response->getData(true)['success']);
        $this->assertNull($assignment->fresh()->cared_at);
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

    private function createCustomerCare(): CustomerCare
    {
        return CustomerCare::create([
            'shop_id' => 7,
            'pancake_customer_id' => 'CUSTOMER-'.uniqid(),
            'date_care' => '2026-08-21',
            'status' => 0,
            'time_care' => null,
            'total_edit' => 0,
            'is_accept' => 1,
            'is_confirm_care' => false,
        ]);
    }

    private function createAssignment(
        CustomerCare $customerCare,
        string $status = CustomerCareAssignment::STATUS_ACTIVE
    ): CustomerCareAssignment {
        return CustomerCareAssignment::create([
            'shop_id' => $customerCare->shop_id,
            'customer_care_id' => $customerCare->id,
            'source_type' => CustomerCareAssignment::SOURCE_ORDER,
            'source_id' => $customerCare->id + 1000,
            'assignee_user_id' => Auth::id(),
            'assignee_pancake_user_id' => 'PANCAKE-31',
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
    }
}
