# Cho phép Sale và CSKH tạo POS — Local 04/11/2026

Đã thực hiện theo yêu cầu nghiệp vụ mới, sau [báo cáo audit trước thay đổi](/Users/viet/Pancake/pancake_order/docs/pos-role-audit-20261104.md). Repo `/Users/viet/Pancake/pancake_order`, branch `viet_dev`; Frontend thực tế từ `frontend/` tại `http://localhost:5173`, API thật tại `http://127.0.0.1:8002/api/v1`, MySQL Local `127.0.0.1:3306/pancake_order`.

## 1. Kết quả thực tế

Mỗi tài khoản đăng nhập qua UI và API thật, chọn shop TEST 38, sản phẩm TEST 13574 giá 1.000đ, khách hợp lệ theo phạm vi, rồi bấm tạo đúng một đơn trên `/ban-hang`. Không dùng SQL, CLI service hoặc HTTP Mock để tạo đơn.

| Vai trò / tài khoản | Đăng nhập | Hiện POS / mở POS | Shop và khách hợp lệ | Tạo đơn thật / MySQL | Đơn mới | Tự vào Kho |
| --- | --- | --- | --- | --- | --- | --- |
| Sale thường 642, role 14 `staff-sale` | PASS | PASS | PASS, chỉ shop 38, khách 467247 | PASS | **POS-349318** | PASS — Chờ đóng hàng |
| CSKH thường 641, role 13 `staff-cskh` | PASS | PASS | PASS, chỉ shop 38, khách 467248 | PASS | **POS-349319** | PASS — Chờ đóng hàng |
| Admin Local 1, role 1 `admin` | PASS | PASS | PASS, chọn shop TEST 38, khách 467244 | PASS | **POS-349320** | PASS — Chờ đóng hàng |

Sale thường TEST **642** là tài khoản mới `sale.pos-staff-20261104@pancake.test`, đúng role dùng chung 14; không dùng role tùy chỉnh của Sale 636 để chứng minh kết quả Sale thường. Giữ nguyên role 18 của Sale 636. CSKH 641 được tái sử dụng, không đặt lại mật khẩu. Admin 1 cũng giữ nguyên mật khẩu/quyền. Thông tin đăng nhập chỉ lưu riêng tư ngoài repo Backend.

## 2. Quyền đã thay đổi

Sao lưu hoàn tất trước khi thay đổi. Migration [2026_11_04_000004_enable_staff_pos_permissions.php](/Users/viet/Pancake/pancake_order/database/migrations/2026_11_04_000004_enable_staff_pos_permissions.php) đã chạy **riêng** trên MySQL Local lúc **22:58:50 +07, 04/11/2026**.

| Role | Grant bổ sung thực tế | Giải thích |
| --- | --- | --- |
| ID 14 `staff-sale` | **`list-product`** | `list-customer` đã có; role Sale đã nằm trong nhánh tạo POS cũ nên không cần thêm `pos-create` |
| ID 13 `staff-cskh` | **`pos-create`, `list-product`, `list-customer`** | Đủ ba điều kiện để đọc catalog/khách và tạo POS |
| ID 2 `manager-cskh` | **`pos-create`** | Đây là role quản lý CSKH hiện có (`isManagerCskh` và phạm vi quản lý đang được code sử dụng); đã có đủ hai quyền đọc |
| ID 1 `admin` | Không đổi | Giữ nhánh tạo POS hiện tại, vẫn cần hai quyền đọc ở Backend |
| Kho, `manager-sale`, role khác | Không đổi | Không cấp quyền Kho hoặc quản trị mới |

Tổng **5 grant**, `role_permissions` **95 → 100**. Không sửa/xóa grant cũ, role, catalog permission hay membership nhân viên cũ. Đã chạy lại `up()` để xác nhận không thêm trùng hoặc đổi timestamp grant cũ. Role ID được giải quyết theo **slug**, không hard-code ID Local khi deploy.

