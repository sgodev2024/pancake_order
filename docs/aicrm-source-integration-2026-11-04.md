# Pancake AICRM — hoàn thiện theo source thực tế, 04/11/2026

Đã sửa trong `/Users/viet/Pancake/pancake_order`, branch `viet_dev`, trên module
AICRM có sẵn. Working tree đã có nhiều thay đổi chưa commit từ trước; giữ nguyên
phần POS, Kho, sản phẩm và phân quyền đã có. Không sửa `/Users/viet/api-vtp`,
không gọi API AICRM thật, không bật Live, không commit/push.

## Source đối chiếu

`/Users/viet/api-vtp`, branch `main`, HEAD đúng
`7dfb3be375e3b19e2656a014b440b07190a8bc6d`. Working tree AICRM sạch trước/sau.

| Source Java/Spring Boot | Hệ quả cho Pancake |
| --- | --- |
| `viettelpost/order/dto/CreateOrderRequest.java` | Giữ đúng tám trường JSON |
| `db/migration/V36__create_order_table.sql` | product_name VARCHAR(255); money_collection BIGINT |
| `viettelpost/order/OrderController.java` | X-API-Key; POST trả response Viettel Post, GET list/detail theo tenant |
| `kernel/TenantContextFilter.java` và `OrderService.findTenantIdByApiKey` | Key ACTIVE, còn hạn xác định tenant; không suy đoán header tenant |
| `viettelpost/order/dto/ViettelPostOrderResponse.java` | POST có ORDER_NUMBER, không có UUID đơn AICRM |
| `viettelpost/order/dto/OrdersPageResponse.java`, `OrderSummaryResponse.java` | Trang bắt đầu 1; content/id/vtpOrderNumber và metadata phân trang |
| `viettelpost/order/dto/OrderResponse.java` | Detail có UUID, tenantId, vtpOrderNumber, status |
| `enums/OrderStatus.java` | Đúng tám trạng thái GET, CANCELED viết một L |
| `viettelpost/dto/ShippingProviderResponse.java` | Response provider có token hãng; chỉ lấy ID hợp lệ |

Đường dẫn trong bảng nằm dưới `backend/src/main/java/vn/aicrm/`, trừ
`kernel/TenantContextFilter.java` dưới `vn/coreplatform/` và SQL dưới
`backend/src/main/resources/`.

## Phần đã sửa

1. **Payload:** giữ X-API-Key, JSON và tám trường. Giới hạn productName sau khi
   ghép tên, số lượng, dấu phân cách ở 255 ký tự Unicode. Vượt giới hạn trả 422
   bằng tiếng Việt trước khi giữ chỗ/gửi; không cắt hoặc đổi dữ liệu sản phẩm.
   moneyCollection phải nguyên VND, không âm, không mất 0; không làm tròn phần lẻ.
   Giữ cách tính totalPrice và quan hệ COD/prepaid/POS hiện tại.
2. **POST:** chỉ lưu kết quả gửi và data.ORDER_NUMBER. Bỏ đọc UUID từ path tùy cấu
   hình và bỏ lưu envelope status=200 thành remote_status. Dù response giả lập có
   thêm id/confirmedId, client cũng không nhận làm UUID. Không sinh UUID AICRM.
3. **Tenant:** thêm AicrmTenantCredentials, mapping shop Local → UUID tenant →
   API Key/provider ID riêng. Thiếu map không lấy key chung; key bị cấu hình cho
   nhiều tenant bị từ chối. Nhiều shop cùng một tenant vẫn dùng được chung key.
   Cấu hình một key cũ chỉ hợp lệ khi credential_tenant khớp chính xác shop.
   Cấm tenant_mode=header/token, Bearer và header X-Tenant-ID trong B2B AICRM.
4. **Liên kết UUID qua GET:** thêm lệnh aicrm:reconcile với actor có cả hai quyền
   Kho và quyền shop. Chỉ áp dụng shipment Live đã thành công có tracking.
   Quét đủ phân trang, yêu cầu đúng một tracking khớp, kiểm tra UUID hợp lệ và GET
   detail khớp tenantId/UUID/tracking/status rồi mới lưu. Không tự chạy sau POST,
   không mở khóa unknown, không thay UUID đã gắn khác. Không ghi raw response.
