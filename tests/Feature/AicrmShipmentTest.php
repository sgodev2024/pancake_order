<?php

namespace Tests\Feature;

use App\Models\AicrmShipment;
use App\Models\AicrmShipmentAttempt;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Shop;
use App\Models\User;
use App\Services\AicrmLiveConnection;
use App\Services\AicrmOrderPayload;
use App\Services\AicrmOrderStatus;
use App\Services\AicrmShipmentService;
use App\Services\ShopAccessService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AicrmShipmentTest extends TestCase
{
    private User $actor;

    private Shop $shop;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'aicrm_testing', 'database.connections.aicrm_testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'warehouse.activated_at' => now()->subDay()->toIso8601String(),
            'aicrm.mode' => 'mock', 'aicrm.shipping_provider_id' => '42', 'aicrm.mock_scenario' => 'success']);
        DB::purge('aicrm_testing');
        DB::setDefaultConnection('aicrm_testing');
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_04_24_082539_create_permission_groups_table.php',
            '2026_04_24_082550_create_permissions_table.php',
            '2026_04_24_082551_create_roles_table.php',
            '2026_04_24_082556_create_role_permissions_table.php',
            '2026_04_27_084626_create_shops_table.php',
            '2026_04_27_093216_add_column_to_users_table.php',
            '2026_04_28_072537_create_shop_users_table.php',
            '2026_04_28_111056_create_customers_table.php',
            '2026_04_28_111057_create_orders_table.php',
            '2026_04_29_170341_add_column_to_orders_table.php',
            '2026_05_04_082209_add_slug_to_roles_table.php',
            '2026_05_11_161116_create_products_table.php',
            '2026_08_20_000000_create_activity_logs_table.php',
            '2026_08_27_000000_add_customer_journey_contract_to_activity_logs_table.php',
            '2026_10_21_000001_add_local_pos_support.php',
            '2026_10_21_000002_add_warehouse_completion_and_source_time.php',
            '2026_11_04_000001_create_aicrm_shipments.php',
            '2026_11_04_000002_add_aicrm_remote_order_metadata.php',
            '2026_11_04_000003_add_aicrm_tenant_binding.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->shop = Shop::create(['name' => 'Local test shop', 'pancake_shop_id' => 'test-shop', 'api_key' => 'test-only']);
        $role = Role::create(['slug' => 'aicrm-test', 'name' => 'AICRM test']);
        $this->actor = User::create(['role_id' => $role->id, 'name' => 'Packer', 'email' => 'packer@test.local', 'password' => 'test-only']);
        $this->actor->shops()->attach($this->shop->id);
        foreach (['warehouse-view', 'warehouse-pack'] as $slug) {
            RolePermission::create(['role_id' => $role->id, 'permission_id' => Permission::where('slug', $slug)->value('id')]);
        }
        $this->order = Order::create([
            'shop_id' => $this->shop->id, 'pancake_order_id' => null, 'origin' => 'local_pos',
            'customer_name' => 'Nguyễn Văn Test', 'customer_phone' => '0901234567', 'customer_address' => '123 Đường Test, Hà Nội',
            'cod' => 200000, 'cash' => 999999, 'total_quantity' => 3, 'status' => 0,
            'warehouse_status' => 'packed', 'warehouse_queued_at' => now(), 'source_created_at' => now(),
            'packed_by_user_id' => $this->actor->id, 'packed_at' => now(),
            'pancake_full_data' => ['prepaid' => 50000, 'pos_totals' => ['total_amount' => '250000.00'], 'items' => [
                ['quantity' => 2, 'variation_info' => ['name' => 'Áo']],
                ['quantity' => 1, 'name' => 'Quần'],
            ]],
        ]);
        (require database_path('migrations/2026_11_04_000001_create_staff_account_states_table.php'))->up();
        $this->actingAs($this->actor, 'api');
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_offline_end_to_end_pack_create_inspect_and_replay_without_network_or_money_changes(): void
    {
        $this->order->update(['warehouse_status' => 'waiting_packing', 'packed_at' => null]);
        $this->postJson($this->url('/complete'))->assertOk()->assertJsonPath('data.warehouse_status', 'packed');
        $this->assertDatabaseCount('aicrm_shipments', 0);
        $before = $this->order->fresh()->getRawOriginal();
        $response = $this->postJson($this->url('/viettel-post'))->assertOk()->assertJsonPath('data.status', 'succeeded');
        $number = $response->json('data.tracking_number');
        $this->assertStringStartsWith('MOCK-VTP-', $number);
        $this->postJson($this->url('/viettel-post'))->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.tracking_number', $number);
        $this->assertDatabaseCount('aicrm_shipments', 1);
        $this->assertDatabaseCount('aicrm_shipment_attempts', 1);
        $this->assertSame($before, $this->order->fresh()->getRawOriginal());
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.shipment.tracking_number', $number)
            ->assertJsonPath('data.can_create_shipment', false)->assertJsonCount(1, 'data.shipment.attempts')
            ->assertJsonMissingPath('data.shipment.attempts.0.request_payload');
        $this->getJson('/api/v1/warehouse/orders?status=packed')->assertOk()->assertJsonPath('data.orders.0.shipment.tracking_number', $number);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_exact_aicrm_mapping_uses_pos_total_and_never_cash_or_browser_payload(): void
    {
        $this->postJson($this->url('/viettel-post'), ['shippingProviderId' => 999, 'moneyCollection' => 1, 'shop_id' => 999])->assertOk();
        $this->assertSame([
            'receiveFullName' => 'Nguyễn Văn Test', 'receiverPhone' => '0901234567',
            'receiverAddress' => '123 Đường Test, Hà Nội', 'productName' => 'Áo x2; Quần x1',
            'quantity' => 3, 'totalPrice' => 250000, 'moneyCollection' => 200000, 'shippingProviderId' => 42,
        ], AicrmShipmentAttempt::first()->request_payload);
    }

    public function test_pos_total_is_independent_of_zero_cod_and_preserves_decimals(): void
    {
        $data = $this->order->pancake_full_data;
        $data['pos_totals'] = ['total_amount' => '123456.78'];
        $this->order->update(['origin' => 'local_pos', 'cod' => 0, 'pancake_full_data' => $data]);
        $this->postJson($this->url('/viettel-post'))->assertOk();
        $payload = AicrmShipmentAttempt::first()->request_payload;
        $this->assertSame(123456.78, $payload['totalPrice']);
        $this->assertEquals(0, $payload['moneyCollection']);
    }

    #[DataProvider('invalidOrderStates')]
    public function test_ineligible_orders_are_rejected_before_sending(array $override): void
    {
        $this->order->forceFill($override)->save();
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        $this->assertDatabaseCount('aicrm_shipments', 0);
        Http::assertNothingSent();
    }

    public static function invalidOrderStates(): array
    {
        return [
            [['warehouse_status' => 'waiting_packing']], [['packed_at' => null]],
            [['order_number_vtp' => 'EXISTING']], [['status' => 6]], [['status' => 99]],
            [['warehouse_queued_at' => null]], [['created_at' => '2000-01-01']],
        ];
    }

    public function test_existing_partner_tracking_also_blocks_creation(): void
    {
        $this->order->update(['pancake_full_data' => array_merge($this->order->pancake_full_data, ['partner' => ['order_number_vtp' => 'EXISTING']])]);
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
    }

    public function test_shop_permissions_view_only_and_auth_are_checked_for_writes_and_history(): void
    {
        $this->actor->shops()->detach();
        $this->postJson($this->url('/viettel-post'))->assertForbidden();
        $this->getJson($this->url())->assertForbidden();
        $this->actor->shops()->attach($this->shop->id);
        RolePermission::where('role_id', $this->actor->role_id)->where('permission_id', Permission::where('slug', 'warehouse-pack')->value('id'))->delete();
        // New app-scoped access resolution after membership change.
        $this->app->forgetInstance(ShopAccessService::class);
        $this->actingAs($this->actor->fresh(), 'api')->postJson($this->url('/viettel-post'))->assertForbidden();
        $this->assertDatabaseCount('aicrm_shipments', 0);
    }

    #[DataProvider('invalidFields')]
    public function test_missing_or_invalid_data_is_validated_before_reservation(array $override): void
    {
        $this->order->update($override);
        $this->postJson($this->url('/viettel-post'))->assertUnprocessable();
        $this->assertDatabaseCount('aicrm_shipments', 0);
        Http::assertNothingSent();
    }

    public static function invalidFields(): array
    {
        return [
            [['customer_name' => '']], [['customer_phone' => 'invalid']], [['customer_address' => null]],
            [['cod' => -1]], [['pancake_full_data' => ['items' => []]]],
            [['pancake_full_data' => ['items' => [['name' => 'Test', 'quantity' => 0]]]]],
        ];
    }

    public function test_shipping_provider_must_be_configured(): void
    {
        config(['aicrm.shipping_provider_id' => null]);
        $this->postJson($this->url('/viettel-post'))->assertUnprocessable();
        $this->assertDatabaseCount('aicrm_shipments', 0);
    }

    public function test_documented_rejection_is_logged_and_explicit_retry_preserves_history(): void
    {
        config(['aicrm.mock_scenario' => 'validation']);
        $this->postJson($this->url('/viettel-post'))->assertStatus(502)->assertJsonPath('data.status', 'failed');
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.can_create_shipment', true);
        config(['aicrm.mock_scenario' => 'success']);
        $this->postJson($this->url('/viettel-post'))->assertOk()->assertJsonPath('data.status', 'succeeded')
            ->assertJsonCount(2, 'data.attempts')->assertJsonPath('data.attempts.1.http_status', 400);
        $this->assertDatabaseCount('aicrm_shipments', 1);
    }

    #[DataProvider('ambiguousScenarios')]
    public function test_ambiguous_outcomes_block_retries(string $scenario): void
    {
        config(['aicrm.mock_scenario' => $scenario]);
        $this->postJson($this->url('/viettel-post'))->assertStatus(502)->assertJsonPath('data.status', 'unknown');
        config(['aicrm.mock_scenario' => 'success']);
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        $this->assertDatabaseCount('aicrm_shipment_attempts', 1);
    }

    public static function ambiguousScenarios(): array
    {
        return [['timeout'], ['server_error'], ['malformed']];
    }

    public function test_crashed_processing_reservation_is_never_automatically_released(): void
    {
        AicrmShipment::create(['order_id' => $this->order->id, 'shop_id' => $this->shop->id,
            'mode' => 'mock', 'status' => 'processing', 'reference' => (string) Str::uuid(), 'submitted_at' => now()->subDays(2)]);
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        $this->assertDatabaseCount('aicrm_shipment_attempts', 0);
    }

    public function test_sandbox_http_exact_endpoint_api_key_tenant_and_reentrant_duplicate_block(): void
    {
        $this->sandbox();

        Http::fake(function ($request) {
            $this->assertSame('http://127.0.0.1:8011/api/v1/orders', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasHeader('X-API-Key', 'test-secret'));
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));
            $this->assertFalse($request->hasHeader('X-Tenant-ID'));
            try {
                app(AicrmShipmentService::class)->create($this->actor, $this->order->id);
                $this->fail('Concurrent creation should be blocked');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }

            return Http::response(['status' => 200, 'error' => false, 'data' => ['ORDER_NUMBER' => 'SANDBOX-123']]);
        });
        $this->postJson($this->url('/viettel-post'))->assertOk()->assertJsonPath('data.tracking_number', 'SANDBOX-123');
        $this->assertSame('SANDBOX-123', $this->order->fresh()->order_number_vtp);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('aicrm_shipment_attempts', 1);
    }

    public function test_upstream_secrets_and_raw_error_body_are_not_exposed_or_persisted(): void
    {
        $this->sandbox();
        Http::fake(['*' => Http::response(['error' => 'test-secret', 'details' => 'Private recipient / access_token=test-secret'], 401)]);
        $response = $this->postJson($this->url('/viettel-post'))->assertStatus(502)->assertJsonPath('data.status', 'failed');
        $this->assertStringNotContainsString('test-secret', $response->getContent());
        $this->assertStringNotContainsString('test-secret', AicrmShipmentAttempt::first()->toJson());
        Http::assertSentCount(1);
    }

    public function test_production_unapproved_host_disabled_sandbox_missing_auth_and_tenant_fail_closed(): void
    {
        $this->sandbox();
        foreach ([
            ['aicrm.mode' => 'production'],
            ['aicrm.base_url' => 'https://production.example.test'],
            ['aicrm.sandbox_enabled' => false],
            ['aicrm.api_key' => ''],
            ['aicrm.tenant_header' => 'X-Tenant-ID', 'aicrm.shop_tenants' => []],
        ] as $configuration) {
            $this->sandbox();
            config($configuration);
            $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        }
        $this->assertDatabaseCount('aicrm_shipments', 0);
        Http::assertNothingSent();
    }

    public function test_redirects_and_200_error_envelopes_never_count_as_success_or_retry(): void
    {
        $this->sandbox();
        Http::fake(['*' => Http::response(['status' => 200, 'error' => true, 'data' => ['ORDER_NUMBER' => 'INVALID']], 200)]);
        $this->postJson($this->url('/viettel-post'))->assertStatus(502)->assertJsonPath('data.status', 'unknown');
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_switching_environment_cannot_resend_an_existing_mock_failure(): void
    {
        config(['aicrm.mock_scenario' => 'validation']);
        $this->postJson($this->url('/viettel-post'))->assertStatus(502);
        $this->sandbox();
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_local_http_mock_mode_never_writes_mock_tracking_to_legacy_carrier_fields(): void
    {
        config(['aicrm.mode' => 'mock_http', 'aicrm.base_url' => 'http://127.0.0.1:8011',
            'aicrm.api_key' => 'test-secret', 'aicrm.tenant_header' => null,
            'aicrm.credential_tenant' => '11111111-1111-4111-8111-111111111111',
            'aicrm.shop_tenants' => [$this->shop->id => '11111111-1111-4111-8111-111111111111'], 'aicrm.tenant_credentials' => []]);
        $url = getenv('AICRM_LOCAL_HTTP_TEST_URL');
        if ($url) {
            // Opt-in actual transport test. Only a loopback server is permitted.
            $this->assertMatchesRegularExpression('~^http://127\.0\.0\.1:[0-9]+$~D', $url);
            config(['aicrm.base_url' => $url]);
            Http::allowStrayRequests([$url.'/api/v1/orders']);
        } else {
            Http::fake(['*' => Http::response(['status' => 200, 'error' => false, 'data' => ['ORDER_NUMBER' => 'MOCK-VTP-HTTP']])]);
        }
        $result = $this->postJson($this->url('/viettel-post'))->assertOk()->assertJsonPath('data.mode', 'mock_http');
        $this->assertStringStartsWith('MOCK-VTP-', $result->json('data.tracking_number'));
        $this->postJson($this->url('/viettel-post'))->assertOk()->assertJsonPath('replayed', true);
        $this->assertNull($this->order->fresh()->order_number_vtp);
        $this->assertDatabaseCount('aicrm_shipment_attempts', 1);
    }

    public function test_redirect_is_ambiguous_and_not_followed(): void
    {
        $this->sandbox();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://production.example.test'])]);
        $this->postJson($this->url('/viettel-post'))->assertStatus(502)->assertJsonPath('data.status', 'unknown');
        Http::assertSentCount(1);
        $this->assertNull($this->order->fresh()->order_number_vtp);
    }

    public function test_production_application_environment_cannot_send_even_with_sandbox_configuration(): void
    {
        $this->sandbox();
        $this->app->detectEnvironment(fn () => 'production');
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_mock_http_cannot_use_a_non_loopback_host(): void
    {
        config(['aicrm.mode' => 'mock_http', 'aicrm.base_url' => 'https://remote.example.test', 'aicrm.api_key' => 'test']);
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_unauthenticated_creation_is_rejected(): void
    {
        auth('api')->forgetUser();
        $this->postJson($this->url('/viettel-post'))->assertUnauthorized();
        $this->assertDatabaseCount('aicrm_shipments', 0);
    }

    public function test_live_is_off_by_default_and_never_falls_back_to_mock(): void
    {
        config(['aicrm.mode' => 'live']);
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        $this->assertDatabaseCount('aicrm_shipments', 0);
        Http::assertNothingSent();
    }

    public function test_live_read_only_connection_does_not_require_enabling_creation_or_changing_app_environment(): void
    {
        $this->live();
        $this->app->detectEnvironment(fn () => 'production');
        Http::fake(['*' => Http::response(['content' => [], 'totalElements' => 0])]);
        $result = app(AicrmLiveConnection::class)->read('orders', $this->shop->id);
        $this->assertSame(200, $result->status());
        $this->assertSame('production', $this->app->environment());
        $this->assertFalse(config('aicrm.live.creation_enabled'));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://aicrm-live.example.test/confirmed/orders?page=1&size=1'
            && $request->hasHeader('X-API-Key', 'live-test-secret')
            && ! $request->hasHeader('Authorization')
            && ! $request->hasHeader('X-Tenant-ID') && ! $request->hasHeader('X-Test-Tenant')
            && ! $request->hasHeader('Cookie'));
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('aicrm_shipments', 0);
    }

    public function test_live_creation_checks_exact_approval_reconciliation_date_and_provider_before_reserving(): void
    {
        foreach ([
            ['aicrm.live.creation_enabled' => false],
            ['aicrm.live.approved_order_id' => 999999],
            ['aicrm.live.reconciliation_confirmed' => false],
            ['aicrm.live.activated_at' => null],
            ['aicrm.live.activated_at' => now()->addHour()->toIso8601String()],
            ['aicrm.live.contract_confirmed' => false],
        ] as $override) {
            $this->live(true);
            config($override);
            $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        }
        $this->live(true);
        config(['aicrm.live.shipping_provider_id' => null]);
        $this->postJson($this->url('/viettel-post'))->assertUnprocessable();
        $this->assertDatabaseCount('aicrm_shipments', 0);
        Http::assertNothingSent();
    }

    public function test_live_rejects_historical_order_even_if_its_id_was_approved(): void
    {
        $this->live(true);
        $this->order->forceFill(['created_at' => now()->subHours(2)])->save();
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_live_https_confirmed_host_header_tenant_and_endpoint_gates(): void
    {
        foreach ([
            ['aicrm.live.base_url' => 'http://aicrm-live.example.test'],
            ['aicrm.live.base_url' => 'https://unconfirmed.example.test'],
            ['aicrm.live.base_url' => 'https://aicrm-live.example.test:8443'],
            ['aicrm.live.base_url' => 'https://username:password@aicrm-live.example.test'],
            ['aicrm.live.base_url' => 'https://aicrm-live.example.test?token=secret'],
            ['aicrm.live.hosts' => []],
            ['aicrm.live.tenant_header' => 'Cookie'],
            ['aicrm.live.api_key' => ''],
            ['aicrm.live.tenant_mode' => null],
            ['aicrm.live.shop_tenants' => []],
            ['aicrm.live.tenant_header' => 'X-API-Key'],
            ['aicrm.live.orders_path' => '//unconfirmed.example.test/orders'],
            ['aicrm.live.orders_path' => '/%2e%2e/orders'],
            ['aicrm.live.orders_path' => null],
        ] as $override) {
            $this->live(true);
            config($override);
            $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        }
        $this->assertDatabaseCount('aicrm_shipments', 0);
        Http::assertNothingSent();
    }

    public function test_live_credential_scoped_to_one_tenant_cannot_send_for_a_different_shop_tenant(): void
    {
        $this->live(true);
        config(['aicrm.live.tenant_mode' => 'api_key', 'aicrm.live.tenant_header' => null,
            'aicrm.live.credential_tenant' => 'different-tenant']);
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        config(['aicrm.live.credential_tenant' => '11111111-1111-4111-8111-111111111111']);
        Http::fake(['*' => Http::response(['status' => 200, 'error' => false, 'data' => ['ORDER_NUMBER' => 'FAKE-TEST-LIVE']])]);
        $this->postJson($this->url('/viettel-post'))->assertOk();
        Http::assertSent(fn ($request) => ! $request->hasHeader('X-Test-Tenant'));
    }

    public function test_post_saves_tracking_without_inventing_uuid_or_shipping_status_and_blocks_duplicates(): void
    {
        $this->live(true);
        $this->app->detectEnvironment(fn () => 'production');
        $before = $this->order->fresh()->only(['cod', 'cash', 'status', 'total_quantity', 'warehouse_status']);
        Http::fake(function ($request) {
            $this->assertSame('https://aicrm-live.example.test/confirmed/orders', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame(73, $request['shippingProviderId']);
            $this->assertTrue($request->hasHeader('X-API-Key', 'live-test-secret'));
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));
            try {
                app(AicrmShipmentService::class)->create($this->actor, $this->order->id);
                $this->fail('Second POST must be blocked');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }

            return Http::response(['status' => 200, 'error' => false, 'data' => ['ORDER_NUMBER' => 'FAKE-TEST-LIVE', 'confirmedId' => 'remote-test-123']]);
        });
        $this->postJson($this->url('/viettel-post'))->assertOk()->assertJsonPath('data.mode', 'live')
            ->assertJsonPath('data.remote_order_id', null)->assertJsonPath('data.remote_status', null);
        $this->assertSame('FAKE-TEST-LIVE', $this->order->fresh()->order_number_vtp);
        $this->assertSame($before, $this->order->fresh()->only(array_keys($before)));
        $this->assertNull(AicrmShipmentAttempt::first()->remote_order_id);
        $this->assertNull(AicrmShipmentAttempt::first()->remote_status);
        $this->postJson($this->url('/viettel-post'))->assertOk()->assertJsonPath('replayed', true);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('aicrm_shipment_attempts', 1);
    }

    public function test_unexpected_post_data_never_assigns_uuid_or_allows_retry(): void
    {
        $this->live(true);
        Http::fake(['*' => Http::response(['status' => 400, 'error' => true, 'data' => ['confirmedId' => 'remote-test-created']], 400)]);
        $this->postJson($this->url('/viettel-post'))->assertStatus(502)->assertJsonPath('data.status', 'unknown')
            ->assertJsonPath('data.remote_order_id', null);
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_live_does_not_accept_a_cached_mock_result_as_live_success(): void
    {
        $this->postJson($this->url('/viettel-post'))->assertOk();
        $this->live(true);
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        Http::assertNothingSent();
        $this->assertDatabaseCount('aicrm_shipment_attempts', 1);
    }

    public function test_live_read_only_command_reports_status_without_exposing_response_body_or_secrets(): void
    {
        $this->live();
        Http::fake(['*' => Http::response(['error' => 'live-test-secret', 'private' => 'recipient-private-data'], 403)]);
        $this->artisan('aicrm:live-check', ['--shop-id' => $this->shop->id, '--actor-id' => $this->actor->id, '--resource' => 'providers'])
            ->expectsOutputToContain('"http_status":403')->doesntExpectOutputToContain('live-test-secret')
            ->doesntExpectOutputToContain('recipient-private-data')->assertFailed();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        $this->assertDatabaseCount('aicrm_shipments', 0);
    }

    public function test_live_command_enforces_actor_shop_and_view_permission_and_cannot_run_post_resource(): void
    {
        $this->live();
        $this->artisan('aicrm:live-check', ['--shop-id' => $this->shop->id, '--actor-id' => $this->actor->id, '--resource' => 'create'])
            ->assertFailed();
        $this->actor->shops()->detach();
        $this->artisan('aicrm:live-check', ['--shop-id' => $this->shop->id, '--actor-id' => $this->actor->id])->assertFailed();
        $this->actor->shops()->attach($this->shop->id);
        RolePermission::where('role_id', $this->actor->role_id)->delete();
        $this->artisan('aicrm:live-check', ['--shop-id' => $this->shop->id, '--actor-id' => $this->actor->id])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_live_read_detail_uses_only_confirmed_path_and_does_not_follow_redirects(): void
    {
        $this->live();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://unconfirmed.example.test'])]);
        $response = app(AicrmLiveConnection::class)->read('detail', $this->shop->id, '22222222-2222-4222-8222-222222222222');
        $this->assertSame(302, $response->status());
        Http::assertSent(fn ($request) => $request->url() === 'https://aicrm-live.example.test/confirmed/orders/22222222-2222-4222-8222-222222222222');
        Http::assertSentCount(1);
    }

    public function test_live_connection_timeout_is_recorded_once_and_never_retried(): void
    {
        $this->live(true);
        Http::fake(['*' => Http::failedConnection('fake timeout')]);
        $this->postJson($this->url('/viettel-post'))->assertStatus(502)->assertJsonPath('data.status', 'unknown');
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        $this->assertDatabaseCount('aicrm_shipment_attempts', 1);
        $this->assertNull($this->order->fresh()->order_number_vtp);
    }

    public function test_live_check_does_not_mistake_html_200_for_a_successful_api_connection(): void
    {
        $this->live();
        Http::fake(['*' => Http::response('<html>Login page</html>', 200, ['Content-Type' => 'text/html'])]);
        $this->artisan('aicrm:live-check', ['--shop-id' => $this->shop->id, '--actor-id' => $this->actor->id])
            ->expectsOutputToContain('"json_response":false')->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_api_key_json_contract_preserves_zero_cod_and_backend_values(): void
    {
        $this->live(true);
        $data = $this->order->pancake_full_data;
        $data['prepaid'] = 250000;
        $this->order->update(['cod' => 0, 'pancake_full_data' => $data]);
        Http::fake(function ($request) {
            $this->assertTrue($request->hasHeader('X-API-Key', 'live-test-secret'));
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));
            $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([
                'receiveFullName', 'receiverPhone', 'receiverAddress', 'productName',
                'quantity', 'totalPrice', 'moneyCollection', 'shippingProviderId',
            ], array_keys($payload));
            $this->assertIsInt($payload['shippingProviderId']);
            $this->assertSame(73, $payload['shippingProviderId']);
            $this->assertIsInt($payload['quantity']);
            $this->assertSame(3, $payload['quantity']);
            foreach (['productName', 'receiveFullName', 'receiverPhone', 'receiverAddress'] as $key) {
                $this->assertIsString($payload[$key]);
            }
            $this->assertTrue(is_int($payload['totalPrice']) || is_float($payload['totalPrice']));
            $this->assertEquals(250000, $payload['totalPrice']);
            $this->assertSame(0, $payload['moneyCollection']);

            return Http::response(['status' => 200, 'error' => false, 'data' => ['ORDER_NUMBER' => 'FAKE-API-KEY-ORDER']]);
        });
        $response = $this->postJson($this->url('/viettel-post'), [
            'totalPrice' => 1, 'moneyCollection' => 999, 'shippingProviderId' => 999, 'quantity' => 999,
        ])->assertOk();
        $this->assertStringNotContainsString('live-test-secret', $response->getContent());
        $this->assertStringNotContainsString('live-test-secret', AicrmShipmentAttempt::first()->toJson());
        Http::assertSentCount(1);
    }

    public function test_provider_discovery_reports_only_enabled_viettel_id_without_sender_or_credentials(): void
    {
        $this->live();
        config(['aicrm.live.providers_path' => '/api/v1/shipping-providers']);
        Http::fake(['*' => Http::response([
            ['id' => 12, 'code' => 'GHN', 'isEnable' => true],
            ['id' => 13, 'code' => 'VIETTEL_POST', 'isEnable' => false],
            ['id' => 87, 'code' => 'VIETTEL_POST', 'isEnable' => true,
                'token' => 'private-provider-token', 'senderPhone' => 'private-phone', 'senderAddress' => 'private-address'],
        ])]);
        $this->artisan('aicrm:live-check', ['--shop-id' => $this->shop->id, '--actor-id' => $this->actor->id])
            ->expectsOutputToContain('"http_json_check_passed":true,"viettel_post_provider_id":87,"tenant_scope_confirmed_by_response":false')
            ->doesntExpectOutputToContain('private-provider-token')->doesntExpectOutputToContain('private-phone')
            ->doesntExpectOutputToContain('private-address')->doesntExpectOutputToContain('live-test-secret')->assertSuccessful();
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://aicrm-live.example.test/api/v1/shipping-providers'
            && $request->hasHeader('X-API-Key', 'live-test-secret')
            && ! $request->hasHeader('Authorization') && ! $request->hasHeader('Cookie'));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('aicrm_shipments', 0);
    }

    #[DataProvider('invalidProviders')]
    public function test_provider_discovery_rejects_invalid_missing_disabled_or_ambiguous_providers(mixed $body, int $status): void
    {
        $this->live();
        Http::fake(['*' => Http::response($body, $status)]);
        $this->artisan('aicrm:live-check', ['--shop-id' => $this->shop->id, '--actor-id' => $this->actor->id])
            ->expectsOutputToContain('"http_json_check_passed":false,"viettel_post_provider_id":null')->assertFailed();
        Http::assertSentCount(1);
    }

    public static function invalidProviders(): array
    {
        $valid = ['id' => 87, 'code' => 'VIETTEL_POST', 'isEnable' => true];

        return [
            [[], 200], [[['id' => 87, 'code' => 'GHN', 'isEnable' => true]], 200],
            [[array_replace($valid, ['isEnable' => false])], 200],
            [[array_replace($valid, ['isEnable' => 'true'])], 200],
            [[array_replace($valid, ['id' => '87'])], 200],
            [[array_replace($valid, ['id' => 0])], 200],
            [[$valid, array_replace($valid, ['id' => 88])], 200],
            [['data' => [$valid]], 200], ['<html>Login</html>', 200],
            [[$valid], 403], [[$valid], 500],
        ];
    }

    public function test_cloudflare_1010_is_reported_as_network_denial_without_retrying(): void
    {
        $this->live();
        Http::fake(['*' => Http::response(['error_code' => 1010, 'error_name' => 'browser_signature_banned'], 403)]);
        $this->artisan('aicrm:live-check', ['--shop-id' => $this->shop->id, '--actor-id' => $this->actor->id])
            ->expectsOutputToContain('Cloudflare HTTP 403 / 1010 chặn truy cập mạng')
            ->expectsOutputToContain('"http_status":403')->assertFailed();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('aicrm_shipments', 0);
    }

    public function test_live_api_key_and_tenant_headers_cannot_fall_back_to_bearer_or_override_credentials(): void
    {
        foreach ([
            ['aicrm.live.api_key' => null, 'aicrm.live.auth_token' => 'old-bearer-secret'],
            ['aicrm.live.api_key' => "key\r\nInjected: value"],
            ['aicrm.live.tenant_header' => 'x-api-key'],
            ['aicrm.live.tenant_header' => 'Authorization'],
        ] as $override) {
            $this->live();
            config($override);
            try {
                app(AicrmLiveConnection::class)->read('providers', $this->shop->id);
                $this->fail('Invalid API key configuration must be rejected');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        Http::assertNothingSent();
    }

    public function test_cloudflare_rejection_during_fake_creation_is_not_misreported_as_bad_api_key(): void
    {
        $this->live(true);
        Http::fake(['*' => Http::response(['error_code' => 1010], 403)]);
        $this->postJson($this->url('/viettel-post'))->assertStatus(502)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.attempts.0.error_code', 'aicrm_network_denied')
            ->assertJsonPath('data.last_error', 'Cloudflare HTTP 403 / 1010 chặn truy cập mạng. Cần AICRM xử lý quyền truy cập B2B.');
        Http::assertSentCount(1);
        $this->assertNull($this->order->fresh()->order_number_vtp);
    }

    #[DataProvider('invalidSuccessEnvelopes')]
    public function test_unconfirmed_success_envelopes_never_persist_a_tracking_number(array $body): void
    {
        $this->live(true);
        Http::fake(['*' => Http::response($body)]);
        $this->postJson($this->url('/viettel-post'))->assertStatus(502)->assertJsonPath('data.status', 'unknown');
        $this->assertNull(AicrmShipment::first()->tracking_number);
        $this->assertNull($this->order->fresh()->order_number_vtp);
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        Http::assertSentCount(1);
    }

    public static function invalidSuccessEnvelopes(): array
    {
        $valid = ['status' => 200, 'error' => false, 'data' => ['ORDER_NUMBER' => 'FAKE-API-KEY-ORDER']];

        return [
            [array_replace($valid, ['status' => '200'])], [array_replace($valid, ['status' => 500])],
            [array_replace($valid, ['error' => true])], [array_replace($valid, ['error' => 'false'])],
            [array_replace($valid, ['data' => []])], [array_replace($valid, ['data' => ['ORDER_NUMBER' => '']])],
        ];
    }

    #[DataProvider('productNameLengths')]
    public function test_product_name_limit_counts_vietnamese_characters_and_complete_item_summary(int $length, bool $valid): void
    {
        $data = ['prepaid' => 0, 'pos_totals' => ['total_amount' => '250000.00'], 'items' => [['name' => str_repeat('Á', $length), 'quantity' => 1]]];
        $this->order->update(['pancake_full_data' => $data]);
        $before = $this->order->fresh()->getRawOriginal();
        $response = $this->postJson($this->url('/viettel-post'));
        if ($valid) {
            $response->assertOk();
            $this->assertSame(255, mb_strlen(AicrmShipmentAttempt::first()->request_payload['productName']));
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('productName');
            $this->assertStringContainsString('255 ký tự', $response->json('errors.productName.0'));
            $this->assertDatabaseCount('aicrm_shipments', 0);
        }
        $this->assertSame($before, $this->order->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public static function productNameLengths(): array
    {
        return [[252, true], [253, false], [1000, false]];
    }

    public function test_product_name_limit_applies_to_combined_lines_including_separators_and_quantities(): void
    {
        $this->order->update(['pancake_full_data' => ['items' => [
            ['name' => str_repeat('A', 125), 'quantity' => 1], ['name' => str_repeat('B', 125), 'quantity' => 1],
        ]]]);
        $this->postJson($this->url('/viettel-post'))->assertUnprocessable()->assertJsonValidationErrors('productName');
        $this->assertDatabaseCount('aicrm_shipment_attempts', 0);
    }

    #[DataProvider('codAmounts')]
    public function test_cod_requires_non_negative_whole_vnd_without_rounding_or_losing_zero(mixed $amount, bool $valid, ?int $expected): void
    {
        $this->order->update(['cod' => $amount]);
        $response = $this->postJson($this->url('/viettel-post'));
        if ($valid) {
            $response->assertOk();
            $this->assertSame($expected, AicrmShipmentAttempt::first()->request_payload['moneyCollection']);
        } else {
            $response->assertUnprocessable();
            $this->assertDatabaseCount('aicrm_shipments', 0);
        }
        Http::assertNothingSent();
    }

    public static function codAmounts(): array
    {
        return [[0, true, 0], ['0.00', true, 0], ['120000.00', true, 120000], [123.45, false, null],
            [0.01, false, null], [-1, false, null], ['abc', false, null], [1000000000000, false, null]];
    }

    public function test_missing_cod_is_rejected_by_payload_validation(): void
    {
        // The DB already forbids null COD; also guard unsaved/imported model data.
        $this->order->cod = null;
        $this->expectException(ValidationException::class);
        app(AicrmOrderPayload::class)->make($this->order);
    }

    public function test_multiple_shops_select_their_tenant_api_key_and_provider_for_both_get_and_post(): void
    {
        $this->live(true);
        $tenantA = config('aicrm.live.credential_tenant');
        $tenantB = '33333333-3333-4333-8333-333333333333';
        $otherShop = Shop::create(['name' => 'Other tenant shop', 'pancake_shop_id' => 'other-shop', 'api_key' => 'pancake-key']);
        $this->actor->shops()->attach($otherShop->id);
        $otherOrder = $this->order->replicate();
        $otherOrder->fill(['shop_id' => $otherShop->id, 'pancake_order_id' => 'other-order'])->save();
        config(['aicrm.live.shop_tenants' => [$this->shop->id => $tenantA, $otherShop->id => $tenantB],
            'aicrm.live.tenant_credentials' => [
                $tenantA => ['api_key' => 'tenant-a-secret', 'shipping_provider_id' => 73],
                $tenantB => ['api_key' => 'tenant-b-secret', 'shipping_provider_id' => 91],
            ]]);
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $key = $request->header('X-API-Key')[0];
            $calls[] = [$request->method(), $key];
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertFalse($request->hasHeader('X-Tenant-ID'));
            if ($request->method() === 'GET') {
                return Http::response([['id' => $key === 'tenant-a-secret' ? 73 : 91, 'code' => 'VIETTEL_POST', 'isEnable' => true, 'token' => 'carrier-secret']]);
            }
            $this->assertSame($key === 'tenant-a-secret' ? 73 : 91, $request['shippingProviderId']);

            return Http::response(['status' => 200, 'error' => false, 'data' => ['ORDER_NUMBER' => 'VTP-'.$request['shippingProviderId']]]);
        });
        foreach ([$this->order, $otherOrder] as $order) {
            config(['aicrm.live.approved_order_id' => $order->id]);
            $this->artisan('aicrm:live-check', ['--shop-id' => $order->shop_id, '--actor-id' => $this->actor->id])
                ->doesntExpectOutputToContain('carrier-secret')->assertSuccessful();
            $this->postJson('/api/v1/warehouse/orders/'.$order->id.'/viettel-post')->assertOk();
        }
        $this->assertSame([['GET', 'tenant-a-secret'], ['POST', 'tenant-a-secret'], ['GET', 'tenant-b-secret'], ['POST', 'tenant-b-secret']], $calls);
        $this->assertSame([$tenantA, $tenantB], AicrmShipment::orderBy('id')->pluck('tenant_id')->all());
        $stored = json_encode(DB::table('aicrm_shipments')->get()).json_encode(DB::table('aicrm_shipment_attempts')->get());
        foreach (['tenant-a-secret', 'tenant-b-secret', 'carrier-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        Http::assertSentCount(4);
    }

    public function test_missing_tenant_credential_and_key_reuse_fail_closed_without_global_fallback(): void
    {
        $tenantB = '33333333-3333-4333-8333-333333333333';
        foreach (['missing', 'duplicate', 'header'] as $scenario) {
            $this->live(true);
            $tenantA = config('aicrm.live.credential_tenant');
            config(['aicrm.live.tenant_credentials' => [
                $tenantB => ['api_key' => 'other-key', 'shipping_provider_id' => 91],
            ]]);
            if ($scenario === 'duplicate') {
                config(['aicrm.live.tenant_credentials.'.$tenantA => ['api_key' => 'other-key', 'shipping_provider_id' => 73]]);
            } elseif ($scenario === 'header') {
                config(['aicrm.live.tenant_mode' => 'header', 'aicrm.live.tenant_header' => 'X-Tenant-ID']);
            }
            $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        }
        $this->assertDatabaseCount('aicrm_shipments', 0);
        Http::assertNothingSent();
    }

    public function test_same_tenant_can_share_a_key_but_a_changed_tenant_cannot_resend_or_reconcile(): void
    {
        $this->live(true);
        $tenantA = config('aicrm.live.credential_tenant');
        config(['aicrm.live.shop_tenants.999' => $tenantA]);
        Http::fake(['*' => Http::response(['status' => 200, 'error' => false, 'data' => ['ORDER_NUMBER' => 'VTP-BINDING']])]);
        $this->postJson($this->url('/viettel-post'))->assertOk();
        $tenantB = '33333333-3333-4333-8333-333333333333';
        config(['aicrm.live.shop_tenants.'.$this->shop->id => $tenantB,
            'aicrm.live.credential_tenant' => $tenantB, 'aicrm.live.api_key' => 'different-key']);
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        $this->artisan('aicrm:reconcile', ['order-id' => $this->order->id, '--actor-id' => $this->actor->id])->assertFailed();
        $this->assertNull(AicrmShipment::first()->remote_order_id);
        Http::assertSentCount(1);
    }

    #[DataProvider('getOrderStatuses')]
    public function test_explicit_reconciliation_maps_get_status_and_uuid_without_changing_pos_warehouse_or_post_history(string $status): void
    {
        $shipment = $this->successfulLiveShipment();
        $before = $this->order->fresh()->getRawOriginal();
        $beforeAttempt = AicrmShipmentAttempt::first()->getRawOriginal();
        $this->fakeReconciliation($status);
        $this->artisan('aicrm:reconcile', ['order-id' => $this->order->id, '--actor-id' => $this->actor->id])
            ->doesntExpectOutputToContain('upstream-private-token')->assertSuccessful();
        $this->assertSame('22222222-2222-4222-8222-222222222222', $shipment->fresh()->remote_order_id);
        $this->assertSame($status, $shipment->fresh()->remote_status);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.shipment.remote_status_label', AicrmOrderStatus::LABELS[$status]);
        $this->assertSame($before, $this->order->fresh()->getRawOriginal());
        $this->assertSame($beforeAttempt, AicrmShipmentAttempt::first()->getRawOriginal());
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $stored = json_encode(DB::table('aicrm_shipments')->get()).json_encode(DB::table('aicrm_shipment_attempts')->get());
        $this->assertStringNotContainsString('upstream-private-token', $stored);
    }

    public static function getOrderStatuses(): array
    {
        return array_map(fn ($status) => [$status], ['PENDING', 'PICKING', 'PROCESSING', 'CANCELED', 'SHIPPING', 'DELIVERED', 'RETURNED', 'ERROR']);
    }

    #[DataProvider('unsafeReconciliations')]
    public function test_reconciliation_never_links_on_missing_ambiguous_wrong_tenant_malformed_or_incomplete_get(string $scenario): void
    {
        $shipment = $this->successfulLiveShipment();
        $this->fakeReconciliation('SHIPPING', $scenario);
        $this->artisan('aicrm:reconcile', ['order-id' => $this->order->id, '--actor-id' => $this->actor->id])->assertFailed();
        $this->assertNull($shipment->fresh()->remote_order_id);
        $this->assertNull($shipment->fresh()->remote_status);
        $this->assertSame('succeeded', $shipment->fresh()->status);
        $this->assertDatabaseCount('aicrm_shipment_attempts', 1);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public static function unsafeReconciliations(): array
    {
        return array_map(fn ($scenario) => [$scenario], ['missing', 'ambiguous', 'wrong_tenant', 'wrong_tracking', 'wrong_uuid',
            'numeric_status', 'unknown_status', 'missing_uuid', 'bad_page', 'html', 'unauthorized', 'timeout', 'too_many_pages', 'changed_total', 'duplicate_rows']);
    }

    public function test_unique_tracking_on_later_page_is_linked_only_after_all_pages_and_detail_tenant_check(): void
    {
        $shipment = $this->successfulLiveShipment();
        $this->fakeReconciliation('PICKING', 'later_page');
        $this->artisan('aicrm:reconcile', ['order-id' => $this->order->id, '--actor-id' => $this->actor->id])->assertSuccessful();
        $this->assertSame('PICKING', $shipment->fresh()->remote_status);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '?page=2&size=100'));
    }

    public function test_reconciliation_requires_shop_and_both_permissions_and_never_unlocks_unknown(): void
    {
        $shipment = $this->successfulLiveShipment();
        $shipment->update(['status' => 'unknown']);
        $this->artisan('aicrm:reconcile', ['order-id' => $this->order->id, '--actor-id' => $this->actor->id])->assertFailed();
        $this->postJson($this->url('/viettel-post'))->assertStatus(409);
        $shipment->update(['status' => 'succeeded']);
        $this->actor->shops()->detach();
        $this->app->forgetInstance(ShopAccessService::class);
        $this->artisan('aicrm:reconcile', ['order-id' => $this->order->id, '--actor-id' => $this->actor->id])->assertFailed();
        $this->actor->shops()->attach($this->shop->id);
        RolePermission::where('role_id', $this->actor->role_id)->where('permission_id', Permission::where('slug', 'warehouse-pack')->value('id'))->delete();
        $this->artisan('aicrm:reconcile', ['order-id' => $this->order->id, '--actor-id' => $this->actor->id])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_legacy_numeric_post_status_is_not_exposed_as_get_shipping_status(): void
    {
        $this->postJson($this->url('/viettel-post'))->assertOk();
        AicrmShipment::first()->update(['remote_status' => '200']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.shipment.remote_status', null)
            ->assertJsonPath('data.shipment.remote_status_label', null);
    }

    public function test_offline_mock_remains_usable_before_tenant_binding_migration_is_applied(): void
    {
        (require database_path('migrations/2026_11_04_000003_add_aicrm_tenant_binding.php'))->down();
        $this->postJson($this->url('/viettel-post'))->assertOk();
        $this->postJson($this->url('/viettel-post'))->assertOk()->assertJsonPath('replayed', true);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.can_create_shipment', false);
        $this->assertDatabaseCount('aicrm_shipment_attempts', 1);
        Http::assertNothingSent();
    }

    private function successfulLiveShipment(): AicrmShipment
    {
        $this->live(true);
        Http::fake(['*' => Http::response(['status' => 200, 'error' => false, 'data' => ['ORDER_NUMBER' => 'VTP-RECONCILE']])]);
        $this->postJson($this->url('/viettel-post'))->assertOk()->assertJsonPath('data.remote_order_id', null)->assertJsonPath('data.remote_status', null);
        config(['aicrm.live.creation_enabled' => false]);
        // A new factory resets recorded HTTP; reconciliation below must be GET only.
        Http::swap(new Factory);
        Http::preventStrayRequests();

        return AicrmShipment::first();
    }

    private function fakeReconciliation(string $status, string $scenario = 'unique'): void
    {
        $uuid = '22222222-2222-4222-8222-222222222222';
        $tenant = config('aicrm.live.credential_tenant');
        if ($scenario === 'too_many_pages') {
            config(['aicrm.live.reconciliation_max_pages' => 1]);
        }
        Http::fake(function ($request) use ($status, $scenario, $uuid, $tenant) {
            $this->assertSame('GET', $request->method());
            $this->assertTrue($request->hasHeader('X-API-Key', 'live-test-secret'));
            $this->assertFalse($request->hasHeader('X-Tenant-ID'));
            $this->assertFalse($request->hasHeader('Authorization'));
            if ($scenario === 'timeout') {
                throw new ConnectionException('upstream-private-token');
            }
            if ($scenario === 'unauthorized') {
                return Http::response(['error' => 'upstream-private-token'], 401);
            }
            if ($scenario === 'html') {
                return Http::response('<html>upstream-private-token</html>');
            }
            if (str_contains($request->url(), '?')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) $query['page'];
                $multi = in_array($scenario, ['later_page', 'ambiguous', 'too_many_pages', 'changed_total', 'duplicate_rows'], true);
                $row = ['id' => $scenario === 'missing_uuid' ? null : $uuid, 'vtpOrderNumber' => $scenario === 'missing' ? 'OTHER' : 'VTP-RECONCILE', 'status' => $status];
                $rows = [$row];
                if ($multi && $page === 1) {
                    $rows = array_map(fn ($index) => ['id' => (string) Str::uuid(), 'vtpOrderNumber' => 'OTHER-'.$index], range(1, 100));
                    if ($scenario !== 'later_page') {
                        $rows[0] = $row;
                    }
                }
                if ($multi && $page === 2) {
                    $rows[0]['id'] = $scenario === 'duplicate_rows' ? $uuid : '44444444-4444-4444-8444-444444444444';
                }

                return Http::response(['content' => $rows, 'pageNumber' => $scenario === 'bad_page' ? 0 : $page,
                    'pageSize' => 100, 'totalElements' => $scenario === 'changed_total' && $page === 2 ? 102 : ($multi ? 101 : 1),
                    'totalPages' => $multi ? 2 : 1]);
            }
            $detailId = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response(['id' => $scenario === 'wrong_uuid' ? (string) Str::uuid() : $detailId,
                'tenantId' => $scenario === 'wrong_tenant' ? (string) Str::uuid() : $tenant,
                'vtpOrderNumber' => $scenario === 'wrong_tracking' ? 'OTHER' : 'VTP-RECONCILE',
                'status' => $scenario === 'numeric_status' ? 200 : ($scenario === 'unknown_status' ? 'CANCELLED' : $status),
                'responsePayload' => 'upstream-private-token']);
        });
    }

    private function live(bool $creationEnabled = false): void
    {
        // Audited source contract, fake host and credentials. All HTTP is intercepted.
        config(['aicrm.mode' => 'live', 'aicrm.live.enabled' => true, 'aicrm.live.contract_confirmed' => true,
            'aicrm.live.creation_enabled' => $creationEnabled, 'aicrm.live.base_url' => 'https://aicrm-live.example.test',
            'aicrm.live.hosts' => ['aicrm-live.example.test'], 'aicrm.live.api_key' => 'live-test-secret',
            'aicrm.live.tenant_mode' => 'api_key', 'aicrm.live.tenant_header' => null,
            'aicrm.live.credential_tenant' => '11111111-1111-4111-8111-111111111111', 'aicrm.live.tenant_credentials' => [],
            'aicrm.live.shop_tenants' => [$this->shop->id => '11111111-1111-4111-8111-111111111111'],
            'aicrm.live.shipping_provider_id' => 73, 'aicrm.live.orders_path' => '/confirmed/orders',
            'aicrm.live.providers_path' => '/confirmed/providers', 'aicrm.live.auth_check_path' => '/confirmed/me',
            'aicrm.live.order_detail_path' => '/confirmed/orders/{id}',
            'aicrm.live.order_id_response_path' => 'data.confirmedId',
            'aicrm.live.reconciliation_confirmed' => true, 'aicrm.live.approved_order_id' => $this->order->id,
            'aicrm.live.activated_at' => now()->subMinute()->toIso8601String()]);
    }

    private function sandbox(): void
    {
        config(['aicrm.mode' => 'sandbox', 'aicrm.sandbox_enabled' => true,
            'aicrm.base_url' => 'http://127.0.0.1:8011', 'aicrm.sandbox_hosts' => ['127.0.0.1'],
            'aicrm.api_key' => 'test-secret', 'aicrm.tenant_header' => null,
            'aicrm.credential_tenant' => '11111111-1111-4111-8111-111111111111',
            'aicrm.shop_tenants' => [$this->shop->id => '11111111-1111-4111-8111-111111111111'], 'aicrm.tenant_credentials' => []]);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/warehouse/orders/'.$this->order->id.$suffix;
    }
}
