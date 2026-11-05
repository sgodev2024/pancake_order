<?php

namespace Tests\Integration;

use App\Services\AicrmLocalConnection;
use App\Services\AicrmShippingProviders;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Opt-in loopback integration. No HTTP fakes, mocks or production endpoint. */
class AicrmLocalConnectionRealTest extends TestCase
{
    private int $shopId;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AICRM_RUN_LOCAL_INTEGRATION') !== '1') {
            $this->markTestSkipped('Real loopback integration is opt-in.');
        }
        $this->app->detectEnvironment(fn () => 'local');
        $this->shopId = (int) getenv('AICRM_LOCAL_TEST_SHOP_ID');
        $this->assertGreaterThan(0, $this->shopId);
    }

    public function test_real_laravel_get_reads_disabled_local_provider_without_selecting_it(): void
    {
        $response = app(AicrmLocalConnection::class)->providers($this->shopId);
        $this->assertSame(200, $response->status());
        $body = $response->json();
        $this->assertIsArray($body);
        $this->assertTrue(array_is_list($body));
        $matches = array_values(array_filter($body, fn ($row) => ($row['id'] ?? null) === (int) config('aicrm.local.expected_provider_id')
            && ($row['code'] ?? null) === 'VIETTEL_POST'));
        $this->assertCount(1, $matches);
        $this->assertFalse($matches[0]['isEnable']);
        $this->expectException(HttpException::class);
        app(AicrmShippingProviders::class)->viettelPostId($response);
    }

    public function test_real_aicrm_rejects_missing_key(): void
    {
        $this->assertSame(401, app(AicrmLocalConnection::class)->providers($this->shopId, 'missing')->status());
    }

    public function test_real_aicrm_rejects_invalid_key(): void
    {
        $this->assertSame(401, app(AicrmLocalConnection::class)->providers($this->shopId, 'invalid')->status());
    }

    public function test_wrong_tenant_is_stopped_by_pancake_binding(): void
    {
        config(['aicrm.local.shop_tenants.'.$this->shopId => (string) Str::uuid()]);
        $this->expectException(HttpException::class);
        app(AicrmLocalConnection::class)->validateConfiguration($this->shopId);
    }
}
