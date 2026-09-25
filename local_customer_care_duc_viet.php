<?php

use App\Models\ActivityLog;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Models\User;
use App\Services\CustomerCareAssignmentService;
use App\Services\CustomerCareListQuery;
use App\Services\CustomerCareWriteAccessService;
use App\Services\ShopAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$manifestPath = storage_path('app/customer-care-parity-ducviet-local.json');
$mode = $argv[1] ?? 'status';

ducVietAssertLocalDatabase();

match ($mode) {
    'seed' => ducVietSeed($manifestPath),
    'status' => ducVietStatus($manifestPath),
    'verify' => ducVietVerify($manifestPath),
    'cleanup' => ducVietCleanup($manifestPath),
    default => ducVietFail("Unknown mode '{$mode}'. Use seed, status, verify, or cleanup."),
};

function ducVietAssertLocalDatabase(): void
{
    $connection = config('database.default');
    $host = (string) config("database.connections.{$connection}.host");
    $database = (string) config("database.connections.{$connection}.database");

    if (! app()->environment('local')) {
        ducVietFail('Refusing to run outside APP_ENV=local.');
    }

    if (! in_array(strtolower($host), ['127.0.0.1', 'localhost'], true)) {
        ducVietFail("Refusing to run against non-local DB host '{$host}'.");
    }

    echo "Environment: local\nDatabase: {$database} @ {$host}\n";
}

function ducVietTargetUser(): User
{
    $user = User::query()->with('role:id,slug')->whereKey(635)->first();

    if ($user === null || strcasecmp((string) $user->email, 'ducviet@gmail.com') !== 0) {
        ducVietFail('User ID 635 with email ducviet@gmail.com was not found. No data was changed.');
    }

    if ($user->role?->slug !== 'staff-cskh') {
        ducVietFail("User 635 has role '{$user->role?->slug}', expected staff-cskh. No data was changed.");
    }

    return $user;
}

function ducVietSnapshotUser(User $user): array
{
    $columns = Schema::getColumnListing('users');
    $row = DB::table('users')->where('id', $user->getKey())->first();
    $attributes = [];

    foreach (['id', 'pancake_user_id', 'role_id', 'manager_id', 'storage_id'] as $column) {
        $attributes[$column] = in_array($column, $columns, true)
            ? ($row->{$column} ?? null)
            : null;
    }

    return [
        'attributes' => $attributes,
        'columns_present' => array_values(array_intersect(
            ['id', 'pancake_user_id', 'role_id', 'manager_id', 'storage_id'],
            $columns
        )),
        'shop_users' => DB::table('shop_users')
            ->where('user_id', $user->getKey())
            ->orderBy('shop_id')
            ->orderBy('id')
            ->get()
            ->map(fn ($membership): array => (array) $membership)
            ->all(),
        'project_users' => Schema::hasTable('project_users')
            ? DB::table('project_users')
                ->where('user_id', $user->getKey())
                ->orderBy('project_id')
                ->orderBy('id')
                ->get()
                ->map(fn ($membership): array => (array) $membership)
                ->all()
            : [],
        'customer_assigneds' => Schema::hasTable('customer_assigneds')
            ? DB::table('customer_assigneds')
                ->where('pancake_user_id', (string) ($user->pancake_user_id ?? $user->getKey()))
                ->get()
                ->map(fn ($assignment): array => (array) $assignment)
                ->all()
            : [],
    ];
}

