<?php

// Standalone HTTP mock: php -S 127.0.0.1:8011 scripts/aicrm-mock-router.php
// No Laravel boot, database access, queue dispatch, or outbound networking.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\AicrmMockApi;

header('Content-Type: application/json; charset=utf-8');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/health') {
    echo json_encode(['mock' => true]);

    return;
}
if ($path !== '/api/v1/orders' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    echo json_encode(['error' => true]);

    return;
}
$apiKey = getenv('AICRM_MOCK_API_KEY');
if (! $apiKey || ! hash_equals($apiKey, $_SERVER['HTTP_X_API_KEY'] ?? '')) {
    http_response_code(401);
    echo json_encode(['error' => true, 'message' => 'Mock authentication failed']);

    return;
}
$payload = json_decode(file_get_contents('php://input'), true);
$valid = is_array($payload);
foreach (['receiveFullName', 'receiverPhone', 'receiverAddress'] as $key) {
    $valid = $valid && is_string($payload[$key] ?? null) && trim($payload[$key]) !== '';
}
foreach (['totalPrice', 'moneyCollection', 'shippingProviderId'] as $key) {
    $valid = $valid && is_numeric($payload[$key] ?? null) && $payload[$key] >= 0;
}
if (! $valid || $payload['shippingProviderId'] < 1 || $payload['moneyCollection'] > $payload['totalPrice']) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid CreateOrderRequest', 'statusCode' => 400]);

    return;
}
$scenario = getenv('AICRM_MOCK_SCENARIO') ?: 'success';
if ($scenario === 'timeout') {
    sleep(15);
}
[$status, $body] = (new AicrmMockApi)->respond($payload, json_encode($payload), $scenario);
http_response_code($status);
echo json_encode($body, JSON_UNESCAPED_UNICODE);
