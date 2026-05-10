<?php

namespace App\Jobs;

use App\Models\CustomerCare;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AddCustomerCareJob implements ShouldQueue
{
    use Queueable;

    protected $shop_id;

    protected $care_cycle_days;

    protected $pancake_order_id;

    protected $order_created_at;

    protected $customer_name;

    protected $customer_phone_numbers;

    protected $customer_address;

    protected $pancake_customer_id;

    protected $user_creator_id;

    protected $user_care_id;

    protected $user_assigning_seller_id;

    /**
     * Create a new job instance.
     */
    public function __construct(
        $shop_id,
        $care_cycle_days,
        $pancake_order_id,
        $order_created_at,
        $customer_name,
        $customer_phone_numbers,
        $customer_address,
        $pancake_customer_id,
        $user_creator_id,
        $user_care_id,
        $user_assigning_seller_id
    )
    {
        $this->shop_id                  = $shop_id;
        $this->care_cycle_days          = $care_cycle_days;
        $this->pancake_order_id         = $pancake_order_id;
        $this->order_created_at         = $order_created_at;
        $this->customer_name            = $customer_name;
        $this->customer_phone_numbers   = $customer_phone_numbers;
        $this->customer_address         = $customer_address;
        $this->pancake_customer_id      = $pancake_customer_id;
        $this->user_creator_id          = $user_creator_id;
        $this->user_care_id             = $user_care_id;
        $this->user_assigning_seller_id = $user_assigning_seller_id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            CustomerCare::create([
                "shop_id"                  => $this->shop_id,
                "pancake_customer_id"      => $this->pancake_customer_id,
                "customer_phones"          => $this->customer_phone_numbers,
                "customer_name"            => $this->customer_name,
                "customer_addresss"        => $this->customer_address,
                "pancake_order_id"         => $this->pancake_order_id,
                "date_care"                => Carbon::parse($this->order_created_at)->addDays($this->care_cycle_days)->format("Y-m-d"),
                "user_creator_id"          => $this->user_creator_id,
                "user_care_id"             => $this->user_care_id,
                "user_assigning_seller_id" => $this->user_assigning_seller_id
            ]);
        } catch (\Throwable $th) {
            Log::channel("pancake-webhook-error")->info("=================AddCustomerCareJob==============");
            Log::channel("pancake-webhook-error")->info($th->getMessage());
        }
    }
}
