<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Validator;

class AicrmOrderPayload
{
    public function make(Order $order): array
    {
        $items = collect(data_get($order->pancake_full_data, 'items', []));
        $lines = $items->map(fn ($item) => [
            'name' => is_array($item) ? (data_get($item, 'variation_info.name') ?? $item['name'] ?? data_get($item, 'product.name')) : null,
            'quantity' => is_array($item) ? ($item['quantity'] ?? null) : null,
        ])->values()->all();
        Validator::make(['items' => $lines], [
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ])->validate();
        // Same accounting as the order screen: COD + prepaid; cash is a receipt,
        // not the order value. POS has an authoritative total independent of cod_mode.
        $prepaid = $order->prepaid_amount ?? data_get($order->pancake_full_data, 'prepaid', 0);
        Validator::make(['cod' => $order->cod, 'prepaid' => $prepaid], [
            'cod' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'prepaid' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
        ])->validate();
        $total = $order->origin === 'local_pos'
            ? data_get($order->pancake_full_data, 'pos_totals.total_amount')
            : round((float) $order->cod + (float) $prepaid, 2);
        $payload = [
            'receiveFullName' => trim((string) $order->customer_name),
            'receiverPhone' => trim((string) $order->customer_phone),
            'receiverAddress' => trim((string) $order->customer_address),
            'productName' => implode('; ', array_map(fn ($line) => $line['name'].' x'.$line['quantity'], $lines)),
            'quantity' => array_sum(array_column($lines, 'quantity')),
            'totalPrice' => $total,
            'moneyCollection' => $order->cod,
            'shippingProviderId' => config('aicrm.mode') === 'mock' ? config('aicrm.shipping_provider_id')
                : app(AicrmTenantCredentials::class)->forShop((int) $order->shop_id, config('aicrm.mode') === 'live')['shipping_provider_id'],
        ];
        Validator::make($payload, [
            'receiveFullName' => ['required', 'string', 'max:255'],
            'receiverPhone' => ['required', 'string', 'regex:/^\+?[0-9]{9,15}$/'],
            'receiverAddress' => ['required', 'string', 'max:1000'],
            'productName' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'totalPrice' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'moneyCollection' => ['required', 'numeric', 'min:0', 'max:999999999999', 'lte:totalPrice',
                function ($attribute, $value, $fail) {
                    if (! is_numeric($value) || (float) $value !== floor((float) $value)) {
                        $fail('Tiền thu hộ phải là số nguyên VND, không có phần lẻ.');
                    }
                }],
            'shippingProviderId' => ['required', 'integer', 'min:1'],
        ], [
            'productName.max' => 'Tên sản phẩm gửi AICRM (gồm số lượng và dấu phân cách) tối đa 255 ký tự. Vui lòng rút gọn tên trước khi tạo vận đơn.',
            'moneyCollection.min' => 'Tiền thu hộ phải là số nguyên VND không âm.',
        ])->validate();
        $payload['totalPrice'] = round((float) $payload['totalPrice'], 2);
        $payload['moneyCollection'] = (int) $payload['moneyCollection'];
        $payload['shippingProviderId'] = (int) $payload['shippingProviderId'];
        $payload['quantity'] = (int) $payload['quantity'];

        return $payload;
    }
}
