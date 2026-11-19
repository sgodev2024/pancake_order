<?php

namespace App\Services\HuongChat;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HuongChatOrderService
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('huongchat.api_url', env('HUONG_CHAT_API_URL', 'http://127.0.0.1:8000/api')), '/');
        $this->apiKey = config('huongchat.api_key', env('HUONG_CHAT_API_KEY'));
        $this->timeout = (int) config('huongchat.timeout', env('HUONG_CHAT_API_TIMEOUT', 10));
    }

    /**
     * Get paginated orders from Huong Chat API.
     */
    public function getOrders(array $params = []): array
    {
        if (empty($this->baseUrl)) {
            return [
                'success' => false,
                'message' => 'Chưa cấu hình URL kết nối API Hương Chất (HUONG_CHAT_API_URL).',
                'data' => [],
                'pagination' => null,
            ];
        }

        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'Chưa cấu hình API Key Hương Chất (HUONG_CHAT_API_KEY).',
                'data' => [],
                'pagination' => null,
            ];
        }

        $endpoint = $this->baseUrl . '/orders';

        // Clean params
        $queryParams = array_filter([
            'page' => $params['page'] ?? 1,
            'per_page' => $params['per_page'] ?? $params['page_size'] ?? 20,
            'search' => isset($params['search']) && trim($params['search']) !== '' ? trim($params['search']) : null,
            'status' => isset($params['status']) && trim($params['status']) !== '' ? trim($params['status']) : null,
            'payment_status' => isset($params['payment_status']) && $params['payment_status'] !== '' && $params['payment_status'] !== null ? $params['payment_status'] : null,
            'start_date' => isset($params['start_date']) && trim($params['start_date']) !== '' ? trim($params['start_date']) : null,
            'end_date' => isset($params['end_date']) && trim($params['end_date']) !== '' ? trim($params['end_date']) : null,
        ], fn($v) => $v !== null);

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'X-API-KEY' => $this->apiKey,
                ])
                ->connectTimeout(3)
                ->timeout($this->timeout)
                ->get($endpoint, $queryParams);

            if ($response->status() === 401 || $response->status() === 403) {
                return [
                    'success' => false,
                    'message' => 'Lỗi xác thực: API Key kết nối Hương Chất không hợp lệ hoặc đã hết hạn.',
                    'data' => [],
                    'pagination' => null,
                ];
            }

            if (!$response->successful()) {
                $status = $response->status();
                Log::warning('Huong Chat API returned non-successful response', [
                    'status' => $status,
                    'url' => $endpoint,
                ]);

                return [
                    'success' => false,
                    'message' => "Hệ thống Hương Chất phản hồi lỗi HTTP {$status}. Vui lòng thử lại sau.",
                    'data' => [],
                    'pagination' => null,
                ];
            }

            $json = $response->json();

            if (!is_array($json) || !isset($json['data'])) {
                return [
                    'success' => false,
                    'message' => 'Phản hồi từ API Hương Chất không đúng định dạng mong đợi.',
                    'data' => [],
                    'pagination' => null,
                ];
            }

            return [
                'success' => true,
                'data' => $json['data'],
                'pagination' => $json['pagination'] ?? null,
            ];
        } catch (ConnectionException $e) {
            Log::warning('Connection to Huong Chat API failed or timed out: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Không thể kết nối đến hệ thống Hương Chất (Timeout hoặc máy chủ không phản hồi).',
                'data' => [],
                'pagination' => null,
            ];
        } catch (Exception $e) {
            Log::error('Error calling Huong Chat API: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Lỗi xử lý kết nối tới Hương Chất: ' . $e->getMessage(),
                'data' => [],
                'pagination' => null,
            ];
        }
    }

    /**
     * Get single order detail from Huong Chat API.
     */
    public function getOrderDetail($id): array
    {
        if (empty($this->baseUrl) || empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'Chưa cấu hình thông tin kết nối API Hương Chất.',
                'data' => null,
            ];
        }

        $endpoint = $this->baseUrl . '/orders/' . $id;

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'X-API-KEY' => $this->apiKey,
                ])
                ->connectTimeout(3)
                ->timeout($this->timeout)
                ->get($endpoint);

            if ($response->status() === 404) {
                return [
                    'success' => false,
                    'message' => 'Không tìm thấy đơn hàng trên hệ thống Hương Chất.',
                    'data' => null,
                ];
            }

            if ($response->status() === 401 || $response->status() === 403) {
                return [
                    'success' => false,
                    'message' => 'Lỗi xác thực kết nối Hương Chất.',
                    'data' => null,
                ];
            }

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Không thể lấy thông tin chi tiết đơn hàng từ Hương Chất (HTTP ' . $response->status() . ').',
                    'data' => null,
                ];
            }

            $json = $response->json();

            return [
                'success' => true,
                'data' => $json['data'] ?? $json,
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'message' => 'Không thể kết nối đến hệ thống Hương Chất.',
                'data' => null,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Lỗi xử lý: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }
}
