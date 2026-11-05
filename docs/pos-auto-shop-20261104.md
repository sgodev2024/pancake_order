# POS tự chọn cửa hàng duy nhất — 04/11/2026

Dự án `/Users/viet/Pancake/pancake_order`, branch `viet_dev`, Frontend `frontend/`. Chỉ sửa và kiểm tra Local: UI `http://localhost:5173/ban-hang`, API `http://127.0.0.1:8002/api/v1`, MySQL Local `pancake_order`. Không commit/push.

## Hành vi đã hoàn thành

| Danh sách shop thực sự được API cho phép | Hành vi POS |
| --- | --- |
| 0 shop | Hiển thị **Bạn chưa được phân công cửa hàng để bán hàng**; không tải sản phẩm/khách, không cho tạo đơn |
| Đúng 1 shop | Sau khi xác minh API thành công, chọn sẵn shop, hiển thị tên, tải ngay sản phẩm và khách theo shop đó |
| 2+ shop | Hiện **Chọn cửa hàng**, không chọn shop đầu tiên, không tải sản phẩm/khách trước khi nhân viên chọn |
| Đang tải / lỗi API | Chưa xác nhận lựa chọn, khóa tải dữ liệu và tạo đơn; lỗi có nút Thử lại |

Không hard-code ID hoặc phân nhánh riêng Sale/CSKH/Admin. Phạm vi lấy từ API `GET /shops?view=summary` hiện có, vốn áp dụng membership cho nhân viên và phạm vi global hiện tại cho Admin. Trang POS không đọc lựa chọn shop được lưu trong bộ lọc chung.

## Cách xử lý trạng thái

- POS sở hữu một query danh sách shop duy nhất; phần hiển thị selector nhận chính query đó, không tự khởi chạy query thứ hai.
- Query danh sách được tách theo user ID. Luôn xác minh lại khi vào POS và khi quay lại cửa sổ; dữ liệu cache cũ không được dùng để tự chọn hoặc tải dữ liệu trước khi request xác minh hoàn tất (`isFetchedAfterMount`, success, không fetching).
- Tài khoản thay đổi hoặc mất quyền POS: tạo lại phiên POS và xóa lựa chọn/giỏ/form cũ. Khi tập ID shop được API cho phép thay đổi, bỏ lựa chọn cũ; chỉ tự chọn lại nếu tập mới có đúng một shop.
- Đổi shop thủ công khi có giỏ cần xác nhận. Xác nhận tạo lại draft: xóa sản phẩm, khách đã chọn, địa chỉ, ghi chú, điều chỉnh tiền, tìm kiếm, phân trang và khóa request cũ. Hủy xác nhận giữ nguyên draft/shop hiện tại.
- Sản phẩm và khách được cache theo user + shop. Query của shop bị thu hồi được loại khỏi cache POS. Request đọc đang chạy có AbortSignal; phản hồi đến muộn không được trộn vào tài khoản hoặc shop mới.
- Trong lúc xác minh lại quyền shop, khóa checkout. Nếu API xác nhận phạm vi không đổi, giữ lại draft hợp lệ; nếu thay đổi thì reset. Callback submit/xác nhận cũ không thể tạo lại draft hoặc gửi đơn sau khi scope đã đổi.
- Luồng đọc `/ban-hang?order_id=...` giữ riêng, không khởi tạo bán hàng hoặc tự tải danh sách shop cho đơn đang đọc.
- Không tự gửi request tạo đơn, thanh toán hay vận chuyển. Kiểm tra shop/khách ở Backend được giữ nguyên, việc tự chọn ở UI không thay thế phân quyền.

## File sửa trong lượt này

