<?php

namespace App\Services;

/** Shared by the offline driver and the standalone local HTTP mock. */
class AicrmMockApi
{
    public function respond(array $payload, string $reference, string $scenario): array
    {
        return match ($scenario) {
            'validation' => [400, ['error' => 'Lỗi từ Viettel Post', 'statusCode' => 400, 'details' => '{"error":true,"message":"Địa chỉ không hợp lệ"}']],
            'unauthorized' => [401, ['error' => true, 'message' => 'Unauthorized']],
            'server_error' => [500, ['error' => 'Lỗi hệ thống', 'details' => 'Mock error']],
            'malformed' => [200, ['status' => 200, 'error' => false, 'data' => []]],
            default => [200, ['status' => 200, 'error' => false, 'message' => 'Success', 'data' => [
                'ORDER_NUMBER' => 'MOCK-VTP-'.strtoupper(substr(hash('sha256', $reference), 0, 16)),
                'MONEY_COLLECTION' => $payload['moneyCollection'], 'MONEY_TOTAL' => $payload['totalPrice'],
            ]]],
        };
    }
}
