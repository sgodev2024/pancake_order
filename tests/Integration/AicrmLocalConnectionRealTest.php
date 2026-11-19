<?php

namespace Tests\Integration;

use App\Services\AicrmLocalConnection;
use Tests\TestCase;

/** Opt-in loopback integration. No HTTP fakes, mocks or production endpoint. */
class AicrmLocalConnectionRealTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AICRM_RUN_LOCAL_INTEGRATION') !== '1') {
            $this->markTestSkipped('Real loopback integration is opt-in.');
        }
        $this->app->detectEnvironment(fn () => 'local');
    }

    public function test_real_laravel_get_reads_local_provider_list(): void
    {
        $response = app(AicrmLocalConnection::class)->providers();
        $this->assertSame(200, $response->status());
        $body = $response->json();
        $this->assertIsArray($body);
        $this->assertTrue(array_is_list($body));
    }

    public function test_real_aicrm_rejects_missing_key(): void
    {
        $this->assertSame(401, app(AicrmLocalConnection::class)->providers('missing')->status());
    }

    public function test_real_aicrm_rejects_invalid_key(): void
    {
        $this->assertSame(401, app(AicrmLocalConnection::class)->providers('invalid')->status());
    }
}