function ducVietSelectShopAndOrders(User $user): array
{
    $shop = Shop::query()
        ->whereKey(11)
        ->whereNull('deleted_at')
        ->first();

    if ($shop === null) {
        ducVietFail('Shop 11 does not exist or is deleted. No data was changed.');
    }

    if (! DB::table('users')->where('pancake_user_id', (string) $user->getKey())->where('id', '!=', $user->getKey())->doesntExist()) {
        ducVietFail('The local Pancake ID 635 is already used by another user. No data was changed.');
    }

    $orders = Order::query()
        ->where('orders.shop_id', $shop->getKey())
        ->whereNotNull('orders.pancake_order_id')
        ->where('orders.pancake_order_id', '!=', '')
        ->whereNotNull('orders.pancake_order_page_id')
        ->where('orders.pancake_order_page_id', '!=', '')
        ->whereNotNull('orders.pancake_order_page_name')
        ->where('orders.pancake_order_page_name', '!=', '')
        ->whereNotExists(function ($query) {
            $query->selectRaw('1')
                ->from('customer_care_assignments as seed_cca')
                ->join('orders as seed_source_orders', 'seed_source_orders.id', '=', 'seed_cca.source_id')
                ->whereColumn('seed_source_orders.pancake_order_id', 'orders.pancake_order_id')
                ->whereNull('seed_source_orders.deleted_at')
                ->where('seed_cca.source_type', CustomerCareAssignment::SOURCE_ORDER)
                ->where('seed_cca.status', CustomerCareAssignment::STATUS_ACTIVE)
                ->whereNull('seed_cca.cared_at');
        })
        ->latest('orders.id')
        ->limit(200)
        ->get()
        ->unique('pancake_order_id')
        ->take(5)
        ->values();

    if ($orders->count() !== 5) {
        ducVietFail('Shop 11 does not have five safe local Orders with source-page data. No data was changed.');
    }

    return [$shop, $orders];
}

