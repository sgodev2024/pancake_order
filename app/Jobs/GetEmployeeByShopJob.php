<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Models\ShopUser;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class GetEmployeeByShopJob implements ShouldQueue
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
        foreach ($this->datas as $data_item) {
            try {
                $user = $data_item["user"];
                $user["pancake_user_id"] = $user["id"];
                if (empty($user["email"])) {
                    $user["email"] = ($user["fb_id"] ? $user["fb_id"] : rand()) . "@gmail.com";
                }
                unset($user["id"]);
                $user["pancake_full_data"] = json_encode($data_item);
                $user["password"] = Hash::make("12345678");
                $user["role_id"] = 3; // 1 = admin, 2= manager, 3=nhân viên
                $user_exist = User::where("pancake_user_id", $user["pancake_user_id"])->first();
                if (empty($user_exist)) {
                    $user_exist = User::create($user);
                }
                ShopUser::updateOrCreate([
                    "user_id"    => $user_exist->id,
                    "shop_id"    => $this->shop_id
                ]);
            } catch (\Throwable $th) {
                Log::info($th->getMessage());
                Log::info($data_item);
            }
        }

        return;
    }

    public function viaQueue()
    {
        return 'get-user-pancake';
    }
}
