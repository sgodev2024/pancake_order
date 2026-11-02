<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CustomerCareController;
use App\Http\Controllers\Api\ProvinceController;
use App\Http\Controllers\Api\UserController;
use App\Models\CustomerCare;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardReportCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Do not use RefreshDatabase: this suite owns only this in-memory DB.
        config([
            'database.default' => 'dashboard_cache_testing',
            'database.connections.dashboard_cache_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('dashboard_cache_testing');
        DB::setDefaultConnection('dashboard_cache_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Cache::flush();
        Carbon::setTestNow('2026-11-02 10:10:10');

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('role_id');
            $table->string('pancake_user_id');
            $table->timestamps();
        });
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('shop_users', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_manager')->default(false);
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_order_id')->nullable();
            $table->string('order_number_vtp')->nullable();
            $table->string('user_creator_id')->nullable();
            $table->string('user_care_id')->nullable();
            $table->string('user_assigning_seller_id')->nullable();
            $table->decimal('cod', 20, 2)->default(0);
            $table->decimal('prepaid_amount', 20, 2)->nullable();
            $table->integer('status')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('customer_cares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_order_id')->nullable();
            $table->string('user_creator_id')->nullable();
            $table->string('user_care_id')->nullable();
            $table->string('user_assigning_seller_id')->nullable();
            $table->date('date_care');
            $table->dateTime('time_care')->nullable();
            $table->integer('status')->default(0);
            $table->boolean('is_accept')->default(true);
            $table->integer('total_edit')->default(0);
            $table->timestamps();
        });
        Schema::create('customer_care_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('customer_care_id');
            $table->unsignedBigInteger('assignee_user_id');
            $table->unsignedBigInteger('source_id');
            $table->string('source_type');
            $table->string('status');
        });
        Schema::create('customer_assigneds', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_care_id');
            $table->string('pancake_user_id');
        });

        DB::table('roles')->insert([['id' => 1, 'slug' => 'admin'], ['id' => 2, 'slug' => 'staff-sale']]);
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Admin', 'role_id' => 1, 'pancake_user_id' => 'admin'],
            ['id' => 10, 'name' => 'Alice', 'role_id' => 2, 'pancake_user_id' => 'alice'],
        ]);
        DB::table('shops')->insert([['id' => 1, 'name' => 'One'], ['id' => 2, 'name' => 'Two']]);
        DB::table('shop_users')->insert(['shop_id' => 1, 'user_id' => 10, 'is_manager' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('dashboard_cache_testing');
        parent::tearDown();
    }

    public function test_weekly_revenue_hit_skips_order_scans_and_committed_edit_refreshes_totals(): void
    {
        DB::table('orders')->insert([
            ['id' => 1, 'shop_id' => 1, 'user_creator_id' => 'alice', 'cod' => 100, 'created_at' => '2026-11-01 12:00:00'],
            ['id' => 2, 'shop_id' => 2, 'user_creator_id' => 'alice', 'cod' => 200, 'created_at' => '2026-11-02 12:00:00'],
        ]);
        $actor = User::findOrFail(1);
        $filters = ['date_from' => '2026-11-01', 'date_to' => '2026-11-30'];

        $this->startQueryLog();
        $cold = app(UserController::class)->weeklyRevenue($this->request($actor, $filters))->getData(true);
        $this->assertSame(300.0, (float) $cold['data']['items'][0]['revenue']);
        $this->assertSame(2, $this->queriesReading('orders'));

        $this->startQueryLog();
        $warm = app(UserController::class)->weeklyRevenue($this->request($actor, $filters))->getData(true);
        $this->assertSame($cold, $warm);
        $this->assertSame(0, $this->queriesReading('orders'));

        DB::transaction(fn () => Order::findOrFail(1)->update(['cod' => 175]));
        $changed = app(UserController::class)->weeklyRevenue($this->request($actor, $filters))->getData(true);
        $this->assertSame(375.0, (float) $changed['data']['items'][0]['revenue']);

        $oneShop = app(UserController::class)->weeklyRevenue($this->request($actor, $filters + ['shop_id' => 1]))->getData(true);
        $this->assertSame(175.0, (float) $oneShop['data']['items'][0]['revenue']);
    }

    public function test_empty_province_result_is_cached_before_schema_and_latest_order_queries(): void
    {
        $actor = User::findOrFail(1);
        $this->startQueryLog();
        $cold = app(ProvinceController::class)->charts($this->request($actor))->getData(true);
        $this->assertTrue($cold['success']);
        $this->assertSame([], $cold['data']['items']);
        $this->assertSame(1, $this->queriesReading('orders'));

        $this->startQueryLog();
        $this->assertSame($cold, app(ProvinceController::class)->charts($this->request($actor))->getData(true));
        $this->assertSame(0, $this->queriesReading('orders'));
        $this->assertCount(0, DB::getQueryLog());

        // Even a non-waybill order invalidates the revision conservatively.
        Order::create(['shop_id' => 1, 'cod' => 10]);
        $this->startQueryLog();
        $this->assertSame($cold, app(ProvinceController::class)->charts($this->request($actor))->getData(true));
        $this->assertSame(1, $this->queriesReading('orders'));

        Carbon::setTestNow('2026-11-02 10:11:10');
        $this->startQueryLog();
        app(ProvinceController::class)->charts($this->request($actor));
        $this->assertSame(1, $this->queriesReading('orders'));
    }

    public function test_rolled_back_order_change_keeps_the_committed_report_cached(): void
    {
        DB::table('orders')->insert([
            'id' => 1,
            'shop_id' => 1,
            'user_creator_id' => 'alice',
            'cod' => 100,
            'created_at' => '2025-01-01 12:00:00',
        ]);
        $actor = User::findOrFail(1);
        $filters = ['week_mode' => 'all'];
        $cold = app(UserController::class)->weeklyRevenue($this->request($actor, $filters))->getData(true);
        $this->assertSame(100.0, (float) $cold['data']['items'][0]['revenue']);
        $this->assertSame('2025-01-01', $cold['data']['from']);

        DB::beginTransaction();
        try {
            Order::findOrFail(1)->update(['cod' => 999]);
        } finally {
            DB::rollBack();
        }

        $this->startQueryLog();
        $this->assertSame($cold, app(UserController::class)->weeklyRevenue($this->request($actor, $filters))->getData(true));
        $this->assertSame(0, $this->queriesReading('orders'));
    }

    public function test_overview_hit_skips_three_care_queries_and_refreshes_after_care_update(): void
    {
        DB::table('customer_cares')->insert([
            ['id' => 1, 'shop_id' => 1, 'user_creator_id' => 'alice', 'date_care' => '2026-11-02', 'status' => 0, 'total_edit' => 2],
            ['id' => 2, 'shop_id' => 2, 'user_creator_id' => 'other', 'date_care' => '2026-11-02', 'status' => 1, 'total_edit' => 3],
        ]);
        $this->actingAs(User::findOrFail(1));
        $this->startQueryLog();
        $cold = app(CustomerCareController::class)->overview()->getData(true);
        $this->assertTrue($cold['success']);
        $this->assertSame(2, $cold['data']['customer_care_today']);
        $this->assertSame(1, $cold['data']['customer_care_today_done']);
        $this->assertSame(3, $this->queriesReading('customer_cares'));

        $this->startQueryLog();
        $this->assertSame($cold, app(CustomerCareController::class)->overview()->getData(true));
        $this->assertSame(0, $this->queriesReading('customer_cares'));
        $this->assertSame(0, $this->queriesReading('orders'));

        DB::transaction(fn () => CustomerCare::findOrFail(1)->update(['status' => 1]));
        $changed = app(CustomerCareController::class)->overview()->getData(true);
        $this->assertSame(2, $changed['data']['customer_care_today_done']);

        $this->actingAs(User::findOrFail(10));
        $staff = app(CustomerCareController::class)->overview()->getData(true);
        $this->assertTrue($staff['success']);
        $this->assertSame(1, $staff['data']['total_customer_care']);
        $this->assertSame(1, $staff['data']['customer_care_today']);
    }

    public function test_authorization_is_checked_before_reading_a_cached_report(): void
    {
        app(ProvinceController::class)->charts($this->request(User::findOrFail(1), ['shop_id' => 2]));
        $this->expectException(AuthorizationException::class);
        app(ProvinceController::class)->charts($this->request(User::findOrFail(10), ['shop_id' => 2]));
    }

    private function request(User $actor, array $query = []): Request
    {
        $request = Request::create('/report-cache-test', 'GET', $query);
        $request->setUserResolver(fn () => $actor);

        return $request;
    }

    private function startQueryLog(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
    }

    private function queriesReading(string $table): int
    {
        return collect(DB::getQueryLog())->filter(
            fn (array $query) => str_contains(strtolower($query['query']), 'from "'.$table.'"')
        )->count();
    }
}
