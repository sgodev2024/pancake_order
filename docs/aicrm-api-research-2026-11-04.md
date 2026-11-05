# Nghiên cứu API AICRM thật — 04/11/2026

**Chưa xác minh được kết nối API nghiệp vụ AICRM có xác thực.** GET theo tài liệu
trả HTTP 403 từ Cloudflare; không nhận được dữ liệu đơn hàng hợp lệ. Chưa đủ thông
tin để kiểm thử đọc dữ liệu thật có xác thực từ Laravel Local. Không bật Live.

## Bảng kết quả

| Nội dung | Đã xác minh | Chưa xác minh | Cần AICRM cung cấp |
| --- | --- | --- | --- |
| Host và API Base URL | Host được người dùng chỉ định: `https://api-vtp.aicrm.vn`; host trả phản hồi Cloudflare | Origin/prefix Backend thực tế; không coi URL giao diện là API Base URL | URL tích hợp chính thức, prefix và môi trường |
| Truy cập mạng | GET danh sách lúc **16:49:57 ngày 04/11/2026, UTC+7** trả **403**, `server=cloudflare`, `error_code=1010`, `browser_signature_banned`, `retryable=false`, `owner_action_required=true` | Request có đến ứng dụng Backend hay không; route nghiệp vụ có tồn tại trên host này hay không | Cách truy cập server-to-server được hỗ trợ, xử lý chặn cho nguồn kết nối được phép |
| Swagger/OpenAPI | Đã tìm file trong workspace, tài liệu Drive liên quan và kết quả công khai theo domain; chưa tìm thấy bản đặc tả truy cập được | Không kết luận Swagger không tồn tại hoặc bị tắt; chưa đọc được OpenAPI từ server | URL Swagger/OpenAPI chính thức hoặc file JSON/YAML/Postman đúng bản triển khai |
| API đơn hàng | `order_api.md` v1.0 ghi GET `/api/v1/orders?page=1&size=1`, GET `/api/v1/orders/{orderId}`, POST `/api/v1/orders` | Hợp đồng đang deploy; phản hồi GET thành công; chưa thử chi tiết vì không có ID được cấp | Xác nhận endpoint và schema hiện hành; ID đơn thuộc phạm vi được phép đọc nếu cần thử chi tiết |
| Xác thực giao diện AICRM | Không thấy tab/phiên AICRM trong inventory trình duyệt đã kiểm tra; không trích xuất cookie/token | Cách đăng nhập, loại token/phiên và scope giao diện AICRM | Tài liệu hoặc source frontend được cấp quyền nếu cần đối chiếu giao diện |
| Xác thực B2B | Laravel có cấu hình credential Live riêng nhưng hiện để trống; mặc định Bearer của Mock/Sandbox không chứng minh auth thật | API key/Bearer/OAuth/service account; tên header, scheme, cấp/refresh token, thời hạn, scope | Hợp đồng B2B và credential được cấp cho tích hợp; đặt trực tiếp vào cấu hình bí mật, không cần gửi vào chat |
| Tenant | Tài liệu nói danh sách thuộc tenant hiện tại qua `TenantContext`; response chi tiết có `tenantId` UUID | Tenant lấy từ claim/token/header nào; tenant thực của từng shop | Quy tắc tenant chính thức, tenant ID và mapping shop Local → tenant; phạm vi credential |
| Nhà vận chuyển và phương thức/dịch vụ | Tài liệu yêu cầu `shippingProviderId`; có `vtpServiceId` trong response và `ORDER_SERVICE` trong ví dụ webhook | Không có endpoint danh sách provider/service trong tài liệu; chưa có danh sách phương thức vận chuyển thật | Endpoint GET, schema và dữ liệu provider/service được phép cho tenant; cách chọn dịch vụ |
| `shippingProviderId` Viettel Post | `1` xuất hiện trong ví dụ tài liệu và cấu hình Mock Local | ID Viettel Post thật của tenant; ID provider không đồng nghĩa mã dịch vụ | ID thật cùng tên/provider, tenant, trạng thái hoạt động và các mã dịch vụ tương ứng |
| Frontend AICRM | Chưa tìm thấy source frontend AICRM trong workspace/tài liệu truy cập được; hai frontend hiện có là Pancake | Các API mà frontend AICRM sử dụng | Repo/bản source hoặc tài liệu giao diện được cấp quyền, nếu muốn dùng nguồn này để đối chiếu |
| Laravel Local | PHP 8.4.26, `APP_ENV=local`, DB host loopback; `mode=mock`, Live/creation/contract đều false; có lệnh `aicrm:live-check` chỉ GET | Chưa có kiểm thử API đọc thật có xác thực bằng Laravel | Hoàn tất Base URL + truy cập mạng + B2B + tenant + endpoint GET; provider/service cần thêm endpoint và ID tương ứng |