Migration bảo đảm các cặp quyền cần thiết cho Sale/CSKH/quản lý CSKH, chỉ thêm cặp còn thiếu. Yêu cầu đúng một role và một permission active cho từng slug, lỗi thì rollback toàn transaction. `down()` chủ động từ chối thu hồi tự động vì không thể phân biệt quyền vốn có với quyền do migration thêm; nếu cần thu hồi phải đối chiếu bản sao lưu và duyệt grant cụ thể.

Bản sao lưu chính xác trước thay đổi: [before.json](/Users/viet/Pancake/.local-backups/pos-staff-enable-20261104/before.json), gồm toàn bộ `roles`, `permissions`, `permission_groups`, `role_permissions`, `shop_users` và checksum. Bằng chứng áp dụng: [policy-applied.json](/Users/viet/Pancake/.local-backups/pos-staff-enable-20261104/policy-applied.json). Thư mục bằng chứng quyền 0700, file riêng tư 0600; không chứa trong Git Backend.

## 3. Frontend và Backend đã sửa

- `canUsePos` kiểm tra đúng nhánh role/`pos-create` và **grant đọc tường minh** giống Backend, kể cả Admin. Sửa lệch cũ khi Frontend tự miễn quyền đọc cho Admin nhưng Backend không miễn. Header, nút trên trang Đơn hàng và form POS cùng dùng helper này.
- Backend giữ middleware `auth:api`, `list-product`, `list-customer`, nhánh role/`pos-create`, `ShopAccessService` và `CustomerReadAccessService`.
- Payload POS có các trường giả danh `user_id`, `created_by_user_id`, `user_creator_id`, `user_assigning_seller_id`, `role_id`, `permissions` không rỗng bị từ chối **422**. Người tạo vẫn lấy từ phiên đăng nhập.
- Phát hiện `list-product` trước đây đồng thời cho phép thêm/sửa/xóa sản phẩm. Để quyền đọc POS không mở quản trị catalog, Backend chặn `store/update/destroy` cho **`staff-sale` và `staff-cskh`** bằng **403**; Frontend ẩn nút thêm/sửa của hai role này. Admin và các role khác giữ điều kiện ghi catalog trước đây.
- Không đổi `OrderService`, `WarehouseService`, `.env`, giá, COD, thanh toán, tồn ảo hay cách queue Kho; SHA-256 các file này khớp bản trước sửa.

File chính: [permissions.js](/Users/viet/Pancake/pancake_order/frontend/src/auth/permissions.js:24), [ProductsPage.jsx](/Users/viet/Pancake/pancake_order/frontend/src/pages/Products/ProductsPage.jsx:46), [ProductController.php](/Users/viet/Pancake/pancake_order/app/Http/Controllers/Api/ProductController.php:94), [OrderController.php](/Users/viet/Pancake/pancake_order/app/Http/Controllers/Api/OrderController.php:365).

## 4. Tác động lên module dùng chung và giới hạn dữ liệu

| Module / API | Tác động và bằng chứng |
| --- | --- |
| Sản phẩm | Sale/CSKH thường thấy menu và đọc sản phẩm trong shop membership. GET shop 38 trả 200; shop 39 trả 403. Không có quyền thêm/sửa/xóa catalog nhờ guard mới. UI CSKH đã xác nhận chỉ có danh sách, không có nút thêm/sửa |
| Khách hàng (`GET /customers`) | CSKH nay thấy menu Khách hàng. Cả Sale 642 và CSKH 641 chỉ nhận đúng khách được gán cho mình, cùng shop 38. Không thay `assigned_user_id` của khách cũ |
| Khách dùng POS (`view=pos`) | Mỗi tài khoản staff chỉ nhận đúng một khách TEST của mình. Chọn khách 467244 thuộc Sale 636 cùng shop vẫn bị 403 khi tạo POS |
| Journey khách hàng | GET journey khách 467244 của người khác trả 403 cho cả Sale/CSKH, Admin đọc được theo quyền cũ |
| Khách trong trang Đơn hàng / order-insights | `list-customer` mở điều kiện xem ở Frontend, nhưng MySQL Local hiện thiếu **`customers.last_order_at`**, API trả **500** cho cả Sale, CSKH, Admin. Đây là lỗi schema/module có sẵn; SQL lỗi vẫn chứa ràng buộc shop và assigned user. Không báo PASS cho module này, không chạy migration/backfill ngoài phạm vi để che lỗi |
| Báo cáo / Loyalty / quản trị | Không cấp thêm permission báo cáo, loyalty, nhân sự, role hay quản trị. Các gate riêng vẫn giữ nguyên |
| Kho | Không cấp `warehouse-view`/`warehouse-pack`; Sale và CSKH gọi GET Kho đều 403. Đơn mới tự vào Kho không đồng nghĩa người bán có quyền quản lý Kho |