5. **Trạng thái:** map chính xác PENDING, PICKING, PROCESSING, CANCELED, SHIPPING,
   DELIVERED, RETURNED, ERROR ở Backend và Frontend. Frontend phân biệt kết quả
   tạo vận đơn với trạng thái đơn AICRM lấy từ GET; khi chưa có UUID/trạng thái
   thì hiển thị rõ. Giá trị legacy `200` không được hiển thị như trạng thái GET.
6. **Chống trùng:** giữ reservation trước HTTP, unique order_id, replay kết quả,
   khóa processing/unknown và không tự retry POST. Thêm tenant_id snapshot để
   chặn gửi/đối soát qua tenant đã đổi. Giữ nguyên trạng thái bán hàng, đóng hàng,
   thanh toán, tồn kho và phân quyền. Mock không ghi tracking vào luồng hãng cũ.
7. **Provider:** giữ bộ lọc đúng một VIETTEL_POST đang bật, ID nguyên dương;
   xác minh GET dùng key của đúng tenant. Không log/lưu/trả token hoặc thông tin
   người gửi ra Frontend. Không dùng ID ví dụ làm ID hãng thật.
8. **Tài liệu:** cập nhật cấu hình hiện hành trong aicrm-api-key-integration.md,
   aicrm-viettel-post-live.md và aicrm-viettel-post-local.md, bỏ chỉ dẫn auth cũ.

Migration `2026_11_04_000003_add_aicrm_tenant_binding.php` chỉ thêm tenant_id
nullable. **Chưa áp dụng vào DB Local/thật**; chỉ chạy trong SQLite bộ nhớ tests.
Cần áp dụng trước khi sử dụng Sandbox/Live. Không backfill shipment cũ tenant null;
phải xác minh thủ công. Đã kiểm chứng Mock offline vẫn hoạt động khi chưa chạy
migration này, để giữ luồng hiện tại trong khi Live tắt.

## Kiểm thử

Backend dùng PHP 8.4, DB SQLite bộ nhớ; không dùng DB dữ liệu Local. Toàn bộ HTTP
AICRM trong test bị fake và chặn request ngoài; tắt đường test HTTP thật qua biến
AICRM_LOCAL_HTTP_TEST_URL rỗng. Công tắc Live trong test chỉ là config trong bộ nhớ.

| Kiểm tra | Baseline | Kết quả cuối |
| --- | --- | --- |
| AICRM | 70 PASS | **113/113 PASS** |
| AICRM + Kho + POS | 105 PASS | **148/148 PASS** |
| Toàn bộ Backend | 537 PASS, 5 FAIL / 542 | **580 PASS, 5 FAIL / 585** |
| Toàn bộ Frontend | 99 PASS, 1 FAIL / 100 | **102 PASS, 1 FAIL / 103** |
| Frontend AICRM + Kho + POS | — | **28/28 PASS** |
| Backend Pint, file sửa | — | **PASS** |
| PHP syntax, 19 file AICRM/fixture | — | **PASS** |
| Composer validate, không plugins/scripts | — | **PASS** |
| Artisan đăng ký hai lệnh AICRM | — | **PASS** |
| Frontend lint | — | **PASS**, còn warning ở phần khác; không warning AICRM mới |
| Frontend production build | — | **PASS** |
| git diff --check | — | **PASS** |

Backend Laravel không khai báo build script riêng. Đã kiểm tra cú pháp, manifest
dependency và khởi tạo/đăng ký lệnh; không chạy optimize/config cache trên cấu hình
dữ liệu thật. Frontend đã chạy production build bằng Vite.

