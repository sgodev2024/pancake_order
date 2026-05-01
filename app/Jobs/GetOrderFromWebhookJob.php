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
            if ($shop) {
                $orderService = new OrderService();
                $result = $orderService->getOrderItem($this->data, $shop->id);
                $order = Order::select("id")->where("pancake_order_id", $result["pancake_order_id"])->first();
                if ($order) {
                    unset($result["pancake_order_id"]);
                    $order->update($result);
                } else {
                    Order::create($result);
                }
                $customer = $this->data["customer"];
                $customer_exist = Customer::select("id", "phone_numbers")->where("pancake_customer_id", $customer["id"])->first();
                if (!$customer_exist) {
                    Customer::create([
                        "shop_id"             => $shop->id,
                        "pancake_customer_id" => $customer["id"],
                        "fb_id"               => $customer["fb_id"],
                        "name"                => $customer["name"],
                        "phone_numbers"       => [$customer["bill_phone_number"]],
                    ]);
                } else {
                    $new_phone = $customer["bill_phone_number"];
                    // 1. Lấy mảng sđt cũ (nếu null thì trả về mảng rỗng để tránh lỗi)
                    $old_phones = $customer_exist->phone_numbers ?? [];
                    
                    // 2. Gộp mảng cũ với số mới
                    $merged_phones = [...$old_phones, $new_phone];
                    
                    // 3. Loại bỏ các số trùng lặp và reset lại index (key) của mảng
                    $unique_phones = array_values(array_unique($merged_phones));
                    $customer_exist->update([
                        "name"          => $customer["name"],
                        "phone_numbers" => $unique_phones
                    ]);
                }
            }

            return;
        } catch (\Throwable $th) {
            Log::channel("pancake-webhook")->info($th->getMessage());
        }
    }
}