Hai khách TEST mới **467247** (gán Sale 642), **467248** (gán CSKH 641), shop 38, số điện thoại giả, tên có marker. Không chuyển khách thật/khách cũ sang tài khoản TEST và không mở rộng scope để vượt validation. Admin dùng lại khách TEST 467244. Sản phẩm 13574 giữ nguyên giá 1.000đ và tồn ảo sàn 5.

`list-product` vẫn trả dữ liệu theo contract sản phẩm hiện tại, gồm các trường trong JSON nguồn của sản phẩm thuộc shop được phép; không thêm một tầng lọc field mới. Quyền này không mở dữ liệu ở shop khác. Manager CSKH giữ phạm vi quản lý khách trong shop như trước; thêm `pos-create` không tự nới membership hoặc phạm vi đọc khách.

## 5. Kiểm tra bảo mật trên API Local thật

| Kiểm tra | Sale thường 642 | CSKH thường 641 | Admin 1 |
| --- | --- | --- | --- |
| Đăng nhập | 200 | 200 | 200 |
| Tạo đơn shop 39 ngoài membership | **403**, 0 bản ghi | **403**, 0 bản ghi | Không áp dụng: Admin đang global theo chính sách cũ; không tạo thêm đơn ở shop khác |
| Chọn khách thuộc nhân viên khác, cùng shop | **403**, 0 bản ghi | **403**, 0 bản ghi | Được đọc theo chính sách Admin cũ; không dùng case này để tạo đơn thứ hai |
| Giả danh người tạo/role/quyền qua body | **422**, 0 bản ghi | **422**, 0 bản ghi | **422**, 0 bản ghi |
| POST tạo sản phẩm / PUT sản phẩm TEST | **403**, không đổi dữ liệu | **403**, không đổi dữ liệu | Quyền quản lý hiện tại giữ nguyên, không thử ghi catalog trên Local |
| GET Kho | **403** | **403** | Không cấp thêm quyền Kho |

Tổng **13 yêu cầu từ chối/giới hạn**; so sánh counts orders/products/customers trước và sau từng yêu cầu đều giữ nguyên, UUID của yêu cầu tạo đơn bị từ chối có 0 bản ghi. Bài test tự động riêng còn kiểm tra DELETE catalog của cả hai role bị 403, sản phẩm giữ nguyên; không gọi DELETE vào dữ liệu Local.

Chứng cứ: [denied-results.json](/Users/viet/Pancake/.local-backups/pos-staff-enable-20261104/denied-results.json), [read-results.json](/Users/viet/Pancake/.local-backups/pos-staff-enable-20261104/read-results.json).

## 6. Đơn MySQL Local và Kho

| Đơn | Creator | Shop / nguồn | Khách | SL × giá | Tổng / COD | Đã thu | Tạo và vào Kho (+07) | Kho |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| POS-349318 | 642 / 642 | 38 / `local_pos` | 467247 | 2 × 1.000đ | **2.000đ / 2.000đ** | 0đ | 04/11/2026 23:02:33 | `waiting_packing` |
| POS-349319 | 641 / 641 | 38 / `local_pos` | 467248 | 3 × 1.000đ | **3.000đ / 3.000đ** | 0đ | 04/11/2026 23:03:54 | `waiting_packing` |
| POS-349320 | 1 / 1 | 38 / `local_pos` | 467244 | 4 × 1.000đ | **4.000đ / 4.000đ** | 0đ | 04/11/2026 23:06:01 | `waiting_packing` |

