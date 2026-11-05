# Kiểm tra phân quyền POS Local — 04/11/2026

Kiểm tra trực tiếp ngày 04/11/2026, Asia/Ho_Chi_Minh. Repo `/Users/viet/Pancake/pancake_order`, branch `viet_dev`. Frontend đang chạy từ `pancake_order/frontend` trên `http://localhost:5173`; API thật `http://127.0.0.1:8002/api/v1`; MySQL `127.0.0.1:3306/pancake_order`, APP_ENV=local. Mốc Kho giữ nguyên `2026-10-22T13:54:15+07:00`.

## Kết quả thực tế

| Vai trò / tài khoản | Hiện menu POS | Truy cập POS | API tạo đơn | ID đơn TEST | Kết quả |
| --- | --- | --- | --- | --- | --- |
| Sale TEST 636, role 18 | Có, nút trên Header | Có | Cho phép, đã tạo qua UI thật | POS-349316 | Lưu MySQL và tự vào Kho chờ đóng |
| CSKH TEST 641, role 13 `staff-cskh` | Không | Bị chặn bằng màn hình 403 | HTTP 403 | Không có | Không phát sinh đơn |
| Admin Local 1, role 1 `admin` | Có, nút trên Header | Có | Cho phép, đã tạo qua UI thật | POS-349317 | Lưu MySQL và tự vào Kho chờ đóng |

**Sale TEST 636 đang dùng role tùy chỉnh `sales-pos-local-test-20261022`, không phải role `staff-sale`.** Tài khoản này đã có ba quyền từ trước lượt kiểm tra. Role Sale thông thường hiện chỉ có `list-customer`, thiếu `list-product`, nên chưa dùng được POS theo code + DB hiện tại. Không dùng tài khoản nhân viên thật để đăng nhập thử và không suy diễn kết quả TEST 636 thành kết quả của 131 nhân viên Sale thật.

CSKH TEST chưa tồn tại tại thời điểm bắt đầu. Đã tạo duy nhất ID **641**, `cskh.pos-role-20261104@pancake.test`, gán role có sẵn **13** `staff-cskh` và shop TEST **38**. Không tạo role, không cấp quyền mới, không sửa role/permission/tài khoản hiện có. Thông tin đăng nhập chỉ lưu trong thư mục bằng chứng riêng tư, không đưa vào Git hay báo cáo.

## 1. Điều kiện trên Frontend

- Điểm vào POS thực tế là nút **Bán hàng POS** ở Header và trong trang Đơn hàng; không có một dòng POS riêng trong sidebar hiện tại. Nhãn tiêu đề “Bán hàng POS” vẫn hiện khi mở URL bị chặn, không đồng nghĩa có quyền sử dụng.
- Header và nút trên trang Đơn hàng dùng `canUsePos(user)`.
- `canUsePos` yêu cầu `(role là admin/staff-sale/manager-sale HOẶC có pos-create) VÀ có list-product VÀ có list-customer`.
- Riêng `hasPermission` trên Frontend tự trả true cho Admin, nên Admin luôn qua hai kiểm tra đọc ở Frontend. Đây là khác biệt với Backend, xem phần vấn đề cần sửa.
- Route `/ban-hang` nằm sau `ProtectedRoute`: cần đăng nhập, không trong luồng đổi mật khẩu lần đầu. Route không có middleware permission riêng; `PosPage` tự kiểm tra `canUsePos` và render màn hình từ chối nếu thiếu quyền. Đây là màn hình 403 trong ứng dụng, không phải chứng cứ HTTP 403 của SPA URL.
- `/ban-hang?order_id=...` là nhánh đọc chi tiết, chạy trước kiểm tra `canUsePos`; Backend `GET /orders/{id}` vẫn kiểm tra shop và người tạo/người chăm sóc. Không coi nhánh xem đơn là quyền tạo POS.

Nguồn: [permissions.js](/Users/viet/Pancake/pancake_order/frontend/src/auth/permissions.js:18), [Header.jsx](/Users/viet/Pancake/pancake_order/frontend/src/layouts/Header.jsx:51), [OrdersPage.jsx](/Users/viet/Pancake/pancake_order/frontend/src/pages/Orders/OrdersPage.jsx:351), [router.jsx](/Users/viet/Pancake/pancake_order/frontend/src/app/router.jsx:125), [PosPage.jsx](/Users/viet/Pancake/pancake_order/frontend/src/pages/Pos/PosPage.jsx:149).

