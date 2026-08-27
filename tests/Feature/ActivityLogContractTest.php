<?php

namespace Tests\Feature;

use App\Data\ActivityLogEvent;
use App\Enums\ActivityLogAction;
use App\Enums\ActivityLogSubjectType;
use App\Exceptions\ActivityLogIdempotencyConflictException;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Support\ActivityLogMetadataContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActivityLogContractTest extends TestCase
{
    private ActivityLogService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Ho_Chi_Minh',
            'database.default' => 'activity_log_contract_testing',
            'database.connections.activity_log_contract_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('activity_log_contract_testing');
        DB::setDefaultConnection('activity_log_contract_testing');
        $this->createSchema();
        $this->service = $this->app->make(ActivityLogService::class);
    }

    public function test_mvp_actions_are_stable_and_metadata_contracts_are_documented(): void
    {
        $this->assertSame([
            'customer.entered_system',
            'customer_care.assigned',
            'customer_care.reassigned',
            'customer_care.reclaimed',
            'customer_care.completed',
            'order.created',
        ], array_map(fn (ActivityLogAction $action) => $action->value, ActivityLogAction::cases()));

        $this->assertSame(
            ['customer_id', 'shop_id'],
            ActivityLogMetadataContract::for(ActivityLogAction::CUSTOMER_ENTERED_SYSTEM)['required_local_ids']
        );
        $this->assertSame(
            ['pancake_order_id', 'pancake_customer_id'],
            ActivityLogMetadataContract::for(ActivityLogAction::ORDER_CREATED)['external_ids']
        );
        $this->assertSame(
            ['order_id', 'shop_id'],
            ActivityLogMetadataContract::for(ActivityLogAction::ORDER_CREATED)['required_local_ids']
        );
    }

    public function test_legacy_assignment_and_reclaim_logging_remains_compatible(): void
    {
        $assigned = $this->service->log(
            'customer_care.assigned',
            'user',
            10,
            'Actor',
            20,
            'Assignee',
            7,
            'Shop',
            'order',
            101,
            'PANCAKE-ORDER-101',
            'PANCAKE-CUSTOMER-101',
            null,
            ['assigned_user_id' => 20],
            ['assignment_id' => 501, 'customer_care_id' => 401]
        );

        $reclaimed = $this->service->log(
            'customer_care.reclaimed',
            'system',
            null,
            'Hệ thống',
            20,
            'Assignee',
            7,
            'Shop',
            'customer_care_assignment',
            501,
            'PANCAKE-ORDER-101',
            'PANCAKE-CUSTOMER-101',
            ['status' => 'active'],
            ['status' => 'reclaimed'],
            ['assignment_id' => 501, 'customer_care_id' => 401]
        );

        $this->assertSame('customer_care.assigned', $assigned->fresh()->action);
        $this->assertSame('customer_care.reclaimed', $reclaimed->fresh()->action);
        $this->assertNull($assigned->fresh()->idempotency_key);
        $this->assertNull($reclaimed->fresh()->idempotency_key);
        $this->assertSame(2, DB::table('activity_logs')->count());
    }

    public function test_occurred_at_is_business_time_and_legacy_null_remains_valid(): void
    {
        $log = $this->service->write($this->event([
            'occurredAt' => CarbonImmutable::parse('2026-08-25 09:30:00', config('app.timezone')),
            'idempotencyKey' => 'occurred-at-1',
        ]));

        DB::table('activity_logs')
            ->where('id', $log->id)
            ->update(['created_at' => '2026-08-27 12:00:00']);

        $log->refresh();

        $this->assertSame('2026-08-25 09:30:00', $log->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-27 12:00:00', $log->created_at->format('Y-m-d H:i:s'));
        $this->assertNotSame($log->occurred_at->toDateTimeString(), $log->created_at->toDateTimeString());

        $legacy = $this->service->write($this->event([
            'idempotencyKey' => 'occurred-at-2',
        ]));

        $this->assertNull($legacy->fresh()->occurred_at);
    }

    public function test_same_idempotency_key_returns_the_original_row_without_a_duplicate(): void
    {
        $event = $this->event(['idempotencyKey' => 'retry-1']);

        $first = $this->service->write($event);
        $second = $this->service->write($event);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('activity_logs')->count());
    }

    public function test_different_idempotency_keys_create_distinct_events(): void
    {
        $first = $this->service->write($this->event(['idempotencyKey' => 'distinct-1']));
        $second = $this->service->write($this->event([
            'idempotencyKey' => 'distinct-2',
            'subjectId' => 1002,
        ]));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, DB::table('activity_logs')->count());
    }

    public function test_reusing_a_key_for_a_different_event_does_not_mutate_the_original(): void
    {
        $first = $this->service->write($this->event(['idempotencyKey' => 'conflict-1']));

        $this->expectException(ActivityLogIdempotencyConflictException::class);

        try {
            $this->service->write($this->event([
                'idempotencyKey' => 'conflict-1',
                'subjectId' => 9999,
            ]));
        } finally {
            $this->assertSame(1, DB::table('activity_logs')->count());
            $this->assertSame((string) $first->subject_id, (string) $first->fresh()->subject_id);
        }
    }

    public function test_new_contract_uses_local_subject_ids_and_keeps_external_ids_separate(): void
    {
        $log = $this->service->write(new ActivityLogEvent(
            action: ActivityLogAction::ORDER_CREATED,
            source: 'system',
            subjectType: ActivityLogSubjectType::ORDER,
            subjectId: 42,
            shopId: 7,
            pancakeOrderId: 'PANCAKE-ORDER-42',
            pancakeCustomerId: 'PANCAKE-CUSTOMER-9',
            idempotencyKey: 'order-created-42',
            metadata: [
                'order_id' => 42,
                'shop_id' => 7,
                'customer_id' => 9,
                'pancake_order_id' => 'PANCAKE-ORDER-42',
                'pancake_customer_id' => 'PANCAKE-CUSTOMER-9',
                'ingestion_path' => 'webhook',
                'acquisition_channel' => 'facebook',
                'payload' => ['raw' => 'must not persist'],
                'nested' => ['raw_data' => ['must not persist']],
            ]
        ));

        $this->assertSame('42', $log->subject_id);
        $this->assertSame('PANCAKE-ORDER-42', $log->pancake_order_id);
        $this->assertSame('PANCAKE-CUSTOMER-9', $log->pancake_customer_id);
        $this->assertSame(42, $log->metadata['order_id']);
        $this->assertSame(9, $log->metadata['customer_id']);
        $this->assertSame('facebook', $log->metadata['acquisition_channel']);
        $this->assertArrayNotHasKey('payload', $log->metadata);
        $this->assertArrayNotHasKey('raw_data', $log->metadata['nested']);
    }

    public function test_activity_log_api_response_shape_and_admin_route_are_unchanged(): void
    {
        $admin = new User;
        $admin->setRelation('role', new Role(['slug' => 'admin']));

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/activity-logs')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'logs' => [],
                    'current_page' => 1,
                    'per_page' => 30,
                    'total_items' => 0,
                    'total_pages' => 1,
                ],
            ]);
    }

    private function event(array $overrides = []): ActivityLogEvent
    {
        return new ActivityLogEvent(
            action: $overrides['action'] ?? ActivityLogAction::CUSTOMER_ENTERED_SYSTEM,
            source: $overrides['source'] ?? 'system',
            subjectType: $overrides['subjectType'] ?? ActivityLogSubjectType::CUSTOMER,
            subjectId: $overrides['subjectId'] ?? 1001,
            shopId: $overrides['shopId'] ?? 7,
            shopName: $overrides['shopName'] ?? 'Shop',
            actorUserId: $overrides['actorUserId'] ?? null,
            actorName: $overrides['actorName'] ?? null,
            targetUserId: $overrides['targetUserId'] ?? null,
            targetUserName: $overrides['targetUserName'] ?? null,
            pancakeOrderId: $overrides['pancakeOrderId'] ?? null,
            pancakeCustomerId: $overrides['pancakeCustomerId'] ?? 'PANCAKE-CUSTOMER-1001',
            occurredAt: $overrides['occurredAt'] ?? null,
            idempotencyKey: $overrides['idempotencyKey'] ?? null,
            oldValues: $overrides['oldValues'] ?? null,
            newValues: $overrides['newValues'] ?? null,
            metadata: $overrides['metadata'] ?? [
                'customer_id' => 1001,
                'shop_id' => 7,
                'pancake_customer_id' => 'PANCAKE-CUSTOMER-1001',
                'ingestion_path' => 'test',
            ],
        );
    }

    private function createSchema(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->string('target_user_name')->nullable();
            $table->string('source', 50);
            $table->string('action', 100)->index();
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
