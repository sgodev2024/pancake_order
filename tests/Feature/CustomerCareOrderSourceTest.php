<?php

namespace Tests\Feature;

use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\CustomerCareListQuery;
use App\Services\CustomerCareOrderSourceService;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerCareOrderSourceTest extends TestCase
{
    private User $admin;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'care_source_testing', 'database.connections.care_source_testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('care_source_testing');
        DB::setDefaultConnection('care_source_testing');
        Http::preventStrayRequests();
        $this->schema();
        $this->shop = Shop::create(['name' => 'Allowed shop']);
        $this->admin = $this->user('admin');
        $this->actingAs($this->admin, 'api');
    }

    public static function resolutionCases(): array
    {
        return array_map(fn ($case) => [$case], [
            'legacy', 'duplicate legacy', 'no order', 'direct duplicate legacy', 'same histories',
            'different histories', 'missing direct', 'cross shop assignment', 'cross shop order',
            'mismatched code', 'deleted direct', 'deleted legacy', 'imported collision',
            'imported with legacy', 'no page', 'blank code direct', 'null code direct',
            'valid and invalid history', 'multiple active same order', 'multiple active different order',
            'same code other shop', 'empty legacy code',
        ]);
    }

    #[DataProvider('resolutionCases')]
    public function test_display_filter_and_options_share_exact_resolution(string $case): void
    {
        $order = $this->order();
        $care = $this->care($order);
        $resolves = in_array($case, ['legacy', 'direct duplicate legacy', 'same histories', 'imported with legacy',
            'blank code direct', 'null code direct', 'multiple active same order', 'same code other shop'], true);

        switch ($case) {
            case 'duplicate legacy':
                $this->order(['pancake_order_id' => $order->pancake_order_id]);
                break;
            case 'no order':
                $care->update(['pancake_order_id' => 'missing']);
                break;
            case 'direct duplicate legacy':
                $this->order(['pancake_order_id' => $order->pancake_order_id, 'pancake_order_page_name' => 'Wrong sibling']);
                $this->assignment($care, $order);
                break;
            case 'same histories':
                $this->assignment($care, $order, ['status' => 'reclaimed']);
                $this->assignment($care, $order, ['status' => 'active', 'cared_at' => now()]);
                break;
            case 'different histories':
            case 'multiple active different order':
                $other = $this->order(['pancake_order_id' => $order->pancake_order_id]);
                $status = $case === 'different histories' ? 'reclaimed' : 'active';
                $this->assignment($care, $order, ['status' => $status]);
                $this->assignment($care, $other, ['status' => $status]);
                break;
            case 'missing direct':
                $this->assignment($care, $order, ['source_id' => 99999]);
                break;
            case 'cross shop assignment':
                $this->assignment($care, $order, ['shop_id' => 999]);
                break;
            case 'cross shop order':
                $other = $this->order(['shop_id' => 999, 'pancake_order_id' => $order->pancake_order_id]);
                $this->assignment($care, $other);
                break;
            case 'mismatched code':
                $this->assignment($care, $this->order());
                break;
            case 'deleted direct':
                $this->assignment($care, $order);
                $order->delete();
                $this->order(['pancake_order_id' => $order->pancake_order_id]);
                break;
            case 'deleted legacy':
                $order->delete();
                break;
            case 'imported collision':
                $care->update(['pancake_order_id' => null]);
                $this->assignment($care, $order, ['source_type' => 'imported_opportunity']);
                break;
            case 'imported with legacy':
                $this->assignment($care, $this->order(), ['source_type' => 'imported_opportunity']);
                break;
            case 'no page':
                $order->update(['pancake_order_page_id' => null, 'pancake_order_page_name' => null]);
                break;
            case 'blank code direct':
            case 'null code direct':
                $care->update(['pancake_order_id' => $case === 'null code direct' ? null : '  ']);
                $this->assignment($care, $order);
                break;
            case 'valid and invalid history':
                $this->assignment($care, $order);
                $this->assignment($care, $order, ['status' => 'reclaimed', 'shop_id' => 999]);
                break;
            case 'multiple active same order':
                $this->assignment($care, $order);
                $this->assignment($care, $order);
                break;
            case 'same code other shop':
                $this->order(['shop_id' => 999, 'pancake_order_id' => $order->pancake_order_id]);
                break;
            case 'empty legacy code':
                $care->update(['pancake_order_id' => '']);
                break;
        }

        $list = $this->getJson($this->listUrl('customer_care_edit'))->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.total_items', 1);
        $list->assertJsonPath('data.customers.0.order_page_id', $resolves ? 'pzl_695112902870160686' : null)
            ->assertJsonPath('data.customers.0.order_page_name', $resolves ? 'Exact snapshot' : null);
        $row = $list->json('data.customers.0');
        $this->assertStringNotContainsString('pancake_full_data', json_encode($row));
        $this->assertArrayNotHasKey('resolved_source_order_id', $row);
        foreach (['active_assignment', 'current_assignment'] as $key) {
            if (! $resolves || str_starts_with($case, 'imported')) {
                $this->assertNull($row[$key]['source_order'] ?? null);
            }
        }
        if ($resolves) {
            $this->assertSame($order->id, $row['order']['id']);
        }
        $this->getJson($this->listUrl('customer_care_edit').'&order_page_id=pzl_695112902870160686')
            ->assertOk()->assertJsonPath('data.total_items', $resolves ? 1 : 0);
        $this->getJson($this->optionsUrl('customer_care_edit'))->assertOk()
            ->assertJsonCount($resolves ? 1 : 0, 'data');
    }

    public static function careTypes(): array
    {
        return [['customer_care_today', 0], ['customer_care_pending', 1], ['customer_care_expire', -1], ['customer_care_edit', 0]];
    }

    #[DataProvider('careTypes')]
    public function test_all_four_types_resolve_and_offer_their_allowed_pages(string $type, int $day): void
    {
        $order = $this->order();
        $care = $this->care($order, ['date_care' => date('Y-m-d', strtotime("{$day} days"))]);
        $this->assignment($care, $order);
        $this->getJson($this->listUrl($type))->assertOk()->assertJsonPath('data.customers.0.order_page_name', 'Exact snapshot');
        $this->getJson($this->optionsUrl($type))->assertOk()->assertExactJson([
            'success' => true, 'data' => [['id' => 'pzl_695112902870160686', 'name' => 'Exact snapshot']],
        ]);
    }

    public function test_staff_context_uses_care_ownership_and_does_not_broaden_default_options(): void
    {
        $staff = $this->user('staff-cskh');
        $staff->shops()->attach($this->shop->id);
        $otherStaff = $this->user('staff-cskh');
        $assigned = $this->order();
        $this->assignment($this->care($assigned), $assigned, ['assignee_user_id' => $staff->id]);
        $hidden = $this->order(['pancake_order_page_id' => 'hidden']);
        // The creator may not reclaim visibility from another active assignee.
        $this->assignment($this->care($hidden, ['user_creator_id' => $staff->pancake_user_id]), $hidden, ['assignee_user_id' => $otherStaff->id]);
        $future = $this->order(['pancake_order_page_id' => 'future']);
        $this->assignment($this->care($future, ['date_care' => date('Y-m-d', strtotime('+1 day'))]), $future, ['assignee_user_id' => $staff->id]);
        $otherShop = Shop::create(['name' => 'Hidden shop']);
        $outside = $this->order(['shop_id' => $otherShop->id, 'pancake_order_page_id' => 'outside']);
        $this->assignment($this->care($outside), $outside, ['assignee_user_id' => $staff->id]);

        $this->actingAs($staff, 'api');
        $this->getJson('/api/v1/order-pages?shop_id='.$this->shop->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->optionsUrl('customer_care_today'))->assertOk()->assertExactJson([
            'success' => true, 'data' => [['id' => 'pzl_695112902870160686', 'name' => 'Exact snapshot']],
        ]);
        $this->getJson($this->listUrl('customer_care_today').'&order_page_id=hidden')->assertJsonPath('data.total_items', 0);
        $this->getJson($this->optionsUrl('customer_care_today', $otherShop->id))->assertForbidden();
        $this->getJson($this->listUrl('customer_care_today').'&shop_id='.$otherShop->id)->assertForbidden();
        // Edit intentionally retains the existing creator/care/assigner/pivot rules.
        $this->getJson($this->optionsUrl('customer_care_edit'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'hidden');
        $manager = $this->user('manager-cskh');
        $manager->shops()->attach($this->shop->id);
        $this->actingAs($manager, 'api')->getJson($this->optionsUrl('customer_care_today'))->assertJsonCount(2, 'data');
        $this->getJson($this->optionsUrl('customer_care_today', $otherShop->id))->assertForbidden();
        $sale = $this->user('manager-sale');
        $sale->shops()->attach($this->shop->id);
        $this->actingAs($sale, 'api')->getJson($this->optionsUrl('customer_care_today'))->assertForbidden();
        $this->actingAs($this->admin, 'api')->getJson($this->optionsUrl('customer_care_today', $otherShop->id))->assertJsonCount(1, 'data');
    }

    public function test_pagination_exact_filter_and_validation(): void
    {
        for ($i = 0; $i < 35; $i++) {
            $order = $this->order();
            $care = $this->care($order);
            $this->assignment($care, $order);
            $this->assignment($care, $order, ['status' => 'reclaimed']);
        }
        $this->care($this->order(['pancake_order_page_id' => 'pzl_6951129028701606860']));
        $this->getJson($this->listUrl('customer_care_edit', 2).'&order_page_id=pzl_695112902870160686')
            ->assertOk()->assertJsonPath('data.total_items', 35)->assertJsonPath('data.total_pages', 2)->assertJsonCount(5, 'data.customers');
        $this->getJson($this->listUrl('customer_care_edit').'&order_page_id=missing')->assertJsonPath('data.total_items', 0);
        $this->getJson($this->listUrl('customer_care_edit').'&order_page_id=')->assertJsonPath('data.total_items', 36);
        $this->getJson($this->listUrl('customer_care_edit').'&order_page_id[]=bad')->assertUnprocessable();
        $this->getJson($this->listUrl('customer_care_edit').'&order_page_id='.str_repeat('x', 256))->assertUnprocessable();
        $this->getJson('/api/v1/order-pages?shop_id='.$this->shop->id.'&context=customer_care')->assertUnprocessable();
        $this->getJson($this->optionsUrl('invalid'))->assertUnprocessable();
    }

    public function test_options_use_only_allowed_snapshots_and_rows_keep_their_own_names(): void
    {
        $old = $this->order(['pancake_order_page_name' => 'Old name']);
        $old->forceFill(['created_at' => '2026-01-01'])->save();
        $care = $this->care($old);
        $new = $this->order(['pancake_order_page_name' => 'New name']);
        $this->care($new);
        $this->order(['pancake_order_page_name' => 'Hidden newest']);
        $this->care($this->order(['pancake_order_page_id' => 'id-only', 'pancake_order_page_name' => null]));
        $this->getJson($this->optionsUrl('customer_care_edit'))->assertExactJson(['success' => true, 'data' => [
            ['id' => 'pzl_695112902870160686', 'name' => 'New name'], ['id' => 'id-only', 'name' => 'id-only'],
        ]]);
        $rows = $this->getJson($this->listUrl('customer_care_edit'))->json('data.customers');
        $this->assertSame('Old name', collect($rows)->keyBy('id')[$care->id]['order_page_name']);
    }

    public function test_imported_relation_cannot_lazy_or_eager_load_a_colliding_order(): void
    {
        $order = $this->order();
        $assignment = $this->assignment($this->care($order), $order, ['source_type' => 'imported_opportunity']);
        $this->assertNull($assignment->fresh()->sourceOrder);
        $this->assertNull(CustomerCareAssignment::with('sourceOrder')->findOrFail($assignment->id)->sourceOrder);
    }

    public function test_query_count_is_bounded_and_explain_uses_existing_indexes(): void
    {
        $make = function (): void {
            $order = $this->order();
            $this->assignment($this->care($order), $order);
        };
        $make();
        // Warm auth/role lookup, then compare identical populated relation shapes.
        $this->getJson($this->listUrl('customer_care_edit'))->assertJsonPath('success', true);
        $measure = function (): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->listUrl('customer_care_edit'))->assertJsonPath('success', true);
            $log = DB::getQueryLog();
            DB::disableQueryLog();
            foreach ($log as $query) {
                $this->assertStringNotContainsString('pancake_full_data', $query['query']);
            }

            return $log;
        };
        $one = $measure();
        for ($i = 1; $i < 30; $i++) {
            $make();
        }
        $thirty = $measure();
        $this->assertCount(count($one), $thirty);
        // A larger, mixed local fixture for the query planner (no application DB).
        for ($i = 0; $i < 1000; $i++) {
            $order = $this->order(['pancake_order_page_id' => 'other-'.($i % 20)]);
            $care = $this->care($order);
            if ($i % 2 === 0) {
                $this->assignment($care, $order);
            }
        }
        DB::statement('ANALYZE');
        $query = app(CustomerCareListQuery::class)->query('customer_care_edit', $this->admin, ['shop_id' => $this->shop->id]);
        app(CustomerCareOrderSourceService::class)->filter($query, 'pzl_695112902870160686');
        $query->select('customer_cares.id');
        $plan = DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings());
        $details = implode("\n", array_column($plan, 'detail'));
        $this->assertStringContainsString('orders_shop_page_index', $details);
        $this->assertCount(30, $query->get());
    }

    public function test_mysql_compatible_resolver_sql_uses_correlated_exists_without_union_subqueries(): void
    {
        $connection = DB::connection();
        $originalGrammar = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new MySqlGrammar($connection));

        try {
            $sources = app(CustomerCareOrderSourceService::class);
            $display = $sources->selectResolvedOrder(
                app(CustomerCareListQuery::class)->query('customer_care_edit', $this->admin, ['shop_id' => $this->shop->id])
            )->toSql();

            $filtered = app(CustomerCareListQuery::class)->query(
                'customer_care_edit', $this->admin, ['shop_id' => $this->shop->id]
            );
            $sources->filter($filtered, 'pzl_695112902870160686');

            $options = $sources->pageOptions(
                app(CustomerCareListQuery::class)->query('customer_care_edit', $this->admin, ['shop_id' => $this->shop->id]),
                $this->shop->id
            )->toSql();

            foreach ([$display, $filtered->toSql(), $options] as $sql) {
                $normalized = strtolower($sql);
                $this->assertStringContainsString('exists (select', $normalized);
                $this->assertStringNotContainsString('union', $normalized);
                $this->assertDoesNotMatchRegularExpression('/\\bin\\s*\\(\\s*select\\b.*\\bunion\\b/is', $normalized);
            }
        } finally {
            $connection->setQueryGrammar($originalGrammar);
        }
    }

    private function listUrl(string $type, int $page = 1): string
    {
        return "/api/v1/customer-cares?type={$type}&page={$page}";
    }

    private function optionsUrl(string $type, ?int $shopId = null): string
    {
        return '/api/v1/order-pages?shop_id='.($shopId ?? $this->shop->id).'&context=customer_care&type='.$type;
    }

    private function user(string $slug): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $key = uniqid();

        return User::create(['role_id' => $role->id, 'name' => $slug, 'email' => $key.'@example.test', 'password' => 'unused', 'pancake_user_id' => $key]);
    }

    private function order(array $attributes = []): Order
    {
        return Order::create(array_merge(['shop_id' => $this->shop->id, 'pancake_order_id' => uniqid().'_'.$this->shop->id,
            'pancake_order_page_id' => 'pzl_695112902870160686', 'pancake_order_page_name' => 'Exact snapshot',
            'status' => 0, 'pancake_full_data' => ['private' => 'must not be returned']], $attributes));
    }

    private function care(Order $order, array $attributes = []): CustomerCare
    {
        return CustomerCare::create(array_merge(['shop_id' => $order->shop_id, 'pancake_order_id' => $order->pancake_order_id,
            'pancake_customer_id' => 'same-customer', 'date_care' => date('Y-m-d'), 'total_edit' => 2, 'is_accept' => 1, 'status' => 0], $attributes));
    }

    private function assignment(CustomerCare $care, Order $order, array $attributes = []): CustomerCareAssignment
    {
        return CustomerCareAssignment::create(array_merge(['customer_care_id' => $care->id, 'shop_id' => $care->shop_id,
            'source_type' => 'order', 'source_id' => $order->id, 'assignee_user_id' => $this->admin->id,
            'assignee_pancake_user_id' => $this->admin->pancake_user_id, 'assigned_at' => now(),
            'reclaim_eligible_on' => date('Y-m-d'), 'status' => 'active'], $attributes));
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('slug');
            $t->string('name');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id');
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->string('pancake_user_id');
            $t->timestamps();
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('shop_users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('user_id');
            $t->boolean('is_manager')->default(false);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->string('pancake_order_id')->index();
            $t->string('pancake_order_page_id')->nullable();
            $t->string('pancake_order_page_name')->nullable();
            $t->string('user_creator_id')->nullable();
            $t->string('user_care_id')->nullable();
            $t->integer('status');
            $t->json('pancake_full_data')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['shop_id', 'pancake_order_page_id'], 'orders_shop_page_index');
        });
        Schema::create('customer_cares', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->string('pancake_order_id')->nullable()->index();
            $t->string('pancake_customer_id');
            foreach (['customer_name', 'customer_phones', 'customer_addresss', 'note', 'user_creator_id', 'user_care_id', 'user_assigning_seller_id'] as $c) {
                $t->string($c)->nullable();
            }
            $t->date('date_care');
            $t->dateTime('time_care')->nullable();
            $t->integer('total_edit')->default(0);
            $t->integer('status')->default(0);
            $t->boolean('is_accept')->default(true);
            $t->boolean('is_confirm_care')->default(false);
            $t->timestamps();
            $t->index(['shop_id', 'date_care']);
            $t->index(['shop_id', 'status', 'date_care']);
        });
        Schema::create('customer_care_assignments', function (Blueprint $t) {
            $t->id();
            foreach (['shop_id', 'customer_care_id', 'source_id', 'assignee_user_id'] as $c) {
                $t->unsignedBigInteger($c);
            }
            $t->string('source_type');
            $t->string('assignee_pancake_user_id');
            $t->string('status');
            $t->timestamp('assigned_at');
            $t->date('reclaim_eligible_on');
            $t->timestamp('cared_at')->nullable();
            $t->timestamp('reclaimed_at')->nullable();
            $t->timestamps();
            $t->index(['customer_care_id', 'status'], 'cca_care_status_idx');
            $t->index(['source_type', 'source_id', 'status'], 'cca_source_status_idx');
        });
        Schema::create('customer_assigneds', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_care_id');
            $t->string('pancake_user_id');
            $t->timestamps();
        });
    }
}
