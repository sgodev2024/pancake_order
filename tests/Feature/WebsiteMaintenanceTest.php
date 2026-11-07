<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use App\Models\Setting;
use App\Services\WebsiteMaintenance;
use App\Http\Controllers\Api\WebsiteMaintenanceController;
use App\Http\Middleware\WebsiteMaintenanceMiddleware;
class WebsiteMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        Schema::create('settings', function (Blueprint $t) { $t->id(); $t->string('code'); $t->text('data')->nullable(); $t->timestamps(); });
    }
    private function requestFor($slug, $path = '/api/v1/orders'): Request
    {
        $r = Request::create($path, 'GET');
        $r->setUserResolver(fn () => (object) ['role' => (object) ['slug' => $slug]]);
        return $r;
    }
    private function enabled(): void { Setting::create(['code' => 'website_maintenance', 'data' => ['enabled' => true]]); }
    public function test_disabled_by_default(): void
    {
        $this->assertFalse(app(WebsiteMaintenance::class)->enabled());
        $this->assertSame(200, (new WebsiteMaintenanceMiddleware)->handle($this->requestFor('staff-cskh'), fn () => response('ok'))->status());
    }
    public function test_blocks_staff_but_keeps_admin_and_profile_access(): void
    {
        $this->enabled(); $m = new WebsiteMaintenanceMiddleware;
        $blocked = $m->handle($this->requestFor('staff-cskh'), fn () => response('ok'));
        $this->assertSame(503, $blocked->status());
        $this->assertSame('Website đang bảo trì', $blocked->getData()->message);
        foreach ([$this->requestFor('admin'), $this->requestFor('staff-cskh', '/api/v1/me')] as $r) $this->assertSame(200, $m->handle($r, fn () => response('ok'))->status());
    }
    public function test_admin_can_enable_and_disable_with_public_status(): void
    {
        foreach ([true, false] as $enabled) {
            $r = $this->requestFor('admin'); $r->merge(['enabled' => $enabled]);
            (new WebsiteMaintenanceController)->update($r);
            $this->assertSame($enabled, app(WebsiteMaintenance::class)->enabled());
            $this->getJson('/api/v1/website-maintenance')->assertOk()->assertJson(['enabled' => $enabled]);
        }
    }
    public function test_staff_cannot_toggle(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new WebsiteMaintenanceController)->update($this->requestFor('staff-cskh'));
    }
}
