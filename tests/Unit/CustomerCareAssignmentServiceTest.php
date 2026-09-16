<?php

namespace Tests\Unit;

use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerCareAssignmentService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CustomerCareAssignmentServiceTest extends TestCase
{
    private CustomerCareAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'phase_b_testing',
            'database.connections.phase_b_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('phase_b_testing');
        DB::setDefaultConnection('phase_b_testing');

        Schema::create('customer_cares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_customer_id');
            $table->date('date_care');
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

        $this->service = new CustomerCareAssignmentService;
    }

    public function test_order_assignment_has_expected_identity_assignee_and_dates(): void
    {
        $assignedAt = CarbonImmutable::parse('2026-08-21 23:59:59', 'Asia/Ho_Chi_Minh');

        $assignment = $this->service->create(
            $this->persistedCustomerCare(101, 7),
            7,
            CustomerCareAssignment::SOURCE_ORDER,
            501,
            $this->persistedAssignee(31, 'PANCAKE-31'),
            $assignedAt,
            CarbonImmutable::parse('2026-08-24', 'Asia/Ho_Chi_Minh')
        );

        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->status);
        $this->assertSame(CustomerCareAssignment::SOURCE_ORDER, $assignment->source_type);
        $this->assertSame(501, $assignment->source_id);
        $this->assertSame(101, $assignment->customer_care_id);
        $this->assertSame(7, $assignment->shop_id);
        $this->assertSame(31, $assignment->assignee_user_id);
        $this->assertSame('PANCAKE-31', $assignment->assignee_pancake_user_id);
        $this->assertSame('2026-08-21 23:59:59', $assignment->assigned_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-27', $assignment->reclaim_eligible_on->toDateString());
        $this->assertNull($assignment->cared_at);
    }

    public function test_imported_opportunity_assignment_has_expected_source_identity(): void
    {
        $assignment = $this->service->create(
            $this->persistedCustomerCare(102, 8),
            8,
            CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY,
            601,
            $this->persistedAssignee(32, 'PANCAKE-32'),
            CarbonImmutable::parse('2026-08-21 09:00:00', 'Asia/Ho_Chi_Minh'),
            CarbonImmutable::parse('2026-08-26', 'Asia/Ho_Chi_Minh')
        );

        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->status);
        $this->assertSame(CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY, $assignment->source_type);
        $this->assertSame(601, $assignment->source_id);
        $this->assertSame(32, $assignment->assignee_user_id);
        $this->assertSame('PANCAKE-32', $assignment->assignee_pancake_user_id);
        $this->assertSame('2026-08-29', $assignment->reclaim_eligible_on->toDateString());
        $this->assertNull($assignment->cared_at);
    }

    public function test_duplicate_active_assignment_is_prevented(): void
    {
        $customerCare = $this->persistedCustomerCare(103, 9);
        $assignee = $this->persistedAssignee(33, 'PANCAKE-33');
        $assignedAt = CarbonImmutable::parse('2026-08-21 10:00:00', 'Asia/Ho_Chi_Minh');

        $this->service->create(
            $customerCare,
            9,
            CustomerCareAssignment::SOURCE_ORDER,
            701,
            $assignee,
            $assignedAt,
            CarbonImmutable::parse('2026-08-24', 'Asia/Ho_Chi_Minh')
        );

        $this->expectException(DomainException::class);

        try {
            $this->service->create(
                $this->persistedCustomerCare(104, 9),
                9,
                CustomerCareAssignment::SOURCE_ORDER,
                701,
                $assignee,
                $assignedAt,
                CarbonImmutable::parse('2026-08-24', 'Asia/Ho_Chi_Minh')
            );
        } finally {
            $this->assertSame(1, CustomerCareAssignment::query()->count());
        }
    }

    public function test_completed_active_assignment_does_not_block_follow_up_assignment(): void
    {
        CustomerCareAssignment::create([
            'shop_id' => 9,
            'customer_care_id' => 103,
            'source_type' => CustomerCareAssignment::SOURCE_ORDER,
            'source_id' => 701,
            'assignee_user_id' => 33,
            'assignee_pancake_user_id' => 'PANCAKE-33',
            'assigned_at' => '2026-08-21 10:00:00',
            'reclaim_eligible_on' => '2026-08-24',
            'status' => CustomerCareAssignment::STATUS_ACTIVE,
            'cared_at' => '2026-08-24 10:00:00',
        ]);

        $followUp = $this->service->create(
            $this->persistedCustomerCare(104, 9),
            9,
            CustomerCareAssignment::SOURCE_ORDER,
            701,
            $this->persistedAssignee(33, 'PANCAKE-33'),
            CarbonImmutable::parse('2026-08-24 10:00:00', 'Asia/Ho_Chi_Minh'),
            CarbonImmutable::parse('2026-08-28', 'Asia/Ho_Chi_Minh')
        );

        $this->assertSame(2, CustomerCareAssignment::query()->count());
        $this->assertSame(104, $followUp->customer_care_id);
        $this->assertNull($followUp->cared_at);
    }

    public function test_assignment_insert_is_rolled_back_with_owning_transaction(): void
    {
        try {
            DB::transaction(function () {
                $customerCare = CustomerCare::create([
                    'shop_id' => 10,
                    'pancake_customer_id' => 'IMPORT-801',
                    'date_care' => '2026-08-24',
                ]);

                $this->service->create(
                    $customerCare,
                    10,
                    CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY,
                    801,
                    $this->persistedAssignee(34, 'PANCAKE-34'),
                    CarbonImmutable::parse('2026-08-21 11:00:00', 'Asia/Ho_Chi_Minh'),
                    CarbonImmutable::parse('2026-08-24', 'Asia/Ho_Chi_Minh')
                );

                throw new RuntimeException('Simulated downstream activity-log failure.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated downstream activity-log failure.', $exception->getMessage());
        }

        $this->assertSame(0, CustomerCare::query()->count());
        $this->assertSame(0, CustomerCareAssignment::query()->count());
    }

    private function persistedCustomerCare(int $id, int $shopId): CustomerCare
    {
        $customerCare = new CustomerCare;
        $customerCare->setRawAttributes(['id' => $id, 'shop_id' => $shopId]);
        $customerCare->exists = true;

        return $customerCare;
    }

    private function persistedAssignee(int $id, string $pancakeUserId): User
    {
        $assignee = new User;
        $assignee->setRawAttributes(['id' => $id, 'pancake_user_id' => $pancakeUserId]);
        $assignee->setRelation('role', new Role(['slug' => 'staff-cskh']));
        $assignee->exists = true;

        return $assignee;
    }
}
