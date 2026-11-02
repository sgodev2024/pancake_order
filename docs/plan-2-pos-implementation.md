# PLAN 2 — Bán hàng POS Local

Ngày: 21/10/2026. Hai repository ở branch `viet_dev`. Giữ nguyên các thay đổi PLAN 1 chưa commit. Không commit/push, không xóa/reset dữ liệu MySQL hoặc đơn lịch sử, không thay đổi role/permission/phân công shop.

## 1. Chức năng đã triển khai

### POS và đơn hàng

- Màn hình `/ban-hang`, menu **Bán hàng POS**, nút truy cập từ danh sách đơn; bố cục sản phẩm bên trái, thông tin đơn/khách/giỏ/thanh toán bên phải, có breakpoint cho màn hình nhỏ.
- Chọn đúng shop được phân công; tìm sản phẩm theo tên, ID Pancake, `display_id` hoặc barcode. Tái sử dụng API sản phẩm, `ShopSelect`, giá bán chuẩn hóa và helper ảnh từ PLAN 1.
- Giỏ cho phép sản phẩm Local và Pancake trong cùng shop, tăng/giảm/nhập số lượng, xóa dòng. Không giới hạn theo tồn hiển thị. Có giới hạn kỹ thuật 1.000.000 đơn vị/dòng, 200 dòng/đơn để bảo vệ kiểu số và request; đây không phải điều kiện tồn kho.
- Tìm/chọn khách có sẵn trong đúng shop và phạm vi bản ghi hiện có. Khách Local mới được lưu cùng transaction tạo đơn, ID Pancake là null. Nếu số điện thoại đã có trên một khách Local mà người dùng được phép truy cập trong shop thì tái sử dụng khách đó.
- Nhập địa chỉ giao hàng, giảm giá số tiền, phí vận chuyển thu khách, phụ thu, tiền đã trả, hình thức tiền mặt/chuyển khoản, chế độ thu hộ phần còn lại/không thu hộ và ghi chú.
- Đơn được lưu tại bảng `orders`, `origin = local_pos`, mã hiển thị nội bộ `POS-{orders.id}`. Không giả ID Pancake hoặc mã vận đơn; không gửi sang Pancake, hãng vận chuyển, ZNS hoặc MISA.
- Lưu snapshot sản phẩm, đơn giá, thành tiền, khách hàng, địa chỉ, các khoản tiền, khoản thanh toán hợp lệ và thông tin biến động tồn ảo trong JSON đơn hiện có. Giá/sản phẩm thay đổi sau đó không làm thay đổi đơn đã tạo.
- Dùng `created_by_user_id` theo ID user Local và `user_creator_id` theo cơ chế cũ để ghi người đầu tiên tạo đơn. Không bổ sung API sửa người tạo hoặc chỉnh tiền đơn đã lưu.
- Có API chi tiết `GET /api/v1/orders/{id}` và URL mở lại đơn POS; danh sách đơn hiển thị mã/nguồn Local, tổng tiền đúng cả khi không thu COD, tìm được mã `POS-...`.

### Tiền và chống trùng

- Tiền hàng = tổng số lượng × đơn giá; tổng thanh toán = tiền hàng − giảm giá + vận chuyển + phụ thu.
- Còn phải thu = tổng − tiền đã trả hợp lệ. COD dự kiến mặc định bằng phần còn lại, hoặc 0 khi không chọn thu hộ. `cash` ghi tiền đã nhận, `cod` là khoản dự kiến; hai khái niệm không bị đánh đồng.
- Backend tính bằng số nguyên đơn vị 1/100 đồng, sau đó lưu dạng decimal/chuỗi thập phân hai chữ số; không cộng tiền bằng float. Giá lấy từ sản phẩm ở server. Giá snapshot client gửi lên chỉ dùng để phát hiện giá đã thay đổi, không được tin làm giá bán.
- Từ chối số âm, trên hai chữ số thập phân, giảm giá vượt tiền hàng, trả vượt tổng, giỏ rỗng, sản phẩm trùng dòng hoặc ngoài shop, số lượng không nguyên/dương, tổng vượt giới hạn.
- Một UUID idempotency cho mỗi yêu cầu; hash payload phát hiện việc dùng lại khóa với dữ liệu khác (409). Khóa unique theo người tạo/UUID, khóa hàng user và sản phẩm, transaction có retry khi deadlock. Retry hợp lệ trả cùng đơn và không ghi thêm khách, thanh toán, event hoặc trừ tồn lần nữa.
- Frontend khóa giỏ khi đang gửi; nếu mất kết quả/ lỗi chưa xác định thì giữ nguyên payload và khóa để gửi lại chính yêu cầu đó. Sau lỗi validation/phân quyền có thể chỉnh lại dữ liệu.

