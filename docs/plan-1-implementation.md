# PLAN 1 — sản phẩm Local, tồn ảo và phạm vi shop

Ngày: 21/10/2026. Backend và Frontend đều trên branch `viet_dev`; Git status ban đầu sạch. Không commit/push. Không chạy migration, seeder hoặc lệnh xóa/reset dữ liệu MySQL. Không thay đổi quyền, role hay phân công shop của tài khoản hiện có.

## Đã triển khai

- Bổ sung nút **Thêm sản phẩm** và form thêm/sửa ngay tại trang `/san-pham`: tên, shop được phép truy cập, giá bán, số lượng gốc và ảnh tùy chọn.
- CRUD dùng các route `/api/v1/products` hiện có. Backend validation tên, shop còn hoạt động, giá không âm (tối đa hai chữ số thập phân), tồn nguyên không âm, ảnh JPG/PNG/WebP tối đa 5 MB.
- Sản phẩm Local có `pancake_product_id = null`. API không nhận ID Pancake hoặc raw payload do client tự truyền vào. Job đồng bộ vẫn được phép cập nhật các trường này qua model như trước.
- Tái sử dụng JSON `products.pancake_full_data`: lưu giá bán vào `retail_price`, số lượng gốc vào `remain_quantity`, ảnh vào `images`. Đây là các khóa đã kiểm tra trong dữ liệu Pancake thực tế trên MySQL Local.
- Ảnh được lưu bằng public disk Laravel, tên ngẫu nhiên do server tạo, kiểm tra nội dung/định dạng/kích thước. Phân quyền và validation hoàn tất trước khi ghi file; nếu lưu DB lỗi thì xóa file vừa tạo. Không upload ảnh mới thì giữ ảnh cũ. Ảnh được phục vụ công khai qua `/storage`, theo cơ chế public disk; API đọc/ghi sản phẩm vẫn kiểm tra shop.
- Danh sách và chi tiết cùng dùng accessor `Product::virtual_inventory_quantity = max(5, inventory_quantity ?? 0)`. `inventory_quantity` tiếp tục là số lượng gốc. Việc đọc/serialize/tính tồn ảo không ghi DB, không sửa số lượng nguồn hoặc tồn từng kho trong payload.
- Frontend lấy giá từ `product.price` đã chuẩn hóa ở Backend, bỏ toàn bộ dò giá đệ quy có thể nhầm `cost`, `amount`, giá nhập hoặc giá sỉ. Backend ưu tiên `retail_price`, hỗ trợ các trường giá bán đã biết; thiếu giá thì trả `null`, UI hiển thị dấu gạch.
- Tái sử dụng `list-product` cho quyền thao tác như CRUD hiện có. Thiếu quyền trả HTTP 403. Backend dùng `ShopAccessService`/`shop_users` cho list, detail, create, update, delete và upload; kiểm tra cả shop nguồn và shop đích khi đổi shop.
- Không cho chuyển sản phẩm Pancake sang shop khác hoặc sửa ID đồng bộ. Form có thông báo: lần đồng bộ sau sẽ cập nhật lại tên, giá, số lượng và ảnh của sản phẩm đồng bộ từ Pancake. Sản phẩm Local không bị job đồng bộ chạm tới.

## Phần có sẵn được tái sử dụng

`Product`, `ProductController`, bảng `products` và JSON hiện có; `GetProductByShopJob` không sửa; route resource sản phẩm không thêm API; `ShopAccessService`, `shop_users`, `PermissionCheckMiddleware`, quyền `list-product`; `ShopSelect` và API shop summary; API client, React Query, Ant Design, thông báo lỗi/validation hiện có; Laravel public disk.

Không thêm model, service, component hoặc bảng trùng chức năng.

## File tạo/sửa

Backend (`/Users/viet/Pancake/pancake_order`):