function ducVietSeed(string $manifestPath): void
{
    $user = ducVietTargetUser();

    if (is_file($manifestPath)) {
        echo "A Đức Việt manifest already exists; no duplicate seed was created.\n";
        ducVietStatus($manifestPath);

        return;
    }

    $snapshot = ducVietSnapshotUser($user);
    [$shop, $orders] = ducVietSelectShopAndOrders($user);

    $originalPancakeUserId = $user->pancake_user_id;
    $testPancakeUserId = (string) $user->getKey();
    $marker = 'CCPARITY-DUCVIET-'.now(config('app.timezone'))->format('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
    $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
    $definitions = [
        'today' => [
            'date' => $today,
            'status' => 0,
            'is_accept' => 1,
            'total_edit' => 0,
            'completed' => false,
            'route' => '/kh-cham-soc-hom-nay',
        ],
        'upcoming' => [
            'date' => $today->addDay(),
            'status' => 0,
            'is_accept' => 1,
            'total_edit' => 0,
            'completed' => false,
            'route' => '/lich-cham-soc-sap-dien-ra',
        ],
        'overdue' => [
            'date' => $today->subDay(),
            'status' => 0,
            'is_accept' => 1,
            'total_edit' => 0,
            'completed' => false,
            'route' => '/khach-cham-soc-qua-han',
        ],
        'pending_edit' => [
            'date' => $today->subDays(10),
            'status' => 1,
            'is_accept' => 0,
            'total_edit' => 2,
            'completed' => true,
            'route' => '/yeu-cau-sua-cskh',
        ],
        'approved_edit' => [
            'date' => $today->subDays(10),
            'status' => 1,
            'is_accept' => 1,
            'total_edit' => 2,
            'completed' => true,
            'route' => '/yeu-cau-sua-cskh',
        ],
    ];

    $sequenceSnapshots = collect($orders)->mapWithKeys(function (Order $order) use ($shop): array {
        $key = 'source:'.$order->getKey();
        $row = Schema::hasTable('customer_care_journey_sequences')
            ? DB::table('customer_care_journey_sequences')
                ->where('shop_id', $shop->getKey())
                ->where('sequence_scope', 'order_source')
                ->where('scope_key', $key)
                ->first()
            : null;

        return [(string) $order->getKey() => [
            'shop_id' => (int) $shop->getKey(),
            'sequence_scope' => 'order_source',
            'scope_key' => $key,
            'existed' => $row !== null,
            'last_sequence' => $row?->last_sequence,
        ]];
    })->all();

    $manifest = DB::transaction(function () use (
        $definitions,
        $marker,
        $snapshot,
        $shop,
        $orders,
        $user,
        $testPancakeUserId,
        $originalPancakeUserId,
        $sequenceSnapshots
    ): array {
        $user->update(['pancake_user_id' => $testPancakeUserId]);
        ShopUser::query()->firstOrCreate([
            'shop_id' => $shop->getKey(),
            'user_id' => $user->getKey(),
        ], ['is_manager' => false]);

        $assignmentService = app(CustomerCareAssignmentService::class);
        $cases = [];

        foreach (array_values(array_keys($definitions)) as $index => $caseName) {
            $definition = $definitions[$caseName];
            /** @var Order $order */
            $order = $orders[$index];
            $completedAt = $definition['completed']
                ? $definition['date']->setTime(10, 0)
                : null;
            $displayName = $marker.':'.strtoupper($caseName);
            $care = CustomerCare::create([
                'shop_id' => $shop->getKey(),
                'pancake_customer_id' => $marker.'-CUSTOMER-'.($index + 1),
                'customer_phones' => json_encode(
                    filled($order->customer_phone) ? [$order->customer_phone] : [],
                    JSON_UNESCAPED_UNICODE
                ),
                'customer_name' => $displayName,
                'customer_addresss' => $order->customer_address,
                'pancake_order_id' => $order->pancake_order_id,
                'date_care' => $definition['date']->toDateString(),
                'note' => $definition['completed'] ? "LOCAL Đức Việt parity {$caseName}" : null,
                'user_creator_id' => $testPancakeUserId,
                'user_care_id' => $testPancakeUserId,
                'user_assigning_seller_id' => $testPancakeUserId,
                'status' => $definition['status'],
                'time_care' => $completedAt,
                'is_accept' => $definition['is_accept'],
                'user_accept_id' => $definition['is_accept'] === 1 && $definition['total_edit'] > 1
                    ? 1
                    : null,
                'total_edit' => $definition['total_edit'],
                'reason' => $definition['total_edit'] > 1 && $definition['is_accept'] === 1
                    ? 'LOCAL Đức Việt approved seed'
                    : null,
                'is_confirm_care' => false,
            ]);

            $assignment = $assignmentService->create(
                $care,
                (int) $shop->getKey(),
                CustomerCareAssignment::SOURCE_ORDER,
                (int) $order->getKey(),
                $user,
                $definition['completed']
                    ? $definition['date']->subDays(3)->setTime(9, 0)
                    : now(config('app.timezone')),
                $definition['date']
            );

            if ($completedAt !== null) {
                $assignment->update(['cared_at' => $completedAt]);
            }

            $cases[$caseName] = [
                'care_id' => (int) $care->getKey(),
                'assignment_id' => (int) $assignment->getKey(),
                'order_id' => (int) $order->getKey(),
                'pancake_order_id' => $order->pancake_order_id,
                'order_page_id' => $order->pancake_order_page_id,
                'order_page_name' => $order->pancake_order_page_name,
                'customer_name' => $displayName,
                'date_care' => $definition['date']->toDateString(),
                'status' => $definition['status'],
                'is_accept' => $definition['is_accept'],
                'total_edit' => $definition['total_edit'],
                'cared_at' => $completedAt?->format('Y-m-d H:i:s'),
                'route' => $definition['route'],
                'expected_actions' => $definition['completed']
                    ? ['Xem chi tiết', 'Lịch sử đơn hàng', 'Lịch sử chăm sóc']
                    : ['Xem chi tiết', 'Xác nhận đã chăm sóc', 'Lịch sử đơn hàng', 'Lịch sử chăm sóc'],
            ];
        }

        return [
            'marker' => $marker,
            'seeded_at' => now(config('app.timezone'))->toISOString(),
            'database' => config('database.connections.'.config('database.default').'.database'),
            'user' => [
                'id' => (int) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->slug,
                'test_pancake_user_id' => $testPancakeUserId,
                'original_pancake_user_id' => $originalPancakeUserId,
            ],
            'shop' => [
                'id' => (int) $shop->getKey(),
                'name' => $shop->name,
                'pancake_shop_id' => $shop->pancake_shop_id,
            ],
            'backup' => $snapshot,
            'cases' => $cases,
            'sequence_snapshots' => $sequenceSnapshots,
        ];
    });

    if (! is_dir(dirname($manifestPath))) {
        mkdir(dirname($manifestPath), 0775, true);
    }

    $written = file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL
    );

    if ($written === false) {
        ducVietFail("Seed created but manifest could not be written at {$manifestPath}; do not run generic cleanup.");
    }

    echo "Created local Đức Việt Customer Care data.\n";
    ducVietPrintManifest($manifest);
}

