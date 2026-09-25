<?php

use App\Models\ActivityLog;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$manifestPath = storage_path('app/customer-care-parity-local-test-data.json');
$mode = $argv[1] ?? 'seed';

assertLocalDatabase();

match ($mode) {
    'seed' => seed($manifestPath),
    'status' => showStatus($manifestPath),
    'cleanup' => cleanup($manifestPath),
    default => fail("Unknown mode '{$mode}'. Use seed, status, or cleanup."),
};

function assertLocalDatabase(): void
{
    $connection = config('database.default');
    $host = (string) config("database.connections.{$connection}.host");
    $database = (string) config("database.connections.{$connection}.database");

    if (! app()->environment('local')) {
        fail('Refusing to run outside APP_ENV=local.');
    }

    if (! in_array(strtolower($host), ['127.0.0.1', 'localhost'], true)) {
        fail("Refusing to run against non-local DB host '{$host}'.");
    }

    echo "Environment: local\nDatabase: {$database} @ {$host}\n";
}

function seed(string $manifestPath): void
{
    if (is_file($manifestPath)) {
        echo "A manifest already exists. Reusing the existing seed.\n";
        showStatus($manifestPath);

        return;
    }

    $selection = selectReusableContext();
    $marker = 'CCPARITY-'.now(config('app.timezone'))->format('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
    $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
    $caseDefinitions = [
        'today' => [
            'date' => $today,
            'status' => 0,
            'is_accept' => 1,
            'total_edit' => 0,
            'completed' => false,
            'route' => '/kh-cham-soc-hom-nay',
            'expected_actions' => [
                'Xem chi tiết',
                'Xác nhận đã chăm sóc',
                'Từ chối sửa CSKH',
                'Thu hồi',
                'Lịch sử đơn hàng',
                'Lịch sử chăm sóc',
            ],
        ],
        'upcoming' => [
            'date' => $today->addDay(),
            'status' => 0,
            'is_accept' => 1,
            'total_edit' => 0,
            'completed' => false,
            'route' => '/lich-cham-soc-sap-dien-ra',
            'expected_actions' => [
                'Xem chi tiết',
                'Xác nhận đã chăm sóc',
                'Từ chối sửa CSKH',
                'Thu hồi',
                'Lịch sử đơn hàng',
                'Lịch sử chăm sóc',
            ],
        ],
        'overdue' => [
            'date' => $today->subDay(),
            'status' => 0,
            'is_accept' => 1,
            'total_edit' => 0,
            'completed' => false,
            'route' => '/khach-cham-soc-qua-han',
            'expected_actions' => [
                'Xem chi tiết',
                'Xác nhận đã chăm sóc',
                'Từ chối sửa CSKH',
                'Thu hồi',
                'Lịch sử đơn hàng',
                'Lịch sử chăm sóc',
            ],
        ],
        'pending_edit' => [
            'date' => $today->subDays(10),
            'status' => 1,
            'is_accept' => 0,
            'total_edit' => 2,
            'completed' => true,
            'route' => '/yeu-cau-sua-cskh',
            'expected_actions' => [
                'Xem chi tiết',
                'Duyệt sửa CSKH',
                'Lịch sử đơn hàng',
                'Lịch sử chăm sóc',
            ],
        ],
        'approved_edit' => [
            'date' => $today->subDays(10),
            'status' => 1,
            'is_accept' => 1,
            'total_edit' => 2,
            'completed' => true,
            'route' => '/yeu-cau-sua-cskh',
            'expected_actions' => [
                'Xem chi tiết',
                'Từ chối sửa CSKH',
                'Lịch sử đơn hàng',
                'Lịch sử chăm sóc',
            ],
        ],
    ];

    $sequenceSnapshots = collect($selection['orders'])->mapWithKeys(function (Order $order) use ($selection) {
        $key = 'source:'.$order->getKey();
        $row = Schema::hasTable('customer_care_journey_sequences')
            ? DB::table('customer_care_journey_sequences')
                ->where('shop_id', $selection['shop_id'])
                ->where('sequence_scope', 'order_source')
                ->where('scope_key', $key)
                ->first()
            : null;

        return [(string) $order->getKey() => [
            'shop_id' => $selection['shop_id'],
            'sequence_scope' => 'order_source',
            'scope_key' => $key,
            'existed' => $row !== null,
            'last_sequence' => $row?->last_sequence,
        ]];
    })->all();

    $manifest = DB::transaction(function () use (
        $caseDefinitions,
        $marker,
        $selection,
        $sequenceSnapshots
    ) {
        $cases = [];

        foreach (array_values(array_keys($caseDefinitions)) as $index => $caseName) {
            $definition = $caseDefinitions[$caseName];
            /** @var Order $order */
            $order = $selection['orders'][$index];
            /** @var User $assignee */
            $assignee = $selection['assignee'];
            $completedAt = $definition['completed']
                ? $definition['date']->setTime(10, 0)
                : null;
            $displayName = $marker.':'.strtoupper($caseName);

            $care = CustomerCare::create([
                'shop_id' => $selection['shop_id'],
                'pancake_customer_id' => $marker.'-CUSTOMER-'.($index + 1),
                'customer_phones' => json_encode(
                    filled($order->customer_phone) ? [$order->customer_phone] : [],
                    JSON_UNESCAPED_UNICODE
                ),
                'customer_name' => $displayName,
                'customer_addresss' => $order->customer_address,
                'pancake_order_id' => $order->pancake_order_id,
                'date_care' => $definition['date']->toDateString(),
                'note' => $definition['completed'] ? "LOCAL parity {$caseName}" : null,
                'user_creator_id' => $assignee->pancake_user_id,
                'user_care_id' => $assignee->pancake_user_id,
                'user_assigning_seller_id' => $assignee->pancake_user_id,
                'status' => $definition['status'],
                'time_care' => $completedAt,
                'is_accept' => $definition['is_accept'],
                'user_accept_id' => $definition['is_accept'] === 1 && $definition['total_edit'] > 1
                    ? $selection['admin']->getKey()
                    : null,
                'total_edit' => $definition['total_edit'],
                'reason' => $definition['total_edit'] > 1 && $definition['is_accept'] === 1
                    ? 'LOCAL parity approved seed'
                    : null,
                'is_confirm_care' => false,
            ]);

            $assignment = CustomerCareAssignment::create([
                'shop_id' => $selection['shop_id'],
                'customer_care_id' => $care->getKey(),
                'source_type' => CustomerCareAssignment::SOURCE_ORDER,
                'source_id' => $order->getKey(),
                'assignee_user_id' => $assignee->getKey(),
                'assignee_pancake_user_id' => $assignee->pancake_user_id,
                'assigned_at' => $definition['completed']
                    ? $definition['date']->subDays(3)->setTime(9, 0)
                    : now(config('app.timezone')),
                'reclaim_eligible_on' => CustomerCareAssignment::calculateReclaimEligibleOn($definition['date']),
                'status' => CustomerCareAssignment::STATUS_ACTIVE,
                'cared_at' => $completedAt,
            ]);

            $cases[$caseName] = [
                'care_id' => $care->getKey(),
                'assignment_id' => $assignment->getKey(),
                'order_id' => $order->getKey(),
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
                'expected_actions' => $definition['expected_actions'],
            ];
        }

        return [
            'marker' => $marker,
            'seeded_at' => now(config('app.timezone'))->toISOString(),
            'database' => config('database.connections.'.config('database.default').'.database'),
            'shop' => [
                'id' => $selection['shop_id'],
                'name' => $selection['shop_name'],
            ],
            'assignee' => [
                'id' => $selection['assignee']->getKey(),
                'name' => $selection['assignee']->name,
                'email' => $selection['assignee']->email,
                'role' => $selection['assignee']->role->slug,
                'pancake_user_id' => $selection['assignee']->pancake_user_id,
            ],
            'login' => [
                'id' => $selection['admin']->getKey(),
                'name' => $selection['admin']->name,
                'email' => $selection['admin']->email,
                'role' => $selection['admin']->role->slug,
            ],
            'cases' => $cases,
            'sequence_snapshots' => $sequenceSnapshots,
        ];
    });

    if (! is_dir(dirname($manifestPath))) {
        mkdir(dirname($manifestPath), 0775, true);
    }
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL
    );

    echo "Created local Customer Care parity data.\n";
    printManifest($manifest);
}