## 2. Điều kiện trên Backend

Tất cả API nghiệp vụ dưới đây cần token Passport đăng nhập thật (`auth:api`). Không dùng HTTP Mock, không giả actor bằng CLI để tạo đơn, không chèn orders bằng SQL/service.

| API | Quyền và giới hạn |
| --- | --- |
| `GET /products?shop_id=...` | `list-product` bằng middleware strict; shop phải thuộc phạm vi |
| `GET /customers?view=pos&shop_id=...` | `list-customer` strict; shop thuộc phạm vi; nhân viên thường chỉ thấy khách được gán cho mình |
| `POST /orders` | Cả `list-product` và `list-customer` strict; sau đó role admin/staff-sale/manager-sale **hoặc** có `pos-create`; shop, sản phẩm, khách và giá được kiểm tra lại |
| `GET /shops?view=summary` | Chỉ shop membership hiện tại cho nhân viên; Admin/Director thấy phạm vi global, không cần `list-shop` để lấy options |
| `GET /warehouse/orders` và chi tiết | `warehouse-view` và phạm vi shop; không tự cấp quyền Kho cho Admin/Sale |

**Admin không cần `pos-create` để tạo POS theo nhánh đặc biệt hiện có. Tuy nhiên Admin vẫn cần bản ghi grant `list-product` và `list-customer` ở Backend.** `can_access` đọc `permissions` + `role_permissions`, không có miễn trừ Admin. Admin Local 1 hiện có cả hai quyền nên tạo được đơn, dù không có `pos-create`.

Nguồn: [OrderController.php](/Users/viet/Pancake/pancake_order/app/Http/Controllers/Api/OrderController.php:24), [nhánh tạo POS](/Users/viet/Pancake/pancake_order/app/Http/Controllers/Api/OrderController.php:356), [can_access](/Users/viet/Pancake/pancake_order/app/Helpers/function.php:37), [PermissionCheckMiddleware.php](/Users/viet/Pancake/pancake_order/app/Http/Middleware/PermissionCheckMiddleware.php:17), [ProductController.php](/Users/viet/Pancake/pancake_order/app/Http/Controllers/Api/ProductController.php:23), [CustomerController.php](/Users/viet/Pancake/pancake_order/app/Http/Controllers/Api/CustomerController.php:147).

## 3. Permission đang có trong MySQL

Snapshot trước lượt kiểm tra; toàn bộ `roles`, `permissions`, `role_permissions` giữ nguyên sau kiểm tra.

| Role ID | Slug | Toàn bộ permission hiện có |
| --- | --- | --- |
| 1 | `admin` | `create-shop`, `list-shop`, `role`, `list-customer`, `list-staff`, `create-staff`, `update-staff`, `delete-staff`, `config`, `list-product`, `update-shop`, `revenue`, `customer-care`, `accept-schedule`, `view-total-staff`, `view-care-cycle-days`, `view-action`, `reject-cskh`, `setting-general`, `shop-view-revenue`, `delete-shop`, `create-update-destroy-loyalty-tier`, `asign-cskh`, `region-report`, `loyalty-report`, `delete-cskh`, `dashboard-overview`, `customer-pendding-upgrade`, `view-chance`, `chance-customer`, `chance-staff` |
| 2 | `manager-cskh` | `create-shop`, `list-shop`, `list-customer`, `list-staff`, `list-product`, `update-shop`, `revenue`, `customer-care`, `accept-schedule`, `view-total-staff`, `view-care-cycle-days`, `view-action`, `reject-cskh`, `shop-view-revenue`, `asign-cskh`, `region-report`, `loyalty-report`, `delete-cskh`, `dashboard-overview`, `customer-pendding-upgrade`, `view-chance`, `chance-customer`, `chance-staff` |
| 13 | `staff-cskh` | `list-shop`, `update-shop`, `view-total-staff`, `view-care-cycle-days` |
| 14 | `staff-sale` | `list-customer` |
| 15 | `manager-sale` | (không có) |
| 18 | `sales-pos-local-test-20261022` | `list-customer`, `list-product`, `pos-create` |

