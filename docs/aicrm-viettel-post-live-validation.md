# Audit và kiểm thử AICRM Live — 04/11/2026

## Kết luận bàn giao

**Chưa xác minh được kết nối Backend AICRM có xác thực. Live OFF, tạo thật OFF.**
Đã hoàn thiện phần cấu hình/gate Live, lệnh GET kiểm tra kết nối, metadata và giao diện,
nhưng không thể xác nhận hợp đồng server thật khi request bị Cloudflare chặn và chưa
có thông tin B2B. Không lấy kết quả Mock/fake HTTP làm bằng chứng kết nối Live.

Repository `pancake_order`, nhánh `viet_dev`, vẫn ahead 5 như đầu phiên. Không
fetch/merge/commit/push; giữ nguyên các thay đổi chưa commit từ các công việc trước.
Đã đọc `docs/aicrm-viettel-post-local.md`, `docs/aicrm-viettel-post-validation.md`,
`docs/order_api.md`, source Service/config/controller/route/test và schema MySQL Local.

## 1–4. Base URL, auth, kết quả thật và provider

| Nội dung | Kết quả xác minh |
| --- | --- |
| Host người dùng cung cấp | `https://api-vtp.aicrm.vn`, trang giao diện `/home` |
| Backend API Base URL thực tế | **Chưa xác minh**, không tự gán từ URL giao diện |
| Xác thực B2B | **Chưa xác minh**, `.env` chưa có credential Live |
| Cơ chế tenant | **Chưa xác minh**; tài liệu cũ chỉ nhắc `TenantContext` |
| Endpoint nhà vận chuyển | **Chưa có trong hợp đồng được cung cấp**, không thử đường dẫn phỏng đoán |
| ShippingProviderId Viettel Post thật | **Chưa xác minh**; ID Mock `1` không được dùng làm ID Live |
| Endpoint tạo/list/detail | Tài liệu cũ ghi POST/GET `/api/v1/orders`, GET `/api/v1/orders/{orderId}`; **chưa đối chiếu được bản đang deploy** |
| Đối soát timeout | **Chưa có hợp đồng idempotency/reference/lookup xác nhận**; tiếp tục khóa `unknown`/`processing` |

GET thực, không token/cookie, không in body dữ liệu đơn:

- Mở URL `/home` bằng HTTP client: HTTP **403**, HTML; không đọc được cấu hình giao diện.
- GET `https://api-vtp.aicrm.vn/api/v1/orders?page=1&size=1`: HTTP **403**, JSON,
  server Cloudflare. Đường dẫn lấy từ `order_api.md`, không phải kết luận Base URL.
- Bằng chứng kiểm tra lúc khoảng **15:45, 04/11/2026 (UTC+7)**:
  `error_code=1010`, `error_name=browser_signature_banned`,
  `error_category=access_denied`, `title=Error 1010: Access denied`,
  `owner_action_required=true`, `retryable=false`. Không có challenge scheme
  `WWW-Authenticate` để xác nhận auth Backend.

403 này là lỗi truy cập Cloudflare, không phải bằng chứng token sai, tenant sai hoặc
route Backend tồn tại. Không đổi chữ ký client, vượt CAPTCHA/challenge hoặc dùng
cookie đăng nhập của người dùng để vòng qua chặn. Sau khi xác định lỗi, không tiếp
tục dò endpoint/phương thức đăng nhập. Không gửi POST tạo vận đơn thật.

Metadata công khai, đã bỏ body/cookie/token, lưu tại:
`/private/tmp/pancake-aicrm-live-orders-probe.json`.

## 5. File và cấu hình thay đổi trong giai đoạn Live