function ducVietStatus(string $manifestPath): void
{
    $user = ducVietTargetUser();
    $shops = $user->shops()->whereNull('shops.deleted_at')->get(['shops.id', 'shops.name']);
    echo json_encode([
        'user' => [
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->slug,
            'pancake_user_id' => $user->pancake_user_id,
            'shop_ids' => $shops->pluck('id')->map(fn ($id) => (int) $id)->values(),
            'shops' => $shops->map(fn ($shop): array => [
                'id' => (int) $shop->id,
                'name' => $shop->name,
            ])->values(),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;

    if (! is_file($manifestPath)) {
        echo "No Đức Việt seed manifest exists.\n";

        return;
    }

    $manifest = ducVietReadManifest($manifestPath);
    ducVietPrintManifest($manifest);

    $cares = CustomerCare::query()
        ->where('customer_name', 'like', $manifest['marker'].':%')
        ->with('assignments')
        ->orderBy('id')
        ->get();

    echo "Current marker records:\n";
    foreach ($cares as $care) {
        $assignment = $care->assignments->sortByDesc('id')->first();
        echo json_encode([
            'care_id' => (int) $care->getKey(),
            'customer_name' => $care->customer_name,
            'date_care' => $care->date_care,
            'status' => (int) $care->status,
            'is_accept' => (int) $care->is_accept,
            'assignment_id' => $assignment?->getKey(),
            'assignee_user_id' => $assignment?->assignee_user_id,
            'assignee_pancake_user_id' => $assignment?->assignee_pancake_user_id,
            'assignment_status' => $assignment?->status,
            'cared_at' => $assignment?->cared_at?->format('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
    }
}

function ducVietCleanup(string $manifestPath): void
{
    if (! is_file($manifestPath)) {
        echo "No Đức Việt manifest exists; nothing was deleted.\n";

        return;
    }

    $manifest = ducVietReadManifest($manifestPath);
    $marker = $manifest['marker'];
    if (! str_starts_with($marker, 'CCPARITY-DUCVIET-')) {
        ducVietFail('Manifest marker is not a Đức Việt marker; refusing cleanup.');
    }

    $deleted = DB::transaction(function () use ($manifest, $marker): array {
        $careIds = CustomerCare::query()
            ->where('customer_name', 'like', $marker.':%')
            ->pluck('id')
            ->merge(collect($manifest['cases'])->pluck('care_id'))
            ->unique()
            ->values();
        $assignmentIds = CustomerCareAssignment::query()
            ->whereIn('customer_care_id', $careIds)
            ->pluck('id');
        $deletedLogs = 0;

        if (Schema::hasTable('activity_logs')) {
            $deletedLogs = ActivityLog::query()
                ->where(function ($query) use ($careIds, $assignmentIds) {
                    $query->where(function ($careQuery) use ($careIds) {
                        $careQuery->where('subject_type', 'customer_care')
                            ->whereIn('subject_id', $careIds->map(fn ($id) => (string) $id));
                    })->orWhere(function ($assignmentQuery) use ($assignmentIds) {
                        $assignmentQuery->where('subject_type', 'customer_care_assignment')
                            ->whereIn('subject_id', $assignmentIds->map(fn ($id) => (string) $id));
                    });
                })
                ->delete();
        }

        if (Schema::hasTable('customer_assigneds')) {
            DB::table('customer_assigneds')->whereIn('customer_care_id', $careIds)->delete();
        }

        $deletedAssignments = CustomerCareAssignment::query()
            ->whereIn('customer_care_id', $careIds)
            ->delete();
        $deletedCares = CustomerCare::query()->whereIn('id', $careIds)->delete();

        if (Schema::hasTable('customer_care_journey_sequences')) {
            foreach ($manifest['sequence_snapshots'] as $snapshot) {
                $query = DB::table('customer_care_journey_sequences')
                    ->where('shop_id', $snapshot['shop_id'])
                    ->where('sequence_scope', $snapshot['sequence_scope'])
                    ->where('scope_key', $snapshot['scope_key']);

                if ($snapshot['existed']) {
                    $query->update([
                        'last_sequence' => $snapshot['last_sequence'],
                        'updated_at' => now(),
                    ]);
                } else {
                    $query->delete();
                }
            }
        }

        $userId = (int) $manifest['user']['id'];
        $originalMemberships = collect($manifest['backup']['shop_users'])
            ->map(fn ($membership): array => [
                'id' => $membership['id'] ?? null,
                'shop_id' => (int) $membership['shop_id'],
                'user_id' => (int) $membership['user_id'],
                'is_manager' => (int) ($membership['is_manager'] ?? 0),
            ]);
        $seedShopId = (int) $manifest['shop']['id'];
        if (! $originalMemberships->contains(fn ($membership): bool => $membership['shop_id'] === $seedShopId)) {
            ShopUser::query()->where('user_id', $userId)->where('shop_id', $seedShopId)->delete();
        }

        $userColumns = Schema::getColumnListing('users');
        $restoreUser = [];
        foreach (['pancake_user_id', 'role_id', 'manager_id', 'storage_id'] as $column) {
            if (in_array($column, $userColumns, true) && array_key_exists($column, $manifest['backup']['attributes'])) {
                $restoreUser[$column] = $manifest['backup']['attributes'][$column];
            }
        }
        if ($restoreUser !== []) {
            DB::table('users')->where('id', $userId)->update($restoreUser);
        }

        return [
            'customer_cares' => $deletedCares,
            'assignments' => $deletedAssignments,
            'activity_logs' => $deletedLogs,
            'user_restored' => $userId,
            'membership_restored' => true,
        ];
    });

    unlink($manifestPath);
    echo 'Cleanup complete: '.json_encode($deleted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
}

function ducVietVerify(string $manifestPath): void
{
    $manifest = ducVietReadManifest($manifestPath);
    $user = ducVietTargetUser();
    $shopAccess = app(ShopAccessService::class);
    $listQuery = app(CustomerCareListQuery::class);
    $writeAccess = app(CustomerCareWriteAccessService::class);
    $marker = $manifest['marker'];

    $listTypes = [
        'today' => 'customer_care_today',
        'upcoming' => 'customer_care_pending',
        'overdue' => 'customer_care_expire',
        'edit_requests' => 'customer_care_edit',
    ];
    $lists = [];
    foreach ($listTypes as $label => $type) {
        $query = $listQuery->query($type, $user, ['search' => $marker]);
        $lists[$label] = [
            'count' => (clone $query)->count(),
            'care_ids' => (clone $query)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->values(),
        ];
    }

    $completion = null;
    $review = [];
    foreach (['today', 'upcoming', 'overdue'] as $caseName) {
        $care = CustomerCare::query()->findOrFail($manifest['cases'][$caseName]['care_id']);
        try {
            $writeAccess->authorize($user, $care, 'completion');
            $completion[$caseName] = 'allowed';
        } catch (Throwable $exception) {
            $completion[$caseName] = 'DENIED: '.$exception->getMessage();
        }
    }
    foreach (['pending_edit', 'approved_edit'] as $caseName) {
        $care = CustomerCare::query()->findOrFail($manifest['cases'][$caseName]['care_id']);
        foreach ([true => 'approve', false => 'reject'] as $isAccept => $label) {
            try {
                $writeAccess->authorizeApprovalDecision($user, $care, (bool) $isAccept);
                $review[$caseName][$label] = 'allowed';
            } catch (Throwable $exception) {
                $review[$caseName][$label] = 'DENIED: '.$exception->getMessage();
            }
        }
    }

    echo json_encode([
        'user_id' => (int) $user->getKey(),
        'role' => $user->role?->slug,
        'shop_access_ids' => $shopAccess->ids($user)->values(),
        'lists' => $lists,
        'completion_write_access' => $completion,
        'edit_review_write_access' => $review,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
}

function ducVietReadManifest(string $manifestPath): array
{
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (! is_array($manifest)
        || ! str_starts_with((string) ($manifest['marker'] ?? ''), 'CCPARITY-DUCVIET-')
        || empty($manifest['cases'])
        || empty($manifest['backup'])) {
        ducVietFail('The Đức Việt manifest is invalid.');
    }

    return $manifest;
}

function ducVietPrintManifest(array $manifest): void
{
    echo json_encode([
        'marker' => $manifest['marker'],
        'seeded_at' => $manifest['seeded_at'],
        'user' => $manifest['user'],
        'shop' => $manifest['shop'],
        'cases' => $manifest['cases'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
}

function ducVietFail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
