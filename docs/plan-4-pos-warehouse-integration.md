# PLAN 4 — Kiểm thử tích hợp POS → Kho trên Local

Ngày kiểm tra: 22/10/2026, Asia/Ho_Chi_Minh. Backend và Frontend: branch `viet_dev`.

**Kết luận: SẴN SÀNG DEMO LOCAL bằng tài khoản/dữ liệu TEST.** Luồng POS UI → API → MySQL → Kho đã kiểm chứng trực tiếp. Luồng đồng bộ đã PASS pipeline mô phỏng trên MySQL TEST riêng; **chưa thể kiểm chứng bằng đơn Pancake thực tế mới** vì Local chưa có đơn nguồn tạo sau mốc kích hoạt. Không tuyên bố kết quả mô phỏng là live Pancake.

## 1. Trạng thái và an toàn

- Đọc báo cáo PLAN 3, kiểm tra Git trước sửa, lưu bản source trước sửa và dữ liệu đối chiếu riêng tư.
- Main Local: MySQL `127.0.0.1:3306`, `pancake_order`, UUID/datadir khớp baseline đã kiểm chứng; APP_ENV=local. API 8000, Frontend 5173.
- Kho **ON**, giữ nguyên `WAREHOUSE_ACTIVATED_AT=2026-10-22T13:54:15+07:00`; không sửa `.env`, không chạy lại migration Kho.
- LaunchAgent `vn.pancake.local.warehouse-worker` vẫn running; worker cùng mốc cấu hình và hash WarehouseService/OrderService/hai job sync. Các file đó không đổi trong PLAN 4 nên không cần restart worker. Bốn job nghiệp vụ khác còn nguyên.
- Trước kiểm thử **90.578** orders; sau **90.580**. Thêm đúng hai đơn TEST, không thêm shop/sản phẩm/khách hàng hoặc tài khoản mới; tái sử dụng fixture PLAN 3.
- **90.577 đơn lịch sử giữ nguyên**, warehouse_queued_at lịch sử vẫn 0. Checksum toàn bộ giá trị cột cũ của 37 bảng khớp backup baseline, loại riêng catalog/migration/fixture TEST đã được duyệt.
- Toàn bộ hàng của **349305 và TEST 349306** khớp trước/sau PLAN 4. Không gửi request hoàn tất cho hai đơn này.
- Không giao hàng/thu tiền thật; cash=0 và payments=[] trên cả hai đơn mới. COD là kỳ vọng mô phỏng, không phải khoản đã thu. Không gọi Pancake gốc, MISA, Viettel Post hoặc ZNS. Không xóa/reset/rollback MySQL, không commit/push.

## 2. Lỗi phân quyền và sửa

**Nguyên nhân:** role `staff-sale` thật thiếu `list-product`; ngoài hai quyền đọc sản phẩm/khách hàng, POS còn hard-code danh sách role `admin/staff-sale/manager-sale`. Một role TEST riêng có hai quyền đọc vẫn bị từ chối. Không tạo slug trùng role Sale và không sửa quyền role dùng chung cho nhân viên thật.

**Sửa tối thiểu:** giữ hành vi các role Sale/Admin cũ; role tùy chỉnh phải được cấp tường minh **`pos-create`**, đồng thời vẫn bắt buộc **`list-product` + `list-customer`**. Backend và Frontend dùng cùng điều kiện; quyền đọc đơn thuần không mở quyền tạo POS. ShopAccessService và giới hạn customer hiện có được giữ nguyên.

Migration bổ sung `2026_10_22_000001_add_explicit_pos_permission.php` đã chạy riêng trên Local: **PASS**. Chỉ đăng ký catalog `pos-create` vào nhóm sản phẩm có sẵn; không đổi schema/orders và không tự gán quyền. Chạy lặp không tạo trùng hoặc đổi grants, đã test trên SQLite. Migration từ chối rollback xóa catalog/grants.

Tạo role **18**, `sales-pos-local-test-20261022`, tên TEST Local PLAN 4 - Sale POS; cấp đúng ba quyền nêu trên. Chỉ đổi role của **Sale TEST 636**, `sale.local-plan3@pancake.test`, shop **38**. Không đổi quyền/role/tài khoản thật hoặc thành viên shop cũ.

Kho TEST vẫn role 17, chỉ warehouse-view/warehouse-pack: ID 637 và 638 shop 38; ID 639 shop 39 dùng kiểm tra từ chối. Chỉ account TEST này được sử dụng.

Thông tin đăng nhập hiện tại nằm trong tệp riêng tư, không lưu mật khẩu/token vào Git/báo cáo:
`/Users/viet/Pancake/.local-backups/plan4-integration-20261022-143034/TEST-LOCAL-PLAN4-LOGIN-PRIVATE.txt`.

