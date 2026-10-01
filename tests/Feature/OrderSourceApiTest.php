<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PancakeOrderSource;
use App\Models\Province;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderSourceApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'order_source_api_testing',
            'database.connections.order_source_api_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('order_source_api_testing');
        DB::setDefaultConnection('order_source_api_testing');
        Http::preventStrayRequests();

        $this->createSchema();
    }

    public function test_catalog_returns_only_active_sources_for_the_authorized_shop_in_stable_name_order(): void
    {
        $shop = $this->createShop('Own shop');
        $otherShop = $this->createShop('Other shop');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $this->attachShop($manager, $shop);

        $this->createSource($shop, '2', 'Alpha', ['parent_external_source_id' => 'parent-2']);
        $this->createSource($shop, '1', 'Alpha');
        $this->createSource($shop, '3', 'Beta');
        $this->createSource($shop, '4', 'Inactive', ['is_active' => false]);
        $this->createSource($otherShop, '1', 'Other shop source');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/order-sources?shop_id='.$shop->id)
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    [
                        'id' => '1',
                        'name' => 'Alpha',
                        'parent_id' => null,
                        'is_active' => true,
                    ],
                    [
                        'id' => '2',
                        'name' => 'Alpha',
                        'parent_id' => 'parent-2',
                        'is_active' => true,
                    ],
                    [
                        'id' => '3',
                        'name' => 'Beta',
                        'parent_id' => null,
                        'is_active' => true,
                    ],
                ],
            ]);
    }

    public function test_catalog_rejects_an_unauthorized_shop_but_admin_keeps_global_access(): void
    {
        $ownShop = $this->createShop('Own shop');
        $otherShop = $this->createShop('Other shop');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $admin = $this->createUser('admin', 'Admin');
        $this->attachShop($manager, $ownShop);
        $this->createSource($otherShop, '-9', 'TikTok');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/order-sources?shop_id='.$otherShop->id)
            ->assertForbidden();

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/order-sources?shop_id='.$otherShop->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', '-9');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/order-sources')
            ->assertUnprocessable();
    }

    public function test_order_source_filter_is_exact_and_remains_inside_existing_shop_scope(): void
    {
        $ownShop = $this->createShop('Own shop');
        $otherShop = $this->createShop('Other shop');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $this->attachShop($manager, $ownShop);

        $matchingOrder = $this->createOrder($ownShop, [
            'pancake_order_id' => 'OWN-MATCH',
            'pancake_order_source_id' => '-9',
            'pancake_order_source_name' => 'Tiktok historical snapshot',
        ]);
        $this->createOrder($ownShop, [
            'pancake_order_id' => 'OWN-OTHER-SOURCE',
            'pancake_order_source_id' => '-90',
            'pancake_order_source_name' => 'Another source',
        ]);
        $this->createOrder($otherShop, [
            'pancake_order_id' => 'OTHER-SHOP-MATCH',
            'pancake_order_source_id' => '-9',
            'pancake_order_source_name' => 'Other shop source',
        ]);
        $this->createSource($ownShop, '-9', 'TikTok current catalog name');

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?page=1&shop_id='.$ownShop->id.'&order_source_id=-9&view=summary')
            ->assertOk()
            ->assertJsonPath('data.total_items', 1)
            ->assertJsonPath('data.orders.0.id', $matchingOrder->id)
            ->assertJsonPath('data.orders.0.order_source_id', '-9')
            ->assertJsonPath(
                'data.orders.0.order_source_name',
                'Tiktok historical snapshot'
            );

        $this->assertArrayNotHasKey('pancake_order_source_id', $response->json('data.orders.0'));
        $this->assertArrayNotHasKey('pancake_order_source_name', $response->json('data.orders.0'));

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?page=1&shop_id='.$ownShop->id.'&order_source_id=missing&view=summary')
            ->assertOk()
            ->assertJsonPath('data.total_items', 0)
            ->assertJsonCount(0, 'data.orders');
    }

    public function test_cod_filter_matches_exact_amount_including_zero(): void
    {
        $shop = $this->createShop('Own shop');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $this->attachShop($manager, $shop);

        $zeroCodOrder = $this->createOrder($shop, [
            'pancake_order_id' => 'COD-ZERO',
            'cod' => 0,
            'note' => 'Số tiền ghi trong ghi chú',
        ]);
        $this->createOrder($shop, [
            'pancake_order_id' => 'COD-OTHER',
            'cod' => 125000,
        ]);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?page=1&shop_id='.$shop->id.'&cod=0&view=summary')
            ->assertOk()
            ->assertJsonPath('data.total_items', 1)
            ->assertJsonPath('data.total_revenue', 0)
            ->assertJsonPath('data.orders.0.id', $zeroCodOrder->id)
            ->assertJsonPath('data.orders.0.cod', 0)
            ->assertJsonPath('data.orders.0.note', 'Số tiền ghi trong ghi chú');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?page=1&shop_id='.$shop->id.'&cod=125000&view=summary')
            ->assertOk()
            ->assertJsonPath('data.total_items', 1)
            ->assertJsonPath('data.orders.0.pancake_order_id', 'COD-OTHER');
    }

    public function test_absent_or_blank_source_filter_preserves_existing_scoped_list_behavior(): void
    {
        $ownShop = $this->createShop('Own shop');
        $otherShop = $this->createShop('Other shop');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $this->attachShop($manager, $ownShop);

        $this->createOrder($ownShop, ['pancake_order_id' => 'OWN-1']);
        $this->createOrder($ownShop, ['pancake_order_id' => 'OWN-2']);
        $this->createOrder($otherShop, ['pancake_order_id' => 'OTHER-1']);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?page=1')
            ->assertOk()
            ->assertJsonPath('data.total_items', 2);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?page=1&order_source_id=')
            ->assertOk()
            ->assertJsonPath('data.total_items', 2);
    }

    public function test_source_filter_composes_with_search_legacy_filters_and_pagination(): void
    {
        $shop = $this->createShop('Own shop');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $this->attachShop($manager, $shop);

        $first = $this->createOrder($shop, [
            'pancake_order_id' => 'MATCH-1',
            'pancake_order_source_id' => 'source-a',
            'customer_phone' => '0901000001',
            'cod' => 100000,
            'pancake_full_data' => ['prepaid' => 10000],
            'status' => 3,
            'received_at_shop' => true,
        ]);
        $first->forceFill(['created_at' => '2026-09-10 10:00:00'])->save();
        $second = $this->createOrder($shop, [
            'pancake_order_id' => 'MATCH-2',
            'pancake_order_source_id' => 'source-a',
            'customer_phone' => '0901000002',
            'cod' => 200000,
            'pancake_full_data' => ['prepaid' => 20000],
            'status' => 3,
            'received_at_shop' => true,
        ]);
        $second->forceFill(['created_at' => '2026-09-09 10:00:00'])->save();
        $this->createOrder($shop, [
            'pancake_order_id' => 'WRONG-SOURCE',
            'pancake_order_source_id' => 'source-b',
            'customer_phone' => '0901000003',
            'status' => 3,
            'received_at_shop' => true,
        ]);
        $this->createOrder($shop, [
            'pancake_order_id' => 'WRONG-STATUS',
            'pancake_order_source_id' => 'source-a',
            'customer_phone' => '0901000004',
            'status' => 4,
            'received_at_shop' => true,
        ]);
        $this->createOrder($shop, [
            'pancake_order_id' => 'WRONG-SEARCH',
            'pancake_order_source_id' => 'source-a',
            'customer_phone' => '0801000005',
            'status' => 3,
            'received_at_shop' => true,
        ]);
        $outsideDate = $this->createOrder($shop, [
            'pancake_order_id' => 'WRONG-DATE',
            'pancake_order_source_id' => 'source-a',
            'customer_phone' => '0901000006',
            'status' => 3,
            'received_at_shop' => true,
        ]);
        $outsideDate->forceFill(['created_at' => '2026-08-31 23:59:59'])->save();

        $query = http_build_query([
            'page' => 1,
            'page_size' => 1,
            'shop_id' => $shop->id,
            'order_source_id' => 'source-a',
            'search' => '0901',
            'status' => 3,
            'received_at_shop' => 1,
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
            'view' => 'summary',
        ]);

        $summaryResponse = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?'.$query)
            ->assertOk()
            ->assertJsonPath('data.total_items', 2)
            ->assertJsonPath('data.per_page', 1)
            ->assertJsonPath('data.total_pages', 2)
            ->assertJsonPath('data.total_revenue', 330000)
            ->assertJsonPath('data.orders.0.id', $first->id);

        $defaultResponse = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?'.str_replace('&view=summary', '', $query))
            ->assertOk();

        $this->assertSame($defaultResponse->json('data.total_items'), $summaryResponse->json('data.total_items'));
        $this->assertSame($defaultResponse->json('data.per_page'), $summaryResponse->json('data.per_page'));
        $this->assertSame($defaultResponse->json('data.total_pages'), $summaryResponse->json('data.total_pages'));
        $this->assertEquals($defaultResponse->json('data.total_revenue'), $summaryResponse->json('data.total_revenue'));
    }

    public function test_summary_contract_is_compact_while_default_keeps_the_legacy_payload(): void
    {
        $ownShop = $this->createShop('Own shop');
        $otherShop = $this->createShop('Other shop');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $creator = $this->createUser('staff-sale', 'Creator');
        $this->attachShop($manager, $ownShop);

        $order = $this->createOrder($ownShop, [
            'pancake_order_id' => 'SUMMARY-ORDER',
            'pancake_order_source_id' => '-9',
            'pancake_order_source_name' => 'TikTok snapshot',
            'order_number_vtp' => 'VTP-123',
            'total_quantity' => 3,
            'cod' => 345000,
            'cash' => 100000,
            'customer_name' => 'Summary customer',
            'customer_phone' => '+84901234567',
            'customer_address' => '123 Example Street',
            'note' => 'Call before delivery',
            'status' => 4,
            'status_vtp' => 'DELIVERING',
            'user_creator_id' => $creator->pancake_user_id,
            'pancake_full_data' => ['prepaid' => 7440000, 'large' => ['legacy' => true]],
        ]);
        $province = Province::query()->create([
            'name' => 'Ho Chi Minh',
            'name_en' => 'Ho Chi Minh City',
            'new_id' => '79',
        ]);
        $order->update(['province_id' => $province->id]);
        $this->createOrder($otherShop, ['pancake_order_id' => 'MUST-NOT-LEAK']);

        $defaultResponse = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?page=1&page_size=30')
            ->assertOk()
            ->assertJsonPath('data.total_items', 1)
            ->assertJsonPath('data.orders.0.id', $order->id)
            ->assertJsonPath('data.orders.0.pancake_full_data.large.legacy', true);
        $this->assertArrayHasKey('pancake_full_data', $defaultResponse->json('data.orders.0'));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $summaryResponse = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?page=1&page_size=30&view=summary')
            ->assertOk()
            ->assertJsonPath('data.total_items', 1)
            ->assertJsonPath('data.orders.0.id', $order->id)
            ->assertJsonPath('data.orders.0.prepaid_amount', 7440000)
            ->assertJsonPath('data.orders.0.cash', 100000)
            ->assertJsonPath('data.orders.0.customer_phone', '+84901234567')
            ->assertJsonPath('data.orders.0.customer_address', '123 Example Street')
            ->assertJsonPath('data.orders.0.province_name', 'Ho Chi Minh')
            ->assertJsonPath('data.orders.0.note', 'Call before delivery')
            ->assertJsonPath('data.orders.0.order_source_id', '-9')
            ->assertJsonPath('data.orders.0.order_source_name', 'TikTok snapshot')
            ->assertJsonPath('data.orders.0.shop.id', $ownShop->id)
            ->assertJsonPath('data.orders.0.shop.name', 'Own shop')
            ->assertJsonPath('data.orders.0.user_creator.id', $creator->id)
            ->assertJsonPath('data.orders.0.user_creator.name', 'Creator');

        $summaryOrder = $summaryResponse->json('data.orders.0');
        $expectedKeys = [
            'cash',
            'cod',
            'created_at',
            'customer_address',
            'customer_name',
            'customer_phone',
            'id',
            'note',
            'order_number_vtp',
            'order_page_id',
            'order_page_name',
            'order_source_id',
            'order_source_name',
            'pancake_order_id',
            'prepaid_amount',
            'province_name',
            'shop',
            'shop_id',
            'status',
            'status_vtp',
            'total_quantity',
            'user_creator',
            'user_creator_id',
        ];
        $actualKeys = array_keys($summaryOrder);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);
        $this->assertSame(['id', 'name'], array_keys($summaryOrder['shop']));
        $this->assertSame(['id', 'name'], array_keys($summaryOrder['user_creator']));
        $this->assertArrayNotHasKey('pancake_full_data', $summaryOrder);

        $nullOrder = $this->createOrder($ownShop, [
            'pancake_order_id' => 'SUMMARY-NULL-VALUES',
            'pancake_full_data' => [],
            'customer_phone' => null,
            'customer_address' => null,
            'note' => null,
            'province_id' => null,
        ]);
        $nullSummary = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?page=1&page_size=30&view=summary')
            ->assertOk()
            ->json('data.orders');
        $nullSummaryOrder = collect($nullSummary)->firstWhere('id', $nullOrder->id);
        $this->assertSame(null, $nullSummaryOrder['customer_phone']);
        $this->assertSame(null, $nullSummaryOrder['customer_address']);
        $this->assertSame(null, $nullSummaryOrder['province_name']);
        $this->assertSame(null, $nullSummaryOrder['note']);

        $mainSelect = collect(DB::getQueryLog())->first(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'from "orders"') && str_contains($sql, ' limit ');
        });
        $this->assertNotNull($mainSelect);
        $this->assertStringNotContainsString('select "pancake_full_data",', strtolower($mainSelect['query']));
    }

    public function test_summary_preserves_staff_creator_or_care_scope(): void
    {
        $shop = $this->createShop('Own shop');
        $staff = $this->createUser('staff-sale', 'Scoped staff');
        $this->attachShop($staff, $shop);

        $createdOrder = $this->createOrder($shop, [
            'pancake_order_id' => 'CREATED-BY-STAFF',
            'user_creator_id' => $staff->pancake_user_id,
        ]);
        $caredOrder = $this->createOrder($shop, [
            'pancake_order_id' => 'CARED-BY-STAFF',
            'user_care_id' => $staff->pancake_user_id,
        ]);
        $this->createOrder($shop, ['pancake_order_id' => 'UNRELATED']);

        $response = $this->actingAs($staff, 'api')
            ->getJson('/api/v1/orders?page=1&view=summary')
            ->assertOk()
            ->assertJsonPath('data.total_items', 2);

        $this->assertEqualsCanonicalizing(
            [$createdOrder->id, $caredOrder->id],
            collect($response->json('data.orders'))->pluck('id')->all()
        );
    }

    public function test_page_summary_and_exact_filter_preserve_shop_scope_and_legacy_response(): void
    {
        $shop = $this->createShop('Own');
        $other = $this->createShop('Other');
        $manager = $this->createUser('manager-sale', 'Manager');
        $this->attachShop($manager, $shop);
        $attributes = [
            'pancake_order_page_id' => '115624128265497',
            'pancake_order_page_name' => 'Hương Chất TV',
            'pancake_order_source_id' => '-1',
            'pancake_order_source_name' => 'Facebook',
        ];
        $order = $this->createOrder($shop, $attributes);
        $this->createOrder($other, $attributes);
        $this->createOrder($shop, ['pancake_order_page_id' => '1156241282654970']);
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?page=1&view=summary&order_page_id=115624128265497')
            ->assertOk()->assertJsonPath('data.total_items', 1)
            ->assertJsonPath('data.orders.0.id', $order->id)
            ->assertJsonPath('data.orders.0.order_page_id', '115624128265497')
            ->assertJsonPath('data.orders.0.order_page_name', 'Hương Chất TV')
            ->assertJsonPath('data.orders.0.order_source_id', '-1')
            ->assertJsonPath('data.orders.0.order_source_name', 'Facebook')
            ->assertJsonMissingPath('data.orders.0.pancake_full_data');
        $this->getJson('/api/v1/orders?page=1&order_page_id=115624128265497')
            ->assertOk()->assertJsonPath('data.total_items', 1)
            ->assertJsonStructure(['data' => ['orders' => [['pancake_full_data', 'order_source_id', 'order_source_name']]]])
            ->assertJsonMissingPath('data.orders.0.order_page_id');
        $this->getJson('/api/v1/orders?page=1&view=summary&shop_id='.$other->id.'&order_page_id=115624128265497')
            ->assertForbidden();
    }

    public function test_zalo_page_filter_composes_with_source_and_pagination_and_blank_is_unfiltered(): void
    {
        $shop = $this->createShop('Own');
        $admin = $this->createUser('admin', 'Admin');
        foreach (['-8', '-8', '-1'] as $source) {
            $this->createOrder($shop, [
                'pancake_order_page_id' => 'pzl_695112902870160686',
                'pancake_order_source_id' => $source,
            ]);
        }
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/orders?page=2&page_size=1&view=summary&order_page_id=pzl_695112902870160686&order_source_id=-8')
            ->assertOk()->assertJsonPath('data.total_items', 2)
            ->assertJsonPath('data.current_page', 2)->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.order_page_id', 'pzl_695112902870160686');
        $this->getJson('/api/v1/orders?page=1&view=summary&order_page_id=missing')
            ->assertOk()->assertJsonPath('data.total_items', 0);
        foreach (['', '&order_page_id=', '&order_page_id=%20%20'] as $filter) {
            $this->getJson('/api/v1/orders?page=1&view=summary'.$filter)
                ->assertOk()->assertJsonPath('data.total_items', 3);
        }
        $this->getJson('/api/v1/orders?page=1&order_page_id[]=invalid')->assertUnprocessable();
    }

    public function test_page_options_use_latest_visible_snapshot_distinct_stable_and_no_raw_or_external_calls(): void
    {
        Http::fake();
        $shop = $this->createShop('Own');
        $admin = $this->createUser('admin', 'Admin');
        $old = $this->createOrder($shop, ['pancake_order_page_id' => 'page-1', 'pancake_order_page_name' => 'Old']);
        $old->forceFill(['created_at' => '2026-01-01'])->save();
        // created_at, not insertion order or MAX(name), chooses the snapshot.
        $latest = $this->createOrder($shop, ['pancake_order_page_id' => 'page-1', 'pancake_order_page_name' => 'Alpha']);
        $latest->forceFill(['created_at' => '2026-09-01'])->save();
        $lateImport = $this->createOrder($shop, ['pancake_order_page_id' => 'page-1', 'pancake_order_page_name' => 'ZZZ']);
        $lateImport->forceFill(['created_at' => '2026-02-01'])->save();
        $this->createOrder($shop, ['pancake_order_page_id' => 'page-2', 'pancake_order_page_name' => 'Alpha']);
        $this->createOrder($shop, ['pancake_order_page_id' => 'pzl_123', 'pancake_order_page_name' => 'Hương Chất Group']);
        $this->createOrder($shop, ['pancake_order_page_id' => 'unnamed', 'pancake_order_page_name' => ' ']);
        $this->createOrder($shop, ['pancake_order_page_id' => ' ', 'pancake_order_page_name' => 'Invalid']);
        $this->createOrder($shop, ['pancake_order_page_name' => 'No ID']);
        $deleted = $this->createOrder($shop, ['pancake_order_page_id' => 'deleted', 'pancake_order_page_name' => 'Deleted']);
        $deleted->delete();
        $this->createOrder($this->createShop('Other'), ['pancake_order_page_id' => 'page-1', 'pancake_order_page_name' => 'Other shop']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($admin, 'api')->getJson('/api/v1/order-pages?shop_id='.$shop->id)
            ->assertOk()->assertExactJson(['success' => true, 'data' => [
                ['id' => 'page-1', 'name' => 'Alpha'],
                ['id' => 'page-2', 'name' => 'Alpha'],
                ['id' => 'pzl_123', 'name' => 'Hương Chất Group'],
                ['id' => 'unnamed', 'name' => 'unnamed'],
            ]]);
        $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));
        $this->assertStringNotContainsString('pancake_full_data', $sql);
        $this->assertStringNotContainsString('pancake_order_sources', $sql);
        Http::assertNothingSent();
    }

    public function test_page_options_require_authorized_shop_and_keep_admin_global(): void
    {
        $shop = $this->createShop('Own');
        $other = $this->createShop('Other');
        $manager = $this->createUser('manager-cskh', 'Manager');
        $this->attachShop($manager, $shop);
        $this->actingAs($manager, 'api')->getJson('/api/v1/order-pages')->assertUnprocessable();
        $this->getJson('/api/v1/order-pages?shop_id=0')->assertUnprocessable();
        $this->getJson('/api/v1/order-pages?shop_id='.$other->id)->assertForbidden();
        $this->getJson('/api/v1/order-pages?shop_id='.$shop->id)->assertOk()->assertJsonCount(0, 'data');
        $admin = $this->createUser('admin', 'Admin');
        $this->actingAs($admin, 'api')->getJson('/api/v1/order-pages?shop_id='.$other->id)->assertOk();
    }

    public function test_staff_options_and_page_filter_do_not_leak_other_employees_or_their_newer_names(): void
    {
        $shop = $this->createShop('Own');
        $staff = $this->createUser('staff-sale', 'Staff');
        $this->attachShop($staff, $shop);
        $own = $this->createOrder($shop, [
            'pancake_order_page_id' => 'same', 'pancake_order_page_name' => 'Own snapshot',
            'user_creator_id' => $staff->pancake_user_id,
        ]);
        $own->forceFill(['created_at' => '2026-01-01'])->save();
        $this->createOrder($shop, [
            'pancake_order_page_id' => 'care', 'pancake_order_page_name' => 'Care snapshot',
            'user_care_id' => $staff->pancake_user_id,
        ]);
        $this->createOrder($shop, ['pancake_order_page_id' => 'same', 'pancake_order_page_name' => 'Hidden newer name']);
        $this->createOrder($shop, ['pancake_order_page_id' => 'hidden', 'pancake_order_page_name' => 'Hidden page']);
        $this->actingAs($staff, 'api')->getJson('/api/v1/order-pages?shop_id='.$shop->id)
            ->assertOk()->assertExactJson(['success' => true, 'data' => [
                ['id' => 'care', 'name' => 'Care snapshot'],
                ['id' => 'same', 'name' => 'Own snapshot'],
            ]]);
        $this->getJson('/api/v1/orders?page=1&view=summary&order_page_id=same')
            ->assertOk()->assertJsonPath('data.total_items', 1)->assertJsonPath('data.orders.0.id', $own->id);
        $this->getJson('/api/v1/orders?page=1&view=summary&order_page_id=hidden')
            ->assertOk()->assertJsonPath('data.total_items', 0);
    }

    public function test_page_options_break_equal_created_at_ties_by_order_id(): void
    {
        $shop = $this->createShop('Own');
        $admin = $this->createUser('admin', 'Admin');
        foreach (['First', 'Second'] as $name) {
            $order = $this->createOrder($shop, ['pancake_order_page_id' => 'page', 'pancake_order_page_name' => $name]);
            $order->forceFill(['created_at' => '2026-01-01'])->save();
        }
        $this->actingAs($admin, 'api')->getJson('/api/v1/order-pages?shop_id='.$shop->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Second');
    }

    private function createShop(string $name): Shop
    {
        return Shop::query()->create([
            'name' => $name,
            'pancake_shop_id' => 'pancake-'.$name,
        ]);
    }

    private function createUser(string $roleSlug, string $name): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => $roleSlug],
            ['name' => $roleSlug]
        );

        return User::query()->create([
            'role_id' => $role->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'password' => 'password',
            'pancake_user_id' => 'pancake-user-'.$name,
        ]);
    }

    private function attachShop(User $user, Shop $shop): void
    {
        $user->shops()->attach($shop->id, ['is_manager' => false]);
    }

    private function createSource(Shop $shop, string $externalId, string $name, array $attributes = []): PancakeOrderSource
    {
        return PancakeOrderSource::query()->create(array_merge([
            'shop_id' => $shop->id,
            'external_source_id' => $externalId,
            'name' => $name,
            'is_active' => true,
        ], $attributes));
    }

    private function createOrder(Shop $shop, array $attributes = []): Order
    {
        return Order::query()->create(array_merge([
            'shop_id' => $shop->id,
            'pancake_order_id' => 'order-'.uniqid(),
            'order_number_vtp' => null,
            'total_quantity' => 1,
            'cod' => 100000,
            'cash' => 0,
            'note' => null,
            'status' => 3,
            'status_vtp' => null,
            'pancake_full_data' => [],
            'received_at_shop' => false,
            'customer_name' => 'Customer',
            'customer_phone' => '0900000000',
            'customer_address' => 'Address',
        ], $attributes));
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('pancake_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('shops', function (Blueprint $table): void {
            $table->id();
            $table->string('pancake_shop_id')->nullable();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('shop_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_manager')->default(false);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('province_id')->nullable();
            $table->string('pancake_order_id');
            $table->string('pancake_order_source_id')->nullable()->index();
            $table->string('pancake_order_source_name')->nullable();
            $table->string('pancake_order_page_id')->nullable();
            $table->string('pancake_order_page_name')->nullable();
            $table->string('pancake_customer_id')->nullable();
            $table->string('user_creator_id')->nullable();
            $table->string('user_care_id')->nullable();
            $table->string('user_assigning_seller_id')->nullable();
            $table->string('order_number_vtp')->nullable();
            $table->integer('total_quantity')->nullable();
            $table->decimal('cod', 15, 2)->default(0);
            $table->decimal('cash', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_address')->nullable();
            $table->integer('status')->default(3);
            $table->string('status_vtp')->nullable();
            $table->json('pancake_full_data')->nullable();
            $table->boolean('received_at_shop')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('provinces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('name_en');
            $table->string('new_id');
            $table->timestamps();
        });

        Schema::create('pancake_order_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('external_source_id');
            $table->string('name');
            $table->string('parent_external_source_id')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('source_inserted_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'external_source_id']);
            $table->index(['shop_id', 'is_active']);
        });
    }
}
