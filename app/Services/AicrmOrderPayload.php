<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AicrmOrderPayload
{
    public function validateRecipient(Order $order): void
    {
        $recipient = implode(' ', [$order->customer_name, $order->customer_address]);
        $normalized = strtoupper(Str::ascii($recipient));
        Validator::make($order->only(['customer_name', 'customer_phone', 'customer_address']), [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'regex:/^\+?[0-9]{9,15}$/D', 'not_regex:/^\+?0{3,}/', 'not_regex:/^\+?([0-9])\1{8,}$/D'],
            'customer_address' => ['required', 'string', 'min:10', 'max:1000'],
        ], ['customer_phone.regex' => 'Cần số điện thoại người nhận hợp lệ.',
            'customer_phone.not_regex' => 'Không dùng số điện thoại test như 0000000641 hoặc số lặp.'])->validate();
        abort_if(preg_match('/KHONG[\s_-]+GIAO[\s_-]+HANG|KHONG[\s_-]+THU[\s_-]+TIEN/', $normalized),
            422, 'Người nhận đang đánh dấu KHÔNG GIAO HÀNG/KHÔNG THU TIỀN. Cần đơn có người nhận hợp lệ, được phê duyệt.');
        abort_if(config('aicrm.mode') === 'local' && preg_match('/(?<![A-Z0-9])TEST(?![A-Z0-9])/', $normalized),
            422, 'Người nhận hoặc địa chỉ đánh dấu TEST chỉ được xem trước payload.');
    }

    public function make(Order $order, bool $simulation = false): array
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
            'totalPrice' => $total,
            'moneyCollection' => $order->cod,
        ];
        $rules = [
            'receiveFullName' => ['required', 'string', 'max:255'],
            'receiverPhone' => ['required', 'string', $simulation ? 'max:255' : 'regex:/^\+?[0-9]{9,15}$/'],
            'receiverAddress' => ['required', 'string', 'max:1000'],
            'productName' => ['required', 'string', 'max:255'],
            'totalPrice' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'moneyCollection' => ['required', 'numeric', 'min:0', 'max:999999999999', 'lte:totalPrice',
                function ($attribute, $value, $fail) {
                    if (! is_numeric($value) || (float) $value !== floor((float) $value)) {
                        $fail('Tiền thu hộ phải là số nguyên VND, không có phần lẻ.');
                    }
                }],
        ];
        Validator::make($payload, $rules, [
            'productName.max' => 'Tên sản phẩm gửi AICRM (gồm số lượng và dấu phân cách) tối đa 255 ký tự. Vui lòng rút gọn tên trước khi tạo vận đơn.',
            'moneyCollection.min' => 'Tiền thu hộ phải là số nguyên VND không âm.',
        ])->validate();
        $payload['totalPrice'] = round((float) $payload['totalPrice'], 2);
        $payload['moneyCollection'] = (int) $payload['moneyCollection'];

        return $payload;
    }
}