## 3. Kết quả POS trực tiếp trên Local

| Đơn mới | Cách tạo | Số lượng | Tổng | Đã thu | COD | Kết quả Kho |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| **POS-349307** | **Giao diện /ban-hang → POST /api/v1/orders → MySQL** | 100 | 101.522 | 0 | 101.522 | UI Kho đóng, actor 637, 14:38:52 +07 |
| **POS-349308** | API Local thực tế, trường hợp bổ sung | 10 | 10.000 | 0 | 0 | API Kho đóng, actor 637, 14:40:27 +07 |

Cả hai ở shop TEST 38, product Local 13574, khách TEST có sẵn; không có vận đơn/Pancake ID. POS-349307 tạo lúc 14:34:55, sau mốc Kho. Giá Backend 1.000/sp; UI/DB cùng tính `100 × 1.000 − 1.234 + 2.300 + 456 = 101.522`. POS-349308 dùng không COD: còn phải thu 10.000 nhưng không ghi đã thu.

| Kiểm thử | Kết quả thực tế |
| --- | --- |
| Sale TEST đăng nhập, chọn đúng shop, sản phẩm, khách, sửa số lượng/giảm giá/phí/phụ thu và thanh toán 0 | **PASS UI** |
| Biên nhận sau API trả về, giá Backend, snapshot/tiền/shop/creator đúng trong MySQL | **PASS** |
| Tồn ảo ban đầu 5 vẫn bán 100 và 10 | **PASS**, sau cả hai vẫn 5; số lượng nguồn vẫn 20 |
| Mỗi đơn xuất hiện đúng một lần Chờ, đúng shop | **PASS API**; 349307 kiểm tra trực tiếp UI |
| UI Kho mở chi tiết, xác nhận và hoàn tất 349307 | **PASS**, actor/time/audit đúng |
| Request tạo lại cùng key, hai lần gửi lại 349307 | **PASS**, HTTP 200 replay, cùng ID |
| Cùng key nhưng sửa phụ thu | **PASS**, HTTP 409, không tạo đơn |
| Giá cũ/sai, giảm vượt tiền hàng, phí âm | **PASS**, HTTP 422, không tạo đơn |
| Sale truy cập sản phẩm/khách hoặc tạo đơn shop 39 | **PASS**, HTTP 403 |
| Kho shop 39 đọc/đóng đơn shop 38 | **PASS**, HTTP 403 |
| Sale không có quyền Kho gọi complete | **PASS**, HTTP 403 |
| Gửi lại complete từ Kho 1/Kho 2 | **PASS**, replay giữ nguyên actor/time, mỗi đơn đúng một warehouse.packed |
| Đóng 349307 không đổi bất kỳ trường tiền/items/snapshot hoặc toàn bộ hàng product | **PASS đối chiếu trước/sau** |
| Tổng trạng thái cuối Local | Chờ **0**, Đã đóng **3** (gồm 349306 cũ giữ nguyên) |

## 4. Pancake Sync → Kho: mô phỏng pipeline thật trong TEST riêng

Không có đơn Pancake thực tế mới phù hợp sau kích hoạt. Không tạo đơn nguồn hoặc bơm payload giả vào Local chính.

Dùng MySQL **`pancake_warehouse_plan3_test_20261021`** sẵn có, không reset. Tạo fixture tối thiểu có prefix `plan4-20261022143746`, lưu log/monitor/cache vào storage riêng để không làm nhiễu giám sát Local chính. Endpoint tạm loopback **8002** chỉ mở `/api/webhook/pancake`, từ chối đường dẫn khác và guard database TEST. Endpoint đã dừng sau kiểm thử.

Đã gửi **7 HTTP POST** qua WebhookController hiện có và thêm một job bulk qua dispatcher hiện có. **8 job serialized** nằm trong MySQL jobs TEST, được worker database queue xử lý thực sự, không fake queue; không có failed job mới, queue TEST về 0. Laravel HTTP chặn request ngoài và xác nhận không gọi HTTP ngoài.

| Trường hợp | Kết quả |
| --- | --- |
| Bulk + webhook đơn mới hợp lệ, **không vận đơn** | PASS, tạo đúng hai đơn nguồn TEST ID **6, 7** |
| Trùng trong bulk, webhook lặp và bulk/webhook cùng nguồn | PASS, không tăng số đơn/yêu cầu Kho |
| source_created_at theo inserted_at gốc, first_synced_at riêng | PASS |
| Nguồn sinh trước mốc nhưng nhận muộn | PASS, không vào Kho |
| Thiếu ngày tạo hoặc ngày tương lai | PASS, không vào Kho |
| Hàng lịch sử tồn tại được sync lại với payload ngày mới | PASS, không enroll |
| Hoàn tất hai đơn TEST và gọi lặp | PASS, giữ tiền/snapshot, một audit mỗi đơn |

