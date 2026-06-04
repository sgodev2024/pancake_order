<?php

namespace App\Jobs;

use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

use function Symfony\Component\Clock\now;

class GetCustomerByShopJob implements ShouldQueue
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
    )
    {
        $this->datas = $datas;
        $this->shop_id = $shop_id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $insertData = [];
            foreach ($this->datas as $data_item) {
                $pancake_customer_id = $data_item['customer_id'];
                $customer = Customer::where("shop_id", $this->shop_id)->where("pancake_customer_id", $pancake_customer_id)->first();
                if (!$customer && $data_item["order_count"] > 0) {
                    $time = Carbon::parse($data_item["inserted_at"])->format("Y-m-d H:i:s");
                    $insertData[] = [
                        'order_count'         => $data_item["order_count"] ?? 0,
                        'shop_id'             => $this->shop_id,
                        'assigned_user_id'    => $data_item["assigned_user_id"],
                        'pancake_customer_id' => $pancake_customer_id,
                        'fb_id'               => $data_item['fb_id'] ?? null,
                        'name'                => $data_item['name'] ?? null,
                        'purchased_amount'    => $data_item["purchased_amount"] ?? 0,
                        'loyalty_tier_id'     => get_loyalty_tier($data_item["purchased_amount"] ?? 0),
                        'phone_numbers'       => !empty($data_item["phone_numbers"]) ? implode(",", $data_item["phone_numbers"]) : NULL,
                        'pancake_full_data'   => json_encode($data_item),
                        'created_at'          => $time,
                        'updated_at'          => $time,
                    ];
                } else {
                    $customer->update([
                        'order_count'         => $data_item["order_count"],
                        'purchased_amount'    => $data_item["purchased_amount"],
                        'pancake_full_data'   => json_encode($data_item),
                    ]);
                }
            }
            if (count($insertData)> 0) {
                Customer::insert($insertData);
                
                return;
            }

            return;
        } catch (\Throwable $th) {
            Log::info($th->getMessage());
        }
    }
}
