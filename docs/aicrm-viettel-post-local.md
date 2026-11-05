# Viettel Post qua AICRM — triển khai Local

> **Hợp đồng mới:** xem [AICRM API Key](aicrm-api-key-integration.md).
> Mock HTTP/Sandbox hiện dùng `AICRM_API_KEY` và `X-API-Key`; mock server nhận
> `AICRM_MOCK_API_KEY`. Tenant được xác định bởi API Key, không thêm header tenant.

> Cập nhật kết nối thật: xem [AICRM Live](aicrm-viettel-post-live.md). Phần bên dưới
> mô tả Mock/Sandbox ban đầu. Live có cấu hình/công tắc riêng, hiện vẫn OFF;
> GET tới host được cung cấp đang bị Cloudflare 1010 chặn, chưa xác minh B2B.

Nguồn phát triển: repo `pancake_order`, nhánh `viet_dev`, frontend `frontend/`.
Tài liệu hợp đồng API v1.0 được sao chép nguyên bản vào [order_api.md](order_api.md).

## Luồng sử dụng

1. Đăng nhập Local, mở Kho, chọn shop được phân công.
2. Hoàn tất đóng hàng như luồng hiện tại. Việc này **không gọi AICRM**.
3. Chuyển tab **Đã đóng hàng**. Người có cả `warehouse-view` và `warehouse-pack`
   được sử dụng **Tạo vận đơn Viettel Post (Mock)** trên danh sách hoặc chi tiết.
4. Kiểm tra người nhận/sản phẩm, bấm nút và xác nhận. Backend chỉ nhận ID đơn;
   dữ liệu và shop được đọc lại từ database, không tin tiền/tenant/shop do trình duyệt gửi.
5. Danh sách hiển thị trạng thái gửi và mã vận đơn; chi tiết có lịch sử từng lần gửi,
   thời gian, người thao tác, mã HTTP và thông báo lỗi an toàn.

Tái sử dụng `WarehouseService`, `ShopAccessService`, middleware quyền nghiêm ngặt,
Passport, quan hệ shop và React Query. Không thêm quyền cho tài khoản/role hiện có.
Luồng đồng bộ Pancake, đóng hàng, job và scheduler không tự tạo vận đơn.

## Mapping đúng CreateOrderRequest

| AICRM | Pancake Local |
| --- | --- |
| `receiveFullName` | `orders.customer_name` |
| `receiverPhone` | `orders.customer_phone` |
| `receiverAddress` | `orders.customer_address` |
| `productName` | Ghép tên từng dòng `items`: `variation_info.name`, `name`, hoặc `product.name`, kèm `x<số lượng>` |
| `quantity` | Tổng `items[*].quantity` đã kiểm tra số nguyên dương |
| `totalPrice` | Pancake: `cod + prepaid_amount`, dự phòng `pancake_full_data.prepaid` nếu cột prepaid chưa có; POS: `pos_totals.total_amount` |
| `moneyCollection` | `orders.cod`, giữ nguyên COD bằng 0 |
| `shippingProviderId` | `AICRM_SHIPPING_PROVIDER_ID` |

Tổng tiền dùng cùng cách tính với màn hình đơn hiện có. `cash` là tiền thực nhận,
không cộng vào giá trị đơn. POS vẫn dùng tổng riêng khi `cod_mode=none`.
Không lấy phí vận chuyển trả về (`MONEY_TOTAL`/`MONEY_TOTAL_FEE`) để sửa giá trị đơn.
Tên/điện thoại/địa chỉ, sản phẩm, số lượng và tiền được kiểm tra trước khi gửi;
thiếu hoặc không hợp lệ trả 422, không tạo bản ghi gửi hoặc gọi API.

## Lưu trữ và chống trùng

- `aicrm_shipments`: duy nhất `order_id`, shop, môi trường, trạng thái,
  mã vận đơn, thời gian gửi/kết thúc, lỗi gần nhất và reference nội bộ.
- `aicrm_shipment_attempts`: mỗi lần gửi có người thao tác, snapshot request,
  trạng thái, mã HTTP và lỗi. Snapshot người nhận chỉ lưu trong DB; không đưa ra API lịch sử.
