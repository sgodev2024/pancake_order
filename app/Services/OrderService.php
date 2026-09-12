<?php

namespace App\Services;

use App\Models\Province;
use Carbon\Carbon;

class OrderService
{
    public function getOrderItem($data_item, $shop_id)
    {
        $total_quantity = 0;
        if (! empty($data_item['items'])) {
            $total_quantity = collect($data_item['items'])->sum('quantity');
        }
        $created_at = Carbon::parse($data_item['inserted_at'], 'UTC')->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s');
        $updated_at = Carbon::parse($data_item['updated_at'], 'UTC')->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s');
        if (! empty($data_item['shipping_address']['province_id'])) {
            $province = Province::where('id', $data_item['shipping_address']['province_id'])
                ->orWhere('new_id', $data_item['shipping_address']['province_id'])
                ->first();
        }

        return [
            'shop_id' => $shop_id,
            'province_id' => ! empty($province) ? $province->id : null,
            'order_number_vtp' => $data_item['partner']['order_number_vtp'] ?? null,
            'pancake_order_id' => $data_item['id'].'_'.$shop_id,
            'pancake_order_source_id' => OrderSourceNormalizer::id($data_item['order_sources'] ?? null),
            'pancake_order_source_name' => OrderSourceNormalizer::name($data_item['order_sources_name'] ?? null),
            'pancake_order_page_id' => OrderPageNormalizer::id($data_item),
            'pancake_order_page_name' => OrderPageNormalizer::name($data_item),
            'total_quantity' => $total_quantity,
            'cod' => $data_item['cod'] ?? 0,
            'discount_percent' => get_discount_by_customer($data_item['customer']['id']),
            'cash' => $data_item['cash'] ?? 0,
            'note' => $data_item['note'] ?? null,
            'user_creator_id' => $data_item['creator']['id'] ?? $data_item['assigning_seller']['id'] ?? $data_item['assigning_care']['id'] ?? null,
            'user_care_id' => $data_item['assigning_care']['id'] ?? null,
            'user_assigning_seller_id' => $data_item['assigning_seller']['id'] ?? null,
            'pancake_customer_id' => $data_item['customer']['customer_id'] ?? null,
            'status' => $data_item['status'],
            'pancake_full_data' => json_encode($data_item),
            'received_at_shop' => $data_item['received_at_shop'],
            'customer_name' => get_customer_name($data_item),
            'customer_phone' => $data_item['bill_phone_number'] ?? null,
            'customer_address' => $data_item['shipping_address']['full_address'] ?? null,
            'created_at' => $created_at,
            'updated_at' => $updated_at,
        ];
    }
}