### Tồn ảo

- `products.virtual_stock` là bộ đếm Local độc lập, nullable trước lần bán POS đầu tiên. Khi chưa bán, giá trị hiển thị lấy từ dữ liệu nguồn như PLAN 1 với sàn 5.
- Trong transaction bán: `sau = max(5, trước - số bán)`. Không kiểm tra “đủ tồn” làm điều kiện tạo đơn.
- Chỉ ghi cột `virtual_stock`; không ghi tăng/giảm `remain_quantity`, tồn từng kho hoặc raw payload Pancake. Snapshot dòng đơn lưu `virtual_stock_before`/`virtual_stock_after` để truy vết.
- Job đồng bộ sản phẩm giữ nguyên; job không cập nhật cột Local này nên không reset bộ đếm hoặc trừ lại lượng đã bán.

### Chuẩn bị Kho

- Đơn POS mới có trạng thái xử lý riêng `warehouse_status = waiting_packing` (**Chờ đóng hàng**), không ghi đè trạng thái vận chuyển/thanh toán hiện có.
- `warehouse_queued_at` chỉ được ghi nếu thời điểm tạo đơn ở sau mốc cấu hình `WAREHOUSE_ACTIVATED_AT`.
- Mặc định mốc kích hoạt chưa được đặt, nên chưa tự đưa đơn vào hàng đợi Kho. Không backfill đơn lịch sử. Không triển khai giao diện Kho hoặc thay đổi job đồng bộ đơn; việc tiếp nhận đơn Pancake đủ điều kiện thuộc PLAN 3.

### Phân quyền

- Tạo POS dành cho `admin`, `staff-sale`, `manager-sale`, đồng thời dùng hai quyền có sẵn `list-product` và `list-customer`. Không tự cấp quyền cho bất kỳ tài khoản nào.
- Backend kiểm tra shop sản phẩm/khách/đơn; khách cũ còn tuân thủ `CustomerReadAccessService` (nhân viên chỉ dùng khách đã được phân công theo cơ chế cũ).
- Đọc chi tiết đơn tuân thủ shop và hạn chế bản ghi tương đương danh sách đơn hiện có. Backend chặn dữ liệu giả mạo dù gọi API trực tiếp.

## 2. File tạo/sửa trong PLAN 2

Backend `/Users/viet/Pancake/pancake_order`:

- `app/Http/Controllers/Api/OrderController.php`: store/show POS, tổng và mã Local trong danh sách.
- `app/Http/Controllers/Api/CustomerController.php`: chế độ danh sách chọn khách POS, kiểm tra quyền.
- `app/Http/Controllers/Api/ProductController.php`: tìm kiếm mã/barcode theo đúng shop; giữ CRUD PLAN 1.
- `app/Models/Order.php`: các trường Local, quan hệ customer, ẩn hash/khóa request khỏi API.
- `app/Models/Product.php`: accessor tồn ảo dùng bộ đếm Local nếu đã phát sinh bán.
- `app/Services/OrderService.php`: thêm luồng tạo Local, không thay đổi hàm chuyển đổi đơn Pancake hiện có.
- `app/Services/OrderCreatedEventWriter.php`: liên kết event với khách Local qua ID Local.
- `config/warehouse.php`: mốc kích hoạt hàng đợi, mặc định tắt.
- `database/migrations/2026_10_21_000001_add_local_pos_support.php`: schema bổ sung an toàn.
- `tests/Feature/PosOrderTest.php`: kiểm thử POS và các ảnh hưởng liên quan.
- `docs/plan-2-pos-implementation.md`: báo cáo này.

Frontend `/Users/viet/Pancake/pancake_frontend_v2`:

- `src/pages/Pos/PosPage.jsx`: màn hình POS và chi tiết đơn.
- `src/pages/Pos/PosOrderReceipt.jsx`: hiển thị snapshot/tiền đơn đã lưu.
- `src/pages/Pos/posTotals.js`: tính tiền dự kiến bằng đơn vị nhỏ nhất.
- `src/pages/Pos/pos.css`: bố cục responsive.
- `src/api/orders.api.js`: create/detail dùng resource orders hiện có.
- `src/app/router.jsx`: route `/ban-hang`.
- `src/auth/permissions.js`: kiểm tra quyền POS, lọc menu.
- `src/constants/menu.js`: menu POS.
- `src/constants/routes.js`: đường dẫn POS.
- `src/pages/Orders/OrdersPage.jsx`: nút POS, mã/nguồn/tổng Local và liên kết chi tiết.
- `src/pages/Products/ProductsPage.jsx`: tái sử dụng helper ảnh được tách ra.
- `src/utils/products.js`: di chuyển helper tên/ảnh hiện có để Products/POS dùng chung.
- `tests/pos.test.js`: tiền, quyền, API và retry.

