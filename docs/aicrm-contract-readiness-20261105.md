# Luồng AICRM theo kiến trúc đã xác nhận — 05/11/2026

Repo `/Users/viet/Pancake/pancake_order`, branch `viet_dev`. Báo cáo này cập nhật yêu cầu API của bản tích hợp đã làm: **Pancake chỉ gọi AICRM; AICRM xử lý Viettel Post bên trong.** Các mô tả cũ về việc Pancake yêu cầu provider trả token hãng, hạn token hoặc người gửi không còn áp dụng.

## Thay đổi bổ sung

- `app/Services/AicrmLiveConnection.php`: bỏ toàn bộ kiểm tra trường `token`, `token_expires_at`, `senderName`, `senderPhone`, `senderAddress` trong response provider. GET chỉ cần đúng một bản ghi `code=VIETTEL_POST`, `isEnable=true`, `id` nguyên dương. Nếu AICRM có trả thêm trường nội bộ, Pancake không lưu/đưa vào payload hoặc response cho FE.
- `app/Services/AicrmOrderClient.php`: thêm method vào **client hiện hữu** để GET/chọn provider. GET và POST dùng cùng credential server-side của Shop. Live tiếp tục dùng helper AicrmLiveConnection hiện hữu; Sandbox cũng GET provider thay vì lấy ID tĩnh. Không thêm client, Service, Controller hoặc route.
- `app/Services/AicrmShipmentService.php`: bước GET đi qua AicrmOrderClient, nằm dưới khóa Order và trước khi tạo reservation/attempt. ID lấy từ GET được dùng để dựng payload và lưu request_payload của attempt. Readiness cũng dùng cùng client. Thông báo khắc phục provider hướng tới quyền API key và provider đang bật; không bắt FE cấu hình thông tin hãng.
- `tests/Feature/AicrmShipmentTest.php`: kiểm tra contract provider tối thiểu không có token/sender, bỏ giả định token hãng phải được Pancake kiểm tra; bổ sung GET→POST cùng key cho Sandbox, response lỗi không POST. Giữ các kiểm thử quyền, Shop, packed, gate, idempotency, timeout, unknown, audit và response đã có.

Controller, route, Frontend, config và dữ liệu nghiệp vụ giữ nguyên trong lượt này. Không bật gate, đổi `.env`, chạy POST thật, thay AICRM/Pancake Production hoặc commit/push.

## Luồng thực thi

1. FE Kho gọi `POST /api/v1/warehouse/orders/{id}/viettel-post`, body rỗng. Không có input tenant UUID, API key, giá hay COD trong luồng tạo shipment trên FE.
2. AicrmShipmentController áp dụng quyền `warehouse-view` + `warehouse-pack`, gọi AicrmShipmentService hiện hữu.
3. Service khóa Order/Shipment, kiểm quyền Shop, hàng đợi Kho, packed/packed_at, status nguồn, tracking/shipment cũ, binding Backend và toàn bộ Live gates/approved ID/mốc thời gian.
4. Service gọi AicrmOrderClient GET `/api/v1/shipping-providers` bằng X-API-Key của Shop. Không gửi header tenant hay tenantId. Chọn đúng một provider VIETTEL_POST có isEnable boolean true; không có/nhiều bản ghi phù hợp, ID sai hoặc response lỗi → 409 rõ nguyên nhân, chưa có attempt và không POST.
5. Service dựng đúng 8 trường từ order: shippingProviderId từ GET; productName/quantity từ items; totalPrice từ tổng POS hoặc COD + prepaid theo origin; moneyCollection từ COD; receiveFullName/receiverPhone/receiverAddress từ snapshot đơn. Giá/COD/provider/tenant do FE gửi tùy ý không được sử dụng.
6. Commit reservation processing + attempt audit trước HTTP. AicrmOrderClient POST `/api/v1/orders` tới **AICRM**, cùng key dùng GET. Không redirect credential và không retry POST.
7. HTTP success, envelope status integer 200, error boolean false và `data.ORDER_NUMBER` chuỗi hợp lệ → lưu vào `aicrm_shipments.tracking_number` và `orders.order_number_vtp`; FE refetch rồi hiển thị mã. Không suy UUID hay trạng thái giao hàng từ envelope status=200.
8. POST timeout, response mơ hồ hoặc thiếu mã hợp lệ → unknown, giữ khóa gửi lại. Đối soát GET hiện hữu được giữ; unknown chưa rõ tracking không tự mở khóa. Không gửi trực tiếp tới URL Viettel Post.