## Phạm vi nguồn đã đọc

- `docs/order_api.md` và cả bốn tài liệu AICRM Local/Live, validation hiện có;
  phần AICRM và cấu hình runtime trong `LOCAL_SETUP.md`.
- Toàn bộ `config/aicrm.php`, `AicrmLiveConnection`, `AicrmOrderClient`,
  `AicrmOrderPayload`, `AicrmShipmentService`, `AicrmMockApi`, hai model AICRM,
  controller AICRM, lệnh kiểm tra Live, hai migration và mock router.
- Các điểm nối tại route, model Order, WarehouseController, frontend API Kho,
  WarehousePage, ViettelPostShipment, shipmentConfirmation; toàn bộ test AICRM
  Backend/Frontend và phần test Kho liên quan vận đơn. Các test được đọc,
  **không chạy lại và không coi kết quả fake HTTP là kết nối thật**.
- Tìm kiếm tên/nội dung AICRM và file Swagger/OpenAPI/Postman trong workspace;
  không thấy source ứng dụng AICRM được cấp trong phạm vi này.
- Gmail kết nối: tìm `api-vtp.aicrm.vn` hoặc AICRM cùng API/Swagger/OpenAPI/Viettel,
  không có kết quả. Google Drive kết nối: tìm `AICRM`, `order_api`, không có kết quả.
- Tìm kiếm web theo domain AICRM, hostname được cấp và Swagger/OpenAPI,
  không có kết quả liên quan. Kết quả tìm kiếm rỗng không chứng minh tài liệu không tồn tại.
- Inventory trình duyệt hiện có không có tab AICRM. Không mở phiên đăng nhập mới,
  không đọc kho cookie, localStorage hoặc token trình duyệt.

## GET thật và giới hạn của bằng chứng

Yêu cầu duy nhất gửi thành công tới mạng ngoài trong phiên nghiên cứu:

```http
GET https://api-vtp.aicrm.vn/api/v1/orders?page=1&size=1
Accept: application/json
```

Không gửi credential/cookie, không thay user-agent để vượt chặn, không theo redirect,
không retry HTTP. Lần chạy trong môi trường mạng hạn chế ban đầu thất bại ở tầng
transport, không nhận HTTP; lần chạy có quyền mạng nhận 403 nói trên. Sau 403 đã
dừng request tới host này. Không thử danh sách đường dẫn Swagger thông dụng,
không dò login/provider endpoint và không thử đổi host/IP để vượt chặn.

Response là JSON báo lỗi Cloudflare, **không phải JSON danh sách đơn** theo schema
`content/pageNumber/pageSize/totalElements/totalPages`. Không có header
`WWW-Authenticate`; sự vắng mặt của header này cũng không xác nhận cơ chế auth.
403 ở đây không chứng minh credential sai, tenant sai hoặc API yêu cầu Bearer.

Metadata đã lọc, không lưu body dữ liệu đơn hoặc cookie:
[aicrm-api-research-orders-get-2026-11-04.json](aicrm-api-research-orders-get-2026-11-04.json).
Báo cáo cũ cũng ghi Cloudflare 1010 khoảng 15:45 cùng ngày; kết quả 16:49 là lần
kiểm tra mới, không dùng báo cáo cũ thay cho bằng chứng phiên này.

