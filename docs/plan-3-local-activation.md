# PLAN 3 — Kho đã kích hoạt trên Local chính

Cập nhật: **2026-10-22T14:02:55.626070+07:00**. Backend/Frontend đều ở branch `viet_dev`.

## Kết quả cuối: MIGRATION PASS — MODULE ON

Module Kho đang **ON** trên MySQL Local `pancake_order`. Mốc kích hoạt thực tế: **2026-10-22T13:54:15+07:00**. Worker và API đã xác minh cùng cấu hình/source trước mốc; API ON được quan sát lúc 13:54:42, hàng đợi ban đầu rỗng. Không chạy production/MISA/Viettel Post, không backfill, không xóa/reset hoặc khôi phục đè database, không commit/push.

Người dùng đã cho phép tự tạo tài khoản TEST trong phản hồi tiếp theo, giải quyết blocker tài khoản của báo cáo trước. Không dùng hoặc sửa quyền các tài khoản thật.

## 1. Migration và an toàn dữ liệu

- Đã kiểm tra đúng `127.0.0.1:3306`, schema `pancake_order`, environment local, datadir Homebrew và UUID khớp backup.
- Backup trước migration: `/Users/viet/Pancake/.local-backups/warehouse-before-activation-20261022-093450`, **44/44 checksum PASS**, đã restore kiểm chứng. Trước migration 37 bảng không drift so với backup.
- Chỉ migration `2026_10_21_000002_add_warehouse_completion_and_source_time.php` được chạy, PASS khoảng 0,607 giây; không chạy lại sau khi đã applied.
- 5 cột nullable, index packed và hai mục quyền đúng. Sau migration toàn bộ 5 cột mới của 90.577 đơn cũ null, queued_count=0; không có role grant tự động.
- Đã kiểm tra lại dữ liệu cũ trước bật và sau toàn bộ thử nghiệm bằng checksum toàn bộ giá trị cột cũ của 37 bảng; loại riêng các bản ghi TEST/token mới và catalog/migration đã được phép thêm: **tất cả dữ liệu cũ khớp**.

| Chỉ số | Trước | Sau |
| --- | --- | --- |
| Đơn lịch sử | 90.577 | **90.577**, toàn bộ dữ liệu giữ nguyên |
| Đơn TEST mới | 0 | **1**, POS-349306 |
| Tổng orders | 90.577 | **90.578** |
| Đơn lịch sử có warehouse_queued_at | 0 | **0** |
| Chờ đóng hàng TEST | 0 | **0** sau hoàn tất |
| Đã đóng hàng TEST | 0 | **1** |
| POS 349305 | waiting_packing, queued_at null | toàn bộ hàng giữ nguyên |
| COD/cash mẫu 349305 | 6.320.000 / 200.000 | giữ nguyên |
| Role/quyền/tài khoản/shop cũ | baseline backup | checksum giữ nguyên |

## 2. Tài khoản và shop TEST được tạo

| Tài khoản | ID | Role / shop | Quyền sử dụng |
| --- | --- | --- | --- |
| sale.local-plan3@pancake.test | 636 | staff-sale hiện có, shop 38 | giữ nguyên role/quyền chung, dùng làm actor của fixture POS |
| kho1.local-plan3@pancake.test | 637 | role TEST Kho mới 17, shop 38 | warehouse-view + warehouse-pack |
| kho2.local-plan3@pancake.test | 638 | role TEST Kho mới 17, shop 38 | warehouse-view + warehouse-pack; kiểm tra replay |
| kho-outside.local-plan3@pancake.test | 639 | role TEST Kho mới 17, shop 39 | hai quyền Kho; kiểm tra từ chối khác shop |

Role mới: `warehouse-local-test-20261022`, không gán tài khoản cũ. Có đúng hai role_permissions mới thuộc role TEST này, không mở quyền Kho cho role thật. Sale role 14 vẫn giữ tập quyền ban đầu.

Shop 38/39 tên `TEST Local PLAN 3 - Shop A/B - KHONG GIAO HANG`, không có mã shop/API key Pancake (chuỗi rỗng theo schema hiện tại yêu cầu NOT NULL). Không tạo ID upstream giả hoặc yêu cầu sync cho chúng. Sản phẩm TEST ID 13574 ở shop 38 có pancake_product_id=null, tên rõ TEST/KHÔNG GIAO HÀNG, giá bán giả lập 1.000, số lượng nguồn TEST 20. Các bản ghi TEST được giữ lại, không xóa sau kiểm tra.