Hệ quả:

- Sale TEST 636: đủ `pos-create`, `list-product`, `list-customer`, tạo được đơn.
- `staff-sale`: thiếu **`list-product`**. `pos-create` không bắt buộc vì thuộc nhánh role Sale cũ.
- `manager-sale`: thiếu **`list-product` và `list-customer`**. `pos-create` cũng không bắt buộc theo nhánh role cũ.
- `staff-cskh`: thiếu **`pos-create`, `list-product`, `list-customer`**. API hiện dừng ngay ở middleware đọc sản phẩm; không giả định CSKH bị cấm vĩnh viễn theo tên role.
- `manager-cskh`: có hai quyền đọc nhưng thiếu **`pos-create`**. Kết luận từ code + DB, không đăng nhập tài khoản quản lý thật.
- Admin: hiện đủ điều kiện tạo; không cần `pos-create`. Quyền Kho là quyền khác, Admin hiện không có `warehouse-view`/`warehouse-pack`.

## 4. Đăng nhập, shop, sản phẩm và khách hàng

| Tài khoản | Đăng nhập UI + API | Shop options | Sản phẩm shop 38 | Khách POS shop 38 | Shop trong form POS |
| --- | --- | --- | --- | --- | --- |
| Sale 636 | Thành công, API HTTP 200 | Chỉ 38 | HTTP 200, chọn product TEST 13574 trên UI | HTTP 200, chọn khách TEST 467244 trên UI | Chỉ shop 38; đã chọn thành công |
| CSKH 641 | Thành công, API HTTP 200 | Chỉ 38 | HTTP 403 | HTTP 403 | Không có form do màn hình từ chối |
| Admin 1 | Thành công, API HTTP 200 | Global, gồm 38/39 | HTTP 200, đã chọn TEST 13574 | HTTP 200, đã chọn TEST 467244 | Đã chọn shop TEST 38 |

Sale gọi hai API đọc ở shop **39** nhận **403**. Admin đọc shop 39 nhận 200 là đúng phạm vi global trong code, không suy diễn từ giả định Admin toàn quyền. Tài khoản CSKH có membership 38 vẫn bị chặn quyền chức năng; membership không thay thế permission.

Khách POS còn được ràng buộc theo `assigned_user_id` đối với nhân viên thường. Khách TEST 467244 được gán cho Sale 636; Admin được đọc khách trong phạm vi global. `CustomerReadAccessService` kiểm tra lại khách được chọn lúc tạo đơn.

## 5. Kiểm tra API bảo mật thực tế

Tất cả yêu cầu dưới đây dùng token đăng nhập thật của đúng tài khoản. Dùng payload nghiệp vụ TEST hợp lệ, khóa UUID mới cho mỗi yêu cầu từ chối. Không tạo đơn thành công bằng dữ liệu giả danh.

| Yêu cầu `POST /orders` | HTTP | Số orders trước/sau | Số bản ghi theo khóa request |
| --- | --- | --- | --- |
| CSKH 641, shop 38 được gán | 403 | 90.587 / 90.587 | 0 |
| CSKH 641, gửi thêm user/creator/role Admin và permission tự khai | 403 | 90.587 / 90.587 | 0 |
| Sale 636, đổi shop sang 39 ngoài phạm vi | 403 | 90.587 / 90.587 | 0 |
| Sale 636, shop 39 và trường user/creator/role/quyền giả danh Admin | 403 | 90.587 / 90.587 | 0 |

Backend kiểm tra shop thật bằng `ShopAccessService::authorizeRequestedShopId` ngay đầu `OrderService::createLocalOrder`, trước transaction tạo đơn/khách và cập nhật tồn. Scope dựa trên `shop_users` cho nhân viên, không nhận phạm vi từ payload. Người tạo lấy từ `$request->user()` rồi `$actor->id`/`pancake_user_id`, không dùng `user_id`/`created_by_user_id` tự khai. Bộ dữ liệu chuyển vào service là dữ liệu đã validate; quyền không lấy từ body request.

