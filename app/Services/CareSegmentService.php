<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CareSegmentService
{
    public function report(User $actor, array $filters): array
    {
        $access = app(ShopAccessService::class);
        $shopIds = $filters['shop_ids'] ?? ($access->isGlobal($actor) ? null : $access->ids($actor)->all());
        foreach ($shopIds ?? [] as $id) {
            $access->authorizeRequestedShopId($actor, (int) $id);
        }
        $start = Carbon::parse($filters['month_from'].'-01')->startOfMonth();
        $end = Carbon::parse($filters['month_to'].'-01')->addMonth()->startOfMonth();
        $orders = Order::query()->whereIn('status', [3, 16])
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->when($shopIds !== null, fn ($q) => $q->whereIn('shop_id', $shopIds));
        $sourceRows = (clone $orders)->whereNotNull('pancake_order_page_id')
            ->whereRaw("TRIM(pancake_order_page_id) <> ''")
            ->select(['shop_id', 'pancake_order_page_id', 'pancake_order_page_name'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY shop_id, pancake_order_page_id ORDER BY created_at DESC, id DESC) AS source_rank');
        $sources = DB::query()->fromSub($sourceRows->toBase(), 'segment_sources')->where('source_rank', 1)
            ->selectRaw("shop_id, pancake_order_page_id AS id, COALESCE(NULLIF(TRIM(pancake_order_page_name), ''), pancake_order_page_id) AS name")
            ->orderBy('name')->orderBy('id')->get();
        if (! empty($filters['sources_only'])) {
            return ['rows' => [], 'products' => [], 'unmatched_orders' => 0, 'sources' => $sources];
        }
        if (! empty($filters['order_page_ids'])) {
            $orders->whereIn('pancake_order_page_id', $filters['order_page_ids']);
        }
        $groups = [];
        $options = [];
        $skipped = 0;
        $selected = $filters['products'] ?? [];

        $orders->select(['id', 'shop_id', 'customer_id', 'pancake_customer_id', 'customer_name',
            'customer_phone', 'customer_address', 'created_at', 'pancake_full_data', 'pancake_order_id'])
            ->chunkById(300, function ($orders) use (&$groups, &$options, &$skipped, $selected): void {
                $customers = Customer::whereIn('shop_id', $orders->pluck('shop_id'))
                    ->where(function ($q) use ($orders): void {
                        $q->whereIn('id', $orders->pluck('customer_id')->filter())
                            ->orWhereIn('pancake_customer_id', $orders->pluck('pancake_customer_id')->filter());
                    })->get(['id', 'shop_id', 'pancake_customer_id', 'name', 'phone_numbers', 'assigned_user_id']);
                $byId = $customers->keyBy('id');
                $byExternal = $customers->filter(fn ($c) => filled($c->pancake_customer_id))
                    ->groupBy(fn ($c) => $c->shop_id.':'.$c->pancake_customer_id);
                foreach ($orders as $order) {
                    $matches = $byExternal->get($order->shop_id.':'.$order->pancake_customer_id, collect());
                    $customer = $order->customer_id ? $byId->get($order->customer_id)
                        : ($matches->count() === 1 ? $matches->first() : null);
                    if (! $customer || (int) $customer->shop_id !== (int) $order->shop_id) {
                        $skipped++;
                        continue;
                    }
                    $items = [];
                    foreach ($order->pancake_full_data['items'] ?? [] as $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        $name = trim((string) (data_get($item, 'variation_info.name') ?: ($item['name'] ?? '')));
                        $quantity = $item['quantity'] ?? null;
                        if ($name === '' || ! is_numeric($quantity) || $quantity <= 0) {
                            continue;
                        }
                        $key = sha1(mb_strtolower($name));
                        $code = (string) (data_get($item, 'variation_info.display_id') ?? '');
                        $options[$key] = ['value' => $key, 'label' => $name, 'code' => $code];
                        if ($selected !== [] && ! in_array($key, $selected, true)) {
                            continue;
                        }
                        $items[$key] ??= ['name' => $name, 'quantity' => 0];
                        $items[$key]['quantity'] += (float) $quantity;
                    }
                    if ($items === []) {
                        continue;
                    }
                    $key = $customer->shop_id.':'.$customer->id;
                    $groups[$key] ??= [
                        'key' => $key, 'customer_id' => $customer->id, 'shop_id' => $customer->shop_id,
                        'name' => $customer->name, 'phone' => $customer->phone_numbers,
                        'assigned_user_id' => $customer->assigned_user_id,
                        'quantity' => 0, 'order_count' => 0, 'last_purchase_at' => null,
                        'products' => [], 'orders' => [],
                    ];
                    $group = &$groups[$key];
                    $group['order_count']++;
                    $date = $order->created_at->format('Y-m-d H:i:s');
                    $group['last_purchase_at'] = max($date, $group['last_purchase_at'] ?? '');
                    foreach ($items as $id => $item) {
                        $group['products'][$id] ??= ['name' => $item['name'], 'quantity' => 0];
                        $group['products'][$id]['quantity'] += $item['quantity'];
                        $group['quantity'] += $item['quantity'];
                    }
                    $group['orders'][] = ['id' => $order->id, 'reference' => $order->pancake_order_id ?: 'POS-'.$order->id,
                        'created_at' => $date, 'products' => array_values($items)];
                    unset($group);
                }
            });

        $rows = array_values(array_filter($groups, function ($row) use ($filters, $selected): bool {
            if (($filters['match'] ?? 'any') === 'all' && array_diff($selected, array_keys($row['products'])) !== []) {
                return false;
            }
            if (isset($filters['quantity_min']) && $row['quantity'] < $filters['quantity_min']) {
                return false;
            }
            if (isset($filters['quantity_max']) && $row['quantity'] > $filters['quantity_max']) {
                return false;
            }
            if (isset($filters['last_purchase_before']) && substr($row['last_purchase_at'], 0, 10) > $filters['last_purchase_before']) {
                return false;
            }
            $search = mb_strtolower(trim($filters['search'] ?? ''));
            return $search === '' || str_contains(mb_strtolower($row['name'].' '.$row['phone']), $search);
        }));
        usort($rows, fn ($a, $b) => ($b['quantity'] <=> $a['quantity']) ?: strcmp($a['key'], $b['key']));
        $shops = DB::table('shops')->whereIn('id', array_column($rows, 'shop_id'))->pluck('name', 'id');
        $assignees = User::whereIn('pancake_user_id', array_filter(array_column($rows, 'assigned_user_id')))
            ->get(['id', 'pancake_user_id', 'name'])->keyBy('pancake_user_id');
        foreach ($rows as &$row) {
            $row['shop_name'] = $shops[$row['shop_id']] ?? '—';
            $row['staff_name'] = $assignees->get($row['assigned_user_id'])?->name ?? 'Chưa gán';
            $row['products'] = array_values($row['products']);
            unset($row['assigned_user_id']);
        }
        unset($row);
        $options = array_values($options);
        usort($options, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));
        return ['rows' => $rows, 'products' => $options, 'unmatched_orders' => $skipped, 'sources' => $sources];
    }
}