- Sửa `app/Models/Product.php`.
- Sửa `app/Http/Controllers/Api/ProductController.php`.
- Tạo `tests/Feature/ProductPlanOneTest.php`.
- Tạo `tests/Unit/ProductTest.php`.
- Tạo báo cáo `docs/plan-1-implementation.md`.

Frontend (`/Users/viet/Pancake/pancake_frontend_v2`):

- Sửa `src/pages/Products/ProductsPage.jsx`.
- Sửa `src/api/products.api.js`.
- Sửa `src/components/ShopSelect.jsx` (hỗ trợ disabled trong form).
- Tạo `tests/productsApi.test.js`.

Thiết lập Local ngoài Git: đã chạy `artisan storage:link`, tạo symlink `public/storage` tới `storage/app/public`. File build và file test tạm nằm trong các thư mục ignored hoặc `/tmp`.

## Migration

Không có. Đã kiểm tra schema MySQL hiện tại và các migration sản phẩm trước khi sửa. Không cần thêm cột để lưu dữ liệu Local.

## Kiểm thử thực tế

Test ghi dữ liệu chạy trên SQLite in-memory và fake storage riêng; không dùng MySQL đang hoạt động để tạo dữ liệu thử.

| Hạng mục | Kết quả |
| --- | --- |
| Thêm sản phẩm Local, ID Pancake null, giá/tồn bằng 0 | PASS |
| Sửa sản phẩm, giá có phần thập phân, đọc chi tiết/tìm kiếm | PASS |
| Validation tên/shop/giá/tồn; chặn raw payload và ID giả | PASS |
| Upload ảnh, thay ảnh qua multipart POST + `_method=PUT`, giữ ảnh khi không upload | PASS |
| Chặn file sai định dạng, SVG và ảnh quá dung lượng | PASS |
| Chặn đọc/sửa/xóa/upload ngoài shop; chặn chuyển shop ngoài phạm vi | PASS |
| Sale, quản lý sale, kho, CSKH, quản lý CSKH cùng tuân thủ shop scope | PASS |
| Thiếu quyền, không được phân shop, shop đã soft-delete | PASS |
| Đồng bộ cập nhật đúng sản phẩm Pancake, giữ ID và dữ liệu nguồn khác; không chạm sản phẩm Local | PASS |
| `0→5`, `3→5`, `5→5`, `20→20`; danh sách và chi tiết thống nhất | PASS |
| So sánh raw record trước/sau đọc: số lượng nguồn, payload kho, timestamp không thay đổi | PASS |
| Chỉ lấy trường giá bán; giá vốn/giá nhập/giá sỉ/amount không làm fallback | PASS |
| Backend: ProductPlanOneTest + ProductTest + PrivilegeHardeningAndShopAccessTest + DirectReadIdorHardeningTest | PASS — 97 tests, 666 assertions |
| Frontend: productsApi + shopOptions | PASS — 7 tests |
| Frontend production build | PASS |
| Backend Pint và Git diff whitespace | PASS |
| Frontend lint | PASS exit 0; 17 warning có sẵn ở các màn hình ngoài phạm vi |
| Trình duyệt Local | PASS — danh sách sản phẩm đồng bộ, giá bán, tồn ảo, nút thêm, form và validation trường bắt buộc |

Lệnh kiểm tra chính:

```sh
# Backend — tránh PHP 7.4 mặc định; dùng PHP 8.4 đã có trên máy
cd /Users/viet/Pancake/pancake_order
DB_CONNECTION=sqlite DB_DATABASE=:memory: /opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit tests/Feature/ProductPlanOneTest.php tests/Unit/ProductTest.php tests/Feature/PrivilegeHardeningAndShopAccessTest.php tests/Feature/DirectReadIdorHardeningTest.php

# Frontend
cd /Users/viet/Pancake/pancake_frontend_v2
node --test --test-force-exit tests/productsApi.test.js tests/shopOptions.test.js
npm run build
npm run lint
```

### Lỗi hồi quy có sẵn ngoài phạm vi

