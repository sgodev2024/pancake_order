<?php

namespace Tests\Feature;

use App\Services\AicrmLocalConnection;
use App\Services\AicrmOrderClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AicrmLocalConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        config(['aicrm.local' => ['enabled' => true, 'base_url' => 'http://127.0.0.1:8185',
            'api_key' => 'dummy-local-guard-test']]);
    }

    public function test_only_canonical_loopback_origins_are_accepted(): void
    {
        foreach (['http://127.0.0.1:8185', 'http://localhost:8185/', 'https://localhost:8185'] as $url) {
            config(['aicrm.local.base_url' => $url]);
            $connection = app(AicrmLocalConnection::class)->validateConfiguration();
            $this->assertSame(rtrim($url, '/'), $connection['base_url']);
        }
    }

    #[DataProvider('unsafeOrigins')]
    public function test_remote_and_ambiguous_urls_are_rejected_before_transport(string $url): void
    {
        config(['aicrm.local.base_url' => $url]);
        $this->expectException(HttpException::class);
        app(AicrmLocalConnection::class)->validateConfiguration();
    }

    public static function unsafeOrigins(): array
    {
        return array_map(fn ($url) => [$url], ['https://api.aicrm.vn', 'http://127.0.0.2:8185',
            'http://localhost.example.test:8185', 'http://127.0.0.1@outside.example.test',
            'http://user@localhost:8185', 'http://localhost:8185/api', 'http://localhost:8185?next=x',
            'http://localhost:8185#fragment', 'http://[::1]:8185', 'file:///tmp/aicrm',
            'http://localhost:0', '//localhost:8185', 'http://localhost:8185//']);
    }

    public function test_missing_key_is_rejected_before_transport(): void
    {
        config(['aicrm.local.api_key' => null]);
        $this->expectException(HttpException::class);
        app(AicrmLocalConnection::class)->validateConfiguration();
    }

    public function test_disabled_local_connection_is_rejected(): void
    {
        config(['aicrm.local.enabled' => false]);
        $this->expectException(HttpException::class);
        app(AicrmLocalConnection::class)->validateConfiguration();
    }

    public function test_production_cannot_enable_local_transport(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(HttpException::class);
        app(AicrmLocalConnection::class)->validateConfiguration();
    }

    public function test_local_connection_does_not_require_a_tenant_uuid(): void
    {
        config(['aicrm.mode' => 'local', 'aicrm.local.upstream_verified' => true]);
        app(AicrmOrderClient::class)->validateAutoConfiguration();
        $this->assertTrue(method_exists(AicrmLocalConnection::class, 'create'));
    }
}