Creator là `created_by_user_id / user_creator_id`. Mỗi actor có đúng một order mang `TEST-POS-CSKH-SALE-20261104`. `cash=0`, snapshot `paid_amount=0`, `payments=[]`, giảm giá/phí/phụ thu đều 0. COD là kỳ vọng TEST, không phải tiền đã thu. Cả ba không có `pancake_order_id`, `order_number_vtp`, `packed_at` hoặc bản ghi AICRM shipment.

`GET /orders/{id}` bằng người tạo và GET danh sách/chi tiết Kho bằng Kho TEST 637 đều 200. Danh sách Kho lọc từng ID có đúng một bản ghi, không chỉ có nhãn trạng thái trong DB. Giữ nguyên kích hoạt Kho `2026-10-22T13:54:15+07:00`; đơn mới được queue ngay tại thời điểm tạo, không cần chạy sync/worker để vào Kho.

Đối chiếu [verified-results.json](/Users/viet/Pancake/.local-backups/pos-staff-enable-20261104/verified-results.json) và các ảnh giao diện tại cuối báo cáo.

## 7. Kiểm thử và bảo toàn

- Backend: **100 PASS, 960 assertions**, SQLite trong bộ nhớ tách hoàn toàn MySQL Local; gồm POS, ProductPlanOne, privilege/shop access, CustomerJourney, Warehouse. Có test migration tối thiểu/idempotent/rollback toàn bộ khi thiếu role, CSKH tạo được POS nhưng không đọc khách người khác hoặc giả danh creator, staff đọc catalog không được quản lý catalog.
- Frontend: **22 PASS**, POS + products API + Warehouse. Có test Admin cần grant đọc thật, CSKH/manager CSKH cần đủ quyền, hai role staff không có thao tác quản lý catalog.
- Build Frontend **PASS**; kiểm tra JS bằng oxlint **PASS**; `git diff --check` **PASS**.
- Pint cho migration và test đã sửa **PASS**. Pint toàn bộ hai controller vẫn báo các quy tắc định dạng cũ; chạy trên bản sao trước sửa cho cùng danh sách quy tắc lỗi. Không reformat controller lớn ngoài phạm vi.
- Orders **90.589 → 90.592**. SHA-256 toàn bộ cột của **90.589 hàng orders cũ** (kể cả deleted rows) giữ nguyên, gồm cả đơn 349316/349317 của lượt audit trước.
- Toàn bộ 249 user cũ, 95 grant cũ, role/catalog, membership cũ, shops, products, ba khách cũ shop TEST, activity cũ và jobs đều khớp checksum. Chỉ thêm user Sale TEST 642, membership 38, hai khách TEST, năm grant và ba đơn mới cùng sự kiện tương ứng.
- Jobs giữ **4**, AICRM shipments giữ **0**; không giao hàng, thu tiền thật, tạo vận đơn Viettel Post, gọi sync/Production, sửa `.env`, sửa mật khẩu, commit hoặc push. Không đóng hàng đơn cũ hoặc đơn mới.

Bằng chứng: [preservation-results.json](/Users/viet/Pancake/.local-backups/pos-staff-enable-20261104/preservation-results.json), [backend-tests.log](/Users/viet/Pancake/.local-backups/pos-staff-enable-20261104/backend-tests.log), [frontend-tests.log](/Users/viet/Pancake/.local-backups/pos-staff-enable-20261104/frontend-tests.log).

## 8. Chính xác tác động nếu áp dụng lên Production

**Chưa triển khai hoặc gọi Production.** Các kết luận sau là tác động của code/migration đã chuẩn bị, không phải xác nhận số người dùng hay trạng thái DB Production.

