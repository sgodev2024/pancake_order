<?php

// Explicit test-only setup. Never switch the application's .env or reset an existing database.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Jobs\GetOrderByShopJob;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Shop;
use App\Models\User;
use App\Services\OrderCreatedEventWriter;
use App\Services\OrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

if (($argv[1] ?? '') !== '--create') {
    fwrite(STDERR, "Use --create to initialize a NEW, isolated warehouse test database. Existing databases are never reset.\n");
    exit(1);
}
$name = 'pancake_warehouse_plan3_test_20261021';
$original = config('database.connections.mysql');
if (! is_array($original) || $original['database'] === $name) {
    throw new RuntimeException('Expected the normal configuration before creating the isolated fixture.');
}
DB::connection('mysql')->statement('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
config(['database.default' => 'warehouse_fixture', 'database.connections.warehouse_fixture' => array_replace($original, ['database' => $name])]);
DB::purge('warehouse_fixture');
DB::setDefaultConnection('warehouse_fixture');
if (DB::connection()->getDatabaseName() !== $name || ! str_starts_with($name, 'pancake_warehouse_plan3_test_')) {
    throw new RuntimeException('Unsafe fixture database.');
}
if (Schema::hasTable('users')) {
    throw new RuntimeException('Test database already contains a schema; refusing to overwrite/reset it.');
}
foreach ([
    '0001_01_01_000000_create_users_table.php',
    '2026_04_24_082539_create_permission_groups_table.php',
    '2026_04_24_082550_create_permissions_table.php',
    '2026_04_24_082551_create_roles_table.php',
    '2026_04_24_082556_create_role_permissions_table.php',
    '2026_04_24_085229_create_oauth_auth_codes_table.php',
    '2026_04_24_085230_create_oauth_access_tokens_table.php',
    '2026_04_24_085231_create_oauth_refresh_tokens_table.php',
    '2026_04_24_085232_create_oauth_clients_table.php',
    '2026_04_24_085233_create_oauth_device_codes_table.php',
    '2026_04_27_084626_create_shops_table.php',
    '2026_04_27_093216_add_column_to_users_table.php',
    '2026_04_28_072537_create_shop_users_table.php',
    '2026_04_28_111056_create_customers_table.php',
    '2026_04_28_111057_create_orders_table.php',
    '2026_04_29_170341_add_column_to_orders_table.php',
    '2026_05_01_043736_add_care_cycle_days_to_shops_table.php',
    '2026_05_04_082209_add_slug_to_roles_table.php',
    '2026_05_11_161116_create_products_table.php',
    '2026_05_14_074248_add_assigned_user_id_to_customers_table.php',
    '2026_05_17_094309_add_is_first_login_column_to_users_table.php',
    '2026_05_20_083440_create_settings_table.php',
    '2026_06_04_110133_add_order_count_to_customers_table.php',
    '2026_08_20_000000_create_activity_logs_table.php',
    '2026_08_27_000000_add_customer_journey_contract_to_activity_logs_table.php',
    '2026_09_12_000001_add_pancake_order_source_to_orders_table.php',
    '2026_09_12_000004_add_pancake_order_page_to_orders_table.php',
    '2026_10_21_000001_add_local_pos_support.php',
    '2026_10_21_000002_add_warehouse_completion_and_source_time.php',
] as $file) {
    (require database_path('migrations/'.$file))->up();
}
Schema::table('customers', function (Blueprint $table) {
    // Match the deployed schema, whose phone column predates the current migration history.
    $table->string('phone_numbers', 1000)->nullable()->change();
    $table->double('purchased_amount')->nullable();
});
Schema::table('orders', fn (Blueprint $table) => $table->unsignedInteger('province_id')->nullable());
$activation = now()->subHour()->toIso8601String();
config(['warehouse.activated_at' => $activation]);
$shop = Shop::create(['name' => 'Kho TEST - Shop A', 'pancake_shop_id' => 'fixture-shop-a', 'api_key' => 'test-only']);
Shop::create(['name' => 'Kho TEST - Shop B', 'pancake_shop_id' => 'fixture-shop-b', 'api_key' => 'test-only']);
$group = PermissionGroup::firstOrCreate(['name' => 'Fixture']);
$users = [];
foreach ([
    ['sale', 'staff-sale', ['list-product', 'list-customer']],
    ['packer1', 'warehouse-test', ['warehouse-view', 'warehouse-pack']],
    ['packer2', 'warehouse-test', ['warehouse-view', 'warehouse-pack']],
] as [$username, $roleSlug, $permissions]) {
    $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug]);
    $user = User::create(['name' => 'TEST '.$username, 'email' => $username.'@warehouse.test', 'password' => 'WarehouseTest123!', 'role_id' => $role->id, 'is_first_login' => false]);
    $user->shops()->attach($shop->id);
    foreach ($permissions as $slug) {
        $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'permission_group_id' => $group->id]);
        RolePermission::firstOrCreate(['role_id' => $role->id, 'permission_id' => $permission->id]);
    }
    $users[$username] = $user;
}
app(ClientRepository::class)->createPersonalAccessGrantClient('Warehouse isolated TEST', 'users');
$product = Product::create(['shop_id' => $shop->id, 'name' => 'Sản phẩm Local - kiểm thử Kho', 'pancake_full_data' => ['retail_price' => 250000, 'remain_quantity' => 20]]);
$ids = [];
foreach (['local_demo', 'concurrency'] as $kind) {
    $order = app(OrderService::class)->createLocalOrder($users['sale'], [
        'idempotency_key' => (string) Str::uuid(), 'shop_id' => $shop->id,
        'customer' => ['name' => 'Khách TEST Kho', 'phone' => '0900000000'],
        'shipping_address' => 'Địa chỉ giả lập trong database TEST',
        'items' => [['product_id' => $product->id, 'quantity' => 2]], 'paid_amount' => 0,
        'note' => 'TEST PLAN 3 - '.$kind.' - không giao hàng, không thu tiền.',
    ]);
    $ids[$kind] = $order->id;
}
(new GetOrderByShopJob([[
    'id' => 'fixture-pancake-new', 'shop_id' => $shop->pancake_shop_id,
    'inserted_at' => now()->subMinutes(5)->utc()->toIso8601String(), 'status' => 0,
    'cod' => 100000, 'creator' => ['id' => 'fixture-creator', 'name' => 'Người tạo Pancake TEST'],
    'bill_full_name' => 'Khách Pancake TEST', 'shipping_address' => ['full_address' => 'Địa chỉ TEST'],
    'note' => 'Đơn Pancake giả lập không vận đơn - chỉ ở database TEST.',
    'items' => [['quantity' => 100, 'variation_info' => ['name' => 'Sản phẩm Pancake TEST', 'images' => []]]],
]], $shop->id))->handle(app(OrderCreatedEventWriter::class));
$ids['pancake_demo'] = Order::where('pancake_order_id', 'fixture-pancake-new_'.$shop->id)->sole()->id;
$ids['historical'] = Order::forceCreate(['shop_id' => $shop->id, 'pancake_order_id' => 'fixture-historical',
    'status' => 0, 'customer_name' => 'Đơn cũ TEST không đưa vào Kho', 'created_at' => now()->subDays(2)])->id;
$result = ['database' => $name, 'activation' => $activation, 'orders' => $ids, 'packers' => [$users['packer1']->id, $users['packer2']->id], 'product_id' => $product->id];
file_put_contents('/tmp/pancake-plan3-test-environment.json', json_encode($result, JSON_PRETTY_PRINT));
echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
