<?php

namespace App\Jobs;

use App\Mail\UserCredentialsMail;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
                $user["pancake_full_data"] = $data_item;
                $raw_password = "12345678";
                $user["password"] = Hash::make($raw_password);
                $user["role_id"] = 3; // 1 = admin, 2= manager, 3=nhân viên
                $user_exist = User::where("pancake_user_id", $user["pancake_user_id"])->first();
                if (empty($user_exist)) {
                    $user_exist = User::create($user);
                    if (!empty($data_item["user"]["email"])) {
                        Mail::to($user_exist->email)->send(new UserCredentialsMail($user_exist, $raw_password));
                    }
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
