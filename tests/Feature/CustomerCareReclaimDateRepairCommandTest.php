<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerCareReclaimDateRepairCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Ho_Chi_Minh',
            'database.default' => 'reclaim_date_repair_testing',
            'database.connections.reclaim_date_repair_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('reclaim_date_repair_testing');
        DB::setDefaultConnection('reclaim_date_repair_testing');
        $this->createSchema();
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-25 12:00:00', 'Asia/Ho_Chi_Minh')
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_preview_reports_old_active_uncared_candidate_without_writes(): void
    {
        $care = $this->createCare('2026-08-24');
        $assignment = $this->createAssignment($care, '2026-08-24');

        $this->artisan('customer-care:repair-reclaim-dates')
            ->expectsOutputToContain('PREVIEW ONLY')
            ->expectsOutputToContain('27/08/2026')
            ->expectsOutputToContain('Candidates: 1')
            ->expectsOutputToContain('Currently prematurely due: 1')
            ->expectsOutputToContain('Would update: 1')
            ->expectsOutputToContain('Updated: 0')
            ->assertSuccessful();

        $this->assertSame('2026-08-24', $assignment->fresh()->reclaim_eligible_on->toDateString());
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_execute_repairs_only_reclaim_date_and_records_safe_audit_log(): void
    {
        $order = $this->createOrder(status: 3);
        $care = $this->createCare(
            '2026-08-24',
            status: 0,
            timeCare: '2026-08-25 08:30:00',
            pancakeOrderId: $order->pancake_order_id
        );
        $assignment = $this->createAssignment($care, '2026-08-24', sourceId: $order->id);
        $assignedAt = $assignment->assigned_at->format('Y-m-d H:i:s');

        $this->artisan('customer-care:repair-reclaim-dates', [
            '--ids' => (string) $assignment->id,
            '--execute' => true,
        ])->expectsOutputToContain('EXECUTE')
            ->expectsOutputToContain('Updated: 1')
            ->assertSuccessful();

        $assignment->refresh();
        $care->refresh();
        $this->assertSame('2026-08-27', $assignment->reclaim_eligible_on->toDateString());
        $this->assertSame($assignedAt, $assignment->assigned_at->format('Y-m-d H:i:s'));
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->status);
        $this->assertNull($assignment->cared_at);
        $this->assertSame('2026-08-24', $care->date_care);
        $this->assertSame(0, (int) $care->status);
        $this->assertSame('2026-08-25 08:30:00', $care->time_care);
        $this->assertSame(3, (int) $order->fresh()->status);

        $log = ActivityLog::query()->sole();
        $this->assertSame('customer_care.reclaim_date_repaired', $log->action);
        $this->assertSame('system', $log->source);
        $this->assertSame('customer_care_assignment', $log->subject_type);
        $this->assertSame((string) $assignment->id, $log->subject_id);
        $this->assertSame(['reclaim_eligible_on' => '2026-08-24'], $log->old_values);
        $this->assertSame(['reclaim_eligible_on' => '2026-08-27'], $log->new_values);
        $this->assertSame($care->id, $log->metadata['customer_care_id']);
        $this->assertSame(CustomerCareAssignment::SOURCE_ORDER, $log->metadata['source_type']);
        $this->assertSame($order->id, $log->metadata['source_id']);
    }

    public function test_aligned_active_assignment_is_not_a_candidate(): void
    {
        $assignment = $this->createAssignment($this->createCare('2026-08-24'), '2026-08-27');

        $this->artisan('customer-care:repair-reclaim-dates', ['--ids' => (string) $assignment->id])
            ->expectsOutputToContain('Candidates: 0')
            ->expectsOutputToContain('Already aligned: 1')
            ->expectsOutputToContain('Updated: 0')
            ->assertSuccessful();

        $this->assertSame('2026-08-27', $assignment->fresh()->reclaim_eligible_on->toDateString());
    }

    public function test_active_cared_assignment_is_normalized_without_reopening_care(): void
    {
        $care = $this->createCare('2026-08-27', status: 1, timeCare: '2026-08-25 09:15:00');
        $assignment = $this->createAssignment(
            $care,
            '2026-08-27',
            caredAt: '2026-08-25 09:15:00'
        );

        $this->artisan('customer-care:repair-reclaim-dates', [
            '--ids' => (string) $assignment->id,
            '--execute' => true,
        ])->expectsOutputToContain('Cared candidates: 1')
            ->expectsOutputToContain('Updated: 1')
            ->assertSuccessful();

        $assignment->refresh();
        $care->refresh();
        $this->assertSame('2026-08-30', $assignment->reclaim_eligible_on->toDateString());
        $this->assertSame('2026-08-25 09:15:00', $assignment->cared_at->format('Y-m-d H:i:s'));
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->status);
        $this->assertSame(1, (int) $care->status);
        $this->assertSame('2026-08-25 09:15:00', $care->time_care);
    }

    public function test_reclaimed_assignment_is_out_of_scope_and_unchanged(): void
    {
        $assignment = $this->createAssignment(
            $this->createCare('2026-08-24'),
            '2026-08-24',
            status: CustomerCareAssignment::STATUS_RECLAIMED
        );

        $this->artisan('customer-care:repair-reclaim-dates', [
            '--ids' => (string) $assignment->id,
            '--execute' => true,
        ])->expectsOutputToContain('Scanned: 0')
            ->expectsOutputToContain('Updated: 0')
            ->assertSuccessful();

        $this->assertSame('2026-08-24', $assignment->fresh()->reclaim_eligible_on->toDateString());
        $this->assertSame(CustomerCareAssignment::STATUS_RECLAIMED, $assignment->fresh()->status);
    }

    public function test_missing_customer_care_and_null_date_are_reported_and_skipped(): void
    {
        $missing = $this->createAssignment(null, '2026-08-24');
        $nullDate = $this->createAssignment($this->createCare(null), '2026-08-24');

        $this->artisan('customer-care:repair-reclaim-dates', [
            '--ids' => "{$missing->id},{$nullDate->id}",
            '--execute' => true,
        ])->expectsOutputToContain('Skipped missing relation: 1')
            ->expectsOutputToContain('Skipped null date: 1')
            ->expectsOutputToContain('Updated: 0')
            ->assertSuccessful();

        $this->assertSame('2026-08-24', $missing->fresh()->reclaim_eligible_on->toDateString());
        $this->assertSame('2026-08-24', $nullDate->fresh()->reclaim_eligible_on->toDateString());
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_ids_are_deduplicated_and_never_expand_scope(): void
    {
        $inside = $this->createAssignment($this->createCare('2026-08-24'), '2026-08-24');
        $outside = $this->createAssignment($this->createCare('2026-08-28'), '2026-08-28');

        $this->artisan('customer-care:repair-reclaim-dates', [
            '--ids' => "{$inside->id},{$inside->id}",
            '--execute' => true,
        ])->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Updated: 1')
            ->assertSuccessful();

        $this->assertSame('2026-08-27', $inside->fresh()->reclaim_eligible_on->toDateString());
        $this->assertSame('2026-08-28', $outside->fresh()->reclaim_eligible_on->toDateString());
        $this->assertSame(1, ActivityLog::query()->count());
    }

    public function test_malformed_ids_are_rejected_before_any_scan_or_write(): void
    {
        $assignment = $this->createAssignment($this->createCare('2026-08-24'), '2026-08-24');

        $this->artisan('customer-care:repair-reclaim-dates', [
            '--ids' => "{$assignment->id},bad",
            '--execute' => true,
        ])->expectsOutputToContain('positive integers')
            ->assertExitCode(2);

        $this->assertSame('2026-08-24', $assignment->fresh()->reclaim_eligible_on->toDateString());
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_execute_is_idempotent_and_corrected_date_is_not_premature(): void
    {
        $assignment = $this->createAssignment($this->createCare('2026-08-24'), '2026-08-24');

        $this->artisan('customer-care:repair-reclaim-dates', [
            '--ids' => (string) $assignment->id,
            '--execute' => true,
        ])->expectsOutputToContain('Updated: 1')->assertSuccessful();

        $this->artisan('customer-care:repair-reclaim-dates', [
            '--ids' => (string) $assignment->id,
            '--execute' => true,
        ])->expectsOutputToContain('Candidates: 0')
            ->expectsOutputToContain('Currently prematurely due: 0')
            ->expectsOutputToContain('Updated: 0')
            ->assertSuccessful();

        $this->assertSame('2026-08-27', $assignment->fresh()->reclaim_eligible_on->toDateString());
        $this->assertTrue($assignment->fresh()->reclaim_eligible_on->isAfter(CarbonImmutable::today()));
        $this->assertSame(1, ActivityLog::query()->count());
    }

    private function createCare(
        ?string $dateCare,
        int $status = 0,
        ?string $timeCare = null,
        ?string $pancakeOrderId = null
    ): CustomerCare {
        return CustomerCare::create([
            'shop_id' => 1,
            'pancake_customer_id' => 'CUSTOMER-'.uniqid(),
            'pancake_order_id' => $pancakeOrderId,
            'date_care' => $dateCare,
            'status' => $status,
            'time_care' => $timeCare,
        ]);
    }

    private function createAssignment(
        ?CustomerCare $care,
        string $reclaimEligibleOn,
        ?string $caredAt = null,
        string $status = CustomerCareAssignment::STATUS_ACTIVE,
        ?int $sourceId = null
    ): CustomerCareAssignment {
        return CustomerCareAssignment::create([
            'shop_id' => 1,
            'customer_care_id' => $care?->id ?? 999999,
            'source_type' => CustomerCareAssignment::SOURCE_ORDER,
            'source_id' => $sourceId ?? 999999,
            'assignee_user_id' => 2,
            'assignee_pancake_user_id' => 'PANCAKE-2',
            'assigned_at' => '2026-08-21 23:59:00',
            'reclaim_eligible_on' => $reclaimEligibleOn,
            'status' => $status,
            'cared_at' => $caredAt,
        ]);
    }

    private function createOrder(int $status): Order
    {
        return Order::create([
            'shop_id' => 1,
            'pancake_order_id' => 'ORDER-'.uniqid(),
            'pancake_customer_id' => 'CUSTOMER-'.uniqid(),
            'status' => $status,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_order_id');
            $table->string('pancake_customer_id');
            $table->integer('status');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('customer_cares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_customer_id');
            $table->string('pancake_order_id')->nullable();
            $table->date('date_care')->nullable();
            $table->integer('status')->default(0);
            $table->dateTime('time_care')->nullable();
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
