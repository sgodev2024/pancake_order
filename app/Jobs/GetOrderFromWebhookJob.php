<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Shop;
use App\Services\CustomerEnteredSystemEventWriter;
use App\Services\OrderCreatedEventWriter;
use App\Services\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class GetOrderFromWebhookJob implements ShouldQueue
{
    use Queueable;

    protected $data;

    /**
     * Create a new job instance.
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(
        CustomerEnteredSystemEventWriter $eventWriter,
        OrderCreatedEventWriter $orderCreatedEventWriter
    ): void {
        try {
            if (empty($this->data['shop_id'])) {
                \App\Services\WebhookMonitor::record('skipped', null, 'Thiếu shop_id');
                Log::channel('pancake-webhook-error')->info('=================not found shop_id==============');
                Log::channel('pancake-webhook-error')->info($this->data);

                return;
            }

            $saved = DB::transaction(function () use ($eventWriter, $orderCreatedEventWriter) {
                $pancakeShopId = $this->data['shop_id'];
                $shop = Shop::where('pancake_shop_id', $pancakeShopId)->first();

                if (! $shop) {
                    \App\Services\WebhookMonitor::record('skipped', $pancakeShopId, 'Shop chưa được cấu hình');
                    return false;
                }

                $orderService = new OrderService;
                $result = $orderService->getOrderItem($this->data, $shop->id);
                if (Schema::hasColumn('orders', 'prepaid_amount')) {
                    $result['prepaid_amount'] = $this->data['prepaid'] ?? 0;
                }

                if (empty($result['order_number_vtp'])) {
                    \App\Services\WebhookMonitor::record('skipped', $pancakeShopId, 'Đơn không có mã vận đơn VTP');
                    return false;
                }

                $result['pancake_full_data'] = json_decode($result['pancake_full_data'], true); // format lại vì dùng create/update
                // Preserve pre-Phase-5 semantics: a soft-deleted order is
                // not an active match, so this webhook may create a new
                // active Order instead of mutating the trashed row.
                $order = Order::query()
                    ->where('shop_id', $shop->id)
                    ->where('pancake_order_id', $result['pancake_order_id'])
                    ->first();
                $customer = $this->data['customer'] ?? [];
                $customerId = $customer['customer_id'] ?? null;
                $hasLastOrderAt = Schema::hasColumn('customers', 'last_order_at');
                $lastOrderAt = Carbon::parse(
                    $customer['last_order_at']
                        ?? $this->data['inserted_at']
                        ?? $this->data['created_at']
                        ?? now()
                );
                $customerColumns = ['id', 'phone_numbers', 'pancake_customer_id', 'name', 'shop_id'];
                if ($hasLastOrderAt) {
                    $customerColumns[] = 'last_order_at';
                }
                $customerExist = $customerId === null
                    ? null
                    : Customer::select($customerColumns)
                        ->where('shop_id', $shop->id)
                        ->where('pancake_customer_id', $customerId)
                        ->first();

                if (! $customerExist && $customerId !== null) {
                    $acquisitionChannel = $this->acquisitionChannel($customer);
                    $newCustomerData = [
                        'order_count' => $customer['order_count'] ?? 0,
                        'assigned_user_id' => $this->data['assigning_care_id'] ?? null,
                        'shop_id' => $shop->id,
                        'purchased_amount' => $customer['purchased_amount'] ?? 0,
                        'pancake_customer_id' => $customerId,
                        'fb_id' => $customer['fb_id'] ?? null,
                        'loyalty_tier_id' => get_loyalty_tier($customer['purchased_amount'] ?? 0),
                        'name' => $customer['name'] ?? null,
                        'phone_numbers' => ! empty($this->data['bill_phone_number'])
                            ? $this->data['bill_phone_number']
                            : implode(',', ($customer['phone_numbers'] ?? [])),
                        'pancake_full_data' => $customer,
                    ];
                    if ($hasLastOrderAt) {
                        $newCustomerData['last_order_at'] = $lastOrderAt;
                    }
                    $newCustomer = Customer::create($newCustomerData);

                    $eventWriter->write(
                        $newCustomer,
                        'pancake_order_webhook',
                        $newCustomer->created_at,
                        $acquisitionChannel
                    );
                    $customerExist = $newCustomer;
                } elseif ($customerExist) {
                    $customerUpdates = [
                        'order_count' => $customer['order_count'] ?? $customerExist->order_count,
                        'assigned_user_id' => $customer['assigned_user_id'] ?? null,
                        'name' => $customer['name'] ?? $customerExist->name,
                        'loyalty_tier_id' => get_loyalty_tier($customer['purchased_amount'] ?? 0),
                        'purchased_amount' => $customer['purchased_amount'] ?? $customerExist->purchased_amount,
                        'phone_numbers' => $this->data['bill_phone_number'] ?? $customerExist->phone_numbers,
                    ];
                    if ($hasLastOrderAt) {
                        $customerUpdates['last_order_at'] = $customerExist->last_order_at?->greaterThan($lastOrderAt)
                            ? $customerExist->last_order_at
                            : $lastOrderAt;
                    }
                    $customerExist->update($customerUpdates);
                }

                $isNewOrder = $order === null;
                if ($order) {
                    unset($result['pancake_order_id']);
                    $order->update($result);
                } else {
                    $order = Order::create($result);
                    $orderCreatedEventWriter->write(
                        $order,
                        'pancake_order_webhook',
                        $order->created_at,
                        $customerExist
                    );

                    if (! empty($this->data['bill_phone_number'])) {
                        SendZnsJob::dispatch(
                            $this->data['bill_phone_number'],
                            $customer,
                        )->onQueue('send-zns')->afterCommit();
                    }
                }

                if ($isNewOrder && $order->status != 3) {
                    AddCustomerCareJob::dispatch(
                        $shop->id,
                        $shop->care_cycle_days,
                        $order->pancake_order_id,
                        $order->created_at,
                        $customerExist?->name ?? $customer['name'] ?? null,
                        $customerExist?->phone_numbers ?? $this->data['bill_phone_number'] ?? null,
                        // CustomerCare must inherit the authoritative address
                        // persisted on the exact local Order. The customer
                        // profile address list can be empty or stale even
                        // when the order shipping address is present.
                        $order->customer_address,
                        $customerExist?->pancake_customer_id ?? $customerId,
                        $order->user_creator_id,
                        $order->user_care_id,
                        $order->user_assigning_seller_id
                    )->onQueue('add-customer-care')->afterCommit();
                }
                return true;
            });
            if ($saved) \App\Services\WebhookMonitor::record('processed', $this->data['shop_id']);

            Log::channel('pancake-webhook-success')->info('=================Thành công GetOrderFromWebhookJob==============');

            return;
        } catch (\Throwable $th) {
            $reason = $th instanceof \Illuminate\Database\QueryException ? 'Lỗi cơ sở dữ liệu' : (str_starts_with($th->getMessage(), 'Undefined array key') ? $th->getMessage() : 'Xử lý thất bại: '.get_class($th));
            \App\Services\WebhookMonitor::record('error', $this->data['shop_id'] ?? null, $reason);
            Log::channel('pancake-webhook-error')->info('=================Lỗi GetOrderFromWebhookJob==============');
            Log::channel('pancake-webhook-error')->info($th->getMessage());
            Log::channel('pancake-webhook-error')->info($this->data);

            throw $th;
        }
    }

    private function acquisitionChannel(array $customer): ?string
    {
        $channel = $customer['acquisition_channel'] ?? null;

        if (! is_string($channel)) {
            return null;
        }

        $channel = trim($channel);

        return $channel === '' ? null : $channel;
    }
}
