<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Order;
use App\Services\OrderCreatedEventWriter;
use App\Services\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class GetOrderByShopJob implements ShouldQueue
{
    use Queueable;

    protected $datas;

    protected $shop_id;

    // 🔁 Số lần retry
    public $tries = 3;

    // ⏱️ Thời gian delay giữa các lần retry (giây)
    public $backoff = [30, 60, 120]; // Retry sau 1, 3, 5 phút

    /**
     * Create a new job instance.
     */
    public function __construct(
        $datas,
        $shop_id
    ) {
        $this->datas = $datas;
        $this->shop_id = $shop_id;
    }

    /**
     * Execute the job.
     */
    public function handle(OrderCreatedEventWriter $eventWriter): void
    {
        try {
            DB::transaction(function () use ($eventWriter): void {
                $shopId = (int) $this->shop_id;
                $insertData = [];
                $storedOrderIds = [];
                $orderService = new OrderService;
                $hasPrepaidAmount = Schema::hasColumn('orders', 'prepaid_amount');

                foreach ($this->datas as $dataItem) {
                    // Preserve the existing bulk business filter: only VTP
                    // partner orders enter the local orders table.
                    if (empty($dataItem['partner']['order_number_vtp'])) {
                        continue;
                    }

                    $orderData = $orderService->getOrderItem($dataItem, $shopId);
                    if ($hasPrepaidAmount) {
                        $orderData['prepaid_amount'] = $dataItem['prepaid'] ?? 0;
                    }
                    $storedOrderId = (string) $orderData['pancake_order_id'];

                    // The persisted identity is external Pancake ID + local
                    // shop ID. Do not compare only the raw external ID.
                    if (isset($storedOrderIds[$storedOrderId])
                        || Order::withTrashed()
                            ->where('shop_id', $shopId)
                            ->where('pancake_order_id', $storedOrderId)
                            ->exists()) {
                        continue;
                    }

                    $storedOrderIds[$storedOrderId] = true;
                    $insertData[] = $orderData;
                }

                if ($insertData === []) {
                    return;
                }

                Order::insert($insertData);

                $insertedOrders = Order::query()
                    ->where('shop_id', $shopId)
                    ->whereIn('pancake_order_id', array_keys($storedOrderIds))
                    ->get();

                if ($insertedOrders->count() !== count($storedOrderIds)) {
                    throw new \RuntimeException(
                        'Could not resolve every newly inserted order for journey logging.'
                    );
                }

                $ordersByPancakeId = $insertedOrders->keyBy(
                    fn (Order $order): string => (string) $order->pancake_order_id
                );
                $customerIds = $insertedOrders
                    ->pluck('pancake_customer_id')
                    ->filter(fn ($id): bool => $id !== null && trim((string) $id) !== '')
                    ->map(fn ($id): string => (string) $id)
                    ->unique()
                    ->values();
                $customerColumns = ['id', 'shop_id', 'pancake_customer_id'];
                $hasLastOrderAt = Schema::hasColumn('customers', 'last_order_at');
                if ($hasLastOrderAt) {
                    $customerColumns[] = 'last_order_at';
                }
                $customersByPancakeId = $customerIds->isEmpty()
                    ? collect()
                    : Customer::query()
                        ->where('shop_id', $shopId)
                        ->whereIn('pancake_customer_id', $customerIds)
                        ->get($customerColumns)
                        ->keyBy(fn (Customer $customer): string => (string) $customer->pancake_customer_id);

                foreach (array_keys($storedOrderIds) as $storedOrderId) {
                    $order = $ordersByPancakeId->get($storedOrderId);

                    if (! $order instanceof Order) {
                        throw new \RuntimeException(
                            'Could not resolve a newly inserted order for journey logging.'
                        );
                    }

                    $customer = $customersByPancakeId->get((string) $order->pancake_customer_id);
                    if (
                        $hasLastOrderAt
                        &&
                        $customer instanceof Customer
                        && $order->created_at !== null
                        && ($customer->last_order_at === null || $order->created_at->greaterThan($customer->last_order_at))
                    ) {
                        $customer->updateQuietly(['last_order_at' => $order->created_at]);
                    }
                    $eventWriter->write(
                        $order,
                        'pancake_bulk_sync',
                        $order->created_at,
                        $customer instanceof Customer ? $customer : null
                    );
                }
            });

            return;
        } catch (\Throwable $th) {
            Log::info($th->getMessage());

            // A failed event must fail the job so the transaction is retried;
            // swallowing this error would leave an untracked new order.
            throw $th;
        }
    }
}
