<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\HuongChat\HuongChatOrderService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PancakeOrderIntegrationTest extends TestCase
{
    protected string $apiUrl = 'http://127.0.0.1:8000/api';
    protected string $apiKey = 'huongchat_secret_key_2026';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'huongchat.api_url' => $this->apiUrl,
            'huongchat.api_key' => $this->apiKey,
            'huongchat.timeout' => 5,
        ]);
    }

    public function test_service_returns_orders_on_successful_api_response(): void
    {
        Http::fake([
            $this->apiUrl . '/orders*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 17,
                        'code' => 'HC-00017',
                        'customer_name' => 'Orion Wilkinson',
                        'phone' => '248.966.0166',
                        'email' => 'damaris27@osinski.com',
                        'address' => '770 Skiles Branch',
                        'total_price' => 728378,
                        'deposit_amount' => 0,
                        'payment_method' => 'cod',
                        'payment_method_text' => 'COD',
                        'payment_status' => 0,
                        'payment_status_text' => 'Chờ thanh toán',
                        'status' => 'completed',
                        'status_text' => 'Đã hoàn thành',
                        'notes' => '',
                        'items_count' => 2,
                        'created_at' => '2026-10-13T03:44:29Z',
                    ],
                ],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => 20,
                    'total' => 1,
                    'last_page' => 1,
                ],
            ], 200),
        ]);

        $service = new HuongChatOrderService();
        $result = $service->getOrders(['page' => 1]);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('HC-00017', $result['data'][0]['code']);
        $this->assertEquals('Orion Wilkinson', $result['data'][0]['customer_name']);
        $this->assertEquals(1, $result['pagination']['total']);
    }

    public function test_service_handles_authentication_failure(): void
    {
        Http::fake([
            $this->apiUrl . '/orders*' => Http::response([
                'success' => false,
                'message' => 'Unauthorized: Invalid or missing API key',
            ], 401),
        ]);

        $service = new HuongChatOrderService();
        $result = $service->getOrders();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('xác thực', mb_strtolower($result['message']));
        $this->assertEmpty($result['data']);
    }

    public function test_service_handles_connection_exception_and_timeout(): void
    {
        Http::fake([
            $this->apiUrl . '/orders*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $service = new HuongChatOrderService();
        $result = $service->getOrders();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('kết nối', mb_strtolower($result['message']));
        $this->assertEmpty($result['data']);
    }

    public function test_service_handles_server_error_500(): void
    {
        Http::fake([
            $this->apiUrl . '/orders*' => Http::response(['error' => 'Internal server error'], 500),
        ]);

        $service = new HuongChatOrderService();
        $result = $service->getOrders();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('500', $result['message']);
        $this->assertEmpty($result['data']);
    }

    public function test_service_get_order_detail_returns_products(): void
    {
        Http::fake([
            $this->apiUrl . '/orders/17' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 17,
                    'code' => 'HC-00017',
                    'customer_name' => 'Orion Wilkinson',
                    'products' => [
                        [
                            'id' => 101,
                            'name' => 'Nước hoa Charme',
                            'price' => 500000,
                            'quantity' => 1,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new HuongChatOrderService();
        $result = $service->getOrderDetail(17);

        $this->assertTrue($result['success']);
        $this->assertEquals(17, $result['data']['id']);
        $this->assertCount(1, $result['data']['products']);
    }

    public function test_no_data_is_written_to_ban_mai_database(): void
    {
        $initialOrderCount = Order::count();

        Http::fake([
            $this->apiUrl . '/orders*' => Http::response([
                'success' => true,
                'data' => [
                    ['id' => 9999, 'code' => 'HC-TEST-9999', 'customer_name' => 'Test Isolation', 'total_price' => 100000],
                ],
                'pagination' => ['total' => 1],
            ], 200),
        ]);

        $service = new HuongChatOrderService();
        $service->getOrders();

        $this->assertEquals($initialOrderCount, Order::count(), 'Ban Mai orders count must remain strictly unchanged.');
    }
}