Backend khi chạy thêm toàn bộ `OpportunitySecurityHardeningTest`: tổng 146 test, 142 PASS và 4 FAIL. Đã nạp lại **Product và ProductController gốc từ HEAD** trong thư mục tạm và chạy lại suite này: cùng 4 FAIL, không phát sinh bởi PLAN 1:

- `test_valid_assign_preserves_response_assignment_state_dates_and_activity_log` (dòng 347).
- `test_staff_and_monitoring_workload_aggregates_count_only_actionable_cares` (dòng 1185).
- `test_import_rejects_incomplete_rows_and_invalid_phone_numbers_without_partial_import` (dòng 1397).
- `test_phase_3_monitoring_returns_only_users_intersecting_the_effective_shop_scope` (dòng 1477).

Frontend chạy tất cả test: 66 test, 59 PASS và 7 FAIL. Đã xuất toàn bộ **HEAD chưa sửa** sang thư mục tạm, dùng cùng dependencies và chạy lại: 61 test, 54 PASS và cùng 7 FAIL. Năm test sản phẩm mới đều PASS. Những test cũ còn FAIL:

- `customerCareActionParity.test.js`: form hoàn tất CSKH.
- `customerCareOrderSource.test.js`: query options CSKH.
- `orderColumnsRender.test.js`: cột/thao tác đơn hàng.
- `orderFilters.test.js`: tham số gọi API đơn hàng.
- `orderPagesApi.test.js`: hai test query options/tham số đơn hàng.
- `orderSourcesApi.test.js`: tham số gọi API đơn hàng.

Dùng `--test-force-exit` vì một số test hiện có giữ timer của React Query sau khi hoàn tất. Không sửa các lỗi hoặc test ngoài phạm vi sản phẩm/shop trong PLAN 1.

## Phần còn lại và giới hạn

Không còn blocker triển khai các hạng mục sản phẩm trong PLAN 1. Chưa triển khai trừ tồn theo đơn hàng, POS bán/xuất, MISA, Viettel Post hoặc mã vận đơn; các phần này nằm ngoài PLAN 1. Công thức hiện tại chỉ tính giá trị hiển thị, chưa có bộ đếm tồn ảo bị trừ theo giao dịch.

Cần xử lý riêng 4 lỗi test Backend và 7 lỗi test Frontend có sẵn nếu yêu cầu toàn bộ test cũ phải xanh.

Các chỉnh sửa trên sản phẩm đồng bộ không phải override vĩnh viễn: lần đồng bộ tiếp theo tiếp tục lấy dữ liệu Pancake. Ảnh upload dùng public disk, không phải kho ảnh riêng tư theo shop. Khi thay ảnh, file cũ chưa bị tự động dọn để tránh xóa nhầm ảnh còn được sử dụng.

## Mở trình duyệt kiểm tra

Backend `127.0.0.1:8000` và Vite `localhost:5173` đã chạy tại thời điểm kiểm tra. Mở `http://localhost:5173/san-pham`, đăng nhập bằng tài khoản có quyền `list-product`, chọn **Thêm sản phẩm**. Nhập số lượng gốc 0 hoặc 3: sau khi lưu, cột tồn ảo phải hiển thị 5; khi mở sửa, trường số lượng vẫn phải là số gốc đã nhập. Thử một sản phẩm đồng bộ để xem thông báo và shop bị khóa.

Nếu cần khởi động lại:

```sh
cd /Users/viet/Pancake/pancake_order
/opt/homebrew/opt/php@8.4/bin/php -d upload_max_filesize=5M -d post_max_size=8M artisan serve --host=127.0.0.1 --port=8000

# Terminal khác
cd /Users/viet/Pancake/pancake_frontend_v2
npm run dev
```

Không cần chạy migration. Khi triển khai sang môi trường khác cần public disk writable, symlink storage và PHP cho phép upload tối thiểu 5 MB/post tối thiểu 8 MB.