function selectReusableContext(): array
{
    $admin = User::query()
        ->whereHas('role', fn ($query) => $query->where('slug', 'admin'))
        ->with('role:id,slug')
        ->orderBy('id')
        ->first();

    if ($admin === null) {
        fail('No existing admin user was found. No data was created.');
    }

    $assignees = User::query()
        ->whereNotNull('pancake_user_id')
        ->where('pancake_user_id', '!=', '')
        ->whereHas('role', fn ($query) => $query->whereIn('slug', ['staff-cskh', 'manager-cskh']))
        ->whereHas('shops')
        ->with(['role:id,slug', 'shops:id,name'])
        ->orderBy('id')
        ->get();

    foreach ($assignees as $assignee) {
        foreach ($assignee->shops as $shop) {
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
                ->limit(100)
                ->get()
                ->unique('pancake_order_id')
                ->take(5)
                ->values();

            if ($orders->count() === 5) {
                return [
                    'admin' => $admin,
                    'assignee' => $assignee,
                    'shop_id' => (int) $shop->getKey(),
                    'shop_name' => $shop->name,
                    'orders' => $orders,
                ];
            }
        }
    }

    fail('No existing shop/CSKH assignee combination has five safe orders with source-page data. No data was created.');
}

function showStatus(string $manifestPath): void
{
    $manifest = readManifest($manifestPath);
    $marker = $manifest['marker'];
    $currentCares = CustomerCare::query()
        ->where('customer_name', 'like', $marker.':%')
        ->with('assignments')
        ->orderBy('id')
        ->get();

    printManifest($manifest);
    echo "Current marker records:\n";
    foreach ($currentCares as $care) {
        $assignment = $care->assignments->sortByDesc('id')->first();
        echo json_encode([
            'care_id' => $care->getKey(),
            'customer_name' => $care->customer_name,
            'date_care' => $care->date_care,
            'status' => (int) $care->status,
            'is_accept' => (int) $care->is_accept,
            'assignment_id' => $assignment?->getKey(),
            'assignee_user_id' => $assignment?->assignee_user_id,
            'assignment_status' => $assignment?->status,
            'cared_at' => $assignment?->cared_at?->format('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
    }
}

function cleanup(string $manifestPath): void
{
    $manifest = readManifest($manifestPath);
    $marker = $manifest['marker'];

    $deleted = DB::transaction(function () use ($manifest, $marker) {
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

        return [
            'customer_cares' => $deletedCares,
            'assignments' => $deletedAssignments,
            'activity_logs' => $deletedLogs,
        ];
    });

    unlink($manifestPath);
    echo 'Cleanup complete: '.json_encode($deleted, JSON_UNESCAPED_UNICODE).PHP_EOL;
}

function readManifest(string $manifestPath): array
{
    if (! is_file($manifestPath)) {
        fail("Manifest not found at {$manifestPath}. Run seed first.");
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (! is_array($manifest) || empty($manifest['marker']) || empty($manifest['cases'])) {
        fail('The local test-data manifest is invalid.');
    }

    return $manifest;
}

function printManifest(array $manifest): void
{
    echo json_encode([
        'marker' => $manifest['marker'],
        'seeded_at' => $manifest['seeded_at'],
        'shop' => $manifest['shop'],
        'login' => $manifest['login'],
        'assignee' => $manifest['assignee'],
        'cases' => $manifest['cases'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
}

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