Thông tin đăng nhập (chỉ tài khoản TEST mới, mật khẩu không ghi vào báo cáo hoặc Git): [TEST-LOCAL-LOGIN-PRIVATE.txt](/Users/viet/Pancake/.local-backups/warehouse-activation-20261022-115411/TEST-LOCAL-LOGIN-PRIVATE.txt). Tệp nằm trong thư mục 0700, quyền file 0600.

## 3. Cấu hình và worker

- `.env` Backend chỉ bổ sung WAREHOUSE_ACTIVATED_AT với mốc **2026-10-22T13:54:15+07:00**; bản cũ lưu riêng tư. Đã clear config cache; API Laravel serve nạp lại `.env`. Không đổi các giá trị khác.
- App Asia/Ho_Chi_Minh, MySQL system +07; chênh đồng hồ PHP/MySQL lúc chuẩn bị 0 giây.
- API Local cổng 8000 và Frontend 5173 chạy đúng source. Vite đang phục vụ API_URL `http://127.0.0.1:8000/api/v1`; không chuyển sang TEST 8001.
- Trước bật không có worker/scheduler Pancake cũ. Đã tạo LaunchAgent Local riêng `vn.pancake.local.warehouse-worker` và bootstrap trước T; trạng thái running, PID kiểm tra 7354, stderr rỗng.
- LaunchAgent: `/Users/viet/Library/LaunchAgents/vn.pancake.local.warehouse-worker.plist`. Launcher có guard chỉ cho app local, host loopback, database/datadir/UUID Local đã xác minh, queue driver database; từ chối môi trường khác.
- Launcher: `/Users/viet/Pancake/.local-backups/warehouse-activation-20261022-115411/local-order-worker.php`. Chỉ tiêu thụ **get-order-webhook, get-order, get-order-chunk**, sleep 1, tries 3, timeout 120. Có KeepAlive/ExitTimeOut để quản lý process; không có worker trùng.
- Đã đối chiếu hash WarehouseService, OrderService và hai job sync với worker runtime; cấu hình worker và HTTP cùng mốc trước T. Lúc còn 101 giây trước T, HTTP enabled=false, queue=0, launchd running và wrong-shop=403.
- Scheduler CSKH không chạy, AUTO_RECLAIM_CUSTOMER_CARE_ENABLED=false. Không khởi động scheduler hoặc đồng bộ các nghiệp vụ ngoài Kho. Bốn job customer/product/employee chờ từ trước không bị xóa/reset/xử lý.
- Frontend TEST 5174/API 8001 giữ tách riêng, không dùng để tuyên bố kết quả Local chính.

Nếu cần tạm dừng Kho về sau: ghi lại mốc T gốc, đặt WAREHOUSE_ACTIVATED_AT trống, nạp lại cấu hình/API và cho worker thoát graceful để đọc config mới. Có thể dừng riêng LaunchAgent bằng `launchctl bootout gui/$(id -u)/vn.pancake.local.warehouse-worker`; không xóa jobs/audit hoặc tự rollback DB. Kiểm tra HTTP báo OFF trước khi coi cơ chế nhận đơn mới vào Kho đã tắt. Không đổi T hoặc tự backfill khi mở lại; cần đối soát khoảng gián đoạn.

## 4. Kiểm thử thực tế sau bật

| Kiểm thử | Kết quả |
| --- | --- |
| Đăng nhập 4 tài khoản TEST qua API Local; shops summary đúng scope | PASS |
| API Kho OFF trước T, ON sau T và queue ban đầu 0 | PASS |
| UI Kho Local qua trình duyệt riêng, không đổi phiên Chrome tài khoản thật | PASS |
| Đơn POS mới sau T vào Chờ đúng một lần, đúng shop | PASS, POS-349306, shop 38, created/queued 13:54:42 |
| Tạo lặp cùng idempotency qua service | PASS, vẫn một ID/order |
| Chi tiết khách/sản phẩm/SL/ghi chú, xác nhận trước đóng | PASS trên UI Local |
| Hoàn tất trên UI bằng TEST Kho 1 | PASS, actor 637, packed_at **13:55:47 +07:00** |
| Hai request lặp bởi Kho 1 và Kho 2 | PASS, replayed=true, giữ actor/time ban đầu |
| Audit hoàn tất | PASS, đúng **1** warehouse.packed |
| Chờ 0, Đã đóng 1 sau thao tác | PASS API/UI |
| Kho shop B gọi list/detail/complete đơn TEST shop A | PASS, HTTP **403** |
| Sale không có quyền Kho gọi complete | PASS, HTTP **403** |
| Toàn bộ giá trị tiền/COD/quantity/items giữ nguyên khi đóng | PASS, chỉ đổi trạng thái/actor/time/update time Kho |
| Tồn không trừ lần hai | PASS, toàn bộ hàng product trước/sau đóng giống nhau |
| Đơn lịch sử/349305 không bị enroll hoặc sửa | PASS, checksum 37 tập dữ liệu cũ và SQL queued history=0 |
| Worker sau kiểm thử | PASS, running, config/hash đúng, không lỗi stderr |

