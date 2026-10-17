<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DirectReadIdorHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'direct_read_idor_testing',
            'database.connections.direct_read_idor_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('direct_read_idor_testing');
        DB::setDefaultConnection('direct_read_idor_testing');
        $this->createSchema();
    }

    public function test_user_detail_and_user_orders_use_exact_shared_shop_scope(): void
    {
        $shop11 = $this->createShop(11);
        $shop12 = $this->createShop(12);
        $manager = $this->createUser('manager-cskh', 'Manager M1');
        $zeroShopManager = $this->createUser('manager-cskh', 'Zero Shop M0');
        $admin = $this->createUser('admin', 'Admin A');
        $onlyShop11 = $this->createUser('staff-cskh', 'Only shop 11');
        $onlyShop12 = $this->createUser('staff-cskh', 'Only shop 12');
        $sharedTarget = $this->createUser('staff-cskh', 'Shared target');

        $this->attach($manager, $shop11);
        $this->attach($onlyShop11, $shop11);
        $this->attach($onlyShop12, $shop12);
        $this->attach($sharedTarget, $shop11);
        $this->attach($sharedTarget, $shop12);
        $this->grantPermission($manager, 'list-staff');
        $this->grantPermission($zeroShopManager, 'list-staff');
        $this->grantPermission($admin, 'list-staff');

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/users/{$onlyShop11->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $onlyShop11->id)
            ->assertJsonPath('data.email', $onlyShop11->email)
            ->assertJsonPath('data.role.id', $onlyShop11->role_id)
            ->assertJsonPath('data.shops.0.id', $shop11->id);
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/users/{$onlyShop12->id}")
            ->assertForbidden();
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/users/{$sharedTarget->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $sharedTarget->id);
        $this->actingAs($zeroShopManager, 'api')
            ->getJson("/api/v1/users/{$onlyShop11->id}")
            ->assertForbidden();
        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/users/{$onlyShop12->id}")
            ->assertOk();

        $order11 = $this->createOrder($shop11, [
            'pancake_customer_id' => 'USER-ORDER-CUSTOMER',
            'user_creator_id' => $sharedTarget->pancake_user_id,
        ]);
        $order12 = $this->createOrder($shop12, [
            'pancake_customer_id' => 'USER-ORDER-CUSTOMER',
            'user_creator_id' => $sharedTarget->pancake_user_id,
        ]);

        $managerResponse = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/users/{$sharedTarget->pancake_user_id}/orders")
            ->assertOk();
        $this->assertSame(
            [$order11->id],
            collect($managerResponse->json('data.items'))->pluck('id')->sort()->values()->all()
        );

        $adminResponse = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/users/{$sharedTarget->pancake_user_id}/orders")
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$order11->id, $order12->id],
            collect($adminResponse->json('data.items'))->pluck('id')->all()
        );
    }

    public function test_shop_orders_and_customers_authorize_the_route_shop_and_keep_staff_record_scope(): void
    {
        $shop11 = $this->createShop(11);
        $shop12 = $this->createShop(12);
        $manager = $this->createUser('manager-cskh', 'Manager M1');
        $staffSale = $this->createUser('staff-sale', 'Staff sale S2');
        $admin = $this->createUser('admin', 'Admin A');
        $zeroShopManager = $this->createUser('manager-cskh', 'Zero Shop M0');
        $otherUser = $this->createUser('staff-sale', 'Other sale');
        $this->attach($manager, $shop11);
        $this->attach($staffSale, $shop11);

        $managerOrder = $this->createOrder($shop11, ['user_creator_id' => $otherUser->pancake_user_id]);
        $otherShopOrder = $this->createOrder($shop12, ['user_creator_id' => $otherUser->pancake_user_id]);
        $staffOrder = $this->createOrder($shop11, ['user_creator_id' => $staffSale->pancake_user_id]);
        $staffCustomer = $this->createCustomer($shop11, [
            'pancake_customer_id' => 'SHOP-STAFF-CUSTOMER',
            'assigned_user_id' => $staffSale->pancake_user_id,
        ]);
        $otherCustomer = $this->createCustomer($shop11, [
            'pancake_customer_id' => 'SHOP-OTHER-CUSTOMER',
            'assigned_user_id' => $otherUser->pancake_user_id,
        ]);

        $managerOrders = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/shops/{$shop11->id}/orders")
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$managerOrder->id, $staffOrder->id],
            collect($managerOrders->json('data.orders'))->pluck('id')->all()
        );
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/shops/{$shop12->id}/orders")
            ->assertForbidden();
        $this->actingAs($zeroShopManager, 'api')
            ->getJson("/api/v1/shops/{$shop11->id}/orders")
            ->assertForbidden();
        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/shops/{$shop12->id}/orders")
            ->assertOk()
            ->assertJsonFragment(['id' => $otherShopOrder->id]);

        $staffOrders = $this->actingAs($staffSale, 'api')
            ->getJson("/api/v1/shops/{$shop11->id}/orders")
            ->assertOk();
        $this->assertSame(
            [$staffOrder->id],
            collect($staffOrders->json('data.orders'))->pluck('id')->all()
        );

        $managerCustomers = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/shops/{$shop11->id}/customers")
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$staffCustomer->id, $otherCustomer->id],
            collect($managerCustomers->json('data.customers'))->pluck('id')->all()
        );
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/shops/{$shop12->id}/customers")
            ->assertForbidden();
        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/shops/{$shop12->id}/customers")
            ->assertOk();

        $staffCustomers = $this->actingAs($staffSale, 'api')
            ->getJson("/api/v1/shops/{$shop11->id}/customers")
            ->assertOk();
        $this->assertSame(
            [$staffCustomer->id],
            collect($staffCustomers->json('data.customers'))->pluck('id')->all()
        );
    }

    public function test_customer_orders_scope_shared_customer_identity_to_authorized_shop_and_preserve_staff_scope(): void
    {
        $shop11 = $this->createShop(11);
        $shop12 = $this->createShop(12);
        $manager = $this->createUser('manager-cskh', 'Manager M1');
        $staffSale = $this->createUser('staff-sale', 'Staff sale S2');
        $otherUser = $this->createUser('staff-sale', 'Other sale');
        $admin = $this->createUser('admin', 'Admin A');
        $this->attach($manager, $shop11);
        $this->attach($staffSale, $shop11);
        $this->grantPermission($manager, 'list-customer');
        $this->grantPermission($staffSale, 'list-customer');
        $this->grantPermission($admin, 'list-customer');

        $order11 = $this->createOrder($shop11, [
            'pancake_customer_id' => 'MULTI-SHOP-CUSTOMER',
            'user_creator_id' => $otherUser->pancake_user_id,
        ]);
        $order12 = $this->createOrder($shop12, [
            'pancake_customer_id' => 'MULTI-SHOP-CUSTOMER',
            'user_creator_id' => $otherUser->pancake_user_id,
        ]);
        $staffOrder = $this->createOrder($shop11, [
            'pancake_customer_id' => 'MULTI-SHOP-CUSTOMER',
            'user_creator_id' => $staffSale->pancake_user_id,
        ]);

        $managerResponse = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/customers/MULTI-SHOP-CUSTOMER/orders')
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$order11->id, $staffOrder->id],
            collect($managerResponse->json('data.data'))->pluck('id')->all()
        );

        $staffResponse = $this->actingAs($staffSale, 'api')
            ->getJson('/api/v1/customers/MULTI-SHOP-CUSTOMER/orders')
            ->assertOk();
        $this->assertSame(
            [$staffOrder->id],
            collect($staffResponse->json('data.data'))->pluck('id')->all()
        );

        $adminResponse = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/customers/MULTI-SHOP-CUSTOMER/orders')
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$order11->id, $order12->id, $staffOrder->id],
            collect($adminResponse->json('data.data'))->pluck('id')->all()
        );
    }

    public function test_customer_orders_preserve_the_legacy_v1_detail_modal_contract(): void
    {
        $shop11 = $this->createShop(11);
        $shop12 = $this->createShop(12);
        $manager = $this->createUser('manager-cskh', 'Manager M1');
        $localCreator = $this->createUser('staff-sale', 'Local creator');
        $otherShopManager = $this->createUser('manager-cskh', 'Manager M2');
        $this->attach($manager, $shop11);
        $this->attach($otherShopManager, $shop12);
        $this->grantPermission($manager, 'list-customer');
        $this->grantPermission($otherShopManager, 'list-customer');

        $customerId = 'V1-DETAIL-CUSTOMER';
        $firstOrder = $this->createOrder($shop11, [
            'pancake_customer_id' => $customerId,
            'user_creator_id' => $localCreator->pancake_user_id,
            'cod' => 345000,
            'pancake_full_data' => [
                'inserted_at' => '2026-08-28T08:30:00Z',
                'creator' => ['id' => $localCreator->pancake_user_id, 'name' => 'Pancake creator'],
                'items' => [
                    ['variation_info' => ['name' => 'Product one', 'images' => ['https://cdn.example.test/one.jpg']], 'quantity' => 1],
                    ['variation_info' => ['name' => 'Product two', 'images' => []], 'quantity' => 2],
                ],
            ],
        ]);
        $secondOrder = $this->createOrder($shop11, [
            'pancake_customer_id' => $customerId,
            'cod' => 120000,
            'pancake_full_data' => [
                'inserted_at' => '2026-08-27T08:30:00Z',
                'items' => [],
            ],
        ]);
        $otherShopOrder = $this->createOrder($shop12, [
            'pancake_customer_id' => $customerId,
            'pancake_full_data' => [
                'inserted_at' => '2026-08-26T08:30:00Z',
                'items' => [['variation_info' => ['name' => 'Other shop product']]],
            ],
        ]);

        $response = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/customers/{$customerId}/orders?page_number=1")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_items', 2)
            ->assertJsonPath('data.total_entries', 2);

        $orders = collect($response->json('data.data'))->keyBy('id');
        $first = $orders->get($firstOrder->id);
        $second = $orders->get($secondOrder->id);

        $this->assertCount(2, $orders);
        $this->assertArrayNotHasKey($otherShopOrder->id, $orders->all());
        $this->assertSame(345000, (int) $first['cod']);
        $this->assertSame('2026-08-28T08:30:00Z', $first['inserted_at']);
        $this->assertSame('Pancake creator', $first['creator']['name']);
        $this->assertSame('Product one', $first['items'][0]['variation_info']['name']);
        $this->assertSame('Product two', $first['items'][1]['variation_info']['name']);
        $this->assertSame(2, $first['items'][1]['quantity']);
        $this->assertSame([], $second['items']);
        $this->assertNull($second['creator']);
        $this->assertArrayHasKey('pancake_full_data', $first);
        $this->assertArrayHasKey('user_creator', $first);

        $this->actingAs($otherShopManager, 'api')
            ->getJson("/api/v1/customers/{$customerId}/orders")
            ->assertOk()
            ->assertJsonPath('data.total_entries', 1)
            ->assertJsonPath('data.data.0.id', $otherShopOrder->id)
            ->assertJsonMissing(['id' => $firstOrder->id]);
    }

    public function test_list_endpoints_reject_tampered_shop_ids_instead_of_silently_broadening_scope(): void
    {
        $shop11 = $this->createShop(11);
        $shop12 = $this->createShop(12);
        $manager = $this->createUser('manager-cskh', 'Manager M1');
        $this->attach($manager, $shop11);
        $this->grantPermission($manager, 'list-customer');
        $this->grantPermission($manager, 'view-chance');

        $this->createOrder($shop11);
        $this->createOrder($shop12);
        $this->createCustomer($shop11);
        $this->createCustomer($shop12);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?shop_id='.$shop12->id)
            ->assertForbidden();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders/chance?shop_id='.$shop12->id)
            ->assertForbidden();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/customers?shop_id='.$shop12->id)
            ->assertForbidden();

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/orders?page=1')
            ->assertOk()
            ->assertJsonPath('data.total_items', 1);
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/customers?page=1')
            ->assertOk()
            ->assertJsonPath('data.total_items', 1);
    }

    public function test_customer_care_reads_require_feature_shop_and_staff_assignment_and_fail_closed_for_bad_links(): void
    {
        $shop11 = $this->createShop(11);
        $shop12 = $this->createShop(12);
        $manager = $this->createUser('manager-cskh', 'Manager M1');
        $managerSale = $this->createUser('manager-sale', 'Manager sale M2');
        $staff = $this->createUser('staff-cskh', 'Staff CSKH S1');
        $otherStaff = $this->createUser('staff-cskh', 'Other CSKH');
        $admin = $this->createUser('admin', 'Admin A');
        $zeroShopManager = $this->createUser('manager-cskh', 'Zero Shop M0');
        $this->attach($manager, $shop11);
        $this->attach($managerSale, $shop11);
        $this->attach($staff, $shop11);

        $managerOrder = $this->createOrder($shop11, [
            'pancake_customer_id' => 'MANAGER-CARE-CUSTOMER',
            'pancake_order_id' => 'MANAGER-CARE-ORDER',
        ]);
        $managerCare = $this->createCare($shop11, [
            'pancake_customer_id' => 'MANAGER-CARE-CUSTOMER',
            'pancake_order_id' => $managerOrder->pancake_order_id,
        ]);
        $otherShopCare = $this->createCare($shop12, [
            'pancake_customer_id' => 'MANAGER-CARE-CUSTOMER',
            'pancake_order_id' => 'OTHER-SHOP-CARE-ORDER',
        ]);

        $managerHistory = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/customer-cares/{$managerCare->id}/histories")
            ->assertOk();
        $this->assertSame(
            [$managerCare->id],
            collect($managerHistory->json('data'))->pluck('id')->all()
        );
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/customer-cares/{$otherShopCare->id}/histories")
            ->assertForbidden();
        $this->actingAs($managerSale, 'api')
            ->getJson("/api/v1/customer-cares/{$managerCare->id}/histories")
            ->assertForbidden();
        $this->actingAs($zeroShopManager, 'api')
            ->getJson("/api/v1/customer-cares/{$managerCare->id}/histories")
            ->assertForbidden();

        $staffOrder = $this->createOrder($shop11, [
            'pancake_customer_id' => 'STAFF-CARE-CUSTOMER',
            'pancake_order_id' => 'STAFF-CARE-ORDER',
            'user_care_id' => $staff->pancake_user_id,
        ]);
        $staffCare = $this->createCare($shop11, [
            'pancake_customer_id' => 'STAFF-CARE-CUSTOMER',
            'pancake_order_id' => $staffOrder->pancake_order_id,
        ]);
        $this->createAssignment($staffCare, $staff, $staffOrder);
        $otherStaffCare = $this->createCare($shop11, [
            'pancake_customer_id' => 'STAFF-CARE-CUSTOMER',
            'pancake_order_id' => 'OTHER-STAFF-CARE-ORDER',
        ]);
        $this->createAssignment($otherStaffCare, $otherStaff, $staffOrder);

        $staffHistory = $this->actingAs($staff, 'api')
            ->getJson("/api/v1/customer-cares/{$staffCare->id}/histories")
            ->assertOk();
        $this->assertSame(
            [$staffCare->id],
            collect($staffHistory->json('data'))->pluck('id')->all()
        );
        $this->actingAs($staff, 'api')
            ->getJson("/api/v1/customer-cares/{$otherStaffCare->id}/histories")
            ->assertForbidden();
        $this->actingAs($staff, 'api')
            ->getJson("/api/v1/customer-cares/{$staffCare->id}/orders")
            ->assertOk()
            ->assertJsonFragment(['id' => $staffOrder->id]);
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/customer-cares/{$managerCare->id}/orders")
            ->assertOk()
            ->assertJsonFragment(['id' => $managerOrder->id]);

        $blankCustomerCare = $this->createCare($shop11, [
            'pancake_customer_id' => '   ',
        ]);
        $this->createOrder($shop11, ['pancake_customer_id' => '   ']);
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/customer-cares/{$blankCustomerCare->id}/orders")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $corruptCare = $this->createCare($shop11, [
            'pancake_customer_id' => 'CORRUPT-CUSTOMER',
            'pancake_order_id' => 'CORRUPT-ORDER',
        ]);
        $this->createOrder($shop12, [
            'pancake_customer_id' => 'CORRUPT-CUSTOMER',
            'pancake_order_id' => 'CORRUPT-ORDER',
        ]);
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/customer-cares/{$corruptCare->id}/orders")
            ->assertStatus(409);

        $adminHistory = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/customer-cares/{$managerCare->id}/histories")
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$managerCare->id, $otherShopCare->id],
            collect($adminHistory->json('data'))->pluck('id')->all()
        );
    }

    public function test_assigned_staff_reads_full_customer_history_only_in_the_care_shop(): void
    {
        [$shop, $otherShop, $staff, $otherStaff, $care, $source] = $this->createPurchaseHistoryContext();
        // Even membership in both shops must not widen this appointment's history.
        $this->attach($staff, $otherShop);
        $expected = [$source->id];
        foreach ([0, 1, 2, 4] as $status) {
            $expected[] = $this->createOrder($shop, [
                'pancake_customer_id' => $care->pancake_customer_id,
                'user_creator_id' => $status === 0 ? $staff->pancake_user_id : $otherStaff->pancake_user_id,
                'user_care_id' => $otherStaff->pancake_user_id,
                'status' => $status,
            ])->id;
        }
        $foreignOrder = $this->createOrder($otherShop, [
            'pancake_customer_id' => $care->pancake_customer_id,
            'user_creator_id' => $staff->pancake_user_id,
        ]);
        $differentCustomer = $this->createOrder($shop, ['user_creator_id' => $staff->pancake_user_id]);
        $deleted = $this->createOrder($shop, ['pancake_customer_id' => $care->pancake_customer_id]);
        $deleted->delete();
        $ordersBefore = DB::table('orders')->orderBy('id')->get()->toJson();
        $assignmentsBefore = DB::table('customer_care_assignments')->get()->toJson();
        $membershipsBefore = DB::table('shop_users')->get()->toJson();

        $response = $this->actingAs($staff, 'api')
            ->getJson("/api/v1/customer-cares/{$care->id}/orders")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure(['success', 'data' => [['id', 'shop_id', 'pancake_customer_id',
                'pancake_order_id', 'status', 'cod', 'total_quantity', 'shop', 'user_creator',
                'user_care', 'user_assigning']]]);
        $this->assertEqualsCanonicalizing($expected, collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(['success', 'data'], array_keys($response->json()));

        // Request parameters cannot replace the backend-verified customer/shop.
        $tampered = $this->getJson("/api/v1/customer-cares/{$care->id}/orders?shop_id={$otherShop->id}"
            ."&customer_id={$differentCustomer->pancake_customer_id}"
            ."&pancake_customer_id={$foreignOrder->pancake_customer_id}")
            ->assertOk();
        $this->assertEqualsCanonicalizing($expected, collect($tampered->json('data'))->pluck('id')->all());
        $this->assertSame($ordersBefore, DB::table('orders')->orderBy('id')->get()->toJson());
        $this->assertSame($assignmentsBefore, DB::table('customer_care_assignments')->get()->toJson());
        $this->assertSame($membershipsBefore, DB::table('shop_users')->get()->toJson());
    }

    #[DataProvider('deniedPurchaseHistoryCases')]
    public function test_purchase_history_cannot_bypass_care_authorization(string $scenario): void
    {
        [$shop, $otherShop, $staff, $otherStaff, $care, $source, $assignment] = $this->createPurchaseHistoryContext();
        // Owning the Order never substitutes for permission to read the care.
        $source->update(['user_creator_id' => $staff->pancake_user_id, 'user_care_id' => $staff->pancake_user_id]);
        switch ($scenario) {
            case 'no_assignment':
                $assignment->delete();
                break;
            case 'other_assignee':
                $assignment->update(['assignee_user_id' => $otherStaff->id]);
                break;
            case 'reclaimed':
                $assignment->update(['status' => CustomerCareAssignment::STATUS_RECLAIMED]);
                break;
            case 'wrong_assignment_shop':
                $assignment->update(['shop_id' => $otherShop->id]);
                break;
            case 'multiple_active':
                $this->createAssignment($care, $staff, $source);
                break;
            case 'different_care':
                $assignment->update(['customer_care_id' => $this->createCare($shop)->id]);
                break;
            case 'legacy_cannot_override_active_assignee':
                $care->update(['user_care_id' => $staff->pancake_user_id]);
                $assignment->update(['assignee_user_id' => $otherStaff->id]);
                break;
            case 'no_shop_membership':
                $staff->shops()->detach($shop->id);
                $this->attach($staff, $otherShop);
                break;
            case 'url_tampering':
                $care = $this->createCare($shop);
                $this->createAssignment($care, $otherStaff, $source);
                break;
        }

        $this->actingAs($staff, 'api')
            ->getJson("/api/v1/customer-cares/{$care->id}/orders")
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('data');
    }

    public static function deniedPurchaseHistoryCases(): array
    {
        return array_map(static fn ($scenario) => [$scenario], [
            'no_assignment', 'other_assignee', 'reclaimed', 'wrong_assignment_shop',
            'multiple_active', 'different_care', 'legacy_cannot_override_active_assignee',
            'no_shop_membership', 'url_tampering',
        ]);
    }

    #[DataProvider('legacyPurchaseHistoryCases')]
    public function test_legacy_care_ownership_still_grants_same_shop_customer_history(string $ownership): void
    {
        [$shop, , $staff, , $care, $source, $assignment] = $this->createPurchaseHistoryContext();
        $assignment->update(['status' => CustomerCareAssignment::STATUS_RECLAIMED]);
        if ($ownership === 'pivot') {
            DB::table('customer_assigneds')->insert([
                'customer_care_id' => $care->id,
                'pancake_user_id' => $staff->pancake_user_id,
            ]);
        } else {
            $care->update([$ownership => $staff->pancake_user_id]);
        }
        $this->actingAs($staff, 'api')
            ->getJson("/api/v1/customer-cares/{$care->id}/orders")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $source->id);
    }

    public static function legacyPurchaseHistoryCases(): array
    {
        return [['user_creator_id'], ['user_care_id'], ['user_assigning_seller_id'], ['pivot']];
    }

    #[DataProvider('blankPurchaseHistoryCustomerIds')]
    public function test_blank_customer_identity_never_groups_unrelated_purchase_history(?string $customerId): void
    {
        [, , $staff, , $care] = $this->createPurchaseHistoryContext(['pancake_customer_id' => $customerId]);
        $this->actingAs($staff, 'api')
            ->getJson("/api/v1/customer-cares/{$care->id}/orders")
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => []]);
    }

    public static function blankPurchaseHistoryCustomerIds(): array
    {
        return [[null], [''], ['   ']];
    }

    public function test_imported_care_without_matching_orders_does_not_join_purchase_history_by_phone(): void
    {
        [$shop, , $staff, , $care, $source, $assignment] = $this->createPurchaseHistoryContext();
        $source->update(['customer_phone' => '0900000000']);
        $care->update(['pancake_customer_id' => 'IMPORT-123', 'pancake_order_id' => null,
            'customer_phones' => '0900000000']);
        $assignment->update(['source_type' => CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY,
            'source_id' => 123]);
        $this->actingAs($staff, 'api')
            ->getJson("/api/v1/customer-cares/{$care->id}/orders")
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => []]);
    }

    public function test_customer_without_orders_returns_empty_purchase_history(): void
    {
        [, , $staff, , $care] = $this->createPurchaseHistoryContext();
        $care->update(['pancake_customer_id' => 'NO-PURCHASES']);
        $this->actingAs($staff, 'api')
            ->getJson("/api/v1/customer-cares/{$care->id}/orders")
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => []]);
    }

    #[DataProvider('purchaseHistoryManagerRoles')]
    public function test_purchase_history_preserves_admin_and_manager_shop_scope(string $role): void
    {
        [$shop, $otherShop, , , $care, $source] = $this->createPurchaseHistoryContext();
        $actor = $this->createUser($role, 'History reader');
        if ($role === 'manager-cskh') {
            $this->attach($actor, $shop);
            $this->attach($actor, $otherShop);
        }
        $other = $this->createOrder($otherShop, ['pancake_customer_id' => $care->pancake_customer_id]);
        $response = $this->actingAs($actor, 'api')
            ->getJson("/api/v1/customer-cares/{$care->id}/orders")
            ->assertOk()->assertJsonPath('success', true);
        $this->assertEqualsCanonicalizing(
            $role === 'admin' ? [$source->id, $other->id] : [$source->id],
            collect($response->json('data'))->pluck('id')->all()
        );
        if ($role === 'manager-cskh') {
            $outsideManager = $this->createUser($role, 'Manager in another shop');
            $this->attach($outsideManager, $otherShop);
            $this->actingAs($outsideManager, 'api')
                ->getJson("/api/v1/customer-cares/{$care->id}/orders")->assertForbidden();
        }
    }

    public static function purchaseHistoryManagerRoles(): array
    {
        return [['admin'], ['manager-cskh']];
    }

    public function test_purchase_history_keeps_soft_delete_and_source_shop_conflict_guards(): void
    {
        [$shop, $otherShop, $staff, , $care, $source] = $this->createPurchaseHistoryContext();
        $source->delete();
        $this->actingAs($staff, 'api')
            ->getJson("/api/v1/customer-cares/{$care->id}/orders")
            ->assertOk()->assertExactJson(['success' => true, 'data' => []]);

        $this->createOrder($otherShop, ['pancake_order_id' => $care->pancake_order_id,
            'pancake_customer_id' => $care->pancake_customer_id]);
        $this->getJson("/api/v1/customer-cares/{$care->id}/orders")
            ->assertStatus(409)->assertJsonPath('success', false);
    }

    public function test_purchase_history_requires_authentication_existing_care_and_cskh_role(): void
    {
        [$shop, , , , $care] = $this->createPurchaseHistoryContext();
        $this->getJson("/api/v1/customer-cares/{$care->id}/orders")->assertUnauthorized();
        $staff = $this->createUser('staff-cskh', 'Staff');
        $this->actingAs($staff, 'api')->getJson('/api/v1/customer-cares/999999/orders')->assertNotFound();
        $sale = $this->createUser('manager-sale', 'Sale');
        $this->attach($sale, $shop);
        $this->actingAs($sale, 'api')->getJson("/api/v1/customer-cares/{$care->id}/orders")->assertForbidden();
    }

    private function createPurchaseHistoryContext(array $careAttributes = []): array
    {
        $shop = $this->createShop(11);
        $otherShop = $this->createShop(12);
        $staff = $this->createUser('staff-cskh', 'Assigned staff');
        $otherStaff = $this->createUser('staff-cskh', 'Other owner');
        $this->attach($staff, $shop);
        $source = $this->createOrder($shop, [
            'pancake_customer_id' => $careAttributes['pancake_customer_id'] ?? 'HISTORY-CUSTOMER',
            'user_creator_id' => $otherStaff->pancake_user_id,
            'user_care_id' => $otherStaff->pancake_user_id,
        ]);
        $care = $this->createCare($shop, array_merge([
            'pancake_customer_id' => $source->pancake_customer_id,
            'pancake_order_id' => $source->pancake_order_id,
        ], $careAttributes));
        // Include actual null-key Orders to prove the blank-ID guard, not just a mismatch.
        $source->update(['pancake_customer_id' => $care->pancake_customer_id]);
        $assignment = $this->createAssignment($care, $staff, $source);

        return [$shop, $otherShop, $staff, $otherStaff, $care, $source, $assignment];
    }

    private function createSchema(): void
    {
        Schema::create('permission_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('permission_group_id');
            $table->string('name');
            $table->string('slug');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('pancake_user_id')->nullable();
            $table->string('phone_number')->nullable();
            $table->json('pancake_full_data')->nullable();
            $table->timestamps();
        });
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_shop_id')->nullable();
            $table->string('name');
            $table->string('api_key')->nullable();
            $table->integer('care_cycle_days')->default(5);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('shop_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_manager')->default(false);
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_order_id');
            $table->string('pancake_order_source_id')->nullable()->index();
            $table->string('pancake_order_source_name')->nullable();
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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_customer_id');
            $table->string('name')->nullable();
            $table->json('phone_numbers')->nullable();
            $table->json('pancake_full_data')->nullable();
            $table->string('assigned_user_id')->nullable();
            $table->decimal('purchased_amount', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('customer_cares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_customer_id')->nullable();
            $table->string('pancake_order_id')->nullable();
            $table->text('customer_phones')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_addresss')->nullable();
            $table->date('date_care');
            $table->text('note')->nullable();
            $table->string('user_creator_id')->nullable();
            $table->string('user_care_id')->nullable();
            $table->string('user_assigning_seller_id')->nullable();
            $table->integer('status')->default(0);
            $table->timestamps();
        });
        Schema::create('customer_care_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('customer_care_id');
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('assignee_user_id');
            $table->string('assignee_pancake_user_id');
            $table->timestamp('assigned_at');
            $table->date('reclaim_eligible_on');
            $table->string('status', 20)->default(CustomerCareAssignment::STATUS_ACTIVE);
            $table->timestamp('cared_at')->nullable();
            $table->timestamp('reclaimed_at')->nullable();
            $table->string('reclaim_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_assigneds', function (Blueprint $table) {
            $table->id();
            $table->string('customer_care_id');
            $table->string('pancake_user_id');
            $table->timestamps();
        });
    }

    private function createShop(int $id): Shop
    {
        DB::table('shops')->insert([
            'id' => $id,
            'pancake_shop_id' => "external-{$id}",
            'name' => "Shop {$id}",
            'api_key' => "key-{$id}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Shop::findOrFail($id);
    }

    private function createUser(string $roleSlug, string $name): User
    {
        $role = Role::query()->firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug]);
        $nextId = (int) User::query()->max('id') + 1;

        return User::create([
            'role_id' => $role->id,
            'name' => $name,
            'email' => "direct-read-{$nextId}@example.test",
            'password' => 'unused-password',
            'pancake_user_id' => "PANCAKE-{$nextId}",
        ]);
    }

    private function attach(User $user, Shop $shop): void
    {
        $user->shops()->attach($shop->id, ['is_manager' => false]);
    }

    private function grantPermission(User $user, string $slug): void
    {
        $group = PermissionGroup::query()->firstOrCreate(['name' => 'Direct read']);
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['permission_group_id' => $group->id, 'name' => $slug]
        );

        DB::table('role_permissions')->insert([
            'role_id' => $user->role_id,
            'permission_id' => $permission->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrder(Shop $shop, array $attributes = []): Order
    {
        $nextId = (int) Order::query()->max('id') + 1;

        return Order::create(array_merge([
            'shop_id' => $shop->id,
            'pancake_order_id' => "ORDER-{$nextId}",
            'pancake_customer_id' => "CUSTOMER-{$nextId}",
            'status' => 3,
        ], $attributes));
    }

    private function createCustomer(Shop $shop, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'shop_id' => $shop->id,
            'pancake_customer_id' => 'CUSTOMER-'.((int) Customer::query()->max('id') + 1),
        ], $attributes));
    }

    private function createCare(Shop $shop, array $attributes = []): CustomerCare
    {
        return CustomerCare::create(array_merge([
            'shop_id' => $shop->id,
            'pancake_customer_id' => 'CARE-CUSTOMER-'.((int) CustomerCare::query()->max('id') + 1),
            'date_care' => now()->toDateString(),
        ], $attributes));
    }

    private function createAssignment(CustomerCare $care, User $assignee, Order $sourceOrder): CustomerCareAssignment
    {
        return CustomerCareAssignment::create([
            'shop_id' => $care->shop_id,
            'customer_care_id' => $care->id,
            'source_type' => CustomerCareAssignment::SOURCE_ORDER,
            'source_id' => $sourceOrder->id,
            'assignee_user_id' => $assignee->id,
            'assignee_pancake_user_id' => $assignee->pancake_user_id,
            'assigned_at' => now(),
            'reclaim_eligible_on' => now()->addDays(3)->toDateString(),
            'status' => CustomerCareAssignment::STATUS_ACTIVE,
        ]);
    }
}
