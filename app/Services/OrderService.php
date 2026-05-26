<?php

namespace App\Services;

use Carbon\Carbon;

class OrderService
{
    public function getOrderItem($data_item, $shop_id)
    {
        $total_quantity = 0;
        if (!empty($data_item["items"])) {
            $total_quantity = collect($data_item["items"])->sum("quantity");
        }
        $created_at = Carbon::parse($data_item["inserted_at"])->format("Y-m-d H:i:s");
        $updated_at = Carbon::parse($data_item["updated_at"])->format("Y-m-d H:i:s");
        
        return [
            "shop_id"                  => $shop_id,
            "order_number_vtp"         => $data_item["partner"]["order_number_vtp"] ?? NULL,
            "pancake_order_id"         => $data_item["id"],
            "total_quantity"           => $total_quantity,
            "cod"                      => $data_item["cod"] ?? 0,
            "cash"                     => $data_item["cash"] ?? 0,
            "note"                     => $data_item["note"] ?? NULL,
            "user_creator_id"          => $data_item["creator"]["id"] ?? NULL,
            "user_care_id"             => $data_item["assigning_care"]["id"] ?? NULL,
            "user_assigning_seller_id" => $data_item["assigning_seller"]["id"] ?? NULL,
            "pancake_customer_id"      => $data_item["customer"]["id"] ?? NULL,
            "status"                   => $data_item["status"],
            "pancake_full_data"        => json_encode($data_item),
            "received_at_shop"         => $data_item["received_at_shop"],
            "customer_name"            => $data_item["customer"]["name"] ?? NULL,
            "customer_phone"           => $data_item["bill_phone_number"] ?? NULL,
            "customer_address"         => $data_item["shipping_address"]["full_address"] ?? NULL,
            "created_at"               => $created_at,
            "updated_at"               => $updated_at
        ];
    }
}