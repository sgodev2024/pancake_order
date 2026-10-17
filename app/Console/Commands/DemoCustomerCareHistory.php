<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Services\CustomerCareAssignmentService;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class DemoCustomerCareHistory extends Command
{
    public const MARKER = 'CSKH_HISTORY_DEMO_20261017';

    protected $signature = 'customer-care:demo-history
                            {--user=nvcskh@gmail.com : Existing local staff-cskh account}
                            {--verify : Only check existing demo data and the read APIs}
                            {--cleanup : Delete only the recorded demo rows}';

    protected $description = 'Create, verify or clean up an isolated local CSKH purchase-history demo';

    public function handle(): int
    {
        try {
            $this->assertLocalDatabase();
            $this->ensure(! ($this->option('verify') && $this->option('cleanup')), 'Choose --verify or --cleanup, not both.');
            $actor = User::where('email', $this->option('user'))->firstOrFail();
            $this->ensure($actor->isStaffCskh() && filled($actor->pancake_user_id), 'The selected account must already be staff-cskh with a pancake_user_id.');
            $shop = Shop::withTrashed()->where('pancake_shop_id', self::MARKER.'_SHOP_A')->first();

            if ($shop) {
                $metadata = $shop->pancake_full_data;
                $this->ensure(($metadata['demo_marker'] ?? null) === self::MARKER, 'Demo shop identifier collision; nothing changed.');
                $manifest = $metadata['manifest'] ?? [];
                $this->ensure(($manifest['actor_id'] ?? null) === $actor->id, 'This demo belongs to another account; nothing changed.');
            } else {
                if ($this->option('cleanup')) {
                    $this->info('No demo fixture exists. Nothing deleted.');

                    return self::SUCCESS;
                }
                $this->ensure(! $this->option('verify'), 'Demo is absent. Run without --verify first.');
                $manifest = Model::withoutEvents(fn () => DB::transaction(fn () => $this->createFixture($actor)));
            }

            if ($this->option('cleanup')) {
                DB::transaction(fn () => $this->cleanupFixture($manifest));
                $this->info(self::MARKER.': deleted only the recorded demo rows; existing account preserved.');
            } else {
                $this->line(json_encode($this->verifyFixture($manifest, $actor), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Do not print SQL bindings, credentials, password hashes or raw model data.
            $this->error($exception instanceof DomainException
                ? $exception->getMessage()
                : 'Demo operation failed ('.class_basename($exception).'). Inspect the local setup; no broad cleanup was attempted.');

            return self::FAILURE;
        }
    }

    protected function assertLocalDatabase(): void
    {
        $connection = DB::connection();
        $this->ensure(app()->environment('local'), 'Refusing demo writes outside APP_ENV=local.');
        $this->ensure($connection->getDriverName() === 'mysql', 'This fixture is restricted to the verified local MySQL database.');
        $this->ensure(in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true), 'Refusing a non-loopback database host.');
        $this->ensure(empty($connection->getConfig('read')) && empty($connection->getConfig('write')), 'Refusing split database connections.');
        $server = $connection->selectOne('SELECT DATABASE() AS db, @@hostname AS hostname');
        $this->ensure($server->db === 'pancake_order' && strcasecmp($server->hostname, gethostname()) === 0,
            'Refusing a database other than pancake_order on this computer.');
    }

    private function createFixture(User $actor): array
    {
        $marker = self::MARKER;
        $ownerEmail = strtolower($marker).'_owner@example.invalid';
        // Check deterministic identifiers before creating anything; the transaction is all-or-nothing.
        $this->ensure(! Shop::withTrashed()->whereIn('pancake_shop_id', [$marker.'_SHOP_A', $marker.'_SHOP_B'])->exists(), 'Demo shop identifier collision.');
        $this->ensure(! User::where('email', $ownerEmail)->orWhere('pancake_user_id', $marker.'_OWNER')->exists(), 'Demo employee identifier collision.');
        $this->ensure(! Customer::whereIn('pancake_customer_id', [$marker.'_CUSTOMER', $marker.'_OTHER_CUSTOMER'])->exists(), 'Demo customer identifier collision.');
        $orderKeys = array_map(fn ($suffix) => $marker.'_'.$suffix, ['DEMO-001', 'DEMO-002', 'DEMO-003', 'DEMO-004', 'DEMO-005', 'OUTSIDE-SHOP', 'OTHER-CUSTOMER']);
        $this->ensure(! Order::withTrashed()->whereIn('pancake_order_id', $orderKeys)->exists(), 'Demo order identifier collision.');
        $this->ensure(! CustomerCare::whereIn('pancake_customer_id', [$marker.'_CUSTOMER', $marker.'_OTHER_CUSTOMER'])->exists(), 'Demo care identifier collision.');

        $shops = [];
        foreach (['A', 'B'] as $letter) {
            $shops[] = Shop::create([
                'pancake_shop_id' => $marker.'_SHOP_'.$letter,
                'name' => $marker.' - Shop '.$letter,
                // Required by the schema, deliberately unusable as upstream credentials.
                'api_key' => 'DEMO_DISABLED_NO_REMOTE_ACCESS',
                'care_cycle_days' => 0,
                'pancake_full_data' => ['demo_marker' => $marker],
            ]);
        }
        [$mainShop, $otherShop] = $shops;
        $owner = User::create([
            'role_id' => $actor->role_id,
            'name' => $marker.' - Nhan vien khac',
            'email' => $ownerEmail,
            'password' => Hash::make(Str::random(64)),
            'pancake_user_id' => $marker.'_OWNER',
            'pancake_full_data' => ['demo_marker' => $marker],
            'is_first_login' => false,
        ]);
        $memberships = [];
        foreach ([[$mainShop, $actor], [$mainShop, $owner], [$otherShop, $owner]] as [$shop, $member]) {
            $memberships[] = DB::table('shop_users')->insertGetId([
                'shop_id' => $shop->id, 'user_id' => $member->id, 'is_manager' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $customers = [];
        foreach ([[$marker.'_CUSTOMER', 'Nguyễn Văn Demo'], [$marker.'_OTHER_CUSTOMER', 'Khách Demo khác']] as [$key, $name]) {
            $customers[] = Customer::create([
                'shop_id' => $mainShop->id, 'pancake_customer_id' => $key, 'name' => $name,
                'phone_numbers' => '0000000000', 'order_count' => $key === $marker.'_CUSTOMER' ? 5 : 1,
                'purchased_amount' => $key === $marker.'_CUSTOMER' ? 1950000 : 99000,
                'pancake_full_data' => ['demo_marker' => $marker],
            ]);
        }
        $orders = [];
        foreach ([250000, 450000, 320000, 780000, 150000, 999000, 99000] as $index => $amount) {
            $employee = $index === 0 ? $actor : $owner;
            $orders[] = Order::create([
                'shop_id' => $index === 5 ? $otherShop->id : $mainShop->id,
                'pancake_customer_id' => $customers[$index === 6 ? 1 : 0]->pancake_customer_id,
                'pancake_order_id' => $orderKeys[$index],
                'user_creator_id' => $employee->pancake_user_id, 'user_care_id' => $employee->pancake_user_id,
                'customer_name' => $customers[$index === 6 ? 1 : 0]->name,
                'customer_phone' => '0000000000', 'customer_address' => 'Địa chỉ giả lập - chỉ dùng local',
                'total_quantity' => 1, 'cod' => $amount, 'cash' => 0, 'status' => 3, 'note' => $marker,
                'pancake_full_data' => ['demo_marker' => $marker],
            ]);
        }
        $care = CustomerCare::create([
            'shop_id' => $mainShop->id, 'pancake_customer_id' => $customers[0]->pancake_customer_id,
            'pancake_order_id' => $orders[0]->pancake_order_id, 'customer_name' => $customers[0]->name,
            'customer_phones' => '0000000000', 'customer_addresss' => 'Địa chỉ giả lập - chỉ dùng local',
            'date_care' => today()->toDateString(), 'status' => 0, 'is_accept' => 1, 'note' => $marker,
            'user_creator_id' => $actor->pancake_user_id,
        ]);
        // create() only persists the real assignment model; no journey event, notifications or upstream sync.
        $assignment = app(CustomerCareAssignmentService::class)->create(
            $care, $mainShop->id, CustomerCareAssignment::SOURCE_ORDER, $orders[0]->id,
            $actor, now(), today()
        );
        $manifest = [
            'marker' => $marker, 'actor_id' => $actor->id,
            'rows' => [
                'shops' => [$mainShop->id, $otherShop->id], 'users' => [$owner->id],
                'shop_users' => $memberships, 'customers' => array_map(fn ($row) => $row->id, $customers),
                'orders' => array_map(fn ($row) => $row->id, $orders),
                'customer_cares' => [$care->id], 'customer_care_assignments' => [$assignment->id],
            ],
        ];
        $mainShop->update(['pancake_full_data' => ['demo_marker' => $marker, 'manifest' => $manifest]]);

        return $manifest;
    }

    private function verifyFixture(array $manifest, User $actor): array
    {
        $this->validateManifest($manifest);
        $rows = $manifest['rows'];
        $care = CustomerCare::findOrFail($rows['customer_cares'][0]);
        $this->ensure($care->shop_id == $rows['shops'][0] && $care->pancake_customer_id === self::MARKER.'_CUSTOMER', 'Demo care identity changed.');
        $this->ensure(DB::table('shop_users')->where('user_id', $actor->id)->where('shop_id', $care->shop_id)->exists(), 'Missing demo membership.');
        $assignments = CustomerCareAssignment::where('customer_care_id', $care->id)->where('status', CustomerCareAssignment::STATUS_ACTIVE)->get();
        $this->ensure($assignments->count() === 1 && $assignments[0]->assignee_user_id == $actor->id
            && $assignments[0]->shop_id == $care->shop_id && $assignments[0]->source_type === CustomerCareAssignment::SOURCE_ORDER
            && $assignments[0]->source_id == $rows['orders'][0], 'Invalid demo active assignment.');
        $orders = Order::where('shop_id', $care->shop_id)->where('pancake_customer_id', $care->pancake_customer_id)->get();
        $owned = $orders->filter(fn ($order) => $order->user_creator_id === $actor->pancake_user_id || $order->user_care_id === $actor->pancake_user_id)->count();
        $this->ensure($orders->count() === 5 && $owned === 1, 'Expected exactly five purchases, with one matching staff ownership.');
        $this->ensure(Order::whereIn('id', array_slice($rows['orders'], 5))->count() === 2, 'Missing out-of-scope demo orders.');
        $expected = array_slice($rows['orders'], 0, 5);
        $response = $this->readApi($actor, '/api/v1/customer-cares/'.$care->id.'/orders');
        $actual = array_column($response['data'] ?? [], 'id');
        sort($expected);
        sort($actual);
        $this->ensure($actual === $expected && ($response['success'] ?? false) === true, 'API did not return exactly the five permitted purchases.');
        $listType = $care->date_care === today()->toDateString() ? 'customer_care_today' : 'customer_care_expire';
        $list = $this->readApi($actor, '/api/v1/customer-cares?'.http_build_query([
            'type' => $listType, 'context' => 'v2', 'shop_id' => $care->shop_id, 'page' => 1,
        ]));
        $this->ensure(in_array($care->id, array_column($list['data']['customers'] ?? [], 'id'), true), 'Demo care is not visible in the appointment list.');

        return [
            'marker' => self::MARKER, 'environment' => app()->environment(), 'database' => DB::connection()->getDatabaseName(),
            'account' => $actor->only(['id', 'email']), 'shop_id' => $care->shop_id, 'shop_name' => Shop::findOrFail($care->shop_id)->name,
            'customer' => $care->customer_name, 'customer_id' => $care->pancake_customer_id,
            'care_id' => $care->id, 'date_care' => $care->date_care, 'assignment_id' => $assignments[0]->id,
            'same_customer_shop_orders' => $orders->count(), 'matching_staff_ownership' => $owned,
            'other_employee_ownership' => 5 - $owned, 'api_status' => 200, 'api_order_ids' => $actual,
            'excluded_order_ids' => array_slice($rows['orders'], 5), 'appointment_list_verified' => true,
        ];
    }

    private function readApi(User $actor, string $uri): array
    {
        // Exercise the real auth middleware/controller without creating or exposing an OAuth token.
        Auth::guard('api')->setUser($actor);
        $response = app(Kernel::class)->handle(Request::create($uri, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']));
        $this->ensure($response->getStatusCode() === 200, 'Read API verification failed with HTTP '.$response->getStatusCode().'.');

        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function validateManifest(array $manifest): void
    {
        $this->ensure(($manifest['marker'] ?? null) === self::MARKER, 'Invalid demo manifest.');
        foreach (['shops' => 2, 'users' => 1, 'shop_users' => 3, 'customers' => 2, 'orders' => 7,
            'customer_cares' => 1, 'customer_care_assignments' => 1] as $table => $count) {
            $ids = $manifest['rows'][$table] ?? [];
            $this->ensure(count($ids) === $count && count(array_unique($ids)) === $count
                && count(array_filter($ids, fn ($id) => is_int($id) && $id > 0)) === $count, 'Invalid recorded demo IDs.');
        }
        $this->ensure(! in_array($manifest['actor_id'], $manifest['rows']['users'], true), 'Cleanup must never delete the existing account.');
    }

    private function cleanupFixture(array $manifest): void
    {
        $this->validateManifest($manifest);
        $rows = $manifest['rows'];
        // Check immutable marker identities before deletion, including soft-deleted rows.
        $identities = [
            'shops' => ['pancake_shop_id', [self::MARKER.'_SHOP_A', self::MARKER.'_SHOP_B']],
            'users' => ['pancake_user_id', [self::MARKER.'_OWNER']],
            'customers' => ['pancake_customer_id', [self::MARKER.'_CUSTOMER', self::MARKER.'_OTHER_CUSTOMER']],
            'orders' => ['pancake_order_id', array_map(fn ($suffix) => self::MARKER.'_'.$suffix,
                ['DEMO-001', 'DEMO-002', 'DEMO-003', 'DEMO-004', 'DEMO-005', 'OUTSIDE-SHOP', 'OTHER-CUSTOMER'])],
            'customer_cares' => ['pancake_customer_id', [self::MARKER.'_CUSTOMER']],
            'customer_care_assignments' => ['customer_care_id', $rows['customer_cares']],
            'shop_users' => ['shop_id', $rows['shops']],
        ];
        foreach ($identities as $table => [$column, $values]) {
            $this->ensure(! DB::table($table)->whereIn('id', $rows[$table])->where(function ($query) use ($column, $values) {
                $query->whereNull($column)->orWhereNotIn($column, $values);
            })->exists(), 'Demo identity changed; cleanup aborted.');
        }
        // Refuse cascades into records not created by this fixture, including future FK tables.
        $references = DB::select('SELECT TABLE_NAME AS child_table, COLUMN_NAME AS child_column, REFERENCED_TABLE_NAME AS parent_table, REFERENCED_COLUMN_NAME AS parent_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL');
        foreach ($references as $reference) {
            if (! isset($rows[$reference->parent_table])) {
                continue;
            }
            $this->ensure($reference->parent_column === 'id', 'Unrecognized foreign key; cleanup aborted.');
            $query = DB::table($reference->child_table)->whereIn($reference->child_column, $rows[$reference->parent_table]);
            if (isset($rows[$reference->child_table])) {
                $query->whereNotIn('id', $rows[$reference->child_table]);
            }
            $this->ensure(! $query->exists(), 'Non-fixture records reference demo rows; cleanup aborted without deleting anything.');
        }
        // These legacy relationships have no database foreign keys.
        $this->ensure(! DB::table('customer_assigneds')->where(function ($query) use ($rows) {
            $query->whereIn('customer_care_id', $rows['customer_cares'])->orWhere('pancake_user_id', self::MARKER.'_OWNER');
        })->exists(), 'Legacy assignments reference the demo; cleanup aborted.');
        $this->ensure(! DB::table('customer_cares')->whereIn('pancake_order_id', $identities['orders'][1])->whereNotIn('id', $rows['customer_cares'])->exists(), 'Additional care tasks reference demo orders; cleanup aborted.');
        $this->ensure(! DB::table('customer_care_assignments')->where('source_type', CustomerCareAssignment::SOURCE_ORDER)
            ->whereIn('source_id', $rows['orders'])->whereNotIn('id', $rows['customer_care_assignments'])->exists(), 'Additional assignments reference demo orders; cleanup aborted.');
        foreach (['orders', 'customer_cares'] as $table) {
            $this->ensure(! DB::table($table)->whereNotIn('id', $rows[$table])
                ->whereIn('pancake_customer_id', $identities['customers'][1])->exists(), 'Additional records reference demo customers; cleanup aborted.');
            $this->ensure(! DB::table($table)->whereNotIn('id', $rows[$table])->where(function ($query) {
                $query->where('user_creator_id', self::MARKER.'_OWNER')->orWhere('user_care_id', self::MARKER.'_OWNER')
                    ->orWhere('user_assigning_seller_id', self::MARKER.'_OWNER');
            })->exists(), 'Additional records reference the demo employee; cleanup aborted.');
        }
        $this->ensure(! DB::table('customers')->whereNotIn('id', $rows['customers'])
            ->where('assigned_user_id', self::MARKER.'_OWNER')->exists(), 'Additional customers reference the demo employee; cleanup aborted.');
        foreach (['customer_care_assignments', 'customer_cares', 'orders', 'customers', 'shop_users', 'users', 'shops'] as $table) {
            DB::table($table)->whereIn('id', $rows[$table])->delete();
        }
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new DomainException($message);
        }
    }
}