Chứng cứ: [denied-results.json](/Users/viet/Pancake/.local-backups/pos-role-audit-20261104/denied-results.json). Nguồn: [ShopAccessService.php](/Users/viet/Pancake/pancake_order/app/Services/ShopAccessService.php:43), [OrderService.php](/Users/viet/Pancake/pancake_order/app/Services/OrderService.php:22).

## 6. Hai đơn thực sự được tạo trong MySQL Local

Cả hai được tạo bằng giao diện `/ban-hang` gọi API nghiệp vụ thật, dùng shop TEST 38, sản phẩm TEST Local 13574 giá 1.000đ, khách TEST có sẵn 467244. Không gọi API vận chuyển, không giao hàng, không ghi nhận thu tiền thật.

| Thuộc tính | Sale | Admin |
| --- | --- | --- |
| Orders ID / mã | 349316 / POS-349316 | 349317 / POS-349317 |
| Tạo lúc (+07) | 04/11/2026 22:39:01 | 04/11/2026 22:40:45 |
| `created_by_user_id` / `user_creator_id` | 636 / 636 | 1 / 1 |
| Shop / nguồn | 38 / `local_pos` | 38 / `local_pos` |
| Số lượng × giá | 2 × 1.000đ | 3 × 1.000đ |
| Giảm giá / phí / phụ thu | 100 / 200 / 50đ | 200 / 300 / 100đ |
| Tổng tiền | **2.150đ** | **3.200đ** |
| `cash` / `paid_amount` | 0 / 0đ | 0 / 0đ |
| `payments` | `[]` | `[]` |
| COD / còn phải thu | **2.150đ / 2.150đ** | **3.200đ / 3.200đ** |
| `pancake_order_id` / `order_number_vtp` | null / null | null / null |
| Kho | `waiting_packing` | `waiting_packing` |
| `warehouse_queued_at` (+07) | 04/11/2026 22:39:01 | 04/11/2026 22:40:45 |
| `packed_at` | null | null |

Công thức Sale: `2 × 1.000 − 100 + 200 + 50 = 2.150`; Admin: `3 × 1.000 − 200 + 300 + 100 = 3.200`. COD là kỳ vọng của dữ liệu TEST, không phải tiền đã thu.

Ghi chú mỗi đơn có `TEST-POS-ROLE-20261104` cùng chỉ dẫn không giao hàng, không thu tiền thật, không tạo vận đơn. Mỗi actor có đúng **1** đơn mới mang marker. CSKH có **0** đơn.

Đối chiếu chỉ đọc: `GET /orders/{id}` trả 200 bằng đúng người tạo; `GET /warehouse/orders/{id}` và danh sách lọc từng ID trả 200 bằng Kho TEST 637, mỗi truy vấn danh sách trả đúng một đơn. UI Kho cũng hiển thị cả hai ở tab Chờ đóng hàng. Kho được kích hoạt từ trước, hai đơn tạo sau mốc và status 0 nên tự vào Kho tại biên tạo. Không bấm Hoàn tất cho đơn cũ hoặc đơn mới.

Nguồn: [OrderService.php](/Users/viet/Pancake/pancake_order/app/Services/OrderService.php:154), [WarehouseService.php](/Users/viet/Pancake/pancake_order/app/Services/WarehouseService.php:105). Chứng cứ SQL/API: [verified-results.json](/Users/viet/Pancake/.local-backups/pos-role-audit-20261104/verified-results.json).

## 7. Bảo toàn dữ liệu và phạm vi thao tác

