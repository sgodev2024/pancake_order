<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\OrderController;
use App\Models\ActivityLog;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\ImportedOpportunity;
use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\CustomerCareAssignmentService;
use App\Services\CustomerCareReclaimService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CustomerCareReclaimExecutionTest extends TestCase
{
    private CustomerCareReclaimService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Ho_Chi_Minh',
            'database.default' => 'phase_e_testing',
            'database.connections.phase_e_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('phase_e_testing');
        DB::setDefaultConnection('phase_e_testing');
        $this->createSchema();
        $this->createFoundationRecords();
        CarbonImmutable::setTestNow('2026-08-24 12:34:56');

        $this->service = $this->app->make(CustomerCareReclaimService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        CustomerCareAssignment::flushEventListeners();
        ImportedOpportunity::flushEventListeners();
        parent::tearDown();
    }

    public function test_eligible_order_is_atomically_reclaimed_with_server_time_reason_history_and_log(): void
    {
        $order = $this->createOrder();
        $customerCare = $this->createCustomerCare(order: $order);
        $assignment = $this->createAssignment($customerCare, $order);
        $assignedAt = $assignment->assigned_at->format('Y-m-d H:i:s');

        $result = $this->service->executeOne($assignment->id, $this->referenceTime());

        $assignment->refresh();
        $this->assertSame(CustomerCareReclaimService::RESULT_RECLAIMED, $result['result']);
        $this->assertSame(CustomerCareAssignment::STATUS_RECLAIMED, $assignment->status);
        $this->assertSame('2026-08-24 12:34:56', $assignment->reclaimed_at->format('Y-m-d H:i:s'));
        $this->assertSame(CustomerCareReclaimService::RECLAIM_REASON, $assignment->reclaim_reason);
        $this->assertSame($assignedAt, $assignment->assigned_at->format('Y-m-d H:i:s'));
        $this->assertNull($assignment->cared_at);
        $this->assertSame(3, (int) $order->fresh()->status);
        $this->assertTrue(CustomerCare::whereKey($customerCare->id)->exists());
        $this->assertSame('Historical note', $customerCare->fresh()->note);
        $this->assertSame('2026-08-24', $customerCare->fresh()->date_care);

        $log = ActivityLog::query()->sole();
        $this->assertSame('customer_care.reclaimed', $log->action);
        $this->assertSame('system', $log->source);
        $this->assertNull($log->actor_user_id);
        $this->assertSame('Hệ thống', $log->actor_name);
        $this->assertSame(2, $log->target_user_id);
        $this->assertSame('Care Staff', $log->target_user_name);
        $this->assertSame('customer_care_assignment', $log->subject_type);
        $this->assertSame((string) $assignment->id, $log->subject_id);
        $this->assertSame('2026-08-24 12:34:56', $log->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            'customer_care.reclaimed:assignment:'.$assignment->id,
            $log->idempotency_key
        );
        $this->assertSame($assignment->id, $log->metadata['assignment_id']);
        $this->assertSame($customerCare->id, $log->metadata['customer_care_id']);
        $this->assertSame($assignment->assignee_user_id, $log->metadata['assignee_user_id']);
        $this->assertSame($assignment->shop_id, $log->metadata['shop_id']);
        $this->assertSame($assignment->source_type, $log->metadata['source_type']);
        $this->assertSame($assignment->source_id, $log->metadata['source_id']);
        $this->assertSame($assignment->reclaimed_at->toISOString(), $log->metadata['reclaimed_at']);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $log->old_values['status']);
        $this->assertSame(2, $log->old_values['assignee_user_id']);
        $this->assertSame('PANCAKE-2', $log->old_values['assignee_pancake_user_id']);
        $this->assertSame('2026-08-21 23:59:00', $log->old_values['assigned_at']);
        $this->assertSame('2026-08-24', $log->old_values['reclaim_eligible_on']);
        $this->assertSame(CustomerCareAssignment::STATUS_RECLAIMED, $log->new_values['status']);
        $this->assertSame('2026-08-24 12:34:56', $log->new_values['reclaimed_at']);
        $this->assertSame(CustomerCareReclaimService::RECLAIM_REASON, $log->new_values['reclaim_reason']);
        $this->assertSame(CustomerCareReclaimService::RECLAIM_REASON, $log->metadata['reason']);
    }

    public function test_order_returns_to_chance_pool_because_only_active_assignments_block_it(): void
    {
        $order = $this->createOrder();
        $customerCare = $this->createCustomerCare(order: $order);
        $assignment = $this->createAssignment($customerCare, $order);
        $controller = new OrderController;

        $before = $controller->chance(Request::create('/api/orders/chance', 'GET'))->getData(true);
        $this->assertSame(0, $before['data']['total_items']);

        $this->service->executeOne($assignment->id, $this->referenceTime());

        $after = $controller->chance(Request::create('/api/orders/chance', 'GET'))->getData(true);
        $this->assertSame(1, $after['data']['total_items']);
        $this->assertSame($order->id, $after['data']['orders'][0]['id']);
        $this->assertTrue(CustomerCare::whereKey($customerCare->id)->exists());
    }

    public function test_imported_opportunity_returns_from_assigned_status_one_to_pool_status_zero(): void
    {
        $opportunity = $this->createImportedOpportunity();
        $customerCare = $this->createCustomerCare();
        $assignment = $this->createAssignment($customerCare, $opportunity);

        $result = $this->service->executeOne($assignment->id, $this->referenceTime());

        $this->assertSame(CustomerCareReclaimService::RESULT_RECLAIMED, $result['result']);
        $this->assertSame(0, (int) $opportunity->fresh()->status);
        $this->assertSame(CustomerCareAssignment::STATUS_RECLAIMED, $assignment->fresh()->status);
    }

    public function test_locked_recheck_skips_assignment_and_persisted_completed_care(): void
    {
        $orderWithMarker = $this->createOrder();
        $careWithMarker = $this->createCustomerCare(order: $orderWithMarker);
        $markerAssignment = $this->createAssignment(
            $careWithMarker,
            $orderWithMarker,
            caredAt: '2026-08-24 10:00:00'
        );
        $orderWithCare = $this->createOrder();
        $completedCare = $this->createCustomerCare(
            status: 1,
            timeCare: '2026-08-24 10:00:00',
            order: $orderWithCare
        );
        $persistedAssignment = $this->createAssignment($completedCare, $orderWithCare);

        $markerResult = $this->service->executeOne($markerAssignment->id, $this->referenceTime());
        $persistedResult = $this->service->executeOne($persistedAssignment->id, $this->referenceTime());

        $this->assertSame(CustomerCareReclaimService::RESULT_ALREADY_CARED, $markerResult['result']);
        $this->assertSame(CustomerCareReclaimService::RESULT_ALREADY_CARED, $persistedResult['result']);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $markerAssignment->fresh()->status);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $persistedAssignment->fresh()->status);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_confirm_and_accept_flags_do_not_prevent_reclaim(): void
    {
        $confirmedOrder = $this->createOrder();
        $confirmedCare = $this->createCustomerCare(confirmed: true, order: $confirmedOrder);
        $confirmed = $this->createAssignment($confirmedCare, $confirmedOrder);
        $acceptedOrder = $this->createOrder();
        $acceptedCare = $this->createCustomerCare(accepted: true, order: $acceptedOrder);
        $accepted = $this->createAssignment($acceptedCare, $acceptedOrder);

        $this->assertSame(
            CustomerCareReclaimService::RESULT_RECLAIMED,
            $this->service->executeOne($confirmed->id, $this->referenceTime())['result']
        );
        $this->assertSame(
            CustomerCareReclaimService::RESULT_RECLAIMED,
            $this->service->executeOne($accepted->id, $this->referenceTime())['result']
        );
    }

    public function test_not_yet_due_assignment_is_skipped_without_writes(): void
    {
        $order = $this->createOrder();
        $assignment = $this->createAssignment(
            $this->createCustomerCare(order: $order),
            $order,
            eligibleOn: '2026-08-25'
        );

        $result = $this->service->executeOne($assignment->id, $this->referenceTime());

        $this->assertSame(CustomerCareReclaimService::RESULT_NOT_YET_DUE, $result['result']);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->fresh()->status);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_locked_recheck_rejects_soft_deleted_shop_and_missing_assignment(): void
    {
        $order = $this->createOrder();
        $assignment = $this->createAssignment($this->createCustomerCare(order: $order), $order);
        DB::table('shops')->where('id', 1)->update(['deleted_at' => now()]);

        $shopResult = $this->service->executeOne($assignment->id, $this->referenceTime());
        $missingResult = $this->service->executeOne(999999, $this->referenceTime());

        $this->assertSame(CustomerCareReclaimService::RESULT_INVALID_RELATION, $shopResult['result']);
        $this->assertStringContainsString('soft deleted', $shopResult['reason']);
        $this->assertSame(CustomerCareReclaimService::RESULT_INVALID_RELATION, $missingResult['result']);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->fresh()->status);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_repeated_execution_is_idempotent_and_preserves_first_reclaim_values(): void
    {
        $order = $this->createOrder();
        $assignment = $this->createAssignment($this->createCustomerCare(order: $order), $order);
        $this->service->executeOne($assignment->id, $this->referenceTime());
        $firstReclaimedAt = $assignment->fresh()->reclaimed_at->format('Y-m-d H:i:s');

        CarbonImmutable::setTestNow('2026-08-25 09:00:00');
        $second = $this->service->executeOne($assignment->id, $this->referenceTime('2026-08-25'));

        $this->assertSame(CustomerCareReclaimService::RESULT_SKIPPED, $second['result']);
        $this->assertSame($firstReclaimedAt, $assignment->fresh()->reclaimed_at->format('Y-m-d H:i:s'));
        $this->assertSame(CustomerCareReclaimService::RECLAIM_REASON, $assignment->fresh()->reclaim_reason);
        $this->assertSame(1, ActivityLog::query()->count());
        $this->assertSame(3, (int) $order->fresh()->status);
    }

    public function test_activity_log_failure_rolls_back_assignment_and_source(): void
    {
        $opportunity = $this->createImportedOpportunity();
        $assignment = $this->createAssignment($this->createCustomerCare(), $opportunity);
        $activityLog = Mockery::mock(ActivityLogService::class);
        $activityLog->shouldReceive('write')->once()->andThrow(new RuntimeException('log failed'));
        $service = new CustomerCareReclaimService($activityLog);

        try {
            $service->executeOne($assignment->id, $this->referenceTime());
            $this->fail('Expected activity-log failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('log failed', $exception->getMessage());
        }

        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->fresh()->status);
        $this->assertNull($assignment->fresh()->reclaimed_at);
        $this->assertSame(1, (int) $opportunity->fresh()->status);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_source_update_failure_rolls_back_assignment_and_log(): void
    {
        $opportunity = $this->createImportedOpportunity();
        $assignment = $this->createAssignment($this->createCustomerCare(), $opportunity);
        ImportedOpportunity::updating(function () {
            throw new RuntimeException('source failed');
        });

        try {
            $this->service->executeOne($assignment->id, $this->referenceTime());
            $this->fail('Expected source update failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('source failed', $exception->getMessage());
        }

        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->fresh()->status);
        $this->assertNull($assignment->fresh()->reclaimed_at);
        $this->assertSame(1, (int) $opportunity->fresh()->status);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_reclaimed_order_can_be_assigned_again_with_a_second_active_assignment(): void
    {
        $order = $this->createOrder();
        $first = $this->createAssignment($this->createCustomerCare(order: $order), $order);
        $this->service->executeOne($first->id, $this->referenceTime());
        $newCare = $this->createCustomerCare(order: $order);

        $second = (new CustomerCareAssignmentService)->create(
            $newCare,
            (int) $order->shop_id,
            CustomerCareAssignment::SOURCE_ORDER,
            (int) $order->id,
            User::findOrFail(2),
            CarbonImmutable::parse('2026-08-24 13:00:00', config('app.timezone')),
            CarbonImmutable::parse($newCare->date_care, config('app.timezone'))
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(CustomerCareAssignment::STATUS_RECLAIMED, $first->fresh()->status);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $second->status);
        $this->assertSame(2, CustomerCareAssignment::query()->where('source_id', $order->id)->count());
        $this->assertSame([$newCare->id], CustomerCare::query()->actionable()->pluck('id')->all());
    }

    public function test_assignment_history_emits_assigned_then_reassigned_with_local_cca_metadata(): void
    {
        $order = $this->createOrder();
        $assignmentService = new CustomerCareAssignmentService($this->app->make(ActivityLogService::class));
        $assignedAt = CarbonImmutable::parse('2026-08-21 12:00:00', config('app.timezone'));

        $firstCare = $this->createCustomerCare(order: $order);
        $firstCare->update(['date_care' => '2026-08-21']);
        $first = DB::transaction(fn () => $assignmentService->createWithJourneyEvent(
            $firstCare,
            (int) $order->shop_id,
            CustomerCareAssignment::SOURCE_ORDER,
            (int) $order->id,
            User::findOrFail(2),
            $assignedAt,
            CarbonImmutable::parse($firstCare->date_care, config('app.timezone')),
            User::findOrFail(1),
            'Shop One'
        ));

        $this->service->executeOne($first->id, $this->referenceTime());

        $secondCare = $this->createCustomerCare(order: $order);
        $second = DB::transaction(fn () => $assignmentService->createWithJourneyEvent(
            $secondCare,
            (int) $order->shop_id,
            CustomerCareAssignment::SOURCE_ORDER,
            (int) $order->id,
            User::findOrFail(2),
            CarbonImmutable::parse('2026-08-24 13:00:00', config('app.timezone')),
            CarbonImmutable::parse($secondCare->date_care, config('app.timezone')),
            User::findOrFail(1),
            'Shop One'
        ));

        $assignedLogs = ActivityLog::query()->where('action', 'customer_care.assigned')->get();
        $reassignedLogs = ActivityLog::query()->where('action', 'customer_care.reassigned')->get();

        $this->assertCount(1, $assignedLogs);
        $this->assertCount(1, $reassignedLogs);
        $this->assertSame((string) $first->id, $assignedLogs->sole()->subject_id);
        $this->assertSame('customer_care_assignment', $assignedLogs->sole()->subject_type);
        $this->assertSame($assignedAt->format('Y-m-d H:i:s'), $assignedLogs->sole()->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            'customer_care.assigned:assignment:'.$first->id,
            $assignedLogs->sole()->idempotency_key
        );

        $reassigned = $reassignedLogs->sole();
        $this->assertSame((string) $second->id, $reassigned->subject_id);
        $this->assertSame($first->id, $reassigned->metadata['previous_assignment_id']);
        $this->assertSame($second->id, $reassigned->metadata['new_assignment_id']);
        $this->assertSame($first->assignee_user_id, $reassigned->metadata['previous_assignee_user_id']);
        $this->assertSame($second->assignee_user_id, $reassigned->metadata['new_assignee_user_id']);
        $this->assertSame($first->customer_care_id, $reassigned->metadata['previous_customer_care_id']);
        $this->assertSame($second->customer_care_id, $reassigned->metadata['customer_care_id']);
        $this->assertSame($second->shop_id, $reassigned->metadata['shop_id']);
        $this->assertSame($second->source_type, $reassigned->metadata['source_type']);
        $this->assertSame($second->source_id, $reassigned->metadata['source_id']);
        $this->assertSame(1, $reassigned->actor_user_id);
        $this->assertSame(2, $reassigned->target_user_id);
        $this->assertSame('customer_care.reassigned:assignment:'.$second->id, $reassigned->idempotency_key);
    }

    public function test_assignment_event_failure_rolls_back_customer_care_and_assignment_together(): void
    {
        $order = $this->createOrder();
        $customerCare = null;
        $activityLog = Mockery::mock(ActivityLogService::class);
        $activityLog->shouldReceive('write')->once()->andThrow(new RuntimeException('log failed'));
        $assignmentService = new CustomerCareAssignmentService($activityLog);

        try {
            DB::transaction(function () use (&$customerCare, $assignmentService, $order) {
                $customerCare = $this->createCustomerCare(order: $order);
                $assignmentService->createWithJourneyEvent(
                    $customerCare,
                    (int) $order->shop_id,
                    CustomerCareAssignment::SOURCE_ORDER,
                    (int) $order->id,
                    User::findOrFail(2),
                    CarbonImmutable::parse('2026-08-24 12:00:00', config('app.timezone')),
                    CarbonImmutable::parse('2026-08-24', config('app.timezone')),
                    User::findOrFail(1),
                    'Shop One'
                );
            });
            $this->fail('Expected activity-log failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('log failed', $exception->getMessage());
        }

        $this->assertFalse(CustomerCareAssignment::query()->exists());
        $this->assertFalse(CustomerCare::query()->whereKey($customerCare?->id)->exists());
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_source_missing_and_unexpected_import_state_are_reported_without_mutation(): void
    {
        $missingSource = new ImportedOpportunity;
        $missingSource->setRawAttributes(['id' => 999, 'shop_id' => 1, 'status' => 1]);
        $missingSource->exists = true;
        $missing = $this->createAssignment(
            $this->createCustomerCare(),
            $missingSource
        );
        $unexpected = $this->createImportedOpportunity(status: 0);
        $unexpectedAssignment = $this->createAssignment($this->createCustomerCare(), $unexpected);

        $missingResult = $this->service->executeOne($missing->id, $this->referenceTime());
        $unexpectedResult = $this->service->executeOne($unexpectedAssignment->id, $this->referenceTime());

        $this->assertSame(CustomerCareReclaimService::RESULT_INVALID_RELATION, $missingResult['result']);
        $this->assertSame(CustomerCareReclaimService::RESULT_ANOMALY, $unexpectedResult['result']);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $missing->fresh()->status);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $unexpectedAssignment->fresh()->status);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_race_change_after_preview_is_caught_by_locked_recheck(): void
    {
        $order = $this->createOrder();
        $care = $this->createCustomerCare(order: $order);
        $assignment = $this->createAssignment($care, $order);
        $preview = $this->service->evaluate($assignment, $this->referenceTime());
        $this->assertSame(CustomerCareReclaimService::RESULT_ELIGIBLE, $preview['result']);

        $care->update(['status' => 1, 'time_care' => '2026-08-24 12:00:00']);
        $result = $this->service->executeOne($assignment->id, $this->referenceTime());

        $this->assertSame(CustomerCareReclaimService::RESULT_ALREADY_CARED, $result['result']);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->fresh()->status);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_lock_queries_follow_customer_care_then_assignment_then_source_order(): void
    {
        $order = $this->createOrder();
        $assignment = $this->createAssignment($this->createCustomerCare(order: $order), $order);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->service->executeOne($assignment->id, $this->referenceTime());

        $careIndex = collect($queries)->search(fn ($sql) => str_contains($sql, 'from "customer_cares"'));
        $lockedAssignmentIndex = collect($queries)->search(
            fn ($sql, $index) => $index > $careIndex
                && str_contains($sql, 'from "customer_care_assignments"')
        );
        $sourceIndex = collect($queries)->search(
            fn ($sql, $index) => $index > $lockedAssignmentIndex && str_contains($sql, 'from "orders"')
        );

        $this->assertIsInt($careIndex);
        $this->assertIsInt($lockedAssignmentIndex);
        $this->assertIsInt($sourceIndex);
        $this->assertLessThan($lockedAssignmentIndex, $careIndex);
        $this->assertLessThan($sourceIndex, $lockedAssignmentIndex);
    }

    public function test_default_and_explicit_dry_run_commands_make_zero_writes(): void
    {
        $order = $this->createOrder();
        $this->createAssignment($this->createCustomerCare(order: $order), $order);
        $before = $this->databaseSnapshot();

        $this->artisan('customer-care:reclaim-stale')
            ->expectsOutputToContain('DRY RUN ONLY')
            ->assertSuccessful();
        $this->assertSame($before, $this->databaseSnapshot());

        $this->artisan('customer-care:reclaim-stale', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN ONLY')
            ->assertSuccessful();
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_execute_command_reclaims_candidates_and_reports_summary(): void
    {
        $order = $this->createOrder();
        $assignment = $this->createAssignment($this->createCustomerCare(order: $order), $order);

        $this->artisan('customer-care:reclaim-stale', ['--execute' => true])
            ->expectsOutputToContain('EXECUTE MODE')
            ->expectsOutputToContain('reclaimed')
            ->assertSuccessful();

        $this->assertSame(CustomerCareAssignment::STATUS_RECLAIMED, $assignment->fresh()->status);
        $this->assertSame(1, ActivityLog::query()->count());
    }

    public function test_dry_run_and_execute_options_are_rejected_together(): void
    {
        $order = $this->createOrder();
        $assignment = $this->createAssignment($this->createCustomerCare(order: $order), $order);

        $this->artisan('customer-care:reclaim-stale', [
            '--dry-run' => true,
            '--execute' => true,
        ])->expectsOutputToContain('cannot be used together')
            ->assertExitCode(2);

        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $assignment->fresh()->status);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_batch_isolates_one_source_error_and_continues_to_next_assignment(): void
    {
        $firstSource = $this->createImportedOpportunity();
        $first = $this->createAssignment($this->createCustomerCare(), $firstSource);
        $secondSource = $this->createImportedOpportunity();
        $second = $this->createAssignment($this->createCustomerCare(), $secondSource);
        ImportedOpportunity::updating(function (ImportedOpportunity $opportunity) use ($firstSource) {
            if ($opportunity->id === $firstSource->id) {
                throw new RuntimeException('first source failed');
            }
        });

        $execution = $this->service->execute($this->referenceTime());

        $this->assertSame(1, $execution['summary']['errors']);
        $this->assertSame(1, $execution['summary']['reclaimed']);
        $this->assertSame(CustomerCareAssignment::STATUS_ACTIVE, $first->fresh()->status);
        $this->assertSame(CustomerCareAssignment::STATUS_RECLAIMED, $second->fresh()->status);
        $this->assertSame(1, (int) $firstSource->fresh()->status);
        $this->assertSame(0, (int) $secondSource->fresh()->status);
    }

    public function test_activity_log_descriptions_cover_order_and_import_without_null_data(): void
    {
        $controller = new ActivityLogController;
        $method = new \ReflectionMethod($controller, 'formatDescription');
        $orderLog = new ActivityLog([
            'action' => 'customer_care.reclaimed',
            'pancake_order_id' => 'ORDER-701',
        ]);
        $importLog = new ActivityLog([
            'action' => 'customer_care.reclaimed',
            'pancake_order_id' => null,
        ]);

        $this->assertSame(
            'Hệ thống thu hồi CSKH đơn ORDER-701 từ Care Staff do quá 3 ngày chưa chăm sóc',
            $method->invoke($controller, $orderLog, 'Hệ thống', 'Care Staff')
        );
        $this->assertSame(
            'Hệ thống thu hồi CSKH từ Care Staff do quá 3 ngày chưa chăm sóc',
            $method->invoke($controller, $importLog, 'Hệ thống', 'Care Staff')
        );
    }

    private function referenceTime(string $date = '2026-08-24 12:34:56'): CarbonImmutable
    {
        return CarbonImmutable::parse($date, config('app.timezone'));
    }

    private function createOrder(int $status = 3): Order
    {
        return Order::create([
            'shop_id' => 1,
            'pancake_order_id' => 'ORDER-'.uniqid(),
            'pancake_customer_id' => 'CUSTOMER-'.uniqid(),
            'customer_name' => 'Customer',
            'customer_phone' => '0900000000',
            'customer_address' => 'Address',
            'status' => $status,
        ]);
    }

    private function createImportedOpportunity(int $status = 1): ImportedOpportunity
    {
        return ImportedOpportunity::create([
            'shop_id' => 1,
            'name' => 'Imported Customer',
            'phone' => '0900000000',
            'address' => 'Address',
            'status' => $status,
            'imported_by' => 1,
        ]);
    }

    private function createCustomerCare(
        int $status = 0,
        ?string $timeCare = null,
        bool $confirmed = false,
        bool $accepted = false,
        ?Order $order = null
    ): CustomerCare {
        return CustomerCare::create([
            'shop_id' => 1,
            'pancake_customer_id' => $order?->pancake_customer_id ?? 'IMPORT-'.uniqid(),
            'pancake_order_id' => $order?->pancake_order_id,
            'date_care' => '2026-08-24',
            'note' => 'Historical note',
            'status' => $status,
            'time_care' => $timeCare,
            'is_confirm_care' => $confirmed,
            'is_accept' => $accepted,
        ]);
    }

    private function createAssignment(
        CustomerCare $customerCare,
        Order|ImportedOpportunity $source,
        string $eligibleOn = '2026-08-24',
        ?string $caredAt = null
    ): CustomerCareAssignment {
        $sourceType = $source instanceof Order
            ? CustomerCareAssignment::SOURCE_ORDER
            : CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY;

        return CustomerCareAssignment::create([
            'shop_id' => 1,
            'customer_care_id' => $customerCare->id,
            'source_type' => $sourceType,
            'source_id' => $source->id,
            'assignee_user_id' => 2,
            'assignee_pancake_user_id' => 'PANCAKE-2',
            'assigned_at' => '2026-08-21 23:59:00',
            'reclaim_eligible_on' => $eligibleOn,
            'status' => CustomerCareAssignment::STATUS_ACTIVE,
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
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'Admin', 'slug' => 'admin'],
            ['id' => 2, 'name' => 'Care', 'slug' => 'staff-cskh'],
        ]);
        DB::table('shops')->insert(['id' => 1, 'name' => 'Shop One']);
        DB::table('users')->insert([
            [
                'id' => 1,
                'role_id' => 1,
                'name' => 'Admin',
                'email' => 'admin@example.test',
                'password' => 'unused',
                'pancake_user_id' => 'PANCAKE-1',
            ],
            [
                'id' => 2,
                'role_id' => 2,
                'name' => 'Care Staff',
                'email' => 'care@example.test',
                'password' => 'unused',
                'pancake_user_id' => 'PANCAKE-2',
            ],
        ]);

        Auth::shouldUse('web');
        Auth::guard('web')->setUser(User::findOrFail(1));
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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_order_id');
            $table->string('pancake_customer_id');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_address')->nullable();
            $table->integer('status');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('customer_cares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_customer_id');
            $table->string('pancake_order_id')->nullable();
            $table->date('date_care');
            $table->text('note')->nullable();
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
        });
        Schema::create('imported_opportunities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('name');
            $table->string('phone');
            $table->string('address');
            $table->integer('status');
            $table->unsignedBigInteger('imported_by');
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
}