| Nhóm | File / nội dung |
| --- | --- |
| Kết nối Live | `app/Services/AicrmLiveConnection.php`: HTTPS, host chính xác, hợp đồng đã xác nhận, credential/header riêng, tenant theo shop, GET/POST riêng, TLS verify, không cookie/redirect/retry |
| Kiểm tra chỉ đọc | `app/Console/Commands/CheckAicrmLiveConnection.php`: `aicrm:live-check`, kiểm tra actor/shop/quyền xem; chỉ GET, chỉ output HTTP/JSON, không body/secret |
| Cấu hình | `config/aicrm.php`, `.env.example`: khối `live` riêng, endpoint trống đến khi xác minh; `.env` Local chỉ thêm ba công tắc OFF, không đổi Mock/APP_ENV/credential |
| Luồng gửi hiện có | `AicrmOrderClient.php`, `AicrmOrderPayload.php`, `AicrmShipmentService.php`: nối Live vào module cũ, provider Live riêng, một ID đơn được duyệt, mốc tạo mới, chống lấy cached Mock làm Live, lưu ID/trạng thái upstream |
| Dữ liệu | `app/Models/AicrmShipment.php`, migration `2026_11_04_000002_add_aicrm_remote_order_metadata.php`: thêm `remote_order_id`, `remote_status` nullable ở cả shipment và attempt |
| API hiện có | `AicrmShipmentController.php`, `WarehouseController.php`: thông báo Live, trạng thái công tắc an toàn, chỉ cho nút đủ điều kiện; giữ route và Passport/quyền nghiêm ngặt |
| Frontend | `frontend/src/pages/Warehouse/ViettelPostShipment.jsx`, `shipmentConfirmation.js`, `WarehousePage.jsx`: phân biệt Live/Mock/Sandbox, cảnh báo vận đơn thật, metadata và nút bị khóa khi chưa cho phép |
| Test | `phpunit.xml`, `tests/Feature/AicrmShipmentTest.php`, `tests/Feature/WarehouseTest.php`, `frontend/tests/viettelPostShipment.test.js` |
| Hướng dẫn | `docs/aicrm-viettel-post-live.md`, báo cáo này; bổ sung liên kết cập nhật ở hai tài liệu Mock cũ và `LOCAL_SETUP.md` ngoài repo |

Không tạo lại module, không thêm route public cấu hình credential, không thay đổi
POS/quản lý sản phẩm hoặc job tự tạo vận đơn. Route tạo cũ vẫn yêu cầu Passport,
`warehouse-view,strict`, `warehouse-pack,strict`, shop hợp lệ và đơn đã đóng.

Live cho phép ứng dụng giữ nguyên `APP_ENV=production` nếu được cấu hình đầy đủ;
không chỉnh môi trường thành local để vượt rào. Mock/Sandbox giữ chặn Production cũ.

## 6. Kết quả kiểm thử

| Kiểm tra | Baseline đầu giai đoạn Live | Sau thay đổi |
| --- | --- | --- |
| Backend toàn bộ, SQLite bộ nhớ | 501/506 đạt, 5 lỗi | **515/520 đạt**, đúng 5 lỗi cũ |
| AICRM + Kho | 53/53 | **67/67 đạt**, 362 assertions |
| Frontend toàn bộ | 97/98 đạt, 1 lỗi | **99/100 đạt**, đúng 1 lỗi cũ |
| Frontend AICRM + Kho | 14/14 | **16/16 đạt** |
| Frontend build | — | Thành công, Vite 8.2.2 |
| Frontend lint | — | Exit 0, 18 warning tại các phần ngoài thay đổi Live |
| PHP Pint các file PHP Live sửa/thêm | — | Đạt |
| `git diff --check` | — | Đạt |

Đã đối chiếu từng tên test thất bại trước/sau bằng script; trùng hoàn toàn:

- Backend: 4 test của `OpportunitySecurityHardeningTest` và 1 test
  `OrderSourceApiTest::test_summary_contract_is_compact_while_default_keeps_the_legacy_payload`.
- Frontend: `completion modal has V1 fields, validation, exact payload and reset/refresh behavior`
  trong `customerCareActionParity.test.js`.