1. Quyền bổ sung áp dụng cho **mọi tài khoản** đang mang `staff-sale`, `staff-cskh`, `manager-cskh`, gồm nhân viên thật. Số Local trước fixture là 131 Sale, 22 CSKH, 15 quản lý CSKH; không dùng các số này làm số Production. Migration tìm role theo slug và chỉ thêm cặp grant thiếu, nên số grant thêm thực tế trên Production phụ thuộc trạng thái Production. Nếu giống Local trước thay đổi thì thêm đúng 5 grant.
2. Staff Sale có đủ quyền đọc để dùng nhánh tạo POS cũ; staff CSKH có đủ ba quyền POS; manager CSKH cũng tạo được qua `pos-create`. Admin giữ nguyên khả năng tạo và không nhận thêm grant. Role `manager-sale` và role khác không được migration cấp quyền mới.
3. Sale/CSKH thấy thêm menu Sản phẩm; CSKH thấy thêm menu Khách hàng và các phần giao diện dùng chung `list-customer`. Khách của nhân viên thường vẫn chỉ là khách được gán cho chính mình trong shop membership; quản lý CSKH tiếp tục phạm vi quản lý trong shop như code hiện tại. Không tự thêm shop, chuyển khách, tăng quyền quản trị hoặc quyền Kho.
4. **Cần triển khai Backend guard catalog trước hoặc đồng thời migration**. Nếu chỉ chạy migration trên code cũ, `list-product` sẽ vô tình mở API thêm/sửa/xóa catalog. Với bản code này, `staff-sale`/`staff-cskh` bị chặn thao tác ghi catalog, kể cả tài khoản staff từng có `list-product` từ trước. Đây là thay đổi hạn chế quyền ghi cần thông báo nếu Production đang dùng staff để quản lý sản phẩm. Manager CSKH/Admin và các role khác giữ hành vi catalog cũ.
5. API từ chối 422 khi client gửi trường giả danh creator/role/quyền không rỗng, thay cho việc bỏ qua chúng. Client cần bỏ các trường này và để Backend xác định actor. Frontend hiện tại đã gửi đúng contract, không cần thêm actor vào payload.
6. API quyền đọc/tạo lấy grant từ DB; UI cần refresh/reload hoặc đăng nhập lại để lấy profile mới. Frontend Admin chỉ hiện POS khi profile có đủ hai grant đọc thật, khớp Backend. Nếu Admin thiếu grant ở Production, cần đối soát cấu hình, không tự miễn quyền hoặc thêm grant Admin trong migration này.
7. Không đổi công thức giá/COD/thanh toán/tồn ảo hoặc queue Kho. Đơn mới vào Kho theo trạng thái/mốc kích hoạt hiện có của môi trường; thay đổi này **không tự bật Kho** ở Production. Không cấp khả năng đóng hàng hoặc tạo vận đơn cho staff.
8. Module order-insights cần được kiểm tra schema `customers.last_order_at` trước rollout; Local hiện thiếu cột nên đã ghi nhận FAIL riêng ở module đó. Không thể khẳng định Production thiếu cột khi chưa kiểm tra Production. Không chạy migration/backfill liên quan trong lượt này.
9. Không đưa tài khoản TEST, khách TEST, đơn TEST hoặc file credential vào deployment. Chỉ triển khai source thay đổi đã liệt kê và migration quyền có kiểm soát; các thay đổi AICRM/Frontend khác đang có sẵn trong working tree không thuộc lượt cấp quyền này. Sao lưu quyền Production trước chạy; nếu cần thu hồi, dùng diff grant và bản sao lưu để thu hồi đúng phần đã thêm, không rollback/xóa đơn đã phát sinh.

## 9. Bằng chứng giao diện

[Sale thường tạo thành công](/Users/viet/Pancake/.local-backups/pos-staff-enable-20261104/sale-created.png), [CSKH thường tạo thành công](/Users/viet/Pancake/.local-backups/pos-staff-enable-20261104/cskh-created.png), [Admin tạo thành công](/Users/viet/Pancake/.local-backups/pos-staff-enable-20261104/admin-created.png), [CSKH chỉ xem catalog](/Users/viet/Pancake/.local-backups/pos-staff-enable-20261104/cskh-products-read-only.png).

![Ba đơn TEST mới trong Kho](/Users/viet/Pancake/.local-backups/pos-staff-enable-20261104/warehouse-three-orders.png)