Đơn TEST có 100 sản phẩm × giá giả lập 1.000, cash=0, COD=0, expected_cod=0, payments rỗng và ghi chú KHÔNG GIAO HÀNG/KHÔNG THU TIỀN. Không có vận đơn. Counter tồn ảo của riêng sản phẩm TEST từ 20 xuống 5 khi tạo đơn 100; số lượng nguồn vẫn 20. Đóng hàng không sửa cả hai giá trị này.

**Giới hạn cần phân biệt:** đơn được tạo bằng CLI qua `OrderService::createLocalOrder` đang dùng chung cho POS, với actor Sale TEST và shop/sản phẩm TEST. Không gọi API POST /orders hoặc thao tác UI bán hàng trong lượt này. Các role Sale đang dùng chung thiếu quyền list-product; không sửa quyền của 131/2 tài khoản thật để phục vụ thử nghiệm. Vì vậy UI/API tạo đơn POS chưa được kiểm chứng lại trên Local với quyền đầy đủ; API/UI Kho đã được kiểm chứng trực tiếp, không giả lập phản hồi.

Không có đơn Pancake live mới hợp lệ để kiểm chứng sau mốc T; không tạo đơn giả trên Pancake gốc hoặc bơm payload giả vào database chính. Luồng đó đã được kiểm thử ở môi trường TEST trong PLAN 3 trước; chưa tuyên bố đã kiểm chứng live Local.

## 5. Cách mở kiểm tra

1. Mở [http://localhost:5173/kho](http://localhost:5173/kho) bằng phiên riêng hoặc dùng tab in-app đã đăng nhập TEST.
2. Dùng `kho1.local-plan3@pancake.test` với mật khẩu trong tệp riêng tư phía trên. Tài khoản thật chưa được cấp quyền Kho sẽ không tự thấy menu.
3. Tab Chờ hiện rỗng; tab Đã đóng có POS-349306. Mở chi tiết để thấy người tạo TEST, Kho 1, thời gian và một audit.
4. Không dùng 349305 hoặc sản phẩm/khách/đơn thật để tiếp tục thử. Không nhấn đồng bộ Pancake cho các shop TEST không có mã/API key.

## 6. Git và file thay đổi

Không commit/push, hai repo vẫn `viet_dev`; giữ nguyên các thay đổi PLAN 1/2/3 chưa commit. Không sửa PHP/JS chức năng hoặc source migration trong lượt bật này.

- Cấu hình runtime sửa: `/Users/viet/Pancake/pancake_order/.env` (chỉ mốc Kho, ignored bởi Git).
- Báo cáo sửa: `docs/plan-3-local-activation.md`, `docs/plan-3-activation-readiness.md`, `docs/plan-3-warehouse-implementation.md` trong Backend.
- Operational mới ngoài repo: LaunchAgent và launcher guard Local nêu trên; các script/bằng chứng/credentials TEST lưu riêng tư tại `/Users/viet/Pancake/.local-backups/warehouse-activation-20261022-115411`.
- Frontend không sửa file. Chỉ dùng source hiện có và kiểm tra trình duyệt trên Local.

Các rủi ro còn lại: backup trước migration vẫn cùng máy/ổ, không chứa các fixture và audit mới; khôi phục snapshot cũ cần đối soát giao dịch sau backup, không ghi đè DB chính. Giữ thư mục chứa launcher khi worker đang vận hành; nếu di chuyển cần cập nhật LaunchAgent đúng quy trình. Không triển khai thêm quyền POS cho người thật hoặc nghiệp vụ hủy sau đóng/MISA/Viettel Post trong lượt này.
