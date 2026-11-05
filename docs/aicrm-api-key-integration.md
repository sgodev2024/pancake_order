# AICRM — hợp đồng theo source Java/Spring Boot

Đối chiếu `/Users/viet/api-vtp`, branch `main`, commit
`7dfb3be375e3b19e2656a014b440b07190a8bc6d`, ngày 04/11/2026.
Đây là hướng dẫn cấu hình hiện hành, thay các giả định từ tài liệu API cũ.
Báo cáo thay đổi và kiểm thử: [source integration](aicrm-source-integration-2026-11-04.md).

## Hợp đồng

- Xác thực Backend bằng `X-API-Key` thô; JSON, `Content-Type: application/json`.
  API Key ACTIVE/chưa hết hạn xác định tenant. Không thêm Bearer hoặc X-Tenant-ID.
- POST `/api/v1/orders` giữ đúng tám trường `CreateOrderRequest`:

| Trường | Kiểu JSON / quy tắc Pancake |
| --- | --- |
| receiveFullName | Chuỗi người nhận từ đơn được phân quyền |
| receiverPhone | Chuỗi số điện thoại |
| receiverAddress | Chuỗi địa chỉ |
| productName | Ghép `tên xSL`, ngăn bằng `; `; toàn bộ tối đa 255 ký tự Unicode |
| quantity | Số nguyên dương, tổng số lượng |
| totalPrice | Số, tổng POS hoặc COD + prepaid, giữ cách tính hiện tại |
| moneyCollection | Số nguyên VND không âm, giữ số 0, không làm tròn COD lẻ |
| shippingProviderId | ID số nguyên dương của Viettel Post đúng tenant |

Tên ghép vượt 255 trả HTTP 422 với hướng dẫn rút gọn tên; không âm thầm cắt tên,
không sửa dữ liệu sản phẩm. COD lẻ/âm/sai kiểu bị chặn trước reservation/HTTP.

- POST thành công cần HTTP 2xx, `error === false`, `status === 200` và
  `data.ORDER_NUMBER` hợp lệ. Chỉ lưu tracking và kết quả gửi `succeeded`.
  POST không trả UUID AICRM; `remote_order_id` và `remote_status` để null.
  UUID `reference` của Pancake chỉ là khóa nội bộ, không phải UUID AICRM.
- GET `/api/v1/orders?page=1&size=100` trả
  `content`, `pageNumber`, `pageSize`, `totalElements`, `totalPages`; trang bắt đầu 1.
  Từng phần tử có `id`, `vtpOrderNumber`, `status` và thông tin tóm tắt đơn.
- GET `/api/v1/orders/{uuid}` có `tenantId`, `vtpOrderNumber`, `status`.
  Trạng thái hợp lệ: `PENDING`, `PICKING`, `PROCESSING`, `CANCELED`, `SHIPPING`,
  `DELIVERED`, `RETURNED`, `ERROR`. Chỉ cập nhật trạng thái AICRM riêng;
  không thay trạng thái bán hàng, đóng hàng hoặc thanh toán Pancake.
- GET `/api/v1/shipping-providers` có thể chứa token hãng và thông tin người gửi.
  Chỉ lấy ID khi đúng một phần tử `code=VIETTEL_POST`, `isEnable=true`, ID nguyên dương.
  Không log, lưu DB hoặc trả toàn bộ response này ra Frontend.

## Cấu hình nhiều shop / nhiều tenant

Giữ các công tắc Live OFF trong bước hiện tại. Đặt bí mật trực tiếp trong `.env`
được Git ignore hoặc secret manager; không dùng VITE_*, không đưa key vào chat/log/repo.

```dotenv
AICRM_MODE=mock
AICRM_LIVE_ENABLED=false
AICRM_LIVE_CREATION_ENABLED=false
AICRM_LIVE_CONTRACT_CONFIRMED=false
AICRM_LIVE_API_URL=
AICRM_LIVE_HOSTS=
AICRM_LIVE_TENANT_MODE=api_key
AICRM_LIVE_TENANT_HEADER=
AICRM_LIVE_SHOP_TENANTS='{}'
AICRM_LIVE_TENANT_CREDENTIALS='{}'
AICRM_LIVE_PROVIDERS_PATH=/api/v1/shipping-providers
AICRM_LIVE_ORDERS_PATH=/api/v1/orders
AICRM_LIVE_ORDER_DETAIL_PATH=/api/v1/orders/{id}
```

`LIVE_SHOP_TENANTS` là object `{ID_shop_Local: UUID_tenant_AICRM}`.
`LIVE_TENANT_CREDENTIALS` là object
`{UUID_tenant_AICRM: {"api_key": key_thật_của_tenant, "shipping_provider_id": ID_đã_đọc}}`.
Đây là mô tả cấu trúc; không chép placeholder làm credential thật.
Nhiều shop cùng tenant được dùng cùng một credential của tenant đó.
Các tenant khác nhau phải dùng key và provider tương ứng; key trùng giữa tenant bị chặn.
Thiếu credential trong map không fallback về key chung.

