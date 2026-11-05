<?php

namespace Tests\Feature;

use App\Services\AicrmLocalConnection;
use App\Services\AicrmOrderClient;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AicrmLocalConnectionTest extends TestCase
{
    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        $directory = storage_path('app/private/aicrm-local');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $this->keyFile = tempnam($directory, 'guard-test-');
        file_put_contents($this->keyFile, 'dummy-local-guard-test');
        chmod($this->keyFile, 0600);
        $tenant = '11111111-1111-4111-8111-111111111111';
        config(['aicrm.local' => ['enabled' => true, 'base_url' => 'http://127.0.0.1:8185',
            'api_key_file' => $this->keyFile, 'credential_tenant' => $tenant, 'shop_tenants' => [7 => $tenant]]]);
    }

    protected function tearDown(): void
    {
        if (isset($this->keyFile) && is_file($this->keyFile)) {
            unlink($this->keyFile);
        }
        parent::tearDown();
    }

    public function test_only_canonical_loopback_origins_are_accepted(): void
    {
        foreach (['http://127.0.0.1:8185', 'http://localhost:8185/', 'https://localhost:8185'] as $url) {
            config(['aicrm.local.base_url' => $url]);
            $connection = app(AicrmLocalConnection::class)->validateConfiguration(7);
            $this->assertSame(rtrim($url, '/'), $connection['base_url']);
        }
        $this->assertFalse(method_exists(AicrmLocalConnection::class, 'create'));
    }

    #[DataProvider('unsafeOrigins')]
    public function test_remote_and_ambiguous_urls_are_rejected_before_transport(string $url): void
    {
        config(['aicrm.local.base_url' => $url]);
        $this->expectException(HttpException::class);
        app(AicrmLocalConnection::class)->validateConfiguration(7);
    }

    public static function unsafeOrigins(): array
    {
        return array_map(fn ($url) => [$url], ['https://api.aicrm.vn', 'http://127.0.0.2:8185',
            'http://localhost.example.test:8185', 'http://127.0.0.1@outside.example.test',
            'http://user@localhost:8185', 'http://localhost:8185/api', 'http://localhost:8185?next=x',
            'http://localhost:8185#fragment', 'http://[::1]:8185', 'file:///tmp/aicrm',
            'http://localhost:0', '//localhost:8185', 'http://localhost:8185//']);
    }

    public function test_wrong_tenant_is_rejected_before_transport(): void
    {
        config(['aicrm.local.shop_tenants.7' => (string) Str::uuid()]);
        $this->expectException(HttpException::class);
        app(AicrmLocalConnection::class)->validateConfiguration(7);
    }

    public function test_unmapped_shop_is_rejected_before_transport(): void
    {
        $this->expectException(HttpException::class);
        app(AicrmLocalConnection::class)->validateConfiguration(8);
    }

    public function test_missing_key_file_is_rejected_before_transport(): void
    {
        config(['aicrm.local.api_key_file' => null]);
        $this->expectException(HttpException::class);
        app(AicrmLocalConnection::class)->validateConfiguration(7);
    }

    public function test_key_file_must_be_private(): void
    {
        chmod($this->keyFile, 0644);
        clearstatcache(true, $this->keyFile);
        $this->expectException(HttpException::class);
        app(AicrmLocalConnection::class)->validateConfiguration(7);
    }

    public function test_disabled_local_connection_is_rejected(): void
    {
        config(['aicrm.local.enabled' => false]);
        $this->expectException(HttpException::class);
        app(AicrmLocalConnection::class)->validateConfiguration(7);
    }

    public function test_production_cannot_enable_local_transport(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(HttpException::class);
        app(AicrmLocalConnection::class)->validateConfiguration(7);
    }

    public function test_local_is_not_a_supported_shipment_creation_mode(): void
    {
        config(['aicrm.mode' => 'local']);
        $this->expectException(HttpException::class);
        app(AicrmOrderClient::class)->validateConfiguration(7);
    }
}
