# 📦 API Quản Lý Đơn Hàng Viettel Post

Tài liệu hướng dẫn sử dụng API cho module **Order** tích hợp với **Viettel Post**.

- **Base URL:** `/api/v1/orders`
- **Content-Type:** `application/json`

---

## 📑 Mục lục

1. [Tạo đơn hàng mới](#1-tạo-đơn-hàng-mới)
2. [Lấy chi tiết đơn hàng](#2-lấy-chi-tiết-đơn-hàng)
3. [Lấy danh sách đơn hàng](#3-lấy-danh-sách-đơn-hàng-có-phân-trang)
4. [Cấu trúc dữ liệu](#4-cấu-trúc-dữ-liệu)
5. [Mã lỗi thường gặp](#5-mã-lỗi-thường-gặp)
6. [Webhook Viettel Post](#6-webhook-viettel-post-tham-khảo)
7. [Ghi chú triển khai](#7-ghi-chú-triển-khai)

---

## 1. Tạo đơn hàng mới

Tạo đơn hàng và đẩy thông tin sang Viettel Post.

### ➤ Endpoint

```
POST /api/v1/orders
```

### ➤ Request Body — `CreateOrderRequest`

| Trường | Kiểu | Bắt buộc | Mô tả |
|--------|------|----------|-------|
| `receiveFullName` | `string` | ✅ | Tên người nhận |
| `receiverPhone` | `string` | ✅ | Số điện thoại người nhận |
| `receiverAddress` | `string` | ✅ | Địa chỉ người nhận |
| `productName` | `string` | ❌ | Tên sản phẩm |
| `quantity` | `integer` | ❌ | Số lượng sản phẩm |
| `totalPrice` | `number` (BigDecimal) | ✅ | Tổng giá trị đơn hàng |
| `moneyCollection` | `number` (BigDecimal) | ✅ | Tiền thu hộ (COD) |
| `shippingProviderId` | `long` | ✅ | ID nhà vận chuyển (Viettel Post) |

### ➤ Ví dụ Request

```json
{
  "receiveFullName": "Nguyễn Văn A",
  "receiverPhone": "0987654321",
  "receiverAddress": "123 Lê Lợi, Quận 1, TP.HCM",
  "productName": "Áo thun nam",
  "quantity": 2,
  "totalPrice": 250000,
  "moneyCollection": 250000,
  "shippingProviderId": 1
}
```

### ➤ Response thành công — `200 OK`

Trả về `ViettelPostResponse<ViettelPostOrderResponse>`:

```json
{
  "status": 200,
  "error": false,
  "message": "Success",
  "data": {
    "ORDER_NUMBER": "VTP123456789",
    "MONEY_COLLECTION": 250000,
    "MONEY_TOTAL": 250000,
    "MONEY_TOTAL_FEE": 22000,
    "MONEY_FEE": 20000,
    "MONEY_COLLECTION_FEE": 2000,
    "MONEY_VAT": 0,
    "KPI_HT": 1.5,
    "EXCHANGE_WEIGHT": 500,
    "RECEIVER_PROVINCE": 79,
    "RECEIVER_DISTRICT": 760,
    "RECEIVER_WARD": 26734,
    "SORT_CODE": "ABCD123"
  }
}
```

### ➤ Response lỗi từ Viettel Post

Khi Viettel Post trả về lỗi (`RestClientResponseException`):

```json
{
  "error": "Lỗi từ Viettel Post",
  "statusCode": 400,
  "details": "{\"error\":true,\"message\":\"Địa chỉ không hợp lệ\"}"
}
```

### ➤ Response lỗi hệ thống

```json
{
  "error": "Lỗi hệ thống",
  "details": "Chi tiết lỗi..."
}
```

---

## 2. Lấy chi tiết đơn hàng

Lấy toàn bộ thông tin chi tiết của một đơn hàng theo `orderId`.

### ➤ Endpoint

```
GET /api/v1/orders/{orderId}
```

### ➤ Path Parameters

| Tham số | Kiểu | Mô tả |
|---------|------|-------|
| `orderId` | `UUID` | ID của đơn hàng |

### ➤ Ví dụ

```
GET /api/v1/orders/550e8400-e29b-41d4-a716-446655440000
```

### ➤ Response thành công — `200 OK` — `OrderResponse`

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "tenantId": "660e8400-e29b-41d4-a716-446655440111",
  "orderCode": "ORD-20241120-001",
  "status": "PENDING",
  "shippingProvidersId": 1,
  "totalAmount": 250000,
  "productName": "Áo thun nam",
  "receiverName": "Nguyễn Văn A",
  "receiverPhone": "0987654321",
  "receiverAddress": "123 Lê Lợi, Quận 1, TP.HCM",
  "senderFullName": "Công ty ABC",
  "senderPhone": "0901234567",
  "senderAddress": "456 Nguyễn Trãi, Hà Nội",
  "vtpServiceId": "SVC001",
  "vtpOrderNumber": "VTP123456789",
  "moneyCollection": 250000,
  "moneyTotal": 272000,
  "moneyTotalFee": 22000,
  "moneyFee": 20000,
  "moneyCollectionFee": 2000,
  "moneyVAT": 0,
  "kpiHt": 1.5,
  "exchangeWeight": 500,
  "receiverProvince": 79,
  "receiverDistrict": 760,
  "receiverWard": 26734,
  "sortCode": "ABCD123",
  "requestPayload": "{...}",
  "responsePayload": "{...}",
  "createdAt": "2024-11-20T10:30:00Z",
  "updatedAt": "2024-11-20T10:30:00Z"
}
```

> 💡 **Lưu ý:** `status` là enum `OrderStatus`, ví dụ: `PENDING`, `CONFIRMED`, `SHIPPING`, `DELIVERED`, `CANCELLED`, `RETURNED`.

---

## 3. Lấy danh sách đơn hàng (có phân trang)

Lấy danh sách đơn hàng thuộc **tenant hiện tại** (xác định qua `TenantContext`).

### ➤ Endpoint

```
GET /api/v1/orders?page={page}&size={size}
```

### ➤ Query Parameters

| Tham số | Kiểu | Mặc định | Mô tả |
|---------|------|----------|-------|
| `page` | `int` | `1` | Trang hiện tại (bắt đầu từ 1) |
| `size` | `int` | `10` | Số bản ghi mỗi trang |

### ➤ Ví dụ

```
GET /api/v1/orders?page=1&size=20
```

### ➤ Response thành công — `200 OK` — `OrdersPageResponse<OrderSummaryResponse>`

```json
{
  "content": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "orderCode": "ORD-20241120-001",
      "status": "PENDING",
      "receiverName": "Nguyễn Văn A",
      "receiverPhone": "0987654321",
      "totalAmount": 250000,
      "vtpOrderNumber": "VTP123456789",
      "createdAt": "2024-11-20T10:30:00Z"
    }
  ],
  "pageNumber": 1,
  "pageSize": 20,
  "totalElements": 145,
  "totalPages": 8
}
```

---

## 4. Cấu trúc dữ liệu

### 4.1. `CreateOrderRequest`

```ts
{
  receiveFullName: string;         // bắt buộc
  receiverPhone: string;           // bắt buộc
  receiverAddress: string;         // bắt buộc
  productName?: string;
  quantity?: number;
  totalPrice: number;              // bắt buộc
  moneyCollection?: number;
  shippingProviderId: number;      // bắt buộc
}
```

### 4.2. `OrderResponse`

```ts
{
  id: string;                      // UUID
  tenantId: string;                // UUID
  orderCode: string;
  status: OrderStatus;
  shippingProvidersId?: number;
  totalAmount?: number;
  productName?: string;

  receiverName?: string;
  receiverPhone?: string;
  receiverAddress?: string;

  senderFullName?: string;
  senderPhone?: string;
  senderAddress?: string;

  vtpServiceId?: string;
  vtpOrderNumber?: string;

  moneyCollection?: number;
  moneyTotal?: number;
  moneyTotalFee?: number;
  moneyFee?: number;
  moneyCollectionFee?: number;
  moneyVAT?: number;
  kpiHt?: number;
  exchangeWeight?: number;
  receiverProvince?: number;
  receiverDistrict?: number;
  receiverWard?: number;
  sortCode?: string;

  requestPayload?: string;
  responsePayload?: string;

  createdAt: string;               // ISO Timestamp
  updatedAt: string;               // ISO Timestamp
}
```

### 4.3. `OrderSummaryResponse`

```ts
{
  id: string;                      // UUID
  orderCode: string;
  status: string;
  receiverName?: string;
  receiverPhone?: string;
  totalAmount?: number;
  vtpOrderNumber?: string;
  createdAt: string;
}
```

### 4.4. `OrdersPageResponse<T>`

```ts
{
  content: T[];
  pageNumber: number;
  pageSize: number;
  totalElements: number;
  totalPages: number;
}
```

### 4.5. `ViettelPostResponse<T>`

```ts
{
  status: number;      // HTTP-like status của Viettel Post
  error: boolean;
  message: string;
  data: T;
}
```

### 4.6. `ViettelPostOrderResponse`

```ts
{
  ORDER_NUMBER: string;
  MONEY_COLLECTION?: number;
  MONEY_TOTAL?: number;
  MONEY_TOTAL_FEE?: number;
  MONEY_FEE?: number;
  MONEY_COLLECTION_FEE?: number;
  MONEY_VAT?: number;
  KPI_HT?: number;
  EXCHANGE_WEIGHT?: number;
  RECEIVER_PROVINCE?: number;
  RECEIVER_DISTRICT?: number;
  RECEIVER_WARD?: number;
  SORT_CODE?: string;
}
```

---

## 5. Mã lỗi thường gặp

| HTTP Code | Trường hợp | Response |
|-----------|-----------|----------|
| `200` | Tạo/lấy đơn thành công | Trả về dữ liệu tương ứng |
| `400` | Dữ liệu đầu vào không hợp lệ (validation) hoặc Viettel Post trả lỗi 400 | `{ "error": "Lỗi từ Viettel Post", "statusCode": 400, "details": "..." }` |
| `401/403` | Viettel Post từ chối (sai token/partner) | Trả nguyên body lỗi từ VTP |
| `500` | Lỗi hệ thống nội bộ | `{ "error": "Lỗi hệ thống", "details": "..." }` |

> ⚠️ **Khi `RestClientResponseException` xảy ra**, controller sẽ **forward nguyên HTTP status code** từ Viettel Post về client, kèm `details` chứa body gốc để dễ debug.

---

## 6. Webhook Viettel Post (tham khảo)

Hệ thống có DTO `ViettelPostWebHookRequest` để nhận callback trạng thái đơn từ Viettel Post.

### Payload mẫu

```json
{
  "DATA": {
    "ORDER_NUMBER": "VTP123456789",
    "ORDER_REFERENCE": "ORD-20241120-001",
    "ORDER_STATUSDATE": "2024-11-21 09:15:00",
    "ORDER_STATUS": 200,
    "STATUS_NAME": "Đang giao",
    "LOCALION_CURRENTLY": "Bưu cục Quận 1",
    "NOTE": "Khách hẹn giao chiều",
    "MONEY_COLLECTION": 250000,
    "MONEY_FEECOD": 2000,
    "MONEY_TOTALFEE": 22000,
    "MONEY_TOTAL": 272000,
    "EXPECTED_DELIVERY": "2024-11-22",
    "PRODUCT_WEIGHT": 500,
    "ORDER_SERVICE": "SVC001",
    "ORDER_PAYMENT": 1,
    "EXPECTED_DELIVERY_DATE": "2024-11-22",
    "DETAIL": [
      { "CODE": "SORT_CODE", "VALUE": "ABCD123" }
    ],
    "VOUCHER_VALUE": 0,
    "MONEY_COLLECTION_ORIGIN": 250000,
    "EMPLOYEE_NAME": "Trần B",
    "EMPLOYEE_PHONE": "0912345678",
    "IS_RETURNING": false,
    "REASON_CODE": null
  },
  "TOKEN": "webhook-token-xxx"
}
```

### Cấu trúc `ViettelPostWebhookResponse` (lưu DB)

```ts
{
  id: string;
  orderId: string;
  tenantId: string;
  orderNumber: string;
  orderReference: string;
  orderStatusDate: string;   // LocalDateTime
  orderStatus: number;
  statusName: string;
  location_currently: string;
  note: string;
  mon: number;
}
```

---

## 7. Ghi chú triển khai

- **Multi-tenant:** API danh sách (`GET /api/v1/orders`) tự động lấy `tenantId` từ `TenantContext`. Đảm bảo request có header/token để xác định tenant trước khi gọi.
- **Timeout & Retry:** Nên cấu hình timeout (ví dụ 10s) cho `RestClient` khi gọi Viettel Post. Cân nhắc retry cho lỗi `5xx`.
- **Logging:** Với đơn tạo mới, `requestPayload` và `responsePayload` được lưu lại trong DB để tiện tra soát.
- **Idempotency:** `orderCode` nên được sinh duy nhất phía server, tránh trùng khi client retry.
- **Validation:** Các trường `@NotNull` trong `CreateOrderRequest` sẽ tự động trả `400 Bad Request` nếu thiếu (thông qua `@Valid`, cần bổ sung `@Valid` vào controller nếu chưa có).

---

📌 **Phiên bản:** 1.0  
👤 **Liên hệ:** Team AICRM — `vn.aicrm.viettelpost.order`