Mock/simulation vẫn là chế độ riêng, không được trình bày như vận đơn thật; không đổi luồng đó trong lần này.

## Ranh giới AICRM API và nội bộ Pancake

**API AICRM yêu cầu:** origin AICRM được cấu hình, X-API-Key, GET provider và POST tám trường. AICRM tự xác định tenant/quyền từ key và tự làm việc với hãng. Pancake không cần và không được yêu cầu API trả token hãng để cho phép gửi.

**Nội bộ Pancake:** quyền Kho/Shop, packed, một approved ID, mốc kích hoạt, audit, lock, chống trùng/unknown, lựa chọn credential cho Shop và metadata tenant dùng đối soát. Binding UUID hiện có nằm hoàn toàn trong cấu hình Backend để chống dùng sai credential và đối chiếu shipment cũ. Đây không phải trường FE phải nhập hay trường/header gửi tới AICRM. Bản ghi binding chưa có cần quản trị Backend hoàn tất từ nguồn tin cậy; response providers đơn lẻ không xác minh được UUID chủ sở hữu key.

**Nội bộ AICRM:** cấu hình và token Viettel Post, hạn token, người gửi, môi trường hãng và việc gọi hãng. Nhóm AICRM chịu trách nhiệm sẵn sàng các phần đó; chúng không phải input/config bắt buộc của FE hoặc điều kiện đọc các trường bí mật từ provider DTO trên Pancake.

## Chính xác nguyên nhân Live đang disabled

Đã kiểm tra lúc **13:01 +07:00, 05/11/2026** bằng GET qua HTTP Kernel Laravel trong tiến trình kiểm tra, actor Kho 637 và MySQL Local transaction READ ONLY; không tạo access token hoặc chạy simulation. Đối chiếu thêm các biến không bí mật được export trong tiến trình Backend 8002 (PID 43393).

`WarehouseController.php` trả `can_create_shipment=false`; `ViettelPostShipment.jsx` giữ `disabled={!order.can_create_shipment || pending}`. POS-349323 nhận đúng bảy blockers:

| Điều kiện | Effective | Kết quả |
|---|---|---|
| AICRM_LIVE_ENABLED | false | FAIL |
| AICRM_LIVE_CREATION_ENABLED | false | FAIL |
| AICRM_LIVE_CONTRACT_CONFIRMED | false | FAIL về cờ vận hành; chưa tự đổi dù hợp đồng vừa được xác nhận trong trao đổi |
| AICRM_LIVE_RECONCILIATION_CONFIRMED | false | FAIL |
| AICRM_LIVE_APPROVED_ORDER_ID | rỗng | FAIL |
| Recipient của POS-349323 | số 0000000641, dữ liệu KHONG GIAO HANG | FAIL; không sửa tự động |
| Binding Backend cho Shop 38 | chưa có | FAIL |

Đơn đã packed, chưa tracking, module/queue/status nguồn hợp lệ. Mốc Live `2026-11-05T09:45:12+07:00` có trước ngày tạo đơn, nhưng không thay thế các gate/approval/recipient trên. Sau kiểm tra vẫn **0 shipment, 0 attempt**, audit POS-349323 vẫn **2 bản ghi cũ**.

Ngoài bảy blockers hiện được trả về, còn cấu hình kết nối Local chưa được chuẩn bị:

- `AICRM_MODE=live`.
- `AICRM_LIVE_API_URL=https://api-vtp.aicrm.vn`, **chưa trỏ AICRM Local 8185**. Tên được code đọc là AICRM_LIVE_API_URL, không phải AICRM_LIVE_URL.
- `AICRM_LIVE_LOCAL_HTTP_ENABLED=false`.
- Dedicated Local key chưa có; dedicated Shop 38 binding chưa có.
- Có key cho nhánh remote, nhưng không gửi key đó qua Local và không coi sự hiện diện là bằng chứng quyền hợp lệ.
- Không có config cache. Tiến trình 8002 có export AICRM env, vì vậy sửa `.env` một mình chưa đảm bảo effective thay đổi; phải nạp lại đúng tiến trình Local khi người dùng áp dụng cấu hình.