| File | Thay đổi |
| --- | --- |
| [PosPage.jsx](/Users/viet/Pancake/pancake_order/frontend/src/pages/Pos/PosPage.jsx) | Phiên POS theo tài khoản, scope shop được xác minh, auto-select một shop, reset draft/cache và chặn callback cũ |
| [ShopSelect.jsx](/Users/viet/Pancake/pancake_order/frontend/src/components/ShopSelect.jsx) | Tách `ShopSelectInput` nhận query từ POS; selector mặc định ở các trang khác tiếp tục dùng query và bộ lọc chung |
| [shops.api.js](/Users/viet/Pancake/pancake_order/frontend/src/api/shops.api.js) | Query POS theo user, xác minh lại dữ liệu, hỗ trợ hủy request; giữ contract summary |
| [products.api.js](/Users/viet/Pancake/pancake_order/frontend/src/api/products.api.js) | GET sản phẩm nhận AbortSignal tùy chọn; không đổi payload ghi hoặc công thức sản phẩm |
| [customers.api.js](/Users/viet/Pancake/pancake_order/frontend/src/api/customers.api.js) | GET khách nhận AbortSignal tùy chọn; không đổi scope Backend |
| [posShopSelection.test.js](/Users/viet/Pancake/pancake_order/frontend/tests/posShopSelection.test.js) | 17 test lifecycle của component POS thật |
| [posUi.js](/Users/viet/Pancake/pancake_order/frontend/tests/fixtures/posUi.js) | Adapter control giao diện cho test; React, query, auth và API modules được thực thi thật trong test |
| [package.json](/Users/viet/Pancake/pancake_order/frontend/package.json), [package-lock.json](/Users/viet/Pancake/pancake_order/frontend/package-lock.json) | Thêm devDependency `react-test-renderer@19.2.8` để chạy test có lifecycle/effect, không đưa vào bundle sản phẩm |
| [Báo cáo này](/Users/viet/Pancake/pancake_order/docs/pos-auto-shop-20261104.md) | Kết quả và bằng chứng |

Giữ nguyên các thay đổi tồn tại sẵn từ các lượt POS/phân quyền/AICRM trước đó. Không sửa Backend hoặc migration trong lượt UX này.

## Test Frontend

**44/44 PASS**, gồm **17 test mới** cùng POS tiền/quyền, shop options, auth query isolation, products API, Warehouse.

| Trường hợp mới | Kết quả |
| --- | --- |
| 0 shop: thông báo đúng, không API sản phẩm/khách/tạo đơn | PASS |
| Sale, CSKH, Admin mỗi role có một shop: tự chọn và tải cả hai dataset | PASS — 3 test |
| Admin nhiều shop: placeholder, không kế thừa bộ lọc chung, chờ chọn tay | PASS |
| Đổi shop: xác nhận trước, reset giỏ/khách/địa chỉ/ghi chú/điều chỉnh tiền, callback cũ bị chặn | PASS |
| Mất shop đã chọn: reset draft, chọn shop còn lại nếu duy nhất, xóa cache không hợp lệ | PASS |
| Danh sách một → nhiều → không shop | PASS |
| API danh sách tải chậm | PASS |
| Cache một shop cũ nhưng API mới nhiều shop | PASS |
| API danh sách lỗi rồi thử lại thành công | PASS |
| Xác minh scope nền: chặn submit, giữ draft khi scope không đổi | PASS |
| Đổi tài khoản cùng shop, phản hồi cũ đến muộn, danh sách khách riêng | PASS |
| Đăng xuất/đăng nhập không kế thừa shop/giỏ của nhân viên trước | PASS |
| Mất quyền POS, hộp xác nhận cũ không khôi phục lựa chọn | PASS |
| React StrictMode | PASS |
| Xem đơn POS cũ không kích hoạt luồng auto-select/bán hàng mới | PASS |

Lệnh chạy:

```sh
node --test --test-force-exit tests/posShopSelection.test.js tests/pos.test.js tests/shopOptions.test.js tests/authQueryIsolation.test.js tests/productsApi.test.js tests/warehouse.test.js
npm run lint
npm run build
```