- Tổng orders: **90.587 → 90.589**, tăng đúng 2 đơn TEST, ID mới 349316 và 349317.
- SHA-256 toàn bộ hàng của **90.587 đơn cũ** (ID ≤349315, gồm cả deleted rows) giữ nguyên. Không sửa, xóa hay đóng hàng đơn cũ.
- Toàn bộ hàng của 248 user cũ, roles, permissions, role_permissions, shops, products, khách shop TEST, jobs và shipments khớp trước/sau. Membership cũ khớp sau khi loại duy nhất membership mới của CSKH TEST 641.
- Activity logs cũ giữ nguyên, chỉ thêm sự kiện tạo của hai đơn mới; không có hoạt động đóng hàng cho hai đơn.
- TEST product 13574 vẫn virtual_stock=5 theo sàn tồn hiện tại, không thay giá hay sửa dữ liệu sản phẩm để test.
- AICRM shipments: **0 → 0**; jobs giữ **4**. Không tạo vận đơn Viettel Post, không chạy đồng bộ Production, không khởi chạy scheduler hoặc worker mới.
- Không sửa source nghiệp vụ, `.env`, role/permission hiện có; không chạy migration, không commit/push. Chỉ thêm báo cáo này vào repo; scripts/bằng chứng/thông tin đăng nhập nằm trong thư mục riêng tư ngoài repo Backend.
- Không dùng HTTP Mock làm bằng chứng tạo đơn. Cấu hình AICRM mock có sẵn không tham gia vào các yêu cầu POS/Kho chỉ đọc của lượt này.

Chứng cứ: [preservation-results.json](/Users/viet/Pancake/.local-backups/pos-role-audit-20261104/preservation-results.json), [before-hashes.json](/Users/viet/Pancake/.local-backups/pos-role-audit-20261104/before-hashes.json).

## 8. Kết luận và đề xuất, chưa sửa chính sách

1. **Sale TEST 636 bán POS được. Sale role chuẩn `staff-sale` hiện chưa được**, do thiếu `list-product`. Đây là khác biệt cấu hình quyền giữa TEST và role nhân viên dùng chung; chưa có bằng chứng yêu cầu kinh doanh cho phép kết luận việc chặn là sai chính sách.
2. **CSKH `staff-cskh` hiện không bán POS được**: UI ẩn nút/chặn form và Backend trả 403, không có bản ghi mới. Thiếu cả `pos-create`, `list-product`, `list-customer`. Tên role không bị cấm tuyệt đối: code có nhánh permission tường minh cho role khác. `manager-cskh` chỉ thiếu `pos-create`, theo audit code + DB.
3. **Admin Local hiện bán POS được**: không cần `pos-create`, nhưng vẫn cần hai quyền đọc thực tế tại Backend và tài khoản đang có đủ.
4. **Kiểm soát phạm vi shop ở Backend hoạt động** trong các yêu cầu đã thử: Sale ngoài shop trả 403, giả danh trong body không vượt được quyền. Không phát hiện vượt quyền trong phạm vi kiểm tra.
5. **Có điểm không nhất quán tiềm ẩn ở giao diện Admin**: `hasPermission` miễn hai quyền đọc cho Admin, trong khi Backend đòi grant thật. Hiện Admin có đủ nên không tái hiện lỗi vận hành. Nếu thiếu grant sau này, UI vẫn mở POS nhưng API trả 403. Đề xuất cho `canUsePos` kiểm tra grant đọc tường minh giống Backend, giữ nhánh miễn `pos-create` hiện có; chưa áp dụng.
6. Nếu nghiệp vụ muốn Sale thường bán POS, cần xem xét cấp `list-product` cho role phù hợp sau khi duyệt phạm vi. Nếu muốn CSKH bán POS, cần quyết định cấp ba quyền cho staff-cskh hoặc chỉ `pos-create` cho manager-cskh, kèm phạm vi khách/shop. Đây là đề xuất chính sách, **không thực hiện trong lượt này**.
7. Hai đơn **POS-349316 và POS-349317** đã lưu MySQL Local và vào Kho. Cả hai còn chờ đóng, không có vận đơn, không thu tiền.

## 9. Bằng chứng giao diện

[Đơn Sale đã tạo](/Users/viet/Pancake/.local-backups/pos-role-audit-20261104/sale-created.png), [đơn Admin đã tạo](/Users/viet/Pancake/.local-backups/pos-role-audit-20261104/admin-created.png), [CSKH bị chặn](/Users/viet/Pancake/.local-backups/pos-role-audit-20261104/cskh-denied.png).

![Hai đơn mới trong tab Chờ đóng hàng của Kho](/Users/viet/Pancake/.local-backups/pos-role-audit-20261104/warehouse-two-new-orders.png)