- Khóa dòng đơn và shipment trong transaction để giữ chỗ. Commit trạng thái
  `processing` **trước HTTP**, sau đó lưu kết quả trong transaction riêng.
- Thành công được phát lại từ DB, không gửi lại. Chặn cả mã vận đơn có sẵn trong
  `orders.order_number_vtp` và `partner.order_number_vtp`.
- Chỉ đơn đã đóng, thuộc hàng đợi Kho đang kích hoạt và trạng thái đơn gốc còn
  hợp lệ mới được gửi. Hủy/đã giao/không rõ trạng thái đều bị chặn.
- Hai chế độ Mock lưu mã trong shipment riêng, không ghi vào `order_number_vtp`,
  không thay đổi tiền, trạng thái nghiệp vụ hoặc tồn kho. Thành công Sandbox lưu
  thêm mã vào trường vận đơn cũ nếu trường đó đang trống; shipment là bản ghi
  độc lập, vẫn tồn tại nếu Pancake đồng bộ lại trường cũ.

| Trạng thái | Ý nghĩa và xử lý |
| --- | --- |
| `processing` | Đang gửi; chặn mọi lần gửi tiếp, kể cả bản ghi tồn tại lâu do tiến trình chết |
| `succeeded` | Đã xác nhận `error=false`, `status=200`, có `data.ORDER_NUMBER`; gửi lại chỉ trả kết quả đã lưu |
| `failed` | HTTP 400/401/403/422: API từ chối; sau kiểm tra dữ liệu/cấu hình có thể bấm thử lại, giữ toàn bộ lịch sử |
| `unknown` | Timeout, lỗi kết nối, 5xx, redirect, JSON không hợp lệ, thiếu mã hoặc envelope lỗi không rõ; khóa gửi lại để đối soát |

Không tự retry POST, không theo redirect, không tự giải phóng `processing` theo TTL.
AICRM v1 chưa cam kết nhận `Idempotency-Key` hoặc mã tham chiếu do client cấp;
reference nội bộ không được giả định là idempotency của upstream. Chống trùng giữa
các request vào Pancake có DB lock và unique index; trường hợp lỗi mơ hồ được chặn
thay vì hứa bảo đảm exactly-once với dịch vụ ngoài.

Đối soát `processing`/`unknown`: người quản lý phải xác minh trên AICRM/Viettel Post
đã tồn tại vận đơn hay chưa. Phiên bản này cố ý chưa có nút xóa trạng thái/ép gửi lại.
Chỉ bổ sung quy trình mở khóa có bằng chứng khi AICRM xác nhận cách tra cứu,
idempotency và tenant chính thức; không xóa dòng DB để thử lại tùy tiện.

## Cấu hình `.env`

Local hiện được cấu hình `mock`, provider ID **giả lập** là `1`, không có URL/token
dịch vụ bên ngoài và `AICRM_SANDBOX_ENABLED=false`. Giá trị provider thật phải do
AICRM cấp, không suy luận từ ID dùng cho Mock. Tất cả khóa có trong `.env.example`.

### Mock ngoại tuyến (mặc định)

```dotenv
AICRM_MODE=mock
AICRM_SHIPPING_PROVIDER_ID=1
AICRM_SANDBOX_ENABLED=false
AICRM_MOCK_SCENARIO=success
```

Không có HTTP ra ngoài. Thay `AICRM_MOCK_SCENARIO` bằng `validation`, `unauthorized`,
`server_error`, `malformed`, `timeout` để kiểm tra lỗi. Đây là cấu hình phía server,
không cho trình duyệt chọn kịch bản.

### Mock qua HTTP thật trên Local

Mở terminal trong repo backend, đặt API Key **chỉ dùng thử** rồi chạy:

```sh
AICRM_MOCK_API_KEY=local-test-only php -S 127.0.0.1:8011 scripts/aicrm-mock-router.php
```

Mock có `GET /health`, `POST /api/v1/orders`, kiểm tra X-API-Key và request;
dùng chung envelope với tài liệu. Không boot Laravel, không truy cập DB và không
có đường gọi Viettel Post. Có thể đặt `AICRM_MOCK_SCENARIO` cho terminal này.

Cấu hình backend:

