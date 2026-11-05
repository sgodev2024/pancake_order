<?php

namespace App\Services;

use Illuminate\Http\Client\Response;

class AicrmShippingProviders
{
    public function viettelPostId(Response $response): int
    {
        abort_unless($response->status() === 200, 409, 'AICRM chưa trả danh sách nhà vận chuyển thành công.');
        $providers = $response->json();
        abort_unless(is_array($providers) && array_is_list($providers),
            409, 'Danh sách nhà vận chuyển AICRM không đúng cấu trúc mảng đã công bố.');
        $matches = array_values(array_filter($providers, fn ($provider) => is_array($provider)
            && ($provider['code'] ?? null) === 'VIETTEL_POST' && ($provider['isEnable'] ?? null) === true));
        abort_unless(count($matches) === 1 && is_int($matches[0]['id'] ?? null) && $matches[0]['id'] > 0,
            409, 'Cần đúng một nhà vận chuyển VIETTEL_POST đang bật với ID số nguyên hợp lệ.');

        // Never return provider credentials or sender contact data to the caller.
        return $matches[0]['id'];
    }

    public function cloudflareDenied(Response $response): bool
    {
        return $response->status() === 403 && $response->json('error_code') === 1010;
    }
}