## Phân biệt các cơ chế xác thực

1. **Người dùng Pancake → Laravel Pancake:** source frontend gửi Bearer token
   Pancake; route Kho dùng `auth:api`/Passport và quyền shop/Kho. Đây không phải
   credential AICRM và không được gửi sang AICRM.
2. **Người dùng → giao diện AICRM:** chưa có bằng chứng về cơ chế xác thực.
   Ngay cả khi có source frontend hoặc đăng nhập thành công, điều đó chưa chứng
   minh token phiên được hỗ trợ cho tích hợp Backend-to-Backend.
3. **Laravel → AICRM vận đơn:** khối `aicrm.live` yêu cầu credential/header và
   tenant riêng. Khả năng cấu hình `header` hoặc `token` là thiết kế phía Pancake,
   không phải xác nhận server AICRM hỗ trợ cả hai. Cần đối chiếu hợp đồng thật.
4. **ZNS hiện có:** `SendZnsJob.php` tham chiếu `x-api-key` cùng
   `API_KEY_FROM_PANCAKE_WEB` cho `https://aicrm.vn/api/send-zns`; hàm hiện return
   ngay đầu. Có giá trị cấu hình khóa ZNS trên Local nhưng không kiểm tra, không
   in và không tái sử dụng. Dịch vụ/host/mục đích này không chứng minh auth API vận đơn.

## Kết quả kiểm tra Laravel Local

Đã boot Laravel bằng runtime dự án `/opt/homebrew/opt/php@8.4/bin/php`, đọc cấu hình
hiệu lực và xác nhận lệnh GET hiện có bằng `artisan help aicrm:live-check`.
Không truy vấn hoặc sửa DB, không chạy migration, queue, scheduler hay đồng bộ.

- `mode=mock`; `live.enabled=false`, `live.creation_enabled=false`,
  `live.contract_confirmed=false`.
- Chưa cấu hình `base_url`, `hosts`, `auth_header`, `auth_token`, `tenant_mode`,
  `tenant_header`, `credential_tenant`, `shop_tenants`, `shipping_provider_id`
  hoặc các đường dẫn GET Live.
- Kiểm tra hàm bảo vệ cấu hình dừng ở lỗi nội bộ **409: Kết nối Live AICRM đang tắt**,
  trước khi gọi mạng/DB. Đây là xác minh cấu hình Local, không phải HTTP 409 từ AICRM.
- Chưa chạy `aicrm:live-check` với API thật vì chưa có hợp đồng/credential/tenant
  hợp lệ. Không tự bật `contract_confirmed`, không dùng credential ZNS hoặc fixture.

Metadata cấu hình đã bỏ toàn bộ giá trị bí mật:
[aicrm-api-research-local-config-2026-11-04.json](aicrm-api-research-local-config-2026-11-04.json).

Để đọc dữ liệu thật, phần tối thiểu còn thiếu là **Base URL chính thức, quyền truy
cập mạng không bị chặn, cơ chế và credential B2B có scope đọc, quy tắc tenant cùng
mapping được cấp, endpoint GET/schema đang triển khai**. Đọc provider/service cần
thêm endpoint danh mục và quyền tương ứng. Provider ID không bắt buộc để chỉ đọc
danh sách đơn nhưng bắt buộc phải xác minh trước khi tích hợp tạo vận đơn.

Hợp đồng hiện có còn dùng `shippingProviderId` ở request nhưng
`shippingProvidersId` ở response; phần mô tả `moneyCollection` bắt buộc không đồng
nhất với kiểu TypeScript optional. Cần schema hiện hành để chốt, không tự sửa theo
phỏng đoán. Endpoint list/detail đã có tài liệu, không cần dò ID đơn hay đường dẫn khác.

Chỉ thêm báo cáo và metadata đã lọc trong phiên này. Không sửa source chức năng,
`.env`, dữ liệu Production hoặc công tắc Live; không gọi POST, không commit/push.
