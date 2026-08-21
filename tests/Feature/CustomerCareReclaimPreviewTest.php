<?php

namespace Tests\Feature;

use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Shop;
use App\Services\CustomerCareReclaimService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerCareReclaimPreviewTest extends TestCase
{
    private CustomerCareReclaimService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Ho_Chi_Minh',
            'database.default' => 'phase_d_testing',
            'database.connections.phase_d_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('phase_d_testing');
        DB::setDefaultConnection('phase_d_testing');
        $this->createSchema();
        $this->createFoundationRecords();

        $this->service = new CustomerCareReclaimService;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_assignment_is_not_eligible_before_calendar_eligibility_date(): void
    {
        $assignment = $this->createAssignment(
            $this->createCustomerCare(),
            '2026-08-24'
        );

        $result = $this->evaluate($assignment, '2026-08-23 23:59:59');

        $this->assertSame(CustomerCareReclaimService::RESULT_NOT_YET_DUE, $result['result']);
    }

    public function test_assignment_is_eligible_on_and_after_calendar_eligibility_date(): void
    {
        $onDate = $this->createAssignment(
            $this->createCustomerCare(),
            '2026-08-24'
        );
        $afterDate = $this->createAssignment(
            $this->createCustomerCare(),
            '2026-08-24'
        );

        $this->assertSame(
            CustomerCareReclaimService::RESULT_ELIGIBLE,
            $this->evaluate($onDate, '2026-08-24 00:00:00')['result']
        );
        $this->assertSame(
            CustomerCareReclaimService::RESULT_ELIGIBLE,
            $this->evaluate($afterDate, '2026-08-25 12:00:00')['result']
        );
    }

    public function test_assignment_and_persisted_care_markers_both_skip_completed_care(): void
    {
        $markerAssignment = $this->createAssignment(
            $this->createCustomerCare(),
            '2026-08-24',
            CustomerCareAssignment::STATUS_ACTIVE,
            '2026-08-23 10:00:00'
        );
        $persistedCareAssignment = $this->createAssignment(
            $this->createCustomerCare(1, '2026-08-23 11:00:00'),
            '2026-08-24'
        );

        $this->assertSame(
            CustomerCareReclaimService::RESULT_ALREADY_CARED,
            $this->evaluate($markerAssignment)['result']
        );
        $this->assertSame(
            CustomerCareReclaimService::RESULT_ALREADY_CARED,
            $this->evaluate($persistedCareAssignment)['result']
        );
    }

    public function test_confirm_and_accept_do_not_prevent_reclaim_eligibility(): void
    {
        $confirmed = $this->createAssignment(
            $this->createCustomerCare(0, null, true, false),
            '2026-08-24'
        );
        $accepted = $this->createAssignment(
            $this->createCustomerCare(0, null, false, true),
            '2026-08-24'
        );

        $this->assertSame(
            CustomerCareReclaimService::RESULT_ELIGIBLE,
            $this->evaluate($confirmed)['result']
        );
        $this->assertSame(
            CustomerCareReclaimService::RESULT_ELIGIBLE,
            $this->evaluate($accepted)['result']
        );
    }

    public function test_reclaimed_assignment_is_skipped_and_excluded_from_preview_scan(): void
    {
        $assignment = $this->createAssignment(
            $this->createCustomerCare(),
            '2026-08-24',
            CustomerCareAssignment::STATUS_RECLAIMED
        );

        $this->assertSame(
            CustomerCareReclaimService::RESULT_SKIPPED,
            $this->evaluate($assignment)['result']
        );

        $preview = $this->service->preview($this->referenceTime(), null, 100);

        $this->assertSame(0, $preview['summary']['scanned']);
        $this->assertSame([], $preview['rows']);
    }

    public function test_inconsistent_customer_care_states_are_reported_as_anomalies(): void
    {
        $statusOnly = $this->createAssignment(
            $this->createCustomerCare(1, null),
            '2026-08-25'
        );
        $timeOnly = $this->createAssignment(
            $this->createCustomerCare(0, '2026-08-23 10:00:00'),
            '2026-08-25'
        );

        $statusOnlyResult = $this->evaluate($statusOnly);
        $timeOnlyResult = $this->evaluate($timeOnly);

        $this->assertSame(CustomerCareReclaimService::RESULT_ANOMALY, $statusOnlyResult['result']);
        $this->assertStringContainsString('status=1', $statusOnlyResult['reason']);
        $this->assertSame(CustomerCareReclaimService::RESULT_ANOMALY, $timeOnlyResult['result']);
        $this->assertStringContainsString('status=0', $timeOnlyResult['reason']);
    }

    public function test_soft_deleted_shop_is_skipped_as_an_invalid_relation(): void
    {
        $assignment = $this->createAssignment(
            $this->createCustomerCare(),
            '2026-08-24'
        );
        Shop::findOrFail(1)->delete();
        $assignment->unsetRelations();

        $result = $this->evaluate($assignment);

        $this->assertSame(CustomerCareReclaimService::RESULT_INVALID_RELATION, $result['result']);
        $this->assertStringContainsString('soft deleted', $result['reason']);
    }

    public function test_legacy_customer_care_without_assignment_is_ignored(): void
    {
        $this->createCustomerCare();

        $preview = $this->service->preview($this->referenceTime(), null, 100);

        $this->assertSame(0, $preview['summary']['scanned']);
        $this->assertSame([], $preview['rows']);
    }

    public function test_preview_classifies_active_assignments_and_honors_shop_and_limit_filters(): void
    {
        $eligible = $this->createAssignment($this->createCustomerCare(), '2026-08-24');
        $this->createAssignment($this->createCustomerCare(), '2026-08-25');
        $this->createAssignment($this->createCustomerCare(), '2026-08-24', shopId: 2);

        $preview = $this->service->preview($this->referenceTime(), 1, 1);

        $this->assertSame('2026-08-24', $preview['reference_date']);
        $this->assertSame(1, $preview['summary']['scanned']);
        $this->assertSame(1, $preview['summary']['eligible']);
        $this->assertSame($eligible->id, $preview['rows'][0]['assignment_id']);
    }

    public function test_preview_query_uses_active_status_and_raw_eligibility_date_ranges(): void
    {
        $this->createAssignment($this->createCustomerCare(), '2026-08-24');
        $this->createAssignment($this->createCustomerCare(), '2026-08-25');
        $assignmentQueries = [];
        DB::listen(function ($query) use (&$assignmentQueries) {
            if (str_contains($query->sql, 'customer_care_assignments')) {
                $assignmentQueries[] = strtolower($query->sql);
            }
        });

        $this->service->preview($this->referenceTime(), null, 100);

        $this->assertTrue(collect($assignmentQueries)->contains(
            fn (string $sql) => str_contains($sql, '"status" = ?')
                && str_contains($sql, '"reclaim_eligible_on" <= ?')
        ));
        $this->assertTrue(collect($assignmentQueries)->contains(
            fn (string $sql) => str_contains($sql, '"status" = ?')
                && str_contains($sql, '"reclaim_eligible_on" > ?')
        ));
        $this->assertFalse(collect($assignmentQueries)->contains(
            fn (string $sql) => str_contains($sql, 'date("reclaim_eligible_on")')
        ));
    }

    public function test_dry_run_command_is_repeatable_and_performs_zero_database_writes(): void
    {
        $this->createAssignment($this->createCustomerCare(), '2026-08-24');
        DB::table('orders')->insert(['id' => 10, 'status' => 3]);
        DB::table('imported_opportunities')->insert(['id' => 20, 'status' => 1]);
        CarbonImmutable::setTestNow($this->referenceTime());
        $before = $this->databaseSnapshot();

        $this->artisan('customer-care:reclaim-stale', [
            '--dry-run' => true,
            '--limit' => 100,
        ])->expectsOutputToContain('DRY RUN ONLY')
            ->expectsOutputToContain('eligible')
            ->assertSuccessful();
        $afterFirstRun = $this->databaseSnapshot();

        $this->artisan('customer-care:reclaim-stale', [
            '--dry-run' => true,
            '--limit' => 100,
        ])->assertSuccessful();
        $afterSecondRun = $this->databaseSnapshot();

        $this->assertSame($before, $afterFirstRun);
        $this->assertSame($before, $afterSecondRun);
    }

    private function evaluate(
        CustomerCareAssignment $assignment,
        string $referenceTime = '2026-08-24 12:00:00'
    ): array {
        return $this->service->evaluate(
            $assignment,
            CarbonImmutable::parse($referenceTime, config('app.timezone'))
        );
    }

    private function referenceTime(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-24 12:00:00', config('app.timezone'));
    }

    private function createCustomerCare(
        int $status = 0,
        ?string $timeCare = null,
        bool $isConfirmed = false,
        bool $isAccepted = false
    ): CustomerCare {
        return CustomerCare::create([
            'shop_id' => 1,
            'pancake_customer_id' => 'CUSTOMER-'.uniqid(),
            'date_care' => '2026-08-21',
            'status' => $status,
            'time_care' => $timeCare,
            'is_confirm_care' => $isConfirmed,
            'is_accept' => $isAccepted,
        ]);
    }

    private function createAssignment(
        CustomerCare $customerCare,
        string $eligibleOn,
        string $status = CustomerCareAssignment::STATUS_ACTIVE,
        ?string $caredAt = null,
        int $shopId = 1
    ): CustomerCareAssignment {
        return CustomerCareAssignment::create([
            'shop_id' => $shopId,
            'customer_care_id' => $customerCare->id,
            'source_type' => CustomerCareAssignment::SOURCE_ORDER,
            'source_id' => $customerCare->id + 1000,
            'assignee_user_id' => 1,
            'assignee_pancake_user_id' => 'PANCAKE-1',
            'assigned_at' => '2026-08-21 23:59:00',
            'reclaim_eligible_on' => $eligibleOn,
            'status' => $status,
            'cared_at' => $caredAt,
        ]);
    }

    private function databaseSnapshot(): array
    {
        return collect([
            'customer_care_assignments',
            'customer_cares',
            'orders',
            'imported_opportunities',
            'activity_logs',
        ])->mapWithKeys(fn (string $table) => [
            $table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ])->all();
    }

    private function createFoundationRecords(): void
    {
        DB::table('shops')->insert([
            [
                'id' => 1,
                'name' => 'Shop One',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Shop Two',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('users')->insert([
            'id' => 1,
            'name' => 'Care Staff',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('customer_cares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_customer_id');
            $table->date('date_care');
            $table->integer('status')->default(0);
            $table->dateTime('time_care')->nullable();
            $table->boolean('is_confirm_care')->default(false);
            $table->boolean('is_accept')->default(false);
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
            $table->index(['status', 'reclaim_eligible_on', 'id']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->integer('status');
        });

        Schema::create('imported_opportunities', function (Blueprint $table) {
            $table->id();
            $table->integer('status');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action')->nullable();
        });
    }
}