Không kết luận trạng thái provider hiện tại bằng key Local mới vì key đó chưa được cấu hình và không gọi API AICRM thật trong lượt này. Kết quả GET/provider được kiểm thử bằng HTTP Fake.

## Cấu hình/nghiệp vụ còn thiếu để người dùng phê duyệt một đơn thật

1. Chọn đích AICRM Mac Local: `AICRM_LIVE_API_URL=http://127.0.0.1:8185`. Ngoại lệ có sẵn chỉ chấp nhận đúng origin này, APP_ENV=local, Shop 38 và cờ `AICRM_LIVE_LOCAL_HTTP_ENABLED=true`; các môi trường khác vẫn HTTPS/host allowlist/443.
2. Cấp **key AICRM Local riêng** qua Backend ENV `AICRM_LIVE_LOCAL_API_KEY`. Không dùng key remote/Production. Hoàn tất binding Backend `AICRM_LIVE_LOCAL_CREDENTIAL_TENANT` và `AICRM_LIVE_LOCAL_SHOP_TENANTS` cho Shop 38; không nhập UUID trên FE, không gửi UUID trong POST. Không cần điền provider ID tĩnh để tạo Live: ID được khám phá từ GET.
3. Được nhóm AICRM xác nhận key có quyền GET/POST và trả đúng một VIETTEL_POST enabled. AICRM chịu trách nhiệm chuẩn bị nội bộ hãng; Pancake không kiểm tra token/sender DTO nữa.
4. Dùng một đơn packed với người nhận thực tế đã xác nhận. POS-349323 hiện không đạt; có thể tự tạo một đơn POS mới bằng luồng hiện hữu. Nếu cần sửa đơn cũ, phải qua chức năng có audit được phê duyệt riêng; không sửa SQL hoặc hồ sơ khách hàng để giả lập sửa snapshot đơn.
5. Review mốc AICRM_LIVE_ACTIVATED_AT hợp lệ, có timezone, không backdate và đơn phải được tạo sau mốc. Đặt AICRM_LIVE_APPROVED_ORDER_ID đúng **một ID**; không wildcard/danh sách.
6. Sau xác nhận hợp đồng và quy trình timeout/đối soát, người phụ trách mới đặt các cờ connection/contract/reconciliation true. **Giữ creation=false trong bước chuẩn bị**. Sau review cuối cùng mới tự phê duyệt `AICRM_LIVE_CREATION_ENABLED=true` và nạp lại Backend Local. Không có bước nào trong báo cáo này đã được thực thi để bật gate.
7. Tải lại Kho → Đã đóng hàng → Shop 38 → đúng ID phê duyệt. Readiness sẽ GET provider khi gate đã đạt. Nếu hết blockers, bấm “Tạo vận đơn Viettel Post (Live)” rồi xác nhận thủ công. Bật gate không tự POST. Sau lần thử có kiểm soát, tắt creation; nếu unknown thì đối soát, không bấm lại hoặc reset attempt.

## Kiểm thử và bằng chứng

- **249 Backend tests / 2.416 assertions PASS**: AicrmShipmentTest, AicrmLocalConnectionTest, WarehouseTest, PosOrderTest; SQLite `:memory:`, Http::fake(), chặn request ngoài fake.
- Bao phủ provider DTO tối thiểu ba trường; trường nội bộ hãng thiếu/expired/sai định dạng không chặn Pancake; GET rồi POST cùng key cho Live/loopback/Sandbox; đúng tám trường và không tenantId/header tenant; dữ liệu tiền từ DB; lưu tracking/audit; provider không hợp lệ không POST; envelope sai/timeout/unknown/double-click/quyền/gate vẫn được kiểm tra.
- **22/22 FE Kho tests PASS**, gồm click/xác nhận/loading/tracking/disabled/mô phỏng/unknown.
- PHP syntax AicrmOrderClient, Pint trên các file sửa và git diff --check: PASS.
- `aicrm-contract-readiness-20261105.evidence.json`: snapshot GET/flags/counts và env non-secret của Backend Local. Không chứa key hoặc provider token.
- Không chạy POST thật, không tự bật Live creation, không thay AICRM hoặc Pancake Production, không commit/push. Các thay đổi từ lượt trước được giữ; không thêm đường tích hợp song song.
