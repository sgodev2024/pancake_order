<?php

namespace App\Services;

class AicrmOrderStatus
{
    // Only GET order.status uses this enum. POST status=200 is an API result.
    public const LABELS = [
        'PENDING' => 'Chờ xử lý',
        'PICKING' => 'Đang lấy hàng',
        'PROCESSING' => 'Đang xử lý',
        'CANCELED' => 'Đã hủy',
        'SHIPPING' => 'Đang vận chuyển',
        'DELIVERED' => 'Đã giao hàng',
        'RETURNED' => 'Đã hoàn hàng',
        'ERROR' => 'Lỗi',
    ];

    public static function valid(mixed $status): bool
    {
        return is_string($status) && array_key_exists($status, self::LABELS);
    }
}