Các file PLAN 1 khác vẫn được giữ nguyên. Không tạo bảng chi tiết/thanh toán trùng chức năng: PLAN 2 dùng snapshot JSON đã tồn tại trên đơn, chưa có nghiệp vụ điều chỉnh/thu thêm tiền sau khi tạo.

## 3. Migration/schema và dữ liệu cũ

**Đã chạy trên MySQL Local** đúng migration `2026_10_21_000001_add_local_pos_support.php`, sau khi kiểm tra schema thật và chạy `--pretend`.

- `products`: thêm `virtual_stock BIGINT UNSIGNED NULL`.
- `orders`: thêm `customer_id`, `created_by_user_id`, `origin`, `idempotency_key`, `request_hash`, `warehouse_status`, `warehouse_queued_at`; index khách, unique người tạo/khóa request và index hàng đợi Kho theo shop.
- `orders.pancake_order_id` và `customers.pancake_customer_id`: cho phép null để không phải giả ID upstream cho dữ liệu Local.
- `orders.cod`/`cash`: nới từ integer sang `DECIMAL(18,2)` để không làm mất phần thập phân của giá sản phẩm. Không thay đổi giá trị các khoản cũ.
- Không thêm bảng mới, không backfill trạng thái hoặc dữ liệu lịch sử. Các trường mới đều nullable trên bản ghi cũ.
- Migration chủ động không hỗ trợ rollback phá hủy dữ liệu POS; không chạy rollback để gỡ các dữ liệu đã phát sinh.

Đối chiếu ngay trước/sau migration, trước khi tạo đơn mẫu:

| Bảng | Trước | Sau |
| --- | ---: | ---: |
| orders | 90.576 | 90.576 |
| customers | 66.180 | 66.180 |
| products | 6.142 | 6.142 |

Checksum gộp ID/shop/ID Pancake/tiền/tracking/status/raw payload/timestamp của đơn cũ: **194512601819690**, trùng khớp trước/sau. Sau đó chỉ bổ sung một đơn và một khách mẫu nêu bên dưới.

Khi áp dụng ở môi trường khác, dùng PHP >=8.2 và chỉ chạy migration đã review:

```sh
cd /Users/viet/Pancake/pancake_order
/opt/homebrew/opt/php@8.4/bin/php artisan migrate --path=database/migrations/2026_10_21_000001_add_local_pos_support.php
```

## 4. Kết quả kiểm thử thực tế

| Hạng mục | Kết quả |
| --- | --- |
| Backend POS + PLAN 1 + lịch sử đơn + event/journey + quyền/shop | PASS — 145 tests, 980 assertions |
| Frontend POS + API sản phẩm + shop options | PASS — 14 tests |
| Toàn bộ test Frontend | 73 tests: 66 PASS, 7 FAIL có sẵn từ trước PLAN 2 |
| Frontend build | PASS |
| Frontend lint | PASS exit 0, 17 warning cũ ngoài logic POS |
| Backend Pint, Git diff whitespace | PASS |
| Migration MySQL và đối chiếu dữ liệu cũ | PASS |
| Trình duyệt tạo đơn, mở lại chi tiết từ MySQL | PASS |
| Hai request replay đồng thời trên MySQL | PASS — cùng HTTP 200/replayed, cùng một ID đơn |
| Đối chiếu tồn vật lý/raw sản phẩm trước/sau đơn mẫu | PASS |

Các trường hợp Backend gồm: sản phẩm Local, Pancake và giỏ trộn; tạo/reuse khách Local; khách cũ; thanh toán đủ/một phần/không thu COD; decimal chính xác; phí/giảm giá/phụ thu; không vận đơn; tồn 5 bán 10/100; đồng bộ không reset tồn; snapshot giá; idempotency và conflict; chặn shop/khách/sản phẩm/giá giả; transaction rollback khi event lỗi; mốc kích hoạt Kho; tổng tiền Local trong danh sách khi không COD; tìm mã POS.

Bảy lỗi Frontend rộng vẫn là các test cũ đã đối chiếu với HEAD ở PLAN 1: `customerCareActionParity`, `customerCareOrderSource`, `orderColumnsRender`, `orderFilters`, hai test `orderPagesApi`, một test `orderSourcesApi`. Không sửa nghiệp vụ hoặc test ngoài phạm vi để làm xanh giả bộ test.

Lệnh chạy lại:

```sh
cd /Users/viet/Pancake/pancake_order
DB_CONNECTION=sqlite DB_DATABASE=:memory: /opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit tests/Feature/PosOrderTest.php tests/Feature/ProductPlanOneTest.php tests/Unit/ProductTest.php tests/Feature/OrderHistoryApiTest.php tests/Feature/OrderJourneyEventTest.php tests/Feature/CustomerEnteredSystemEventTest.php tests/Feature/DirectReadIdorHardeningTest.php tests/Feature/PrivilegeHardeningAndShopAccessTest.php

cd /Users/viet/Pancake/pancake_frontend_v2
node --test --test-force-exit tests/pos.test.js tests/productsApi.test.js tests/shopOptions.test.js
npm run build
npm run lint
```

Các test tự động ghi dữ liệu dùng SQLite in-memory, không reset MySQL. Chỉ đơn smoke test dưới đây được lưu có chủ đích trên MySQL Local và được giữ lại.

## 5. Chưa triển khai

- Không triển khai PLAN 3: màn hình đóng hàng/xuất kho, tự tiếp nhận đơn Pancake vào Kho, luồng chuyển trạng thái tiếp theo.
- Không gửi đơn sang Pancake, không tích hợp MISA/Viettel Post, không tạo mã vận đơn.
- Chưa có nghiệp vụ thu thêm/hoàn/hủy thanh toán sau khi tạo hoặc hủy đơn/hoàn tồn; không tạo API thay đổi sổ thanh toán khi chưa có nghiệp vụ được chốt.
- Bảy test Frontend có sẵn còn lỗi; bốn lỗi Backend ngoài các suite liên quan đã ghi nhận ở PLAN 1 không thuộc phạm vi POS.
- Chưa bật mốc `WAREHOUSE_ACTIVATED_AT`; đây là điểm nối cho PLAN 3, không tự đưa đơn cũ vào hàng đợi.

Không còn blocker cho luồng tạo đơn POS trong phạm vi PLAN 2.

## 6. Đường dẫn Local

- POS: http://localhost:5173/ban-hang
- Đơn đã kiểm thử: http://localhost:5173/ban-hang?order_id=349305
- Danh sách đơn: http://localhost:5173/don-hang — tìm `POS-349305`.

Backend `127.0.0.1:8000`, Frontend `localhost:5173` đã chạy khi kiểm thử. Nếu cần khởi động lại:

```sh
cd /Users/viet/Pancake/pancake_order
/opt/homebrew/opt/php@8.4/bin/php artisan serve --host=127.0.0.1 --port=8000

# Terminal khác
cd /Users/viet/Pancake/pancake_frontend_v2
npm run dev
```

## 7. Đơn mẫu đã tạo và hướng dẫn kiểm tra

**Đã giữ lại đơn `POS-349305` (orders.id = 349305), shop CANXI MY**, tạo bằng phiên Admin đang đăng nhập trên trình duyệt Local. Khách có tên “Khách kiểm thử PLAN2”; ghi chú nêu rõ đây là dữ liệu kiểm thử, không phải tiền thực thu và không giao hàng. Không có ID Pancake hay vận đơn.

| Khoản | Giá trị |
| --- | ---: |
| Táo sấy dẻo: 100 × 65.000đ | 6.500.000đ |
| Giảm giá | 20.000đ |
| Phí vận chuyển | 30.000đ |
| Phụ thu | 10.000đ |
| Tổng thanh toán | 6.520.000đ |
| Tiền đã trả — dữ liệu kiểm thử | 200.000đ |
| Còn phải thu / COD dự kiến | 6.320.000đ |

Đã gửi lại đồng thời đúng UUID/payload của đơn này hai lần qua API trên MySQL: cả hai trả `200`, `replayed=true`, ID 349305. Database chỉ có một đơn theo khóa đó. Sản phẩm ID 6144: tồn nguồn **0 trước và sau**, raw payload không đổi, tồn ảo sau bán/retry vẫn **5**. `warehouse_status=waiting_packing`, `warehouse_queued_at=null` vì module Kho chưa kích hoạt.

Để tạo đơn khác: mở POS → chọn shop được phân công → chọn sản phẩm → chọn khách cũ hoặc nhập khách mới và địa chỉ → nhập số lượng/phí/giảm giá/phụ thu/tiền đã trả → chọn COD → **Tạo đơn hàng**. Đơn đã lưu hiển thị số liệu từ Backend. Khi kiểm tra tồn, đối chiếu “Tồn ảo” với số lượng gốc ở form sản phẩm; hai số phục vụ mục đích khác nhau.

Ảnh xác minh: `/Users/viet/.codex/visualizations/2026/10/21/01a15d5d-90d2-7a30-9354-e734bd2885d0/pancake-plan2-order.png`.
