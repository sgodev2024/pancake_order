<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Shop;
use App\Services\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
    public function handle(): void
    {
        try {
            $pancake_shop_id = $this->data["shop_id"];
            $shop = Shop::where("pancake_shop_id", $pancake_shop_id)->first();
            $is_new_order = true;
            if ($shop) {
                $orderService = new OrderService();
                $result = $orderService->getOrderItem($this->data, $shop->id);
                $order = Order::select("id")->where("pancake_order_id", $result["pancake_order_id"])->first();
                $customer = $this->data["customer"];
                if ($order) {
                    $is_new_order = false;
                    unset($result["pancake_order_id"]);
                    $order->update($result);
                } else {
                    $order = Order::create($result);
                    if (!empty($this->data["bill_phone_number"])) {
                        SendZnsJob::dispatch(
                            $customer["phone_numbers"] ?? NULL,
                            $this->data["bill_phone_number"] ?? NULL,
                            $customer,
                            $this->data["shipping_address"]
                        )->onQueue("send-zns");
                    }
                    
                }
                $customer_exist = Customer::select("id", "phone_numbers", "pancake_customer_id", "name")->where("pancake_customer_id", $customer["id"])->first();
                if (!$customer_exist) {
                    $customer_exist = Customer::create([
                        "shop_id"             => $shop->id,
                        "pancake_customer_id" => $customer["id"],
                        "fb_id"               => $customer["fb_id"],
                        "name"                => $customer["name"],
                        "phone_numbers"       => $customer["phone_numbers"] ?? [$this->data["bill_phone_number"]],
                    ]);
                } else {
                    //$new_phones = $customer["phone_numbers"] ?? [$customer["bill_phone_number"]];
                    // 1. Lấy mảng sđt cũ (nếu null thì trả về mảng rỗng để tránh lỗi)
                    //$old_phones = $customer_exist->phone_numbers ?? [];
                    
                    // 2. Gộp mảng cũ với số mới
                    //$merged_phones = [...$old_phones, ...$new_phones];
                    
                    // 3. Loại bỏ các số trùng lặp và reset lại index (key) của mảng
                    //$unique_phones = array_values(array_unique($merged_phones));
                    $customer_exist->update([
                        "name"          => $customer["name"],
                        "phone_numbers" => [$this->data["bill_phone_number"] ?? ""]//$unique_phones
                    ]);
                }
                AddCustomerCareJob::dispatch(
                    $shop->id,
                    $shop->care_cycle_days,
                    $order->pancake_order_id,
                    $order->created_at,
                    $customer_exist->name,
                    $customer_exist->phone_numbers,
                    $customer["new_full_address"] ?? NULL,
                    $customer_exist->pancake_customer_id,
                    $order->user_creator_id,
                    $order->user_care_id,
                    $order->user_assigning_seller_id
                )->onQueue("add-customer-care");
            }
            Log::channel("pancake-webhook-error")->info("=================Thành công GetOrderFromWebhookJob==============");
            return;
        } catch (\Throwable $th) {
            Log::channel("pancake-webhook-error")->info("=================Lỗi GetOrderFromWebhookJob==============");
            Log::channel("pancake-webhook-error")->info($th->getMessage());
        }
    }
}