```dotenv
AICRM_MODE=mock_http
AICRM_API_URL=http://127.0.0.1:8011
AICRM_API_KEY=local-test-only
# UUID dưới đây chỉ dùng cho Mock HTTP local, không phải tenant thật.
AICRM_CREDENTIAL_TENANT=11111111-1111-4111-8111-111111111111
AICRM_SHOP_TENANTS='{"12":"11111111-1111-4111-8111-111111111111"}'
AICRM_SHIPPING_PROVIDER_ID=1
```

`mock_http` chỉ chấp nhận loopback; đây vẫn là Mock, không ghi mã giả vào trường VTP cũ.

### API AICRM hoạt động tại môi trường kiểm thử

Chỉ dùng endpoint/credential Sandbox đã được AICRM xác nhận. Ví dụ cấu trúc:

```dotenv
AICRM_MODE=sandbox
AICRM_API_URL=https://sandbox-host-do-aicrm-cap.example
AICRM_SANDBOX_ENABLED=true
AICRM_SANDBOX_HOSTS=sandbox-host-do-aicrm-cap.example
AICRM_API_KEY=<api-key-sandbox>
AICRM_CREDENTIAL_TENANT=<uuid-tenant-sandbox>
AICRM_SHOP_TENANTS='{"12":"<uuid-tenant-sandbox>"}'
AICRM_SHIPPING_PROVIDER_ID=<id-viettel-post-sandbox>
AICRM_TIMEOUT=10
AICRM_CONNECT_TIMEOUT=3
```

URL là origin hoặc prefix trước `/api/v1/orders`, không bao gồm đường dẫn endpoint
này lần nữa. Không dùng user/password/query/fragment trong URL. Máy chủ ngoài
loopback bắt buộc HTTPS. Không có chế độ `production`; ứng dụng `APP_ENV=production`
cũng bị chặn gửi. Không dùng URL/token Production trong bất kỳ chế độ nào.

Source đã xác nhận X-API-Key và tenant theo key; không có Bearer hoặc X-Tenant-ID.
Cấu hình Sandbox cần mapping shop Local → UUID tenant và credential đúng tenant,
theo [hướng dẫn hiện hành](aicrm-api-key-integration.md).
Các biến AUTH_HEADER/AUTH_SCHEME/AUTH_TOKEN cũ không còn được dùng;
thay bằng AICRM_API_KEY, AICRM_CREDENTIAL_TENANT, AICRM_SHOP_TENANTS hoặc
AICRM_TENANT_CREDENTIALS cho nhiều tenant. productName tối đa 255 ký tự;
moneyCollection là số nguyên VND không âm. POST không trả UUID AICRM hoặc trạng thái vận chuyển.

Sau đổi `.env`: chạy `php artisan config:clear` nếu đã cache config và khởi động lại
backend Local. Dùng **đơn kiểm thử mới** khi đổi môi trường: không chuyển một đơn
đã gửi Mock thành đơn thật, không tự xóa bản ghi chống trùng.

## Migration và kiểm thử

Migration thêm hai bảng, không backfill/sửa dữ liệu đơn/role/permission:

```sh
php artisan migrate --path=database/migrations/2026_11_04_000001_create_aicrm_shipments.php
```

Kiểm thử không dùng MySQL dữ liệu làm việc:

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit tests/Feature/AicrmShipmentTest.php tests/Feature/WarehouseTest.php
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit
cd frontend
node --test --test-force-exit --test-concurrency=2 tests/*.test.js
npm run lint
npm run build
```

Kiểm thử HTTP thực tới Mock (terminal server dùng `AICRM_MOCK_TOKEN=test-secret`):

```sh
AICRM_LOCAL_HTTP_TEST_URL=http://127.0.0.1:8011 APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit tests/Feature/AicrmShipmentTest.php --filter=test_local_http_mock_mode_never_writes_mock_tracking_to_legacy_carrier_fields
```

Test này vẫn tạo fixture/đơn giả trong SQLite bộ nhớ; chỉ cho phép HTTP tới loopback.
Các test còn lại chặn network ngoài hoặc fake HTTP. Không chạy queue worker/scheduler.

Kết quả thực thi cuối cùng được ghi trong [báo cáo kiểm thử](aicrm-viettel-post-validation.md).