Lint exit 0; 18 warning có sẵn ngoài các file sửa trong lượt này. Lint riêng các file thay đổi không có warning/error. Build PASS, `git diff --check` PASS. Test renderer có cảnh báo deprecated của React; chỉ dùng trong dev test. Log: [frontend-tests.log](/Users/viet/Pancake/.local-backups/pos-auto-shop-20261104/frontend-tests.log), [lint.log](/Users/viet/Pancake/.local-backups/pos-auto-shop-20261104/lint.log), [build.log](/Users/viet/Pancake/.local-backups/pos-auto-shop-20261104/build.log).

## Kiểm tra giao diện Local thật

Không mock API cho các kiểm tra trình duyệt sau. Không nhấn Tạo đơn hàng hoặc ghi nhận thanh toán.

| Tài khoản / thao tác | Hành vi quan sát | Kết quả |
| --- | --- | --- |
| CSKH TEST 641 mở POS | Shop 38 chọn sẵn, sáu sản phẩm tải ngay; dropdown khách có đúng khách TEST được gán cho CSKH | PASS |
| CSKH chọn khách và thêm một sản phẩm TEST vào giỏ, sau đó đăng xuất | Chỉ là draft, không lưu đơn | PASS |
| Sale TEST 642 đăng nhập tiếp | Shop 38 chọn sẵn; giỏ về 0, tên/địa chỉ/khách cũ của CSKH đều trống; dropdown chỉ có khách TEST của Sale | PASS |
| Admin 1 đăng nhập | Nhiều shop, giữ placeholder Chọn cửa hàng, chưa tải sản phẩm/khách và chưa kế thừa shop 38 của Sale | PASS |
| Admin chọn shop TEST A, thêm sản phẩm TEST, chọn khách, nhập ghi chú; chuyển sang shop TEST B | Có xác nhận đổi shop; sau xác nhận giỏ 0, khách/địa chỉ/ghi chú được xóa, sản phẩm shop A không còn trong giỏ/grid | PASS |

Các trường hợp 0 shop, tải chậm/lỗi và thu hồi quyền được kiểm thử bằng component test; không thay role, membership hoặc dữ liệu Local để giả lập các trường hợp đó.

Ảnh: [CSKH tự chọn shop](/Users/viet/Pancake/.local-backups/pos-auto-shop-20261104/cskh-auto-selected.png), [Sale tự chọn shop và draft sạch](/Users/viet/Pancake/.local-backups/pos-auto-shop-20261104/sale-auto-selected.png), [Admin chờ chọn shop](/Users/viet/Pancake/.local-backups/pos-auto-shop-20261104/admin-manual-selection.png), [đổi shop đã reset giỏ](/Users/viet/Pancake/.local-backups/pos-auto-shop-20261104/admin-shop-switch-reset.png). Bản AX tương ứng lưu cùng thư mục bằng chứng.

## Bảo toàn nghiệp vụ và dữ liệu

- Chỉ bổ sung UX Frontend. `OrderService`, `WarehouseService`, `ShopAccessService`, `posTotals.js`, `posMoneyInput.js` khớp SHA-256 trước/sau; không đổi giá, COD, tồn ảo, thanh toán hoặc Kho.
- Đối chiếu MySQL Local trước/sau: **90.593 orders**, max ID **349321**, shipments **0**, jobs **4**, không thay đổi. ID 349321 đã có trước lượt kiểm tra, không phải đơn do lượt UX tạo.
- Checksum toàn bộ user, role, permissions, role_permissions và shop_users khớp trước/sau. Không đổi mật khẩu, role hoặc shop để kiểm thử.
- Không gọi Production, không giao hàng/thu tiền/tạo vận đơn; không commit/push. Không tạo thêm đơn TEST trong lượt này.

Chứng cứ: [local-before.json](/Users/viet/Pancake/.local-backups/pos-auto-shop-20261104/local-before.json), [local-after.json](/Users/viet/Pancake/.local-backups/pos-auto-shop-20261104/local-after.json), [business-logic-unchanged.json](/Users/viet/Pancake/.local-backups/pos-auto-shop-20261104/business-logic-unchanged.json).