Với duy nhất một tenant, có thể giữ cấu hình cũ `LIVE_API_KEY`,
`LIVE_CREDENTIAL_TENANT`, `LIVE_SHIPPING_PROVIDER_ID` khi map credentials rỗng.
Tenant của shop phải trùng chính xác `LIVE_CREDENTIAL_TENANT`.
Mock HTTP/Sandbox áp dụng cùng cấu trúc với các biến không có tiền tố LIVE:
`AICRM_SHOP_TENANTS`, `AICRM_TENANT_CREDENTIALS`, `AICRM_CREDENTIAL_TENANT`.
Mock offline không cần credential; các môi trường không fallback credential lẫn nhau.
Header tenant có giá trị hoặc tenant_mode khác api_key đều bị từ chối.

## GET và đối soát UUID

Các lệnh sau là hướng dẫn cho bước kết nối sau này, chưa chạy với API thật trong phiên này.
Sau khi xác nhận host, key, tenant và được phép bật kết nối chỉ đọc, giữ creation OFF:

```sh
php artisan aicrm:live-check --shop-id=<shop-local> --actor-id=<user-local> --resource=providers
php artisan aicrm:live-check --shop-id=<shop-local> --actor-id=<user-local> --resource=orders
php artisan aicrm:live-check --shop-id=<shop-local> --actor-id=<user-local> --resource=detail --aicrm-order-id=<uuid>
```

`live-check` cần warehouse-view và quyền truy cập shop. Output lọc chỉ có ID provider,
HTTP/JSON và các cờ; không có response body/token/người gửi.
Provider response không có tenantId: phải đối chiếu quyền sở hữu key/tenant với AICRM;
HTTP 200 không tự chứng minh cấu hình tenant chính xác. ID vừa đọc phải được đặt vào
credential đúng tenant ở Backend. Không dùng ID ví dụ từ tài liệu hoặc fixture.

Liên kết UUID cho vận đơn Live đã tạo thành công, có mã đã lưu:

```sh
php artisan aicrm:reconcile <order-id-local> --actor-id=<user-local>
```

Lệnh cần cả warehouse-view, warehouse-pack và quyền truy cập shop. Chỉ GET, không POST:
quét đầy đủ danh sách phân trang (100 dòng/trang, tối đa 100 trang), yêu cầu đúng một
`vtpOrderNumber` khớp tuyệt đối, UUID hợp lệ; sau đó GET detail kiểm tra lại UUID,
tenantId, tracking và enum status. Mới ghi `remote_order_id`, `remote_status` của shipment.
Không ghi đè attempt POST thành kết quả GET. Đã liên kết UUID khác thì không tự thay.
Thiếu/mơ hồ/sai tenant/lỗi mạng/response sai/phân trang đổi hoặc vượt giới hạn đều
không liên kết; không tự lấy kết quả trang đầu. API dùng offset, không có snapshot/cursor:
kiểm tra metadata và UUID trùng giúp phát hiện biến động nhưng không đảm bảo snapshot
khi dữ liệu upstream thay đổi đồng thời. Tenant quá 10.000 đơn cần quy trình đối soát riêng.

Shipment lưu tenant_id tại thời điểm reservation. Đổi tenant shop không được replay,
gửi lại hay liên kết qua tenant mới. Shipment cũ tenant_id=null giữ nguyên dữ liệu,
cần xác minh thủ công; không tự backfill tenant hoặc mở khóa gửi lại.

Timeout/unknown/processing tiếp tục khóa POST. Khi timeout chưa biết tracking,
không tự ghép theo tên/điện thoại/tổng tiền, không tạo UUID giả. Lệnh trên không mở khóa
unknown. AICRM chưa có idempotency/client reference trong tám trường đã audit;
cần quy trình vận hành để đối soát các trường hợp này trước lần tạo thật.

## Chuẩn bị triển khai sau này

Migration mới `2026_11_04_000003_add_aicrm_tenant_binding.php` chỉ thêm tenant_id nullable.
Chưa chạy vào DB Local/thật trong phiên này; chỉ chạy trong SQLite bộ nhớ của tests.
Cần áp dụng migration trước khi dùng Sandbox/Live. Mock offline vẫn hoạt động khi
chưa áp dụng migration này, đã có regression test kiểm chứng.
Không cần sửa schema POS/Kho/phân quyền hoặc backfill dữ liệu đơn hiện có.

Còn thiếu: hostname B2B/môi trường đúng commit deploy; API Key thật ACTIVE và UUID tenant
của từng shop; quyền mạng/Cloudflare; ID provider lấy bằng GET có xác thực cho từng tenant;
quy trình xử lý timeout không biết tracking. Source đã chốt auth, tám trường, routes và DTO;
không cần tự suy đoán header tenant, vị trí UUID POST hoặc endpoint login.

Lịch sử GET trước phiên này ghi nhận Cloudflare 403/1010, chưa xác minh kết nối có key thật.
Phiên hiện tại chỉ đọc source local và fake HTTP; không gửi request AICRM thật,
không bật Live, không sửa AICRM, không commit/push.
