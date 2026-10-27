<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Province;
use App\Models\Shop;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderService
{
    private const MAX_MONEY_CENTS = 99999999999999;

    public function createLocalOrder(User $actor, array $input): Order
    {
        $access = app(ShopAccessService::class);
        $access->authorizeRequestedShopId($actor, (int) $input['shop_id']);
        $hash = $this->localRequestHash($input);

        return DB::transaction(function () use ($actor, $input, $hash): Order {
            // Serialize submissions from this actor, including simultaneous first requests.
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $existing = Order::withTrashed()
                ->where('created_by_user_id', $actor->id)
                ->where('idempotency_key', $input['idempotency_key'])->first();
            if ($existing) {
                abort_if($existing->trashed() || ! hash_equals($existing->request_hash, $hash), 409,
                    'Khóa tạo đơn đã được sử dụng với dữ liệu khác. Không tạo thêm đơn.');

                return $existing;
            }
            Shop::whereKey($input['shop_id'])->sharedLock()->firstOrFail();
            $products = Product::where('shop_id', $input['shop_id'])
                ->whereIn('id', array_column($input['items'], 'product_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($products->count() !== count($input['items'])) {
                throw ValidationException::withMessages(['items' => 'Có sản phẩm không tồn tại hoặc không thuộc shop đã chọn.']);
            }

            $subtotal = 0;
            $items = [];
            foreach ($input['items'] as $index => $line) {
                $product = $products->get($line['product_id']);
                if ($product->price === null || $product->price < 0) {
                    throw ValidationException::withMessages(["items.{$index}" => 'Sản phẩm chưa có giá bán hợp lệ.']);
                }
                $price = self::moneyCents($product->price);
                if (isset($line['unit_price']) && self::moneyCents($line['unit_price']) !== $price) {
                    throw ValidationException::withMessages(["items.{$index}.unit_price" => 'Giá bán đã thay đổi. Hãy cập nhật giỏ hàng trước khi tạo đơn.']);
                }
                $quantity = (int) $line['quantity'];
                if ($price > intdiv(self::MAX_MONEY_CENTS - $subtotal, $quantity)) {
                    throw ValidationException::withMessages(['items' => 'Giá trị đơn vượt giới hạn tiền được hỗ trợ.']);
                }
                $lineTotal = $price * $quantity;
                $subtotal += $lineTotal;
                $images = data_get($product->pancake_full_data, 'images', []);
                $imageRemoved = (bool) data_get($product->pancake_full_data, 'image_removed', false);
                if (! $imageRemoved && (! is_array($images) || $images === [])) {
                    $image = data_get($product->pancake_full_data, 'product.image');
                    $images = is_string($image) && $image !== '' ? [$image] : [];
                }
                if ($imageRemoved) {
                    $images = [];
                }
                $items[] = [
                    'product_id' => $product->id,
                    'pancake_product_id' => $product->pancake_product_id,
                    'name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => self::moneyString($price),
                    'line_total' => self::moneyString($lineTotal),
                    'virtual_stock_before' => (int) $product->virtual_inventory_quantity,
                    'virtual_stock_after' => max(5, (int) $product->virtual_inventory_quantity - $quantity),
                    'variation_info' => ['name' => $product->name, 'images' => $images],
                ];
            }
            $discount = self::moneyCents($input['discount_amount'] ?? 0);
            $shipping = self::moneyCents($input['shipping_fee'] ?? 0);
            $surcharge = self::moneyCents($input['surcharge'] ?? 0);
            $paid = self::moneyCents($input['paid_amount'] ?? 0);
            if ($discount > $subtotal) {
                throw ValidationException::withMessages(['discount_amount' => 'Giảm giá không được vượt tiền hàng.']);
            }
            $total = $subtotal - $discount + $shipping + $surcharge;
            if ($total > self::MAX_MONEY_CENTS || $paid > $total) {
                throw ValidationException::withMessages(['paid_amount' => 'Tiền đã trả không được vượt tổng thanh toán hoặc tổng tiền vượt giới hạn.']);
            }
            $remaining = $total - $paid;
            $cod = ($input['cod_mode'] ?? 'remaining') === 'remaining' ? $remaining : 0;

            if (! empty($input['customer_id'])) {
                $customer = Customer::where('shop_id', $input['shop_id'])
                    ->whereKey($input['customer_id'])->lockForUpdate()->first();
                if (! $customer) {
                    throw ValidationException::withMessages(['customer_id' => 'Khách hàng không thuộc shop đã chọn.']);
                }
                app(CustomerReadAccessService::class)->authorize($actor, $customer);
            } else {
                $localCustomers = Customer::where('shop_id', $input['shop_id'])
                    ->whereNull('pancake_customer_id')->where('phone_numbers', $input['customer']['phone']);
                app(CustomerReadAccessService::class)->applyRecordScope($localCustomers, $actor);
                $customer = $localCustomers->orderBy('id')->lockForUpdate()->first();
                if (! $customer) {
                    $customer = Customer::create([
                        'shop_id' => $input['shop_id'], 'pancake_customer_id' => null,
                        'name' => $input['customer']['name'], 'phone_numbers' => $input['customer']['phone'],
                        'assigned_user_id' => $actor->pancake_user_id ?? $actor->id,
                        'order_count' => 0, 'purchased_amount' => 0,
                        'pancake_full_data' => ['origin' => 'local_pos', 'address' => $input['shipping_address']],
                    ]);
                    app(CustomerEnteredSystemEventWriter::class)->write($customer, 'local_pos');
                }
            }
            $totals = [
                'subtotal' => self::moneyString($subtotal), 'discount_amount' => self::moneyString($discount),
                'shipping_fee' => self::moneyString($shipping), 'surcharge' => self::moneyString($surcharge),
                'total_amount' => self::moneyString($total), 'paid_amount' => self::moneyString($paid),
                'remaining_amount' => self::moneyString($remaining), 'expected_cod' => self::moneyString($cod),
            ];
            $orderData = [
                'shop_id' => $input['shop_id'], 'origin' => 'local_pos',
                'customer_id' => $customer->id, 'pancake_customer_id' => $customer->pancake_customer_id,
                'pancake_order_id' => null, 'order_number_vtp' => null,
                'created_by_user_id' => $actor->id, 'user_creator_id' => $actor->pancake_user_id ?? $actor->id,
                'user_assigning_seller_id' => $actor->pancake_user_id ?? $actor->id,
                'idempotency_key' => $input['idempotency_key'], 'request_hash' => $hash,
                'customer_name' => $customer->name, 'customer_phone' => $customer->phone_numbers,
                'customer_address' => $input['shipping_address'], 'note' => $input['note'] ?? null,
                'total_quantity' => array_sum(array_column($items, 'quantity')),
                // Legacy cash is actual received money. COD remains an expectation, never a receipt.
                'cod' => self::moneyString($cod), 'cash' => self::moneyString($paid), 'status' => 0,
                'warehouse_status' => Order::WAREHOUSE_WAITING,
                'warehouse_queued_at' => null,
                'pancake_full_data' => [
                    'origin' => 'local_pos', 'items' => $items, 'pos_totals' => $totals,
                    'prepaid' => self::moneyString($paid), 'shipping_fee' => self::moneyString($shipping),
                    'cod_mode' => $input['cod_mode'] ?? 'remaining',
                    'payments' => $paid === 0 ? [] : [[
                        'amount' => self::moneyString($paid), 'method' => $input['payment_method'] ?? 'cash',
                        'status' => 'valid', 'recorded_by_user_id' => $actor->id, 'recorded_at' => now()->toIso8601String(),
                    ]],
                ],
            ];
            if (Schema::hasColumn('orders', 'prepaid_amount')) {
                $orderData['prepaid_amount'] = self::moneyString($paid);
            }
            $order = Order::create($orderData);
            app(WarehouseService::class)->initializeLocal($order);
            foreach ($items as $line) {
                $product = $products->get($line['product_id']);
                $product->virtual_stock = $line['virtual_stock_after'];
                $product->save();
            }
            // A placed/unfulfilled order must not inflate historical completed-purchase statistics.
            app(OrderCreatedEventWriter::class)->write($order, 'local_pos', $order->created_at, $customer);

            return $order;
        }, 3);
    }

    private function localRequestHash(array $input): string
    {
        unset($input['idempotency_key']);
        foreach (['discount_amount', 'shipping_fee', 'surcharge', 'paid_amount'] as $key) {
            $input[$key] = self::moneyString(self::moneyCents($input[$key] ?? 0));
        }
        $input['shop_id'] = (int) $input['shop_id'];
        $input['customer_id'] = isset($input['customer_id']) ? (int) $input['customer_id'] : null;
        $input['cod_mode'] ??= 'remaining';
        $input['payment_method'] ??= 'cash';
        $input['note'] ??= null;
        foreach ($input['items'] as &$line) {
            $line['product_id'] = (int) $line['product_id'];
            $line['quantity'] = (int) $line['quantity'];
            if (isset($line['unit_price'])) {
                $line['unit_price'] = self::moneyString(self::moneyCents($line['unit_price']));
            }
            ksort($line);
        }
        unset($line);
        usort($input['items'], fn ($a, $b) => $a['product_id'] <=> $b['product_id']);
        if (isset($input['customer'])) {
            ksort($input['customer']);
        }
        ksort($input);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    /** Exact base-10 arithmetic; floats are only accepted at the normalized model boundary. */
    private static function moneyCents(int|float|string $value): int
    {
        $text = is_float($value) ? number_format($value, 2, '.', '') : (string) $value;
        if (! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $text)) {
            throw ValidationException::withMessages(['items' => 'Số tiền không hợp lệ.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private static function moneyString(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function getOrderItem($data_item, $shop_id)
    {
        $total_quantity = 0;
        if (! empty($data_item['items'])) {
            $total_quantity = collect($data_item['items'])->sum('quantity');
        }
        $insertedAt = $data_item['inserted_at']
            ?? $data_item['created_at']
            ?? $data_item['updated_at']
            ?? null;

        $updatedAt = $data_item['updated_at']
            ?? $insertedAt;

        $created_at = $insertedAt
            ? Carbon::parse($insertedAt, 'UTC')->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s')
            : null;

        $updated_at = $updatedAt
            ? Carbon::parse($updatedAt, 'UTC')->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s')
            : $created_at;
        if (! empty($data_item['shipping_address']['province_id'])) {
            $province = Province::where('id', $data_item['shipping_address']['province_id'])
                ->orWhere('new_id', $data_item['shipping_address']['province_id'])
                ->first();
        }

        $result = [
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
            'cash' => $data_item['cash'] ?? 0,
            'note' => $data_item['note'] ?? null,
            'user_creator_id' => $data_item['creator']['id'] ?? $data_item['assigning_seller']['id'] ?? $data_item['assigning_care']['id'] ?? null,
            'user_care_id' => $data_item['assigning_care']['id'] ?? null,
            'user_assigning_seller_id' => $data_item['assigning_seller']['id'] ?? null,
            'pancake_customer_id' => $data_item['customer']['customer_id'] ?? null,
            'pancake_full_data' => json_encode($data_item),
            'customer_phone' => $data_item['bill_phone_number'] ?? null,
            'customer_address' => $data_item['shipping_address']['full_address'] ?? null,
            'created_at' => $created_at,
            'updated_at' => $updated_at,
        ];

        // Webhook can be a partial payload. Only update these fields
        // when Pancake actually sends them.
        if (! empty($data_item['customer']['id'])) {
            $result['discount_percent'] = get_discount_by_customer($data_item['customer']['id']);
        }

        if (array_key_exists('status', $data_item)) {
            $result['status'] = $data_item['status'];
        }

        if (array_key_exists('received_at_shop', $data_item)) {
            $result['received_at_shop'] = $data_item['received_at_shop'];
        }

        $customerName = get_customer_name($data_item);
        if ($customerName !== null && $customerName !== '') {
            $result['customer_name'] = $customerName;
        }

        return $result;
    }
}