Thêm 43 trường hợp Backend và 3 test Frontend: biên 255/256 ký tự tiếng Việt,
tên ghép nhiều sản phẩm, COD 0/chuỗi số nguyên/lẻ/âm/thiếu/sai kiểu/vượt giới hạn,
hai shop hai tenant chọn key/provider khác, thiếu key/trùng key/đổi tenant,
POST không trả UUID/trạng thái, tám enum GET, khớp ở trang sau, nhiều kết quả
khớp xuyên trang, sai tenant/tracking/UUID/status, phân trang đổi hoặc sai,
401/HTML/timeout, quyền actor/shop, giữ khóa unknown và tương thích Mock trước migration.

Các lỗi **trùng baseline**, đã đối chiếu tự động bằng JUnit; không sửa ngoài phạm vi:

- OpportunitySecurityHardeningTest::test_valid_assign_preserves_response_assignment_state_dates_and_activity_log
- OpportunitySecurityHardeningTest::test_staff_and_monitoring_workload_aggregates_count_only_actionable_cares
- OpportunitySecurityHardeningTest::test_import_rejects_incomplete_rows_and_invalid_phone_numbers_without_partial_import
- OpportunitySecurityHardeningTest::test_phase_3_monitoring_returns_only_users_intersecting_the_effective_shop_scope
- OrderSourceApiTest::test_summary_contract_is_compact_while_default_keeps_the_legacy_payload
- Frontend customerCareActionParity.test.js: completion modal has V1 fields, validation, exact payload and reset/refresh behavior

Lệnh chính đã chạy:

```sh
PATH=/opt/homebrew/opt/php@8.4/bin:$PATH DB_CONNECTION=sqlite DB_DATABASE=:memory: AICRM_LOCAL_HTTP_TEST_URL= php artisan test
cd frontend
node --test --test-force-exit tests/*.test.js
npm run lint
npm run build
```

Node cần --test-force-exit vì bộ test query/cache có handle giữ tiến trình;
flag không bỏ qua test lỗi. Baseline Frontend sạch được chạy trong bản sao tạm
với component AICRM và tests nguyên trước sửa; không đảo source working tree.

Log/JUnit:

- `/private/tmp/aicrm-source-backend-baseline.{log,xml}`
- `/private/tmp/aicrm-source-backend-final.{log,xml}`
- `/private/tmp/aicrm-source-frontend-baseline-clean.log`
- `/private/tmp/aicrm-source-frontend-final.log`
- `/private/tmp/aicrm-source-frontend-target.log`
- `/private/tmp/aicrm-source-frontend-{lint,build}.log`
- `/private/tmp/aicrm-source-backend-{lint,package}.log`

## Thông tin còn thiếu để kết nối thật

- Hostname/môi trường B2B chính thức và xác nhận bản deploy phù hợp source audit.
- API Key thật ACTIVE, chưa hết hạn; UUID tenant hợp lệ và mapping từng shop.
  Cấu hình chỉ khai báo quyền sở hữu key, không thể tự chứng minh key thuộc tenant
  nếu chưa có GET thật; provider DTO không có tenantId.
- ID Viettel Post đang bật lấy từ GET có xác thực trong từng tenant; không lấy từ fixture.
- Quyền mạng B2B/Cloudflare: các lần thăm dò trước phiên này có 403/1010; phiên này
  không thử lại hoặc vượt chặn.
- Quy trình đối soát timeout khi chưa nhận tracking: hợp đồng tám trường không có
  client reference/idempotency. Không tự ghép theo người nhận/tiền hoặc mở khóa POST.

GET dùng offset, không có snapshot/cursor. Bộ kiểm tra từ chối metadata biến động,
UUID trùng và danh sách quá 100 trang, nhưng không chứng minh snapshot tuyệt đối
khi dữ liệu upstream thay đổi đồng thời. Tenant quá 10.000 đơn cần đối soát riêng;
không gắn UUID từ một phần danh sách.

Trạng thái cấu hình thực tế kiểm tra cuối phiên: mode=mock; live.enabled=false,
creation_enabled=false, contract_confirmed=false; chưa cấu hình URL/key Live,
map credential Live rỗng. Không sửa .env, không chạy migration trên DB dữ liệu,
không worker/scheduler, không tạo vận đơn thật và không commit/push.
