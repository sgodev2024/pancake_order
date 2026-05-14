<?php

namespace App\Jobs;

use App\Models\ApiKey;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendZnsJob implements ShouldQueue
{
    use Queueable;

    protected $phone_numbers;

    protected $bill_phone_number;

    protected $customer;

    /**
     * Create a new job instance.
     */
    public function __construct(
        $phone_numbers,
        $bill_phone_number,
        $customer
    )
    {
        $this->phone_numbers = $phone_numbers;
        $this->bill_phone_number = $bill_phone_number;
        $this->customer = $customer;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        return;
        try {
            $new_phones = [$this->bill_phone_number];
            // $customer = $this->customer;
            // if (!empty($this->phone_numbers)) {
            //     $new_phones = [...$this->phone_numbers];
            // }
            // if (!empty($this->bill_phone_number)) {
            //     $new_phones = [...$new_phones, $this->bill_phone_number];
            // }
            if (!empty($new_phones)) {
                $unique_phones = array_values(array_unique($new_phones));
                foreach ($unique_phones as $phone_number) {
                    $response = Http::withHeader("x-api-key", env("API_KEY_FROM_PANCAKE_WEB"))
                                    ->post("https://aicrm.vn/api/send-zns", [
                                        "username"     => "sgovn",
                                        "template_id"  =>  "",
                                        "phone"        => $phone_number,
                                        "name"         => $customer["name"],
                                        "email"        => "",
                                        "dob"          => "",
                                        "custom_field" => "",
                                        "address"      => "",
                                        "source"       => "",
                                        "product_id"   =>  $customer["new_full_address"] ?? ""
                                    ])
                                    ->json();
                    Log::channel("zns")->info($response);
                } 
            }
            Log::channel("zns")->info("=============Thành công SendZnsJob =============");
            return;
        } catch (\Throwable $th) {
            Log::channel("zns")->info("=============Thất bại SendZnsJob =============");
            Log::channel("zns")->info($th->getMessage());

            return;
        }
    }
}