**Phân loại:** pipeline mô phỏng MySQL/HTTP/job/worker/Kho **PASS**; xác nhận bằng dữ liệu **Pancake thực tế: CHƯA THỂ KIỂM CHỨNG THỰC TẾ**. Không thay đổi webhook/job sản phẩm trong PLAN 4.

## 5. Test và build

- Backend: **76 PASS, 528 assertions**, gồm ProductTest, ProductPlanOneTest, PosOrderTest, WarehouseTest. Chạy với DB_CONNECTION=sqlite, DB_DATABASE=:memory:; các suite tự tạo connection SQLite riêng, không dùng MySQL chính.
- Bao gồm giá bán không nhầm vốn, tồn ảo 0/3/5/20, floor 5, không đổi tồn nguồn, giảm giá/phí/phụ thu, tiền lẻ, thanh toán một phần/đủ/không COD, chống trùng và rollback giao dịch khi audit thất bại.
- Test hồi quy mới: role tùy chỉnh chỉ đọc bị 403, được pos-create mới tạo được, thiếu một trong hai quyền đọc vẫn 403, sai shop 403; catalog chạy hai lần không đổi grants. Giữ test từ chối CSKH cũ.
- Frontend: **20/20 PASS**, gồm POS, products API và warehouse; thêm kiểm tra custom role phải đủ cả ba quyền và menu POS nhất quán.
- Frontend build: **PASS**. Lint các file JS/JSX sửa: **PASS**. `git diff --check` hai repo: **PASS**.

## 6. File thay đổi trong PLAN 4

Backend:
- `app/Http/Controllers/Api/OrderController.php`: nhận quyền pos-create tường minh cho role riêng, giữ hai middleware quyền đọc và scope shop.
- `database/migrations/2026_10_22_000001_add_explicit_pos_permission.php`: catalog quyền, không schema/grant tự động.
- `tests/Feature/PosOrderTest.php`: hồi quy quyền/khác shop/catalog idempotent.
- `docs/plan-4-pos-warehouse-integration.md`: báo cáo này.

Frontend:
- `src/auth/permissions.js`: cùng điều kiện POS với Backend.
- `src/pages/Pos/PosPage.jsx`: thông báo khi thiếu quyền phù hợp.
- `tests/pos.test.js`: test custom role/đủ quyền/menu.

Hai repo vẫn `viet_dev`, không commit/push; các thay đổi PLAN 1/2/3 chưa commit được giữ nguyên. Không sửa .env, migration Kho hoặc PHP/JS nghiệp vụ tiền/tồn/sync. Migration catalog PLAN 4 đã applied trên Local, không cần chạy lại tại đây.

## 7. Demo và giới hạn còn lại

1. Mở [POS Local](http://localhost:5173/ban-hang), đăng nhập Sale TEST 636 bằng tệp riêng tư. Shop 38 có sản phẩm/khách TEST sẵn.
2. Mở [Kho Local](http://localhost:5173/kho) bằng phiên Kho TEST 637. Tab Đã đóng có ba TEST orders; xem 349307 để thấy đơn tạo từ UI và một audit. Tab in-app hiện đăng nhập Kho TEST.
3. Nếu demo tạo tiếp, đánh dấu TEST/KHÔNG GIAO HÀNG/KHÔNG THU TIỀN, giữ thanh toán 0, chỉ dùng shop 38. Không dùng hoặc sửa 349306 và đơn thật, không nhấn đồng bộ Pancake cho các shop TEST không có ID/API key.
4. Chưa mở quyền POS/Kho cho tài khoản thật; chưa demo live Pancake hay giao hàng/thanh toán bên ngoài. Những phần đó không được suy diễn là đã sẵn sàng production.

Bằng chứng riêng tư: `/Users/viet/Pancake/.local-backups/plan4-integration-20261022-143034` (0700, các file 0600); gồm snapshot trước sửa, kết quả API/UI/SQL/checksum, log test/build, pipeline TEST và script kiểm chứng. Không chứa trong Git. Giữ backup trước kích hoạt; tuyệt đối không khôi phục snapshot đè Local chính vì sẽ mất giao dịch sau backup. Không có lỗi nghiêm trọng cần tắt Kho.

Ảnh kiểm chứng: `plan4-pos-created.png` và `plan4-pos-warehouse-packed.png` tại thư mục visualization của phiên.