Test mới kiểm tra Live OFF không fallback Mock, HTTPS/host/endpoint/header/tenant,
credential khác tenant bị chặn, thiếu provider, mốc thời gian/đơn lịch sử, chỉ một ID
được duyệt, không nhấn/gửi đồng thời, replay, timeout không retry, lưu ID/status,
GET command không rò body/secret, GET 200 HTML không coi thành công, quyền shop và
giao diện phân biệt vận đơn thật. Toàn bộ POST trong test đều qua **fake HTTP** tới
domain `.example.test`; không gửi ra AICRM thật.

PHPUnit ép hai công tắc Live false khi boot, sau đó các test riêng mới ghi đè config
với fixture giả và `Http::preventStrayRequests()`. Chưa chạy kiểm thử B2B có credential;
lệnh GET thật đã sẵn sàng nhưng chưa được bật/chạy với tài khoản AICRM hợp lệ.

Log: `/private/tmp/pancake-aicrm-live-{backend,frontend}-{baseline,final}.log`,
`pancake-aicrm-live-target.log`, `pancake-aicrm-live-frontend-target.log`,
`pancake-aicrm-live-build.log`, `pancake-aicrm-live-lint.log`, `pancake-aicrm-live-pint.log`.

## 7–8. Thông tin thiếu và trạng thái bật Live

Thiếu đủ các mục xác minh trong bảng trên: Base URL thật, auth B2B, credential/scope,
tenant mapping, endpoint/provider ID Viettel Post, contract create/detail hiện hành,
quy trình đối soát và cách truy cập B2B qua Cloudflare. Đã yêu cầu người dùng cung cấp
OpenAPI/Postman và hướng dẫn, không yêu cầu gửi mật khẩu/token trong chat.

Kiểm tra cuối trên MySQL Local:

- `AICRM_MODE=mock`, `LIVE_ENABLED=false`, `LIVE_CREATION_ENABLED=false`,
  `LIVE_CONTRACT_CONFIRMED=false`.
- URL/auth/provider Live **chưa cấu hình**. Chưa đủ điều kiện bật Live.
- Migration mới đã áp dụng riêng; bốn cột metadata nullable đã có.
- **90.587 đơn, 6.148 sản phẩm, shipment 0, attempt 0**, bằng số liệu audit đầu phiên.
- Backend `/up`: HTTP 200; Frontend `/kho`: HTTP 200.
- Không đồng bộ hàng loạt, không tạo vận đơn lịch sử, không queue worker/scheduler,
  không tạo vận đơn thật, không commit/push.

## 9. Bước còn lại cho một vận đơn thật có kiểm soát

1. Bên AICRM xử lý Cloudflare/hướng dẫn kết nối B2B và cung cấp hợp đồng/credential.
2. Đối chiếu rồi điền khối Live trong `.env`; chỉ bật GET, giữ tạo thật OFF.
3. Chạy `aicrm:live-check` với actor/shop hợp lệ để kiểm tra auth, providers và
   danh sách/chi tiết thuộc tenant; xác minh provider ID thực tế bằng response hợp lệ.
4. Xác nhận idempotency hoặc phương án đối soát khi mất phản hồi; nếu auth thực tế
   cần OAuth/login/refresh khác credential header tĩnh, triển khai theo hợp đồng đó.
5. Người dùng thống nhất tài khoản, shop, người nhận/địa chỉ thật, tiền/sản phẩm và
   một đơn thử mới; có chấp thuận cụ thể trước khi bật POST cho ID đơn đó.
6. Người có quyền nhấn nút Live, tạo đúng một vận đơn; lưu và đối chiếu mã/ID/status,
   sau đó tắt lại công tắc tạo. Không retry nếu kết quả chưa rõ.

Chi tiết từng khóa/lệnh và điều kiện nằm trong [runbook Live](aicrm-viettel-post-live.md).
